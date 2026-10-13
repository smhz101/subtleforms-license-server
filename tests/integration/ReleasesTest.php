<?php
/**
 * Items 12 and 19: release zips and signed download links.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Releases.
 */
class SFLS_Releases_Test extends SFLS_Test_Case {

	/**
	 * Temp files to delete.
	 *
	 * @var string[]
	 */
	private array $tmp = array();

	/**
	 * Clean up zips.
	 */
	protected function tearDown(): void {
		foreach ( $this->tmp as $file ) {
			wp_delete_file( $file );
		}
		parent::tearDown();
	}

	/**
	 * Build a zip.
	 *
	 * @param array<string,string> $files Path => contents.
	 */
	private function zip( array $files ): string {
		$path        = wp_tempnam( 'sfls-test.zip' );
		$this->tmp[] = $path;
		$zip         = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $files as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		return $path;
	}

	/**
	 * Plugin main file with headers.
	 *
	 * @param string $version Version.
	 */
	private function main_file( string $version ): string {
		return "<?php\n/**\n * Plugin Name: SubtleForms Pro\n * Version:           {$version}\n * Requires at least: 6.5\n * Requires PHP:      8.1\n * Text Domain: subtleforms-pro\n */\n";
	}

	/**
	 * Headers are read from subtleforms-pro/subtleforms-pro.php, with Tested
	 * up to taken from readme.txt when the plugin header has none.
	 */
	public function test_reads_headers_from_zip(): void {
		$zip = $this->zip(
			array(
				'subtleforms-pro/subtleforms-pro.php' => $this->main_file( '3.1.0' ),
				'subtleforms-pro/readme.txt'          => "=== SubtleForms Pro ===\nTested up to: 7.1\nStable tag: 3.1.0\n",
			)
		);
		$headers = SFLS_Releases::inspect_zip( $zip );
		$this->assertSame(
			array(
				'version'      => '3.1.0',
				'requires'     => '6.5',
				'tested'       => '7.1',
				'requires_php' => '8.1',
			),
			$headers
		);
	}

	/**
	 * A zip without subtleforms-pro/subtleforms-pro.php is refused.
	 */
	public function test_refuses_wrong_layout(): void {
		$flat = SFLS_Releases::inspect_zip( $this->zip( array( 'subtleforms-pro.php' => $this->main_file( '3.1.0' ) ) ) );
		$this->assertWPError( $flat, 'bad_layout' );
		$other = SFLS_Releases::inspect_zip( $this->zip( array( 'subtleforms/subtleforms.php' => $this->main_file( '3.1.0' ) ) ) );
		$this->assertWPError( $other, 'bad_layout' );
		$this->assertStringContainsString( 'subtleforms-pro/subtleforms-pro.php', $other->get_error_message() );
	}

