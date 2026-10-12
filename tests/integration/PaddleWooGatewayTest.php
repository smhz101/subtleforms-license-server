<?php
/**
 * Pay with Paddle: the WooCommerce gateway (License Server 1.5.0).
 *
 * The Paddle API is mocked through pre_http_request; carts, checkouts,
 * orders, emails and the signed webhook run for real.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals

/**
 * Gateway availability, transactions, completion, trials, renewals, refunds,
 * upgrades and tax.
 */
class SFLS_Paddle_Woo_Gateway_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;
	use SFLS_Woo_Paddle_Helpers;

	/**
	 * Mock and products.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->woo_paddle();
	}

	/**
	 * Refunds of an order, from the database (WooCommerce caches the list).
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order_Refund[]
	 */
	private function refunds_of( WC_Order $order ): array {
		return wc_get_orders(
			array(
				'type'   => 'shop_order_refund',
				'parent' => $order->get_id(),
				'limit'  => 20,
			)
		);
	}

	/**
	 * Gateways offered for the current cart.
	 *
	 * @return string[]
	 */
	private function offered(): array {
		WC()->cart->calculate_totals();
		return array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
	}

	// ── Availability ────────────────────────────────────────────────────

	/**
	 * Pay with Paddle is the only method for a license-only cart, and only
	 * then.
	 */
	public function test_gateway_availability_rules(): void {
		$this->cart_plan( 'pro' );
		$this->assertSame( array( 'sfls_paddle' ), $this->offered(), 'License-only cart: Paddle only.' );

		// A physical product next to it: not a license checkout.
		WC()->cart->add_to_cart( $this->make_physical_product()->get_id() );
		$this->assertNotContains( 'sfls_paddle', $this->offered() );
		$this->assertContains( 'cheque', $this->offered() );

		// Only a physical product.
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->make_physical_product()->get_id() );
		$this->assertNotContains( 'sfls_paddle', $this->offered() );

		// Lifetime license without a Paddle product for its plan.
		WC()->cart->empty_cart();
		$lifetime = $this->make_license_product( 'agency', 0, '499' );
		( new SFLS_Woo() )->buy( $lifetime->get_id() );
		$this->assertContains( 'sfls_paddle', $this->offered(), 'Lifetime is a one-time Paddle charge when the plan has a Paddle product.' );
		$config                                   = SFLS_Paddle::config();
		$config['plans']['agency']['product_id'] = '';
		update_option( SFLS_Paddle::OPTION, $config );
		$this->assertNotContains( 'sfls_paddle', $this->offered() );

		// Other selling modes or missing credentials hide it.
		WC()->cart->empty_cart();
		$this->cart_plan( 'pro' );
		$this->settings( array( 'sell_with' => 'woocommerce' ) );
		$this->assertNotContains( 'sfls_paddle', $this->offered() );
		$this->assertContains( 'cheque', $this->offered() );
		$this->settings( array( 'sell_with' => 'woo_paddle' ) );
		$config                 = SFLS_Paddle::config();
		$config['client_token'] = '';
		update_option( SFLS_Paddle::OPTION, $config );
		$this->assertNotContains( 'sfls_paddle', $this->offered() );

		// Switched off in WooCommerce.
		$config['client_token'] = 'test_clienttoken';
		update_option( SFLS_Paddle::OPTION, $config );
		update_option( 'woocommerce_sfls_paddle_settings', array( 'enabled' => 'no' ) );
		$this->reset_gateways();
		$this->assertNotContains( 'sfls_paddle', $this->offered() );
	}

	/**
	 * The Checkout block integration describes the method.
	 */
	public function test_block_integration(): void {
		require_once SFLS_DIR . 'includes/class-sfls-gateway-paddle-blocks.php';
		$block = new SFLS_Gateway_Paddle_Blocks();
		$block->initialize();
		$this->assertTrue( $block->is_active() );
		$this->assertSame( array( 'sfls-gateway-blocks' ), $block->get_payment_method_script_handles() );
		$data = $block->get_payment_method_data();
		$this->assertSame( 'Pay with Paddle', $data['title'] );
		$this->assertSame( 'Sales tax or VAT is calculated by Paddle at payment.', $data['taxNote'] );
		$this->assertContains( 'refunds', $data['supports'] );
		$this->assertStringContainsString( 'Card', $data['methods'] );
	}

	// ── Transaction ─────────────────────────────────────────────────────

	/**
	 * Place order builds the right Paddle transaction from the order.
	 */
	public function test_process_payment_builds_transaction(): void {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'sflstest' . strtolower( wp_generate_password( 5, false ) ) );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 10 );
		$coupon->save();
		$this->posts[] = $coupon->get_id();

		$this->cart_plan( 'pro' );
		WC()->cart->apply_coupon( $coupon->get_code() );
		$email = $this->email();
		$order = $this->checkout_order( $email );
		$this->assertSame( '69.00', $order->get_total() );
		$this->assertSame( 'pending', $order->get_status() );

		$result = $this->pay( $order );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $order->get_checkout_payment_url( true ), $result['redirect'] );

		$lookup = $this->calls( 'GET', '/customers' );
		$this->assertSame( $email, $lookup[0]['query']['email'] );
		$this->assertSame( $email, $this->calls( 'POST', '/customers' )[0]['body']['email'] );
		$address = $this->calls( 'POST', '#^/customers/ctm_\w+/addresses$#' )[0]['body'];
		$this->assertSame( 'US', $address['country_code'] );
		$this->assertSame( '10001', $address['postal_code'] );

		$body = $this->calls( 'POST', '/transactions' )[0]['body'];
		$this->assertSame(
			array(
				array(
					'price_id' => 'pri_pro',
					'quantity' => 1,
				),
			),
			$body['items']
		);
		$this->assertStringStartsWith( 'ctm_', $body['customer_id'] );
		$this->assertSame( 'add_test01', $body['address_id'] );
		$this->assertSame( 'USD', $body['currency_code'] );
		$this->assertSame( 'automatic', $body['collection_mode'] );
		$this->assertSame( 'flat', $body['discount']['type'] );
		$this->assertSame( '1000', $body['discount']['amount'] );
		$this->assertFalse( $body['discount']['recur'], 'The coupon applies to the first payment only.' );
		$this->assertArrayNotHasKey( 'discount_id', $body );
		$this->assertSame( (string) $order->get_id(), $body['custom_data']['wc_order_id'] );
		$this->assertSame( $order->get_order_key(), $body['custom_data']['order_key'] );
		$this->assertSame( 'subscription', $body['custom_data']['sfls_kind'] );
		$this->assertSame( 'pro', $body['custom_data']['sfls_plan'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertStringStartsWith( 'txn_', $order->get_meta( '_sfls_paddle_txn_id' ) );
		$this->assertSame( 'pending', $order->get_status(), 'Pending until Paddle confirms.' );
		$this->assertSame( array(), $this->licenses_of_order( $order->get_id() ) );

		// Paying again (order-pay retry) replaces and cancels the old transaction.
		$first = (string) $order->get_meta( '_sfls_paddle_txn_id' );
		$this->assertSame( 'success', $this->pay( $order )['result'] );
		$cancel = $this->calls( 'PATCH', '/transactions/' . $first );
		$this->assertSame( array( 'status' => 'canceled' ), $cancel[0]['body'] );
		$this->assertNotSame( $first, wc_get_order( $order->get_id() )->get_meta( '_sfls_paddle_txn_id' ) );

		// A coupon mapped to a Paddle discount uses it instead.
		$coupon->update_meta_data( '_sfls_paddle_discount_id', 'dsc_onceonly01' );
		$coupon->save();
		$this->assertSame( array( 'discount_id' => 'dsc_onceonly01' ), SFLS_Woo_Paddle::discount_for_order( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * A Paddle price that differs from the WooCommerce price is refused
	 * before the customer pays.
	 */
	public function test_price_mismatch_is_refused(): void {
		$this->plan_products['pro']->set_regular_price( '59' );
		$this->plan_products['pro']->save();
		$this->cart_plan( 'pro' );
		$order  = $this->checkout_order( $this->email() );
		$result = $this->pay( $order );
		$this->assertSame( 'failure', $result['result'] );
		$this->assertStringContainsString( 'cannot be paid right now', implode( ' ', $this->notices( 'error' ) ) );
		$txn = $this->calls( 'POST', '/transactions' )[0];
		$this->assertNotEmpty( $this->calls( 'PATCH', '#^/transactions/txn_#' ), 'The transaction is canceled.' );
		unset( $txn );
	}

	/**
	 * The order payment page shows the inline Paddle form for the transaction (1.5.1).
	 */
	public function test_payment_page(): void {
		$this->cart_plan( 'pro' );
		$order = $this->checkout_order( $this->email() );
		$this->pay( $order );
		$order = wc_get_order( $order->get_id() );
		ob_start();
		( new SFLS_Woo_Paddle() )->receipt_page( $order->get_id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Complete your payment', $html );
		$this->assertStringContainsString( 'data-sfls-paddle-pay-inline', $html );
		$this->assertTrue( wp_script_is( 'sfls-paddle-inline', 'enqueued' ) );
		$data = (string) wp_scripts()->get_data( 'sfls-paddle-inline', 'data' );
		$this->assertStringContainsString( (string) $order->get_meta( '_sfls_paddle_txn_id' ), $data );
		$this->assertStringContainsString( 'test_clienttoken', $data );
	}

	// ── Completion ──────────────────────────────────────────────────────

	/**
	 * The webhook completes the right order once, adds Paddle's tax, and
	 * links the license to the subscription.
	 */
	public function test_webhook_completes_order_once(): void {
		$this->paddle_tax = 0.1;
		$email            = $this->email();
		$this->cart_plan( 'pro' );
		$order = $this->checkout_order( $email );
		$this->pay( $order );
		$txn = $this->completed_txn( $order );

		$response = $this->webhook( 'transaction.completed', $txn );
		$this->assertSame( 'completed', $response->get_data()['action'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( $txn['id'], $order->get_transaction_id() );
		$this->assertSame( '86.90', $order->get_total(), 'The order records what Paddle charged, tax included.' );
		$fees = $order->get_fees();
		$this->assertCount( 1, $fees );
		$this->assertSame( '7.9', (string) (float) reset( $fees )->get_total() );
		$this->assertSame( 'Paddle (Visa ending 4242)', $order->get_payment_method_title() );

		$licenses = $this->licenses_of_order( $order->get_id() );
		$this->assertCount( 1, $licenses );
		$lic = $licenses[0];
		$this->assertSame( 'paddle', $lic['billing'] );
		$this->assertSame( $txn['subscription_id'], $lic['paddle_subscription_id'] );
		$this->assertSame( $txn['id'], $lic['paddle_transaction_id'] );
		$this->assertSame( 'active', $lic['billing_status'] );
		$this->assertSame( '5', (string) $lic['sites_allowed'] );
		$this->assertNotEmpty( $lic['renews_at'] );
		$this->assertSame( $txn['subscription_id'], $order->get_meta( '_sfls_paddle_subscription_id' ) );

		// The order email carries the key.
		$mail = array_values( array_filter( $this->mails, static fn( $m ) => str_contains( (string) $m['message'], $lic['license_key'] ) ) );
		$this->assertNotEmpty( $mail, 'The order email carries the key.' );

		// Paddle retries (new event ID), the subscription events follow: still one license.
		$this->webhook( 'transaction.completed', $txn );
		$sub = $this->subs[ $txn['subscription_id'] ];
		$this->webhook( 'subscription.created', $sub + array( 'transaction_id' => $txn['id'] ) );
		$this->webhook( 'subscription.activated', $sub );
		$this->assertCount( 1, $this->licenses_of_order( $order->get_id() ) );
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE paddle_subscription_id = %s", $txn['subscription_id'] ) ) );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_fees(), 'Tax is added once.' );

		// A second, different payment for the same order is flagged, not applied.
		$other       = $txn;
		$other['id'] = 'txn_' . strtolower( wp_generate_password( 14, false, false ) );
		$response    = $this->webhook( 'transaction.completed', $other );
		$this->assertSame( 'duplicate', $response->get_data()['action'] );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'already paid' ) ) );
	}

	/**
	 * Amount, currency and order key are checked.
	 */
	public function test_webhook_rejects_mismatch(): void {
		$this->cart_plan( 'pro' );
		$order = $this->checkout_order( $this->email() );
		$this->pay( $order );
		$txn = $this->completed_txn(
			$order,
			array(
				'details' => array(
					'totals' => array(
						'subtotal' => '1000',
						'total'    => '1000',
						'tax'      => '0',
					),
				),
			)
		);
		$response = $this->webhook( 'transaction.completed', $txn );
		$this->assertSame( 'rejected', $response->get_data()['action'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array(), $this->licenses_of_order( $order->get_id() ) );

		// Wrong order key: nothing happens.
		WC()->cart->empty_cart();
		$this->cart_plan( 'pro' );
		$second = $this->checkout_order( $this->email() );
		$this->pay( $second );
		$txn                             = $this->completed_txn( $second );
		$txn['custom_data']['order_key'] = 'wc_order_forged';
		$this->webhook( 'transaction.completed', $txn );
		$this->assertSame( 'pending', wc_get_order( $second->get_id() )->get_status() );
		$this->assertSame( array(), $this->licenses_of_order( $second->get_id() ) );

		// Wrong currency.
		$txn = $this->completed_txn( $second, array( 'details' => array( 'totals' => array( 'currency_code' => 'EUR' ) ) ) );
		$this->assertSame( 'rejected', $this->webhook( 'transaction.completed', $txn )->get_data()['action'] );
		$this->assertSame( array(), $this->licenses_of_order( $second->get_id() ) );
	}

	/**
	 * subscription.created can arrive before transaction.completed.
	 */
	public function test_subscription_event_first(): void {
		$this->cart_plan( 'pro' );
		$order = $this->checkout_order( $this->email() );
		$this->pay( $order );
		$txn = $this->completed_txn( $order );
		$sub = $this->subs[ $txn['subscription_id'] ];
		// No custom data on the subscription: found through its transaction.
		$sub['custom_data'] = null;

		$response = $this->webhook( 'subscription.created', $sub + array( 'transaction_id' => $txn['id'] ) );
		$this->assertSame( 'completed', $response->get_data()['action'], wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, $this->licenses_of_order( $order->get_id() ) );

		$this->webhook( 'transaction.completed', $txn );
		$this->assertCount( 1, $this->licenses_of_order( $order->get_id() ) );
		$this->assertSame( $sub['id'], $this->licenses_of_order( $order->get_id() )[0]['paddle_subscription_id'] );
	}

	/**
	 * The order received page polls; Paddle is asked directly when the
	 * webhook is late.
	 */
	public function test_order_status_endpoint(): void {
		$this->cart_plan( 'pro' );
		$order = $this->checkout_order( $this->email() );
		$this->pay( $order );
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 404, $this->rest( 'GET', '/sfls/v1/paddle/checkout', array( 'order' => $order->get_id(), 'key' => 'wc_order_wrong' ) )->get_status() );
		$data = $this->rest( 'GET', '/sfls/v1/paddle/checkout', array( 'order' => $order->get_id(), 'key' => $order->get_order_key() ) )->get_data();
		$this->assertFalse( $data['ready'] );

		// The order received page shows "Confirming your payment" meanwhile.
		$this->filter( 'woocommerce_is_order_received_page', '__return_true' );
		$html = SFLS_Delivery::panel_html( $order );
		$this->assertStringContainsString( 'data-sfls-order-status', $html );
		$this->assertStringContainsString( 'Confirming your payment', $html );
		remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		// Elsewhere (My Account) a pending order offers to pay.
		$this->assertStringContainsString( 'Pay now', SFLS_Delivery::panel_html( $order ) );

		// Paddle has the payment, the webhook has not come yet.
		$this->completed_txn( $order );
		delete_transient( 'sfls_paddle_poll_' . $order->get_id() );
		$data = $this->rest( 'GET', '/sfls/v1/paddle/checkout', array( 'order' => $order->get_id(), 'key' => $order->get_order_key() ) )->get_data();
		$this->assertTrue( $data['ready'] );
		$lic = $this->licenses_of_order( $order->get_id() )[0];
		$this->assertSame( $lic['license_key'], $data['license_key'] );
		$this->assertStringContainsString( $lic['license_key'], $data['html'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	// ── Trials ──────────────────────────────────────────────────────────

	/**
	 * A trial is a $0 order that still goes through Paddle with the trial
	 * price, and the license starts as a 1 site trial.
	 */
	public function test_trial_order_flow(): void {
		$email = $this->email( 'trial' );
		$this->cart_plan( 'pro', true );
		$this->assertSame( 0.0, (float) WC()->cart->get_total( 'edit' ) );
		$this->assertTrue( WC()->cart->needs_payment(), 'A $0 trial still needs Paddle to collect the card.' );
		$contents = WC()->cart->get_cart_contents();
		$item     = reset( $contents );
		$rows = apply_filters( 'woocommerce_get_item_data', array(), $item );
		$this->assertContains( 'Free for 14 days, then $79.00/year', wp_list_pluck( $rows, 'value' ) );
		$this->assertSame( array( 'sfls_paddle' ), $this->offered() );

		$order = $this->checkout_order( $email );
		$this->assertSame( '0.00', $order->get_total() );
		$this->assertTrue( $order->needs_payment() );
		$items = $order->get_items();
		$line  = reset( $items );
		$this->assertSame( '14', (string) $line->get_meta( '_sfls_trial' ) );
		$this->assertSame( 'Free for 14 days, then $79.00/year', $line->get_meta( 'Free trial' ) );

		$this->pay( $order );
		$body = $this->calls( 'POST', '/transactions' )[0]['body'];
		$this->assertSame( 'pri_pro_trial', $body['items'][0]['price_id'] );
		$this->assertSame( 'trial', $body['custom_data']['sfls_kind'] );
		$this->assertArrayNotHasKey( 'discount', $body );

		$txn = $this->completed_txn( $order );
		$this->assertSame( '0', $txn['details']['totals']['total'] );
		$this->webhook( 'transaction.completed', $txn );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$lic = $this->licenses_of_order( $order->get_id() )[0];
		$this->assertSame( '1', (string) $lic['trial'] );
		$this->assertSame( '1', (string) $lic['sites_allowed'] );
		$this->assertSame( 'trialing', $lic['billing_status'] );
		$this->assertNotEmpty( $lic['trial_ends_at'] );
		$this->assertFalse( SFLS_Trials::check( $email )['eligible'], 'The trial is claimed.' );

		// The same person cannot start a second trial at checkout.
		WC()->cart->empty_cart();
		$this->cart_plan( 'pro', true );
		$errors = new WP_Error();
		( new SFLS_Woo_Paddle() )->classic_validate(
			array(
				'billing_email'  => $email,
				'payment_method' => 'sfls_paddle',
			),
			$errors
		);
		$message = (string) $errors->get_error_message( 'sfls_trial' );
		$this->assertStringContainsString( 'already had a free trial', $message );
		$this->assertStringContainsString( 'sfls_trial=0', $message, 'Offers to buy without the trial.' );

		// Store API (Checkout block) refuses it too.
		$second = $this->checkout_order( $email );
		try {
			( new SFLS_Woo_Paddle() )->store_api_validate( $second );
			$this->fail( 'Expected a RouteException.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
			$this->assertSame( 'sfls_trial_not_eligible', $e->getErrorCode() );
			$this->assertStringContainsString( 'Buy without the free trial', $e->getMessage() );
		}

		// Buying without the trial swaps the cart line.
		( new SFLS_Woo() )->buy( $this->plan_products['pro']->get_id(), false );
		WC()->cart->calculate_totals();
		$this->assertSame( 79.0, (float) WC()->cart->get_total( 'edit' ) );
		$this->assertCount( 1, WC()->cart->get_cart() );
		$errors = new WP_Error();
		( new SFLS_Woo_Paddle() )->classic_validate(
			array(
				'billing_email'  => $email,
				'payment_method' => 'sfls_paddle',
			),
			$errors
		);
		$this->assertFalse( $errors->has_errors() );
	}

	// ── Renewals ────────────────────────────────────────────────────────

	/**
	 * A Paddle renewal charge becomes a Completed renewal order and extends
	 * the license; no new license.
	 */
	public function test_renewal_creates_order_without_new_license(): void {
		$user  = $this->make_user();
		$order = $this->buy_with_paddle( $user->user_email, 'pro', false, $user );
		$lic   = $this->licenses_of_order( $order->get_id() )[0];
		$sub   = $this->subs[ $lic['paddle_subscription_id'] ];
		$this->mails = array();

		$renewal = $this->transaction(
			$sub,
			array(
				'billing_period' => array(
					'starts_at' => self::when( 365 * DAY_IN_SECONDS ),
					'ends_at'   => self::when( 730 * DAY_IN_SECONDS ),
				),
			)
		);
		$response = $this->webhook( 'transaction.completed', $renewal );
		$this->assertSame( 200, $response->get_status() );

		$orders = wc_get_orders(
			array(
				'transaction_id' => $renewal['id'],
				'limit'          => 5,
			)
		);
		$this->assertCount( 1, $orders );
		$renewal_order  = $orders[0];
		$this->orders[] = $renewal_order->get_id();
		$this->assertSame( 'completed', $renewal_order->get_status() );
		$this->assertSame( 'sfls_paddle', $renewal_order->get_payment_method() );
		$this->assertSame( (string) $order->get_id(), (string) $renewal_order->get_meta( '_sfls_parent_order' ) );
		$this->assertSame( $user->ID, $renewal_order->get_customer_id() );
		$this->assertSame( '79.00', $renewal_order->get_total() );
		$items = $renewal_order->get_items();
		$item  = reset( $items );
		$this->assertSame( (string) $lic['id'], (string) $item->get_meta( '_sfls_license_ids' ) );

		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE customer_email = %s", $user->user_email ) ), 'No new license.' );
		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( $lic['license_key'], $after['license_key'] );
		$this->assertGreaterThan( strtotime( $lic['expires_at'] . ' UTC' ) + 300 * DAY_IN_SECONDS, strtotime( $after['expires_at'] . ' UTC' ) );
		$this->assertSame( array(), $this->mails_with( 'on its way' ), 'No WooCommerce completed email for renewals.' );
		$this->assertNotEmpty( $this->mails_with( 'Payment received' ) + $this->mails_with( 'payment' ), 'The Paddle payment email is sent.' );

		// It shows in the customer's orders.
		$mine = wc_get_orders(
			array(
				'customer_id' => $user->ID,
				'limit'       => 10,
				'return'      => 'ids',
			)
		);
		$this->assertContains( $renewal_order->get_id(), $mine );

		// Paddle retries: still one renewal order.
		$this->webhook( 'transaction.completed', $renewal );
		$this->assertCount( 1, wc_get_orders( array( 'transaction_id' => $renewal['id'] ) ) );

		// A failed renewal: note on the first order and the past due email.
		$failed = $this->transaction( $sub, array( 'status' => 'past_due' ) );
		$this->mails = array();
		$this->webhook( 'transaction.payment_failed', $failed );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'could not charge the renewal' ) ) );
		$this->assertNotEmpty( $this->mails );
	}

	// ── Refunds ─────────────────────────────────────────────────────────

	/**
	 * Refunds from WooCommerce go to Paddle; refunds in Paddle come back as
	 * WooCommerce refunds; nothing is refunded twice.
	 */
	public function test_refunds_both_directions(): void {
		$order = $this->buy_with_paddle( $this->email() );
		$txn   = $this->txns[ $order->get_transaction_id() ];
		$adjustments = array();
		$this->paddle_routes = array( 'POST /adjustments' => function ( $call ) use ( &$adjustments ) {
			$id                 = 'adj_' . strtolower( wp_generate_password( 10, false, false ) );
			$adjustments[ $id ] = $call['body'];
			return array(
				201,
				array(
					'id'             => $id,
					'status'         => 'pending_approval',
					'action'         => 'refund',
					'transaction_id' => $call['body']['transaction_id'],
					'totals'         => array(
						'total'         => '2000',
						'currency_code' => 'USD',
					),
				),
			);
		} ) + $this->paddle_routes;

		// Partial refund from WooCommerce.
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '20.00',
				'reason'         => 'Asked nicely',
				'refund_payment' => true,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund, is_wp_error( $refund ) ? $refund->get_error_message() : '' );
		$this->assertCount( 1, $adjustments );
		$adj_id = (string) array_key_first( $adjustments );
		$body   = $adjustments[ $adj_id ];
		$this->assertSame( 'refund', $body['action'] );
		$this->assertSame( $txn['id'], $body['transaction_id'] );
		$this->assertSame( 'partial', $body['type'] );
		$this->assertSame( 'Asked nicely', $body['reason'] );
		$this->assertSame( '2000', $body['items'][0]['amount'] );
		$this->assertStringStartsWith( 'txnitm_', $body['items'][0]['item_id'] );
		$this->assertSame( $adj_id, wc_get_order( $refund->get_id() )->get_meta( '_sfls_paddle_adjustment_id' ) );

		// Paddle approves it: no second WooCommerce refund.
		$adjustment = array(
			'id'             => $adj_id,
			'action'         => 'refund',
			'status'         => 'approved',
			'transaction_id' => $txn['id'],
			'totals'         => array(
				'total'         => '2000',
				'currency_code' => 'USD',
			),
		);
		$this->webhook( 'adjustment.updated', $adjustment );
		$this->assertCount( 1, $this->refunds_of( $order ) );

		// A refund made in the Paddle dashboard: pending, then approved.
		$paddle_adj = array(
			'id'             => 'adj_dashboard01',
			'action'         => 'refund',
			'status'         => 'pending_approval',
			'reason'         => 'Duplicate purchase',
			'transaction_id' => $txn['id'],
			'totals'         => array(
				'total'         => '1500',
				'currency_code' => 'USD',
			),
		);
		$this->webhook( 'adjustment.created', $paddle_adj );
		$this->assertCount( 1, $this->refunds_of( $order ), 'Not before approval.' );
		$paddle_adj['status'] = 'approved';
		$this->webhook( 'adjustment.updated', $paddle_adj );
		$refunds = $this->refunds_of( $order );
		$this->assertCount( 2, $refunds );
		$this->assertSame( '15.00', wc_format_decimal( $refunds[0]->get_amount(), 2 ) );
		$this->assertSame( 'adj_dashboard01', $refunds[0]->get_meta( '_sfls_paddle_adjustment_id' ) );
		$this->assertCount( 1, $adjustments, 'Mirrored refunds are not sent back to Paddle.' );
		$this->webhook( 'adjustment.updated', $paddle_adj );
		$this->assertCount( 2, $this->refunds_of( $order ), 'Replays do not refund twice.' );

		// Paddle rejects the WooCommerce refund: it is removed again.
		$adjustment['status'] = 'rejected';
		$this->webhook( 'adjustment.updated', $adjustment );
		$refunds = $this->refunds_of( $order );
		$this->assertCount( 1, $refunds );
		$this->assertSame( 'adj_dashboard01', $refunds[0]->get_meta( '_sfls_paddle_adjustment_id' ) );

		// Full refund: type full, and the subscription is canceled.
		$this->paddle_routes = array(
			'#^POST /subscriptions/sub_\w+/cancel$#' => function ( $call ) {
				$id                          = explode( '/', $call['path'] )[2];
				$this->subs[ $id ]['status'] = 'canceled';
				$this->subs[ $id ]['canceled_at'] = self::when( 0 );
				return $this->subs[ $id ];
			},
		) + $this->paddle_routes;
		$rest = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => wc_format_decimal( wc_get_order( $order->get_id() )->get_remaining_refund_amount() ),
				'refund_payment' => true,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $rest, is_wp_error( $rest ) ? $rest->get_error_message() : '' );
		$last = end( $adjustments );
		$this->assertSame( 'partial', $last['type'], 'What is left after earlier refunds is a partial refund in Paddle.' );
		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );
		$this->assertNotEmpty( $this->calls( 'POST', '#^/subscriptions/sub_\w+/cancel$#' ) );
		$lic = $this->licenses_of_order( $order->get_id() )[0];
		$this->assertSame( 'suspended', $lic['status'] );
	}

	/**
	 * A full refund of an untouched order is sent as type full.
	 */
	public function test_full_refund_type(): void {
		$order = $this->buy_with_paddle( $this->email() );
		$sent  = array();
		$this->paddle_routes = array(
			'POST /adjustments' => function ( $call ) use ( &$sent ) {
				$sent[] = $call['body'];
				return array(
					201,
					array(
						'id'     => 'adj_full0001',
						'status' => 'pending_approval',
					),
				);
			},
			'#^POST /subscriptions/sub_\w+/cancel$#' => fn() => $this->subscription( array( 'status' => 'canceled' ) ),
		) + $this->paddle_routes;
		$gateway = SFLS_Woo_Paddle::gateway();
		$this->assertTrue( $gateway->process_refund( $order->get_id(), 79.0, '' ) );
		$this->assertSame( 'full', $sent[0]['type'] );
		$this->assertArrayNotHasKey( 'items', $sent[0] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $sent[0]['reason'] );

		// No Paddle transaction yet: refused.
		$pending = $this->make_order( array( $this->plan_products['pro'] ) );
		$pending->set_payment_method( 'sfls_paddle' );
		$pending->save();
		$this->assertInstanceOf( WP_Error::class, $gateway->process_refund( $pending->get_id(), 10.0, '' ) );
	}

	// ── Upgrades ────────────────────────────────────────────────────────

	/**
	 * An upgrade of a Paddle license at the WooCommerce checkout updates the
	 * subscription (prorated_immediately), keeps the key and records the
	 * prorated charge as an order.
	 */
	public function test_upgrade_updates_subscription(): void {
		$user  = $this->make_user();
		$order = $this->buy_with_paddle( $user->user_email, 'pro', false, $user );
		$lic   = $this->licenses_of_order( $order->get_id() )[0];
		$sub   = $this->subs[ $lic['paddle_subscription_id'] ];
		wp_set_current_user( $user->ID );

		// My Account offers the upgrade through the cart.
		$options = SFLS_Products::upgrade_options( $lic );
		$this->assertArrayHasKey( 'agency', $options );
		$this->assertSame( array(), SFLS_Account::paddle_upgrades( $lic ), 'No direct plan change in this mode.' );

		$this->paddle_routes = array(
			'#^PATCH /subscriptions/sub_\w+/preview$#' => array(
				'immediate_transaction' => array(
					'details' => array(
						'totals' => array(
							'subtotal'      => '11950',
							'tax'           => '0',
							'total'         => '11950',
							'currency_code' => 'USD',
						),
					),
				),
			),
			'#^PATCH /subscriptions/sub_\w+$#'        => function ( $call ) use ( $sub ) {
				$updated                         = $sub;
				$updated['items'][0]['price']['id'] = $call['body']['items'][0]['price_id'];
				$updated['items'][0]['price']['unit_price']['amount'] = '19900';
				$this->subs[ $sub['id'] ]        = $updated;
				return $updated;
			},
		) + $this->paddle_routes;

		WC()->cart->empty_cart();
		$this->cart_plan( 'agency', false, array( 'sfls_upgrade_license' => (int) $lic['id'] ) );
		$this->assertSame( array( 'sfls_paddle' ), $this->offered() );
		$estimate = (float) WC()->cart->get_total( 'edit' );
		$this->assertGreaterThan( 100, $estimate );
		$this->assertLessThan( 121, $estimate );

		$upgrade = $this->checkout_order( $user->user_email );
		$upgrade->set_customer_id( $user->ID );
		$upgrade->save();
		$before = count( $this->calls( 'POST', '/transactions' ) );
		$result = $this->pay( $upgrade );
		$this->assertSame( 'success', $result['result'], wp_json_encode( $this->notices( 'error' ) ) );
		$this->assertSame( $before, count( $this->calls( 'POST', '/transactions' ) ), 'No new subscription.' );

		$patch = $this->calls( 'PATCH', '/subscriptions/' . $sub['id'] )[0]['body'];
		$this->assertSame( 'prorated_immediately', $patch['proration_billing_mode'] );
		$this->assertSame( 'prevent_change', $patch['on_payment_failure'] );
		$this->assertSame( 'pri_agency', $patch['items'][0]['price_id'] );

		$upgrade = wc_get_order( $upgrade->get_id() );
		$this->assertSame( 'completed', $upgrade->get_status() );
		$this->assertSame( '119.50', $upgrade->get_total(), 'The order records what Paddle charged.' );
		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( $lic['license_key'], $after['license_key'] );
		$this->assertSame( 'agency', $after['plan'] );
		$this->assertSame( (string) $sub['id'], $after['paddle_subscription_id'] );
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE customer_email = %s", $user->user_email ) ) );

		// Paddle confirms the prorated charge.
		$charge = $this->transaction(
			$this->subs[ $sub['id'] ],
			array(
				'origin'  => 'subscription_update',
				'details' => array(
					'totals' => array(
						'subtotal'    => '11950',
						'total'       => '11950',
						'grand_total' => '11950',
					),
				),
			)
		);
		$this->mails = array();
		$this->webhook( 'transaction.completed', $charge );
		$upgrade = wc_get_order( $upgrade->get_id() );
		$this->assertSame( $charge['id'], $upgrade->get_transaction_id() );
		$this->assertSame( '', (string) $upgrade->get_meta( '_sfls_paddle_upgrade_pending' ) );
		$this->assertSame( array(), $this->mails_with( 'payment' ), 'The upgrade order already told the customer.' );
	}

	/**
	 * During a trial an upgrade charges nothing and still goes through Paddle.
	 */
	public function test_upgrade_during_trial(): void {
		$user  = $this->make_user();
		$order = $this->buy_with_paddle( $user->user_email, 'pro', true, $user );
		$lic   = $this->licenses_of_order( $order->get_id() )[0];
		$this->assertSame( 'trialing', $lic['billing_status'] );
		$sub = $this->subs[ $lic['paddle_subscription_id'] ];
		wp_set_current_user( $user->ID );
		$this->paddle_routes = array(
			'#^PATCH /subscriptions/sub_\w+$#' => function ( $call ) use ( $sub ) {
				$sub['items'][0]['price']['id'] = $call['body']['items'][0]['price_id'];
				return $sub;
			},
		) + $this->paddle_routes;

		WC()->cart->empty_cart();
		$this->cart_plan( 'agency', false, array( 'sfls_upgrade_license' => (int) $lic['id'] ) );
		$this->assertSame( 0.0, (float) WC()->cart->get_total( 'edit' ) );
		$this->assertTrue( WC()->cart->needs_payment() );
		$upgrade = $this->checkout_order( $user->user_email );
		$this->assertSame( 'success', $this->pay( $upgrade )['result'] );
		$patch = $this->calls( 'PATCH', '/subscriptions/' . $sub['id'] )[0]['body'];
		$this->assertSame( 'do_not_bill', $patch['proration_billing_mode'] );
		$this->assertEmpty( $this->calls( 'PATCH', '/subscriptions/' . $sub['id'] . '/preview' ) );
		$this->assertSame( 'completed', wc_get_order( $upgrade->get_id() )->get_status() );
		$this->assertSame( 'agency', SFLS_Licenses::find( (int) $lic['id'] )['plan'] );
	}

	// ── Tax, pricing, migration ─────────────────────────────────────────

	/**
	 * No WooCommerce tax on license products while Paddle takes the payment.
	 */
	public function test_tax_suppressed(): void {
		global $wpdb;
		$this->set_option( 'woocommerce_calc_taxes', 'yes' );
		$this->set_option( 'woocommerce_default_customer_address', 'base' );
		$rate = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'SFLS test tax',
				'tax_rate_priority' => 9,
				'tax_rate_shipping' => 0,
				'tax_rate_class'    => '',
			)
		);
		try {
			$this->cart_plan( 'pro' );
			$this->assertSame( 0.0, (float) WC()->cart->get_total_tax() );
			$this->assertSame( 79.0, (float) WC()->cart->get_total( 'edit' ) );
			$this->assertSame( 'none', $this->plan_products['pro']->get_tax_status() );
			ob_start();
			( new SFLS_Woo_Paddle() )->classic_tax_row();
			$this->assertStringContainsString( 'Sales tax or VAT is calculated by Paddle at payment.', (string) ob_get_clean() );

			// With other gateways WooCommerce tax applies as before.
			$this->settings( array( 'sell_with' => 'woocommerce' ) );
			WC()->cart->calculate_totals();
			$this->assertSame( 7.9, round( (float) WC()->cart->get_total_tax(), 2 ) );
		} finally {
			WC_Tax::_delete_tax_rate( $rate );
			unset( $wpdb );
		}
	}

	/**
	 * The pricing cards link to the cart, with the trial flag.
	 */
	public function test_pricing_links_to_cart(): void {
		wp_dequeue_script( 'sfls-paddle' ); // Left over from the overlay tests.
		$html = do_shortcode( '[sfls_pricing]' );
		$pro  = $this->plan_products['pro']->get_id();
		$this->assertStringContainsString( 'sfls_buy=' . $pro . '&#038;sfls_trial=1', $html );
		$this->assertStringContainsString( 'Start 14-day free trial', $html );
		$this->assertStringContainsString( 'or buy now without a trial', $html );
		$this->assertStringContainsString( 'sfls_buy=' . $this->plan_products['agency']->get_id(), $html );
		$this->assertStringNotContainsString( 'sfls-paddle-buy', $html );
		$this->assertFalse( wp_script_is( 'sfls-paddle', 'enqueued' ), 'No Paddle.js on the pricing page.' );
	}

	/**
	 * 1.4.0 shops that sold with the Paddle overlay move to the WooCommerce
	 * checkout with Paddle payments.
	 */
	public function test_migration_from_140(): void {
		$this->set_option( 'sfls_migrated_150', null );
		$this->settings( array( 'sell_with' => 'paddle' ) );
		$this->set_option( 'woocommerce_sfls_paddle_settings', array( 'enabled' => 'no' ) );
		SFLS_Settings::migrate_150();
		$this->assertSame( 'woo_paddle', SFLS_Settings::sell_with() );
		$this->assertSame( 'yes', get_option( 'woocommerce_sfls_paddle_settings' )['enabled'] );

		// Once only: choosing the legacy mode later sticks.
		$this->settings( array( 'sell_with' => 'paddle' ) );
		SFLS_Settings::migrate_150();
		$this->assertSame( 'paddle', SFLS_Settings::sell_with() );

		// A WooCommerce shop stays a WooCommerce shop.
		$this->set_option( 'sfls_migrated_150', null );
		$this->settings( array( 'sell_with' => 'woocommerce' ) );
		SFLS_Settings::migrate_150();
		$this->assertSame( 'woocommerce', SFLS_Settings::sell_with() );
		$this->assertSame( 'woocommerce', SFLS_Settings::clean_sell_with( 'bogus' ) );
	}

	/**
	 * An abandoned order that WooCommerce cancels cannot be paid in Paddle.
	 */
	public function test_cancelled_order_cancels_transaction(): void {
		$this->cart_plan( 'pro' );
		$order = $this->checkout_order( $this->email() );
		$this->pay( $order );
		$txn = (string) wc_get_order( $order->get_id() )->get_meta( '_sfls_paddle_txn_id' );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$this->assertSame( 'canceled', $this->txns[ $txn ]['status'] );
	}
}
