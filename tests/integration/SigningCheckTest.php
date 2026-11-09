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
		$this->set_option( SFLS_Signing_Check::MAIL_LOCK, null );
		$this->set_option( SFLS_Signing_Check::RECOVERY_LOCK, null );
		wp_clear_scheduled_hook( SFLS_Signing_Check::EVENT );
		$this->pair = SFLS_Signing::generate();
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		$this->forget_request_result();
	}

	/**
	 * Tear down: back to the configured key.
	 */
	protected function tearDown(): void {
		SFLS_Signing::use_test_key( null );
		wp_clear_scheduled_hook( SFLS_Signing_Check::EVENT );
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
		update_option( SFLS_Signing_Check::MAIL_LOCK, time() - $seconds, false );
	}

	/**
	 * Make the next outgoing email "slow": while it is being sent, another
	 * request runs the check and reads the state the first one has not saved
	 * yet. The nested runs send what they decide to send into the same mail log.
	 *
	 * @param int $others How many overlapping requests arrive while the first email is sent.
	 */
	private function overlap_during_first_mail( int $others = 1 ): void {
		$left = $others;
		$this->filter(
			'pre_wp_mail',
			static function ( $pre ) use ( &$left ) {
				while ( $left > 0 ) {
					--$left;
					SFLS_Signing_Check::run();
				}
				return $pre;
			},
			5
		);
	}

	/**
	 * Call a private static method.
	 *
	 * @param string $method Method.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call_private( string $method, ...$args ) {
		$ref = new ReflectionMethod( SFLS_Signing_Check::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	/**
	 * Store a result as if the last check ran this long ago.
	 *
	 * @param bool $ok      Whether it passed.
	 * @param int  $seconds Seconds ago.
	 */
	private function stored_result( bool $ok, int $seconds ): void {
		update_option(
			SFLS_Signing_Check::OPTION,
			array(
				'ok'            => $ok,
				'code'          => $ok ? '' : 'key_missing',
				'message'       => $ok ? '' : 'SFLS_SIGNING_SECRET_KEY is not defined.',
				'fix'           => $ok ? '' : 'Put it back.',
				'checked_at'    => time() - $seconds,
				'failing_since' => $ok ? 0 : time() - $seconds,
				'last_mail'     => 0,
			),
			false
		);
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

		// The refusals did not run the check or send anything in their own requests ...
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 0, SFLS_Signing_Check::state()['checked_at'] );
		// ... they asked for one check, which tells the owner without waiting for the daily run.
		$this->assertNotFalse( wp_next_scheduled( SFLS_Signing_Check::EVENT ) );
		do_action( SFLS_Signing_Check::EVENT );
		$this->assertCount( 1, $this->mails_with( 'SubtleForms license server: signing key check failed' ) );
		$this->assertFalse( SFLS_Signing_Check::state()['ok'] );
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

	/**
	 * Two requests that fail at the same moment send one email: the second
	 * reads the old state while the first is still talking to the mail server.
	 */
	public function test_overlapping_failing_runs_send_one_email(): void {
		SFLS_Signing::use_test_key( '' );
		$this->overlap_during_first_mail( 3 );

		SFLS_Signing_Check::run();

		$this->assertCount( 1, $this->mails_with( 'SubtleForms license server: signing key check failed' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertGreaterThan( 0, SFLS_Signing_Check::state()['last_mail'], 'The saved state keeps the time of the email.' );
		$this->assertFalse( SFLS_Signing_Check::state()['ok'] );
	}

	/**
	 * A run that finds the window already claimed by another request sends
	 * nothing and does not hand the window out again when it saves its state.
	 */
	public function test_a_claimed_window_sends_nothing(): void {
		SFLS_Signing::use_test_key( '' );
		$this->assertTrue( $this->call_private( 'claim', SFLS_Signing_Check::MAIL_LOCK, time() ) );

		$state = SFLS_Signing_Check::run();

		$this->assertSame( array(), $this->mails );
		$this->assertFalse( $state['ok'] );
		$this->assertGreaterThan( 0, SFLS_Signing_Check::state()['last_mail'] );

		// The next run, with the state saved by the one that lost, still sends nothing.
		SFLS_Signing_Check::run();
		$this->assertSame( array(), $this->mails );
	}

	/**
	 * The claim is exactly-once: a second claim of a held lock fails and leaves
	 * the first claimer's value alone (add_option() would have overwritten it
	 * and answered true), and a lock from an earlier day is taken over once.
	 */
	public function test_the_claim_is_exactly_once(): void {
		$name = SFLS_Signing_Check::MAIL_LOCK;
		$now  = time();

		$this->assertTrue( $this->call_private( 'claim', $name, $now ) );
		$this->assertFalse( $this->call_private( 'claim', $name, $now + 1 ) );
		$this->assertFalse( $this->call_private( 'claim', $name, $now + DAY_IN_SECONDS - 1 ) );
		$this->assertSame( $now, $this->call_private( 'lock_time', $name ), 'The first claim is still the stored one.' );

		// A day later the lock is free again, for one caller.
		$this->assertTrue( $this->call_private( 'claim', $name, $now + DAY_IN_SECONDS ) );
		$this->assertFalse( $this->call_private( 'claim', $name, $now + DAY_IN_SECONDS ) );
		$this->assertSame( $now + DAY_IN_SECONDS, $this->call_private( 'lock_time', $name ) );
		$this->assertSame( (string) ( $now + DAY_IN_SECONDS ), (string) get_option( $name ), 'The options API sees the claim.' );
	}

	/**
	 * Overlapping requests that recover at the same moment send one
	 * "recovered" email.
	 */
	public function test_overlapping_recoveries_send_one_email(): void {
		SFLS_Signing::use_test_key( '' );
		SFLS_Signing_Check::run();
		$this->assertCount( 1, $this->mails );

		SFLS_Signing::use_test_key( $this->pair['secret'] );
		$this->overlap_during_first_mail( 3 );
		$state = SFLS_Signing_Check::run();

		$this->assertTrue( $state['ok'] );
		$this->assertCount( 1, $this->mails_with( 'SubtleForms license server: signing key check recovered' ) );
		$this->assertCount( 2, $this->mails, 'One failure email and one recovery email.' );
		$this->assertTrue( SFLS_Signing_Check::state()['ok'] );
	}

	/**
	 * A failure that starts after a recovery is mailed at once, and so is its
	 * recovery, even inside the same day.
	 */
	public function test_a_new_failure_after_a_recovery_is_mailed_again(): void {
		$failed    = 'SubtleForms license server: signing key check failed';
		$recovered = 'SubtleForms license server: signing key check recovered';

		SFLS_Signing::use_test_key( '' );
		SFLS_Signing_Check::run();
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		SFLS_Signing_Check::run();
		SFLS_Signing::use_test_key( '' );
		SFLS_Signing_Check::run();
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		SFLS_Signing_Check::run();

		$this->assertCount( 2, $this->mails_with( $failed ) );
		$this->assertCount( 2, $this->mails_with( $recovered ) );
	}

	/**
	 * A refused signed route does not check the key in its own request: it
	 * schedules one check, never a second one while it waits, and sends nothing.
	 */
	public function test_the_guard_schedules_one_check_and_does_not_mail(): void {
		$this->filter( 'sfls_require_signing', '__return_true', 20 );
		SFLS_Signing::use_test_key( '' );
		$lic = $this->make_license();

		for ( $i = 0; $i < 3; $i++ ) {
			$response = $this->rest( 'POST', '/sfls/v1/license/validate', array( 'license_key' => $lic['license_key'], 'site_url' => 'https://guard.example.com' ) );
			$this->assertSame( 503, $response->get_status() );
		}

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), (array) get_option( SFLS_Signing_Check::OPTION, array() ), 'Nothing was stored by the refused requests.' );
		$events = array_filter( (array) _get_cron_array(), static fn( $hooks ) => isset( $hooks[ SFLS_Signing_Check::EVENT ] ) );
		$this->assertCount( 1, $events );
		$this->assertCount( 1, reset( $events )[ SFLS_Signing_Check::EVENT ] );

		// The event is the check: it stores the failure and sends the one email.
		do_action( SFLS_Signing_Check::EVENT );
		$this->assertCount( 1, $this->mails );
		$this->assertFalse( SFLS_Signing_Check::state()['ok'] );
	}

	/**
	 * A stored result younger than five minutes is enough for the guard; an
	 * older one asks for a new check.
	 */
	public function test_the_guard_asks_for_a_check_only_when_the_result_is_five_minutes_old(): void {
		SFLS_Signing::use_test_key( '' );

		$this->stored_result( false, 240 );
		SFLS_Signing_Check::request_check();
		$this->assertFalse( wp_next_scheduled( SFLS_Signing_Check::EVENT ), 'Four minutes old: fresh.' );

		$this->stored_result( false, 301 );
		SFLS_Signing_Check::request_check();
		SFLS_Signing_Check::request_check();
		$this->assertNotFalse( wp_next_scheduled( SFLS_Signing_Check::EVENT ), 'Five minutes old: ask for a check.' );
		$this->assertSame( array(), $this->mails );

		// The check that runs refreshes the result, so the next refusals leave it alone.
		wp_clear_scheduled_hook( SFLS_Signing_Check::EVENT );
		do_action( SFLS_Signing_Check::EVENT );
		SFLS_Signing_Check::request_check();
		$this->assertFalse( wp_next_scheduled( SFLS_Signing_Check::EVENT ) );
	}

	/**
	 * After the key is restored a check that passes anywhere clears the notice
	 * for every later request.
	 */
	public function test_a_passing_check_anywhere_clears_the_notice(): void {
		SFLS_Signing::use_test_key( '' );
		SFLS_Signing_Check::run();
		$this->as_admin();
		$this->forget_request_result();
		$this->assertStringContainsString( 'notice-error', $this->notice_html() );

		SFLS_Signing::use_test_key( $this->pair['secret'] );
		do_action( SFLS_Signing_Check::EVENT );

		$this->forget_request_result();
		$this->assertSame( '', $this->notice_html() );
	}

	/**
	 * While the notice shows, any admin screen re-checks the key, at most every
	 * five minutes, so a restored key clears the notice without waiting a day.
	 */
	public function test_other_admin_screens_recheck_while_the_notice_shows(): void {
		$this->as_admin();
		$_GET['page'] = 'plugins';

		// Inside five minutes: nothing runs, the notice stays.
		$this->stored_result( false, 120 );
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		SFLS_Signing_Check::on_admin_load();
		$this->forget_request_result();
		$this->assertStringContainsString( 'notice-error', $this->notice_html() );
		$this->assertFalse( SFLS_Signing_Check::state()['ok'] );

		// Five minutes old and the key is back: the screen load clears it and sends the one recovery email.
		$this->stored_result( false, 301 );
		$this->forget_request_result();
		SFLS_Signing_Check::on_admin_load();
		$this->assertTrue( SFLS_Signing_Check::state()['ok'] );
		$this->assertSame( '', $this->notice_html(), 'Cleared on the same page load.' );
		$this->assertCount( 1, $this->mails_with( 'SubtleForms license server: signing key check recovered' ) );

		// Still failing: the re-check keeps the notice and sends no new failure email inside the day.
		SFLS_Signing::use_test_key( '' );
		$this->stored_result( false, 301 );
		update_option( SFLS_Signing_Check::MAIL_LOCK, time() - 301, false );
		$state = (array) get_option( SFLS_Signing_Check::OPTION );
		$state['last_mail'] = time() - 301;
		update_option( SFLS_Signing_Check::OPTION, $state, false );
		$this->forget_request_result();
		$before = count( $this->mails );
		SFLS_Signing_Check::on_admin_load();
		$this->assertFalse( SFLS_Signing_Check::state()['ok'] );
		$this->assertCount( $before, $this->mails );
		$this->assertGreaterThan( time() - 60, SFLS_Signing_Check::state()['checked_at'], 'The re-check is stored, so the next screen load waits five minutes.' );
	}

	/**
	 * A working key and no notice: other admin screens do not run the check,
	 * and people who cannot manage licenses never trigger it.
	 */
	public function test_other_admin_screens_do_not_check_without_a_notice(): void {
		$_GET['page'] = 'plugins';
		$this->as_admin();
		SFLS_Signing_Check::on_admin_load();
		$this->assertSame( 0, SFLS_Signing_Check::state()['checked_at'], 'No stored result and no failure: nothing ran.' );

		$this->stored_result( false, 3600 );
		SFLS_Signing::use_test_key( $this->pair['secret'] );
		wp_set_current_user( $this->make_user( 'shopper' )->ID );
		$this->forget_request_result();
		SFLS_Signing_Check::on_admin_load();
		$this->assertFalse( SFLS_Signing_Check::state()['ok'], 'A user who cannot manage licenses does not trigger the check.' );
	}
}
