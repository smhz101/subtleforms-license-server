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
		add_action( 'woocommerce_order_status_completed', array( $this, 'reactivate_for_order' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_issue_paid' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_issue_paid' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'suspend_for_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'suspend_for_order' ) );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'partial_refund' ), 10, 2 );

		// Cart: buy, renew and upgrade links; one plan at a time.
		add_action( 'template_redirect', array( $this, 'handle_cart_links' ), 5 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'one_plan_at_a_time' ), 20, 6 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'renewal_needs_license' ), 10, 6 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line_item' ), 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( $this, 'order_meta_label' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'order_meta_value' ), 10, 2 );

		// 1.7.0: license carts need an account (LS-02); the customer's email
		// cannot be changed to an address that owns other licenses (LS-01).
		add_filter( 'pre_option_woocommerce_enable_guest_checkout', array( $this, 'option_no_guest' ) );
		add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', array( $this, 'option_signup' ) );
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'registration_required' ) );
		add_filter( 'woocommerce_checkout_registration_enabled', array( $this, 'registration_enabled' ) );
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'refuse_email_takeover' ), 10, 2 );
		add_action( 'user_profile_update_errors', array( $this, 'refuse_profile_email_takeover' ), 10, 3 );
		add_action( 'profile_update', array( $this, 'remember_email_change' ), 10, 2 );

		// 1.7.0: subjects for license-only orders (LS-11), renewal-only coupons.
		add_filter( 'woocommerce_email_subject_customer_completed_order', array( $this, 'completed_subject' ), 10, 3 );
		add_action( 'woocommerce_coupon_options', array( $this, 'coupon_field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_field' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'coupon_valid_for_product' ), 10, 4 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'coupon_valid' ), 10, 3 );
	}

	// ── Accounts at checkout (LS-01, LS-02) ─────────────────────────────

	/**
	 * Whether the current request checks out (or renders the checkout for)
	 * a cart that only holds licenses, and accounts are forced for those.
	 */
	public static function license_checkout(): bool {
		if ( ! SFLS_Settings::force_account() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		static $checking = false;
		if ( $checking ) {
			return false; // cart_is_license_only() reads options that we filter.
		}
		$checking = true;
		$context  = ( did_action( 'wp' ) && function_exists( 'is_checkout' ) && is_checkout() )
			|| ( method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request() )
			|| wp_doing_ajax()
			|| doing_action( 'woocommerce_checkout_process' )
			|| doing_action( 'woocommerce_after_checkout_validation' );
		$context  = (bool) apply_filters( 'sfls_checkout_context', $context );
		$result   = $context && self::cart_is_license_only();
		$checking = false;
		return (bool) apply_filters( 'sfls_license_checkout', $result );
	}

	/**
	 * Guest checkout off for license carts.
	 *
	 * @param mixed $pre Option value (false = read the option).
	 * @return mixed
	 */
	public function option_no_guest( $pre ) {
		return self::license_checkout() ? 'no' : $pre;
	}

	/**
	 * Account creation on for license carts.
	 *
	 * @param mixed $pre Option value.
	 * @return mixed
	 */
	public function option_signup( $pre ) {
		return self::license_checkout() ? 'yes' : $pre;
	}

	/**
	 * Classic checkout: an account is required for license carts.
	 *
	 * @param bool $required Required.
	 * @return bool
	 */
	public function registration_required( $required ) {
		return self::license_checkout() ? true : $required;
	}

	/**
	 * Classic checkout: the registration fields are shown for license carts.
	 *
	 * @param bool $enabled Enabled.
	 * @return bool
	 */
	public function registration_enabled( $enabled ) {
		return self::license_checkout() ? true : $enabled;
	}

	/**
	 * Licenses another account (or an unclaimed guest order) holds under an
	 * email address, so changing to that address would show them.
	 *
	 * @param string $email   New email.
	 * @param int    $user_id The account changing its email.
	 * @return array[]
	 */
	public static function licenses_blocking_email( string $email, int $user_id ): array {
		$out = array();
		foreach ( SFLS_Licenses::by_email( $email ) as $lic ) {
			if ( (int) $lic['user_id'] !== $user_id ) {
				$out[] = $lic;
			}
		}
		return $out;
	}

	/**
	 * My Account › Account details: refuse a new email that owns licenses
	 * elsewhere. The claim link in the order email, or support, moves them.
	 *
	 * @param WP_Error $errors Errors.
	 * @param WP_User  $user   User with the new values.
	 */
	public function refuse_email_takeover( $errors, $user ): void {
		if ( ! $errors instanceof WP_Error || ! $user instanceof WP_User ) {
			return;
		}
		$current = get_userdata( (int) $user->ID );
		if ( ! $current || 0 === strcasecmp( $current->user_email, (string) $user->user_email ) ) {
			return;
		}
		if ( self::licenses_blocking_email( (string) $user->user_email, (int) $user->ID ) ) {
			$errors->add( 'sfls_email_taken', __( 'That email address is linked to SubtleForms licenses bought under another account. Use the claim link in the order email, or contact support, to move them to this account first.', 'subtleforms-license-server' ) );
		}
	}

	/**
	 * Same check for the WordPress profile screen (customers rarely see it,
	 * but every path that can change the email is covered).
	 *
	 * @param WP_Error $errors Errors.
	 * @param bool     $update Updating.
	 * @param stdClass $user   Submitted user.
	 */
	public function refuse_profile_email_takeover( $errors, $update, $user ): void {
		if ( ! $update || ! $errors instanceof WP_Error || empty( $user->ID ) || empty( $user->user_email ) || current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = get_userdata( (int) $user->ID );
		if ( ! $current || 0 === strcasecmp( $current->user_email, (string) $user->user_email ) ) {
			return;
		}
		if ( self::licenses_blocking_email( (string) $user->user_email, (int) $user->ID ) ) {
			$errors->add( 'sfls_email_taken', __( 'That email address is linked to SubtleForms licenses bought under another account. Use the claim link in the order email, or contact support, to move them to this account first.', 'subtleforms-license-server' ) );
		}
	}

	/**
	 * Remember when an account changed its email, so unclaimed licenses are
	 * never matched to an address adopted after they were bought.
	 *
	 * @param int          $user_id  User.
	 * @param WP_User|null $old_data User before the change.
	 */
	public function remember_email_change( $user_id, $old_data = null ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! $old_data instanceof WP_User ) {
			return;
		}
		if ( 0 !== strcasecmp( (string) $old_data->user_email, (string) $user->user_email ) ) {
			update_user_meta( (int) $user_id, SFLS_Licenses::EMAIL_CHANGED_META, current_time( 'mysql', true ) );
		}
	}

	// ── Subjects and coupons (1.7.0) ────────────────────────────────────

	/**
	 * "Your SubtleForms Pro license key" instead of the shop default for
	 * license-only orders; renewals and upgrades get their own line.
	 *
	 * @param string   $subject Subject.
	 * @param WC_Order $order   Order.
	 * @param mixed    $email   WC_Email.
	 * @return string
	 */
	public function completed_subject( $subject, $order = null, $email = null ) {
		unset( $email );
		if ( ! $order instanceof WC_Order || ! self::order_is_license_only( $order ) ) {
			return $subject;
		}
		$kind = self::order_kind( $order );
		if ( 'renewal' === $kind ) {
			$new = __( 'Your SubtleForms Pro license has been renewed', 'subtleforms-license-server' );
		} elseif ( 'upgrade' === $kind ) {
			$new = __( 'Your SubtleForms Pro license has been upgraded', 'subtleforms-license-server' );
		} else {
			$new = __( 'Your SubtleForms Pro license key', 'subtleforms-license-server' );
		}
		return (string) apply_filters( 'sfls_completed_email_subject', $new, $order, $kind );
	}

	/**
	 * What a license order does: new, renewal or upgrade.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_kind( WC_Order $order ): string {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( (int) $item->get_meta( '_sfls_upgrade_license' ) ) {
				return 'upgrade';
			}
			if ( (int) $item->get_meta( '_sfls_renew_license' ) || 'yes' === get_post_meta( (int) $item->get_product_id(), '_sfls_renewal', true ) ) {
				return 'renewal';
			}
		}
		return 'new';
	}

	/**
	 * Coupon editor: "Renewals only" checkbox.
	 *
	 * @param int       $coupon_id Coupon ID.
	 * @param WC_Coupon $coupon    Coupon.
	 */
	public function coupon_field( $coupon_id = 0, $coupon = null ): void {
		unset( $coupon_id );
		woocommerce_wp_checkbox(
			array(
				'id'          => '_sfls_renewal_only',
				'label'       => __( 'SubtleForms renewals only', 'subtleforms-license-server' ),
				'description' => __( 'The coupon only applies to license renewal products, and only when the cart renews an existing license.', 'subtleforms-license-server' ),
				'value'       => $coupon instanceof WC_Coupon && 'yes' === $coupon->get_meta( '_sfls_renewal_only' ) ? 'yes' : 'no',
			)
		);
	}

	/**
	 * Save the coupon checkbox.
	 *
	 * @param int       $coupon_id Coupon ID.
	 * @param WC_Coupon $coupon    Coupon.
	 */
	public function save_coupon_field( $coupon_id, $coupon = null ): void {
		$coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon( (int) $coupon_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the coupon save nonce.
		$coupon->update_meta_data( '_sfls_renewal_only', isset( $_POST['_sfls_renewal_only'] ) ? 'yes' : 'no' );
		$coupon->save();
	}

	/**
	 * Whether a coupon is restricted to renewals.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 */
	public static function coupon_renewal_only( WC_Coupon $coupon ): bool {
		return 'yes' === $coupon->get_meta( '_sfls_renewal_only' );
	}

	/**
	 * A renewal-only coupon never discounts anything but a renewal line.
	 *
	 * @param bool       $valid   Valid.
	 * @param WC_Product $product Product.
	 * @param WC_Coupon  $coupon  Coupon.
	 * @param array      $values  Cart item.
	 * @return bool
	 */
	public function coupon_valid_for_product( $valid, $product = null, $coupon = null, $values = array() ) {
		if ( ! $coupon instanceof WC_Coupon || ! self::coupon_renewal_only( $coupon ) ) {
			return $valid;
		}
		$product_id = $product instanceof WC_Product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : 0;
		if ( 'renewal' !== SFLS_Products::type_of( (int) $product_id ) ) {
			return false;
		}
		if ( $values instanceof WC_Order_Item_Product ) {
			return (int) $values->get_meta( '_sfls_renew_license' ) > 0;
		}
		return ! empty( $values['sfls_renew_license'] );
	}

	/**
	 * A renewal-only coupon is refused when the cart renews nothing.
	 *
	 * @param bool          $valid     Valid.
	 * @param WC_Coupon     $coupon    Coupon.
	 * @param WC_Discounts  $discounts Discounts.
	 * @return bool
	 * @throws Exception When the coupon does not apply.
	 */
	public function coupon_valid( $valid, $coupon = null, $discounts = null ) {
		unset( $discounts );
		if ( ! $valid || ! $coupon instanceof WC_Coupon || ! self::coupon_renewal_only( $coupon ) || ! WC()->cart ) {
			return $valid;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['sfls_renew_license'] ) ) {
				return $valid;
			}
		}
		throw new Exception( esc_html__( 'This coupon is for license renewals. Start the renewal from My Account › Licenses and apply it there.', 'subtleforms-license-server' ) );
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

		$account = wc_get_account_endpoint_url( 'licenses' );

		if ( $buy ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public buy link.
			$trial = isset( $_GET['sfls_trial'] ) && '1' === sanitize_key( wp_unslash( $_GET['sfls_trial'] ) );
			if ( 'renewal' === SFLS_Products::type_of( $buy ) ) {
				// LS-04: a renewal product needs a license to renew.
				$target = is_user_logged_in() ? $this->renewable_for_user( wp_get_current_user(), (string) get_post_meta( $buy, '_sfls_plan', true ) ) : null;
				if ( ! $target ) {
					wc_add_notice( __( 'Renewals are for existing licenses. Sign in and use the Renew button next to your license.', 'subtleforms-license-server' ), 'error' );
					wp_safe_redirect( is_user_logged_in() ? $account : SFLS_Connect::login_url( $account ) );
					exit;
				}
				$this->put_in_cart( $buy, array( 'sfls_renew_license' => (int) $target['id'] ) );
				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}
			$this->buy( $buy, $trial );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$id = $renew ? $renew : $upgrade;
		check_admin_referer( ( $renew ? 'sfls_renew_' : 'sfls_upgrade_' ) . $id );

		$lic = SFLS_Licenses::find( $id );
		if ( ! is_user_logged_in() || ! $lic || ! SFLS_Licenses::user_owns( $lic, wp_get_current_user() ) ) {
			wc_add_notice( __( 'That license was not found in your account.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $account );
			exit;
		}

		if ( $renew ) {
			// A smaller plan at renewal (1.7.0 downgrade): ?plan=pro on the renew link.
			$target_plan = '' !== $plan && SFLS_Plans::get( $plan ) && SFLS_Plans::rank( $plan ) < SFLS_Plans::rank( (string) $lic['plan'] ) ? $plan : (string) $lic['plan'];
			$product     = SFLS_Products::find( $target_plan, 'renewal' ) ?? SFLS_Products::find( $target_plan, 'yearly' );
			$data        = array( 'sfls_renew_license' => (int) $lic['id'] );
			if ( $target_plan !== (string) $lic['plan'] ) {
				$data['sfls_downgrade_plan'] = $target_plan;
			}
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
		$swapped       = false;
		$this->removed = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! self::is_license_product( (int) $item['product_id'] ) ) {
				continue;
			}
			$same = (int) $item['product_id'] === $product_id
				&& (int) ( $item['sfls_renew_license'] ?? 0 ) === (int) ( $data['sfls_renew_license'] ?? 0 )
				&& (int) ( $item['sfls_upgrade_license'] ?? 0 ) === (int) ( $data['sfls_upgrade_license'] ?? 0 )
				&& (string) ( $item['sfls_downgrade_plan'] ?? '' ) === (string) ( $data['sfls_downgrade_plan'] ?? '' )
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
	 * LS-04: a renewal product can only be added for an existing license of
	 * its plan: either the Renew button put the license in the cart item
	 * data, or the signed-in customer has one to renew (then it is attached).
	 *
	 * @param bool  $passed       Validation result so far.
	 * @param int   $product_id   Product being added.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation.
	 * @param array $variations   Variation attributes.
	 * @param array $item_data    Cart item data.
	 * @return bool
	 */
	public function renewal_needs_license( $passed, $product_id = 0, $quantity = 1, $variation_id = 0, $variations = array(), $item_data = array() ) {
		unset( $quantity, $variation_id, $variations );
		if ( ! $passed || ! self::is_license_product( (int) $product_id ) || 'renewal' !== SFLS_Products::type_of( (int) $product_id ) ) {
			return $passed;
		}
		$target = (int) ( $item_data['sfls_renew_license'] ?? 0 );
		$lic    = $target ? SFLS_Licenses::find( $target ) : null;
		$user   = wp_get_current_user();
		if ( $lic && $user->ID && SFLS_Licenses::user_owns( $lic, $user ) ) {
			return $passed;
		}
		if ( $user->ID && $this->renewable_for_user( $user, (string) get_post_meta( (int) $product_id, '_sfls_plan', true ) ) ) {
			return $passed; // issue_for_order() picks the license the same way.
		}
		wc_add_notice( __( 'Renewals are for existing licenses. Sign in and use the Renew button next to your license in My Account › Licenses.', 'subtleforms-license-server' ), 'error' );
		return false;
	}

	/**
	 * The license a signed-in customer could renew with a plan's renewal
	 * product (the one expiring first).
	 *
	 * @param WP_User $user User.
	 * @param string  $plan Plan of the renewal product.
	 */
	public function renewable_for_user( WP_User $user, string $plan ): ?array {
		$plan = '' === $plan ? 'pro' : $plan;
		$best = null;
		foreach ( SFLS_Licenses::for_user( $user ) as $lic ) {
			if ( empty( $lic['expires_at'] ) || 'suspended' === $lic['status'] || SFLS_Licenses::is_paddle( $lic ) ) {
				continue;
			}
			// A renewal product of a smaller plan is a downgrade at renewal.
			if ( $lic['plan'] !== $plan && SFLS_Plans::rank( $plan ) > SFLS_Plans::rank( (string) $lic['plan'] ) ) {
				continue;
			}
			if ( ! $best || strtotime( $lic['expires_at'] . ' UTC' ) < strtotime( $best['expires_at'] . ' UTC' ) ) {
				$best = $lic;
			}
		}
		return $best;
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
		if ( ! empty( $cart_item['sfls_downgrade_plan'] ) ) {
			$item_data[] = array(
				'key'   => __( 'New plan', 'subtleforms-license-server' ),
				/* translators: %s: plan name */
				'value' => sprintf( __( '%s from this renewal on', 'subtleforms-license-server' ), SFLS_Plans::label( (string) $cart_item['sfls_downgrade_plan'] ) ),
			);
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
		if ( ! empty( $values['sfls_downgrade_plan'] ) ) {
			$item->add_meta_data( '_sfls_downgrade_plan', sanitize_key( (string) $values['sfls_downgrade_plan'] ), true );
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
			'_sfls_downgrade_plan'  => __( 'New plan at renewal', 'subtleforms-license-server' ),
			'_sfls_license_ids'     => __( 'License IDs', 'subtleforms-license-server' ),
			'_sfls_renew_before'    => __( 'Expiry before renewal', 'subtleforms-license-server' ),
			'_sfls_upgrade_before'  => __( 'Before upgrade', 'subtleforms-license-server' ),
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
	 * Whether a license belongs to the customer of an order.
	 *
	 * @param array    $lic   License.
	 * @param WC_Order $order Order.
	 */
	private function order_owns( array $lic, WC_Order $order ): bool {
		$customer = (int) $order->get_customer_id();
		if ( $customer && (int) $lic['user_id'] === $customer ) {
			return true;
		}
		$user = $customer ? get_userdata( $customer ) : false;
		if ( $user && SFLS_Licenses::user_owns( $lic, $user ) ) {
			return true;
		}
		return '' !== $lic['customer_email'] && 0 === strcasecmp( $lic['customer_email'], (string) $order->get_billing_email() );
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
				wc_update_order_item_meta(
					$item_id,
					'_sfls_upgrade_before',
					wp_json_encode(
						array(
							'plan'          => $upgrade['plan'],
							'sites_allowed' => (int) $upgrade['sites_allowed'],
							'expires_at'    => $upgrade['expires_at'],
							'product_id'    => (int) $upgrade['product_id'],
						)
					)
				);
				/* translators: %d: order number */
				SFLS_Audit::label( 'upgrade', sprintf( __( 'Order #%d', 'subtleforms-license-server' ), $order_id ), 'order' );
				$this->apply_upgrade( $upgrade, $plan, $sites, $months, $product_id, $keep );
				$ids[] = (int) $upgrade['id'];
				$after = SFLS_Licenses::find( (int) $upgrade['id'] );
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
				$renewal = $this->renewable_license( (int) $order->get_customer_id(), $email, $plan );
			}

			if ( $renewal && 1 === (int) $item->get_quantity() ) {
				wc_update_order_item_meta( $item_id, '_sfls_renew_before', (string) $renewal['expires_at'] );
				$downgrade = sanitize_key( (string) $item->get_meta( '_sfls_downgrade_plan' ) );
				$downgrade = '' !== $downgrade && SFLS_Plans::get( $downgrade ) && SFLS_Plans::rank( $downgrade ) < SFLS_Plans::rank( (string) $renewal['plan'] ) ? $downgrade : '';
				/* translators: %d: order number */
				SFLS_Audit::label( '' !== $downgrade ? 'downgrade' : 'renew', sprintf( __( 'Order #%d', 'subtleforms-license-server' ), $order_id ), 'order' );
				SFLS_Licenses::extend( (int) $renewal['id'], max( 1, $months ) );
				$extra = array( 'reminders' => '' );
				if ( '' !== $downgrade ) {
					wc_update_order_item_meta(
						$item_id,
						'_sfls_upgrade_before',
						wp_json_encode(
							array(
								'plan'          => $renewal['plan'],
								'sites_allowed' => (int) $renewal['sites_allowed'],
								'product_id'    => (int) $renewal['product_id'],
							)
						)
					);
					$extra['plan']          = $downgrade;
					$extra['sites_allowed'] = '' === $sites ? (int) ( SFLS_Plans::get( $downgrade )['sites'] ?? 1 ) : (int) $sites;
					$extra['product_id']    = $product_id;
					SFLS_Audit::label( 'downgrade', sprintf( /* translators: %d: order number */ __( 'Order #%d', 'subtleforms-license-server' ), $order_id ), 'order' );
				}
				SFLS_Licenses::update( (int) $renewal['id'], $extra );
				$ids[] = (int) $renewal['id'];
				if ( '' !== $downgrade ) {
					/* translators: 1: masked key, 2: old plan, 3: new plan */
					$order->add_order_note( sprintf( __( 'SubtleForms license %1$s renewed and moved from %2$s to %3$s.', 'subtleforms-license-server' ), SFLS_Licenses::mask_key( $renewal['license_key'] ), SFLS_Plans::label( $renewal['plan'] ), SFLS_Plans::label( $downgrade ) ) );
					do_action( 'sfls_license_downgraded', (int) $renewal['id'], $renewal['plan'], $downgrade );
				} else {
					/* translators: %s: masked key */
					$order->add_order_note( sprintf( __( 'SubtleForms license %s renewed.', 'subtleforms-license-server' ), SFLS_Licenses::mask_key( $renewal['license_key'] ) ) );
				}
			} else {
				if ( 'yes' === get_post_meta( $product_id, '_sfls_renewal', true ) ) {
					$order->add_order_note( __( 'This renewal product found no license to renew for the customer, so a new key was issued instead.', 'subtleforms-license-server' ) );
				}
				for ( $i = 0; $i < max( 1, (int) $item->get_quantity() ); $i++ ) {
					$lic   = SFLS_Licenses::create(
						array(
							'plan'           => $plan,
							'sites_allowed'  => '' === $sites ? (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ) : (int) $sites,
							'customer_email' => $email,
							'order_email'    => $email,
							'user_id'        => (int) $order->get_customer_id(),
							'order_id'       => $order_id,
							'product_id'     => $product_id,
							'months'         => $months,
							/* translators: %d: order number */
							'audit_note'     => sprintf( __( 'Order #%d', 'subtleforms-license-server' ), $order_id ),
							'audit_source'   => 'order',
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
	 * A refunded or cancelled order takes back what it gave (LS-03): keys it
	 * issued are suspended, a renewal's added period is removed and an
	 * upgrade goes back to the previous plan and site count.
	 *
	 * @param int $order_id Order ID.
	 */
	public function suspend_for_order( $order_id ): void {
		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'yes' === $order->get_meta( '_sfls_reversed' ) ) {
			return;
		}
		$reversed = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$ids = array_filter( array_map( 'intval', explode( ',', (string) $item->get_meta( '_sfls_license_ids' ) ) ) );
			foreach ( $ids as $id ) {
				$lic = SFLS_Licenses::find( $id );
				if ( ! $lic ) {
					continue;
				}
				/* translators: %d: order number */
				$note = sprintf( __( 'Order #%d refunded or cancelled', 'subtleforms-license-server' ), $order_id );
				if ( (int) $lic['order_id'] === $order_id ) {
					SFLS_Audit::label( 'refund', $note, 'order' );
					SFLS_Licenses::update( $id, array( 'status' => 'suspended' ) );
					$reversed = true;
					continue;
				}
				$before_upgrade = json_decode( (string) $item->get_meta( '_sfls_upgrade_before' ), true );
				if ( is_array( $before_upgrade ) && ! empty( $before_upgrade['plan'] ) ) {
					$data = array(
						'plan'          => sanitize_key( (string) $before_upgrade['plan'] ),
						'sites_allowed' => (int) ( $before_upgrade['sites_allowed'] ?? ( SFLS_Plans::get( (string) $before_upgrade['plan'] )['sites'] ?? 1 ) ),
						'product_id'    => (int) ( $before_upgrade['product_id'] ?? 0 ),
					);
					if ( array_key_exists( 'expires_at', $before_upgrade ) ) {
						$data['expires_at'] = $before_upgrade['expires_at'];
					}
					SFLS_Audit::label( 'refund', $note, 'order' );
					SFLS_Licenses::update( $id, $data );
					$order->add_order_note(
						sprintf(
							/* translators: 1: masked key, 2: plan */
							__( 'SubtleForms license %1$s moved back to %2$s.', 'subtleforms-license-server' ),
							SFLS_Licenses::mask_key( $lic['license_key'] ),
							SFLS_Plans::label( (string) $before_upgrade['plan'] )
						)
					);
					$reversed = true;
				}
				if ( (int) $item->get_meta( '_sfls_renew_license' ) || 'yes' === get_post_meta( (int) $item->get_product_id(), '_sfls_renewal', true ) ) {
					$before = (string) $item->get_meta( '_sfls_renew_before' );
					if ( '' === $before && ! empty( $lic['expires_at'] ) ) {
						// Renewal from before 1.7.0: take the product's months off the expiry.
						$months = (string) get_post_meta( (int) $item->get_product_id(), '_sfls_months', true );
						$months = '' === $months ? 12 : (int) $months;
						$before = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months", strtotime( $lic['expires_at'] . ' UTC' ) ) );
					}
					if ( '' !== $before ) {
						$current = SFLS_Licenses::find( $id );
						$data    = array( 'expires_at' => $before );
						if ( $current && ! empty( $current['expires_at'] ) && strtotime( $before . ' UTC' ) < time() && 'active' === $current['status'] ) {
							$data['reminders'] = '';
						}
						SFLS_Audit::label( 'refund', $note, 'order' );
						SFLS_Licenses::update( $id, $data );
						$order->add_order_note(
							sprintf(
								/* translators: 1: masked key, 2: date */
								__( 'SubtleForms license %1$s: the renewal period was removed, it now expires on %2$s.', 'subtleforms-license-server' ),
								SFLS_Licenses::mask_key( $lic['license_key'] ),
								wp_date( get_option( 'date_format' ), strtotime( $before . ' UTC' ) )
							)
						);
						$reversed = true;
					}
				}
			}
		}
		if ( $reversed ) {
			$order->update_meta_data( '_sfls_reversed', 'yes' );
			$order->save_meta_data();
			do_action( 'sfls_order_reversed', $order_id );
		}
	}

	/**
	 * A partial refund: Licenses › Settings decides (default: add a note and
	 * leave the license alone; or suspend as for a full refund; or ignore).
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function partial_refund( $order_id, $refund_id = 0 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || 0 === self::order_mix( $order )['license'] ) {
			return;
		}
		$mode = (string) SFLS_Settings::get( 'partial_refund' );
		if ( 'suspend' === $mode ) {
			$this->suspend_for_order( (int) $order_id );
			return;
		}
		if ( 'ignore' === $mode ) {
			return;
		}
		$refund = $refund_id ? wc_get_order( (int) $refund_id ) : null;
		$amount = $refund instanceof WC_Order_Refund ? wc_price( (float) $refund->get_amount(), array( 'currency' => $order->get_currency() ) ) : '';
		$keys   = array_map( static fn( $lic ) => SFLS_Licenses::mask_key( $lic['license_key'] ), self::order_licenses( (int) $order_id ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: keys */
				__( 'Partial refund of %1$s. The licenses (%2$s) were left unchanged; suspend them from Licenses if needed.', 'subtleforms-license-server' ),
				html_entity_decode( wp_strip_all_tags( (string) $amount ), ENT_QUOTES, 'UTF-8' ),
				implode( ', ', $keys )
			)
		);
		foreach ( self::order_licenses( (int) $order_id ) as $lic ) {
			/* translators: %d: order number */
			SFLS_Audit::record( (int) $lic['id'], 'partial_refund', array(), array(), sprintf( __( 'Order #%d partially refunded, license unchanged.', 'subtleforms-license-server' ), (int) $order_id ), 'order' );
		}
	}

	/**
	 * A refunded order set back to Completed brings its keys back (their
	 * status was refunded by suspend_for_order()).
	 *
	 * @param int $order_id Order ID.
	 */
	public function reactivate_for_order( $order_id ): void {
		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( '_sfls_reversed' ) ) {
			return;
		}
		foreach ( self::order_licenses( $order_id ) as $lic ) {
			if ( (int) $lic['order_id'] === $order_id && in_array( $lic['status'], array( 'suspended', 'refunded' ), true ) ) {
				/* translators: %d: order number */
				SFLS_Audit::label( 'refund_reversed', sprintf( __( 'Order #%d completed again', 'subtleforms-license-server' ), $order_id ), 'order' );
				SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'active' ) );
			}
		}
		$order->update_meta_data( '_sfls_reversed', 'no' );
		$order->save_meta_data();
	}

	/**
	 * A license this customer could renew: same plan, issued earlier.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   Billing email.
	 * @param string $plan    Plan.
	 */
	private function renewable_license( int $user_id, string $email, string $plan ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE plan = %s AND status <> 'suspended' AND ( ( user_id > 0 AND user_id = %d ) OR customer_email = %s ) AND expires_at IS NOT NULL ORDER BY expires_at ASC LIMIT 1", $plan, $user_id, $email ), ARRAY_A );
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
