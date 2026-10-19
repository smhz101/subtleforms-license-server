<?php
/**
 * LS-16: data retention, uninstall leftovers, and no license keys in
 * download URLs.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery

if ( ! class_exists( 'SFLS_Test_Stop' ) ) {
	require_once __DIR__ . '/ReleaseManagementTest.php';
}

/**
 * Retention and privacy.
 */
class SFLS_Retention_Test extends SFLS_Test_Case {

	/**
	 * Signed download links and package addresses name the license by ID and
	 * a token; the key is never in the URL, and they still work.
	 */
	public function test_download_urls_carry_no_key(): void {
		$this->add_release( '9.1.0' );
		$lic = $this->make_license();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'shop.example.com' );
		$args = array(
			'license_key' => $lic['license_key'],
			'site_url'    => 'https://shop.example.com',
		);

		$check = $this->rest( 'GET', '/sfls/v1/update/check', $args )->get_data();
		$this->assertStringContainsString( '/sfls/v1/update/package', $check['package'] );
		$this->assertStringNotContainsString( $lic['license_key'], rawurldecode( $check['package'] ) );
		$this->assertStringNotContainsString( 'license_key', $check['package'] );

		$versions = $this->rest( 'GET', '/sfls/v1/update/versions', $args )->get_data();
		$this->assertStringNotContainsString( $lic['license_key'], rawurldecode( $versions['versions'][0]['package'] ) );

		// The package address works without the key.
		wp_parse_str( (string) wp_parse_url( $check['package'], PHP_URL_QUERY ), $p );
		$package = $this->rest( 'GET', '/sfls/v1/update/package', $p );
		$this->assertSame( 302, $package->get_status(), wp_json_encode( $package->get_data() ) );
		$location = $package->get_headers()['Location'];
		$this->assertStringNotContainsString( $lic['license_key'], rawurldecode( $location ) );
		$this->assertStringNotContainsString( 'license_key', $location );

		// A wrong token is not a key.
		$p['ptoken'] = str_repeat( 'a', 64 );
		$this->assertSame( 400, $this->rest( 'GET', '/sfls/v1/update/package', $p )->get_status() );

		// The signed link downloads.
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $q );
		$this->filter(
			'sfls_release_downloaded',
			static function ( $release, $l, $domain ) {
				throw new SFLS_Test_Stop( array( $release['version'], $l['license_key'], $domain ) );
			},
			10,
			3
		);
		try {
			$this->rest( 'GET', '/sfls/v1/update/download', $q );
			$this->fail( 'Expected the zip.' );
		} catch ( SFLS_Test_Stop $stop ) {
			$this->assertSame( array( '9.1.0', $lic['license_key'], 'shop.example.com' ), $stop->data );
		}
	}

	/**
	 * The activation log and Paddle events are pruned daily after the
	 * retention setting (365 days by default, 0 keeps them).
	 */
	public function test_old_activity_is_pruned(): void {
		global $wpdb;
		$this->assertSame( 365, SFLS_Settings::retention_days() );
		$this->assertNotFalse( has_action( SFLS_Reminders::HOOK, array( 'SFLS_DB', 'prune' ) ) );

		$lic = $this->make_license();
		$old = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
		$new = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
		foreach ( array( $old, $new ) as $when ) {
			$wpdb->insert(
				SFLS_DB::activation_log(),
				array(
					'license_id' => (int) $lic['id'],
					'domain'     => 'shop.example.com',
					'event'      => 'activate',
					'source'     => 'api',
					'ip'         => '198.51.100.9',
					'created_at' => $when,
				)
			);
			$wpdb->insert(
				SFLS_DB::paddle_events(),
				array(
					'event_id'    => 'evt_test_' . md5( $when ),
					'event_type'  => 'subscription.updated',
					'received_at' => $when,
					'status'      => 'updated',
				)
			);
		}

		$this->settings( array( 'retention_days' => 0 ) );
		$this->assertSame( 0, SFLS_DB::prune() );
		$this->settings( array( 'retention_days' => 365 ) );
		$this->assertGreaterThanOrEqual( 2, SFLS_DB::prune() );

		$this->assertSame( array( $new ), wp_list_pluck( SFLS_Licenses::log_entries( (int) $lic['id'] ), 'created_at' ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}sfls_paddle_events WHERE event_id = %s", 'evt_test_' . md5( $old ) ) ) );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}sfls_paddle_events WHERE event_id = %s", 'evt_test_' . md5( $new ) ) ) );

		// The setting is on the settings screen.
		wp_set_current_user( 1 );
		ob_start();
		( new SFLS_Admin() )->page_settings();
		$this->assertStringContainsString( 'name="retention_days"', (string) ob_get_clean() );
	}

	/**
	 * Uninstall removes the user meta and lock options the plugin leaves.
	 */
	public function test_uninstall_removes_leftovers(): void {
		global $wpdb;
		$keys = "'_sfls_paddle_customer_id','_sfls_paddle_customer_email','_sfls_claim','_sfls_claim_sent'";
		$meta = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ( {$keys} )", ARRAY_A );
		$opts = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'sfls\\_issue\\_lock\\_%' OR option_name LIKE 'sfls\\_paddle\\_lock\\_%'", ARRAY_A );

		$user = $this->make_user();
		update_user_meta( $user->ID, '_sfls_paddle_customer_id', 'ctm_test' );
		update_user_meta( $user->ID, '_sfls_paddle_customer_email', $user->user_email );
		update_user_meta( $user->ID, '_sfls_claim', array( 'hash' => 'x' ) );
		add_option( 'sfls_issue_lock_999999991', (string) time(), '', false );
		add_option( 'sfls_paddle_lock_999999992', (string) time(), '', false );
		add_option( 'sfls_issue_lockout_keep', 'keep', '', false );

		try {
			SFLS_DB::remove_leftovers();
			wp_cache_flush();
			$this->assertSame( '', get_user_meta( $user->ID, '_sfls_paddle_customer_id', true ) );
			$this->assertSame( '', get_user_meta( $user->ID, '_sfls_paddle_customer_email', true ) );
			$this->assertSame( '', get_user_meta( $user->ID, '_sfls_claim', true ) );
			$this->assertFalse( get_option( 'sfls_issue_lock_999999991' ) );
			$this->assertFalse( get_option( 'sfls_paddle_lock_999999992' ) );
			$this->assertSame( 'keep', get_option( 'sfls_issue_lockout_keep' ), 'Only the lock options go.' );
			$this->assertStringContainsString( 'SFLS_DB::remove_leftovers()', (string) file_get_contents( SFLS_DIR . 'uninstall.php' ) );
		} finally {
			delete_option( 'sfls_issue_lockout_keep' );
			foreach ( $meta as $row ) {
				add_user_meta( (int) $row['user_id'], $row['meta_key'], maybe_unserialize( $row['meta_value'] ) );
			}
			foreach ( $opts as $row ) {
				$wpdb->replace( $wpdb->options, $row );
			}
			wp_cache_flush();
		}
	}
}
