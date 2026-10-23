<?php
/**
 * WooCommerce checkout with Paddle payments (Licenses › Settings › Paddle ›
 * "WooCommerce checkout with Paddle payments").
 *
 * The customer uses the normal WooCommerce cart and checkout. "Pay with
 * Paddle" (SFLS_Gateway_Paddle) is the payment method for license-only carts:
 * on Place order the order is created Pending, a Paddle transaction is created
 * from it through the API, and the Paddle payment form opens inline on the
 * checkout page, right under the payment method (the order's payment page
 * shows the same form for orders paid later). The signed Paddle webhook completes the
 * order (SFLS_Woo_Paddle_Orders), which issues the license once and links it
 * to the Paddle subscription, so renewals, trials, cancel, resume, the
 * customer portal and upgrades all run through Paddle Billing.
 *
 * This class covers the shop side:
 *   - gateway registration (classic and Checkout block), and Pay with Paddle
 *     as the only method for license-only carts;
 *   - free trials as $0 lines ("Free for 14 days, then $79/year") that still
 *     go through payment, so Paddle collects the card;
 *   - trial eligibility at checkout (classic and Store API);
 *   - no WooCommerce tax on license products, because Paddle is the merchant
 *     of record and adds sales tax or VAT;
 *   - building the Paddle transaction from an order;
 *   - the inline Paddle payment form on the checkout and order-pay pages.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce + Paddle checkout.
 */
final class SFLS_Woo_Paddle {

