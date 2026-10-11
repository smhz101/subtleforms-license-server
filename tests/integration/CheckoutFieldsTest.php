<?php
/**
 * Item 7: name, email and country only for license-only carts.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Simple checkout.
 */
class SFLS_Checkout_Fields_Test extends SFLS_Test_Case {

	/**
	 * Set up: pretend this request is the checkout.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->filter( 'sfls_checkout_context', '__return_true' );
		$this->settings( array( 'simple_checkout' => 1 ) );
	}

	/**
	 * Checkout fields, not the copy WC_Checkout cached earlier in the run.
	 */
	private function checkout_fields(): array {
		$prop = new ReflectionProperty( WC_Checkout::class, 'fields' );
		$prop->setAccessible( true );
		$prop->setValue( WC()->checkout(), null );
		return WC()->checkout()->get_checkout_fields();
	}

	/**
	 * Fresh country locale.
	 */
	private function locale(): array {
		WC()->countries->locale = null;
		return WC()->countries->get_country_locale();
	}

	/**
	 * Classic checkout keeps four billing fields for a license-only cart.
	 */
	public function test_classic_fields_for_license_only_cart(): void {
		WC()->cart->add_to_cart( $this->make_license_product()->get_id() );
		$fields = $this->checkout_fields();
		$this->assertEqualsCanonicalizing( SFLS_Checkout::KEPT_BILLING_FIELDS, array_keys( $fields['billing'] ) );
	}

	/**
	 * A cart with something to ship keeps the full address.
	 */
	public function test_mixed_cart_keeps_address(): void {
		WC()->cart->add_to_cart( $this->make_license_product()->get_id() );
		WC()->cart->add_to_cart( $this->make_physical_product()->get_id() );
		$fields = $this->checkout_fields();
		$this->assertArrayHasKey( 'billing_address_1', $fields['billing'] );
		$this->assertArrayHasKey( 'billing_city', $fields['billing'] );
		$locale = $this->locale();
		$this->assertEmpty( $locale['US']['address_1']['hidden'] ?? false );
	}

	/**
	 * The setting turns it off.
	 */
	public function test_setting_off(): void {
		$this->settings( array( 'simple_checkout' => 0 ) );
		WC()->cart->add_to_cart( $this->make_license_product()->get_id() );
		$fields = $this->checkout_fields();
		$this->assertArrayHasKey( 'billing_address_1', $fields['billing'] );
	}

	/**
	 * Checkout block: the locale hides the address and the visibility options
	 * hide company, apartment and phone.
	 */
	public function test_block_locale_hides_address(): void {
		WC()->cart->add_to_cart( $this->make_license_product()->get_id() );
		$locale = $this->locale();
		foreach ( array( 'US', 'GB', 'DE', 'default' ) as $country ) {
			foreach ( SFLS_Checkout::HIDDEN_LOCALE_FIELDS as $field ) {
				$this->assertTrue( $locale[ $country ][ $field ]['hidden'], "$country $field hidden" );
				$this->assertFalse( $locale[ $country ][ $field ]['required'], "$country $field optional" );
			}
		}
		$this->assertSame( 'hidden', get_option( 'woocommerce_checkout_phone_field' ) );
		$this->assertSame( 'hidden', get_option( 'woocommerce_checkout_company_field' ) );
		$this->assertSame( 'hidden', get_option( 'woocommerce_checkout_address_2_field' ) );
		WC()->countries->locale = null;
	}

	/**
	 * A real Store API checkout succeeds with only name, email and country.
	 */
	public function test_store_api_checkout_accepts_name_email_country(): void {
		$this->filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		WC()->countries->locale = null;
		WC()->cart->add_to_cart( $this->make_license_product( 'starter', 12, '49' )->get_id() );
		$email    = 'checkout' . wp_generate_password( 4, false ) . '@' . self::DOMAIN;
		$response = $this->rest(
			'POST',
			'/wc/store/v1/checkout',
			array(
				'billing_address' => array(
					'first_name' => 'Ada',
					'last_name'  => 'Lovelace',
					'email'      => $email,
					'country'    => 'GB',
				),
				'payment_method'  => 'cheque',
			)
		);
		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->orders[] = (int) $data['order_id'];
		$order          = wc_get_order( (int) $data['order_id'] );
		$this->assertSame( 'GB', $order->get_billing_country() );
		$this->assertSame( '', $order->get_billing_address_1() );
		WC()->countries->locale = null;
	}
}
