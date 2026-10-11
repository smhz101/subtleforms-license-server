<?php
/**
 * Checkout for license carts.
 *
 * - A "Change plan" link next to the license in the order summary, in the
 *   Checkout block (Store API cart item data plus a small ExperimentalOrderMeta
 *   fill) and in the classic checkout (woocommerce_cart_item_name).
 * - Licenses › Settings › "Simple checkout for license-only carts": when every
 *   item is a license, billing asks only for name, email and country (country
 *   is needed for tax). The Checkout block reads its address fields from the
 *   country locale and the field visibility options, so those are filtered;
 *   the classic checkout uses woocommerce_checkout_fields.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout tweaks.
 */
final class SFLS_Checkout {

	/**
	 * Address parts hidden for license-only carts.
	 */
	const HIDDEN_LOCALE_FIELDS = array( 'company', 'address_1', 'address_2', 'city', 'state', 'postcode' );

	/**
	 * Billing fields kept in the classic checkout.
	 */
	const KEPT_BILLING_FIELDS = array( 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_country' );

	/**
	 * Hook in when WooCommerce is loaded.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) && ! did_action( 'woocommerce_loaded' ) ) {
			add_action( 'woocommerce_loaded', array( $this, 'hooks' ) );
			return;
		}
		$this->hooks();
	}

	/**
	 * WooCommerce hooks.
	 */
	public function hooks(): void {
		// Change plan.
		add_filter( 'woocommerce_cart_item_name', array( $this, 'classic_change_plan' ), 20, 3 );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->store_api_data();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'store_api_data' ) );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'render_block_woocommerce/checkout', array( $this, 'print_notices' ), 5 );

		// Simple checkout fields.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'classic_fields' ), 20 );
		add_filter( 'woocommerce_get_country_locale', array( $this, 'locale' ), 99 );
		add_filter( 'woocommerce_get_country_locale_default', array( $this, 'locale_fields' ), 99 );
		add_filter( 'woocommerce_get_country_locale_base', array( $this, 'locale_fields' ), 99 );
		foreach ( array( 'company', 'address_2', 'phone' ) as $field ) {
			add_filter( 'option_woocommerce_checkout_' . $field . '_field', array( $this, 'field_visibility' ) );
			add_filter( 'default_option_woocommerce_checkout_' . $field . '_field', array( $this, 'field_visibility' ) );
		}
	}

	// ── Change plan ─────────────────────────────────────────────────────

	/**
	 * Where "Change plan" goes.
	 */
	public static function change_plan_url(): string {
		return SFLS_Settings::pricing_url();
	}

	/**
	 * Classic checkout: link under the license name.
	 *
	 * @param string $name      Item name HTML.
	 * @param array  $cart_item Cart item.
	 * @param string $key       Cart item key.
	 * @return string
	 */
	public function classic_change_plan( $name, $cart_item = array(), $key = '' ) {
		unset( $key );
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! empty( $cart_item['sfls_upgrade_license'] ) || ! empty( $cart_item['sfls_renew_license'] ) ) {
			return $name;
		}
		if ( ! SFLS_Woo::is_license_product( (int) ( $cart_item['product_id'] ?? 0 ) ) ) {
			return $name;
		}
		return $name . ' <a class="sfls-change-plan-link" href="' . esc_url( self::change_plan_url() ) . '">' . esc_html__( 'Change plan', 'subtleforms-license-server' ) . '</a>';
	}

	/**
	 * Tell the Checkout block which cart items are licenses.
	 */
	public function store_api_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => 'sfls',
				'data_callback'   => array( $this, 'cart_item_extension' ),
				'schema_callback' => array( $this, 'cart_item_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Store API data for one cart item.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function cart_item_extension( $cart_item ): array {
		$product_id = (int) ( $cart_item['product_id'] ?? 0 );
		$is_license = SFLS_Woo::is_license_product( $product_id );
		return array(
			'is_license' => $is_license,
			'plan'       => $is_license ? SFLS_Woo::plan_name( $product_id ) : '',
			'swappable'  => $is_license && empty( $cart_item['sfls_upgrade_license'] ) && empty( $cart_item['sfls_renew_license'] ),
		);
	}

	/**
	 * Store API schema for the cart item data.
	 */
	public function cart_item_schema(): array {
		return array(
			'is_license' => array(
				'description' => __( 'Whether the item is a SubtleForms license.', 'subtleforms-license-server' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'plan'       => array(
				'description' => __( 'Plan name.', 'subtleforms-license-server' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'swappable'  => array(
				'description' => __( 'Whether the customer can pick a different plan instead.', 'subtleforms-license-server' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Load the order summary script on the checkout page.
	 */
	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
			return;
		}
		wp_enqueue_style( 'sfls-checkout', SFLS_URL . 'assets/css/sfls-checkout.css', array(), SFLS_VERSION );
		if ( ! wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
			return; // Classic checkout: the item name filter covers it.
		}
		wp_enqueue_script( 'sfls-checkout', SFLS_URL . 'assets/js/sfls-checkout.js', array( 'wc-blocks-checkout', 'wp-plugins', 'wp-element', 'wp-data' ), SFLS_VERSION, true );
		wp_localize_script(
			'sfls-checkout',
			'sflsCheckout',
			array(
				'url'  => self::change_plan_url(),
				'link' => __( 'Change plan', 'subtleforms-license-server' ),
				/* translators: %s: plan name */
				'text' => __( '%s plan', 'subtleforms-license-server' ),
			)
		);
	}

	/**
	 * Show notices queued for the checkout (such as "We swapped your cart to
	 * Pro.") above the Checkout block. Block themes print them only when the
	 * template has a Store Notices block; when it has, they are already shown
	 * and gone from the session, so nothing is printed twice.
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	public function print_notices( $content ) {
		if ( ! function_exists( 'wc_print_notices' ) || ! function_exists( 'WC' ) || ! WC()->session || 0 === wc_notice_count() ) {
			return $content;
		}
		return '<div class="woocommerce-notices-wrapper sfls-checkout-notices">' . wc_print_notices( true ) . '</div>' . $content;
	}

	// ── Simple checkout ─────────────────────────────────────────────────

	/**
	 * Whether the trimmed billing fields apply to this request.
	 */
	public static function simplify_now(): bool {
		if ( ! SFLS_Settings::simple_checkout() || ! function_exists( 'WC' ) ) {
			return false;
		}
		$context = ( did_action( 'wp' ) && function_exists( 'is_checkout' ) && is_checkout() )
			|| ( method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request() )
			|| wp_doing_ajax();
		/**
		 * Whether this request renders or submits the checkout.
		 *
		 * @param bool $context Detected context.
		 */
		$context = (bool) apply_filters( 'sfls_checkout_context', $context );
		return (bool) apply_filters( 'sfls_simple_checkout', $context && SFLS_Woo::cart_is_license_only() );
	}

	/**
	 * Classic checkout: name, email and country only.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function classic_fields( $fields ) {
		if ( ! self::simplify_now() || empty( $fields['billing'] ) ) {
			return $fields;
		}
		$fields['billing'] = array_intersect_key( $fields['billing'], array_flip( self::KEPT_BILLING_FIELDS ) );
		return $fields;
	}

	/**
	 * Checkout block: hide street, city, state, postcode and company for every
	 * country.
	 *
	 * @param array $locale Country => fields.
	 * @return array
	 */
	public function locale( $locale ) {
		if ( ! is_array( $locale ) || ! self::simplify_now() ) {
			return $locale;
		}
		foreach ( $locale as $country => $fields ) {
			$locale[ $country ] = $this->hide( is_array( $fields ) ? $fields : array() );
		}
		return $locale;
	}

	/**
	 * Default and base locale fields.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public function locale_fields( $fields ) {
		if ( ! is_array( $fields ) || ! self::simplify_now() ) {
			return $fields;
		}
		return $this->hide( $fields );
	}

	/**
	 * Mark address parts hidden and optional.
	 *
	 * @param array $fields Fields.
	 */
	private function hide( array $fields ): array {
		foreach ( self::HIDDEN_LOCALE_FIELDS as $key ) {
			$fields[ $key ]             = isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) ? $fields[ $key ] : array();
			$fields[ $key ]['hidden']   = true;
			$fields[ $key ]['required'] = false;
		}
		return $fields;
	}

	/**
	 * Checkout block: company, apartment and phone hidden.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public function field_visibility( $value ) {
		return self::simplify_now() ? 'hidden' : $value;
	}
}
