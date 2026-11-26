<?php
/**
 * 1.15.0 product downloads: /update/package and /update/download for Pro
 * (unchanged) and for extensions (three refusal codes, product bound links).
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Package and download per product.
 */
class SFLS_Extension_Download_Test extends SFLS_Test_Case {

	const HOST = 'dl.example.com';

	/**
	 * Key pair made for this test.
	 *
	 * @var array{secret:string,public:string}
	 */
	private array $pair = array();

	/**
	 * Set up: a signing key and no releases.
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
	 * A license activated on the test site.
	 *
	 * @param string $plan Plan.
	 */
	private function licence( string $plan = 'pro' ): array {
		$lic = $this->make_license( array( 'plan' => $plan ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], self::HOST );
		return $lic;
	}

	/**
	 * Query arguments of an address.
	 *
	 * @param string $url Address.
	 */
	private function args( string $url ): array {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return $args;
	}

	/**
	 * Package request for a license and product.
	 *
	 * @param array  $lic     License.
	 * @param string $product Product.
	 * @param string $version Version.
	 */
	private function package( array $lic, string $product, string $version = '' ): WP_REST_Response {
		$extra = '' === $version ? array() : array( 'version' => $version );
		if ( SFLS_Releases::PRO !== $product ) {
			$extra['product'] = $product;
		}
		return $this->rest( 'GET', '/sfls/v1/update/package', $this->args( SFLS_REST::package_address( $lic, self::HOST, $extra, $product ) ) );
	}

	/**
	 * The release a download link would stream (the route streams and exits, so the test stops it just before), or the refusal.
	 *
	 * @param string $location Signed link.
	 * @param array  $override Query arguments to change.
	 * @return array|WP_REST_Response
	 */
	private function download( string $location, array $override = array() ) {
		$served = null;
		$hook   = static function ( $release ) use ( &$served ) {
			$served = $release;
			throw new RuntimeException( 'stop before streaming' );
		};
		add_action( 'sfls_release_downloaded', $hook );
		try {
			$res = $this->rest( 'GET', '/sfls/v1/update/download', array_merge( $this->args( $location ), $override ) );
		} catch ( RuntimeException $e ) {
			unset( $e );
			return (array) $served;
		} finally {
			remove_action( 'sfls_release_downloaded', $hook );
		}
		return $res;
	}

	/**
	 * Pro: the address, token and link are what they were before extensions.
	 */
	public function test_pro_links_are_unchanged(): void {
		$this->add_release( '9.4.0' );
		$lic = $this->licence();

		$address = SFLS_REST::package_address( $lic, self::HOST, array( 'version' => '9.4.0' ) );
		$args    = $this->args( $address );
		$this->assertArrayNotHasKey( 'product', $args );
		$legacy = hash_hmac( 'sha256', 'package|' . (int) $lic['id'] . '|' . strtoupper( $lic['license_key'] ) . '|' . self::HOST, wp_salt( 'auth' ) . 'subtleforms-license-server' );
		$this->assertSame( $legacy, $args['ptoken'], 'The token of a Pro address is the one cached addresses carry.' );

		$res = $this->rest( 'GET', '/sfls/v1/update/package', $args );
		$this->assertSame( 302, $res->get_status() );
		$link = $res->get_headers()['Location'];
		$q    = $this->args( $link );
		$this->assertArrayNotHasKey( 'product', $q );
		$message = strtoupper( $lic['license_key'] ) . '|' . self::HOST . '|' . $q['expires'] . '|' . $q['token'] . '|v=9.4.0';
		$this->assertSame( hash_hmac( 'sha256', $message, wp_salt( 'auth' ) . 'subtleforms-license-server' ), $q['sig'], 'The Pro link signature does not cover a product.' );

		$served = $this->download( $link );
		$this->assertSame( '9.4.0', $served['version'] );
		$this->assertSame( SFLS_Releases::PRO, $served['product'] );
	}

	/**
	 * Pro: a package address made with the product in the token also works, and a different product does not.
	 */
	public function test_pro_accepts_the_product_token_too(): void {
		$this->add_release( '9.4.0' );
		$lic   = $this->licence();
		$token = hash_hmac( 'sha256', 'package|' . SFLS_Releases::PRO . '|' . (int) $lic['id'] . '|' . strtoupper( $lic['license_key'] ) . '|' . self::HOST, wp_salt( 'auth' ) . 'subtleforms-license-server' );
		$args  = $this->args( SFLS_REST::package_address( $lic, self::HOST, array( 'version' => '9.4.0' ) ) );

		$args['ptoken'] = $token;
		$this->assertSame( 302, $this->rest( 'GET', '/sfls/v1/update/package', $args )->get_status() );
	}

