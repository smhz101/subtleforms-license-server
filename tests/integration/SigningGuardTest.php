<?php
/**
 * Package and download routes answer 503 while the signing key is missing,
 * and a release cannot be published without a signature.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Signing guard.
 */
class SFLS_Signing_Guard_Test extends SFLS_Test_Case {

	/**
	 * Set up: a good test key.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->filter( 'sfls_require_signing', '__return_true', 20 );
		SFLS_Signing::use_test_key( SFLS_Signing::generate()['secret'] );
	}

	/**
	 * Tear down: back to the configured key.
	 */
	protected function tearDown(): void {
		SFLS_Signing::use_test_key( null );
		parent::tearDown();
	}

	/**
	 * Without a key, package and download answer 503 signing_unavailable.
	 */
	public function test_routes_refused_without_key(): void {
		SFLS_Signing::use_test_key( '' );
		foreach ( array( '/sfls/v1/update/package', '/sfls/v1/update/download' ) as $route ) {
			$res = $this->rest( 'GET', $route );
			$this->assertSame( 503, $res->get_status(), $route );
			$this->assertSame( 'signing_unavailable', $res->get_data()['reason'], $route );
		}
	}

	/**
	 * With a key, the routes are not refused with 503.
	 */
	public function test_routes_dispatch_with_key(): void {
		foreach ( array( '/sfls/v1/update/package', '/sfls/v1/update/download' ) as $route ) {
			$this->assertNotSame( 503, $this->rest( 'GET', $route )->get_status(), $route );
		}
	}

	/**
	 * Publishing is refused without a key and works with one.
	 */
	public function test_publish_requires_signing(): void {
		$rel = $this->add_release( '9.8.0', array( 'publish' => false ) );
		SFLS_Signing::use_test_key( '' );
		$res = SFLS_Releases::publish( $rel['id'] );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'signing_unavailable', $res->get_error_code() );
		$this->assertSame( 'draft', SFLS_Releases::get_by_id( $rel['id'] )['status'] );

		SFLS_Signing::use_test_key( SFLS_Signing::generate()['secret'] );
		$res = SFLS_Releases::publish( $rel['id'] );
		$this->assertIsArray( $res );
		$this->assertSame( 'published', $res['status'] );
	}
}
