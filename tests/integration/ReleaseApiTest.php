<?php
/**
 * 1.6.0 CI release API: deploy tokens (good, bad, revoked, rate limit,
 * https), multipart upload, package_url (intercepted), idempotent re-post
 * and replace rules, publish, yank and delete.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Release API.
 */
class SFLS_Release_Api_Test extends SFLS_Test_Case {

	/**
	 * Plain token of the test.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * Set up: a fresh token, no releases, uploads accepted from temp files.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->set_option( SFLS_Deploy_Tokens::OPTION, get_option( SFLS_Deploy_Tokens::OPTION, array() ) );
		$this->set_option( SFLS_Releases::SETTINGS_OPTION, get_option( SFLS_Releases::SETTINGS_OPTION, array() ) );
		$this->token = SFLS_Deploy_Tokens::create( 'Test CI' )['token'];
		$this->isolate_releases();
		$this->filter( 'sfls_release_api_check_uploaded', '__return_false' );
	}

	/**
	 * An API request with the token.
	 *
	 * @param string      $method Method.
	 * @param string      $route  Route.
	 * @param array       $params Params.
	 * @param string|null $token  Token (null = the test token, '' = none).
	 * @param array       $files  File params.
	 */
	private function api( string $method, string $route, array $params = array(), ?string $token = null, array $files = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$token = null === $token ? $this->token : $token;
		if ( '' !== $token ) {
			$request->set_header( 'Authorization', 'Bearer ' . $token );
		}
		if ( $files ) {
			$request->set_file_params( $files );
		}
		return rest_ensure_response( rest_do_request( $request ) );
	}

	/**
	 * Multipart file param for a zip.
	 *
	 * @param string $path Zip.
	 */
	private function upload( string $path ): array {
		return array(
			'package' => array(
				'name'     => basename( $path ),
				'type'     => 'application/zip',
				'tmp_name' => $path,
				'error'    => 0,
				'size'     => filesize( $path ),
			),
		);
	}

	/**
	 * Tokens: stored hashed, shown once; good, bad, missing and revoked.
	 */
	public function test_token_auth(): void {
		$stored = wp_json_encode( get_option( SFLS_Deploy_Tokens::OPTION ) );
		$this->assertStringNotContainsString( $this->token, (string) $stored, 'Only the hash is stored.' );
		$this->assertStringStartsWith( 'sflsdt_', $this->token );

		$ok = $this->api( 'GET', '/sfls/v1/releases' );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( array(), $ok->get_data()['releases'] );
		$record = current( array_filter( SFLS_Deploy_Tokens::all(), static fn( $t ) => 'Test CI' === $t['name'] ) );
		$this->assertNotSame( '', $record['last_used_at'] );

		$this->assertSame( 'invalid_token', $this->api( 'GET', '/sfls/v1/releases', array(), 'sflsdt_' . str_repeat( '0', 48 ) )->get_data()['code'] );
		$this->assertSame( 401, $this->api( 'GET', '/sfls/v1/releases', array(), 'nonsense' )->get_status() );
		$this->assertSame( 'missing_token', $this->api( 'GET', '/sfls/v1/releases', array(), '' )->get_data()['code'] );

		// X-SFLS-Token works for hosts that drop Authorization.
		$request = new WP_REST_Request( 'GET', '/sfls/v1/releases' );
		$request->set_header( 'X-SFLS-Token', $this->token );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		SFLS_Deploy_Tokens::revoke( (int) $record['id'] );
		$this->assertSame( 'invalid_token', $this->api( 'GET', '/sfls/v1/releases' )->get_data()['code'] );
	}

