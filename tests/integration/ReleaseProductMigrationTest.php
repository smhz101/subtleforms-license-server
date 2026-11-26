<?php
/**
 * Schema 15 (1.15.0): releases belong to a product. The column defaults to
 * subtleforms-pro, so existing rows become Pro, and the unique key is
 * (product, version).
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Releases table after the product migration.
 */
class SFLS_Release_Product_Migration_Test extends SFLS_Test_Case {

	/**
	 * A row without a product, the way every row was inserted before schema 15.
	 *
	 * @param string $version Version.
	 * @param array  $extra   More columns.
	 */
	private function insert_old_style( string $version, array $extra = array() ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			SFLS_DB::releases(),
			array_merge(
				array(
					'version'    => $version,
					'created_at' => $now,
					'updated_at' => $now,
				),
				$extra
			)
		);
		SFLS_Releases::flush();
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Index rows of the releases table by key name.
	 *
	 * @return array<string,array{unique:bool,columns:string[]}>
	 */
	private function indexes(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SHOW INDEX FROM ' . SFLS_DB::releases(), ARRAY_A ) as $row ) {
			$out[ $row['Key_name'] ]['unique']    = 0 === (int) $row['Non_unique'];
			$out[ $row['Key_name'] ]['columns'][] = $row['Column_name'];
		}
		return $out;
	}

	/**
	 * The product column exists, defaults to Pro and is not null.
	 */
	public function test_product_column_defaults_to_pro(): void {
		global $wpdb;
		$this->isolate_releases();
		$column = $wpdb->get_row( 'SHOW COLUMNS FROM ' . SFLS_DB::releases() . " LIKE 'product'", ARRAY_A );
		$this->assertIsArray( $column );
		$this->assertSame( 'varchar(64)', $column['Type'] );
		$this->assertSame( 'NO', $column['Null'] );
		$this->assertSame( SFLS_Releases::PRO, $column['Default'] );

		$id = $this->insert_old_style( '8.0.0' );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( SFLS_Releases::PRO, SFLS_Releases::get_by_id( $id )['product'], 'An existing row is a Pro release.' );
		$this->assertNotNull( SFLS_Releases::get( '8.0.0' ), 'The Pro lookups still find it.' );
	}

	/**
	 * The unique key is (product, version): the same version may exist for two products, never twice for one.
	 */
	public function test_unique_key_is_product_and_version(): void {
		global $wpdb;
		$this->isolate_releases();
		$keys = $this->indexes();
		$this->assertArrayHasKey( 'product_version', $keys );
		$this->assertTrue( $keys['product_version']['unique'] );
		$this->assertSame( array( 'product', 'version' ), $keys['product_version']['columns'] );
		$this->assertArrayNotHasKey( 'version', $keys, 'The key on version alone is gone.' );
		$this->assertSame( array( 'product', 'status' ), $keys['product_status']['columns'] );

		$this->assertGreaterThan( 0, $this->insert_old_style( '8.1.0' ) );
		$this->assertGreaterThan( 0, $this->insert_old_style( '8.1.0', array( 'product' => 'sfltest-other' ) ), 'The same version for another product.' );
		$suppress = $wpdb->suppress_errors( true );
		$this->assertSame( 0, $this->insert_old_style( '8.1.0' ), 'The same version twice for one product is refused.' );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * The upgrade step drops the old key on version alone and leaves the new one.
	 */
	public function test_upgrade_drops_the_old_version_key(): void {
		global $wpdb;
		$this->isolate_releases();
		$table = SFLS_DB::releases();
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY version (version)" );
		$this->assertArrayHasKey( 'version', $this->indexes() );

		SFLS_DB::drop_old_version_key();
		$keys = $this->indexes();
		$this->assertArrayNotHasKey( 'version', $keys );
		$this->assertArrayHasKey( 'product_version', $keys );

		SFLS_DB::drop_old_version_key();
		$this->assertArrayHasKey( 'product_version', $this->indexes(), 'Running it again changes nothing.' );
	}

	/**
	 * Running the installer again keeps the data and the schema; the catalog table exists.
	 */
	public function test_install_is_repeatable(): void {
		global $wpdb;
		$this->isolate_releases();
		$id = $this->insert_old_style( '8.2.0' );

		SFLS_DB::install();

		$this->assertSame( SFLS_DB::SCHEMA_VERSION, get_option( 'sfls_schema_version' ) );
		$this->assertSame( '15', SFLS_DB::SCHEMA_VERSION );
		$this->assertSame( SFLS_Releases::PRO, SFLS_Releases::get_by_id( $id )['product'] );
		$this->assertSame( SFLS_DB::extensions(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', SFLS_DB::extensions() ) ) );
		$this->assertArrayNotHasKey( 'version', $this->indexes() );
		$slug = $wpdb->get_row( 'SHOW INDEX FROM ' . SFLS_DB::extensions() . " WHERE Key_name = 'slug'", ARRAY_A );
		$this->assertSame( 0, (int) $slug['Non_unique'], 'The slug is unique.' );
	}

	/**
	 * Pro code never sees an extension release, and an extension version does not hide a Pro one.
	 */
	public function test_products_do_not_mix(): void {
		$slug = $this->make_extension( 'mix' );
		$this->add_release( '3.0.0' );
		$this->add_extension_release( $slug, '3.0.0' );
		$this->add_extension_release( $slug, '4.0.0' );

		$this->assertSame( array( '3.0.0' ), array_column( SFLS_Releases::rows(), 'version' ) );
		$this->assertSame( array( '4.0.0', '3.0.0' ), array_column( SFLS_Releases::rows( $slug ), 'version' ) );
		$this->assertSame( SFLS_Releases::PRO, SFLS_Releases::get( '3.0.0' )['product'] );
		$this->assertSame( $slug, SFLS_Releases::get( '3.0.0', false, $slug )['product'] );
		$this->assertNull( SFLS_Releases::get( '4.0.0' ) );
		$this->assertSame( '3.0.0', SFLS_Releases::latest( 'stable' )['version'] );
		$this->assertSame( '4.0.0', SFLS_Releases::latest( 'stable', true, $slug )['version'] );
	}

	/**
	 * An extension zip needs its own folder, and a product outside the catalog is refused.
	 */
	public function test_ingest_checks_the_product(): void {
		$slug = $this->make_extension( 'zip' );
		$this->isolate_releases();

		$wrong = SFLS_Releases::ingest( $this->pro_zip( '1.0.0' ), array( 'product' => $slug ) );
		$this->assertWPError( $wrong, 'bad_layout' );

		$pro = SFLS_Releases::ingest( $this->extension_zip( $slug, '1.0.0' ) );
		$this->assertWPError( $pro, 'bad_layout' );

		$unknown = SFLS_Releases::ingest( $this->extension_zip( 'sfltest-nope', '1.0.0' ), array( 'product' => 'sfltest-nope' ) );
		$this->assertWPError( $unknown, 'unknown_product' );

		$ok = SFLS_Releases::ingest( $this->extension_zip( $slug, '1.0.0' ), array( 'product' => $slug ) );
		$this->assertIsArray( $ok );
		$this->assertSame( $slug, $ok['release']['product'] );
		$this->assertStringStartsWith( 'subtleforms-' . $slug . '-1.0.0-', $ok['release']['file'] );
	}

	/**
	 * Assert a WP_Error with a code.
	 *
	 * @param mixed  $value Value.
	 * @param string $code  Code.
	 */
	private function assertWPError( $value, string $code ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
		$this->assertSame( $code, $value->get_error_code() );
	}
}
