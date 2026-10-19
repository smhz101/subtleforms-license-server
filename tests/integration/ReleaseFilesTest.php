<?php
/**
 * LS-08: release zips are stored under random names.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Private release file names.
 */
class SFLS_Release_Files_Test extends SFLS_Test_Case {

	/**
	 * The repro: the predictable URL of a stored release does not exist; the
	 * package has a random name and is still served through the endpoint.
	 */
	public function test_packages_get_random_names(): void {
		$release = $this->add_release( '9.7.0' );
		$dir     = trailingslashit( SFLS_Releases::dir() );
		$this->assertFileDoesNotExist( $dir . 'subtleforms-pro-9.7.0.zip', 'Nothing at the predictable path.' );
		$this->assertMatchesRegularExpression( '/^subtleforms-pro-9\.7\.0-[a-f0-9]{32}\.zip$/', $release['file'] );
		$this->assertTrue( SFLS_Releases::has_package( $release ) );
		$this->assertFileExists( $dir . '.htaccess', 'The deny rule stays.' );

		// Two uploads of the same version never share a name.
		$this->assertNotSame( SFLS_Releases::private_name( '9.7.0' ), SFLS_Releases::private_name( '9.7.0' ) );
	}

	/**
	 * Files stored by 1.6.0 under the predictable name are renamed once on
	 * upgrade, replaced files included.
	 */
	public function test_existing_files_are_renamed(): void {
		global $wpdb;
		$release = $this->add_release( '9.8.0' );
		$dir     = trailingslashit( SFLS_Releases::dir() );
		$old     = 'subtleforms-pro-9.8.0.zip';
		$older   = 'subtleforms-pro-9.8.0-1a2b3c4d.zip';
		rename( $dir . $release['file'], $dir . $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		copy( $dir . $old, $dir . $older );
		$wpdb->update(
			SFLS_DB::releases(),
			array(
				'file'           => $old,
				'replaced_files' => wp_json_encode( array( $older ) ),
			),
			array( 'id' => $release['id'] )
		);
		SFLS_Releases::flush();

		$this->set_option( SFLS_Releases::PRIVATE_NAMES_OPTION, null );
		$this->assertSame( 2, SFLS_Releases::make_names_private() );
		$this->assertSame( 0, SFLS_Releases::make_names_private(), 'Runs once.' );

		$after = SFLS_Releases::get( '9.8.0', true );
		$this->assertTrue( SFLS_Releases::is_private_name( $after['file'] ) );
		$this->assertFileExists( $dir . $after['file'] );
		$this->assertFileDoesNotExist( $dir . $old );
		$this->assertFileDoesNotExist( $dir . $older );
		$replaced = SFLS_Releases::replaced_files( $after );
		$this->assertCount( 1, $replaced );
		$this->assertTrue( SFLS_Releases::is_private_name( $replaced[0] ) );
		$this->assertSame( $release['sha256'], hash_file( 'sha256', $dir . $after['file'] ) );
	}
}
