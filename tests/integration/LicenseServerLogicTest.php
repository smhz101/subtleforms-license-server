<?php
/**
 * License server business rules: plan seats, one grace period, status changes,
 * seat check, proxy address, free mail and disposable lists, rate limit
 * counters and their separate buckets.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification

/**
 * Stops an admin handler at its redirect.
 */
class SFLS_Logic_Stop extends RuntimeException {

	/**
	 * Redirect target.
	 *
	 * @var string
	 */
	public string $location = '';
}

/**
 * Rules.
 */
class SFLS_License_Server_Logic_Test extends SFLS_Test_Case {

	/**
	 * Turn rate limits on.
	 *
	 * @param int $limit Requests per window.
	 */
	private function limit( int $limit = 30 ): void {
		$this->filter( 'sfls_rate_limit_enabled', '__return_true' );
		$this->filter(
			'sfls_rate_limit',
			static fn( $conf, $type = '' ) => array(
				'limit'  => $limit,
				'window' => 600,
			),
			10,
			2
		);
	}

	/**
	 * Run an admin handler and return where it redirected.
	 *
	 * @param callable $handler Handler.
	 * @param array    $post    $_POST.
	 */
	private function admin_post( callable $handler, array $post ): string {
		wp_set_current_user( 1 );
		$_POST = $post;
		$this->filter(
			'wp_redirect',
			static function ( $location ) {
				$stop           = new SFLS_Logic_Stop( 'redirect' );
				$stop->location = (string) $location;
				throw $stop;
			}
		);
		try {
			$handler();
			return '';
		} catch ( SFLS_Logic_Stop $stop ) {
			return $stop->location;
		} finally {
			$_POST = array();
		}
	}

	// ── Plans ───────────────────────────────────────────────────────────

	/**
	 * Agency is 25 sites, not unlimited, and a new Agency license gets 25.
	 */
	public function test_agency_has_25_sites(): void {
		$this->assertSame( 25, SFLS_Plans::get( 'agency' )['sites'] );
		$lic = $this->make_license( array( 'plan' => 'agency' ) );
		$this->assertSame( 25, (int) $lic['sites_allowed'] );
		$this->assertSame( 25, SFLS_Licenses::payload( $lic )['sites_allowed'] );
	}

	// ── One grace period ────────────────────────────────────────────────

