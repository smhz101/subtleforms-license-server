<?php
/**
 * 1.9.0 signing: release packages and license answers carry ed25519
 * signatures Pro checks with the public key it ships; replacing a published
 * package needs a second flag and is logged.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DiscouragedPHPFunctions

/**
 * Package and response signing.
 */
class SFLS_Signing_Test extends SFLS_Test_Case {

	/**
	 * Base64 key pair made for this test (never a real key).
	 *
	 * @var array{secret:string,public:string}
	 */
	private array $pair = array();

	/**
	 * Set up: a fresh key pair, no releases, signatures and the replacement log restored afterwards.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->set_option( SFLS_Signing::SIGNATURES_OPTION, get_option( SFLS_Signing::SIGNATURES_OPTION, array() ) );
		$this->set_option( SFLS_Signing::REPLACEMENTS_OPTION, get_option( SFLS_Signing::REPLACEMENTS_OPTION, array() ) );
		$this->pair = SFLS_Signing::generate();
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		$this->isolate_releases();
	}

	/**
	 * Tear down: back to the configured key.
	 */
	protected function tearDown(): void {
		SFLS_Signing::use_test_key( null );
		parent::tearDown();
	}

	/**
	 * A license active on shop.example.com.
	 */
	private function active_license(): array {
		$lic = $this->make_license();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'shop.example.com' );
		return $lic;
	}

	/**
	 * What Pro does: decode the body and check the signature over the route
	 * and the canonical JSON of everything else.
	 *
	 * @param string $route Route.
	 * @param array  $data  Response data.
	 */
	private function verifies( string $route, array $data ): bool {
		$body = json_decode( (string) wp_json_encode( $data ), true );
		return SFLS_Signing::verify( SFLS_Signing::response_message( $route, $body ), (string) ( $body['signature'] ?? '' ), $this->pair['public'] );
	}

	/**
	 * Sign and verify; the key pair is the right size; a wrong key fails.
	 */
	public function test_sign_and_verify(): void {
		$this->assertTrue( SFLS_Signing::enabled() );
		$this->assertSame( $this->pair['public'], SFLS_Signing::public_key() );
		$sig = SFLS_Signing::sign( 'hello' );
		$this->assertSame( 64, strlen( base64_decode( $sig, true ) ) );
		$this->assertTrue( SFLS_Signing::verify( 'hello', $sig, $this->pair['public'] ) );
		$this->assertFalse( SFLS_Signing::verify( 'hellO', $sig, $this->pair['public'] ) );
		$this->assertFalse( SFLS_Signing::verify( 'hello', $sig, SFLS_Signing::generate()['public'] ) );
		$this->assertFalse( SFLS_Signing::verify( 'hello', 'not base64!', $this->pair['public'] ) );
	}

	/**
	 * Canonical JSON sorts object keys at every level and keeps lists in order.
	 */
	public function test_canonical_json(): void {
		$this->assertSame(
			'{"a":1,"b":{"x":[3,1,2],"y":"z/é"}}',
			SFLS_Signing::canonical(
				array(
					'b' => array(
						'y' => 'z/é',
						'x' => array( 3, 1, 2 ),
					),
					'a' => 1,
				)
			)
		);
	}

	/**
	 * A stored release is signed over slug, version and sha256, and
	 * update/check hands out package_sha256 and package_sig.
	 */
	public function test_release_signed_and_offered(): void {
		$release = $this->add_release( '9.7.0' );
		$sig     = SFLS_Signing::release_signature( $release );
		$this->assertNotSame( '', $sig );
		$this->assertTrue( SFLS_Signing::verify( SFLS_Signing::package_message( '9.7.0', $release['sha256'] ), $sig, $this->pair['public'] ) );
		$this->assertFalse( SFLS_Signing::verify( SFLS_Signing::package_message( '9.7.0', str_repeat( 'a', 64 ) ), $sig, $this->pair['public'] ), 'Another zip does not match.' );
		$this->assertFalse( SFLS_Signing::verify( SFLS_Signing::package_message( '9.6.0', $release['sha256'] ), $sig, $this->pair['public'] ), 'The version is signed too.' );

		$lic   = $this->active_license();
		$check = $this->rest(
			'POST',
			'/sfls/v1/update/check',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://shop.example.com',
				'sig_nonce'   => 'abcdefghijklmnop0123',
			)
		)->get_data();
		$this->assertSame( '9.7.0', $check['new_version'] );
		$this->assertNotSame( '', $check['package'] );
		$this->assertSame( $release['sha256'], $check['package_sha256'] );
		$this->assertSame( $sig, $check['package_sig'] );
		$this->assertSame( 'abcdefghijklmnop0123', $check['sig_nonce'] );
		$this->assertTrue( $this->verifies( '/sfls/v1/update/check', $check ) );

		// The release API shows the signature too.
		$this->assertSame( $sig, SFLS_Release_API::payload( $release )['package_sig'] );
	}

	/**
	 * A signature no longer counts once the sha256 of the release changed
	 * (a database edit cannot move a signature to another zip).
	 */
	public function test_signature_tied_to_sha256(): void {
		$release = $this->add_release( '9.7.1' );
		SFLS_Releases::update( $release['id'], array( 'sha256' => str_repeat( 'd', 64 ) ) );
		$this->assertSame( '', SFLS_Signing::release_signature( (array) SFLS_Releases::get( '9.7.1', true ) ) );

		// sign_all refuses to sign a row whose zip does not match.
		$out = SFLS_Signing::sign_all();
		$this->assertContains( '9.7.1', $out['mismatch'] );
		$this->assertSame( '', SFLS_Signing::release_signature( (array) SFLS_Releases::get( '9.7.1', true ) ) );
	}

	/**
	 * Releases stored before a key existed get signed by sign_all.
	 */
	public function test_sign_all_signs_old_releases(): void {
		SFLS_Signing::use_test_key( '' );
		$release = $this->add_release( '9.7.2' );
		$this->assertSame( '', SFLS_Signing::release_signature( $release ) );
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		$out = SFLS_Signing::sign_all();
		$this->assertSame( array( '9.7.2' ), $out['signed'] );
		$this->assertNotSame( '', SFLS_Signing::release_signature( $release ) );
		$this->assertSame( array( '9.7.2' ), SFLS_Signing::sign_all()['skipped'] );
	}

	/**
	 * License answers are signed with a timestamp and the echoed nonce;
	 * changing any field breaks the signature; so does another route.
	 */
	public function test_license_answers_signed(): void {
		$lic  = $this->active_license();
		$data = $this->rest(
			'POST',
			'/sfls/v1/license/validate',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://shop.example.com',
				'sig_nonce'   => 'nonce-0123456789abcdef',
			)
		)->get_data();
		$this->assertTrue( $data['valid'] );
		$this->assertEqualsWithDelta( time(), $data['signed_at'], 5 );
		$this->assertSame( 'nonce-0123456789abcdef', $data['sig_nonce'] );
		$this->assertTrue( $this->verifies( '/sfls/v1/license/validate', $data ) );

		$tampered         = $data;
		$tampered['plan'] = 'agency';
		$this->assertFalse( $this->verifies( '/sfls/v1/license/validate', $tampered ) );
		$this->assertFalse( $this->verifies( '/sfls/v1/license/activate', $data ), 'Bound to the route.' );

		// Error answers are signed too, so Pro can trust the reason.
		$error = $this->rest(
			'POST',
			'/sfls/v1/license/validate',
			array(
				'license_key' => 'SFP-00000000-00000000-00000000-00000000',
				'site_url'    => 'https://shop.example.com',
			)
		);
		$this->assertSame( 404, $error->get_status() );
		$this->assertSame( 'license_not_found', $error->get_data()['reason'] );
		$this->assertArrayNotHasKey( 'sig_nonce', $error->get_data(), 'No nonce sent, none echoed.' );
		$this->assertTrue( $this->verifies( '/sfls/v1/license/validate', $error->get_data() ) );
	}

	/**
	 * A malformed nonce is not echoed.
	 */
	public function test_bad_nonce_not_echoed(): void {
		$lic  = $this->active_license();
		$data = $this->rest(
			'POST',
			'/sfls/v1/license/status',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://shop.example.com',
				'sig_nonce'   => '<script>',
			)
		)->get_data();
		$this->assertArrayNotHasKey( 'sig_nonce', $data );
		$this->assertTrue( $this->verifies( '/sfls/v1/license/status', $data ) );
	}

	/**
	 * Without a key nothing is signed, and the empty update answer stays {}
	 * (Pro before 2.7.0 only accepts a JSON object).
	 */
	public function test_signing_off(): void {
		SFLS_Signing::use_test_key( '' );
		$this->assertFalse( SFLS_Signing::enabled() );
		$lic  = $this->active_license();
		$data = $this->rest(
			'POST',
			'/sfls/v1/license/validate',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://shop.example.com',
			)
		)->get_data();
		$this->assertArrayNotHasKey( 'signature', $data );
		$this->assertArrayNotHasKey( 'signed_at', $data );

		$empty = $this->rest(
			'POST',
			'/sfls/v1/update/check',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://shop.example.com',
			)
		)->get_data();
		$this->assertSame( '{}', wp_json_encode( $empty ) );

		// A release without a signature: sha256 offered, no package_sig.
		$release = $this->add_release( '9.7.3' );
		$check   = $this->rest(
			'POST',
			'/sfls/v1/update/check',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://shop.example.com',
			)
		)->get_data();
		$this->assertSame( $release['sha256'], $check['package_sha256'] );
		$this->assertArrayNotHasKey( 'package_sig', $check );
	}

	/**
	 * replace=true on a published release needs confirm_replace from the
	 * API, GitHub and WP-CLI; the replacement is logged and re-signed. The
	 * admin upload (nonce plus ticked box) needs no second flag but is
	 * logged too. Drafts are replaced freely and not logged.
	 */
	public function test_replace_needs_confirm_and_is_logged(): void {
		$first = $this->add_release( '9.8.0', array( 'source' => 'api' ) );
		$other = $this->pro_zip( '9.8.0', array( 'subtleforms-pro/changed.txt' => 'x' ) );

		$refused = SFLS_Releases::ingest(
			$other,
			array(
				'source'  => 'api',
				'replace' => true,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'replace_unconfirmed', $refused->get_error_code() );
		$this->assertSame( 409, $refused->get_error_data()['status'] );
		$this->assertSame( array(), SFLS_Signing::replacements() );

		foreach ( array( 'cli', 'github' ) as $source ) {
			$again = SFLS_Releases::ingest(
				$other,
				array(
					'source'  => $source,
					'replace' => true,
				)
			);
			$this->assertSame( 'replace_unconfirmed', $again->get_error_code(), $source );
		}

		$done = SFLS_Releases::ingest(
			$other,
			array(
				'source'          => 'api',
				'replace'         => true,
				'confirm_replace' => true,
			)
		);
		$this->assertIsArray( $done );
		$this->assertSame( 'replaced', $done['result'] );
		$log = SFLS_Signing::replacements();
		$this->assertCount( 1, $log );
		$this->assertSame( '9.8.0', $log[0]['version'] );
		$this->assertSame( $first['sha256'], $log[0]['old'] );
		$this->assertSame( hash_file( 'sha256', $other ), $log[0]['new'] );
		$this->assertSame( 'api', $log[0]['source'] );
		$this->assertTrue( SFLS_Signing::verify( SFLS_Signing::package_message( '9.8.0', hash_file( 'sha256', $other ) ), SFLS_Signing::release_signature( $done['release'] ), $this->pair['public'] ), 'The new package is signed.' );

		// Admin upload: the ticked box is the confirmation.
		$third  = $this->pro_zip( '9.8.0', array( 'subtleforms-pro/third.txt' => 'y' ) );
		$upload = SFLS_Releases::ingest(
			$third,
			array(
				'source'  => 'upload',
				'replace' => true,
			)
		);
		$this->assertSame( 'replaced', $upload['result'] );
		$this->assertCount( 2, SFLS_Signing::replacements() );
		$this->assertSame( 'upload', SFLS_Signing::replacements()[0]['source'] );

		// A draft is replaced without any flag and without a log entry.
		SFLS_Releases::ingest( $this->pro_zip( '9.9.1' ), array( 'source' => 'api' ) );
		$draft = SFLS_Releases::ingest( $this->pro_zip( '9.9.1', array( 'subtleforms-pro/d.txt' => 'z' ) ), array( 'source' => 'api' ) );
		$this->assertSame( 'replaced', $draft['result'] );
		$this->assertCount( 2, SFLS_Signing::replacements() );
	}

	/**
	 * Deleting a release drops its signature.
	 */
	public function test_delete_forgets_signature(): void {
		$release = $this->add_release( '9.8.5' );
		$this->assertArrayHasKey( $release['id'], get_option( SFLS_Signing::SIGNATURES_OPTION ) );
		SFLS_Releases::delete( $release['id'] );
		$this->assertArrayNotHasKey( $release['id'], (array) get_option( SFLS_Signing::SIGNATURES_OPTION, array() ) );
	}

	/**
	 * Signing answers is limited to the license API routes.
	 */
	public function test_only_license_routes(): void {
		$this->assertSame( 1, preg_match( SFLS_Signing::SIGNED_ROUTES, '/sfls/v1/license/deactivate' ) );
		$this->assertSame( 1, preg_match( SFLS_Signing::SIGNED_ROUTES, '/sfls/v1/connect/exchange' ) );
		$this->assertSame( 0, preg_match( SFLS_Signing::SIGNED_ROUTES, '/sfls/v1/update/download' ) );
		$this->assertSame( 0, preg_match( SFLS_Signing::SIGNED_ROUTES, '/sfls/v1/releases' ) );
	}

	/**
	 * wp sfls signing generate prints the two wp-config lines and stores nothing.
	 */
	public function test_cli_generate(): void {
		$phar = getenv( 'SFLS_WP_CLI' ) ? getenv( 'SFLS_WP_CLI' ) : '/tmp/claude-0/wp-cli.phar';
		if ( ! function_exists( 'exec' ) || ! is_file( $phar ) ) {
			$this->markTestSkipped( 'WP-CLI not found. Set SFLS_WP_CLI.' );
		}
		global $wpdb;
		$out  = array();
		$code = 0;
		exec( 'php ' . escapeshellarg( $phar ) . ' --allow-root --path=' . escapeshellarg( ABSPATH ) . ' sfls signing generate < /dev/null 2>&1', $out, $code );
		$text = implode( "\n", $out );
		$this->assertSame( 0, $code, $text );
		$this->assertSame( 1, preg_match( "/define\\( 'SFLS_SIGNING_SECRET_KEY', '([A-Za-z0-9+\\/=]+)' \\);/", $text, $secret ), $text );
		$this->assertSame( 1, preg_match( "/define\\( 'SFLS_SIGNING_PUBLIC_KEY', '([A-Za-z0-9+\\/=]+)' \\);/", $text, $public ), $text );
		$this->assertSame( 64, strlen( base64_decode( $secret[1], true ) ) );
		$this->assertSame( 32, strlen( base64_decode( $public[1], true ) ) );
		SFLS_Signing::use_test_key( $secret[1] );
		$this->assertSame( $public[1], SFLS_Signing::public_key(), 'The two printed keys belong together.' );
		$stored = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( $secret[1] ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, $stored, 'The secret key is not stored in wp_options.' );
	}
}