	/**
	 * Failed attempts are limited per IP, even for a good token afterwards.
	 */
	public function test_failed_auth_rate_limit(): void {
		$this->filter(
			'sfls_token_auth_limit',
			static fn() => array(
				'limit'  => 3,
				'window' => 600,
			)
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 401, $this->api( 'GET', '/sfls/v1/releases', array(), 'sflsdt_bad' . $i )->get_status() );
		}
		$limited = $this->api( 'GET', '/sfls/v1/releases' );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $limited->get_headers() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.78';
		$this->assertSame( 200, $this->api( 'GET', '/sfls/v1/releases' )->get_status(), 'Per IP.' );
	}

	/**
	 * Plain http is refused outside local sites.
	 */
	public function test_https_required(): void {
		$this->filter( 'sfls_release_api_allow_http', '__return_false' );
		$res = $this->api( 'GET', '/sfls/v1/releases' );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'https_required', $res->get_data()['code'] );
	}

	/**
	 * Multipart upload; idempotent re-post; a different package needs replace.
	 */
	public function test_multipart_upload_idempotent_and_replace(): void {
		$zip = $this->pro_zip( '9.4.0' );
		$res = $this->api(
			'POST',
			'/sfls/v1/releases',
			array(
				'changelog'  => "* New: CI\n* Fixed: things",
				'git_ref'    => 'v9.4.0',
				'git_commit' => str_repeat( 'b', 40 ),
				'publish'    => 'true',
				'sha256'     => hash_file( 'sha256', $zip ),
			),
			null,
			$this->upload( $zip )
		);
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertSame( 'created', $data['result'] );
		$this->assertSame( '9.4.0', $data['release']['version'] );
		$this->assertSame( 'published', $data['release']['status'] );
		$this->assertSame( 'api', $data['release']['source'] );
		$this->assertSame( 'v9.4.0', $data['release']['git_ref'] );
		$this->assertSame( hash_file( 'sha256', $zip ), $data['release']['sha256'] );
		$this->assertSame( filesize( $zip ), $data['release']['size'] );
		$this->assertTrue( $data['release']['latest'] );
		$this->assertArrayNotHasKey( 'file', $data['release'], 'The storage path is never exposed.' );
		$stored = SFLS_Releases::get( '9.4.0', true );
		$this->assertMatchesRegularExpression( '/^subtleforms-pro-9\.4\.0-[a-f0-9]{32}\.zip$/', $stored['file'], 'Stored under a name nobody can guess.' );
		$this->assertFileExists( SFLS_Releases::package_path( $stored ) );
		$this->assertFileExists( $zip, 'The source is not moved.' );

		// Same version, same zip: the existing release (200).
		$again = $this->api( 'POST', '/sfls/v1/releases', array( 'publish' => 'true' ), null, $this->upload( $zip ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 'existing', $again->get_data()['result'] );
		$this->assertSame( $data['release']['id'], $again->get_data()['release']['id'] );

		// Same version, another zip: refused unless replace=true.
		$other   = $this->pro_zip( '9.4.0', array( 'subtleforms-pro/changed.txt' => 'x' ) );
		$refused = $this->api( 'POST', '/sfls/v1/releases', array(), null, $this->upload( $other ) );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'version_exists', $refused->get_data()['code'] );

		$replaced = $this->api( 'POST', '/sfls/v1/releases', array( 'replace' => 'true' ), null, $this->upload( $other ) );
		$this->assertSame( 201, $replaced->get_status() );
		$this->assertSame( 'replaced', $replaced->get_data()['result'] );
		$this->assertSame( hash_file( 'sha256', $other ), $replaced->get_data()['release']['sha256'] );
		$new = SFLS_Releases::get( '9.4.0', true );
		$this->assertNotSame( $stored['file'], $new['file'] );
		$this->assertSame( array( $stored['file'] ), SFLS_Releases::replaced_files( $new ) );
		$this->assertFileExists( trailingslashit( SFLS_Releases::dir() ) . $stored['file'], 'The old zip is kept until the release is deleted.' );
		$this->assertSame( '* New: CI' . "\n" . '* Fixed: things', $new['changelog'], 'Changelog kept when none is sent.' );

		// A wrong sha256 is refused.
		$bad = $this->api( 'POST', '/sfls/v1/releases', array( 'sha256' => str_repeat( 'c', 64 ) ), null, $this->upload( $this->pro_zip( '9.5.0' ) ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'sha256_mismatch', $bad->get_data()['code'] );

		// A zip that is not SubtleForms Pro is refused.
		$wrong = $this->pro_zip( '9.5.0' );
		$z     = new ZipArchive();
		$z->open( $wrong, ZipArchive::OVERWRITE );
		$z->addFromString( 'other/other.php', '<?php' );
		$z->close();
		$this->assertSame( 'bad_layout', $this->api( 'POST', '/sfls/v1/releases', array(), null, $this->upload( $wrong ) )->get_data()['code'] );

		// Too large.
		$this->filter( 'sfls_release_max_size', static fn() => 10 );
		$this->assertSame( 413, $this->api( 'POST', '/sfls/v1/releases', array(), null, $this->upload( $this->pro_zip( '9.6.0' ) ) )->get_status() );

		// Delete removes the release and both files.
		$files = array( SFLS_Releases::package_path( $new ), trailingslashit( SFLS_Releases::dir() ) . $stored['file'] );
		$del   = $this->api( 'DELETE', '/sfls/v1/releases/9.4.0' );
		$this->assertSame( 200, $del->get_status() );
		foreach ( $files as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		$this->assertSame( 404, $this->api( 'GET', '/sfls/v1/releases/9.4.0' )->get_status() );
	}

	/**
	 * Without publish the release is a draft, unless auto publish is on; the
	 * publish and yank endpoints; a hyphen means beta.
	 */
	public function test_draft_publish_yank(): void {
		SFLS_Releases::save_settings( array( 'auto_publish' => 0 ) );
		$res = $this->api( 'POST', '/sfls/v1/releases', array(), null, $this->upload( $this->pro_zip( '9.7.0' ) ) );
		$this->assertSame( 'draft', $res->get_data()['release']['status'] );
		$this->assertNull( SFLS_Releases::latest( 'stable' ) );

		$pub = $this->api( 'POST', '/sfls/v1/releases/9.7.0/publish' );
		$this->assertSame( 'published', $pub->get_data()['status'] );
		$this->assertTrue( $pub->get_data()['latest'] );

		SFLS_Releases::save_settings( array( 'auto_publish' => 1 ) );
		$beta = $this->api( 'POST', '/sfls/v1/releases', array(), null, $this->upload( $this->pro_zip( '9.8.0-rc.1' ) ) );
		$this->assertSame( 'published', $beta->get_data()['release']['status'] );
		$this->assertSame( 'beta', $beta->get_data()['release']['channel'] );

		$forced = $this->api( 'POST', '/sfls/v1/releases', array( 'channel' => 'stable', 'publish' => 'false' ), null, $this->upload( $this->pro_zip( '9.9.0' ) ) );
		$this->assertSame( 'draft', $forced->get_data()['release']['status'], 'publish=false wins over auto publish.' );
		$this->assertSame( 'stable', $forced->get_data()['release']['channel'] );

		$yank = $this->api( 'POST', '/sfls/v1/releases/9.7.0/yank', array( 'reason' => 'Bad build' ) );
		$this->assertSame( 'yanked', $yank->get_data()['status'] );
		$this->assertSame( 'Bad build', $yank->get_data()['yank_reason'] );

		$list = $this->api( 'GET', '/sfls/v1/releases' )->get_data();
		$this->assertNull( $list['latest'] );
		$this->assertSame( '9.8.0-rc.1', $list['latest_beta'] );
		$this->assertSame( 404, $this->api( 'POST', '/sfls/v1/releases/1.2.3/publish' )->get_status() );
	}

	/**
	 * package_url: https GitHub hosts only, redirects followed by hand.
	 */
	public function test_package_url(): void {
		$zip   = $this->pro_zip( '9.4.2' );
		$calls = array();
		$this->filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $zip, &$calls ) {
				$calls[] = array( $url, $args['headers'] ?? array() );
				if ( 'https://github.com/acme/subtleforms-pro/releases/download/v9.4.2/subtleforms-pro-9.4.2.zip' === $url ) {
					return array(
						'headers'  => array( 'location' => 'https://objects.githubusercontent.com/release-asset/1?sig=x' ),
						'body'     => '',
						'response' => array(
							'code'    => 302,
							'message' => 'Found',
						),
						'cookies'  => array(),
						'filename' => $args['filename'] ?? null,
					);
				}
				if ( str_starts_with( $url, 'https://objects.githubusercontent.com/' ) ) {
					return array(
						'headers'  => array(),
						'body'     => file_get_contents( $zip ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => $args['filename'] ?? null,
					);
				}
				return new WP_Error( 'unexpected', $url );
			},
			10,
			3
		);
		$res = $this->api(
			'POST',
			'/sfls/v1/releases',
			array(
				'package_url' => 'https://github.com/acme/subtleforms-pro/releases/download/v9.4.2/subtleforms-pro-9.4.2.zip',
				'publish'     => true,
			)
		);
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( hash_file( 'sha256', $zip ), $res->get_data()['release']['sha256'] );
		$this->assertCount( 2, $calls, 'Redirect followed.' );

		$http = $this->api( 'POST', '/sfls/v1/releases', array( 'package_url' => 'http://github.com/acme/x.zip' ) );
		$this->assertSame( 'url_not_allowed', $http->get_data()['code'] );
		$other = $this->api( 'POST', '/sfls/v1/releases', array( 'package_url' => 'https://evil.example.com/subtleforms-pro.zip' ) );
		$this->assertSame( 'url_not_allowed', $other->get_data()['code'] );
		$this->assertSame( 'missing_package', $this->api( 'POST', '/sfls/v1/releases' )->get_data()['code'] );
		$this->assertCount( 2, $calls, 'Refused URLs are never requested.' );
	}
}