	/**
	 * Not a zip, or no Version header.
	 */
	public function test_refuses_broken_files(): void {
		$path        = wp_tempnam( 'sfls-test.zip' );
		$this->tmp[] = $path;
		file_put_contents( $path, 'not a zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertWPError( SFLS_Releases::inspect_zip( $path ), 'bad_zip' );
		$this->assertWPError( SFLS_Releases::inspect_zip( $this->zip( array( 'subtleforms-pro/subtleforms-pro.php' => "<?php\n/* Plugin Name: X */" ) ) ), 'no_version' );
	}

	/**
	 * A version lower than the current release gets a note (since 1.6.0 it
	 * is stored as an older version instead of refused).
	 */
	public function test_version_must_not_go_down(): void {
		$this->add_release( '2.2.0' );
		$this->assertNotSame( '', SFLS_Releases::version_problem( '2.1.9' ) );
		$this->assertStringContainsString( 'lower than the current release 2.2.0', SFLS_Releases::version_problem( '2.1.9' ) );
		$this->assertSame( '', SFLS_Releases::version_problem( '2.2.0' ) );
		$this->assertSame( '', SFLS_Releases::version_problem( '2.2.1' ) );
		$this->assertSame( '', SFLS_Releases::version_problem( '10.0.0' ) );
		$this->assertNotSame( '', SFLS_Releases::version_problem( '' ) );
	}

	/**
	 * Signed links expire after 15 minutes and work once.
	 */
	public function test_signed_links_expire_and_are_single_use(): void {
		$url = SFLS_Releases::signed_url( 'SFP-AAAA', 'example.com' );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$expires = (int) $q['expires'];

		$this->assertLessThanOrEqual( time() + 15 * MINUTE_IN_SECONDS, $expires );
		$this->assertGreaterThan( time() + 14 * MINUTE_IN_SECONDS, $expires );
		$this->assertTrue( SFLS_Releases::verify( 'SFP-AAAA', 'example.com', $expires, $q['sig'], $q['token'] ) );
		$this->assertFalse( SFLS_Releases::verify( 'SFP-AAAA', 'other.com', $expires, $q['sig'], $q['token'] ), 'Bound to the domain.' );
		$this->assertFalse( SFLS_Releases::verify( 'SFP-AAAA', 'example.com', $expires, $q['sig'], str_repeat( 'a', 32 ) ), 'Bound to the token.' );

		$this->assertTrue( SFLS_Releases::consume_token( $q['token'] ) );
		$this->assertFalse( SFLS_Releases::consume_token( $q['token'] ), 'Second use refused.' );

		// An expired link, even correctly signed, is refused.
		$old   = time() - 1;
		$token = str_repeat( 'b', 32 );
		$this->assertFalse( SFLS_Releases::verify( 'SFP-AAAA', 'example.com', $old, SFLS_Releases::sign( 'SFP-AAAA', 'example.com', $old, $token ), $token ) );

		// A link signed to live longer than 15 minutes is refused.
		$far = time() + HOUR_IN_SECONDS;
		$this->assertFalse( SFLS_Releases::verify( 'SFP-AAAA', 'example.com', $far, SFLS_Releases::sign( 'SFP-AAAA', 'example.com', $far, $token ), $token ) );

		// 1.2.0 links (no token) no longer work.
		$this->assertFalse( SFLS_Releases::verify( 'SFP-AAAA', 'example.com', $expires, SFLS_Releases::sign( 'SFP-AAAA', 'example.com', $expires ), '' ) );
	}

	/**
	 * Over REST: the update check hands out the package address, which
	 * redirects to a fresh signed link; a used link answers 403.
	 */
	public function test_rest_package_and_used_link(): void {
		if ( ! SFLS_Releases::has_package() ) {
			$this->markTestSkipped( 'The test site has no release package.' );
		}
		$lic = $this->make_license();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'shop.example.com' );

		$check = $this->rest(
			'GET',
			'/sfls/v1/update/check',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://shop.example.com',
			)
		)->get_data();
		$this->assertStringContainsString( '/sfls/v1/update/package', $check['package'] );

		$package = $this->rest(
			'GET',
			'/sfls/v1/update/package',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://shop.example.com',
			)
		);
		$this->assertSame( 302, $package->get_status() );
		$location = $package->get_headers()['Location'];
		$this->assertStringContainsString( '/sfls/v1/update/download', $location );
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $q );
		$this->assertTrue( SFLS_Releases::consume_token( $q['token'] ) ); // Simulate the first download.

		$again = $this->rest( 'GET', '/sfls/v1/update/download', $q );
		$this->assertSame( 403, $again->get_status() );
		$this->assertSame( 'link_used', $again->get_data()['reason'] );

		// Not activated on that site: no link.
		$denied = $this->rest(
			'GET',
			'/sfls/v1/update/package',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://elsewhere.example.com',
			)
		);
		$this->assertSame( 403, $denied->get_status() );
	}

	/**
	 * Helper.
	 *
	 * @param mixed  $value Value.
	 * @param string $code  Expected code.
	 */
	private function assertWPError( $value, string $code ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
		$this->assertSame( $code, $value->get_error_code() );
	}
}