	const GATEWAY = 'sfls_paddle';

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
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'add_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_block_method' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'only_paddle' ), 20 );

		// Paddle adds the tax.
		add_filter( 'woocommerce_product_get_tax_status', array( $this, 'tax_status' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_tax_status', array( $this, 'tax_status' ), 20, 2 );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'classic_tax_row' ) );

		// Free trials.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'trial_prices' ), 30 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'trial_item_data' ), 15, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'trial_line_item' ), 15, 3 );
		add_filter( 'woocommerce_cart_needs_payment', array( $this, 'cart_needs_payment' ), 20 );
		add_filter( 'woocommerce_order_needs_payment', array( $this, 'order_needs_payment' ), 20, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'classic_validate' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'store_api_validate' ), 5 );

		// Payment page and abandoned orders.
		add_action( 'woocommerce_receipt_' . self::GATEWAY, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_open_transaction' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'release_trial_claims' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'release_trial_claims' ) );
		add_action( 'template_redirect', array( $this, 'handle_trial_code' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ), 6 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout' ), 20 );
	}

	/**
	 * Add the gateway class.
	 *
	 * @param array $gateways Gateway class names.
	 * @return array
	 */
	public static function add_gateway( $gateways ) {
		require_once SFLS_DIR . 'includes/class-sfls-gateway-paddle.php';
		$gateways   = (array) $gateways;
		$gateways[] = 'SFLS_Gateway_Paddle';
		return $gateways;
	}

	/**
	 * The gateway instance WooCommerce loaded.
	 */
	public static function gateway(): ?WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$all = WC()->payment_gateways()->payment_gateways();
		return $all[ self::GATEWAY ] ?? null;
	}

	/**
	 * Register the Checkout block integration.
	 *
	 * @param object $registry Payment method registry.
	 */
	public function register_block_method( $registry ): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once SFLS_DIR . 'includes/class-sfls-gateway-paddle-blocks.php';
		$registry->register( new SFLS_Gateway_Paddle_Blocks() );
	}

	/**
	 * Register the inline payment script.
	 */
	public static function register_assets(): void {
		SFLS_Checkout_Paddle::register_assets();
		if ( ! wp_script_is( 'sfls-paddle-inline', 'registered' ) ) {
			wp_register_script( 'sfls-paddle-inline', SFLS_URL . 'assets/js/sfls-paddle-inline.js', array( 'sfls-paddle-js' ), SFLS_VERSION, true );
		}
		if ( ! wp_script_is( 'sfls-order-status', 'registered' ) ) {
			wp_register_script( 'sfls-order-status', SFLS_URL . 'assets/js/sfls-order-status.js', array(), SFLS_VERSION, true );
		}
	}

	/**
	 * Settings for assets/js/sfls-paddle-inline.js.
	 *
	 * @param array $pay Order to pay right away on the order-pay page
	 *                   ({ transactionId, successUrl, trial }), or empty.
	 */
	public static function inline_config( array $pay = array() ): array {
		$config = SFLS_Paddle::config();
		return array(
			'token'       => (string) $config['client_token'],
			'environment' => (string) $config['env'],
			'locale'      => substr( determine_locale(), 0, 2 ),
			'pay'         => $pay,
			'i18n'        => array(
				'lead'      => __( 'Pay securely with Paddle by card, PayPal, Apple Pay or Google Pay.', 'subtleforms-license-server' ),
				'leadTrial' => __( 'Add a card for your free trial. Nothing is charged today.', 'subtleforms-license-server' ),
				'loading'   => __( 'Loading the secure payment form…', 'subtleforms-license-server' ),
				'change'    => __( 'Change details', 'subtleforms-license-server' ),
				'cancelled' => __( 'Payment not finished, so nothing was charged. Check your details and place the order again.', 'subtleforms-license-server' ),
				'error'     => __( 'The payment form ran into a problem. Please try again.', 'subtleforms-license-server' ),
				'noPaddle'  => __( 'The Paddle payment form could not load. Check your connection or turn off content blockers, then try again.', 'subtleforms-license-server' ),
				'payPage'   => __( 'Pay on the order page', 'subtleforms-license-server' ),
				'done'      => __( 'Payment received. Taking you to your license…', 'subtleforms-license-server' ),
			),
		);
	}

	/**
	 * Load the inline payment form script on the checkout page (classic and
	 * block). Paddle.js on this page also opens Paddle's emailed payment
	 * links (?_ptxn=…), since the default payment link points here.
	 */
	public function enqueue_checkout(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || is_checkout_pay_page() ) {
			return;
		}
		if ( ! SFLS_Paddle::woo_selling() ) {
			return;
		}
		self::register_assets();
		wp_enqueue_script( 'sfls-paddle-inline' );
		wp_localize_script( 'sfls-paddle-inline', 'sflsPaddleInline', self::inline_config() );
	}

	/**
	 * "Sales tax or VAT is calculated by Paddle at payment."
	 */
	public static function tax_note(): string {
		return __( 'Sales tax or VAT is calculated by Paddle at payment.', 'subtleforms-license-server' );
	}

	// ── What Paddle can charge ──────────────────────────────────────────

	/**
	 * How a license line is paid in Paddle.
	 *
	 *   subscription  new yearly license: the plan's catalog price
	 *   trial         new yearly license with a free trial: the trial price
	 *   renewal       renewal of a license not billed by Paddle: a yearly price
	 *                 at the line price, so it renews automatically from now on
	 *   upgrade       upgrade of a Paddle license: the subscription is updated
	 *   one_time      lifetime licenses and upgrades of licenses not billed by
	 *                 Paddle: a one-time charge
	 *
	 * @param int  $product_id Product.
	 * @param int  $upgrade    License being upgraded (0 = none).
	 * @param int  $renew      License being renewed (0 = none).
	 * @param bool $trial      Free trial line.
	 * @return array{kind:string,plan:string,license:?array}|WP_Error
	 */
	public static function line_kind( int $product_id, int $upgrade = 0, int $renew = 0, bool $trial = false ) {
		$plan = (string) get_post_meta( $product_id, '_sfls_plan', true );
		$plan = '' !== $plan ? $plan : 'pro';
		$type = SFLS_Products::type_of( $product_id );
		$map  = SFLS_Paddle::plan( $plan );
		$fail = new WP_Error( 'sfls_paddle_unmapped', __( 'This plan cannot be paid with Paddle yet.', 'subtleforms-license-server' ) );

		if ( $upgrade ) {
			$lic = SFLS_Licenses::find( $upgrade );
			if ( ! $lic ) {
				return $fail;
			}
			if ( SFLS_Licenses::is_paddle( $lic ) ) {
				return '' !== $map['price_id'] && in_array( (string) $lic['billing_status'], array( 'active', 'trialing' ), true )
					? array(
						'kind'    => 'upgrade',
						'plan'    => $plan,
						'license' => $lic,
					)
					: $fail;
			}
			return '' !== $map['product_id'] ? array(
				'kind'    => 'one_time',
				'plan'    => $plan,
				'license' => $lic,
			) : $fail;
		}
		if ( 'lifetime' === $type ) {
			return '' !== $map['product_id'] ? array(
				'kind'    => 'one_time',
				'plan'    => $plan,
				'license' => null,
			) : $fail;
		}
		if ( $renew ) {
			$lic = SFLS_Licenses::find( $renew );
			if ( ! $lic || SFLS_Licenses::is_paddle( $lic ) ) {
				return $fail; // Paddle renews its own subscriptions.
			}
			return '' !== $map['product_id'] ? array(
				'kind'    => 'renewal',
				'plan'    => $plan,
				'license' => $lic,
			) : $fail;
		}
		if ( 'renewal' === $type ) {
			return '' !== $map['product_id'] ? array(
				'kind'    => 'renewal',
				'plan'    => $plan,
				'license' => null,
			) : $fail;
		}
		if ( $trial ) {
			return SFLS_Paddle::plan_has_trial( $plan ) ? array(
				'kind'    => 'trial',
				'plan'    => $plan,
				'license' => null,
			) : $fail;
		}
		return '' !== $map['price_id'] ? array(
			'kind'    => 'subscription',
			'plan'    => $plan,
			'license' => null,
		) : $fail;
	}

	/**
	 * The one license line of a cart (cart key => item), or null.
	 */
	public static function cart_license_item(): ?array {
		if ( ! SFLS_Woo::cart_is_license_only() ) {
			return null;
		}
		$items = WC()->cart->get_cart();
		if ( 1 !== count( $items ) ) {
			return null; // One plan per cart: anything else is not a license checkout.
		}
		return reset( $items );
	}

	/**
	 * Whether the current cart can be paid with Paddle.
	 */
	public static function cart_payable(): bool {
		$item = self::cart_license_item();
		if ( ! $item || 1 !== (int) ( $item['quantity'] ?? 1 ) ) {
			return false;
		}
		return ! is_wp_error( self::line_kind( (int) $item['product_id'], (int) ( $item['sfls_upgrade_license'] ?? 0 ), (int) ( $item['sfls_renew_license'] ?? 0 ), ! empty( $item['sfls_trial'] ) ) );
	}

	/**
	 * The license line of an order and how Paddle charges it.
	 *
	 * @param WC_Order $order Order.
	 * @return array{item:WC_Order_Item_Product,kind:string,plan:string,license:?array}|WP_Error
	 */
	public static function order_line( WC_Order $order ) {
		$items = $order->get_items();
		if ( 1 !== count( $items ) || ! SFLS_Woo::order_is_license_only( $order ) ) {
			return new WP_Error( 'sfls_paddle_cart', __( 'Pay with Paddle is for one SubtleForms license at a time.', 'subtleforms-license-server' ) );
		}
		$item = reset( $items );
		if ( ! $item instanceof WC_Order_Item_Product || 1 !== (int) $item->get_quantity() ) {
			return new WP_Error( 'sfls_paddle_cart', __( 'Pay with Paddle is for one SubtleForms license at a time.', 'subtleforms-license-server' ) );
		}
		$kind = self::line_kind( (int) $item->get_product_id(), (int) $item->get_meta( '_sfls_upgrade_license' ), (int) $item->get_meta( '_sfls_renew_license' ), '' !== (string) $item->get_meta( '_sfls_trial' ) );
		return is_wp_error( $kind ) ? $kind : array( 'item' => $item ) + $kind;
	}

	/**
	 * The order on the current order-pay page, if any.
	 */
	private static function pay_page_order(): ?WC_Order {
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return null;
		}
		$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Whether what the customer is paying for right now (the cart, or the
	 * order on the pay page) can be paid with Paddle.
	 */
	public static function context_payable(): bool {
		$order = self::pay_page_order();
		if ( $order ) {
			return ! is_wp_error( self::order_line( $order ) );
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		return self::cart_payable();
	}

	/**
	 * Pay with Paddle is the only method for license-only carts (Paddle is the
	 * merchant of record and takes the tax). Filter `sfls_paddle_only_gateway`
	 * to offer the other gateways next to it.
	 *
	 * @param array $gateways Available gateways.
	 * @return array
	 */
	public function only_paddle( $gateways ) {
		if ( ! is_array( $gateways ) || ! isset( $gateways[ self::GATEWAY ] ) || ! SFLS_Paddle::woo_selling() ) {
			return $gateways;
		}
		if ( ! apply_filters( 'sfls_paddle_only_gateway', true ) ) {
			return $gateways;
		}
		return array( self::GATEWAY => $gateways[ self::GATEWAY ] );
	}

	// ── Tax ─────────────────────────────────────────────────────────────

	/**
	 * License products carry no WooCommerce tax while Paddle takes the
	 * payments: Paddle calculates and collects sales tax or VAT itself.
	 *
	 * @param string     $status  Tax status.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function tax_status( $status, $product = null ) {
		if ( $product instanceof WC_Product && SFLS_Woo::is_license_product( $product ) && SFLS_Paddle::woo_selling() ) {
			return 'none';
		}
		return $status;
	}

	/**
	 * Classic checkout: a tax line above the total.
	 */
	public function classic_tax_row(): void {
		if ( ! SFLS_Paddle::woo_selling() || ! self::cart_payable() ) {
			return;
		}
		echo '<tr class="sfls-paddle-tax-row"><th>' . esc_html__( 'Tax', 'subtleforms-license-server' ) . '</th><td>' . esc_html( self::tax_note() ) . '</td></tr>';
	}

	// ── Free trials ─────────────────────────────────────────────────────

	/**
	 * Whether a product can be bought with a free trial right now.
	 *
	 * @param int $product_id Product.
	 */
	public static function trial_allowed( int $product_id ): bool {
		if ( ! SFLS_Paddle::woo_selling() || ! SFLS_Woo::is_license_product( $product_id ) || 'yearly' !== SFLS_Products::type_of( $product_id ) ) {
			return false;
		}
		$plan = (string) get_post_meta( $product_id, '_sfls_plan', true );
		return SFLS_Paddle::plan_has_trial( '' !== $plan ? $plan : 'pro' );
	}

	/**
	 * "Free for 14 days, then $79.00/year".
	 *
	 * @param int $product_id Product.
	 */
	public static function trial_label( int $product_id ): string {
		$plan    = (string) get_post_meta( $product_id, '_sfls_plan', true );
		$plan    = '' !== $plan ? $plan : 'pro';
		$map     = SFLS_Paddle::plan( $plan );
		$price   = '' !== $map['amount'] && '' !== $map['currency'] ? SFLS_Paddle::format_amount( $map['amount'], $map['currency'] ) : '';
		$product = wc_get_product( $product_id );
		if ( '' === $price && $product ) {
			$price = SFLS_Upgrades::plain_price( (float) $product->get_regular_price() );
		}
		/* translators: 1: number of days, 2: yearly price */
		return sprintf( __( 'Free for %1$d days, then %2$s/year', 'subtleforms-license-server' ), (int) $map['trial_days'], $price );
	}

	/**
	 * Link that swaps a trial in the cart for a normal purchase.
	 *
	 * @param int $product_id Product.
	 */
	public static function buy_without_trial_url( int $product_id ): string {
		return add_query_arg( 'sfls_trial', '0', SFLS_Products::buy_url( $product_id ) );
	}

	/**
	 * Trial lines cost nothing today.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public function trial_prices( $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['sfls_trial'] ) && isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
				if ( self::trial_allowed( (int) $item['product_id'] ) ) {
					$item['data']->set_price( 0 );
				}
			}
		}
	}

	/**
	 * "Free trial: Free for 14 days, then $79.00/year" in cart and checkout.
	 *
	 * @param array $item_data Display rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function trial_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['sfls_trial'] ) && self::trial_allowed( (int) ( $cart_item['product_id'] ?? 0 ) ) ) {
			$item_data   = (array) $item_data;
			$item_data[] = array(
				'key'   => __( 'Free trial', 'subtleforms-license-server' ),
				'value' => self::trial_label( (int) $cart_item['product_id'] ),
			);
		}
		return $item_data;
	}

	/**
	 * Keep the trial on the order line.
	 *
	 * @param WC_Order_Item_Product $item     Line item.
	 * @param string                $cart_key Cart key.
	 * @param array                 $values   Cart item.
	 */
	public function trial_line_item( $item, $cart_key, $values ): void {
		if ( empty( $values['sfls_trial'] ) || ! self::trial_allowed( (int) ( $values['product_id'] ?? 0 ) ) ) {
			return;
		}
		$plan = (string) get_post_meta( (int) $values['product_id'], '_sfls_plan', true );
		$item->add_meta_data( '_sfls_trial', (string) SFLS_Paddle::plan( '' !== $plan ? $plan : 'pro' )['trial_days'], true );
		$item->add_meta_data( __( 'Free trial', 'subtleforms-license-server' ), self::trial_label( (int) $values['product_id'] ), true );
	}

	/**
	 * Whether a cart item must go through Paddle even at $0: a free trial
	 * (Paddle collects the card) or an upgrade of a Paddle subscription.
	 *
	 * @param array $item Cart item.
	 */
	private static function cart_item_needs_paddle( array $item ): bool {
		if ( ! empty( $item['sfls_trial'] ) ) {
			return true;
		}
		$lic = ! empty( $item['sfls_upgrade_license'] ) ? SFLS_Licenses::find( (int) $item['sfls_upgrade_license'] ) : null;
		return $lic && SFLS_Licenses::is_paddle( $lic );
	}

	/**
	 * Trial and Paddle upgrade carts need payment even when they total $0.
	 *
	 * @param bool $needs Needs payment.
	 * @return bool
	 */
	public function cart_needs_payment( $needs ) {
		if ( $needs || ! SFLS_Paddle::woo_selling() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $needs;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::cart_item_needs_paddle( $item ) ) {
				return true;
			}
		}
		return $needs;
	}

	/**
	 * Whether an order has a line that goes through Paddle at $0.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_needs_paddle( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( '' !== (string) $item->get_meta( '_sfls_trial' ) ) {
				return true;
			}
			$lic = (int) $item->get_meta( '_sfls_upgrade_license' ) ? SFLS_Licenses::find( (int) $item->get_meta( '_sfls_upgrade_license' ) ) : null;
			if ( $lic && SFLS_Licenses::is_paddle( $lic ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Same for orders (order-pay page, Store API checkout).
	 *
	 * @param bool     $needs    Needs payment.
	 * @param WC_Order $order    Order.
	 * @param array    $statuses Statuses that can be paid.
	 * @return bool
	 */
	public function order_needs_payment( $needs, $order = null, $statuses = array() ) {
		if ( $needs || ! $order instanceof WC_Order || ! SFLS_Paddle::woo_selling() ) {
			return $needs;
		}
		if ( ! $order->has_status( (array) $statuses ) || $order->is_paid() || '' !== (string) $order->get_meta( '_sfls_paddle_paid_txn' ) ) {
			return $needs;
		}
		return self::order_needs_paddle( $order );
	}

	/**
	 * Trial problem for a cart and email, as notice HTML ('' when fine).
	 *
	 * @param string $email          Billing email.
	 * @param string $payment_method Chosen payment method.
	 * @param int    $order_id       The order being placed (0 when not known): its own pending claims do not count.
	 */
	public static function trial_problem( string $email, string $payment_method, int $order_id = 0 ): string {
		$item = null;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $row ) {
				if ( ! empty( $row['sfls_trial'] ) ) {
					$item = $row;
					break;
				}
			}
		}
		if ( ! $item ) {
			return '';
		}
		$product_id = (int) $item['product_id'];
		$link       = '<a href="' . esc_url( self::buy_without_trial_url( $product_id ) ) . '">' . esc_html__( 'Buy without the free trial', 'subtleforms-license-server' ) . '</a>';
		if ( self::GATEWAY !== $payment_method ) {
			return esc_html__( 'Free trials are paid with Paddle, which keeps your card on file for when the trial ends. Choose Pay with Paddle.', 'subtleforms-license-server' );
		}
		if ( $order_id <= 0 && function_exists( 'WC' ) && WC()->session ) {
			$order_id = (int) WC()->session->get( 'order_awaiting_payment' ); // The unpaid order this checkout retries.
		}
		$check = SFLS_Trials::check( $email, '', 0, $order_id );
		if ( $check['eligible'] ) {
			return '';
		}
		return esc_html( trim( $check['message'] . ' ' . __( 'You can buy the plan right away instead.', 'subtleforms-license-server' ) ) ) . ' ' . $link;
	}

	/**
	 * Classic checkout: one free trial per person, company and site.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public function classic_validate( $data, $errors ): void {
		if ( ! SFLS_Paddle::woo_selling() || ! $errors instanceof WP_Error ) {
			return;
		}
		$problem = self::trial_problem( (string) ( $data['billing_email'] ?? '' ), (string) ( $data['payment_method'] ?? '' ) );
		if ( '' !== $problem ) {
			$errors->add( 'sfls_trial', $problem );
		}
	}

	/**
	 * Checkout block (Store API): the same check, right before payment.
	 *
	 * @param WC_Order $order Order.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the trial is not allowed.
	 */
	public function store_api_validate( $order ): void {
		if ( ! SFLS_Paddle::woo_selling() || ! $order instanceof WC_Order || ! class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			return;
		}
		$problem = self::trial_problem( (string) $order->get_billing_email(), (string) $order->get_payment_method(), $order->get_id() );
		if ( '' !== $problem ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'sfls_trial_not_eligible', wp_kses( $problem, array( 'a' => array( 'href' => true ) ) ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped and filtered above.
		}
	}

	// ── Paddle transaction ──────────────────────────────────────────────

	/**
	 * Paddle customer and address for an order (found or created).
	 *
	 * @param WC_Order $order Order.
	 * @return array{customer_id:string,address_id:string}|WP_Error
	 */
	public static function customer_for_order( WC_Order $order ) {
		$email = (string) $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'sfls_paddle_email', __( 'Enter a valid email address.', 'subtleforms-license-server' ) );
		}
		$user_id  = (int) $order->get_customer_id();
		$customer = $user_id ? (string) get_user_meta( $user_id, '_sfls_paddle_customer_id', true ) : '';
		if ( '' === $customer || 0 !== strcasecmp( (string) get_user_meta( $user_id, '_sfls_paddle_customer_email', true ), $email ) ) {
			$customer = SFLS_Paddle::customer_for( $email, trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
			if ( is_wp_error( $customer ) ) {
				return $customer;
			}
			if ( $user_id ) {
				update_user_meta( $user_id, '_sfls_paddle_customer_id', $customer );
				update_user_meta( $user_id, '_sfls_paddle_customer_email', $email );
			}
		}
		$address = '';
		$country = (string) $order->get_billing_country();
		if ( '' !== $country ) {
			$made = SFLS_Paddle::create_address( $customer, $country, (string) $order->get_billing_postcode() );
			// Without an address the Paddle checkout simply asks for the country and postcode.
			$address = is_wp_error( $made ) ? '' : $made;
		}
		return array(
			'customer_id' => $customer,
			'address_id'  => $address,
		);
	}

	/**
	 * The Paddle discount for the order's WooCommerce coupons, first payment
	 * only. A coupon with the `_sfls_paddle_discount_id` meta uses that
	 * (non-recurring) Paddle discount; otherwise a one-time flat discount of
	 * the same amount is sent with the transaction.
	 *
	 * @param WC_Order $order Order.
	 * @return array Body fields (discount_id or discount), empty without a discount.
	 */
	public static function discount_for_order( WC_Order $order ): array {
		$amount = (float) $order->get_discount_total();
		if ( $amount <= 0 ) {
			return array();
		}
		$codes = $order->get_coupon_codes();
		if ( 1 === count( $codes ) ) {
			$coupon = new WC_Coupon( (string) reset( $codes ) );
			$mapped = (string) $coupon->get_meta( '_sfls_paddle_discount_id' );
			if ( preg_match( '/^dsc_[a-z0-9]+$/', $mapped ) ) {
				return array( 'discount_id' => $mapped );
			}
		}
		return array(
			'discount' => array(
				/* translators: 1: coupon codes, 2: order number */
				'description' => sprintf( __( 'WooCommerce coupon %1$s on order #%2$s', 'subtleforms-license-server' ), implode( ', ', $codes ), $order->get_order_number() ),
				'type'        => 'flat',
				'amount'      => SFLS_Paddle::to_minor( (string) $amount, $order->get_currency() ),
				'currency_code' => strtoupper( $order->get_currency() ),
				'recur'       => false,
			),
		);
	}

	/**
	 * The transaction request for an order.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $line     order_line() result.
	 * @param array    $customer customer_for_order() result.
	 * @return array
	 */
	public static function transaction_body( WC_Order $order, array $line, array $customer ): array {
		$plan     = $line['plan'];
		$map      = SFLS_Paddle::plan( $plan );
		$currency = strtoupper( $order->get_currency() );
		/* translators: %s: plan name */
		$name     = sprintf( __( 'SubtleForms Pro %s', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) );
		$subtotal = SFLS_Paddle::to_minor( (string) $line['item']->get_subtotal(), $currency );
		$license  = $line['license'] ? (string) $line['license']['id'] : '';

		switch ( $line['kind'] ) {
			case 'trial':
				$item = array( 'price_id' => $map['trial_price_id'] );
				break;
			case 'renewal':
				$item = array(
					'price' => array(
						/* translators: %s: product name */
						'description'   => sprintf( __( '%s, yearly renewal', 'subtleforms-license-server' ), $name ),
						'name'          => $name,
						'product_id'    => $map['product_id'],
						'unit_price'    => array(
							'amount'        => $subtotal,
							'currency_code' => $currency,
						),
						'billing_cycle' => array(
							'interval'  => 'year',
							'frequency' => 1,
						),
						'quantity'      => array(
							'minimum' => 1,
							'maximum' => 1,
						),
						'custom_data'   => array( 'source' => 'subtleforms-license-server' ),
					),
				);
				break;
			case 'one_time':
				$item = array(
					'price' => array(
						'description' => $line['license']
							/* translators: %s: product name */
							? sprintf( __( '%s, prorated upgrade', 'subtleforms-license-server' ), $name )
							/* translators: %s: product name */
							: sprintf( __( '%s, one-time payment', 'subtleforms-license-server' ), $name ),
						'name'        => (string) $line['item']->get_name(),
						'product_id'  => $map['product_id'],
						'unit_price'  => array(
							'amount'        => $subtotal,
							'currency_code' => $currency,
						),
						'quantity'    => array(
							'minimum' => 1,
							'maximum' => 1,
						),
						'custom_data' => array( 'source' => 'subtleforms-license-server' ),
					),
				);
				break;
			default:
				$item = array( 'price_id' => $map['price_id'] );
		}
		$item['quantity'] = 1;

		$body = array(
			'items'           => array( $item ),
			'customer_id'     => $customer['customer_id'],
			'currency_code'   => $currency,
			'collection_mode' => 'automatic',
			'custom_data'     => array(
				'wc_order_id'      => (string) $order->get_id(),
				'order_key'        => $order->get_order_key(),
				'sfls_plan'        => $plan,
				'sfls_kind'        => $line['kind'],
				'sfls_license_ids' => $license,
				'source'           => 'subtleforms-license-server',
			),
		);
		if ( '' !== $customer['address_id'] ) {
			$body['address_id'] = $customer['address_id'];
		}
		if ( 'trial' !== $line['kind'] ) {
			$body += self::discount_for_order( $order );
		}
		/**
		 * Filter the Paddle transaction created for a WooCommerce order.
		 *
		 * @param array    $body  Request body.
		 * @param WC_Order $order Order.
		 * @param array    $line  { item, kind, plan, license }.
		 */
		return (array) apply_filters( 'sfls_paddle_transaction_body', $body, $order, $line );
	}

	/**
	 * Create the Paddle transaction for an order and check that Paddle
	 * charges what the order says (before tax).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $line  order_line() result.
	 * @return array|WP_Error Transaction.
	 */
	public static function create_transaction( WC_Order $order, array $line ) {
		$customer = self::customer_for_order( $order );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$txn = SFLS_Paddle::create_transaction( self::transaction_body( $order, $line, $customer ) );
		if ( is_wp_error( $txn ) ) {
			return $txn;
		}
		$id       = (string) ( $txn['id'] ?? '' );
		$totals   = (array) ( $txn['details']['totals'] ?? array() );
		$currency = strtoupper( (string) ( $totals['currency_code'] ?? $txn['currency_code'] ?? '' ) );
		$expected = (int) SFLS_Paddle::to_minor( (string) $order->get_total(), $order->get_currency() );
		$net      = SFLS_Paddle::net_of( $totals );
		$total    = (int) ( $totals['total'] ?? 0 );
		if ( '' === $id ) {
			return new WP_Error( 'sfls_paddle_txn', __( 'Paddle did not return a transaction.', 'subtleforms-license-server' ) );
		}
		if ( $currency !== strtoupper( $order->get_currency() ) || ( abs( $net - $expected ) > 1 && abs( $total - $expected ) > 1 ) ) {
			SFLS_Paddle::cancel_transaction( $id );
			$order->add_order_note(
				sprintf(
					/* translators: 1: transaction ID, 2: Paddle amount, 3: order amount */
					__( 'Paddle transaction %1$s was canceled: Paddle would charge %2$s before tax, but the order total is %3$s. Check that the WooCommerce price and the Paddle price of this plan match.', 'subtleforms-license-server' ),
					$id,
					SFLS_Paddle::format_amount( (string) $net, '' !== $currency ? $currency : $order->get_currency() ),
					SFLS_Paddle::format_amount( (string) $expected, $order->get_currency() )
				)
			);
			return new WP_Error( 'sfls_paddle_amount', __( 'This plan cannot be paid right now because its price is being updated. Please contact us and we will sort it out.', 'subtleforms-license-server' ) );
		}
		return $txn;
	}

	/**
	 * Place order with Pay with Paddle: create the transaction (or update the
	 * subscription for an upgrade) and say where the customer goes next.
	 *
	 * @param WC_Order $order Order.
	 * @return array{result:string,redirect:string}|WP_Error
	 */
	public static function start_payment( WC_Order $order ) {
		$line = self::order_line( $order );
		if ( is_wp_error( $line ) ) {
			return $line;
		}
		if ( 'upgrade' === $line['kind'] ) {
			return SFLS_Woo_Paddle_Orders::pay_upgrade( $order, $line );
		}
		if ( 'trial' === $line['kind'] ) {
			// Claim the trial now, before any payment, so parallel checkouts with the same email cannot both get one.
			$check = SFLS_Trials::reserve( (string) $order->get_billing_email(), $order->get_id() );
			if ( ! $check['eligible'] ) {
				return new WP_Error( 'sfls_trial', $check['message'] );
			}
			if ( ! self::trial_email_confirmed( $order ) ) {
				// Email a code and send the customer to the payment page, which asks for it before the Paddle form.
				SFLS_Trials::issue_code( (string) $order->get_billing_email() );
				$order->add_order_note( __( 'Free trial: a confirmation code was emailed to the customer. The Paddle checkout opens once it is entered.', 'subtleforms-license-server' ) );
				return array(
					'result'   => 'success',
					'redirect' => $order->get_checkout_payment_url( true ),
				);
			}
		}
		return self::open_transaction( $order, $line );
	}

	/**
	 * Whether the email of a trial order does not need a code (setting off)
	 * or was confirmed with one.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function trial_email_confirmed( WC_Order $order ): bool {
		return ! SFLS_Settings::trial_verify() || SFLS_Trials::is_verified( (string) $order->get_billing_email() );
	}

	/**
	 * Create the Paddle transaction of an order and say where the customer
	 * goes next.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $line  order_line() result.
	 * @return array{result:string,redirect:string}|WP_Error
	 */
	public static function open_transaction( WC_Order $order, array $line ) {
		$previous = (string) $order->get_meta( '_sfls_paddle_txn_id' );
		$txn      = self::create_transaction( $order, $line );
		if ( is_wp_error( $txn ) ) {
			if ( 'trial' === $line['kind'] ) {
				SFLS_Trials::release_order( $order->get_id() ); // The payment did not start: the trial is free again.
			}
			return $txn;
		}
		if ( '' !== $previous && $previous !== $txn['id'] ) {
			SFLS_Paddle::cancel_transaction( $previous ); // Only the newest transaction can be paid.
		}
		$order->update_meta_data( '_sfls_paddle_txn_id', (string) $txn['id'] );
		$order->update_meta_data( '_sfls_paddle_kind', $line['kind'] );
		$order->update_meta_data( '_sfls_paddle_customer_id', (string) ( $txn['customer_id'] ?? '' ) );
		$order->update_meta_data( '_sfls_paddle_env', SFLS_Paddle::config()['env'] );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: transaction ID, 2: environment */
				__( 'Paddle transaction %1$s created (%2$s). Waiting for the customer to pay in the Paddle checkout.', 'subtleforms-license-server' ),
				(string) $txn['id'],
				'live' === SFLS_Paddle::config()['env'] ? __( 'live', 'subtleforms-license-server' ) : __( 'sandbox', 'subtleforms-license-server' )
			)
		);
		$pay_url = $order->get_checkout_payment_url( true );
		return array(
			'result'              => 'success',
			// Classic checkout (AJAX) stays on the page and shows the inline form; the
			// Checkout block does the same in onCheckoutSuccess. The payment page is
			// the fallback when the inline form cannot run.
			'redirect'            => wp_doing_ajax() ? '#sfls-paddle' : $pay_url,
			'sfls_paddle_txn'     => (string) $txn['id'],
			'sfls_paddle_success' => $order->get_checkout_order_received_url(),
			'sfls_paddle_pay_url' => $pay_url,
			'sfls_paddle_trial'   => 'trial' === $line['kind'] ? 'yes' : 'no',
		);
	}

	// ── Payment page ────────────────────────────────────────────────────

	/**
	 * The payment page (order-pay receipt): the Paddle payment form, inline,
	 * for the order's transaction. Used for orders paid later (My Account ›
	 * Orders › Pay) and as the fallback when the checkout cannot show the form.
	 *
	 * @param int $order_id Order.
	 */
	public function receipt_page( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		SFLS_Assets::enqueue();
		$txn = (string) $order->get_meta( '_sfls_paddle_txn_id' );
		if ( $order->is_paid() || '' !== (string) $order->get_meta( '_sfls_paddle_paid_txn' ) ) {
			echo '<div class="sfls sfls-paddle-pay"><p>' . esc_html__( 'This order is paid.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( $order->get_checkout_order_received_url() ) . '">' . esc_html__( 'See your license', 'subtleforms-license-server' ) . '</a></p></div>';
			return;
		}
		$line  = self::order_line( $order );
		$trial = ! is_wp_error( $line ) && 'trial' === $line['kind'];
		if ( $trial && ! self::trial_email_confirmed( $order ) ) {
			self::trial_code_form( $order );
			return;
		}
		if ( '' === $txn && $trial && $order->has_status( 'pending' ) ) {
			// The code was entered here: now the Paddle transaction can be created.
			$started = self::open_transaction( $order, $line );
			if ( is_wp_error( $started ) ) {
				echo '<div class="sfls sfls-paddle-pay"><p class="sfls-paddle-error" role="alert">' . esc_html( $started->get_error_message() ) . '</p></div>';
				return;
			}
			$order = wc_get_order( $order->get_id() );
			$txn   = (string) $order->get_meta( '_sfls_paddle_txn_id' );
		}
		if ( '' === $txn ) {
			return;
		}

		self::register_assets();
		wp_enqueue_script( 'sfls-paddle-inline' );
		wp_localize_script(
			'sfls-paddle-inline',
			'sflsPaddleInline',
			self::inline_config(
				array(
					'transactionId' => $txn,
					'successUrl'    => $order->get_checkout_order_received_url(),
					'trial'         => $trial ? 'yes' : 'no',
				)
			)
		);

		echo '<div class="sfls sfls-paddle-pay">';
		echo '<h2 class="sfls-paddle-pay-title">' . esc_html__( 'Complete your payment', 'subtleforms-license-server' ) . '</h2>';
		echo '<div class="sfls-paddle-inline" data-sfls-paddle-pay-inline></div>';
		echo '<p class="sfls-muted sfls-paddle-pay-tax">' . esc_html( self::tax_note() ) . '</p>';
		echo '<p><a href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Back to checkout', 'subtleforms-license-server' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * The form that asks for the emailed code before a trial's Paddle form.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function trial_code_form( WC_Order $order ): void {
		$notice = (string) $order->get_meta( '_sfls_trial_code_notice' );
		if ( '' !== $notice ) {
			$order->delete_meta_data( '_sfls_trial_code_notice' );
			$order->save_meta_data();
		}
		$email = (string) $order->get_billing_email();
		echo '<div class="sfls sfls-paddle-pay sfls-trial-code">';
		echo '<h2 class="sfls-paddle-pay-title">' . esc_html__( 'Confirm your email', 'subtleforms-license-server' ) . '</h2>';
		/* translators: %s: email address */
		echo '<p>' . esc_html( sprintf( __( 'We emailed a 6 digit code to %s. Enter it to start your free trial.', 'subtleforms-license-server' ), $email ) ) . '</p>';
		if ( '' !== $notice ) {
			echo '<p class="sfls-paddle-error" role="alert">' . esc_html( $notice ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( $order->get_checkout_payment_url( true ) ) . '">';
		wp_nonce_field( 'sfls_trial_code_' . $order->get_id(), 'sfls_trial_nonce' );
		echo '<input type="hidden" name="sfls_trial_order" value="' . esc_attr( (string) $order->get_id() ) . '">';
		echo '<p><label for="sfls-trial-code">' . esc_html__( 'Confirmation code', 'subtleforms-license-server' ) . '</label><br><input id="sfls-trial-code" type="text" name="sfls_trial_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required class="input-text"></p>';
		echo '<p><button type="submit" name="sfls_trial_action" value="verify" class="button alt">' . esc_html__( 'Confirm and continue', 'subtleforms-license-server' ) . '</button> ';
		echo '<button type="submit" name="sfls_trial_action" value="resend" class="button" formnovalidate>' . esc_html__( 'Email me a new code', 'subtleforms-license-server' ) . '</button></p>';
		/* translators: %d: minutes */
		echo '<p class="sfls-muted">' . esc_html( sprintf( __( 'The code works for %d minutes.', 'subtleforms-license-server' ), SFLS_Trials::CODE_MINUTES ) ) . '</p>';
		echo '</form></div>';
	}

	/**
	 * Handle the code form of the payment page: confirm the code, or send a
	 * new one, then show the page again.
	 */
	public function handle_trial_code(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified below with the order's own nonce.
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || empty( $_POST['sfls_trial_order'] ) || empty( $_POST['sfls_trial_action'] ) ) {
			return;
		}
		$order_id = absint( $_POST['sfls_trial_order'] );
		$nonce    = isset( $_POST['sfls_trial_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sfls_trial_nonce'] ) ) : '';
		$order    = wc_get_order( $order_id );
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order instanceof WC_Order || ! wp_verify_nonce( $nonce, 'sfls_trial_code_' . $order_id ) || ! hash_equals( $order->get_order_key(), $key ) || self::GATEWAY !== $order->get_payment_method() ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['sfls_trial_action'] ) );
		$email  = (string) $order->get_billing_email();
		$result = true;
		if ( 'resend' === $action ) {
			$result = SFLS_Trials::issue_code( $email );
			if ( true === $result ) {
				$result = new WP_Error( 'code_sent', __( 'A new code is on its way.', 'subtleforms-license-server' ) );
			}
		} elseif ( 'verify' === $action ) {
			$result = SFLS_Trials::verify_code( $email, isset( $_POST['sfls_trial_code'] ) ? sanitize_text_field( wp_unslash( $_POST['sfls_trial_code'] ) ) : '' );
		}
		// phpcs:enable
		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( '_sfls_trial_code_notice', $result->get_error_message() );
			$order->save_meta_data();
		}
		wp_safe_redirect( $order->get_checkout_payment_url( true ) );
		exit;
	}

	/**
	 * A trial order that failed or was canceled gives its claim back.
	 *
	 * @param int $order_id Order.
	 */
	public function release_trial_claims( $order_id ): void {
		SFLS_Trials::release_order( (int) $order_id );
	}

	/**
	 * An order that is cancelled (abandoned pending orders are cancelled by
	 * WooCommerce after the hold stock time) can no longer be paid in Paddle.
	 *
	 * @param int $order_id Order.
	 */
	public function cancel_open_transaction( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || self::GATEWAY !== $order->get_payment_method() || '' !== (string) $order->get_meta( '_sfls_paddle_paid_txn' ) ) {
			return;
		}
		$txn = (string) $order->get_meta( '_sfls_paddle_txn_id' );
		if ( '' === $txn || ! SFLS_Paddle::has_api_key() ) {
			return;
		}
		$result = SFLS_Paddle::cancel_transaction( $txn );
		if ( ! is_wp_error( $result ) ) {
			/* translators: %s: transaction ID */
			$order->add_order_note( sprintf( __( 'Paddle transaction %s canceled with the order.', 'subtleforms-license-server' ), $txn ) );
		}
	}
}
