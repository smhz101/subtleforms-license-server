<?php
/**
 * Items 13 and 14: list filters, search, CSV export, test reminder.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Admin list.
 */
class SFLS_Licenses_List_Test extends SFLS_Test_Case {

	/**
	 * IDs from a query.
	 *
	 * @param array $args Query args.
	 * @return int[]
	 */
	private function ids( array $args ): array {
		[ $rows ] = SFLS_Licenses::query( $args + array( 'per' => 0 ) );
		return array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
	}

	/**
	 * Plan, status and "expiring in 30 days" filters and domain search.
	 */
	public function test_filters_and_search(): void {
		$tag      = wp_generate_password( 6, false );
		$starter  = $this->make_license(
			array(
				'plan'           => 'starter',
				'customer_email' => "a{$tag}@" . self::DOMAIN,
			)
		);
		$agency   = $this->make_license(
			array(
				'plan'           => 'agency',
				'customer_email' => "b{$tag}@" . self::DOMAIN,
			)
		);
		$expiring = $this->make_license(
			array(
				'plan'           => 'pro',
				'customer_email' => "c{$tag}@" . self::DOMAIN,
			)
		);
		SFLS_Licenses::update( (int) $expiring['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ) );
		SFLS_Licenses::add_activation( (int) $agency['id'], "client-{$tag}.example.com" );

		$mine = static fn( array $ids ) => array_values( array_intersect( $ids, array( (int) $starter['id'], (int) $agency['id'], (int) $expiring['id'] ) ) );

		$this->assertSame( array( (int) $starter['id'] ), $mine( $this->ids( array( 'plan' => 'starter', 'search' => $tag ) ) ) );
		$this->assertSame( array( (int) $expiring['id'] ), $mine( $this->ids( array( 'status' => 'expiring', 'search' => $tag ) ) ) );
		$this->assertSame( array( (int) $agency['id'] ), $this->ids( array( 'search' => "client-{$tag}" ) ), 'Search by activated domain.' );
		$this->assertSame( array( (int) $starter['id'] ), $this->ids( array( 'search' => substr( $starter['license_key'], 4, 8 ) ) ), 'Search by key.' );
		$this->assertCount( 3, $this->ids( array( 'search' => $tag ) ) );
		[ , $total ] = SFLS_Licenses::query( array( 'search' => $tag, 'per' => 1 ) );
		$this->assertSame( 3, $total );
	}

	/**
	 * CSV rows: header, domains, and formulas neutralised.
	 */
	public function test_csv_export(): void {
		$lic = $this->make_license( array( 'notes' => '' ) );
		SFLS_Licenses::update( (int) $lic['id'], array( 'customer_email' => '=HYPERLINK("x")@' . self::DOMAIN ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'one.example.com' );
		$rows = SFLS_Licenses::csv_rows( array( SFLS_Licenses::find( (int) $lic['id'] ) ) );

		$this->assertSame( 'license_key', $rows[0][1] );
		$this->assertSame( $lic['license_key'], $rows[1][1] );
		$this->assertSame( 'one.example.com', $rows[1][6] );
		$this->assertStringStartsWith( "'=", $rows[1][7], 'Formula injection is neutralised.' );

		$csv = SFLS_Admin::csv( $rows );
		$this->assertStringStartsWith( 'id,license_key,plan,status,', $csv );
		$this->assertStringContainsString( '"\'=HYPERLINK(""x"")@', $csv );
	}

	/**
	 * Send test reminder: one email to the admin, no license touched.
	 */
	public function test_send_test_reminder(): void {
		global $wpdb;
		$before = (string) $wpdb->get_var( "SELECT MAX(updated_at) FROM {$wpdb->prefix}sfls_licenses" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_set_current_user( 1 );
		$this->mails = array();

		$this->assertTrue( SFLS_Reminders::send_test( 'owner@' . self::DOMAIN ) );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'owner@' . self::DOMAIN, is_array( $this->mails[0]['to'] ) ? $this->mails[0]['to'][0] : $this->mails[0]['to'] );
		$this->assertStringStartsWith( '[Test] Your SubtleForms Pro license expires', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'This is a test of the renewal reminder', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'Renew your license', $this->mails[0]['message'] );

		$this->assertTrue( SFLS_Reminders::send_test( 'owner@' . self::DOMAIN, 'lapsed' ) );
		$this->assertStringContainsString( 'has expired', $this->mails[1]['subject'] );
		$this->assertFalse( SFLS_Reminders::send_test( 'not-an-email' ) );
		$this->assertSame( $before, (string) $wpdb->get_var( "SELECT MAX(updated_at) FROM {$wpdb->prefix}sfls_licenses" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The Settings screen has the button, the list has the filters.
	 */
	public function test_screens_have_controls(): void {
		wp_set_current_user( 1 );
		$admin = new SFLS_Admin();
		ob_start();
		$admin->page_settings();
		$this->assertStringContainsString( 'Send test reminder', (string) ob_get_clean() );
		ob_start();
		$admin->page_list();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="plan"', $html );
		$this->assertStringContainsString( 'Expiring in 30 days', $html );
		$this->assertStringContainsString( 'action=sfls_export', $html );
		$this->assertStringContainsString( 'Key, email or domain', $html );
	}
}
