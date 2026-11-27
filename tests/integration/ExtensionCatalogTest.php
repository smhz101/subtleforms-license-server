<?php
/**
 * 1.15.0 extension catalog: the table and its CLI, and the public signed
 * GET /extensions answer.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DiscouragedPHPFunctions

/**
 * Catalog CRUD and public catalog.
 */
class SFLS_Extension_Catalog_Test extends SFLS_Test_Case {

	/**
	 * Key pair made for this test.
	 *
	 * @var array{secret:string,public:string}
	 */
	private array $pair = array();

	/**
	 * Set up: a fresh signing key and no releases.
	 */
	protected function setUp(): void {
		parent::setUp();
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
	 * Run wp with arguments, as the CLI tests do.
	 *
	 * @param string $args Arguments (already escaped).
	 * @return array{0:int,1:string}
	 */
	private function wp( string $args ): array {
		$phar = getenv( 'SFLS_WP_CLI' ) ? getenv( 'SFLS_WP_CLI' ) : '/tmp/claude-0/wp-cli.phar';
		$cmd  = 'php ' . escapeshellarg( $phar ) . ' --allow-root --path=' . escapeshellarg( ABSPATH ) . ' ' . $args . ' < /dev/null 2>&1';
		$out  = array();
		$code = 0;
		exec( $cmd, $out, $code );
		return array( $code, trim( implode( "\n", $out ) ) );
	}

	/**
	 * What a site does: decode the body and check the signature.
	 *
	 * @param string $route Route.
	 * @param array  $data  Response data.
	 */
	private function verifies( string $route, array $data ): bool {
		$body = json_decode( (string) wp_json_encode( $data ), true );
		return SFLS_Signing::verify( SFLS_Signing::response_message( $route, $body ), (string) ( $body['signature'] ?? '' ), $this->pair['public'] );
	}

	/**
	 * Create, read, update, publish and unpublish.
	 */
	public function test_catalog_crud(): void {
		$entry = SFLS_Extensions::create(
			'sfltest-sheets',
			array(
				'title'       => 'Test Sheets',
				'description' => 'Send entries to a sheet.',
				'category'    => 'integrations',
				'min_plan'    => 'pro',
				'icon'        => 'database-export',
				'docs-url'    => 'https://example.com/docs',
				'sort'        => '3',
			)
		);
		$this->assertIsArray( $entry );
		$this->assertSame( 'draft', $entry['status'], 'A new extension is a draft.' );
		$this->assertSame( 3, $entry['sort'] );
		$this->assertSame( 'https://example.com/docs', $entry['docs_url'] );
		$this->assertSame( 'database-export', $entry['icon'] );

		$this->assertWPError( SFLS_Extensions::create( 'sfltest-sheets', array( 'title' => 'Again' ) ), 'exists' );
		$this->assertWPError( SFLS_Extensions::create( 'Bad Slug', array( 'title' => 'X' ) ), 'invalid_slug' );
		$this->assertWPError( SFLS_Extensions::create( SFLS_Releases::PRO, array( 'title' => 'X' ) ), 'invalid_slug' );
		$this->assertWPError( SFLS_Extensions::create( 'sfltest-x', array( 'title' => 'X', 'category' => 'nope' ) ), 'invalid_category' );
		$this->assertWPError( SFLS_Extensions::create( 'sfltest-x', array( 'title' => 'X', 'min-plan' => 'gold' ) ), 'invalid_min_plan' );
		$this->assertWPError( SFLS_Extensions::create( 'sfltest-x', array( 'category' => 'site' ) ), 'missing_title' );

		$updated = SFLS_Extensions::update( 'sfltest-sheets', array( 'min-plan' => 'agency', 'title' => 'Renamed' ) );
		$this->assertSame( 'agency', $updated['min_plan'] );
		$this->assertSame( 'Renamed', $updated['title'] );
		$this->assertSame( 'database-export', $updated['icon'], 'Fields that were not named stay.' );
		$this->assertWPError( SFLS_Extensions::update( 'sfltest-missing', array( 'title' => 'X' ) ), 'not_found' );
		$this->assertWPError( SFLS_Extensions::update( 'sfltest-sheets', array() ), 'nothing_to_update' );

		$this->assertSame( 'published', SFLS_Extensions::update( 'sfltest-sheets', array( 'status' => 'published' ) )['status'] );
		$this->assertSame( 'draft', SFLS_Extensions::update( 'sfltest-sheets', array( 'status' => 'draft' ) )['status'] );
		$this->assertWPError( SFLS_Extensions::update( 'sfltest-sheets', array( 'status' => 'live' ) ), 'invalid_status' );
	}

	/**
	 * The CLI class does the same through wp sfls extension.
	 */
	public function test_cli_commands(): void {
		$phar = getenv( 'SFLS_WP_CLI' ) ? getenv( 'SFLS_WP_CLI' ) : '/tmp/claude-0/wp-cli.phar';
		if ( ! function_exists( 'exec' ) || ! is_file( $phar ) ) {
			$this->markTestSkipped( 'WP-CLI not found. Set SFLS_WP_CLI.' );
		}
		[ $code, $out ] = $this->wp( 'sfls extension add sfltest-cli --title="CLI test" --description="A test." --category=fields --min-plan=starter --icon=editor-code --docs-url=https://example.com/d --sort=7' );
		$this->assertSame( 0, $code, $out );
		$this->assertStringContainsString( 'sfltest-cli added as a draft', $out );

		[ $code, $out ] = $this->wp( 'sfls extension add sfltest-cli --title="Again" --description="X." --category=fields --min-plan=pro' );
		$this->assertNotSame( 0, $code );
		$this->assertStringContainsString( 'already exists', $out );

		[ $code, $out ] = $this->wp( 'sfls extension add sfltest-bad --title=Bad --description="X." --category=nope --min-plan=pro' );
		$this->assertNotSame( 0, $code );
		$this->assertStringContainsString( 'category must be one of', $out );

		[ $code, $out ] = $this->wp( 'sfls extension publish sfltest-cli' );
		$this->assertSame( 0, $code, $out );
		$this->assertSame( 'published', SFLS_Extensions::get( 'sfltest-cli' )['status'] );

		[ $code, $out ] = $this->wp( 'sfls extension update sfltest-cli --min-plan=agency --sort=2' );
		$this->assertSame( 0, $code, $out );
		$row = SFLS_Extensions::get( 'sfltest-cli' );
		$this->assertSame( 'agency', $row['min_plan'] );
		$this->assertSame( 2, $row['sort'] );

		[ , $json ] = $this->wp( 'sfls extension list --format=json' );
		$list = json_decode( $json, true );
		$this->assertContains( 'sfltest-cli', array_column( (array) $list, 'slug' ) );

		[ $code, $out ] = $this->wp( 'sfls extension unpublish sfltest-cli' );
		$this->assertSame( 0, $code, $out );
		$this->assertSame( 'draft', SFLS_Extensions::get( 'sfltest-cli' )['status'] );

		[ $code, ] = $this->wp( 'sfls extension publish sfltest-nothing' );
		$this->assertNotSame( 0, $code );
	}

	/**
	 * Only published entries are served, and the answer is signed.
	 */
	public function test_public_catalog_lists_only_published_and_is_signed(): void {
		$this->make_extension( 'live' );
		$this->make_extension( 'hidden', 'pro', false );

		$res  = $this->rest( 'GET', '/sfls/v1/extensions', array( 'sig_nonce' => 'abcdefghijklmnop0123' ) );
		$data = $res->get_data();

		$this->assertSame( 200, $res->get_status() );
		$slugs = array_column( $data['extensions'], 'slug' );
		$this->assertContains( 'sfltest-live', $slugs );
		$this->assertNotContains( 'sfltest-hidden', $slugs );
		$this->assertSame( 'abcdefghijklmnop0123', $data['sig_nonce'] );
		$this->assertTrue( $this->verifies( '/sfls/v1/extensions', $data ), 'The answer verifies with the public key.' );

		$tampered                          = $data;
		$tampered['extensions'][0]['slug'] = 'sfltest-other';
		$this->assertFalse( $this->verifies( '/sfls/v1/extensions', $tampered ) );
		$this->assertFalse( SFLS_Signing::verify( SFLS_Signing::response_message( '/sfls/v1/license/validate', json_decode( (string) wp_json_encode( $data ), true ) ), $data['signature'], $this->pair['public'] ), 'Bound to its route.' );
	}

	/**
	 * Every entry has the documented fields.
	 */
	public function test_entry_shape(): void {
		$slug = $this->make_extension( 'shape' );
		$mine = array_values( array_filter( $this->rest( 'GET', '/sfls/v1/extensions' )->get_data()['extensions'], static fn( $e ) => $e['slug'] === $slug ) )[0];
		$keys = array( 'slug', 'title', 'description', 'category', 'min_plan', 'icon', 'docs_url', 'sort', 'version', 'requires_free', 'requires_pro', 'requires_wp', 'requires_php', 'tested' );
		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $mine, $key );
		}
		$this->assertSame( 'pro', $mine['min_plan'] );
		$this->assertSame( 5, $mine['sort'] );
	}

	/**
	 * Version and requirements come from the latest published release of that product.
	 */
	public function test_version_and_requires_come_from_the_latest_published_release(): void {
		$slug  = $this->make_extension( 'rel' );
		$other = $this->make_extension( 'other' );
		$find  = fn( string $s ) => array_values( array_filter( $this->rest( 'GET', '/sfls/v1/extensions' )->get_data()['extensions'], static fn( $e ) => $e['slug'] === $s ) )[0];

		$this->isolate_releases();
		$this->assertNull( $find( $slug )['version'], 'No release yet.' );
		$this->assertNull( $find( $slug )['requires_wp'] );

		$this->add_extension_release( $slug, '1.0.0' );
		$this->add_extension_release( $slug, '1.1.0' );
		$draft = $this->add_extension_release( $slug, '1.2.0', array( 'publish' => false ) );
		$this->add_extension_release( $other, '9.0.0' );
		$this->add_release( '7.0.0' );

		$entry = $find( $slug );
		$this->assertSame( '1.1.0', $entry['version'], 'A draft is not offered, another product does not leak in.' );
		$this->assertSame( '6.5', $entry['requires_wp'] );
		$this->assertSame( '8.1', $entry['requires_php'] );
		$this->assertSame( '7.1', $entry['tested'] );
		$this->assertSame( '9.0.0', $find( $other )['version'] );

		SFLS_Releases::publish( $draft['id'] );
		$this->assertSame( '1.2.0', $find( $slug )['version'], 'Publishing clears the five minute cache.' );
		SFLS_Releases::yank( $draft['id'] );
		$this->assertSame( '1.1.0', $find( $slug )['version'] );
	}

	/**
	 * The catalog is cached for five minutes and a catalog change clears it.
	 */
	public function test_catalog_is_cached_and_cleared_on_change(): void {
		$slug = $this->make_extension( 'cache' );
		$this->rest( 'GET', '/sfls/v1/extensions' );
		$this->assertIsArray( get_transient( SFLS_Extensions::CACHE_KEY ) );

		SFLS_Extensions::update( $slug, array( 'title' => 'Changed title' ) );
		$this->assertFalse( get_transient( SFLS_Extensions::CACHE_KEY ) );
		$titles = array_column( $this->rest( 'GET', '/sfls/v1/extensions' )->get_data()['extensions'], 'title', 'slug' );
		$this->assertSame( 'Changed title', $titles[ $slug ] );
	}

	/**
	 * The answer refuses to go out unsigned while signing is down (503, like the licence answers).
	 */
	public function test_route_is_guarded_while_signing_is_unavailable(): void {
		$this->filter( 'sfls_require_signing', '__return_true', 20 );
		SFLS_Signing::use_test_key( '' );
		$res = $this->rest( 'GET', '/sfls/v1/extensions' );
		$this->assertSame( 503, $res->get_status() );
		$this->assertSame( 'signing_unavailable', $res->get_data()['reason'] );
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
