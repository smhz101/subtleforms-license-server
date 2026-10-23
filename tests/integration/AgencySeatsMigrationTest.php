<?php
/**
 * Schema 10: Agency licences issued unlimited get the plan's 25 seats.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * The seat upgrade for existing Agency licences.
 */
class SFLS_Agency_Seats_Migration_Test extends SFLS_Test_Case {

	/**
	 * Add activation rows for a licence without the seat checks.
	 */
	private function add_domains( int $license_id, int $count ): void {
		global $wpdb;
		for ( $i = 1; $i <= $count; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				SFLS_DB::activations(),
				array(
					'license_id'   => $license_id,
					'domain'       => 'site' . $i . '-' . $license_id . '.example.com',
					'activated_at' => current_time( 'mysql', true ),
					'last_seen_at' => current_time( 'mysql', true ),
				)
			);
		}
	}

	/**
	 * Unlimited Agency licences get 25, one with more domains keeps its count
	 * and is listed, and a Pro licence with 0 is left alone.
	 */
	public function test_agency_zero_seats_upgrade(): void {
		$few  = $this->make_license( array( 'plan' => 'agency', 'sites_allowed' => 0 ) );
		$many = $this->make_license( array( 'plan' => 'agency', 'sites_allowed' => 0 ) );
		$pro  = $this->make_license( array( 'plan' => 'pro', 'sites_allowed' => 0 ) );
		$this->add_domains( (int) $few['id'], 3 );
		$this->add_domains( (int) $many['id'], 30 );

		$over = SFLS_DB::migrate_agency_seats();

		$this->assertSame( 25, (int) SFLS_Licenses::find( (int) $few['id'] )['sites_allowed'] );
		$this->assertSame( 30, (int) SFLS_Licenses::find( (int) $many['id'] )['sites_allowed'] );
		$this->assertSame( 0, (int) SFLS_Licenses::find( (int) $pro['id'] )['sites_allowed'] );
		$this->assertContains( (int) $many['id'], $over );
		$this->assertNotContains( (int) $few['id'], $over );
		$this->assertContains( (int) $many['id'], array_map( 'intval', (array) get_option( SFLS_DB::AGENCY_OVER_LIMIT, array() ) ) );
		$this->assertNotContains( (int) $pro['id'], array_map( 'intval', (array) get_option( SFLS_DB::AGENCY_OVER_LIMIT, array() ) ) );

		$events = wp_list_pluck( SFLS_Licenses::log_entries( (int) $many['id'] ), 'event' );
		$this->assertContains( 'over_limit', $events );
		$this->assertEmpty( SFLS_Licenses::log_entries( (int) $few['id'] ) );
	}

	/**
	 * Running the installer again (a reactivation) does not turn an unlimited
	 * Agency licence back into 25 seats; crossing the schema-10 boundary does.
	 */
	public function test_install_migrates_only_when_crossing_schema_10(): void {
		$lic = $this->make_license( array( 'plan' => 'agency', 'sites_allowed' => 0 ) );

		update_option( 'sfls_schema_version', SFLS_DB::SCHEMA_VERSION );
		SFLS_DB::install();
		$this->assertSame( 0, (int) SFLS_Licenses::find( (int) $lic['id'] )['sites_allowed'], 'A reactivation keeps unlimited.' );

		update_option( 'sfls_schema_version', '9' );
		SFLS_DB::install();
		$this->assertSame( 25, (int) SFLS_Licenses::find( (int) $lic['id'] )['sites_allowed'] );
		$this->assertSame( SFLS_DB::SCHEMA_VERSION, get_option( 'sfls_schema_version' ) );
	}
}
