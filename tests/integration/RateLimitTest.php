<?php
/**
 * LS-06: people who know a key cannot lock its activated sites out.
 *
 * @package SFLS
 */

/**
 * Per-key rate limits count failures only.
 */
class SFLS_Rate_Limit_Test extends SFLS_Test_Case {

	/**
	 * Turn rate limits on with a small limit.
	 *
	 * @param int   $limit    Requests per window.
	 * @param array $per_type Limits of single buckets (ip, site, pair, ceiling).
	 */
	private function limit( int $limit, array $per_type = array() ): void {
		$this->filter( 'sfls_rate_limit_enabled', '__return_true' );
		$this->filter(
			'sfls_rate_limit',
			static fn( $conf, $type = '' ) => array(
				'limit'  => $per_type[ $type ] ?? $limit,
				'window' => 600,
			),
			10,
			2
		);
	}

	/**
	 * Request params.
	 *
	 * @param array  $lic  License.
	 * @param string $site Site URL.
	 */
	private function params( array $lic, string $site ): array {
		return array(
			'license_key' => $lic['license_key'],
			'site_url'    => $site,
		);
	}

	/**
	 * The repro: failures from five other IPs and sites do not lock out the
	 * site the key is activated on.
	 */
	public function test_activated_site_is_not_locked_out_by_others(): void {
		$this->limit( 5 );
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'shop.example.com' );

		foreach ( range( 1, 5 ) as $n ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $n;
			$this->assertNotSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://attacker' . $n . '.example.net' ) )->get_status() );
		}

		// The same abuser asking again from the same address is refused...
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$status = 0;
		foreach ( range( 1, 5 ) as $n ) {
			$status = $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://attacker1.example.net' ) )->get_status();
		}
		$this->assertSame( 429, $status );

		// ...but the real site keeps validating and updating.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
		$ok = $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://shop.example.com' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertTrue( $ok->get_data()['valid'] );
		$this->assertNotSame( 429, $this->rest( 'GET', '/sfls/v1/license/status', $this->params( $lic, 'https://shop.example.com' ) )->get_status() );
	}

	/**
	 * LS-06: a valid key with free seats activates a new site even after
	 * others burned failures for the key from other addresses and sites.
	 */
	public function test_new_site_with_a_free_seat_is_not_blocked_by_others(): void {
		$this->limit( 3 );
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'first.example.com' );

		// Twenty strangers from twenty addresses, each one failing for this key.
		foreach ( range( 1, 20 ) as $n ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $n;
			$this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://stranger' . $n . '.example.net' ) );
		}

		$_SERVER['REMOTE_ADDR'] = '203.0.113.172';
		$response = $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic, 'https://second.example.com' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['activated'] );
		$this->assertNotNull( SFLS_Licenses::activation( (int) $lic['id'], 'second.example.com' ) );
	}

	/**
	 * A pair of address and site has its own failure budget.
	 */
	public function test_failures_are_budgeted_per_address_and_site(): void {
		$this->limit( 100, array( 'pair' => 2 ) );
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'shop.example.com' );

		// The seat is taken, so every other site fails.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		foreach ( range( 1, 2 ) as $n ) {
			$this->assertSame( 400, $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic, 'https://other.example.net' ) )->get_status() );
		}
		$this->assertSame( 429, $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic, 'https://other.example.net' ) )->get_status() );

		// Another address, and the same address for another site, have their own.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		$this->assertSame( 400, $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic, 'https://other.example.net' ) )->get_status() );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$this->assertSame( 400, $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic, 'https://third.example.net' ) )->get_status() );
	}

	/**
	 * The per-key ceiling is high but real: a flood from many addresses and
	 * sites is stopped, and the activated site still works.
	 */
	public function test_global_ceiling_per_key_stops_a_flood(): void {
		$this->limit( 100, array( 'ceiling' => 1 ) );
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'shop.example.com' );

		$ceiling = 10;
		foreach ( range( 1, $ceiling ) as $n ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $n;
			$this->assertSame( 400, $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic, 'https://flood' . $n . '.example.net' ) )->get_status(), "Request $n" );
		}
		$_SERVER['REMOTE_ADDR'] = '198.51.100.200';
		$this->assertSame( 429, $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic, 'https://flood99.example.net' ) )->get_status() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.171';
		$this->assertSame( 200, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://shop.example.com' ) )->get_status() );
	}

	/**
	 * Successful requests never use up the key: an Agency license on many
	 * sites can check in from all of them at once.
	 */
	public function test_successes_are_counted_per_site(): void {
		$this->limit( 3 );
		$lic = $this->make_license( array( 'plan' => 'agency' ) );
		foreach ( range( 1, 10 ) as $n ) {
			SFLS_Licenses::add_activation( (int) $lic['id'], 'site' . $n . '.example.com' );
		}
		foreach ( range( 1, 10 ) as $n ) {
			$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $n;
			$this->assertSame( 200, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://site' . $n . '.example.com' ) )->get_status(), "Site $n" );
		}

		// One site hammering is still limited, on its own.
		$_SERVER['REMOTE_ADDR'] = '192.0.2.50';
		foreach ( range( 1, 2 ) as $n ) {
			$this->assertSame( 200, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://site1.example.com' ) )->get_status() );
		}
		$_SERVER['REMOTE_ADDR'] = '192.0.2.51';
		$this->assertSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://site1.example.com' ) )->get_status() );
		$this->assertSame( 200, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://site2.example.com' ) )->get_status() );
	}

	/**
	 * The ceiling grows with the sites the license is on: an expired
	 * license on many sites still gets its "expired" answers.
	 */
	public function test_failure_ceiling_scales_with_sites(): void {
		$this->limit( 100, array( 'ceiling' => 1 ) );
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		foreach ( range( 1, 4 ) as $n ) {
			SFLS_Licenses::add_activation( (int) $lic['id'], 'old' . $n . '.example.com' );
		}
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
		// One site would have a ceiling of 10; four sites have 40.
		foreach ( range( 1, 30 ) as $n ) {
			$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $n;
			$data = $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://old' . ( 1 + $n % 4 ) . '.example.com' ) )->get_data();
			$this->assertSame( 'expired', $data['reason'] ?? '', "Request $n" );
		}
	}

	/**
	 * Checkout polling keeps an address-wide ceiling: new order or txn values
	 * on every request do not open a fresh bucket.
	 */
	public function test_checkout_polling_has_an_address_ceiling(): void {
		$this->limit( 100, array( 'pollip' => 3 ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		foreach ( range( 1, 3 ) as $n ) {
			$this->assertNotSame( 429, $this->rest( 'GET', '/sfls/v1/paddle/checkout', array( 'txn' => 'txn_unknown' . $n ) )->get_status() );
		}
		$this->assertSame( 429, $this->rest( 'GET', '/sfls/v1/paddle/checkout', array( 'txn' => 'txn_unknown4' ) )->get_status() );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$this->assertNotSame( 429, $this->rest( 'GET', '/sfls/v1/paddle/checkout', array( 'txn' => 'txn_unknown5' ) )->get_status() );
	}
}
