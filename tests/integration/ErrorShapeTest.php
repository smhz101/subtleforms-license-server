<?php
/**
 * LS-10: license API errors share one shape and say what is wrong.
 *
 * @package SFLS
 */

/**
 * License API error answers.
 */
class SFLS_Error_Shape_Test extends SFLS_Test_Case {

	/**
	 * The repro, part one: validating on a site that is not activated
	 * answered with "message" while other errors use "error". It now has the
	 * error field too, and stays a 200 that Pro reads as "inactive".
	 */
	public function test_not_activated_has_error_field(): void {
		$lic = $this->make_license();
		$res = $this->rest(
			'POST',
			'/sfls/v1/license/validate',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://not-activated.example.com',
			)
		);
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $data['valid'] );
		$this->assertSame( 'domain_not_activated', $data['reason'] );
		$this->assertSame( 'This site is not activated for this license.', $data['error'] );
		$this->assertSame( $data['error'], $data['message'], 'Older Pro versions read message.' );

		$missing = $this->rest( 'POST', '/sfls/v1/license/validate', array( 'license_key' => 'SFLS-NOPE-NOPE', 'site_url' => 'https://a.example.com' ) )->get_data();
		$this->assertSame( array( 'valid', 'reason', 'error' ), array_keys( $missing ), 'Every error has these fields.' );
	}

	/**
	 * The repro, part two: activating with site_url=javascript:alert(1) said
	 * "license_key and site_url are required." It names the real problem now.
	 */
	public function test_bad_site_url_is_named(): void {
		$lic = $this->make_license();
		$res = $this->rest(
			'POST',
			'/sfls/v1/license/activate',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'javascript:alert(1)',
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_site_url', $res->get_data()['reason'] );
		$this->assertSame( 'site_url must be the address of a website, such as https://example.com.', $res->get_data()['error'] );

		$no_key = $this->rest( 'POST', '/sfls/v1/license/activate', array( 'site_url' => 'https://a.example.com' ) )->get_data();
		$this->assertSame( 'license_key is required.', $no_key['error'] );
		$no_site = $this->rest( 'POST', '/sfls/v1/license/activate', array( 'license_key' => $lic['license_key'] ) )->get_data();
		$this->assertSame( 'site_url is required.', $no_site['error'] );
		$neither = $this->rest( 'POST', '/sfls/v1/license/activate', array() )->get_data();
		$this->assertSame( 'license_key and site_url are required.', $neither['error'] );
		$this->assertSame( 'missing_parameters', $neither['reason'] );
	}
}