	/**
	 * A lapsed license is valid for 14 days after its expiry, once; a lapsed
	 * trial gets none. Paddle adds nothing on top.
	 */
	public function test_single_grace_period(): void {
		$this->assertSame( 0, SFLS_Paddle::BILLING_GRACE_DAYS );
		$lic = $this->make_license();
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );
		$state = SFLS_Licenses::state( SFLS_Licenses::find( (int) $lic['id'] ) );
		$this->assertSame( 'grace_period', $state['status'] );
		$this->assertEqualsWithDelta( time() + 4 * DAY_IN_SECONDS, strtotime( $state['grace_period_ends_at'] ), 5 );

		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 15 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 'expired', SFLS_Licenses::state( SFLS_Licenses::find( (int) $lic['id'] ) )['status'] );

		SFLS_Licenses::update(
			(int) $lic['id'],
			array(
				'trial'      => 1,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 'expired', SFLS_Licenses::state( SFLS_Licenses::find( (int) $lic['id'] ) )['status'], 'A trial has no grace.' );
	}

	// ── Status changes ──────────────────────────────────────────────────

	/**
	 * The extend() method moves the expiry only: suspended and refunded stay as they are.
	 */
	public function test_extend_never_changes_status(): void {
		foreach ( array( 'suspended', 'refunded' ) as $status ) {
			$lic = $this->make_license();
			SFLS_Licenses::update( (int) $lic['id'], array( 'status' => $status ) );
			SFLS_Licenses::extend( (int) $lic['id'], 12 );
			$after = SFLS_Licenses::find( (int) $lic['id'] );
			$this->assertSame( $status, $after['status'] );
			$this->assertGreaterThan( strtotime( $lic['expires_at'] . ' UTC' ), strtotime( $after['expires_at'] . ' UTC' ) );
		}
		// A lifetime license has nothing to extend and keeps its status.
		$life = $this->make_license( array( 'months' => 0 ) );
		SFLS_Licenses::update( (int) $life['id'], array( 'status' => 'suspended' ) );
		SFLS_Licenses::extend( (int) $life['id'] );
		$this->assertSame( 'suspended', SFLS_Licenses::find( (int) $life['id'] )['status'] );
	}

	/**
	 * Resume lifts a suspension but never a refund.
	 */
	public function test_resume_only_lifts_a_suspension(): void {
		$lic = $this->make_license();
		SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'suspended' ) );
		$this->assertTrue( SFLS_Licenses::resume( (int) $lic['id'] ) );
		$this->assertSame( 'active', SFLS_Licenses::find( (int) $lic['id'] )['status'] );

		SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'refunded' ) );
		$this->assertFalse( SFLS_Licenses::resume( (int) $lic['id'] ) );
		$this->assertSame( 'refunded', SFLS_Licenses::find( (int) $lic['id'] )['status'] );
	}

	/**
	 * The editor needs an explicit status, and a blank site limit is refused.
	 */
	public function test_editor_requires_explicit_status_and_sites(): void {
		$lic = $this->make_license();
		SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'suspended' ) );
		$id = (int) $lic['id'];
		wp_set_current_user( 1 );
		$nonce = wp_create_nonce( 'sfls_save_' . $id );
		$base  = array(
			'id'             => $id,
			'_wpnonce'       => $nonce,
			'plan'           => 'pro',
			'sites_allowed'  => '5',
			'customer_email' => $lic['customer_email'],
			'expires_at'     => '',
		);
		$_REQUEST['_wpnonce'] = $nonce;

		// No status posted: a suspended license stays suspended.
		$this->admin_post( array( new SFLS_Admin(), 'handle_save' ), $base );
		$this->assertSame( 'suspended', SFLS_Licenses::find( $id )['status'] );
		$this->assertSame( 5, (int) SFLS_Licenses::find( $id )['sites_allowed'] );

		// An unknown status keeps it too.
		$this->admin_post( array( new SFLS_Admin(), 'handle_save' ), $base + array( 'status' => 'whatever' ) );
		$this->assertSame( 'suspended', SFLS_Licenses::find( $id )['status'] );

		// A blank site limit would be unlimited: nothing is saved.
		$this->admin_post( array( new SFLS_Admin(), 'handle_save' ), array_merge( $base, array( 'sites_allowed' => '', 'status' => 'active' ) ) );
		$after = SFLS_Licenses::find( $id );
		$this->assertSame( 'suspended', $after['status'] );
		$this->assertSame( 5, (int) $after['sites_allowed'] );

		// An explicit choice works, and refunded is a choice of its own.
		$this->admin_post( array( new SFLS_Admin(), 'handle_save' ), $base + array( 'status' => 'active' ) );
		$this->assertSame( 'active', SFLS_Licenses::find( $id )['status'] );
		$this->admin_post( array( new SFLS_Admin(), 'handle_save' ), $base + array( 'status' => 'refunded' ) );
		$this->assertSame( 'refunded', SFLS_Licenses::find( $id )['status'] );

		// 0 typed on purpose is still allowed.
		$this->admin_post( array( new SFLS_Admin(), 'handle_save' ), array_merge( $base, array( 'sites_allowed' => '0', 'status' => 'active' ) ) );
		$this->assertSame( 0, (int) SFLS_Licenses::find( $id )['sites_allowed'] );
		unset( $_REQUEST['_wpnonce'] );
	}

	// ── Seats ───────────────────────────────────────────────────────────

	/**
	 * The seat check and the insert run in one locked transaction: the limit
	 * holds, and a refused site leaves no row.
	 */
	public function test_seat_limit_holds(): void {
		$lic = $this->make_license( array( 'sites_allowed' => 2 ) );
		$this->assertTrue( SFLS_Licenses::activate_domain( $lic, 'one.example.com' ) );
		$this->assertTrue( SFLS_Licenses::activate_domain( $lic, 'two.example.com' ) );
		$third = SFLS_Licenses::activate_domain( $lic, 'three.example.com' );
		$this->assertInstanceOf( WP_Error::class, $third );
		$this->assertSame( 'activation_limit', $third->get_error_code() );
		$this->assertSame( 2, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
		$this->assertNull( SFLS_Licenses::activation( (int) $lic['id'], 'three.example.com' ) );
		// A site that is already active and a staging site still work.
		$this->assertTrue( SFLS_Licenses::activate_domain( $lic, 'one.example.com' ) );
		$this->assertTrue( SFLS_Licenses::activate_domain( $lic, 'shop.local' ) );
		$this->assertSame( 2, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
	}

	/**
	 * The locking read is the statement the seat check sends.
	 */
	public function test_seat_check_sends_locking_read(): void {
		$lic     = $this->make_license( array( 'sites_allowed' => 3 ) );
		$queries = array();
		$this->filter(
			'query',
			static function ( $sql ) use ( &$queries ) {
				$queries[] = $sql;
				return $sql;
			}
		);
		SFLS_Licenses::activate_domain( $lic, 'locked.example.com' );
		$text = implode( "\n", $queries );
		$this->assertStringContainsString( 'START TRANSACTION', $text );
		$this->assertMatchesRegularExpression( '/SELECT id FROM \S*sfls_licenses WHERE id = \d+ FOR UPDATE/', $text );
		$this->assertStringContainsString( 'COMMIT', $text );
		$this->assertLessThan( strpos( $text, 'COMMIT' ), strpos( $text, 'FOR UPDATE' ) );
	}

	// ── Client address ──────────────────────────────────────────────────

	/**
	 * Behind a trusted proxy the address comes from CF-Connecting-IP, else
	 * X-Forwarded-For; otherwise only REMOTE_ADDR counts.
	 */
	public function test_client_ip_behind_a_proxy(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.7, 10.0.0.1';
		$this->assertSame( '10.0.0.1', SFLS_Licenses::client_ip(), 'Off by default: headers can be forged.' );

		$this->settings( array( 'trusted_proxy' => 1 ) );
		$this->assertSame( '203.0.113.9', SFLS_Licenses::client_ip() );
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$this->assertSame( '198.51.100.7', SFLS_Licenses::client_ip() );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not an address';
		$this->assertSame( '10.0.0.1', SFLS_Licenses::client_ip() );
	}

	/**
	 * Settings save the proxy and trial switches only when the form has them.
	 */
	public function test_settings_save_rules(): void {
		$this->settings( array() );
		SFLS_Settings::save(
			array(
				'rules_present'    => 1,
				'trusted_proxy'    => 1,
				'disposable_extra' => "Throwaway.example\nnot a domain\n@other-temp.test",
			)
		);
		$this->assertTrue( SFLS_Settings::trusted_proxy() );
		$this->assertFalse( SFLS_Settings::trial_verify(), 'An unchecked box is off.' );
		$this->assertFalse( SFLS_Settings::block_disposable() );
		$this->assertSame( array( 'throwaway.example', 'other-temp.test' ), SFLS_Settings::clean_domains( (string) SFLS_Settings::get( 'disposable_extra' ) ) );
		SFLS_Settings::save( array( 'reminder_days' => '30' ) );
		$this->assertTrue( SFLS_Settings::trusted_proxy(), 'Other forms keep the switches.' );
	}

	// ── Mail domains ────────────────────────────────────────────────────

	/**
	 * At least 40 free mail domains, with the ones the review named.
	 */
	public function test_free_mail_list(): void {
		$list = SFLS_Settings::free_mail_domains();
		$this->assertGreaterThanOrEqual( 40, count( $list ) );
		foreach ( array( 'yandex.com', 'zoho.com', 'fastmail.com', 'mail.ru', 'qq.com', '163.com', 'gmx.net' ) as $domain ) {
			$this->assertContains( $domain, $list );
			$this->assertTrue( SFLS_Settings::is_free_mail( 'someone@' . $domain ), $domain );
		}
		$this->assertTrue( SFLS_Settings::is_free_mail( 'a@yandex.by' ) );
		$this->assertFalse( SFLS_Settings::is_free_mail( 'a@acme-company.com' ) );
		// A genuine Zoho user shares no company domain with a stranger on Zoho.
		$this->assertSame( '', SFLS_Trials::company_domain( 'a@zoho.com' ) );
	}

	/**
	 * Disposable addresses are refused for trials unless the option is off.
	 */
	public function test_disposable_domains_are_refused(): void {
		$this->settings( array( 'block_disposable' => 1 ) );
		$tag = 'sflstest' . strtolower( wp_generate_password( 6, false, false ) );
		foreach ( array( 'mailinator.com', 'sub.yopmail.com', '10minutemail.com' ) as $domain ) {
			$check = SFLS_Trials::check( $tag . '@' . $domain );
			$this->assertFalse( $check['eligible'], $domain );
			$this->assertSame( 'disposable_email', $check['reason'] );
		}
		$this->assertTrue( SFLS_Trials::check( $tag . '@gmail.com' )['eligible'] );

		$this->filter(
			'sfls_disposable_domains',
			static function ( $list ) {
				$list[] = 'burn.example';
				return $list;
			}
		);
		$this->assertSame( 'disposable_email', SFLS_Trials::check( $tag . '@burn.example' )['reason'] );

		$this->settings( array( 'block_disposable' => 0 ) );
		$this->assertTrue( SFLS_Trials::check( $tag . '@mailinator.com' )['eligible'] );
	}

	// ── Rate limits ─────────────────────────────────────────────────────

	/**
	 * The counter is a row that is raised, not a transient, and the request
	 * over the limit is the first refused.
	 */
	public function test_counter_is_a_table_row(): void {
		global $wpdb;
		$this->limit( 3 );
		$rest = new SFLS_REST();
		for ( $n = 1; $n <= 3; $n++ ) {
			$this->assertNull( $rest->rate_limit( 'ip', '192.0.2.1' ), "Request $n" );
		}
		$refused = $rest->rate_limit( 'ip', '192.0.2.1' );
		$this->assertSame( 429, $refused->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $refused->get_headers() );
		$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$wpdb->prefix}sfls_rate_limits WHERE bucket = %s", SFLS_REST::bucket_name( 'ip', '192.0.2.1' ) ) );
		$this->assertSame( 4, $hits );
		$this->assertNull( $rest->rate_limit( 'ip', '192.0.2.2' ), 'Another address has its own row.' );
		// Checking without counting does not raise it.
		$rest->rate_limit( 'ip', '192.0.2.3', false );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$wpdb->prefix}sfls_rate_limits WHERE bucket = %s", SFLS_REST::bucket_name( 'ip', '192.0.2.3' ) ) ) );
		// A window that ended starts again at 1.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}sfls_rate_limits SET window_start = %d", time() - 601 ) );
		$this->assertNull( $rest->rate_limit( 'ip', '192.0.2.1' ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$wpdb->prefix}sfls_rate_limits WHERE bucket = %s", SFLS_REST::bucket_name( 'ip', '192.0.2.1' ) ) ) );
	}

	/**
	 * Checkout status polling and update checks do not use the license bucket.
	 */
	public function test_separate_buckets(): void {
		$this->limit( 2 );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
		$lic                    = $this->make_license();
		$params                 = array(
			'license_key' => $lic['license_key'],
			'site_url'    => 'https://shop.example.com',
		);
		// Use up the license bucket of this address.
		$this->rest( 'POST', '/sfls/v1/license/validate', $params );
		$this->rest( 'POST', '/sfls/v1/license/validate', $params );
		$this->assertSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $params )->get_status() );

		// Polling still answers (404 for an unknown order, never 429), and has its own, larger allowance.
		$poll = array(
			'order' => 999999,
			'key'   => 'wc_order_nokey',
		);
		$this->assertNotSame( 429, $this->rest( 'GET', '/sfls/v1/paddle/checkout', $poll )->get_status() );
		$this->assertNotSame( 429, $this->rest( 'GET', '/sfls/v1/paddle/checkout', $poll )->get_status() );
		$this->assertSame( 429, $this->rest( 'GET', '/sfls/v1/paddle/checkout', $poll )->get_status(), 'The poll bucket has a limit too.' );

		// Update checks are limited in their own bucket, not by the license one.
		$params['site_url'] = 'https://other.example.com';
		$this->assertNotSame( 429, $this->rest( 'GET', '/sfls/v1/update/check', $params + array( 'version' => '1.0.0' ) )->get_status() );
		$this->assertNotSame( 429, $this->rest( 'GET', '/sfls/v1/update/check', $params + array( 'version' => '1.0.0' ) )->get_status() );
		$this->assertSame( 429, $this->rest( 'GET', '/sfls/v1/update/check', $params + array( 'version' => '1.0.0' ) )->get_status() );
	}

	/**
	 * Unknown keys sent to /update/check count as failures, so it cannot test
	 * keys in bulk.
	 */
	public function test_update_check_counts_unknown_keys(): void {
		$this->filter( 'sfls_rate_limit_enabled', '__return_true' );
		$this->filter(
			'sfls_rate_limit',
			static fn( $conf, $type = '' ) => array(
				'limit'  => 'update' === $type ? 100 : 3,
				'window' => 600,
			),
			10,
			2
		);
		$_SERVER['REMOTE_ADDR'] = '198.51.100.88';
		$params                 = array(
			'license_key' => 'SFP-00000000-00000000-00000000-00000000',
			'site_url'    => 'https://shop.example.com',
		);
		$codes = array();
		for ( $n = 0; $n < 6; $n++ ) {
			$codes[] = $this->rest( 'GET', '/sfls/v1/update/check', $params )->get_status();
		}
		$this->assertContains( 429, $codes );
		$this->assertSame( 200, $codes[0] );
	}

	// ── Database ────────────────────────────────────────────────────────

	/**
	 * Schema 8 adds the indexes, the columns and the tables through dbDelta.
	 */
	public function test_schema_8(): void {
		global $wpdb;
		$this->assertSame( '8', SFLS_DB::SCHEMA_VERSION );
		$this->assertSame( '8', get_option( 'sfls_schema_version' ) );
		$keys = wp_list_pluck( (array) $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}sfls_licenses", ARRAY_A ), 'Key_name' );
		$this->assertContains( 'status', $keys );
		$this->assertContains( 'expires_at', $keys );
		$cols = wp_list_pluck( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}sfls_trial_claims", ARRAY_A ), 'Field' );
		$this->assertContains( 'order_id', $cols );
		$this->assertContains( 'status', $cols );
		foreach ( array( SFLS_DB::rate_limits(), SFLS_DB::trial_codes() ) as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	/**
	 * The private release folder's .htaccess is Apache 2.4 only.
	 */
	public function test_htaccess_is_apache_24_only(): void {
		$file = SFLS_Releases::dir() . '/.htaccess';
		$this->assertFileExists( $file );
		$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'Require all denied', $text );
		$this->assertStringNotContainsString( 'Deny from all', $text );
	}
}
