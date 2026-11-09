<?php
/**
 * Item 9: key, Download Pro and install steps after purchase.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Order received page and order emails.
 */
class SFLS_Delivery_Test extends SFLS_Test_Case {

	/**
	 * The completed order email carries the key, Download Pro and the steps.
	 */
	public function test_completed_email_has_key_download_and_steps(): void {
		$this->add_release( '9.9.0' ); // Its own package, whatever the site has.
		$this->set_option( 'woocommerce_customer_completed_order_settings', array_merge( (array) get_option( 'woocommerce_customer_completed_order_settings', array() ), array( 'enabled' => 'yes' ) ) );
		$user  = $this->make_user();
		$order = $this->make_order( array( $this->make_license_product( 'pro' ) ), $user );
		$this->mails = array();

		$order->payment_complete( 'ch_test' );

		$completed = array_values( array_filter( $this->mails, static fn( $m ) => $m['to'] === $user->user_email || ( is_array( $m['to'] ) && in_array( $user->user_email, $m['to'], true ) ) ) );
		$this->assertNotEmpty( $completed, 'The customer gets the completed order email.' );
		$body = $completed[ count( $completed ) - 1 ]['message'];
		$lic  = $this->licenses_of_order( $order->get_id() )[0];

		$this->assertStringContainsString( $lic['license_key'], $body );
		$this->assertStringContainsString( 'Download Pro', $body );
		$this->assertStringContainsString( 'pro-downloads', $body );
		$this->assertStringContainsString( 'How to install Pro', $body );
		$this->assertStringContainsString( 'Download the zip', $body );
		$this->assertStringContainsString( 'Upload it to WordPress', $body );
		$this->assertStringContainsString( 'Connect your account', $body );
	}

	/**
	 * Plain text emails get the same content.
	 */
	public function test_plain_text_email(): void {
		$order = $this->make_order( array( $this->make_license_product( 'starter' ) ) );
		$order->payment_complete( 'ch_test' );
		$order = wc_get_order( $order->get_id() );
		ob_start();
		( new SFLS_Delivery() )->email( $order, false, true );
		$text = (string) ob_get_clean();
		$this->assertStringContainsString( $this->licenses_of_order( $order->get_id() )[0]['license_key'], $text );
		$this->assertStringContainsString( '1. Download the zip', $text );
		$this->assertStringContainsString( '3. Connect your account', $text );
	}

