<?php
/**
 * 1.9.2 signing key self-test: a missing, malformed, damaged or mismatched
 * key is caught by the daily check, shown as an admin notice and emailed once
 * a day; signed routes answer 503 while the server cannot sign.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.NonceVerification

/**
 * Signing key check.
 */
class SFLS_Signing_Check_Test extends SFLS_Test_Case {

	/**
	 * Base64 key pair made for this test (never a real key).
	 *
	 * @var array{secret:string,public:string}
	 */
	private array $pair = array();

	/**
	 * Set up: a good test key, no stored result, the page parameter restored afterwards.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->set_option( SFLS_Signing_Check::OPTION, null );
		$this->pair = SFLS_Signing::generate();
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		$this->forget_request_result();
	}

	/**
	 * Tear down: back to the configured key.
	 */
	protected function tearDown(): void {
		SFLS_Signing::use_test_key( null );
		unset( $_GET['page'] );
		$this->forget_request_result();
		parent::tearDown();
	}

	/**
	 * The check remembers its result for the rest of the request; a test is
	 * several "requests".
	 */
	private function forget_request_result(): void {
		$prop = new ReflectionProperty( SFLS_Signing_Check::class, 'current' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * Emails sent so far with this subject.
	 *
	 * @param string $subject Subject.
	 */
	private function mails_with( string $subject ): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => $subject === $m['subject'] ) );
	}

	/**
	 * Pretend the last failure email went out this long ago.
	 *
	 * @param int $seconds Seconds ago.
	 */
	private function failure_mail_was_sent( int $seconds ): void {
		$state              = (array) get_option( SFLS_Signing_Check::OPTION );
		$state['last_mail'] = time() - $seconds;
		update_option( SFLS_Signing_Check::OPTION, $state, false );
	}

	/**
	 * A secret key whose seed was damaged but whose public half is intact.
	 */
	private function damaged_secret(): string {
		$raw    = base64_decode( $this->pair['secret'], true );
		$raw[0] = chr( ord( $raw[0] ) ^ 0xFF );
		return base64_encode( $raw );
	}

	/**
	 * Become an administrator.
	 */
	private function as_admin(): void {
		$user = $this->make_user( 'owner' );
		$user->set_role( 'administrator' );
		wp_set_current_user( $user->ID );
	}

	/**
	 * What the notice prints.
	 */
	private function notice_html(): string {
		ob_start();
		SFLS_Signing_Check::notice();
		return (string) ob_get_clean();
	}

	/**
	 * With the configured key the sample answer signs and verifies.
	 */
	public function test_passes_with_the_configured_key(): void {
		$result = SFLS_Signing_Check::diagnose();
		$this->assertTrue( $result['ok'] );

		$state = SFLS_Signing_Check::run();
		$this->assertTrue( $state['ok'] );
		$this->assertSame( array(), $this->mails );
		$this->assertStringStartsWith( 'Signing: OK, checked ', SFLS_Signing_Check::summary( $state ) );
		$this->as_admin();
		$this->assertSame( '', $this->notice_html() );
	}

	/**
	 * It also passes when SFLS_SIGNING_PUBLIC_KEY is defined and belongs to the secret key.
	 */
	public function test_passes_with_a_matching_public_key(): void {
		SFLS_Signing::use_test_key( $this->pair['secret'], $this->pair['public'] );
		$this->assertTrue( SFLS_Signing_Check::diagnose()['ok'] );
	}

	/**
	 * No key at all.
	 */
	public function test_fails_when_the_key_is_missing(): void {
		SFLS_Signing::use_test_key( '' );

		$result = SFLS_Signing_Check::diagnose();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'key_missing', $result['code'] );
		$this->assertStringContainsString( 'SFLS_SIGNING_SECRET_KEY', $result['message'] );
		$this->assertStringContainsString( 'wp-config.php', $result['fix'] );
	}

	/**
	 * A value that is not a base64 ed25519 secret key.
	 */
	public function test_fails_when_the_key_is_malformed(): void {
		SFLS_Signing::use_test_key( base64_encode( 'too short' ) );
		$this->assertSame( 'key_malformed', SFLS_Signing_Check::diagnose()['code'] );

		SFLS_Signing::use_test_key( 'not base64 at all!' );
		$this->assertSame( 'key_malformed', SFLS_Signing_Check::diagnose()['code'] );
	}

	/**
	 * A key of the right size that signs something that does not verify.
	 */
	public function test_fails_when_the_signature_does_not_verify(): void {
		SFLS_Signing::use_test_key( $this->damaged_secret() );

		$result = SFLS_Signing_Check::diagnose();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'verify_failed', $result['code'] );
	}

	/**
	 * The secret key was replaced and no longer belongs to the public key Pro ships.
	 */
	public function test_fails_when_the_public_key_does_not_match(): void {
		SFLS_Signing::use_test_key( $this->pair['secret'], SFLS_Signing::generate()['public'] );

		$result = SFLS_Signing_Check::diagnose();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'public_key_mismatch', $result['code'] );
		$this->assertStringContainsString( 'SFLS_SIGNING_PUBLIC_KEY', $result['message'] );
	}

	/**
	 * A failure is emailed to the admin address once a day, not on every run,
	 * and again the next day while it lasts.
	 */
	public function test_failure_email_goes_out_once_a_day(): void {
		SFLS_Signing::use_test_key( '' );
		$subject = 'SubtleForms license server: signing key check failed';

		SFLS_Signing_Check::run();
		SFLS_Signing_Check::run();
		SFLS_Signing_Check::run();

		$sent = $this->mails_with( $subject );
		$this->assertCount( 1, $sent );
		$this->assertSame( get_option( 'admin_email' ), is_array( $sent[0]['to'] ) ? $sent[0]['to'][0] : $sent[0]['to'] );
		$this->assertStringContainsString( 'SFLS_SIGNING_SECRET_KEY', $sent[0]['message'] );
		$this->assertStringContainsString( 'What to do:', $sent[0]['message'] );

		// Still inside the day: nothing more.
		$this->failure_mail_was_sent( DAY_IN_SECONDS - 600 );
		SFLS_Signing_Check::run();
		$this->assertCount( 1, $this->mails_with( $subject ) );

		// A day later the next one goes out.
		$this->failure_mail_was_sent( DAY_IN_SECONDS + 5 );
		SFLS_Signing_Check::run();
		$this->assertCount( 2, $this->mails_with( $subject ) );
		$this->assertCount( 2, $this->mails );
	}

	/**
	 * The notice stays on the admin screens for administrators while the check fails.
	 */
	public function test_notice_shows_while_failing(): void {
		SFLS_Signing::use_test_key( '' );
		SFLS_Signing_Check::run();

		$this->as_admin();
		$html = $this->notice_html();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'signing key check failed', $html );
		$this->assertStringContainsString( 'SFLS_SIGNING_SECRET_KEY', $html );
		$this->assertStringContainsString( 'wp-config.php', $html );

		// The next page load reads the stored result.
		$this->forget_request_result();
		$this->assertStringContainsString( 'notice-error', $this->notice_html() );

		// Someone who cannot manage licenses sees nothing.
		wp_set_current_user( $this->make_user( 'shopper' )->ID );
		$this->assertSame( '', $this->notice_html() );
	}

	/**
	 * When the key is back the notice goes and one "recovered" email is sent.
	 */
	public function test_recovery_clears_the_notice_and_sends_one_email(): void {
		SFLS_Signing::use_test_key( '' );
		SFLS_Signing_Check::run();
		$this->assertCount( 1, $this->mails );

		SFLS_Signing::use_test_key( $this->pair['secret'] );
		$state = SFLS_Signing_Check::run();
		SFLS_Signing_Check::run();
		SFLS_Signing_Check::run();

		$this->assertTrue( $state['ok'] );
		$recovered = $this->mails_with( 'SubtleForms license server: signing key check recovered' );
		$this->assertCount( 1, $recovered );
		$this->assertCount( 2, $this->mails, 'One failure email and one recovery email.' );
		$this->as_admin();
		$this->forget_request_result();
		$this->assertSame( '', $this->notice_html() );
		$this->assertTrue( SFLS_Signing_Check::state()['ok'] );
	}

	/**
	 * A key that always passed never sends a "recovered" email.
	 */
	public function test_no_recovery_email_without_a_failure(): void {
		SFLS_Signing_Check::run();
		SFLS_Signing_Check::run();
		$this->assertSame( array(), $this->mails );
	}

	/**
	 * The check runs from the existing daily event and when a Licenses screen loads.
	 */
	public function test_runs_daily_and_on_the_licenses_screen(): void {
		$this->assertNotFalse( has_action( SFLS_Reminders::HOOK, array( 'SFLS_Signing_Check', 'run' ) ) );

		SFLS_Signing::use_test_key( '' );
		$this->as_admin();
		$_GET['page'] = 'sfls';
		SFLS_Signing_Check::on_admin_load();

		$this->assertFalse( SFLS_Signing_Check::state()['ok'] );
		$this->assertCount( 1, $this->mails );

		// Other admin screens do not run it.
		$this->forget_request_result();
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		$_GET['page'] = 'plugins';
		SFLS_Signing_Check::on_admin_load();
		$this->assertFalse( SFLS_Signing_Check::state()['ok'] );
	}

	/**
	 * Without a key the signed routes answer 503 signing_unavailable instead
	 * of an unsigned 200, before the endpoint runs.
	 */
	public function test_signed_routes_answer_503_without_a_key(): void {
		$this->filter( 'sfls_require_signing', '__return_true', 20 );
		SFLS_Signing::use_test_key( '' );
		$lic = $this->make_license();

		foreach ( array( '/sfls/v1/license/validate', '/sfls/v1/license/activate', '/sfls/v1/update/check' ) as $route ) {
			$response = $this->rest(
				'POST',
				$route,
				array(
					'license_key' => $lic['license_key'],
					'site_url'    => 'https://guard.example.com',
				)
			);
			$this->assertSame( 503, $response->get_status(), $route );
			$this->assertSame( 'signing_unavailable', $response->get_data()['reason'], $route );
			$this->assertArrayNotHasKey( 'signature', $response->get_data() );
		}
		$this->assertEmpty( SFLS_Licenses::activations( (int) $lic['id'] ), 'The refused activate did not take a seat.' );

		// The refusal told the owner without waiting for the daily run.
		$this->assertCount( 1, $this->mails_with( 'SubtleForms license server: signing key check failed' ) );
	}

	/**
	 * With a key the same routes answer and sign; routes that are not signed are untouched.
	 */
	public function test_signed_routes_answer_normally_with_a_key(): void {
		$this->filter( 'sfls_require_signing', '__return_true', 20 );
		$lic = $this->make_license();

		$response = $this->rest(
			'POST',
			'/sfls/v1/license/validate',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://guard.example.com',
			)
		);

		$this->assertNotSame( 503, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['signature'] );

		SFLS_Signing::use_test_key( '' );
		$other = $this->rest( 'GET', '/sfls/v1/update/download', array() );
		$this->assertNotSame( 503, $other->get_status(), 'The download route is not a signed route.' );
	}

	/**
	 * A development server can keep answering unsigned with the filter.
	 */
	public function test_the_guard_can_be_turned_off(): void {
		$this->filter( 'sfls_require_signing', '__return_false', 20 );
		SFLS_Signing::use_test_key( '' );
		$lic = $this->make_license();

		$response = $this->rest(
			'POST',
			'/sfls/v1/license/validate',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://guard.example.com',
			)
		);

		$this->assertNotSame( 503, $response->get_status() );
		$this->assertArrayNotHasKey( 'signature', $response->get_data() );
	}
}
