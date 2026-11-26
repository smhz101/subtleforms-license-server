<?php
/**
 * 1.15.0 entitlement: the signed licence answer lists the extensions the
 * plan includes.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * `extensions` in /license/validate and /license/status.
 */
class SFLS_Extension_Entitlement_Test extends SFLS_Test_Case {

	const SITE = 'https://ent.example.com';

	/**
	 * Key pair made for this test.
	 *
	 * @var array{secret:string,public:string}
	 */
	private array $pair = array();

	/**
	 * Set up: a signing key and a catalog of our own on top of whatever the site has.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->pair = SFLS_Signing::generate();
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		$this->make_extension( 'zeta', 'starter' );
		$this->make_extension( 'alpha', 'starter' );
		$this->make_extension( 'mid', 'pro' );
		$this->make_extension( 'top', 'agency' );
		$this->make_extension( 'draft', 'starter', false );
	}

	/**
	 * Tear down: back to the configured key.
	 */
	protected function tearDown(): void {
		SFLS_Signing::use_test_key( null );
		parent::tearDown();
	}

	/**
	 * A license of a plan, activated on the test site.
	 *
	 * @param string $plan Plan.
	 * @param array  $args make_license() args.
	 */
	private function licence( string $plan, array $args = array() ): array {
		$lic = $this->make_license( array_merge( array( 'plan' => $plan ), $args ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'ent.example.com' );
		return $lic;
	}

	/**
	 * The licence answer.
	 *
	 * @param array  $lic   License.
	 * @param string $route validate | status.
	 */
	private function answer( array $lic, string $route = 'validate' ): array {
		return $this->rest(
			'validate' === $route ? 'POST' : 'GET',
			'/sfls/v1/license/' . $route,
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => self::SITE,
				'sig_nonce'   => 'abcdefghijklmnop0123',
			)
		)->get_data();
	}

	/**
	 * Our own extensions in an allowed list.
	 *
	 * @param array $data Answer.
	 * @return string[]
	 */
	private function mine( array $data ): array {
		return array_values( array_filter( $data['extensions']['allowed'], static fn( $s ) => str_starts_with( $s, 'sfltest-' ) ) );
	}

	/**
	 * Each plan gets the extensions at or below its rank, sorted, without drafts.
	 */
	public function test_each_plan_gets_its_allowed_list(): void {
		$starter = $this->answer( $this->licence( 'starter' ) );
		$pro     = $this->answer( $this->licence( 'pro' ) );
		$agency  = $this->answer( $this->licence( 'agency' ) );

		$this->assertSame( 'starter', $starter['extensions']['plan'] );
		$this->assertSame( array( 'sfltest-alpha', 'sfltest-zeta' ), $this->mine( $starter ) );
		$this->assertSame( 'pro', $pro['extensions']['plan'] );
		$this->assertSame( array( 'sfltest-alpha', 'sfltest-mid', 'sfltest-zeta' ), $this->mine( $pro ) );
		$this->assertSame( 'agency', $agency['extensions']['plan'] );
		$this->assertSame( array( 'sfltest-alpha', 'sfltest-mid', 'sfltest-top', 'sfltest-zeta' ), $this->mine( $agency ) );
		$this->assertNotContains( 'sfltest-draft', $agency['extensions']['allowed'], 'A draft is never entitled.' );
	}

	/**
	 * The list is sorted so the signed body is stable.
	 */
	public function test_list_is_sorted(): void {
		$allowed = $this->answer( $this->licence( 'agency' ) )['extensions']['allowed'];
		$sorted  = $allowed;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $allowed );
	}

	/**
	 * Both routes carry it, and the signed body covers it.
	 */
	public function test_validate_and_status_carry_it_inside_the_signature(): void {
		$lic = $this->licence( 'pro' );
		foreach ( array( 'validate', 'status' ) as $route ) {
			$data = $this->answer( $lic, $route );
			$this->assertSame( array( 'sfltest-alpha', 'sfltest-mid', 'sfltest-zeta' ), $this->mine( $data ), $route );
			$body = json_decode( (string) wp_json_encode( $data ), true );
			$this->assertTrue( SFLS_Signing::verify( SFLS_Signing::response_message( '/sfls/v1/license/' . $route, $body ), $body['signature'], $this->pair['public'] ), $route );

			$body['extensions']['allowed'][] = 'sfltest-top';
			$this->assertFalse( SFLS_Signing::verify( SFLS_Signing::response_message( '/sfls/v1/license/' . $route, $body ), $body['signature'], $this->pair['public'] ), 'An added extension breaks the signature.' );
		}
	}

	/**
	 * An expired licence keeps its list; the answer says it is no longer valid.
	 */
	public function test_expired_keeps_its_list(): void {
		$lic = $this->licence( 'pro' );
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );

		$data = $this->answer( $lic );

		$this->assertFalse( $data['valid'] );
		$this->assertSame( 'expired', $data['status'] );
		$this->assertSame( array( 'sfltest-alpha', 'sfltest-mid', 'sfltest-zeta' ), $this->mine( $data ) );
	}

	/**
	 * A licence in grace keeps it too.
	 */
	public function test_grace_keeps_its_list(): void {
		$lic = $this->licence( 'pro' );
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );

		$data = $this->answer( $lic );

		$this->assertSame( 'grace_period', $data['status'] );
		$this->assertSame( array( 'sfltest-alpha', 'sfltest-mid', 'sfltest-zeta' ), $this->mine( $data ) );
	}

	/**
	 * An unknown key has no extensions key; neither has a suspended licence.
	 */
	public function test_invalid_key_has_no_extensions(): void {
		$res = $this->rest(
			'POST',
			'/sfls/v1/license/validate',
			array(
				'license_key' => SFLS_Licenses::generate_key(),
				'site_url'    => self::SITE,
			)
		);
		$this->assertSame( 404, $res->get_status() );
		$this->assertArrayNotHasKey( 'extensions', $res->get_data() );

		$lic = $this->licence( 'pro' );
		SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'suspended' ) );
		$this->assertArrayNotHasKey( 'extensions', $this->answer( $lic ) );
	}

	/**
	 * A site that is not activated gets no list.
	 */
	public function test_site_not_activated_has_no_extensions(): void {
		$lic  = $this->make_license( array( 'plan' => 'pro' ) );
		$data = $this->rest(
			'POST',
			'/sfls/v1/license/validate',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://other-site.example.com',
			)
		)->get_data();
		$this->assertFalse( $data['valid'] );
		$this->assertArrayNotHasKey( 'extensions', $data );
	}
}
