<?php
/**
 * 1.15.0 batched update check: one request for Pro and every installed
 * extension, and the old single-product request unchanged.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * /update/check with and without `products`.
 */
class SFLS_Extension_Update_Check_Test extends SFLS_Test_Case {

	const SITE = 'https://upd.example.com';

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
		SFLS_Licenses::add_activation( (int) $lic['id'], 'upd.example.com' );
		return $lic;
	}

	/**
	 * Update check.
	 *
	 * @param array  $lic    License.
	 * @param array  $params More params.
	 * @param string $method Method.
	 */
	private function check( array $lic, array $params = array(), string $method = 'GET' ): WP_REST_Response {
		return $this->rest(
			$method,
			'/sfls/v1/update/check',
			array_merge(
				array(
					'license_key' => $lic['license_key'],
					'site_url'    => self::SITE,
					'version'     => '1.0.0',
				),
				$params
			)
		);
	}

	/**
	 * An answer without what changes on every call.
	 *
	 * @param array $data Answer.
	 */
	private function stable( array $data ): string {
		unset( $data['signed_at'], $data['signature'] );
		return (string) wp_json_encode( $data );
	}

	/**
	 * Without `products` the answer is what it was, even with extensions and their releases around.
	 */
	public function test_old_request_returns_the_old_shape(): void {
		$this->add_release( '9.4.0' );
		$lic    = $this->licence();
		$before = $this->check( $lic )->get_data();

		$this->assertSame(
			array( 'new_version', 'requires', 'tested', 'requires_php', 'last_updated', 'changelog', 'channel', 'package', 'package_sha256', 'package_sig', 'signed_at', 'signature' ),
			array_keys( $before )
		);
		$this->assertSame( '9.4.0', $before['new_version'] );
		$this->assertArrayNotHasKey( 'products', $before );

		$slug = $this->make_extension( 'upd' );
		$this->add_extension_release( $slug, '99.0.0' );
		$after = $this->check( $lic )->get_data();

		$this->assertSame( $this->stable( $before ), $this->stable( $after ), 'Extensions do not change the single-product answer.' );
	}

	/**
	 * Without `products` and without a release the answer is still the empty object.
	 */
	public function test_old_request_without_a_release_is_still_empty(): void {
		$slug = $this->make_extension( 'upd' );
		$this->add_extension_release( $slug, '1.0.0' );
		$data = $this->check( $this->licence() )->get_data();
		$this->assertArrayNotHasKey( 'new_version', $data );
		$this->assertArrayNotHasKey( 'products', $data );
	}

	/**
	 * A batched request returns only the products the licence may download and that have a newer release.
	 */
	public function test_batched_request_returns_only_entitled_newer_products(): void {
		$newer   = $this->make_extension( 'newer' );
		$current = $this->make_extension( 'current' );
		$locked  = $this->make_extension( 'locked', 'agency' );
		$draft   = $this->make_extension( 'draft', 'starter', false );
		$this->add_release( '9.4.0' );
		$this->add_extension_release( $newer, '1.2.0' );
		$this->add_extension_release( $current, '1.0.0' );
		$this->add_extension_release( $locked, '5.0.0' );
		$this->add_extension_release( $draft, '5.0.0' );
		$lic = $this->licence( 'pro' );

		$res  = $this->check(
			$lic,
			array(
				'products' => array(
					SFLS_Releases::PRO => '9.0.0',
					$newer             => '1.1.0',
					$current           => '1.0.0',
					$locked            => '1.0.0',
					$draft             => '1.0.0',
					'sfltest-ghost'    => '1.0.0',
				),
			),
			'POST'
		);
		$data = $res->get_data();

		$this->assertSame( 200, $res->get_status() );
		$products = (array) $data['products'];
		$this->assertSame( array( $newer, SFLS_Releases::PRO ), $this->sorted_keys( $products ) );
		$this->assertSame( '1.2.0', $products[ $newer ]['version'] );
		$this->assertSame( '9.4.0', $products[ SFLS_Releases::PRO ]['version'] );
		$this->assertArrayNotHasKey( 'new_version', $data, 'The batched answer is its own shape.' );
	}

	/**
	 * Keys, order of keys does not matter.
	 *
	 * @param array $products Products.
	 * @return string[]
	 */
	private function sorted_keys( array $products ): array {
		$keys = array_keys( $products );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * Each entry has the documented fields, and its package address gives a signed link for that product.
	 */
	public function test_entry_fields_and_package(): void {
		$slug = $this->make_extension( 'upd' );
		$rel  = $this->add_extension_release( $slug, '1.2.0', array( 'changelog' => "* Fixed a thing\n* Added a thing" ) );
		$lic  = $this->licence();

		$products = (array) $this->check( $lic, array( 'products' => wp_json_encode( array( $slug => '1.0.0' ) ) ) )->get_data()['products'];
		$entry    = (array) $products[ $slug ];

		foreach ( array( 'version', 'package', 'requires', 'requires_php', 'tested', 'changelog', 'sha256', 'package_sig' ) as $field ) {
			$this->assertArrayHasKey( $field, $entry, $field );
		}
		$this->assertSame( '6.5', $entry['requires'] );
		$this->assertSame( '8.1', $entry['requires_php'] );
		$this->assertSame( '7.1', $entry['tested'] );
		$this->assertStringContainsString( 'Fixed a thing', $entry['changelog'] );
		$this->assertSame( $rel['sha256'], $entry['sha256'] );
		$this->assertSame( SFLS_Signing::release_signature( $rel ), $entry['package_sig'] );
		$this->assertTrue( SFLS_Signing::verify( SFLS_Signing::package_message( '1.2.0', $rel['sha256'], $slug ), $entry['package_sig'], $this->pair['public'] ), 'An extension package is signed under its own slug.' );

		wp_parse_str( (string) wp_parse_url( $entry['package'], PHP_URL_QUERY ), $args );
		$this->assertSame( $slug, $args['product'] );
		$res = $this->rest( 'GET', '/sfls/v1/update/package', $args );
		$this->assertSame( 302, $res->get_status() );
	}

	/**
	 * GET with a JSON-encoded products value works like a POST body.
	 */
	public function test_get_json_and_post_object_agree(): void {
		$slug = $this->make_extension( 'upd' );
		$this->add_extension_release( $slug, '1.2.0' );
		$lic  = $this->licence();
		$list = array( $slug => '1.0.0' );

		$get  = $this->check( $lic, array( 'products' => wp_json_encode( $list ) ), 'GET' )->get_data();
		$post = $this->check( $lic, array( 'products' => $list ), 'POST' )->get_data();

		$this->assertSame( array( $slug ), array_keys( (array) $get['products'] ) );
		$this->assertSame( $this->stable( $get ), $this->stable( $post ) );
	}

	/**
	 * The batched answer is signed and the signature verifies, also when it is empty.
	 */
	public function test_batched_answer_is_signed(): void {
		$slug = $this->make_extension( 'upd' );
		$this->add_extension_release( $slug, '1.2.0' );
		$lic = $this->licence();
		foreach ( array( array( $slug => '1.0.0' ), array( $slug => '1.2.0' ) ) as $list ) {
			$data = $this->check( $lic, array( 'products' => $list, 'sig_nonce' => 'abcdefghijklmnop0123' ), 'POST' )->get_data();
			$body = json_decode( (string) wp_json_encode( $data ), true );
			$this->assertSame( 'abcdefghijklmnop0123', $body['sig_nonce'] );
			$this->assertTrue( SFLS_Signing::verify( SFLS_Signing::response_message( '/sfls/v1/update/check', $body ), $body['signature'], $this->pair['public'] ) );
		}
		$this->assertSame( '{}', (string) wp_json_encode( $data['products'] ), 'Nothing to update is an empty object.' );
	}

	/**
	 * A licence that is not valid, or a site it is not activated on, gets nothing.
	 */
	public function test_no_products_for_an_inactive_licence_or_unactivated_site(): void {
		$slug = $this->make_extension( 'upd' );
		$this->add_release( '9.4.0' );
		$this->add_extension_release( $slug, '1.2.0' );
		$products = array(
			SFLS_Releases::PRO => '1.0.0',
			$slug              => '1.0.0',
		);

		$expired = $this->licence();
		SFLS_Licenses::update( (int) $expired['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$this->assertSame( array(), (array) $this->check( $expired, array( 'products' => $products ), 'POST' )->get_data()['products'] );

		$elsewhere = $this->make_license( array( 'plan' => 'pro' ) );
		$this->assertSame( array(), (array) $this->check( $elsewhere, array( 'products' => $products ), 'POST' )->get_data()['products'] );
	}

	/**
	 * An unknown key is answered as before.
	 */
	public function test_unknown_key_in_a_batched_request(): void {
		$res = $this->rest(
			'POST',
			'/sfls/v1/update/check',
			array(
				'license_key' => SFLS_Licenses::generate_key(),
				'site_url'    => self::SITE,
				'products'    => array( SFLS_Releases::PRO => '1.0.0' ),
			)
		);
		$this->assertSame( array(), (array) $res->get_data()['products'] );
	}

	/**
	 * `products` that is not an object of versions is refused.
	 */
	public function test_malformed_products_are_refused(): void {
		$lic = $this->licence();
		foreach ( array( 'not json', '["a","b"]', '{"a":{"b":1}}', '"x"' ) as $bad ) {
			$res = $this->check( $lic, array( 'products' => $bad ) );
			$this->assertSame( 400, $res->get_status(), $bad );
			$this->assertSame( 'invalid_products', $res->get_data()['reason'], $bad );
		}
	}

	/**
	 * Pro in a batched request follows the same pin and channel rules as the single request.
	 */
	public function test_pro_in_a_batch_matches_the_single_check(): void {
		$this->add_release( '9.4.0' );
		$this->add_release( '9.5.0' );
		SFLS_Releases::pin( '9.4.0' );
		$lic = $this->licence();

		$single  = $this->check( $lic )->get_data();
		$batched = (array) $this->check( $lic, array( 'products' => array( SFLS_Releases::PRO => '1.0.0' ) ), 'POST' )->get_data()['products'];

		$this->assertSame( '9.4.0', $single['new_version'] );
		$this->assertSame( $single['new_version'], $batched[ SFLS_Releases::PRO ]['version'] );
		$this->assertSame( $single['package'], $batched[ SFLS_Releases::PRO ]['package'], 'Same address as the single check.' );
		$this->assertSame( $single['package_sig'], $batched[ SFLS_Releases::PRO ]['package_sig'] );
	}
}
