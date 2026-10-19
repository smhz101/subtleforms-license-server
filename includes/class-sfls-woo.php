<?php
/**
 * WooCommerce: sell licenses, renew and upgrade them, deliver keys.
 *
 * Mark a product as a SubtleForms license in Product data › General (or let
 * Licenses › Products create the products). Keys are issued when the order is
 * paid: orders that contain only license products move straight to Completed
 * when payment completes (whether or not the products are downloadable), and
 * paid orders that also contain other items issue their keys at Processing.
 * The `_sfls_issued` order flag makes sure an order never issues twice.
 * Refunded or cancelled orders suspend their keys.
 *
 * The cart holds one license at a time: buying another plan swaps it.
 *
 * Renewal products extend a key instead of issuing one. When the renewal was
 * started from My Account › Licenses, the cart item carries
 * `sfls_renew_license` and exactly that key is extended. An upgrade carries
 * `sfls_upgrade_license`: the existing key moves to the bought plan, priced by
 * SFLS_Upgrades.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce glue.
 */
final class SFLS_Woo {

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
		// Product settings.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );

		// Payment: license-only orders complete themselves.
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'payment_complete_status' ), 10, 3 );

		// Issue / suspend.
		add_action( 'woocommerce_order_status_completed', array( $this, 'issue_for_order' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_issue_paid' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_issue_paid' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'suspend_for_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'suspend_for_order' ) );

		// Cart: buy, renew and upgrade links; one plan at a time.
		add_action( 'template_redirect', array( $this, 'handle_cart_links' ), 5 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_renewal' ), 10, 6 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'one_plan_at_a_time' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'guard_renewal_item' ), 20, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_renewals_in_cart' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'store_api_renewal_errors' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line_item' ), 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( $this, 'order_meta_label' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'order_meta_value' ), 10, 2 );

		// Emails: license-only orders get a subject that fits a digital license (LS-11).
		add_filter( 'woocommerce_email_subject_customer_completed_order', array( $this, 'completed_subject' ), 10, 2 );
	}

	// ── License products ────────────────────────────────────────────────

	/**
	 * Whether a product (or its parent, for variations) sells a license.
	 *
	 * @param int|WC_Product|null $product Product or ID.
	 */
	public static function is_license_product( $product ): bool {
		if ( $product instanceof WC_Product ) {
			$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		} else {
			$id = (int) $product;
		}
		return $id > 0 && 'yes' === get_post_meta( $id, '_sfls_enabled', true );
	}

	/**
	 * Whether a product (or its parent) is a renewal: it extends a key the
	 * buyer already has and never issues a new one.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function is_renewal_product( int $product_id ): bool {
		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
		$id      = ( $product && $product->get_parent_id() ) ? $product->get_parent_id() : $product_id;
		return self::is_license_product( $id ) && 'yes' === get_post_meta( $id, '_sfls_renewal', true );
	}

	/**
	 * Why a renewal product cannot go in the cart with this data, or '' when
	 * it can (or the product is not a renewal). A renewal needs a signed-in
	 * buyer, a license of theirs, and the same plan as that license.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $data       Cart item data (sfls_renew_license).
	 */
	public static function renewal_problem( int $product_id, array $data ): string {
		if ( ! self::is_renewal_product( $product_id ) ) {
			return '';
		}
		$user   = wp_get_current_user();
		$target = (int) ( $data['sfls_renew_license'] ?? 0 );
		if ( ! $user->ID || ! $target ) {
			return __( 'Renewals are for existing licenses. Sign in and use Renew on My Account › Licenses, or buy a new license.', 'subtleforms-license-server' );
		}
		$lic = SFLS_Licenses::find( $target );
		if ( ! $lic || ! SFLS_Licenses::user_owns( $lic, $user ) ) {
			return __( 'That license was not found in your account.', 'subtleforms-license-server' );
		}
		$product = wc_get_product( $product_id );
		$id      = ( $product && $product->get_parent_id() ) ? $product->get_parent_id() : $product_id;
		$plan    = (string) get_post_meta( $id, '_sfls_plan', true );
		if ( (string) $lic['plan'] !== ( '' === $plan ? 'pro' : $plan ) ) {
			return __( 'This renewal is for another plan. Use Renew on My Account › Licenses.', 'subtleforms-license-server' );
		}
		return '';
	}

	/**
	 * Refuse a renewal product that is not for a license of the buyer (shop
	 * buttons, add-to-cart links and the Store API).
	 *
	 * @param bool  $passed       Validation result so far.
	 * @param int   $product_id   Product being added.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation.
	 * @param array $variations   Variation attributes.
	 * @param array $item_data    Cart item data.
	 * @return bool
	 */
	public function validate_renewal( $passed, $product_id = 0, $quantity = 1, $variation_id = 0, $variations = array(), $item_data = array() ) {
		unset( $quantity, $variations );
		if ( ! $passed ) {
			return $passed;
		}
		$problem = self::renewal_problem( (int) ( $variation_id ? $variation_id : $product_id ), (array) $item_data );
		if ( '' === $problem ) {
			return $passed;
		}
		if ( function_exists( 'wc_add_notice' ) && ! wc_has_notice( $problem, 'error' ) ) {
			wc_add_notice( $problem, 'error' );
		}
		return false;
	}

	/**
	 * Last guard inside WC_Cart::add_to_cart(), which every other path uses:
	 * an exception there is shown as a notice and nothing is added.
	 *
	 * @param array $data       Cart item data.
	 * @param int   $product_id Product ID.
	 * @return array
	 * @throws Exception When a renewal is not for a license of the buyer.
	 */
	public function guard_renewal_item( $data, $product_id = 0 ) {
		$problem = self::renewal_problem( (int) $product_id, (array) $data );
		if ( '' !== $problem ) {
			throw new Exception( esc_html( $problem ) );
		}
		return $data;
	}

	/**
	 * Remove renewal lines that are no longer valid (signed out, another
	 * account) before checkout.
	 */
	public function check_renewals_in_cart(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$problem = self::renewal_problem( (int) ( $item['product_id'] ?? 0 ), (array) $item );
			if ( '' !== $problem ) {
				WC()->cart->remove_cart_item( $key );
				if ( ! wc_has_notice( $problem, 'error' ) ) {
					wc_add_notice( $problem, 'error' );
				}
			}
		}
	}

	/**
	 * Same check for the Cart and Checkout blocks.
	 *
	 * @param WP_Error $errors Errors.
	 * @param WC_Cart  $cart   Cart.
	 */
	public function store_api_renewal_errors( $errors, $cart = null ): void {
		$cart = $cart instanceof WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $errors instanceof WP_Error || ! $cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			$problem = self::renewal_problem( (int) ( $item['product_id'] ?? 0 ), (array) $item );
			if ( '' !== $problem ) {
				$errors->add( 'sfls_renewal', $problem );
				return;
			}
		}
	}

	/**
	 * Count license and other line items of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array{license:int,other:int}
	 */
	public static function order_mix( WC_Order $order ): array {
		$mix = array(
			'license' => 0,
			'other'   => 0,
		);
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && self::is_license_product( (int) $item->get_product_id() ) ) {
				++$mix['license'];
			} else {
				++$mix['other'];
			}
		}
		return $mix;
	}

	/**
	 * Whether every line item of an order is a license product.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_is_license_only( WC_Order $order ): bool {
		$mix = self::order_mix( $order );
		return $mix['license'] > 0 && 0 === $mix['other'];
	}

	/**
	 * What a license order does: issue a new key, renew one or upgrade one.
	 *
	 * @param WC_Order $order Order.
	 * @return string new | renewal | upgrade
	 */
	public static function order_kind( WC_Order $order ): string {
		$kind = 'new';
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || ! self::is_license_product( (int) $item->get_product_id() ) ) {
				continue;
			}
			if ( (int) $item->get_meta( '_sfls_upgrade_license' ) ) {
				return 'upgrade';
			}
			if ( (int) $item->get_meta( '_sfls_renew_license' ) || self::is_renewal_product( (int) $item->get_product_id() ) ) {
				$kind = 'renewal';
			}
		}
		return $kind;
	}

	/**
	 * Subject of the completed order email for license-only orders, instead
	 * of WooCommerce's "Your order is on its way!".
	 *
	 * @param string        $subject Subject.
	 * @param WC_Order|null $order   Order.
	 * @return string
	 */
	public function completed_subject( $subject, $order = null ) {
		if ( ! $order instanceof WC_Order || ! self::order_is_license_only( $order ) ) {
			return $subject;
		}
		$kind = self::order_kind( $order );
		if ( 'renewal' === $kind ) {
			$new = __( 'Your SubtleForms Pro license is renewed', 'subtleforms-license-server' );
		} elseif ( 'upgrade' === $kind ) {
			$new = __( 'Your SubtleForms Pro license is upgraded', 'subtleforms-license-server' );
		} else {
			$new = __( 'Your SubtleForms Pro license key', 'subtleforms-license-server' );
		}
		/**
		 * Subject of the completed order email of a license-only order.
		 *
		 * @param string   $new   Subject.
		 * @param WC_Order $order Order.
		 * @param string   $kind  new | renewal | upgrade.
		 */
		return (string) apply_filters( 'sfls_completed_email_subject', $new, $order, $kind );
	}

	/**
	 * Whether the cart has items and every one is a license product.
	 */
	public static function cart_is_license_only(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! self::is_license_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				return false;
			}
		}
		return true;
	}

	// ── Product settings ────────────────────────────────────────────────

	/**
	 * License fields in Product data › General.
	 */
	public function product_fields(): void {
		echo '<div class="options_group show_if_simple show_if_virtual">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_sfls_enabled',
				'label'       => __( 'SubtleForms license', 'subtleforms-license-server' ),
				'description' => __( 'Issue a SubtleForms Pro license key when this product is bought.', 'subtleforms-license-server' ),
			)
		);
		$options = array();
		foreach ( SFLS_Plans::all() as $slug => $plan ) {
			$options[ $slug ] = $plan['label'];
		}
		woocommerce_wp_select(
			array(
				'id'      => '_sfls_plan',
				'label'   => __( 'Plan', 'subtleforms-license-server' ),
				'options' => $options,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_sfls_sites',
				'label'             => __( 'Sites', 'subtleforms-license-server' ),
				'description'       => __( 'Leave empty for the plan default. 0 means unlimited.', 'subtleforms-license-server' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_sfls_months',
				'label'             => __( 'Duration (months)', 'subtleforms-license-server' ),
				'description'       => __( '12 for yearly. 0 for lifetime.', 'subtleforms-license-server' ),
				'type'              => 'number',
				'placeholder'       => '12',
				'custom_attributes' => array( 'min' => '0' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_sfls_renewal',
				'label'       => __( 'Renewal', 'subtleforms-license-server' ),
				'description' => __( 'Extend the customer’s existing key for this plan instead of issuing a new one.', 'subtleforms-license-server' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Save the license fields.
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_product_fields( int $product_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
		update_post_meta( $product_id, '_sfls_enabled', isset( $_POST['_sfls_enabled'] ) ? 'yes' : 'no' );
		update_post_meta( $product_id, '_sfls_renewal', isset( $_POST['_sfls_renewal'] ) ? 'yes' : 'no' );
		if ( isset( $_POST['_sfls_plan'] ) ) {
			update_post_meta( $product_id, '_sfls_plan', sanitize_key( wp_unslash( $_POST['_sfls_plan'] ) ) );
		}
		foreach ( array( '_sfls_sites', '_sfls_months' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				update_post_meta( $product_id, $key, '' === $val ? '' : (string) absint( $val ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		SFLS_Products::flush();
	}

	// ── Cart links ──────────────────────────────────────────────────────

	/**
	 * Handle ?sfls_buy, ?sfls_renew and ?sfls_upgrade.
	 */
	public function handle_cart_links(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Buy links are public; renew/upgrade are nonce-checked below.
		$buy     = absint( $_GET['sfls_buy'] ?? 0 );
		$renew   = absint( $_GET['sfls_renew'] ?? 0 );
		$upgrade = absint( $_GET['sfls_upgrade'] ?? 0 );
		$plan    = sanitize_key( wp_unslash( $_GET['plan'] ?? '' ) );
		// phpcs:enable
		if ( ! $buy && ! $renew && ! $upgrade ) {
			return;
		}
		if ( function_exists( 'wc_load_cart' ) && null === WC()->cart ) {
			wc_load_cart();
		}
		if ( ! WC()->cart ) {
			return;
		}

		if ( $buy ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public buy link.
			$trial = isset( $_GET['sfls_trial'] ) && '1' === sanitize_key( wp_unslash( $_GET['sfls_trial'] ) );
			$this->buy( $buy, $trial );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$account = wc_get_account_endpoint_url( 'licenses' );
		$id      = $renew ? $renew : $upgrade;
		check_admin_referer( ( $renew ? 'sfls_renew_' : 'sfls_upgrade_' ) . $id );

		$lic = SFLS_Licenses::find( $id );
		if ( ! is_user_logged_in() || ! $lic || ! SFLS_Licenses::user_owns( $lic, wp_get_current_user() ) ) {
			wc_add_notice( __( 'That license was not found in your account.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $account );
			exit;
		}

		if ( $renew ) {
			$product = SFLS_Products::find( $lic['plan'], 'renewal' ) ?? SFLS_Products::find( $lic['plan'], 'yearly' );
			$data    = array( 'sfls_renew_license' => (int) $lic['id'] );
		} else {
			$options = SFLS_Products::upgrade_options( $lic );
			$product = $options[ $plan ] ?? null;
			$data    = array( 'sfls_upgrade_license' => (int) $lic['id'] );
		}

		if ( ! $product ) {
			wc_add_notice( __( 'This option is not on sale right now. Contact support and we will sort it out.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $account );
			exit;
		}

		$this->put_in_cart( (int) $product['id'], $data );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Put a license product in the cart for a Buy button, replacing any other
	 * plan already there. Returns whether the product is in the cart.
	 *
	 * @param int $product_id Product ID.
	 */
	public function buy( int $product_id, bool $trial = false ): bool {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() || ! self::is_license_product( $product ) ) {
			return false;
		}
		$data = array();
		if ( $trial && SFLS_Woo_Paddle::trial_allowed( $product_id ) ) {
			$user  = wp_get_current_user();
			$check = $user->ID ? SFLS_Trials::check( $user->user_email ) : array( 'eligible' => true );
			if ( $check['eligible'] ) {
				$data['sfls_trial'] = 1;
			} else {
				wc_add_notice( trim( $check['message'] . ' ' . __( 'You can buy the plan right away instead.', 'subtleforms-license-server' ) ), 'notice' );
			}
		}
		return $this->put_in_cart( $product_id, $data );
	}

	/**
	 * Add a license product. Every other license line (another plan, or the
	 * same product for a different license) is removed first, so the cart only
	 * ever holds one plan. Unrelated items stay. Returns whether it is in the
	 * cart afterwards.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $data       Cart item data.
	 */
	public function put_in_cart( int $product_id, array $data ): bool {
		$problem = self::renewal_problem( $product_id, $data );
		if ( '' !== $problem ) {
			wc_add_notice( $problem, 'error' ); // Leave the cart as it was.
			return false;
		}
		$swapped       = false;
		$this->removed = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! self::is_license_product( (int) $item['product_id'] ) ) {
				continue;
			}
			$same = (int) $item['product_id'] === $product_id
				&& (int) ( $item['sfls_renew_license'] ?? 0 ) === (int) ( $data['sfls_renew_license'] ?? 0 )
				&& (int) ( $item['sfls_upgrade_license'] ?? 0 ) === (int) ( $data['sfls_upgrade_license'] ?? 0 )
				&& (int) ( $item['sfls_trial'] ?? 0 ) === (int) ( $data['sfls_trial'] ?? 0 );
			if ( $same ) {
				continue; // Already there; keep it.
			}
			WC()->cart->remove_cart_item( $key );
			$this->removed[] = (int) $item['product_id'];
			$swapped         = true;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) $item['product_id'] === $product_id ) {
				return true;
			}
		}
		$swapped = $swapped && ! self::only_trial_changed( $product_id, $data );
		try {
			$added = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $data );
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return false;
		}
		if ( $added && $swapped ) {
			self::swap_notice( $product_id );
		}
		return (bool) $added;
	}

	/**
	 * Products removed by the last put_in_cart() call.
	 *
	 * @var int[]
	 */
	private array $removed = array();

	/**
	 * Whether put_in_cart() only switched the free trial on or off for the
	 * same product (no "we swapped your cart" notice then).
	 *
	 * @param int   $product_id Product.
	 * @param array $data       Cart item data.
	 */
	private function only_trial_changed( int $product_id, array $data ): bool {
		unset( $data );
		return array( $product_id ) === array_values( array_unique( $this->removed ) );
	}

	/**
	 * "You can buy one plan at a time. We swapped your cart to {plan}."
	 *
	 * @param int $product_id The product now in the cart.
	 */
	private static function swap_notice( int $product_id ): void {
		$message = sprintf(
			/* translators: %s: plan, e.g. "Agency" or "Pro Lifetime" */
			__( 'You can buy one plan at a time. We swapped your cart to %s.', 'subtleforms-license-server' ),
			self::plan_name( $product_id )
		);
		if ( ! wc_has_notice( $message, 'notice' ) ) {
			wc_add_notice( $message, 'notice' );
		}
	}

	/**
	 * Plan name of a license product: "Agency", "Pro Lifetime".
	 *
	 * @param int $product_id Product ID.
	 */
	public static function plan_name( int $product_id ): string {
		$plan  = (string) get_post_meta( $product_id, '_sfls_plan', true );
		$label = SFLS_Plans::label( '' === $plan ? 'pro' : $plan );
		if ( 'lifetime' === SFLS_Products::type_of( $product_id ) ) {
			/* translators: %s: plan name */
			return sprintf( __( '%s Lifetime', 'subtleforms-license-server' ), $label );
		}
		return $label;
	}

	/**
	 * Server-side guard for every other way into the cart (shop buttons, the
	 * Store API, add-to-cart links): adding a license product removes the plan
	 * that was there before and says so.
	 *
	 * @param bool  $passed       Validation result so far.
	 * @param int   $product_id   Product being added.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation.
	 * @param array $variations   Variation attributes.
	 * @param array $item_data    Cart item data.
	 * @return bool
	 */
	public function one_plan_at_a_time( $passed, $product_id = 0, $quantity = 1, $variation_id = 0, $variations = array(), $item_data = array() ) {
		unset( $quantity, $variation_id, $variations, $item_data );
		if ( ! $passed || ! self::is_license_product( (int) $product_id ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $passed;
		}
		$swapped = false;
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( (int) $item['product_id'] !== (int) $product_id && self::is_license_product( (int) $item['product_id'] ) ) {
				WC()->cart->remove_cart_item( $key );
				$swapped = true;
			}
		}
		if ( $swapped ) {
			self::swap_notice( (int) $product_id );
		}
		return $passed;
	}

	/**
	 * Show which license a renewal or upgrade applies to in cart and checkout.
	 *
	 * @param array $item_data Display rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function cart_item_data( $item_data, $cart_item ) {
		foreach ( array(
			'sfls_renew_license'   => __( 'Renews license', 'subtleforms-license-server' ),
			'sfls_upgrade_license' => __( 'Upgrades license', 'subtleforms-license-server' ),
		) as $key => $label ) {
			if ( ! empty( $cart_item[ $key ] ) ) {
				$lic = SFLS_Licenses::find( (int) $cart_item[ $key ] );
				if ( $lic ) {
					$item_data[] = array(
						'key'   => $label,
						'value' => SFLS_Licenses::mask_key( $lic['license_key'] ),
					);
				}
			}
		}
		return $item_data;
	}

	/**
	 * Copy renewal/upgrade targets onto the order line.
	 *
	 * @param WC_Order_Item_Product $item     Line item.
	 * @param string                $cart_key Cart key.
	 * @param array                 $values   Cart item.
	 */
	public function order_line_item( $item, $cart_key, $values ): void {
		if ( ! empty( $values['sfls_renew_license'] ) ) {
			$item->add_meta_data( '_sfls_renew_license', (int) $values['sfls_renew_license'], true );
		}
		if ( ! empty( $values['sfls_upgrade_license'] ) ) {
			$item->add_meta_data( '_sfls_upgrade_license', (int) $values['sfls_upgrade_license'], true );
		}
	}

	/**
	 * Label for our order item meta (shown in admin).
	 *
	 * @param string $key  Display key.
	 * @param object $meta Meta object.
	 * @return string
	 */
	public function order_meta_label( $key, $meta ) {
		$labels = array(
			'_sfls_renew_license'   => __( 'Renews license', 'subtleforms-license-server' ),
			'_sfls_upgrade_license' => __( 'Upgrades license', 'subtleforms-license-server' ),
			'_sfls_license_ids'     => __( 'License IDs', 'subtleforms-license-server' ),
		);
		return $labels[ $meta->key ] ?? $key;
	}

	/**
	 * Show masked keys instead of raw IDs.
	 *
	 * @param string $value Display value.
	 * @param object $meta  Meta object.
	 * @return string
	 */
	public function order_meta_value( $value, $meta ) {
		if ( in_array( $meta->key, array( '_sfls_renew_license', '_sfls_upgrade_license' ), true ) ) {
			$lic = SFLS_Licenses::find( (int) $meta->value );
			return $lic ? esc_html( SFLS_Licenses::mask_key( $lic['license_key'] ) ) : $value;
		}
		return $value;
	}

	// ── Payment ─────────────────────────────────────────────────────────

	/**
	 * Orders that only contain license products need no processing: complete
	 * them as soon as payment completes, downloadable flag or not.
	 *
	 * @param string        $status   Status WooCommerce picked.
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 * @return string
	 */
	public function payment_complete_status( $status, $order_id = 0, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order && 'processing' === $status && self::order_is_license_only( $order ) ) {
			return 'completed';
		}
		return $status;
	}

	// ── Issue / suspend ─────────────────────────────────────────────────

	/**
	 * Issue keys for paid orders at Processing (orders with other items stay
	 * there until shipped) and right after payment completes. Cash on delivery
	 * is not paid yet, so it waits for Completed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function maybe_issue_paid( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || 'cod' === $order->get_payment_method() ) {
			return;
		}
		if ( ! $order->get_date_paid() && ! $order->is_paid() ) {
			return;
		}
		if ( self::order_mix( $order )['license'] > 0 ) {
			$this->issue_for_order( (int) $order_id );
		}
	}

	/**
	 * Whether a license belongs to the customer of an order: the order's
	 * customer account owns it. The billing email proves nothing.
	 *
	 * @param array    $lic   License.
	 * @param WC_Order $order Order.
	 */
	private function order_owns( array $lic, WC_Order $order ): bool {
		$customer = (int) $order->get_customer_id();
		return $customer > 0 && (int) $lic['user_id'] === $customer;
	}

	/**
	 * Take a short lock on an order so two requests (payment webhook and the
	 * customer's return, say) cannot issue at the same time.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function lock( int $order_id ): bool {
		global $wpdb;
		$name = 'sfls_issue_lock_' . $order_id;
		// Same pattern as WP_Upgrader::create_lock(): INSERT IGNORE is atomic.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $name, (string) time() ) );
		if ( ! $got ) {
			$since = (int) get_option( $name );
			if ( $since && $since > time() - MINUTE_IN_SECONDS ) {
				return false;
			}
			update_option( $name, (string) time(), false ); // Stale lock from a crashed request.
		}
		return true;
	}

	/**
	 * Release the order lock.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function unlock( int $order_id ): void {
		delete_option( 'sfls_issue_lock_' . $order_id );
	}

	/**
	 * Issue, renew or upgrade keys for an order, once.
	 *
	 * @param int $order_id Order ID.
	 */
	public function issue_for_order( $order_id ): void {
		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'yes' === $order->get_meta( '_sfls_issued' ) ) {
			return;
		}
		if ( 0 === self::order_mix( $order )['license'] ) {
			return;
		}
		if ( ! self::lock( $order_id ) ) {
			return;
		}

		// Re-read inside the lock: another request may have just finished.
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'yes' === $order->get_meta( '_sfls_issued' ) ) {
			self::unlock( $order_id );
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			if ( ! self::is_license_product( $product_id ) ) {
				continue;
			}
			if ( $item->get_meta( '_sfls_license_ids' ) ) {
				continue; // Already issued.
			}

			$plan   = get_post_meta( $product_id, '_sfls_plan', true );
			$plan   = $plan ? $plan : 'pro';
			$sites  = (string) get_post_meta( $product_id, '_sfls_sites', true );
			$months = (string) get_post_meta( $product_id, '_sfls_months', true );
			$months = '' === $months ? 12 : (int) $months;
			$email  = $order->get_billing_email();
			$ids    = array();

			$target_upgrade = (int) $item->get_meta( '_sfls_upgrade_license' );
			$target_renew   = (int) $item->get_meta( '_sfls_renew_license' );
			$upgrade        = $target_upgrade ? SFLS_Licenses::find( $target_upgrade ) : null;
			$upgrade        = ( $upgrade && $this->order_owns( $upgrade, $order ) ) ? $upgrade : null;

			if ( $upgrade ) {
				$keep = 'yes' === $item->get_meta( '_sfls_upgrade_keep_expiry' );
				$this->apply_upgrade( $upgrade, $plan, $sites, $months, $product_id, $keep );
				$ids[] = (int) $upgrade['id'];
				$after = SFLS_Licenses::find( (int) $upgrade['id'] );
				self::record_change( (int) $item_id, 'upgrade', $upgrade, (array) $after );
				$order->add_order_note(
					sprintf(
						/* translators: 1: masked key, 2: old plan, 3: new plan, 4: sites, 5: amount charged */
						__( 'SubtleForms license %1$s upgraded from %2$s to %3$s (%4$s), same key. Charged %5$s for the rest of the current term.', 'subtleforms-license-server' ),
						SFLS_Licenses::mask_key( $upgrade['license_key'] ),
						SFLS_Plans::label( $upgrade['plan'] ),
						SFLS_Plans::label( $plan ),
						SFLS_Plans::sites_label( (int) ( $after['sites_allowed'] ?? 0 ) ),
						html_entity_decode( wp_strip_all_tags( wc_price( (float) $item->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' )
					)
				);
				wc_update_order_item_meta( $item_id, '_sfls_license_ids', implode( ',', $ids ) );
				continue;
			}

			$renewal = null;
			if ( $target_renew ) {
				$renewal = SFLS_Licenses::find( $target_renew );
				$renewal = ( $renewal && $this->order_owns( $renewal, $order ) ) ? $renewal : null;
			}
			if ( ! $renewal && 'yes' === get_post_meta( $product_id, '_sfls_renewal', true ) ) {
				$renewal = $this->renewable_license( (int) $order->get_customer_id(), $plan );
			}

			if ( ( ! $renewal || 1 !== (int) $item->get_quantity() ) && 'yes' === get_post_meta( $product_id, '_sfls_renewal', true ) ) {
				// A renewal product never issues a new key.
				$order->add_order_note(
					sprintf(
						/* translators: %s: product name */
						__( 'No SubtleForms license was issued for %s: it is a renewal, and the order does not name a license of this customer. Refund it, or renew the right license by hand.', 'subtleforms-license-server' ),
						$item->get_name()
					)
				);
				continue;
			}

			if ( $renewal && 1 === (int) $item->get_quantity() ) {
				SFLS_Licenses::extend( (int) $renewal['id'], max( 1, $months ) );
				SFLS_Licenses::update( (int) $renewal['id'], array( 'reminders' => '' ) );
				self::record_change( (int) $item_id, 'renew', $renewal, (array) SFLS_Licenses::find( (int) $renewal['id'] ) );
				$ids[] = (int) $renewal['id'];
				/* translators: %s: masked key */
				$order->add_order_note( sprintf( __( 'SubtleForms license %s renewed.', 'subtleforms-license-server' ), SFLS_Licenses::mask_key( $renewal['license_key'] ) ) );
			} else {
				for ( $i = 0; $i < max( 1, (int) $item->get_quantity() ); $i++ ) {
					$lic   = SFLS_Licenses::create(
						array(
							'plan'           => $plan,
							'sites_allowed'  => '' === $sites ? (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ) : (int) $sites,
							'customer_email' => $email,
							'user_id'        => (int) $order->get_customer_id(),
							'order_id'       => $order_id,
							'product_id'     => $product_id,
							'months'         => $months,
						)
					);
					$ids[] = (int) $lic['id'];
				}
				/* translators: %d: number of keys */
				$order->add_order_note( sprintf( _n( '%d SubtleForms license key issued.', '%d SubtleForms license keys issued.', count( $ids ), 'subtleforms-license-server' ), count( $ids ) ) );
			}

			wc_update_order_item_meta( $item_id, '_sfls_license_ids', implode( ',', $ids ) );
		}

		$order->update_meta_data( '_sfls_issued', 'yes' );
		$order->save_meta_data();
		self::unlock( $order_id );

		do_action( 'sfls_order_issued', $order_id );
	}

	/**
	 * Move a license to a bigger plan, keeping the key. A prorated upgrade
	 * (the customer paid the difference for the rest of the term) keeps the
	 * expiry. Otherwise the expiry is kept, or pushed out to a full term from
	 * today when that is later. A lifetime purchase makes it lifetime.
	 *
	 * @param array  $lic         License.
	 * @param string $plan        New plan.
	 * @param string $sites       Product site limit ('' = plan default).
	 * @param int    $months      Product duration (0 = lifetime).
	 * @param int    $product_id  Product ID.
	 * @param bool   $keep_expiry Prorated: leave the expiry as it is.
	 */
	public function apply_upgrade( array $lic, string $plan, string $sites, int $months, int $product_id, bool $keep_expiry = false ): void {
		$limit = '' === $sites ? (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ) : (int) $sites;
		$data  = array(
			'plan'          => $plan,
			'sites_allowed' => $limit,
			'status'        => 'active',
			'product_id'    => $product_id,
		);
		if ( 0 === $months ) {
			$data['expires_at'] = null;
		} elseif ( ! empty( $lic['expires_at'] ) && ! $keep_expiry ) {
			$current            = strtotime( $lic['expires_at'] . ' UTC' );
			$term               = strtotime( "+{$months} months" );
			$data['expires_at'] = gmdate( 'Y-m-d H:i:s', max( $current, $term ) );
			$data['reminders']  = '';
		}
		SFLS_Licenses::update( (int) $lic['id'], $data );
		do_action( 'sfls_license_upgraded', (int) $lic['id'], $lic['plan'], $plan );
	}

	/**
	 * Columns a renewal or upgrade order changes, recorded so a refund can
	 * undo exactly that.
	 */
	const CHANGED_COLUMNS = array( 'plan', 'sites_allowed', 'expires_at', 'product_id', 'status', 'reminders' );

	/**
	 * Remember on the order line what a renewal or upgrade changed
	 * (`_sfls_applied`: type, license, values before and after).
	 *
	 * @param int    $item_id Order item.
	 * @param string $type    renew | upgrade.
	 * @param array  $before  License before.
	 * @param array  $after   License after.
	 */
	private static function record_change( int $item_id, string $type, array $before, array $after ): void {
		$pick = static fn( array $lic ): array => array_intersect_key( $lic, array_flip( self::CHANGED_COLUMNS ) );
		wc_update_order_item_meta(
			$item_id,
			'_sfls_applied',
			wp_json_encode(
				array(
					'type'       => $type,
					'license_id' => (int) $before['id'],
					'before'     => $pick( $before ),
					'after'      => $pick( $after ),
				)
			)
		);
	}

	/**
	 * Refunded or cancelled order: suspend the keys it issued, and undo the
	 * renewals and upgrades it applied to existing keys. Partial refunds do
	 * not change the order status, so they leave the license alone.
	 *
	 * @param int $order_id Order ID.
	 */
	public function suspend_for_order( $order_id ): void {
		$order_id = (int) $order_id;
		foreach ( self::order_licenses( $order_id ) as $lic ) {
			if ( (int) $lic['order_id'] === $order_id ) {
				SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'suspended' ) );
			}
		}
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			self::revert_changes( $order );
		}
	}

	/**
	 * Undo what the renewal and upgrade lines of an order changed, once.
	 * A renewal loses the time it added. An upgrade goes back to the previous
	 * plan, site limit and expiry, unless the license changed plan since
	 * (then an order note asks for a manual check).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function revert_changes( WC_Order $order ): void {
		foreach ( $order->get_items() as $item_id => $item ) {
			$applied = json_decode( (string) $item->get_meta( '_sfls_applied' ), true );
			if ( ! is_array( $applied ) || '' !== (string) $item->get_meta( '_sfls_reverted' ) ) {
				continue;
			}
			$lic = SFLS_Licenses::find( (int) ( $applied['license_id'] ?? 0 ) );
			if ( ! $lic ) {
				continue;
			}
			wc_update_order_item_meta( (int) $item_id, '_sfls_reverted', gmdate( 'c' ) );
			$before = (array) ( $applied['before'] ?? array() );
			$after  = (array) ( $applied['after'] ?? array() );
			$data   = array();

			if ( 'renew' === ( $applied['type'] ?? '' ) ) {
				if ( ! empty( $before['expires_at'] ) && ! empty( $after['expires_at'] ) && ! empty( $lic['expires_at'] ) ) {
					// Take off exactly what this order added, even if another renewal came later.
					$added              = strtotime( $after['expires_at'] . ' UTC' ) - strtotime( $before['expires_at'] . ' UTC' );
					$data['expires_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $lic['expires_at'] . ' UTC' ) - max( 0, $added ) );
				}
				if ( isset( $before['status'] ) && 'active' === $lic['status'] && 'active' !== $before['status'] ) {
					$data['status'] = (string) $before['status'];
				}
				$note = sprintf(
					/* translators: 1: masked key, 2: new expiry */
					__( 'Renewal of SubtleForms license %1$s undone because the order was refunded or cancelled. It now expires %2$s.', 'subtleforms-license-server' ),
					SFLS_Licenses::mask_key( (string) $lic['license_key'] ),
					isset( $data['expires_at'] ) ? SFLS_Licenses::expiry_label( array( 'expires_at' => $data['expires_at'] ) ) : SFLS_Licenses::expiry_label( $lic )
				);
			} elseif ( 'upgrade' === ( $applied['type'] ?? '' ) ) {
				if ( (string) $lic['plan'] !== (string) ( $after['plan'] ?? '' ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: masked key, 2: plan */
							__( 'SubtleForms license %1$s was not moved back after the refund because it changed to %2$s since. Check it by hand.', 'subtleforms-license-server' ),
							SFLS_Licenses::mask_key( (string) $lic['license_key'] ),
							SFLS_Plans::label( (string) $lic['plan'] )
						)
					);
					continue;
				}
				foreach ( array( 'plan', 'sites_allowed', 'product_id' ) as $column ) {
					if ( array_key_exists( $column, $before ) ) {
						$data[ $column ] = $before[ $column ];
					}
				}
				if ( array_key_exists( 'expires_at', $before ) && (string) ( $lic['expires_at'] ?? '' ) === (string) ( $after['expires_at'] ?? '' ) ) {
					$data['expires_at'] = $before['expires_at'];
				}
				$note = sprintf(
					/* translators: 1: masked key, 2: plan, 3: sites */
					__( 'Upgrade of SubtleForms license %1$s undone because the order was refunded or cancelled. Back to %2$s (%3$s).', 'subtleforms-license-server' ),
					SFLS_Licenses::mask_key( (string) $lic['license_key'] ),
					SFLS_Plans::label( (string) ( $before['plan'] ?? $lic['plan'] ) ),
					SFLS_Plans::sites_label( (int) ( $before['sites_allowed'] ?? $lic['sites_allowed'] ) )
				);
			} else {
				continue;
			}
			if ( $data ) {
				SFLS_Licenses::update( (int) $lic['id'], $data );
			}
			$order->add_order_note( $note );
			do_action( 'sfls_license_change_reverted', (int) $lic['id'], (string) $applied['type'], $order->get_id() );
		}
	}

	/**
	 * A license this customer account could renew: same plan, issued earlier.
	 *
	 * @param int    $user_id User ID (0 never matches).
	 * @param string $plan    Plan.
	 */
	private function renewable_license( int $user_id, string $plan ): ?array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE plan = %s AND status <> 'suspended' AND user_id = %d AND expires_at IS NOT NULL ORDER BY expires_at ASC LIMIT 1", $plan, $user_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Licenses attached to an order's items.
	 *
	 * @param int $order_id Order ID.
	 * @return array[]
	 */
	public static function order_licenses( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}
		$out = array();
		foreach ( $order->get_items() as $item ) {
			foreach ( array_filter( explode( ',', (string) $item->get_meta( '_sfls_license_ids' ) ) ) as $id ) {
				$lic = SFLS_Licenses::find( (int) $id );
				if ( $lic ) {
					$out[ (int) $lic['id'] ] = $lic;
				}
			}
		}
		return array_values( $out );
	}
}
