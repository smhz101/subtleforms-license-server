<?php
/**
 * Paddle webhook signature verification.
 *
 * @package SFLS
 */

/**
 * Paddle-Signature: ts=...;h1=HMAC-SHA256( "ts:raw body", secret ).
 */
class SFLS_Paddle_Signature_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;

	const BODY = '{"event_id":"evt_01","event_type":"subscription.created","data":{"id":"sub_01"}}';

	/**
	 * A correctly signed body verifies.
	 */
	public function test_valid_signature(): void {
		$ts     = 1700000000;
		$header = 'ts=' . $ts . ';h1=' . hash_hmac( 'sha256', $ts . ':' . self::BODY, 'secret_a' );
		$this->assertTrue( SFLS_Paddle_REST::verify( $header, self::BODY, 'secret_a', $ts + 10 ) );
	}

	/**
	 * Signed with another secret: refused.
	 */
	public function test_wrong_secret(): void {
		$ts     = 1700000000;
		$header = SFLS_Paddle_REST::sign( self::BODY, 'secret_b', $ts );
		$result = SFLS_Paddle_REST::verify( $header, self::BODY, 'secret_a', $ts );
		$this->assertWPError( $result, 'bad_signature' );
	}

	/**
	 * A changed body (even whitespace) no longer matches.
	 */
	public function test_tampered_body(): void {
		$ts     = 1700000000;
		$header = SFLS_Paddle_REST::sign( self::BODY, 'secret_a', $ts );
		$this->assertWPError( SFLS_Paddle_REST::verify( $header, self::BODY . ' ', 'secret_a', $ts ), 'bad_signature' );
	}

	/**
	 * Old (and far future) timestamps are refused even with a valid hash.
	 */
	public function test_stale_timestamp(): void {
		$ts     = 1700000000;
		$header = SFLS_Paddle_REST::sign( self::BODY, 'secret_a', $ts );
		$this->assertTrue( SFLS_Paddle_REST::verify( $header, self::BODY, 'secret_a', $ts + 300 ) );
		$this->assertWPError( SFLS_Paddle_REST::verify( $header, self::BODY, 'secret_a', $ts + 301 ), 'stale_signature' );
		$this->assertWPError( SFLS_Paddle_REST::verify( $header, self::BODY, 'secret_a', $ts - 301 ), 'stale_signature' );
		$this->assertWPError( SFLS_Paddle_REST::verify( $header, self::BODY, 'secret_a', $ts + 6, 5 ), 'stale_signature', 'The tolerance can be tightened.' );
	}

	/**
	 * While Paddle rotates secrets the header carries several h1 values.
	 */
	public function test_rotation_with_several_h1(): void {
		$ts     = 1700000000;
		$old    = hash_hmac( 'sha256', $ts . ':' . self::BODY, 'old_secret' );
		$new    = hash_hmac( 'sha256', $ts . ':' . self::BODY, 'new_secret' );
		$header = "ts=$ts;h1=$old;h1=$new";
		$this->assertTrue( SFLS_Paddle_REST::verify( $header, self::BODY, 'new_secret', $ts ) );
		$this->assertTrue( SFLS_Paddle_REST::verify( $header, self::BODY, 'old_secret', $ts ) );
	}

	/**
	 * Missing parts and a missing secret.
	 */
	public function test_malformed_and_unconfigured(): void {
		$this->assertWPError( SFLS_Paddle_REST::verify( '', self::BODY, 'secret_a' ), 'malformed_signature' );
		$this->assertWPError( SFLS_Paddle_REST::verify( 'ts=abc;h1=00', self::BODY, 'secret_a' ), 'malformed_signature' );
		$this->assertWPError( SFLS_Paddle_REST::verify( 'ts=' . time(), self::BODY, 'secret_a' ), 'malformed_signature' );
		$this->assertWPError( SFLS_Paddle_REST::verify( SFLS_Paddle_REST::sign( self::BODY, 'x' ), self::BODY, '' ), 'not_configured' );
	}

	/**
	 * The endpoint answers 401 for a bad signature and changes nothing, 401
	 * for a stale one, 200 for a good one.
	 */
	public function test_endpoint_checks_signature(): void {
		$this->paddle();
		$sub = $this->subscription();
		$this->customer_emails[ $sub['customer_id'] ] = $this->email();

		$bad = $this->webhook( 'subscription.created', $sub, array(), null, 'not_the_secret' );
		$this->assertSame( 401, $bad->get_status() );
		$this->assertSame( 'bad_signature', $bad->get_data()['error'] );

		$stale = $this->webhook( 'subscription.created', $sub, array(), time() - 3600 );
		$this->assertSame( 401, $stale->get_status() );
		$this->assertSame( 'stale_signature', $stale->get_data()['error'] );
		$this->assertNull( SFLS_Licenses::find_by_subscription( $sub['id'] ), 'Nothing was created.' );

		$good = $this->webhook( 'subscription.created', $sub );
		$this->assertSame( 200, $good->get_status() );
		$this->assertNotNull( SFLS_Licenses::find_by_subscription( $sub['id'] ) );
	}

	/**
	 * Without a secret the endpoint is closed (503) instead of trusting anyone.
	 */
	public function test_endpoint_closed_without_secret(): void {
		$this->paddle();
		SFLS_Paddle::save_config( array( 'webhook_secret' => '' ) );
		$this->assertSame( 503, $this->webhook( 'subscription.created', $this->subscription() )->get_status() );
	}

	/**
	 * Assert a WP_Error with a code.
	 *
	 * @param mixed  $value Value.
	 * @param string $code  Code.
	 * @param string $msg   Message.
	 */
	private function assertWPError( $value, string $code, string $msg = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $value, $msg );
		$this->assertSame( $code, $value->get_error_code(), $msg );
	}
}
