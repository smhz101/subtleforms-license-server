<?php
/**
 * Item 10: prorated upgrades that keep the key.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Upgrade flow.
 */
class SFLS_Upgrade_Test extends SFLS_Test_Case {

	/**
	 * Wizard product for a plan, or skip.
	 *
	 * @param string $plan Plan.
	 * @param string $type Type.
	 */
	private function site_product( string $plan, string $type = 'yearly' ): WC_Product {
		$row = SFLS_Products::find( $plan, $type );
		if ( ! $row ) {
			$this->markTestSkipped( "No $plan $type product on the test site." );
		}
		return wc_get_product( $row['id'] );
	}

	/**
	 * Halfway through the year, Starter to Pro costs half the difference.
	 */
	public function test_quote_is_prorated(): void {
		$starter = (float) $this->site_product( 'starter' )->get_price();
		$pro     = $this->site_product( 'pro' );
		$now     = time();
		$lic     = $this->make_license( array( 'plan' => 'starter' ) );
		$lic['expires_at'] = gmdate( 'Y-m-d H:i:s', $now + (int) ( 182.5 * DAY_IN_SECONDS ) );

		$quote = SFLS_Upgrades::quote( $lic, $pro->get_id(), $now );

		$this->assertEqualsWithDelta( ( (float) $pro->get_price() - $starter ) / 2, $quote['charge'], 0.01 );
		$this->assertEqualsWithDelta( (float) $pro->get_price() - $quote['charge'], $quote['credit'], 0.01 );
		$this->assertTrue( $quote['keep_expiry'] );
		$this->assertSame( 183, $quote['days_left'] );
	}

	/**
	 * Expired licenses pay the full price and start a new term.
	 */
	public function test_quote_full_price_when_expired(): void {
		$pro = $this->site_product( 'pro' );
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		$lic['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		$quote = SFLS_Upgrades::quote( $lic, $pro->get_id() );
		$this->assertEqualsWithDelta( (float) $pro->get_price(), $quote['charge'], 0.001 );
		$this->assertFalse( $quote['keep_expiry'] );
	}

	/**
	 * Lifetime to lifetime pays the difference of the lifetime prices.
	 */
	public function test_quote_lifetime_difference(): void {
		$starter = (float) $this->site_product( 'starter', 'lifetime' )->get_price();
		$agency  = $this->site_product( 'agency', 'lifetime' );
		$lic     = $this->make_license(
			array(
				'plan'   => 'starter',
				'months' => 0,
			)
		);
		$quote = SFLS_Upgrades::quote( $lic, $agency->get_id() );
		$this->assertEqualsWithDelta( (float) $agency->get_price() - $starter, $quote['charge'], 0.001 );
	}

	/**
	 * The whole flow: cart charges the prorated amount, payment upgrades the
	 * same key in place, raises sites and features, keeps the expiry and
	 * leaves an order note.
	 */
	public function test_upgrade_flow_keeps_key_and_charges_difference(): void {
		$pro_product = $this->site_product( 'pro' );
		$user        = $this->make_user();
		$lic         = $this->make_license(
			array(
				'plan'           => 'starter',
				'customer_email' => $user->user_email,
				'user_id'        => $user->ID,
			)
		);
		$expires = gmdate( 'Y-m-d H:i:s', time() + 100 * DAY_IN_SECONDS );
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => $expires ) );
		$lic = SFLS_Licenses::find( (int) $lic['id'] );
		wp_set_current_user( $user->ID );

		$options = SFLS_Products::upgrade_options( $lic );
		$this->assertSame( $pro_product->get_id(), $options['pro']['id'] );
		$quote = SFLS_Upgrades::quote( $lic, $pro_product->get_id() );
		$this->assertGreaterThan( 0, $quote['charge'] );
		$this->assertLessThan( (float) $pro_product->get_price(), $quote['charge'] );

		$this->assertTrue( ( new SFLS_Woo() )->put_in_cart( $pro_product->get_id(), array( 'sfls_upgrade_license' => (int) $lic['id'] ) ) );
		WC()->cart->calculate_totals();
		$this->assertEqualsWithDelta( $quote['charge'], (float) WC()->cart->get_subtotal(), 0.01, 'The cart charges the prorated amount.' );

		$item_data = apply_filters( 'woocommerce_get_item_data', array(), array_values( WC()->cart->get_cart() )[0] );
		$this->assertContains( 'Prorated discount', wp_list_pluck( $item_data, 'key' ) );

		// Check out through the Store API like the Checkout block does. Over HTTP
		// the Store API request is detected from the URL; here it is in-process.
		$this->filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		$this->filter( 'sfls_checkout_context', '__return_true' );
		WC()->countries->locale = null;
		$response = $this->rest(
			'POST',
			'/wc/store/v1/checkout',
			array(
				'billing_address' => array(
					'first_name' => 'Up',
					'last_name'  => 'Grader',
					'email'      => $user->user_email,
					'country'    => 'US',
				),
				'payment_method'  => 'cheque',
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order_id       = (int) $response->get_data()['order_id'];
		$this->orders[] = $order_id;
		$order          = wc_get_order( $order_id );
		$this->assertEqualsWithDelta( $quote['charge'], (float) $order->get_total(), 0.02 );

		WC()->countries->locale = null;
		$order->set_payment_method( 'stripe' );
		$order->save();
		$order->payment_complete( 'ch_test' );

		$after = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( $lic['license_key'], $after['license_key'], 'Same key.' );
		$this->assertSame( 'pro', $after['plan'] );
		$this->assertSame( (int) SFLS_Plans::get( 'pro' )['sites'], (int) $after['sites_allowed'] );
		$this->assertSame( $expires, $after['expires_at'], 'Prorated upgrades keep the renewal date.' );
		$this->assertContains( 'webhooks', SFLS_Licenses::payload( $after )['features'] );
		$this->assertSame( array(), $this->licenses_of_order( $order_id ), 'No new key.' );

		$order = wc_get_order( $order_id );
		$this->assertSame( 'completed', $order->get_status() );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order_id ) ), 'content' );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'upgraded from Starter to Pro' ) && str_contains( $n, 'same key' ) ) );
	}

	/**
	 * Someone else's license gets no discount.
	 */
	public function test_no_discount_for_licenses_you_do_not_own(): void {
		$pro_product = $this->site_product( 'pro' );
		$owner       = $this->make_user();
		$lic         = $this->make_license(
			array(
				'plan'           => 'starter',
				'customer_email' => $owner->user_email,
				'user_id'        => $owner->ID,
			)
		);
		wp_set_current_user( $this->make_user()->ID );
		WC()->cart->add_to_cart( $pro_product->get_id(), 1, 0, array(), array( 'sfls_upgrade_license' => (int) $lic['id'] ) );
		WC()->cart->calculate_totals();
		$this->assertEqualsWithDelta( (float) $pro_product->get_price(), (float) WC()->cart->get_subtotal(), 0.001 );
	}
}
