<?php
/**
 * LS-04: renewal products only renew a license the buyer owns.
 *
 * @package SFLS
 */

/**
 * Renewal products in the cart and at completion.
 */
class SFLS_Renewal_Gate_Test extends SFLS_Test_Case {

	/**
	 * A renewal product.
	 *
	 * @param string $plan Plan.
	 */
	private function renewal_product( string $plan = 'starter' ): WC_Product_Simple {
		$product = $this->make_license_product( $plan, 12, '39' );
		$product->update_meta_data( '_sfls_renewal', 'yes' );
		$product->save();
		return $product;
	}

	/**
	 * Products in the cart.
	 *
	 * @return int[]
	 */
	private function cart_products(): array {
		return array_values( array_map( static fn( $i ) => (int) $i['product_id'], WC()->cart->get_cart() ) );
	}

	/**
	 * The repro: signed out, a buy link for a renewal product is refused.
	 */
	public function test_signed_out_buy_link_is_refused(): void {
		$renewal = $this->renewal_product();
		$this->assertFalse( ( new SFLS_Woo() )->buy( $renewal->get_id() ) );
		$this->assertSame( array(), $this->cart_products() );
		$this->assertNotEmpty( array_filter( $this->notices( 'error' ), static fn( $n ) => str_contains( $n, 'Renewals are for existing licenses' ) ) );
	}

	/**
	 * Signed in, a renewal needs a license of the buyer with the same plan.
	 * A refused renewal leaves the plan already in the cart alone.
	 */
	public function test_renewal_needs_an_owned_license_of_that_plan(): void {
		$renewal = $this->renewal_product( 'starter' );
		$owner   = $this->make_user();
		$other   = $this->make_user();
		$lic     = $this->make_license(
			array(
				'plan'           => 'starter',
				'customer_email' => $owner->user_email,
				'user_id'        => $owner->ID,
			)
		);
		$pro_lic = $this->make_license(
			array(
				'plan'           => 'pro',
				'customer_email' => $owner->user_email,
				'user_id'        => $owner->ID,
			)
		);
		$woo     = new SFLS_Woo();

		wp_set_current_user( $other->ID );
		$plan = $this->make_license_product( 'pro' );
		$this->assertTrue( $woo->buy( $plan->get_id() ) );
		$this->assertFalse( $woo->put_in_cart( $renewal->get_id(), array( 'sfls_renew_license' => (int) $lic['id'] ) ), 'Not their license.' );
		$this->assertFalse( $woo->buy( $renewal->get_id() ), 'No license named.' );
		$this->assertSame( array( $plan->get_id() ), $this->cart_products(), 'The cart is unchanged.' );

		// Shop buttons and add-to-cart links.
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $renewal->get_id(), 1, 0, array(), array() ) );

		WC()->cart->empty_cart();
		wp_set_current_user( $owner->ID );
		$this->assertFalse( $woo->put_in_cart( $renewal->get_id(), array( 'sfls_renew_license' => (int) $pro_lic['id'] ) ), 'A Starter renewal cannot renew Pro.' );
		$this->assertTrue( $woo->put_in_cart( $renewal->get_id(), array( 'sfls_renew_license' => (int) $lic['id'] ) ) );
		$this->assertSame( array( $renewal->get_id() ), $this->cart_products() );

		// Signing out before checkout removes it again.
		wp_set_current_user( 0 );
		do_action( 'woocommerce_check_cart_items' );
		$this->assertSame( array(), $this->cart_products() );
	}

	/**
	 * The Store API refuses a renewal product too.
	 */
	public function test_store_api_refuses_renewals(): void {
		$this->filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		$renewal  = $this->renewal_product();
		$response = $this->rest(
			'POST',
			'/wc/store/v1/cart/add-item',
			array(
				'id'       => $renewal->get_id(),
				'quantity' => 1,
			)
		);
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( array(), $this->cart_products() );
	}

	/**
	 * An order with a renewal product that names no license of the customer
	 * never issues a new key.
	 */
	public function test_completion_never_issues_a_key_for_a_renewal(): void {
		$order = $this->make_order( array( $this->renewal_product() ) );
		$order->payment_complete( 'ch_test' );
		$this->assertSame( array(), $this->licenses_of_order( $order->get_id() ) );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'it is a renewal' ) ) );

		// Someone else's license named on the line is not renewed either.
		$owner  = $this->make_user();
		$lic    = $this->make_license(
			array(
				'plan'    => 'starter',
				'user_id' => $owner->ID,
			)
		);
		$buyer  = $this->make_user();
		$second = $this->make_order( array( $this->renewal_product() ), $buyer, array( array( '_sfls_renew_license' => (int) $lic['id'] ) ) );
		$second->payment_complete( 'ch_test' );
		$this->assertSame( array(), $this->licenses_of_order( $second->get_id() ) );
		$this->assertSame( $lic['expires_at'], SFLS_Licenses::find( (int) $lic['id'] )['expires_at'] );
	}
}
