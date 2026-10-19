<?php
/**
 * Items 17 and 18, and is_local: rate limits, activity log, payload.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * License API.
 */
class SFLS_Rest_Api_Test extends SFLS_Test_Case {

	/**
	 * Request params for a license and site.
	 *
	 * @param array  $lic  License.
	 * @param string $site Site URL.
	 */
	private function params( array $lic, string $site = 'https://shop.example.com' ): array {
		return array(
			'license_key' => $lic['license_key'],
			'site_url'    => $site,
		);
	}

	/**
	 * Turn rate limits on (local sites skip them) with a small limit.
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
	 * Status and update checks accept POST with the key in a JSON body, so
	 * the key never has to travel in a URL (Pro 2.4.1).
	 */
	public function test_status_and_update_check_accept_post_body(): void {
		$lic = $this->make_license();
		foreach ( array( '/sfls/v1/license/status', '/sfls/v1/update/check' ) as $route ) {
			$request = new WP_REST_Request( 'POST', $route );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $this->params( $lic ) ) );
			$response = rest_ensure_response( rest_do_request( $request ) );
			$this->assertNotSame( 404, $response->get_status(), $route );
			$this->assertNotSame( 405, $response->get_status(), $route );
			$this->assertNotSame( 'rest_no_route', $response->get_data()['code'] ?? '', $route );
		}
	}

	/**
	 * The defaults are 30 requests per 10 minutes.
	 */
	public function test_default_limit_is_30_per_10_minutes(): void {
		$this->filter( 'sfls_rate_limit_enabled', '__return_true' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
		$lic                    = $this->make_license();
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertNotSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic ) )->get_status(), "Request $i" );
		}
		$this->assertSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic ) )->get_status() );
	}

	/**
	 * Per IP: the next request answers 429 with Retry-After.
	 */
	public function test_rate_limit_per_ip(): void {
		$this->limit( 3 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertNotSame( 429, $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $this->make_license() ) )->get_status() );
		}
		$response = $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $this->make_license() ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limited', $response->get_data()['reason'] );
		$retry = (int) $response->get_headers()['Retry-After'];
		$this->assertGreaterThan( 590, $retry );
		$this->assertLessThanOrEqual( 600, $retry );

		// Another IP is not affected.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$this->assertNotSame( 429, $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $this->make_license() ) )->get_status() );
	}

	/**
	 * Per key: one key hammered from many IPs is limited too, and status
	 * (GET) shares the bucket with validate.
	 */
	public function test_rate_limit_per_key(): void {
		$this->limit( 3 );
		$lic = $this->make_license();
		foreach ( array( 1, 2, 3 ) as $n ) {
			$_SERVER['REMOTE_ADDR'] = '192.0.2.' . $n;
			$this->assertNotSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic ) )->get_status() );
		}
		$_SERVER['REMOTE_ADDR'] = '192.0.2.99';
		$response               = $this->rest( 'GET', '/sfls/v1/license/status', $this->params( $lic ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $response->get_headers() );

		// A different key from that IP still works.
		$this->assertNotSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $this->make_license() ) )->get_status() );
	}

	/**
	 * The window resets.
	 */
	public function test_window_resets(): void {
		$this->limit( 1 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		$lic                    = $this->make_license();
		$this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic ) );
		$this->assertSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic ) )->get_status() );
		foreach ( array( 'ip' => '203.0.113.50', 'key' => strtoupper( $lic['license_key'] ) ) as $type => $value ) {
			set_transient(
				'sfls_rl_' . $type . '_' . md5( $value ),
				array(
					'start' => time() - 601,
					'hits'  => 99,
				),
				600
			);
		}
		$this->assertNotSame( 429, $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic ) )->get_status() );
	}

	/**
	 * Activations and deactivations are logged with site, IP and time.
	 */
	public function test_activity_log(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
		$lic                    = $this->make_license();
		$this->assertSame( 200, $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic ) )->get_status() );
		$this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic ) ); // Re-activation is not a new event.
		$this->rest( 'POST', '/sfls/v1/license/deactivate', $this->params( $lic ) );
		$this->rest( 'POST', '/sfls/v1/license/deactivate', $this->params( $lic ) ); // Nothing left to remove.

		$log = SFLS_Licenses::log_entries( (int) $lic['id'] );
		$this->assertCount( 2, $log );
		$this->assertSame( array( 'deactivate', 'activate' ), wp_list_pluck( $log, 'event' ) );
		$this->assertSame( 'shop.example.com', $log[1]['domain'] );
		$this->assertSame( '198.51.100.23', $log[1]['ip'] );
		$this->assertSame( 'api', $log[1]['source'] );
		$this->assertEqualsWithDelta( time(), strtotime( $log[1]['created_at'] . ' UTC' ), 5 );

		// Admin and account removals are logged with their source.
		SFLS_Licenses::add_activation( (int) $lic['id'], 'a.example.com' );
		SFLS_Licenses::reset_activations( (int) $lic['id'], 'admin' );
		$this->assertSame( 'admin', SFLS_Licenses::log_entries( (int) $lic['id'] )[0]['source'] );

		// The license screen shows it.
		wp_set_current_user( 1 );
		$_GET['edit'] = $lic['id'];
		ob_start();
		( new SFLS_Admin() )->page_list();
		$html = (string) ob_get_clean();
		unset( $_GET['edit'] );
		$this->assertStringContainsString( 'Activity', $html );
		$this->assertStringContainsString( '198.51.100.23', $html );
		$this->assertStringContainsString( 'Deactivated', $html );

		// Deleting the license deletes its log.
		SFLS_Licenses::delete( (int) $lic['id'] );
		$this->assertSame( array(), SFLS_Licenses::log_entries( (int) $lic['id'] ) );
	}

	/**
	 * is_local is in every license payload and the old fields are unchanged.
	 */
	public function test_is_local_in_payloads(): void {
		$lic  = $this->make_license();
		$keys = array( 'valid', 'status', 'plan', 'features', 'expires_at', 'grace_period_ends_at', 'sites_allowed', 'sites_used', 'check_again_in' );

		$live = $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic ) )->get_data();
		$this->assertTrue( $live['activated'] );
		$this->assertFalse( $live['is_local'] );
		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $live, $key );
		}

		$staging = $this->rest( 'POST', '/sfls/v1/license/activate', $this->params( $lic, 'https://staging.shop.example.com' ) )->get_data();
		$this->assertTrue( $staging['is_local'] );
		$this->assertSame( 1, $staging['sites_used'], 'Staging does not use a seat.' );

		$validate = $this->rest( 'POST', '/sfls/v1/license/validate', $this->params( $lic, 'http://localhost:10003' ) )->get_data();
		$this->assertTrue( $validate['is_local'] );
		$this->assertSame( 'domain_not_activated', $validate['reason'] );

		$status = $this->rest( 'GET', '/sfls/v1/license/status', $this->params( $lic ) )->get_data();
		$this->assertTrue( $status['valid'] );
		$this->assertFalse( $status['is_local'] );

		// Connect exchange.
		$user = $this->make_user();
		$code = wp_generate_password( 40, false );
		set_transient(
			'sfls_code_' . hash( 'sha256', $code ),
			array(
				'license_id' => (int) $lic['id'],
				'domain'     => 'mysite.test',
				'user_id'    => $user->ID,
			),
			600
		);
		$exchange = $this->rest(
			'POST',
			'/sfls/v1/connect/exchange',
			array(
				'code'     => $code,
				'site_url' => 'https://mysite.test',
			)
		)->get_data();
		$this->assertSame( $lic['license_key'], $exchange['license_key'] );
		$this->assertTrue( $exchange['is_local'] );
		$this->assertArrayHasKey( 'account', $exchange );
	}
}
