<?php
/**
 * Paddle webhook events and the license transitions they cause.
 *
 * @package SFLS
 */

/**
 * Event → license.
 */
class SFLS_Paddle_Webhook_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;

	/**
	 * Set up Paddle.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->paddle();
	}

	/**
	 * GMT datetime from an RFC 3339 date plus days.
	 *
	 * @param string $rfc  Date.
	 * @param int    $days Days to add.
	 */
	private static function gmt_plus( string $rfc, int $days = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( $rfc ) + $days * DAY_IN_SECONDS );
	}

	/**
	 * subscription.created while trialing: a 1-site trial license, the
	 * account, the welcome email, the trial claims and the checkout link.
	 */
	public function test_trialing_creates_one_site_trial_license(): void {
		$email = $this->email( 'trial' );
		$sub   = $this->subscription();
		$this->customer_emails[ $sub['customer_id'] ] = $email;

		$response = $this->webhook( 'subscription.created', $sub + array( 'transaction_id' => 'txn_checkout1' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'created', $response->get_data()['action'] );

		$lic = SFLS_Licenses::find_by_subscription( $sub['id'] );
		$this->assertSame( 'paddle', $lic['billing'] );
		$this->assertSame( 'trialing', $lic['billing_status'] );
		$this->assertSame( 'pro', $lic['plan'] );
		$this->assertSame( 1, (int) $lic['sites_allowed'], 'A trial covers 1 site whatever the plan.' );
		$this->assertSame( 1, (int) $lic['trial'] );
		$this->assertSame( 'active', $lic['status'] );
		$trial_end = $sub['items'][0]['trial_dates']['ends_at'];
		$this->assertSame( self::gmt_plus( $trial_end ), $lic['trial_ends_at'] );
		$this->assertSame( self::gmt_plus( $trial_end, SFLS_Paddle::BILLING_GRACE_DAYS ), $lic['expires_at'], 'Valid until the trial end plus the billing grace.' );
		$this->assertSame( self::gmt_plus( $sub['next_billed_at'] ), $lic['renews_at'] );
		$this->assertNull( $lic['cancels_at'] );
		$this->assertSame( '7900', $lic['billing_amount'] );
		$this->assertSame( 'USD', $lic['billing_currency'] );
		$this->assertSame( $sub['customer_id'], $lic['paddle_customer_id'] );
		$this->assertSame( 'pri_pro_trial', $lic['paddle_price_id'] );
		$this->assertSame( 'txn_checkout1', $lic['paddle_transaction_id'] );
		$this->assertSame( hash( 'sha256', $sub['custom_data']['sfls_token'] ), $lic['checkout_token_hash'] );
		$this->assertNotEmpty( $lic['trial_started_at'] );

		// Account created and linked, with a set-password email.
		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( (int) $user->ID, (int) $lic['user_id'] );
		$this->assertContains( 'customer', $user->roles );
		$this->assertNotEmpty( array_filter( $this->mails, static fn( $m ) => str_contains( (string) $m['subject'], 'Login Details' ) || str_contains( (string) $m['message'], 'set your password' ) ) );

		// Welcome email with the key and the trial end.
		$welcome = $this->mails_with( 'free trial has started' );
		$this->assertCount( 1, $welcome );
		$this->assertSame( $email, is_array( $welcome[0]['to'] ) ? $welcome[0]['to'][0] : $welcome[0]['to'] );
		$this->assertStringContainsString( $lic['license_key'], $welcome[0]['message'] );
		$this->assertStringContainsString( '$79.00', $welcome[0]['message'] );

		// Trial claims.
		$this->assertFalse( SFLS_Trials::check( $email )['eligible'] );

		// Pro sees a valid license.
		$payload = SFLS_Licenses::payload( $lic, 'shop.example.com' );
		$this->assertTrue( $payload['valid'] );
		$this->assertSame( 'valid', $payload['status'] );
		$this->assertTrue( $payload['trial'] );
		$this->assertSame( 1, $payload['sites_allowed'] );
	}

	/**
	 * An existing account with the same email is not duplicated, and the
	 * license is not linked to it until its owner confirms the address
	 * (LS-01: an account email can be changed without confirmation).
	 */
	public function test_existing_account_is_not_linked_by_email(): void {
		$user    = $this->make_user( 'existing' . wp_generate_password( 4, false, false ) );
		$before  = count_users()['total_users'];
		[ $lic ] = $this->start_trial( $user->user_email );
		$this->assertSame( 0, (int) $lic['user_id'] );
		$this->assertSame( $before, count_users()['total_users'] );
		$this->assertSame( array(), $this->mails_with( 'Login Details' ) );
	}

	/**
	 * subscription.activated (first payment): full plan sites, trial over,
	 * expiry at the end of the paid year plus grace, same key.
	 */
	public function test_activated_gives_full_plan(): void {
		[ $lic, $sub ] = $this->start_trial();
		$active        = $this->subscription(
			array(
				'id'          => $sub['id'],
				'customer_id' => $sub['customer_id'],
				'status'      => 'active',
			)
		);
		$this->assertSame( 200, $this->webhook( 'subscription.activated', $active, array( 'occurred_at' => self::when( 5 ) ) )->get_status() );
		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( $lic['license_key'], $after['license_key'] );
		$this->assertSame( 0, (int) $after['trial'] );
		$this->assertSame( 'active', $after['billing_status'] );
		$this->assertSame( 5, (int) $after['sites_allowed'] );
		$this->assertSame( self::gmt_plus( $active['current_billing_period']['ends_at'], SFLS_Paddle::BILLING_GRACE_DAYS ), $after['expires_at'] );
		$this->assertSame( self::gmt_plus( $active['next_billed_at'] ), $after['renews_at'] );
		$this->assertFalse( SFLS_Licenses::payload( $after )['trial'] );
		$this->assertSame( 5, SFLS_Licenses::payload( $after )['sites_allowed'] );
	}

	/**
	 * transaction.completed for the first charge and for a renewal: the
	 * license runs to the end of the paid period, and the customer gets one
	 * payment email per transaction.
	 */
	public function test_transaction_completed_extends_and_emails_once(): void {
		[ $lic, $sub ] = $this->start_trial();
		$first         = $this->transaction( $sub );
		$this->assertSame( 200, $this->webhook( 'transaction.completed', $first )->get_status() );
		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( 0, (int) $after['trial'], 'The first payment ends the trial.' );
		$this->assertSame( 5, (int) $after['sites_allowed'] );
		$this->assertSame( self::gmt_plus( $first['billing_period']['ends_at'], SFLS_Paddle::BILLING_GRACE_DAYS ), $after['expires_at'] );
		$this->assertCount( 1, $this->mails_with( 'Payment received' ) );
		$this->assertStringContainsString( 'free trial is over', $this->mails_with( 'Payment received' )[0]['message'] );

		// Renewal a year later.
		$renewal = $this->transaction(
			$sub,
			array(
				'billing_period' => array(
					'starts_at' => self::when( 365 * DAY_IN_SECONDS ),
					'ends_at'   => self::when( 730 * DAY_IN_SECONDS ),
				),
			)
		);
		$this->webhook( 'transaction.completed', $renewal );
		$this->webhook( 'transaction.completed', $renewal ); // Same transaction, new event ID: no second email.
		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( self::gmt_plus( $renewal['billing_period']['ends_at'], SFLS_Paddle::BILLING_GRACE_DAYS ), $after['expires_at'] );
		$this->assertCount( 2, $this->mails_with( 'Payment received' ) );
	}

	/**
	 * A zero-total checkout transaction (the trial start) is not a payment.
	 */
	public function test_zero_total_transaction_is_not_a_payment(): void {
		[ $lic, $sub ] = $this->start_trial();
		$txn           = $this->transaction(
			$sub,
			array(
				'origin'  => 'web',
				'details' => array(
					'totals' => array(
						'total'       => '0',
						'grand_total' => '0',
					),
				),
			)
		);
		$this->webhook( 'transaction.completed', $txn );
		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( 1, (int) $after['trial'] );
		$this->assertSame( $lic['expires_at'], $after['expires_at'] );
		$this->assertSame( array(), $this->mails_with( 'Payment received' ) );
	}

	/**
	 * past_due keeps the license valid until the paid period ends, and the
	 * customer is told how to update the card (once).
	 */
	public function test_past_due_keeps_period_and_emails(): void {
		$sub = $this->subscription( array( 'status' => 'active' ) );
		$this->customer_emails[ $sub['customer_id'] ] = $this->email();
		$this->webhook( 'subscription.created', $sub );
		$lic = SFLS_Licenses::find_by_subscription( $sub['id'] );
		$this->assertSame( 5, (int) $lic['sites_allowed'], 'Bought without a trial: full plan at once.' );

		$due = $sub;
		$due['status']                              = 'past_due';
		$due['current_billing_period']['starts_at'] = self::when( 365 * DAY_IN_SECONDS );
		$due['current_billing_period']['ends_at']   = self::when( 730 * DAY_IN_SECONDS );
		$this->webhook( 'subscription.past_due', $due, array( 'occurred_at' => self::when( 10 ) ) );
		$this->webhook( 'subscription.past_due', $due, array( 'occurred_at' => self::when( 20 ) ) );

		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( 'past_due', $after['billing_status'] );
		$this->assertSame( $lic['expires_at'], $after['expires_at'], 'No extension without payment.' );
		$this->assertTrue( SFLS_Licenses::state( $after )['valid'] );
		$failed = $this->mails_with( 'Payment failed' );
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'Update payment method', $failed[0]['message'] );
		$this->assertStringContainsString( '#license-' . $lic['id'], $failed[0]['message'] );
		$this->assertSame( 'past_due', SFLS_Licenses::payload( $after )['billing_status'] );

		// transaction.payment_failed for the same period does not email again.
		$this->webhook(
			'transaction.payment_failed',
			$this->transaction(
				$sub,
				array(
					'status'         => 'past_due',
					'billing_period' => $due['current_billing_period'],
				)
			)
		);
		$this->assertCount( 1, $this->mails_with( 'Payment failed' ) );
	}

	/**
	 * A failed attempt during checkout is not emailed (the customer is there).
	 */
	public function test_checkout_payment_failure_is_ignored(): void {
		[ $lic, $sub ] = $this->start_trial();
		$response      = $this->webhook( 'transaction.payment_failed', $this->transaction( $sub, array( 'origin' => 'web' ) ) );
		$this->assertSame( 'ignored', $response->get_data()['action'] );
		$this->assertSame( array(), $this->mails_with( 'Payment failed' ) );
		$this->assertSame( 'trialing', SFLS_Licenses::find( (int) $lic['id'] )['billing_status'] );
	}

	/**
	 * Canceled during the trial: it ends now, with no grace period.
	 */
	public function test_canceled_during_trial_ends_now(): void {
		[ $lic, $sub ] = $this->start_trial();
		$canceled      = $sub;
		$canceled['status']      = 'canceled';
		$canceled['canceled_at'] = self::when( 0 );
		$this->webhook( 'subscription.canceled', $canceled, array( 'occurred_at' => self::when( 5 ) ) );
		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( 'canceled', $after['billing_status'] );
		$this->assertLessThanOrEqual( time() + 2, strtotime( $after['expires_at'] . ' UTC' ) );
		$state = SFLS_Licenses::state( $after );
		$this->assertFalse( $state['valid'] );
		$this->assertSame( 'expired', $state['status'] );
		$this->assertNull( $after['renews_at'] );
		$this->assertStringContainsString( 'will not be charged', $this->mails_with( 'Auto-renew is off' )[0]['message'] );
	}

	/**
	 * Auto-renew turned off on a paid license: cancels_at is set, the
	 * renewal date goes, the customer is told it stays active until the end
	 * of the period. When the cancellation takes effect the paid expiry stays.
	 */
	public function test_scheduled_cancel_then_canceled_keeps_paid_period(): void {
		$sub = $this->subscription( array( 'status' => 'active' ) );
		$this->customer_emails[ $sub['customer_id'] ] = $this->email();
		$this->webhook( 'subscription.created', $sub );
		$lic = SFLS_Licenses::find_by_subscription( $sub['id'] );

		$scheduled                     = $sub;
		$scheduled['scheduled_change'] = array(
			'action'       => 'cancel',
			'effective_at' => $sub['current_billing_period']['ends_at'],
			'resume_at'    => null,
		);
		$this->webhook( 'subscription.updated', $scheduled, array( 'occurred_at' => self::when( 10 ) ) );
		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( self::gmt_plus( $sub['current_billing_period']['ends_at'] ), $after['cancels_at'] );
		$this->assertNull( $after['renews_at'] );
		$payload = SFLS_Licenses::payload( $after );
		$this->assertNotNull( $payload['cancels_at'] );
		$this->assertNull( $payload['renews_at'] );
		$this->assertNull( $payload['renewal_amount'], 'Nothing will be charged.' );
		$mail = $this->mails_with( 'Auto-renew is off' );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'stays active until', $mail[0]['message'] );

		$canceled                     = $sub;
		$canceled['status']           = 'canceled';
		$canceled['canceled_at']      = $sub['current_billing_period']['ends_at'];
		$canceled['scheduled_change'] = null;
		$this->webhook( 'subscription.canceled', $canceled, array( 'occurred_at' => self::when( 20 ) ) );
		$final = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( 'canceled', $final['billing_status'] );
		$this->assertSame( $lic['expires_at'], $final['expires_at'], 'Paid until the end of the period.' );
		$this->assertTrue( SFLS_Licenses::state( $final )['valid'] );
		$this->assertCount( 1, $this->mails_with( 'Auto-renew is off' ), 'No second cancellation email.' );
	}

	/**
	 * subscription.updated with another plan's price: same key, new plan,
	 * sites and features, and a note.
	 */
	public function test_plan_change_keeps_key(): void {
		$sub = $this->subscription( array( 'status' => 'active' ) );
		$this->customer_emails[ $sub['customer_id'] ] = $this->email();
		$this->webhook( 'subscription.created', $sub );
		$lic = SFLS_Licenses::find_by_subscription( $sub['id'] );

		$upgraded                                              = $sub;
		$upgraded['items'][0]['price']['id']                   = 'pri_agency';
		$upgraded['items'][0]['price']['unit_price']['amount'] = '19900';
		$this->webhook( 'subscription.updated', $upgraded, array( 'occurred_at' => self::when( 10 ) ) );

		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( $lic['license_key'], $after['license_key'] );
		$this->assertSame( 'agency', $after['plan'] );
		$this->assertSame( 0, (int) $after['sites_allowed'] );
		$this->assertSame( '19900', $after['billing_amount'] );
		$this->assertContains( 'white_label', SFLS_Licenses::payload( $after )['features'] );
		$this->assertStringContainsString( 'Plan changed from Pro to Agency', (string) $after['notes'] );
	}

	/**
	 * The same event delivered twice is handled once.
	 */
	public function test_idempotent_by_event_id(): void {
		$sub = $this->subscription();
		$this->customer_emails[ $sub['customer_id'] ] = $this->email();
		$first  = $this->webhook( 'subscription.created', $sub, array( 'event_id' => 'evt_test_dup_' . $sub['id'] ) );
		$second = $this->webhook( 'subscription.created', $sub, array( 'event_id' => 'evt_test_dup_' . $sub['id'] ) );
		$this->assertSame( 'created', $first->get_data()['action'] );
		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['duplicate'] );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE paddle_subscription_id = %s", $sub['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 1, $count );
		$this->assertCount( 1, $this->mails_with( 'free trial has started' ) );

		// A different event for the same subscription (subscription.trialing) does not create a second license either.
		$this->webhook( 'subscription.trialing', $sub, array( 'occurred_at' => self::when( 5 ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE paddle_subscription_id = %s", $sub['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertCount( 1, $this->mails_with( 'free trial has started' ) );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_paddle_events WHERE event_id = %s", 'evt_test_dup_' . $sub['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'created', $row['status'] );
	}

	/**
	 * An event older than the last applied one is skipped (out of order).
	 */
	public function test_out_of_order_event_is_skipped(): void {
		[ $lic, $sub ] = $this->start_trial();
		$active        = $sub;
		$active['status'] = 'active';
		$active['items'][0]['trial_dates'] = null;
		$this->webhook( 'subscription.activated', $active, array( 'occurred_at' => self::when( 60 ) ) );
		$response = $this->webhook( 'subscription.trialing', $sub, array( 'occurred_at' => self::when( 30 ) ) );
		$this->assertSame( 'stale', $response->get_data()['action'] );
		$this->assertSame( 'active', SFLS_Licenses::find( (int) $lic['id'] )['billing_status'] );
	}

	/**
	 * transaction.completed before subscription.created: the subscription is
	 * fetched from the API and the license is created and linked to the checkout.
	 */
	public function test_transaction_first_fetches_subscription(): void {
		$sub = $this->subscription( array( 'status' => 'active' ) );
		$this->customer_emails[ $sub['customer_id'] ] = $this->email();
		$this->paddle_routes[ 'GET /subscriptions/' . $sub['id'] ] = $sub;
		$txn = $this->transaction( $sub, array( 'origin' => 'web' ) );

		$this->assertSame( 200, $this->webhook( 'transaction.completed', $txn )->get_status() );
		$lic = SFLS_Licenses::find_by_subscription( $sub['id'] );
		$this->assertNotNull( $lic );
		$this->assertSame( $txn['id'], $lic['paddle_transaction_id'] );
		$this->assertCount( 1, $this->calls( 'GET', '/subscriptions/' . $sub['id'] ) );
		$call = $this->calls( 'GET', '/subscriptions/' . $sub['id'] )[0];
		$this->assertSame( 'Bearer pdl_sdbx_apikey_test', $call['headers']['Authorization'] );
		$this->assertSame( '1', $call['headers']['Paddle-Version'] );
	}

	/**
	 * When the API cannot be reached the event fails with 500 (Paddle
	 * retries) and is not marked as handled.
	 */
	public function test_failure_returns_500_and_allows_retry(): void {
		$sub = $this->subscription( array( 'status' => 'active' ) );
		$this->paddle_routes[ 'GET /subscriptions/' . $sub['id'] ] = array( 500, array( 'error' => array( 'code' => 'internal_error', 'detail' => 'down' ) ) );
		$txn      = $this->transaction( $sub );
		$event_id = 'evt_test_retry_' . $sub['id'];
		$this->assertSame( 500, $this->webhook( 'transaction.completed', $txn, array( 'event_id' => $event_id ) )->get_status() );

		unset( $this->paddle_routes[ 'GET /subscriptions/' . $sub['id'] ] );
		$this->paddle_routes[ 'GET /subscriptions/' . $sub['id'] ] = $sub;
		$this->customer_emails[ $sub['customer_id'] ]               = $this->email();
		$retry = $this->webhook( 'transaction.completed', $txn, array( 'event_id' => $event_id ) );
		$this->assertSame( 200, $retry->get_status() );
		$this->assertArrayNotHasKey( 'duplicate', $retry->get_data() );
		$this->assertNotNull( SFLS_Licenses::find_by_subscription( $sub['id'] ) );
	}

	/**
	 * Events we do not use are acknowledged.
	 */
	public function test_unused_event_is_acknowledged(): void {
		$response = $this->webhook( 'customer.updated', array( 'id' => 'ctm_x' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ignored', $response->get_data()['action'] );
	}
}
