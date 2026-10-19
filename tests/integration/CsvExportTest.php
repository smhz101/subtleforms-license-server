<?php
/**
 * LS-09: the license CSV export reads activations in bulk.
 *
 * @package SFLS
 */

/**
 * CSV export speed.
 */
class SFLS_Csv_Export_Test extends SFLS_Test_Case {

	/**
	 * The repro: two extra queries per license made 10,000 licenses take
	 * 17.5 s. The number of queries no longer grows with the licenses, and
	 * sites used and domains are still right.
	 */
	public function test_activations_are_read_in_one_query(): void {
		global $wpdb;
		$licenses = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$lic = $this->make_license( array( 'sites_allowed' => 3 ) );
			SFLS_Licenses::add_activation( (int) $lic['id'], "site{$i}.example.com" );
			SFLS_Licenses::add_activation( (int) $lic['id'], "shop{$i}.example.com" );
			SFLS_Licenses::add_activation( (int) $lic['id'], 'localhost' );
			$licenses[] = SFLS_Licenses::find( (int) $lic['id'] );
		}

		$before = $wpdb->num_queries;
		$rows   = SFLS_Licenses::csv_rows( $licenses );
		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $before, 'No queries per license.' );

		$this->assertCount( 31, $rows );
		$this->assertSame( '2', $rows[1][5], 'Local sites use no slot.' );
		$this->assertSame( 'site0.example.com shop0.example.com localhost', $rows[1][6] );
		$this->assertSame( 'site29.example.com shop29.example.com localhost', $rows[30][6] );
		$this->assertSame( '', SFLS_Licenses::csv_rows( array( SFLS_Licenses::find( (int) $this->make_license()['id'] ) ) )[1][6] );
	}
}
