<?php
/**
 * Customer billing actions (upgrade with proration, cancel, resume, update
 * card), the members area, payload fields, notices and the welcome endpoint.
 *
 * @package SFLS
 */

/**
 * Paddle billing.
 */
class SFLS_Paddle_Billing_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;

	/**
	 * Set up Paddle.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->paddle();
	}

	/**
	 * An active (paid) Paddle license owned by a user.
	 *
	 * @param array $over Subscription overrides.
	 * @return array{0:array,1:array,2:WP_User}
	 */
	private function paid( array $over = array() ): array {
		$user = $this->make_user();
		$sub  = $this->subscription( array_merge( array( 'status' => 'active' ), $over ) );
		$this->customer_emails[ $sub['customer_id'] ] = $user->user_email;
		$this->webhook( 'subscription.created', $sub );
		return array( SFLS_Licenses::find_by_subscription( $sub['id'] ), $sub, $user );
	}

	/**
	 * Upgrade: PATCH /subscriptions/{id} with the new price and
	 * prorated_immediately; the key stays and the limits rise at once.
	 */
	public function test_upgrade_uses_prorated_immediately(): void {
		[ $lic, $sub ] = $this->paid( array( '_price' => 'pri_starter', '_amount' => '3900' ) );
		$this->assertSame( 'starter', $lic['plan'] );
		$this->assertSame( 1, (int) $lic['sites_allowed'] );

		$this->paddle_routes[ 'PATCH /subscriptions/' . $sub['id'] ] = static function ( $call ) use ( $sub ) {
			$updated                                              = $sub;
			$updated['items'][0]['price']['id']                   = $call['body']['items'][0]['price_id'];
			$updated['items'][0]['price']['unit_price']['amount'] = '7900';
			return $updated;
		};

		$after = SFLS_Billing::change_plan( $lic, 'pro' );
		$this->assertIsArray( $after );
		$patch = $this->calls( 'PATCH', '/subscriptions/' . $sub['id'] );
		$this->assertCount( 1, $patch );
		$this->assertSame(
			array(
				'items'                  => array(
					array(
						'price_id' => 'pri_pro',
						'quantity' => 1,
					),
				),
				'proration_billing_mode' => 'prorated_immediately',
				'on_payment_failure'     => 'prevent_change',
			),
			$patch[0]['body']
		);
		$this->assertSame( $lic['license_key'], $after['license_key'] );
		$this->assertSame( 'pro', $after['plan'] );
		$this->assertSame( 5, (int) $after['sites_allowed'], 'Limits rise right away.' );
		$this->assertContains( 'webhooks', SFLS_Licenses::payload( $after )['features'] );
	}

	/**
	 * Upgrading during a trial switches to the new plan's trial price and
	 * bills nothing now; the trial stays at 1 site.
	 */
	public function test_upgrade_during_trial(): void {
		[ $lic, $sub ] = $this->start_trial();
		$this->paddle_routes[ 'PATCH /subscriptions/' . $sub['id'] ] = static function ( $call ) use ( $sub ) {
			$updated                            = $sub;
			$updated['items'][0]['price']['id'] = $call['body']['items'][0]['price_id'];
			$updated['custom_data']['sfls_plan'] = 'agency';
			return $updated;
		};
		$after = SFLS_Billing::change_plan( $lic, 'agency' );
		$body  = $this->calls( 'PATCH', '/subscriptions/' . $sub['id'] )[0]['body'];
		$this->assertSame( 'do_not_bill', $body['proration_billing_mode'] );
		$this->assertSame( 'pri_agency', $body['items'][0]['price_id'], 'Agency has no trial price, so its yearly price is used.' );
		$this->assertSame( 'agency', $after['plan'] );
		$this->assertSame( 1, (int) $after['sites_allowed'] );
	}

	/**
	 * A refused update leaves the license alone and returns the error.
	 */
	public function test_failed_upgrade_changes_nothing(): void {
		[ $lic, $sub ] = $this->paid();
		$this->paddle_routes[ 'PATCH /subscriptions/' . $sub['id'] ] = array( 400, array( 'error' => array( 'code' => 'subscription_update_when_past_due', 'detail' => 'Cannot update a past due subscription' ) ) );
		$result = SFLS_Billing::change_plan( $lic, 'agency' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'past due', $result->get_error_message() );
		$this->assertSame( 'pro', SFLS_Licenses::find( (int) $lic['id'] )['plan'] );
	}

	/**
	 * Cancel auto-renew and Resume.
	 */
	public function test_cancel_and_resume(): void {
		[ $lic, $sub ] = $this->paid();
		$scheduled     = $sub;
		$scheduled['scheduled_change'] = array(
			'action'       => 'cancel',
			'effective_at' => $sub['current_billing_period']['ends_at'],
			'resume_at'    => null,
		);
		$this->paddle_routes[ 'POST /subscriptions/' . $sub['id'] . '/cancel' ] = $scheduled;
		$this->paddle_routes[ 'PATCH /subscriptions/' . $sub['id'] ]           = $sub;

		$canceled = SFLS_Billing::cancel_auto_renew( $lic );
		$this->assertSame( array( 'effective_from' => 'next_billing_period' ), $this->calls( 'POST', '/subscriptions/' . $sub['id'] . '/cancel' )[0]['body'] );
		$this->assertNotNull( $canceled['cancels_at'] );
		$this->assertNull( $canceled['renews_at'] );
		$this->assertTrue( SFLS_Licenses::state( $canceled )['valid'] );

		$resumed = SFLS_Billing::resume( $canceled );
		$patch   = $this->calls( 'PATCH', '/subscriptions/' . $sub['id'] )[0];
		$this->assertSame( '{"scheduled_change":null}', $patch['raw'], 'The scheduled cancellation is removed.' );
		$this->assertNull( $resumed['cancels_at'] );
		$this->assertNotNull( $resumed['renews_at'] );
	}

	/**
	 * Update payment method: a fresh customer portal session for the subscription.
	 */
	public function test_portal_link(): void {
		[ $lic, $sub ] = $this->paid();
		$this->paddle_routes[ 'POST /customers/' . $sub['customer_id'] . '/portal-sessions' ] = array(
			'id'   => 'cpls_1',
			'urls' => array(
				'general'       => array( 'overview' => 'https://sandbox-customer-portal.paddle.com/cpl_1?action=overview&token=t' ),
				'subscriptions' => array(
					array(
						'id'                                 => $sub['id'],
						'cancel_subscription'                => 'https://sandbox-customer-portal.paddle.com/cpl_1?action=cancel&token=t',
						'update_subscription_payment_method' => 'https://sandbox-customer-portal.paddle.com/cpl_1?action=update&token=t',
					),
				),
			),
		);
		$url  = SFLS_Billing::portal_url( $lic, 'payment' );
		$call = $this->calls( 'POST', '/customers/' . $sub['customer_id'] . '/portal-sessions' )[0];
		$this->assertSame( array( 'subscription_ids' => array( $sub['id'] ) ), $call['body'] );
		$this->assertStringContainsString( 'action=update', $url );
		$this->assertStringContainsString( 'action=overview', SFLS_Billing::portal_url( $lic, 'overview' ) );
	}

	/**
	 * My Account › Licenses shows the billing state and the buttons.
	 */
	public function test_members_area_card(): void {
		[ $lic, $sub, $user ] = $this->paid();
		wp_set_current_user( $user->ID );
		ob_start();
		( new SFLS_Account() )->licenses_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Renews on', $html );
		$this->assertStringContainsString( '$79.00', $html );
		$this->assertStringContainsString( 'Update payment method', $html );
		$this->assertStringContainsString( 'Cancel auto-renew', $html );
		$this->assertStringContainsString( 'value="change_plan"', $html );
		$this->assertStringContainsString( 'Upgrade to Agency', $html );
		$this->assertStringNotContainsString( 'sfls_renew=', $html, 'No manual Renew for Paddle licenses.' );

		SFLS_Licenses::update(
			(int) $lic['id'],
			array(
				'cancels_at' => gmdate( 'Y-m-d H:i:s', time() + 100 * DAY_IN_SECONDS ),
				'renews_at'  => null,
			)
		);
		ob_start();
		( new SFLS_Account() )->licenses_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Cancels on', $html );
		$this->assertStringContainsString( 'value="resume"', $html );
		$this->assertStringNotContainsString( 'value="cancel"', $html );

		SFLS_Licenses::update(
			(int) $lic['id'],
			array(
				'cancels_at'     => null,
				'billing_status' => 'past_due',
			)
		);
		ob_start();
		( new SFLS_Account() )->licenses_page();
		$this->assertStringContainsString( 'Past due', (string) ob_get_clean() );
	}

	/**
	 * A trial card says when it ends, that it covers 1 site, and offers Cancel.
	 */
	public function test_members_area_trial_card(): void {
		$user = $this->make_user();
		[ $lic ] = $this->start_trial( $user->user_email );
		wp_set_current_user( $user->ID );
		ob_start();
		( new SFLS_Account() )->licenses_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Free trial', $html );
		$this->assertStringContainsString( 'Trial ends on', $html );
		$this->assertStringContainsString( 'Trial covers 1 site', $html );
		$this->assertStringContainsString( 'Then $79.00 a year', $html );
		$this->assertStringContainsString( 'value="cancel"', $html );
		$this->assertSame( (int) $user->ID, (int) $lic['user_id'] );
	}

	/**
	 * Payload: billing fields for every kind of license; old fields stay.
	 */
	public function test_payload_fields(): void {
		$old = array( 'valid', 'status', 'plan', 'features', 'expires_at', 'grace_period_ends_at', 'sites_allowed', 'sites_used', 'check_again_in', 'is_local' );
		$new = array( 'billing', 'billing_status', 'trial', 'trial_ends_at', 'renews_at', 'cancels_at', 'renewal_amount', 'manage_url' );

		$manual = SFLS_Licenses::payload( $this->make_license() );
		foreach ( array_merge( $old, $new ) as $key ) {
			$this->assertArrayHasKey( $key, $manual, $key );
		}
		$this->assertSame( 'manual', $manual['billing'] );
		$this->assertFalse( $manual['trial'] );
		$this->assertNull( $manual['billing_status'] );
		$this->assertNull( $manual['trial_ends_at'] );
		$this->assertNull( $manual['renewal_amount'] );

		$woo = SFLS_Licenses::payload( $this->make_license( array( 'order_id' => 123456 ) ) );
		$this->assertSame( 'woocommerce', $woo['billing'] );

		[ $trial ] = $this->start_trial();
		$payload   = SFLS_Licenses::payload( $trial, 'example.com' );
		$this->assertTrue( $payload['valid'] );
		$this->assertSame( 'valid', $payload['status'], 'Old Pro versions see a normal valid license.' );
		$this->assertSame( 'paddle', $payload['billing'] );
		$this->assertSame( 'trialing', $payload['billing_status'] );
		$this->assertTrue( $payload['trial'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $payload['trial_ends_at'] );
		$this->assertNotNull( $payload['renews_at'] );
		$this->assertNull( $payload['cancels_at'] );
		$this->assertSame( '$79.00', $payload['renewal_amount'] );
		$this->assertStringContainsString( 'licenses', $payload['manage_url'] );
		$this->assertFalse( $payload['is_local'] );

		// Through the REST API Pro calls.
		SFLS_Licenses::add_activation( (int) $trial['id'], 'example.com' );
		$api = $this->rest(
			'POST',
			'/sfls/v1/license/validate',
			array(
				'license_key' => $trial['license_key'],
				'site_url'    => 'https://example.com',
			)
		)->get_data();
		$this->assertTrue( $api['trial'] );
		$this->assertSame( 'paddle', $api['billing'] );
		$this->assertTrue( $api['valid'] );
	}

	/**
	 * Trial ending (3 days before) and renewal notices (14 and 3 days),
	 * once each; WooCommerce reminders skip Paddle licenses.
	 */
	public function test_trial_and_renewal_notices(): void {
		$this->settings(
			array(
				'trial_notice'    => 3,
				'renewal_notice'  => '14,3',
				'reminder_days'   => '30,7',
				'lapsed_reminder' => 0,
			)
		);
		[ $trial ] = $this->start_trial();
		SFLS_Licenses::update( (int) $trial['id'], array( 'trial_ends_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) ) );

		[ $paid ] = $this->paid();
		SFLS_Licenses::update(
			(int) $paid['id'],
			array(
				'renews_at'  => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 13 * DAY_IN_SECONDS ),
			)
		);
		$this->mails = array();

		SFLS_Reminders::run();
		$trial_mail = array_values( array_filter( $this->mails, static fn( $m ) => str_contains( $m['subject'], 'trial ends on' ) ) );
		$this->assertCount( 1, $trial_mail );
		$this->assertStringContainsString( '$79.00', $trial_mail[0]['message'] );
		$this->assertStringContainsString( 'Cancel auto-renew', $trial_mail[0]['message'] );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), time() + 2 * DAY_IN_SECONDS ), $trial_mail[0]['message'] );

		$renewal = array_values( array_filter( $this->mails, static fn( $m ) => str_contains( $m['subject'], 'SubtleForms Pro renews on' ) ) );
		$this->assertCount( 1, $renewal );
		$this->assertStringContainsString( '$79.00', $renewal[0]['message'] );
		$this->assertSame( array(), array_values( array_filter( $this->mails, static fn( $m ) => str_contains( $m['subject'], 'expires in' ) ) ), 'No manual-renewal reminder for a Paddle license.' );

		// Nothing twice.
		$count = count( $this->mails );
		SFLS_Reminders::run();
		$this->assertCount( $count, $this->mails );

		// The 3 day renewal notice is a separate stage.
		SFLS_Licenses::update( (int) $paid['id'], array( 'renews_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) ) );
		SFLS_Reminders::run();
		$this->assertCount( $count + 1, $this->mails );

		// Set to cancel: no renewal notice.
		$this->mails = array();
		SFLS_Licenses::update(
			(int) $paid['id'],
			array(
				'renews_at'  => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
				'cancels_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
			)
		);
		SFLS_Reminders::run();
		$this->assertSame( array(), $this->mails );
	}

	/**
	 * Every billing template can be sent as a test.
	 */
	public function test_test_emails(): void {
		foreach ( array_keys( SFLS_Billing_Emails::templates() ) as $template ) {
			$this->mails = array();
			$this->assertTrue( SFLS_Billing_Emails::send_test( 'admin@' . self::DOMAIN, $template ), $template );
			$this->assertCount( 1, $this->mails, $template );
			$this->assertStringStartsWith( '[Test]', $this->mails[0]['subject'], $template );
			$this->assertStringNotContainsString( '—', $this->mails[0]['message'], $template . ' has no em dash.' );
		}
	}

	/**
	 * GET /sfls/v1/paddle/checkout: waits, then shows the key only to the
	 * browser with the checkout token (or the signed-in owner).
	 */
	public function test_welcome_endpoint(): void {
		$waiting = $this->rest( 'GET', '/sfls/v1/paddle/checkout', array( 'txn' => 'txn_notyet' ) )->get_data();
		$this->assertFalse( $waiting['ready'] );

		$sub = $this->subscription();
		$txn = 'txn_' . strtolower( wp_generate_password( 14, false, false ) );
		[ $lic ] = $this->start_trial( '', array( 'id' => $sub['id'] ) + $sub );
		SFLS_Licenses::update( (int) $lic['id'], array( 'paddle_transaction_id' => $txn ) );
		$token = $sub['custom_data']['sfls_token'];
		SFLS_Licenses::update( (int) $lic['id'], array( 'checkout_token_hash' => hash( 'sha256', $token ) ) );

		$ready = $this->rest(
			'GET',
			'/sfls/v1/paddle/checkout',
			array(
				'txn'   => $txn,
				'token' => $token,
			)
		)->get_data();
		$this->assertTrue( $ready['ready'] );
		$this->assertTrue( $ready['authorized'] );
		$this->assertSame( $lic['license_key'], $ready['license_key'] );
		$this->assertTrue( $ready['trial'] );
		$this->assertNotSame( '', $ready['trial_ends'] );
		$this->assertSame( '$79.00', $ready['amount'] );

		$stranger = $this->rest(
			'GET',
			'/sfls/v1/paddle/checkout',
			array(
				'txn'   => $txn,
				'token' => 'wrong',
			)
		)->get_data();
		$this->assertTrue( $stranger['ready'] );
		$this->assertFalse( $stranger['authorized'] );
		$this->assertArrayNotHasKey( 'license_key', $stranger );
	}

	/**
	 * Pricing in Paddle mode: trial and buy buttons with the right data.
	 */
	public function test_pricing_cards(): void {
		$html = ( new SFLS_Shortcodes() )->pricing( array() );
		$this->assertStringContainsString( 'Start 14-day free trial', $html );
		$this->assertStringContainsString( 'Start 7-day free trial', $html );
		$this->assertStringContainsString( 'Then $79/year, cancel anytime', $html );
		$this->assertStringContainsString( 'data-trial-price="pri_pro_trial"', $html );
		$this->assertStringContainsString( 'data-price="pri_agency"', $html );
		$this->assertStringContainsString( 'Buy Agency', $html );
		$this->assertStringContainsString( 'sfls-trial-email', $html );
		$this->assertTrue( wp_script_is( 'sfls-paddle', 'enqueued' ) );

		// A signed-in customer who had a trial sees Buy now with the reason.
		$user = $this->make_user();
		SFLS_Trials::record_start( (int) $this->make_license()['id'], $user->user_email );
		wp_set_current_user( $user->ID );
		$html = ( new SFLS_Shortcodes() )->pricing( array() );
		$this->assertStringNotContainsString( 'free trial</button>', $html );
		$this->assertStringContainsString( 'data-mode="buy"', $html );
		$this->assertStringContainsString( 'already had a free trial', $html );
	}

	/**
	 * Migration: schema 4 columns, tables and the billing backfill.
	 */
	public function test_schema_4_migration(): void {
		global $wpdb;
		$this->assertSame( SFLS_DB::SCHEMA_VERSION, get_option( 'sfls_schema_version' ) );
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}sfls_licenses LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lic = $row ? $row : $this->make_license();
		foreach ( array_merge( array( 'billing' ), SFLS_Licenses::BILLING_COLUMNS ) as $column ) {
			$this->assertArrayHasKey( $column, $lic, $column );
		}
		$old = $this->make_license( array( 'order_id' => 99 ) );
		SFLS_Licenses::update( (int) $old['id'], array( 'billing' => '' ) );
		SFLS_DB::install();
		$this->assertSame( 'woocommerce', SFLS_Licenses::find( (int) $old['id'] )['billing'] );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_trial_claims WHERE kind = 'nothing'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotNull( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_paddle_events" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
