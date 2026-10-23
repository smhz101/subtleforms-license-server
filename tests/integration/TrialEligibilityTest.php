<?php
/**
 * One free trial per email, per company domain and per site, checked before
 * checkout and again in the webhook.
 *
 * @package SFLS
 */

/**
 * Trial rules.
 */
class SFLS_Trial_Eligibility_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;

	/**
	 * Set up Paddle.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->paddle();
	}

	/**
	 * A license to hang claims on.
	 */
	private function trial_license(): array {
		return $this->make_license(
			array(
				'billing'          => 'paddle',
				'trial'            => 1,
				'trial_started_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * The same email cannot trial twice, including +tags, case and Gmail dots.
	 */
	public function test_one_trial_per_email(): void {
		$tag   = 'sflstest' . strtolower( wp_generate_password( 6, false, false ) );
		$email = $tag . '@gmail.com';
		$this->assertTrue( SFLS_Trials::check( $email )['eligible'] );
		SFLS_Trials::record_start( (int) $this->trial_license()['id'], $email );

		foreach ( array( $email, strtoupper( $email ), $tag . '+again@gmail.com', substr( $tag, 0, 4 ) . '.' . substr( $tag, 4 ) . '@googlemail.com' ) as $variant ) {
			$check = SFLS_Trials::check( $variant );
			$this->assertFalse( $check['eligible'], $variant );
			$this->assertSame( 'email_used', $check['reason'], $variant );
		}
	}

	/**
	 * A claim that belongs to no license (license deleted, or recorded by
	 * hand) still counts.
	 */
	public function test_claim_without_license_counts(): void {
		$email = 'sflstest' . strtolower( wp_generate_password( 6, false, false ) ) . '@gmail.com';
		SFLS_Trials::record_start( 0, $email );
		$this->assertSame( 'email_used', SFLS_Trials::check( $email )['reason'] );
		$this->assertSame( 'email_used', SFLS_Trials::check( $email, '', 123 )['reason'] );
	}

	/**
	 * Anyone at a company domain counts as that company.
	 */
	public function test_one_trial_per_company_domain(): void {
		$domain = 'acme-' . strtolower( wp_generate_password( 6, false, false ) ) . '.' . self::DOMAIN;
		SFLS_Trials::record_start( (int) $this->trial_license()['id'], 'alice@' . $domain );
		$check = SFLS_Trials::check( 'bob@' . $domain );
		$this->assertFalse( $check['eligible'] );
		$this->assertSame( 'domain_used', $check['reason'] );
		$this->assertStringContainsString( $domain, $check['message'] );
		$this->assertTrue( SFLS_Trials::check( 'bob@other-' . $domain )['eligible'] );
	}

	/**
	 * On free mail domains only the exact address counts.
	 */
	public function test_free_mail_domains_count_only_the_address(): void {
		foreach ( array( 'gmail.com', 'outlook.com', 'yahoo.com', 'hotmail.com', 'icloud.com', 'proton.me' ) as $free ) {
			$first = 'sflstest' . strtolower( wp_generate_password( 6, false, false ) ) . '@' . $free;
			SFLS_Trials::record_start( (int) $this->trial_license()['id'], $first );
			$this->assertTrue( SFLS_Trials::check( 'sflstest' . strtolower( wp_generate_password( 6, false, false ) ) . '@' . $free )['eligible'], $free );
			$this->assertFalse( SFLS_Trials::check( $first )['eligible'], $free );
			$this->assertSame( '', SFLS_Trials::company_domain( $first ) );
		}
	}

	/**
	 * A site that ran a trial license cannot run another trial license, but
	 * a paid license works there, and staging sites are never blocked.
	 */
	public function test_one_trial_per_site(): void {
		$site  = 'sfls-site-test-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com';
		$first = $this->trial_license();
		$this->assertTrue( SFLS_Licenses::activate_domain( $first, $site ) );
		$this->assertTrue( SFLS_Trials::site_used_by_other( $site, 0 ) );
		$this->assertFalse( SFLS_Trials::site_used_by_other( $site, (int) $first['id'] ), 'Its own claim does not count.' );

		$second = $this->trial_license();
		$result = SFLS_Licenses::activate_domain( $second, 'www.' . $site );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'trial_site_used', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// The same refusal through the API Pro calls.
		$api = $this->rest(
			'POST',
			'/sfls/v1/license/activate',
			array(
				'license_key' => $second['license_key'],
				'site_url'    => 'https://' . $site,
			)
		);
		$this->assertSame( 403, $api->get_status() );
		$this->assertSame( 'trial_site_used', $api->get_data()['reason'] );

		// Staging is fine, a paid license is fine, and the eligibility check knows the site.
		$this->assertTrue( SFLS_Licenses::activate_domain( $second, 'staging.' . $site ) );
		$this->assertTrue( SFLS_Licenses::activate_domain( $this->make_license(), $site ) );
		$this->assertSame( 'site_used', SFLS_Trials::check( $this->email(), 'https://' . $site )['reason'] );

		// The first license deactivating the site does not free it for another trial.
		SFLS_Licenses::remove_activation( (int) $first['id'], $site );
		$this->assertTrue( SFLS_Trials::site_used_by_other( $site, (int) $second['id'] ) );
	}

	/**
	 * Releasing a license's claims (admin "Allow another trial") lets the
	 * customer trial again.
	 */
	public function test_release_allows_another_trial(): void {
		$email = $this->email();
		$lic   = $this->trial_license();
		SFLS_Trials::record_start( (int) $lic['id'], $email );
		$this->assertFalse( SFLS_Trials::check( $email )['eligible'] );
		SFLS_Trials::release( (int) $lic['id'] );
		$this->assertTrue( SFLS_Trials::check( $email )['eligible'] );
	}

	/**
	 * POST /sfls/v1/paddle/eligibility.
	 */
	public function test_eligibility_endpoint(): void {
		$email = $this->email();
		$ok    = $this->rest(
			'POST',
			'/sfls/v1/paddle/eligibility',
			array(
				'email' => $email,
				'plan'  => 'pro',
			)
		)->get_data();
		$this->assertTrue( $ok['eligible'] );
		$this->assertSame( 14, $ok['trial_days'] );

		// A signed-in customer gets the real answer for their own account.
		$user = $this->make_user();
		SFLS_Trials::record_start( (int) $this->trial_license()['id'], $user->user_email );
		wp_set_current_user( $user->ID );
		$used = $this->rest(
			'POST',
			'/sfls/v1/paddle/eligibility',
			array(
				'email' => $email,
				'plan'  => 'starter',
			)
		)->get_data();
		wp_set_current_user( 0 );
		$this->assertFalse( $used['eligible'] );
		$this->assertSame( 'email_used', $used['reason'] );
		$this->assertNotSame( '', $used['message'] );

		$none = $this->rest(
			'POST',
			'/sfls/v1/paddle/eligibility',
			array(
				'email' => $this->email(),
				'plan'  => 'agency',
			)
		)->get_data();
		$this->assertSame( 'no_trial', $none['reason'] );

		$bad = $this->rest(
			'POST',
			'/sfls/v1/paddle/eligibility',
			array(
				'email' => 'not-an-email',
				'plan'  => 'pro',
			)
		)->get_data();
		$this->assertSame( 'invalid_email', $bad['reason'] );
	}

	/**
	 * A signed-in customer is checked by their account email, whatever they send.
	 */
	public function test_eligibility_uses_signed_in_account(): void {
		$user = $this->make_user();
		SFLS_Trials::record_start( (int) $this->trial_license()['id'], $user->user_email );
		wp_set_current_user( $user->ID );
		$data = $this->rest(
			'POST',
			'/sfls/v1/paddle/eligibility',
			array(
				'email' => $this->email( 'someone-else' ),
				'plan'  => 'pro',
			)
		)->get_data();
		$this->assertFalse( $data['eligible'] );
		$this->assertSame( $user->user_email, $data['email'] );
	}

	/**
	 * Not selling with Paddle: no trials.
	 */
	public function test_eligibility_when_not_selling_with_paddle(): void {
		$this->settings( array( 'sell_with' => 'woocommerce' ) );
		$response = $this->rest(
			'POST',
			'/sfls/v1/paddle/eligibility',
			array(
				'email' => $this->email(),
				'plan'  => 'pro',
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['eligible'] );
	}

	/**
	 * Backstop: a second trial that slipped past checkout is canceled at
	 * once through the Paddle API, no license is issued and the customer is
	 * told. Later events for it are ignored.
	 */
	public function test_ineligible_trial_is_canceled_in_webhook(): void {
		$email = $this->email( 'twice' );
		$this->start_trial( $email );
		$this->mails = array();

		$sub = $this->subscription();
		$this->customer_emails[ $sub['customer_id'] ] = $email;
		$this->paddle_routes[ 'POST /subscriptions/' . $sub['id'] . '/cancel' ] = array_merge(
			$sub,
			array(
				'status'      => 'canceled',
				'canceled_at' => self::when( 0 ),
			)
		);

		$response = $this->webhook( 'subscription.created', $sub );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'rejected', $response->get_data()['action'] );

		$cancel = $this->calls( 'POST', '/subscriptions/' . $sub['id'] . '/cancel' );
		$this->assertCount( 1, $cancel, 'The subscription is canceled through the API.' );
		$this->assertSame( array( 'effective_from' => 'immediately' ), $cancel[0]['body'] );
		$this->assertSame( 'Bearer pdl_sdbx_apikey_test', $cancel[0]['headers']['Authorization'] );
		$this->assertStringStartsWith( SFLS_Paddle::API_SANDBOX, SFLS_Paddle::api_base() );

		$this->assertNull( SFLS_Licenses::find_by_subscription( $sub['id'] ), 'No license for the second trial.' );
		$mail = $this->mails_with( 'could not start your SubtleForms Pro trial' );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'have not been billed', $mail[0]['message'] );
		$this->assertContains( $sub['id'], get_option( SFLS_Billing::REJECTED_OPTION ) );

		// Paddle's follow-up events for that subscription change nothing.
		$this->webhook( 'subscription.trialing', $sub, array( 'occurred_at' => self::when( 5 ) ) );
		$this->webhook(
			'subscription.canceled',
			array_merge( $sub, array( 'status' => 'canceled' ) ),
			array( 'occurred_at' => self::when( 10 ) )
		);
		$this->assertCount( 1, $this->calls( 'POST', '/subscriptions/' . $sub['id'] . '/cancel' ) );
		$this->assertNull( SFLS_Licenses::find_by_subscription( $sub['id'] ) );
		$this->assertCount( 1, $this->mails_with( 'could not start' ) );
	}

	/**
	 * The backstop also catches a colleague at the same company.
	 */
	public function test_backstop_company_domain(): void {
		$domain = 'corp' . strtolower( wp_generate_password( 6, false, false ) ) . '.' . self::DOMAIN;
		$this->start_trial( 'first@' . $domain );
		$sub = $this->subscription();
		$this->customer_emails[ $sub['customer_id'] ] = 'second@' . $domain;
		$this->paddle_routes[ 'POST /subscriptions/' . $sub['id'] . '/cancel' ] = array_merge( $sub, array( 'status' => 'canceled' ) );
		$this->assertSame( 'rejected', $this->webhook( 'subscription.created', $sub )->get_data()['action'] );
		$this->assertCount( 1, $this->calls( 'POST', '/subscriptions/' . $sub['id'] . '/cancel' ) );
	}

	/**
	 * Buying without a trial is always allowed, even after a trial.
	 */
	public function test_paid_subscription_after_trial_is_fine(): void {
		$email = $this->email();
		$this->start_trial( $email );
		$sub = $this->subscription( array( 'status' => 'active' ) );
		$this->customer_emails[ $sub['customer_id'] ] = $email;
		$this->assertSame( 'created', $this->webhook( 'subscription.created', $sub )->get_data()['action'] );
		$this->assertSame( array(), $this->calls( 'POST', '#/cancel$#' ) );
	}
}
