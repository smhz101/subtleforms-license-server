<?php
/**
 * 1.6.0 GitHub connection: webhook signature, ping, release published with
 * an asset (download intercepted), prerelease to beta, tag and version
 * mismatch, delivery log and retry, test connection and import by tag.
 * GitHub is never reached: pre_http_request answers every call.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery

/**
 * GitHub webhook.
 */
class SFLS_GitHub_Webhook_Test extends SFLS_Test_Case {

	const SECRET = 'test-webhook-secret';
	const REPO   = 'acme/subtleforms-pro';
	const TOKEN  = 'github_pat_TESTTOKEN1234';

	/**
	 * HTTP calls: [ url, headers ].
	 *
	 * @var array[]
	 */
	private array $calls = array();

	/**
	 * Zips served for asset IDs.
	 *
	 * @var array<int,string>
	 */
	private array $assets = array();

	/**
	 * Highest delivery row before the test.
	 *
	 * @var int
	 */
	private int $last_delivery = 0;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		global $wpdb;
		parent::setUp();
		$this->set_option(
			SFLS_GitHub::OPTION,
			array(
				'repo'          => self::REPO,
				'token'         => self::TOKEN,
				'secret'        => self::SECRET,
				'asset_pattern' => 'subtleforms-pro-*.zip',
				'auto_publish'  => 1,
			)
		);
		$this->isolate_releases();
		$this->last_delivery = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}sfls_github_deliveries" );
		$this->filter( 'pre_http_request', array( $this, 'fake_github' ), 10, 3 );
	}

	/**
	 * Remove the deliveries the test recorded.
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sfls_github_deliveries WHERE id > %d", $this->last_delivery ) );
		parent::tearDown();
	}

	/**
	 * Fake GitHub.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 */
	public function fake_github( $pre, $args, $url ) {
		$this->calls[] = array( $url, (array) ( $args['headers'] ?? array() ) );
		$reply         = static fn( int $code, string $body, array $headers = array() ) => array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
		if ( preg_match( '#^https://api\.github\.com/repos/acme/subtleforms-pro/releases/assets/(\d+)$#', $url, $m ) ) {
			return isset( $this->assets[ (int) $m[1] ] ) ? $reply( 302, '', array( 'location' => 'https://release-assets.githubusercontent.com/asset/' . $m[1] . '?sig=abc' ) ) : $reply( 404, '{"message":"Not Found"}' );
		}
		if ( preg_match( '#^https://release-assets\.githubusercontent\.com/asset/(\d+)#', $url, $m ) ) {
			return $reply( 200, (string) file_get_contents( $this->assets[ (int) $m[1] ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( 'https://api.github.com/repos/acme/subtleforms-pro' === $url ) {
			return $reply(
				200,
				(string) wp_json_encode(
					array(
						'full_name'      => self::REPO,
						'private'        => true,
						'default_branch' => 'main',
					)
				)
			);
		}
		if ( 'https://api.github.com/repos/acme/subtleforms-pro/releases/tags/v9.7.0' === $url ) {
			return $reply( 200, (string) wp_json_encode( $this->release_payload( 'v9.7.0', 7 )['release'] ) );
		}
		return $reply( 404, '{"message":"Not Found"}' );
	}

	/**
	 * A release event payload.
	 *
	 * @param string $tag        Tag.
	 * @param int    $asset_id   Asset ID.
	 * @param bool   $prerelease Pre-release.
	 * @param string $action     Action.
	 */
	private function release_payload( string $tag, int $asset_id, bool $prerelease = false, string $action = 'published' ): array {
		return array(
			'action'     => $action,
			'release'    => array(
				'id'               => 1000 + $asset_id,
				'tag_name'         => $tag,
				'target_commitish' => 'main',
				'name'             => $tag,
				'draft'            => false,
				'prerelease'       => $prerelease,
				'body'             => "## What's new\n\n- **New:** inline `payment` form\n- Fixed: [webhook](https://example.com) retries\n\n<!-- hidden -->",
				'assets'           => array(
					array(
						'id'   => 1,
						'name' => 'source-code.tar.gz',
						'url'  => 'https://api.github.com/repos/acme/subtleforms-pro/releases/assets/1',
					),
					array(
						'id'                   => $asset_id,
						'name'                 => 'subtleforms-pro-' . ltrim( $tag, 'v' ) . '.zip',
						'size'                 => 1000,
						'url'                  => 'https://api.github.com/repos/acme/subtleforms-pro/releases/assets/' . $asset_id,
						'browser_download_url' => 'https://github.com/acme/subtleforms-pro/releases/download/' . $tag . '/x.zip',
					),
				),
			),
			'repository' => array( 'full_name' => self::REPO ),
		);
	}

	/**
	 * Send a webhook.
	 *
	 * @param string      $event   Event.
	 * @param array       $payload Payload.
	 * @param string|null $secret  Secret to sign with (null = the right one).
	 */
	private function hook( string $event, array $payload, ?string $secret = null ): WP_REST_Response {
		$body    = (string) wp_json_encode( $payload );
		$request = new WP_REST_Request( 'POST', '/sfls/v1/github/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-GitHub-Event', $event );
		$request->set_header( 'X-GitHub-Delivery', wp_generate_uuid4() );
		$request->set_header( 'X-Hub-Signature-256', 'sha256=' . hash_hmac( 'sha256', $body, $secret ?? self::SECRET ) );
		$request->set_body( $body );
		return rest_ensure_response( rest_do_request( $request ) );
	}

	/**
	 * Latest delivery row.
	 */
	private function last_delivery(): array {
		return SFLS_GitHub::recent_deliveries( 1 )[0];
	}

	/**
	 * Signature check and ping.
	 */
	public function test_signature_and_ping(): void {
		$ping = $this->hook( 'ping', array( 'zen' => 'Keep it logically awesome.' ) );
		$this->assertSame( 200, $ping->get_status() );
		$this->assertSame( 'ok', $ping->get_data()['result'] );
		$this->assertSame( 'ping', $this->last_delivery()['event'] );

		$bad = $this->hook( 'ping', array( 'zen' => 'x' ), 'wrong-secret' );
		$this->assertSame( 401, $bad->get_status() );
		$row = $this->last_delivery();
		$this->assertSame( 'rejected', $row['result'] );
		$this->assertSame( '0', (string) $row['has_payload'], 'Unsigned payloads are not stored.' );

		$request = new WP_REST_Request( 'POST', '/sfls/v1/github/webhook' );
		$request->set_header( 'X-GitHub-Event', 'ping' );
		$request->set_body( '{}' );
		$this->assertSame( 401, rest_do_request( $request )->get_status(), 'No signature.' );

		$this->assertTrue( SFLS_GitHub::verify_signature( 'abc', 'sha256=' . hash_hmac( 'sha256', 'abc', 's' ), 's' ) );
		$this->assertFalse( SFLS_GitHub::verify_signature( 'abc', hash_hmac( 'sha256', 'abc', 's' ), 's' ), 'Prefix required.' );
		$this->assertFalse( SFLS_GitHub::verify_signature( 'abc', 'sha256=' . hash_hmac( 'sha256', 'abc', '' ), '' ), 'No secret, no pass.' );

		$this->set_option( SFLS_GitHub::OPTION, array_merge( SFLS_GitHub::config(), array( 'secret' => '' ) ) );
		$this->assertSame( 503, $this->hook( 'ping', array() )->get_status() );
	}

	/**
	 * A published release with the zip asset is downloaded through the API
	 * (token only on the API host), checked and stored.
	 */
	public function test_release_published_with_asset(): void {
		$this->assets[5] = $this->pro_zip( '9.5.0' );
		$res             = $this->hook( 'release', $this->release_payload( 'v9.5.0', 5 ) );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'stored', $res->get_data()['result'] );

		$rel = SFLS_Releases::get( '9.5.0', true );
		$this->assertSame( 'github', $rel['source'] );
		$this->assertSame( 'v9.5.0', $rel['git_ref'] );
		$this->assertSame( 'main', $rel['git_commit'] );
		$this->assertSame( 'stable', $rel['channel'] );
		$this->assertSame( 'published', $rel['status'] );
		$this->assertSame( hash_file( 'sha256', $this->assets[5] ), $rel['sha256'] );
		$this->assertSame( "What's new\n* New: inline payment form\n* Fixed: webhook retries", $rel['changelog'] );
		$this->assertSame( '9.5.0', SFLS_Releases::latest( 'stable' )['version'] );

		// Asset request: API URL, octet-stream, token; storage host without the token.
		[ $api_url, $api_headers ] = $this->calls[0];
		$this->assertSame( 'https://api.github.com/repos/acme/subtleforms-pro/releases/assets/5', $api_url );
		$this->assertSame( 'application/octet-stream', $api_headers['Accept'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, $api_headers['Authorization'] );
		[ $cdn_url, $cdn_headers ] = $this->calls[1];
		$this->assertStringStartsWith( 'https://release-assets.githubusercontent.com/', $cdn_url );
		$this->assertArrayNotHasKey( 'Authorization', $cdn_headers );

		$row = $this->last_delivery();
		$this->assertSame( 'stored', $row['result'] );
		$this->assertSame( 'v9.5.0', $row['tag'] );
		$this->assertSame( '9.5.0', $row['release_version'] );

		// The "released" event for the same release does not download again.
		$count = count( $this->calls );
		$again = $this->hook( 'release', $this->release_payload( 'v9.5.0', 5, false, 'released' ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 'existing', $again->get_data()['result'] );
		$this->assertCount( $count, $this->calls );

		// Other actions are ignored.
		$this->assertSame( 'ignored', $this->hook( 'release', $this->release_payload( 'v9.5.0', 5, false, 'edited' ) )->get_data()['result'] );
		$this->assertSame( 'ignored', $this->hook( 'push', array( 'ref' => 'refs/heads/main' ) )->get_data()['result'] );
		$other               = $this->release_payload( 'v9.5.1', 5 );
		$other['repository'] = array( 'full_name' => 'someone/else' );
		$this->assertSame( 'ignored', $this->hook( 'release', $other )->get_data()['result'] );
	}

	/**
	 * A pre-release goes to the beta channel, and moves to stable when it is
	 * released; auto publish off keeps it a draft.
	 */
	public function test_prerelease_to_beta(): void {
		$this->set_option( SFLS_GitHub::OPTION, array_merge( SFLS_GitHub::config(), array( 'auto_publish' => 0 ) ) );
		$this->assets[6] = $this->pro_zip( '9.6.0-beta.2' );
		$res             = $this->hook( 'release', $this->release_payload( 'v9.6.0-beta.2', 6, true ) );
		$this->assertSame( 'stored', $res->get_data()['result'], wp_json_encode( $res->get_data() ) );
		$rel = SFLS_Releases::get( '9.6.0-beta.2', true );
		$this->assertSame( 'beta', $rel['channel'] );
		$this->assertSame( 'draft', $rel['status'] );

		$moved = $this->hook( 'release', $this->release_payload( 'v9.6.0-beta.2', 6, false, 'released' ) );
		$this->assertSame( 'existing', $moved->get_data()['result'] );
		$this->assertSame( 'stable', SFLS_Releases::get( '9.6.0-beta.2', true )['channel'] );
	}

	/**
	 * The tag must match the plugin Version header; a failed delivery can be
	 * retried once fixed.
	 */
	public function test_tag_mismatch_and_retry(): void {
		$this->assets[8] = $this->pro_zip( '9.8.1' );
		$res             = $this->hook( 'release', $this->release_payload( 'v9.8.0', 8 ) );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( 'failed', $res->get_data()['result'] );
		$this->assertStringContainsString( 'does not match the plugin Version header 9.8.1', $res->get_data()['message'] );
		$this->assertNull( SFLS_Releases::get( '9.8.0', true ) );
		$this->assertNull( SFLS_Releases::get( '9.8.1', true ) );

		// Missing asset, then retry after it is attached.
		$payload = $this->release_payload( 'v9.9.0', 9 );
		$failed  = $this->hook( 'release', $payload );
		$this->assertSame( 'failed', $failed->get_data()['result'] );
		$this->assertStringContainsString( '404', $failed->get_data()['message'] );
		$row = $this->last_delivery();
		$this->assertSame( '1', (string) $row['has_payload'] );

		$this->assets[9] = $this->pro_zip( '9.9.0' );
		$retry           = SFLS_GitHub::retry( (int) $row['id'] );
		$this->assertSame( 'stored', $retry['result'] );
		$this->assertSame( 'stored', $this->last_delivery()['result'] );
		$this->assertStringContainsString( '(retried)', $this->last_delivery()['message'] );
		$this->assertNotNull( SFLS_Releases::get( '9.9.0', true ) );

		// No matching asset at all.
		$none                       = $this->release_payload( 'v9.9.5', 10 );
		$none['release']['assets'] = array_slice( $none['release']['assets'], 0, 1 );
		$this->assertStringContainsString( 'No asset matching subtleforms-pro-*.zip', $this->hook( 'release', $none )->get_data()['message'] );
	}

	/**
	 * Test connection and Import a release by tag.
	 */
	public function test_connection_and_import(): void {
		$ok = SFLS_GitHub::test_connection();
		$this->assertSame( self::REPO, $ok['full_name'] );
		$this->assertTrue( $ok['private'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, $this->calls[0][1]['Authorization'] );

		$this->assets[7] = $this->pro_zip( '9.7.0' );
		$import          = SFLS_GitHub::import_tag( 'v9.7.0' );
		$this->assertSame( 'stored', $import['result'], $import['message'] );
		$this->assertSame( 'github', SFLS_Releases::get( '9.7.0', true )['source'] );

		$missing = SFLS_GitHub::import_tag( 'v0.0.1' );
		$this->assertSame( 'failed', $missing['result'] );

		$this->set_option( SFLS_GitHub::OPTION, array_merge( SFLS_GitHub::config(), array( 'repo' => 'acme/missing' ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_GitHub::test_connection() );
	}

	/**
	 * Settings helpers.
	 */
	public function test_settings_helpers(): void {
		$this->assertSame( 'acme/subtleforms-pro', SFLS_GitHub::sanitize_repo( 'https://github.com/acme/subtleforms-pro.git' ) );
		$this->assertSame( '', SFLS_GitHub::sanitize_repo( 'not a repo' ) );
		$this->assertTrue( SFLS_GitHub::matches( 'subtleforms-pro-2.4.0.zip', 'subtleforms-pro-*.zip' ) );
		$this->assertFalse( SFLS_GitHub::matches( 'subtleforms-pro-2.4.0.tar.gz', 'subtleforms-pro-*.zip' ) );
		$this->assertSame( '2.4.0', SFLS_Releases::version_from_tag( 'v2.4.0' ) );
		$this->assertSame( '2.4.0', SFLS_Releases::version_from_tag( 'refs/tags/2.4.0' ) );
		$secret = SFLS_GitHub::generate_secret();
		$this->assertSame( 48, strlen( $secret ) );
		$this->assertSame( $secret, SFLS_GitHub::config()['secret'] );
	}
}
