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
	 * @param int $limit Requests per window.
	 */
	private function limit( int $limit ): void {
		$this->filter( 'sfls_rate_limit_enabled', '__return_true' );
		$this->filter(
			'sfls_rate_limit',
			static fn() => array(
				'limit'  => $limit,
				'window' => 600,
			)
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
	 * The repro: five requests from five IPs, then a sixth from a new IP for
	 * the site the key is activated on is served.
	 */
	public function test_activated_site_is_not_locked_out_by_others(): void {
		$this->limit( 5 );
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'shop.example.com' );

		foreach ( range( 1, 5 ) as $n ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $n;
			$this->assertNotSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://attacker' . $n . '.example.net' ) )->get_status() );
		}

		// The abuser's next attempt is refused...
		$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
		$this->assertSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://attacker9.example.net' ) )->get_status() );

		// ...but the real site keeps validating and updating.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
		$ok = $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://shop.example.com' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertTrue( $ok->get_data()['valid'] );
		$this->assertNotSame( 429, $this->rest( 'GET', '/sfls/v1/license/status', $this->params( $lic, 'https://shop.example.com' ) )->get_status() );
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
	 * The failure budget grows with the sites the license is on: an expired
	 * license on many sites still gets its "expired" answers.
	 */
	public function test_failure_budget_scales_with_sites(): void {
		$this->limit( 2 );
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		foreach ( range( 1, 4 ) as $n ) {
			SFLS_Licenses::add_activation( (int) $lic['id'], 'old' . $n . '.example.com' );
		}
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
		foreach ( range( 1, 8 ) as $n ) {
			$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( 100 + $n );
			$data = $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://old' . ( 1 + $n % 4 ) . '.example.com' ) )->get_data();
			$this->assertSame( 'expired', $data['reason'] ?? '', "Request $n" );
		}
		$_SERVER['REMOTE_ADDR'] = '192.0.2.150';
		$this->assertSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'https://old1.example.com' ) )->get_status() );
	}
}
