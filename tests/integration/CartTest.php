<?php
/**
 * Item 2: one plan in the cart at a time, and a Change plan link.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Buy buttons and the add-to-cart guard.
 */
class SFLS_Cart_Test extends SFLS_Test_Case {

	/**
	 * License product IDs in the cart.
	 *
	 * @return int[]
	 */
	private function license_lines(): array {
		$out = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( SFLS_Woo::is_license_product( (int) $item['product_id'] ) ) {
				$out[] = (int) $item['product_id'];
			}
		}
		return $out;
	}

	/**
	 * Clicking Buy Starter and then Buy Pro leaves only Pro.
	 */
	public function test_two_buy_buttons_leave_one_license(): void {
		$starter = $this->make_license_product( 'starter', 12, '49' );
		$pro     = $this->make_license_product( 'pro', 12, '99' );
		$woo     = new SFLS_Woo();

		$this->assertTrue( $woo->buy( $starter->get_id() ) );
		$this->assertTrue( $woo->buy( $pro->get_id() ) );

		$this->assertSame( array( $pro->get_id() ), $this->license_lines() );
		$this->assertEqualsWithDelta( 99.0, (float) WC()->cart->get_subtotal(), 0.001 );
		$this->assertContains( 'You can buy one plan at a time. We swapped your cart to Pro.', $this->notices() );
	}

	/**
	 * Clicking the same Buy button twice keeps one line and says nothing.
	 */
	public function test_same_buy_button_twice_is_harmless(): void {
		$pro = $this->make_license_product( 'pro' );
		$woo = new SFLS_Woo();
		$woo->buy( $pro->get_id() );
		$woo->buy( $pro->get_id() );
		$this->assertSame( array( $pro->get_id() ), $this->license_lines() );
		$this->assertSame( array(), $this->notices() );
	}

	/**
	 * Unrelated items stay in the cart when the plan is swapped.
	 */
	public function test_swap_keeps_unrelated_items(): void {
		$shirt   = $this->make_physical_product();
		$starter = $this->make_license_product( 'starter' );
		$agency  = $this->make_license_product( 'agency', 0, '599' );
		WC()->cart->add_to_cart( $shirt->get_id() );
		$woo = new SFLS_Woo();
		$woo->buy( $starter->get_id() );
		$woo->buy( $agency->get_id() );

		$this->assertSame( array( $agency->get_id() ), $this->license_lines() );
		$this->assertSame( 2, count( WC()->cart->get_cart() ) );
		$this->assertContains( 'You can buy one plan at a time. We swapped your cart to Agency Lifetime.', $this->notices() );
	}

	/**
	 * Any other way into the cart (shop button, add-to-cart link) runs the
	 * woocommerce_add_to_cart_validation guard.
	 */
	public function test_add_to_cart_guard_replaces_the_first_license(): void {
		$starter = $this->make_license_product( 'starter' );
		$pro     = $this->make_license_product( 'pro' );

		// What WC_Form_Handler::add_to_cart_action() does for ?add-to-cart=ID.
		foreach ( array( $starter, $pro ) as $product ) {
			$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 );
			$this->assertTrue( $passed );
			WC()->cart->add_to_cart( $product->get_id(), 1 );
		}

		$this->assertSame( array( $pro->get_id() ), $this->license_lines() );
		$this->assertContains( 'You can buy one plan at a time. We swapped your cart to Pro.', $this->notices() );
	}

	/**
	 * The Store API (block cart, add-to-cart blocks) goes through the guard too.
	 */
	public function test_store_api_add_item_swaps_plan(): void {
		$this->filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		$starter = $this->make_license_product( 'starter' );
		$pro     = $this->make_license_product( 'pro' );

		foreach ( array( $starter, $pro ) as $product ) {
			$response = $this->rest(
				'POST',
				'/wc/store/v1/cart/add-item',
				array(
					'id'       => $product->get_id(),
					'quantity' => 1,
				)
			);
			$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		}

		$items = $response->get_data()['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( $pro->get_id(), $items[0]['id'] );
		$this->assertTrue( $items[0]['extensions']->sfls['is_license'] ?? $items[0]['extensions']['sfls']['is_license'] );
	}

	/**
	 * The classic checkout shows "Change plan" next to the license.
	 */
	public function test_classic_checkout_change_plan_link(): void {
		$pro = $this->make_license_product( 'pro' );
		$this->filter( 'woocommerce_is_checkout', '__return_true' );
		$html = ( new SFLS_Checkout() )->classic_change_plan( 'SubtleForms Pro', array( 'product_id' => $pro->get_id() ), 'k' );
		$this->assertStringContainsString( 'Change plan', $html );
		$this->assertStringContainsString( esc_url( SFLS_Settings::pricing_url() ), $html );

		$shirt = $this->make_physical_product();
		$this->assertSame( 'Shirt', ( new SFLS_Checkout() )->classic_change_plan( 'Shirt', array( 'product_id' => $shirt->get_id() ), 'k' ) );
	}

	/**
	 * Store API cart items tell the Checkout block which line is a license.
	 */
	public function test_store_api_cart_item_extension(): void {
		$pro  = $this->make_license_product( 'pro', 0, '299' );
		$data = ( new SFLS_Checkout() )->cart_item_extension( array( 'product_id' => $pro->get_id() ) );
		$this->assertTrue( $data['is_license'] );
		$this->assertTrue( $data['swappable'] );
		$this->assertSame( 'Pro Lifetime', $data['plan'] );
	}
}