	/**
	 * Admin emails list the key without the customer instructions.
	 */
	public function test_admin_email_lists_keys_only(): void {
		$order = $this->make_order( array( $this->make_license_product() ) );
		$order->payment_complete( 'ch_test' );
		ob_start();
		( new SFLS_Delivery() )->email( wc_get_order( $order->get_id() ), true, false );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'SFP-', $html );
		$this->assertStringNotContainsString( 'How to install Pro', $html );
	}

	/**
	 * The order received page shows the panel once, right after the status
	 * block of the block theme, and not again further down.
	 */
	public function test_order_received_panel(): void {
		$order = $this->make_order( array( $this->make_license_product() ) );
		$order->payment_complete( 'ch_test' );
		$order = wc_get_order( $order->get_id() );
		set_query_var( 'order-received', $order->get_id() );
		$_GET['key'] = $order->get_order_key();

		$delivery = new SFLS_Delivery();
		$html     = $delivery->after_status_block( '<p>Thank you.</p>' );
		$this->assertStringStartsWith( '<p>Thank you.</p>', $html );
		$this->assertStringContainsString( $this->licenses_of_order( $order->get_id() )[0]['license_key'], $html );
		$this->assertStringContainsString( 'data-sfls-copy', $html );
		$this->assertStringContainsString( 'How to install Pro', $html );

		ob_start();
		$delivery->order_page( $order );
		$delivery->thankyou( $order->get_id() );
		$this->assertSame( '', (string) ob_get_clean(), 'Printed once per request.' );

		unset( $_GET['key'] );
		set_query_var( 'order-received', 0 );
	}

	/**
	 * Strangers with a wrong order key see nothing.
	 */
	public function test_order_received_needs_the_order_key(): void {
		$order = $this->make_order( array( $this->make_license_product() ) );
		$order->payment_complete( 'ch_test' );
		set_query_var( 'order-received', $order->get_id() );
		$_GET['key'] = 'wc_order_wrong';
		$this->assertSame( '<p>x</p>', ( new SFLS_Delivery() )->after_status_block( '<p>x</p>' ) );
		unset( $_GET['key'] );
		set_query_var( 'order-received', 0 );
	}

	/**
	 * Before payment the page says the key is on its way.
	 */
	public function test_unpaid_order_says_key_follows(): void {
		$order = $this->make_order( array( $this->make_license_product() ) );
		$html  = SFLS_Delivery::panel_html( $order );
		$this->assertStringContainsString( 'appears here as soon as your payment is confirmed', $html );
	}

	/**
	 * A trialing Paddle license, as SFLS_Billing::fields_for() stores it.
	 *
	 * @param array $over Fields to change.
	 */
	private function trial_license( array $over = array() ): array {
		return array_merge(
			array(
				'plan'                   => 'starter',
				'billing'                => 'paddle',
				'billing_status'         => 'trialing',
				'paddle_subscription_id' => 'sub_trial_test',
				'trial'                  => 1,
				'trial_ends_at'          => '2026-11-15 00:37:22',
				'cancels_at'             => null,
				'billing_amount'         => '4900',
				'billing_currency'       => 'USD',
			),
			$over
		);
	}

	/**
	 * The trial line promises what the subscription will charge, not the plan's current price.
	 */
	public function test_trial_phrase_uses_the_subscription_amount(): void {
		$config = (array) get_option( 'sfls_paddle', array() );
		$plans  = (array) ( $config['plans'] ?? array() );
		$plans['starter'] = array_merge( (array) ( $plans['starter'] ?? array() ), array( 'amount' => '5900', 'currency' => 'USD' ) );
		$this->set_option( 'sfls_paddle', array_merge( $config, array( 'plans' => $plans ) ) );

		$line = SFLS_Delivery::trial_phrase( $this->trial_license() );
		$this->assertStringContainsString( 'trial ends', $line );
		$this->assertStringContainsString( '49.00/year', $line );
		$this->assertStringNotContainsString( '59', $line );
	}

	/**
	 * Zero-decimal currencies are not divided by 100.
	 */
	public function test_trial_phrase_zero_decimal_currency(): void {
		$line = SFLS_Delivery::trial_phrase( $this->trial_license( array( 'billing_amount' => '4900', 'billing_currency' => 'JPY' ) ) );
		$this->assertMatchesRegularExpression( '/4,?900\/year/', $line );
		$this->assertStringNotContainsString( '49.00', $line );
	}

	/**
	 * Without a stored amount the plan's mapping is the fallback.
	 */
	public function test_trial_phrase_falls_back_to_the_plan_price(): void {
		$config = (array) get_option( 'sfls_paddle', array() );
		$plans  = (array) ( $config['plans'] ?? array() );
		$plans['starter'] = array_merge( (array) ( $plans['starter'] ?? array() ), array( 'amount' => '4900', 'currency' => 'USD' ) );
		$this->set_option( 'sfls_paddle', array_merge( $config, array( 'plans' => $plans ) ) );

		$line = SFLS_Delivery::trial_phrase( $this->trial_license( array( 'billing_amount' => '', 'billing_currency' => '' ) ) );
		$this->assertStringContainsString( '49.00/year', $line );
	}

	/**
	 * A cancelled trial is never charged; a paid license keeps "renews".
	 */
	public function test_trial_phrase_cancelled_and_paid(): void {
		$this->assertStringContainsString( 'no charge', SFLS_Delivery::trial_phrase( $this->trial_license( array( 'cancels_at' => '2026-11-15 00:37:22' ) ) ) );
		$this->assertSame( '', SFLS_Delivery::trial_phrase( $this->trial_license( array( 'billing_status' => 'active', 'trial' => 0 ) ) ) );
	}

	/**
	 * Only a Paddle subscription "renews". A manual or one-off WooCommerce
	 * license is "valid until" its date, and no date is "lifetime".
	 */
	public function test_license_line_term_by_billing(): void {
		$base = array(
			'plan'          => 'agency',
			'sites_allowed' => 25,
			'expires_at'    => '2027-11-08 01:30:44',
		);

		$manual = SFLS_Delivery::license_line( array_merge( $base, array( 'billing' => 'manual' ) ) );
		$this->assertStringContainsString( 'valid until', $manual );
		$this->assertStringNotContainsString( 'renews', $manual );

		$woo = SFLS_Delivery::license_line( array_merge( $base, array( 'billing' => 'woocommerce', 'order_id' => 12 ) ) );
		$this->assertStringContainsString( 'valid until', $woo );

		$paddle = SFLS_Delivery::license_line(
			array_merge(
				$base,
				array( 'billing' => 'paddle', 'billing_status' => 'active', 'paddle_subscription_id' => 'sub_active_test', 'trial' => 0 )
			)
		);
		$this->assertStringContainsString( 'renews', $paddle );
		$this->assertStringNotContainsString( 'valid until', $paddle );

		$this->assertStringContainsString( 'lifetime', SFLS_Delivery::license_line( array_merge( $base, array( 'billing' => 'manual', 'expires_at' => null ) ) ) );
	}
}