	/**
	 * Extension: a valid, entitled, activated license gets a link that serves that product.
	 */
	public function test_extension_download_works_for_an_entitled_licence(): void {
		$slug = $this->make_extension( 'dl' );
		$this->add_release( '9.4.0' );
		$this->add_extension_release( $slug, '1.0.0' );
		$lic = $this->licence();

		$res = $this->package( $lic, $slug, '1.0.0' );
		$this->assertSame( 302, $res->get_status() );
		$q = $this->args( $res->get_headers()['Location'] );
		$this->assertSame( $slug, $q['product'] );
		$this->assertSame( '1.0.0', $q['version'] );

		$served = $this->download( $res->get_headers()['Location'] );
		$this->assertSame( $slug, $served['product'] );
		$this->assertSame( '1.0.0', $served['version'] );
		$this->assertSame( 1, (int) SFLS_Releases::get( '1.0.0', true, $slug )['downloads'] );
		$this->assertSame( 0, (int) SFLS_Releases::get( '9.4.0', true )['downloads'] );

		$this->assertNotSame( '', SFLS_Signing::release_signature( SFLS_Releases::get( '1.0.0', false, $slug ) ), 'The package signature is kept for sha256 and package_sig checks.' );
	}

	/**
	 * Without a version the latest published release of the product is served, whatever Pro has.
	 */
	public function test_extension_without_version_gets_its_latest(): void {
		$slug = $this->make_extension( 'dl' );
		$this->add_release( '9.9.0' );
		$this->add_extension_release( $slug, '1.0.0' );
		$this->add_extension_release( $slug, '1.1.0' );
		$res = $this->package( $this->licence(), $slug );
		$this->assertSame( 302, $res->get_status() );
		$this->assertSame( '1.1.0', $this->download( $res->get_headers()['Location'] )['version'] );
	}

	/**
	 * Expired licence: licence_inactive, 403.
	 */
	public function test_expired_licence_is_refused_with_licence_inactive(): void {
		$slug = $this->make_extension( 'dl' );
		$this->add_extension_release( $slug, '1.0.0' );
		$lic = $this->licence();
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );

		$res = $this->package( $lic, $slug, '1.0.0' );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'licence_inactive', $res->get_data()['reason'] );
	}

	/**
	 * A licence in grace may still download.
	 */
	public function test_grace_licence_may_download(): void {
		$slug = $this->make_extension( 'dl' );
		$this->add_extension_release( $slug, '1.0.0' );
		$lic = $this->licence();
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );

		$this->assertSame( 302, $this->package( $lic, $slug, '1.0.0' )->get_status() );
	}

	/**
	 * A site the licence is not activated on: site_not_activated, 403.
	 */
	public function test_unactivated_site_is_refused_with_site_not_activated(): void {
		$slug = $this->make_extension( 'dl' );
		$this->add_extension_release( $slug, '1.0.0' );
		$lic = $this->make_license( array( 'plan' => 'pro' ) );

		$res = $this->package( $lic, $slug, '1.0.0' );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'site_not_activated', $res->get_data()['reason'] );
	}

	/**
	 * An extension above the plan, a draft and an unknown product: not_entitled, 403.
	 */
	public function test_extension_outside_the_plan_is_refused_with_not_entitled(): void {
		$agency = $this->make_extension( 'agency', 'agency' );
		$draft  = $this->make_extension( 'draft', 'starter', false );
		$this->add_extension_release( $agency, '1.0.0' );
		$this->add_extension_release( $draft, '1.0.0' );
		$lic = $this->licence( 'pro' );

		foreach ( array( $agency, $draft, 'sfltest-unknown' ) as $slug ) {
			$res = $this->package( $lic, $slug, '1.0.0' );
			$this->assertSame( 403, $res->get_status(), $slug );
			$this->assertSame( 'not_entitled', $res->get_data()['reason'], $slug );
		}
		$this->assertSame( 302, $this->package( $this->licence( 'agency' ), $agency, '1.0.0' )->get_status() );
	}

	/**
	 * Refusals come in this order: inactive, then site, then plan.
	 */
	public function test_refusal_order(): void {
		$agency = $this->make_extension( 'agency', 'agency' );
		$this->add_extension_release( $agency, '1.0.0' );
		$lic = $this->make_license( array( 'plan' => 'starter' ) );

		$this->assertSame( 'site_not_activated', $this->package( $lic, $agency, '1.0.0' )->get_data()['reason'] );
		SFLS_Licenses::add_activation( (int) $lic['id'], self::HOST );
		$this->assertSame( 'not_entitled', $this->package( $lic, $agency, '1.0.0' )->get_data()['reason'] );
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 'licence_inactive', $this->package( $lic, $agency, '1.0.0' )->get_data()['reason'] );
	}

	/**
	 * A token for product A cannot fetch product B, at the package route or the download route.
	 */
	public function test_a_token_for_one_product_cannot_download_another(): void {
		$a = $this->make_extension( 'one' );
		$b = $this->make_extension( 'two' );
		$this->add_extension_release( $a, '1.0.0' );
		$this->add_extension_release( $b, '2.0.0' );
		$lic = $this->licence();

		// The package address of A, pointed at B.
		$args            = $this->args( SFLS_REST::package_address( $lic, self::HOST, array( 'product' => $a, 'version' => '1.0.0' ), $a ) );
		$args['product'] = $b;
		$res             = $this->rest( 'GET', '/sfls/v1/update/package', $args );
		$this->assertGreaterThanOrEqual( 400, $res->get_status() );
		$this->assertNotSame( 302, $res->get_status() );

		// The address of A without its product is a Pro address: not valid either.
		unset( $args['product'] );
		$this->assertNotSame( 302, $this->rest( 'GET', '/sfls/v1/update/package', $args )->get_status() );

		// The signed link of A, pointed at B or at Pro.
		$link = $this->package( $lic, $a, '1.0.0' )->get_headers()['Location'];
		$res  = $this->download( $link, array( 'product' => $b ) );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 'invalid_signature', $res->get_data()['reason'] );
		$res = $this->download( $link, array( 'product' => SFLS_Releases::PRO ) );
		$this->assertSame( 'invalid_signature', $res->get_data()['reason'] );
		$res = $this->download( $link, array( 'product' => '' ) );
		$this->assertSame( 'invalid_signature', $res->get_data()['reason'], 'Dropping the product makes it a Pro link.' );

		// A Pro link cannot be turned into an extension link.
		$this->add_release( '9.4.0' );
		$pro_link = $this->package( $lic, SFLS_Releases::PRO, '9.4.0' )->get_headers()['Location'];
		$res      = $this->download( $pro_link, array( 'product' => $a ) );
		$this->assertSame( 'invalid_signature', $res->get_data()['reason'] );
	}

	/**
	 * An expired link is refused.
	 */
	public function test_expired_link_is_refused(): void {
		$slug = $this->make_extension( 'dl' );
		$this->add_extension_release( $slug, '1.0.0' );
		$lic     = $this->licence();
		$expires = time() - 30;
		$token   = bin2hex( random_bytes( 16 ) );
		$link    = add_query_arg(
			array(
				'lid'     => $lic['id'],
				'site'    => self::HOST,
				'expires' => $expires,
				'token'   => $token,
				'sig'     => SFLS_Releases::sign( $lic['license_key'], self::HOST, $expires, $token, '1.0.0', $slug ),
				'version' => '1.0.0',
				'product' => $slug,
			),
			rest_url( 'sfls/v1/update/download' )
		);

		$res = $this->download( $link );

		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'invalid_signature', $res->get_data()['reason'] );
	}

	/**
	 * A link works once.
	 */
	public function test_link_is_single_use(): void {
		$slug = $this->make_extension( 'dl' );
		$this->add_extension_release( $slug, '1.0.0' );
		$link = $this->package( $this->licence(), $slug, '1.0.0' )->get_headers()['Location'];

		$this->assertSame( '1.0.0', $this->download( $link )['version'] );
		$second = $this->download( $link );

		$this->assertInstanceOf( WP_REST_Response::class, $second );
		$this->assertSame( 403, $second->get_status() );
		$this->assertSame( 'link_used', $second->get_data()['reason'] );
	}

	/**
	 * A licence that lapses after the link was made is refused at download, and the refusal does not use the link up.
	 */
	public function test_download_checks_the_licence_again(): void {
		$slug = $this->make_extension( 'dl' );
		$this->add_extension_release( $slug, '1.0.0' );
		$lic  = $this->licence();
		$link = $this->package( $lic, $slug, '1.0.0' )->get_headers()['Location'];

		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$res = $this->download( $link );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 'licence_inactive', $res->get_data()['reason'] );

		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
		$this->assertSame( '1.0.0', $this->download( $link )['version'], 'The refused request did not burn the link.' );
	}

	/**
	 * A site removed from the licence after the link was made is refused too.
	 */
	public function test_download_refuses_a_site_that_was_removed(): void {
		$slug = $this->make_extension( 'dl' );
		$this->add_extension_release( $slug, '1.0.0' );
		$lic  = $this->licence();
		$link = $this->package( $lic, $slug, '1.0.0' )->get_headers()['Location'];

		SFLS_Licenses::remove_activation( (int) $lic['id'], self::HOST, 'api' );
		$res = $this->download( $link );

		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 'site_not_activated', $res->get_data()['reason'] );
	}

	/**
	 * A draft or yanked version is not served.
	 */
	public function test_unpublished_extension_version_is_not_served(): void {
		$slug    = $this->make_extension( 'dl' );
		$release = $this->add_extension_release( $slug, '1.0.0' );
		$lic     = $this->licence();
		SFLS_Releases::yank( $release['id'] );

		$res = $this->package( $lic, $slug, '1.0.0' );

		$this->assertSame( 410, $res->get_status() );
		$this->assertSame( 'version_withdrawn', $res->get_data()['reason'] );
	}
}
