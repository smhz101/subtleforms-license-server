<?php
/**
 * WooCommerce: sell licenses, renew and upgrade them, deliver keys.
 *
 * Mark a product as a SubtleForms license in Product data › General (or let
 * Licenses › Products create the products). When an order with it is
 * completed, one key per quantity is issued, saved on the order and added to
 * the customer's emails. Refunded or cancelled orders suspend their keys.
 *
 * Renewal products extend a key instead of issuing one. When the renewal was
 * started from My Account › Licenses, the cart item carries
 * `sfls_renew_license` and exactly that key is extended. An upgrade carries
 * `sfls_upgrade_license`: the existing key moves to the bought plan.
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

		// Issue / suspend.
		add_action( 'woocommerce_order_status_completed', array( $this, 'issue_for_order' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_issue_virtual' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'suspend_for_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'suspend_for_order' ) );

		// Cart: buy, renew and upgrade links.
		add_action( 'template_redirect', array( $this, 'handle_cart_links' ), 5 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line_item' ), 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( $this, 'order_meta_label' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'order_meta_value' ), 10, 2 );

		// Delivery.
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_keys' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_keys' ) );
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
			$product = wc_get_product( $buy );
			if ( $product && $product->is_purchasable() && 'yes' === $product->get_meta( '_sfls_enabled' ) ) {
				$this->put_in_cart( $buy, array() );
			}
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
	 * Add a license product, replacing other cart lines for the same product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $data       Cart item data.
	 */
	private function put_in_cart( int $product_id, array $data ): void {
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( (int) $item['product_id'] === $product_id ) {
				$same = ( $item['sfls_renew_license'] ?? 0 ) === ( $data['sfls_renew_license'] ?? 0 )
					&& ( $item['sfls_upgrade_license'] ?? 0 ) === ( $data['sfls_upgrade_license'] ?? 0 );
				if ( $same ) {
					return; // Already there.
				}
				WC()->cart->remove_cart_item( $key );
			}
		}
		try {
			WC()->cart->add_to_cart( $product_id, 1, 0, array(), $data );
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
		}
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

	// ── Issue / suspend ─────────────────────────────────────────────────

	/**
	 * Virtual-only orders often stop at "processing"; issue keys there too.
	 *
	 * @param int $order_id Order ID.
	 */
	public function maybe_issue_virtual( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && ! $order->needs_processing() ) {
			$this->issue_for_order( $order_id );
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
	 * Issue, renew or upgrade keys for an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function issue_for_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = (int) $item->get_product_id();
			if ( 'yes' !== get_post_meta( $product_id, '_sfls_enabled', true ) ) {
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
				$this->apply_upgrade( $upgrade, $plan, $sites, $months, $product_id );
				$ids[] = (int) $upgrade['id'];
				/* translators: 1: masked key, 2: plan */
				$order->add_order_note( sprintf( __( 'SubtleForms license %1$s upgraded to %2$s.', 'subtleforms-license-server' ), SFLS_Licenses::mask_key( $upgrade['license_key'] ), SFLS_Plans::label( $plan ) ) );
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
				SFLS_Licenses::extend( (int) $renewal['id'], max( 1, $months ) );
				SFLS_Licenses::update( (int) $renewal['id'], array( 'reminders' => '' ) );
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
	}

	/**
	 * Move a license to a bigger plan. Expiry is kept, or pushed out to a full
	 * term from today when that is later. A lifetime purchase makes it lifetime.
	 *
	 * @param array  $lic        License.
	 * @param string $plan       New plan.
	 * @param string $sites      Product site limit ('' = plan default).
	 * @param int    $months     Product duration (0 = lifetime).
	 * @param int    $product_id Product ID.
	 */
	private function apply_upgrade( array $lic, string $plan, string $sites, int $months, int $product_id ): void {
		$limit = '' === $sites ? (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ) : (int) $sites;
		$data  = array(
			'plan'          => $plan,
			'sites_allowed' => $limit,
			'status'        => 'active',
			'product_id'    => $product_id,
		);
		if ( 0 === $months ) {
			$data['expires_at'] = null;
		} elseif ( ! empty( $lic['expires_at'] ) ) {
			$current            = strtotime( $lic['expires_at'] . ' UTC' );
			$term               = strtotime( "+{$months} months" );
			$data['expires_at'] = gmdate( 'Y-m-d H:i:s', max( $current, $term ) );
			$data['reminders']  = '';
		}
		SFLS_Licenses::update( (int) $lic['id'], $data );
	}

	/**
	 * Suspend keys issued by an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function suspend_for_order( int $order_id ): void {
		foreach ( $this->order_licenses( $order_id ) as $lic ) {
			if ( (int) $lic['order_id'] === $order_id ) {
				SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'suspended' ) );
			}
		}
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
	private function order_licenses( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}
		$out = array();
		foreach ( $order->get_items() as $item ) {
			foreach ( array_filter( explode( ',', (string) $item->get_meta( '_sfls_license_ids' ) ) ) as $id ) {
				$lic = SFLS_Licenses::find( (int) $id );
				if ( $lic ) {
					$out[] = $lic;
				}
			}
		}
		return $out;
	}

	// ── Delivery ────────────────────────────────────────────────────────

	/**
	 * Keys in order emails.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Admin email.
	 * @param bool     $plain_text    Plain text email.
	 * @param mixed    $email         Email object.
	 */
	public function email_keys( $order, $sent_to_admin, $plain_text, $email = null ): void {
		$licenses = $this->order_licenses( $order->get_id() );
		if ( ! $licenses ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Your SubtleForms Pro license', 'subtleforms-license-server' ) . "\n";
			foreach ( $licenses as $lic ) {
				echo esc_html( $lic['license_key'] . ' (' . SFLS_Plans::label( $lic['plan'] ) . ')' ) . "\n";
			}
			echo esc_html__( 'Activate it in WordPress: SubtleForms › Settings › License.', 'subtleforms-license-server' ) . "\n\n";
			return;
		}
		$this->render_keys( $licenses, true );
	}

	/**
	 * Keys on the thank-you / order page.
	 *
	 * @param WC_Order $order Order.
	 */
	public function order_keys( $order ): void {
		$licenses = $this->order_licenses( $order->get_id() );
		if ( $licenses ) {
			$this->render_keys( $licenses, false );
		}
	}

	/**
	 * Print keys.
	 *
	 * @param array[] $licenses Licenses.
	 * @param bool    $email    Inline styles for email.
	 */
	private function render_keys( array $licenses, bool $email ): void {
		$style = $email ? 'font-family:monospace;font-size:15px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;display:inline-block;' : '';
		echo '<h2>' . esc_html__( 'Your SubtleForms Pro license', 'subtleforms-license-server' ) . '</h2>';
		foreach ( $licenses as $lic ) {
			echo '<p><code class="sfls-key" style="' . esc_attr( $style ) . '">' . esc_html( $lic['license_key'] ) . '</code><br><small>' . esc_html(
				sprintf(
					/* translators: 1: plan, 2: expiry date */
					__( '%1$s plan · renews %2$s', 'subtleforms-license-server' ),
					SFLS_Plans::label( $lic['plan'] ),
					$lic['expires_at'] ? wp_date( get_option( 'date_format' ), strtotime( $lic['expires_at'] . ' UTC' ) ) : __( 'never', 'subtleforms-license-server' )
				)
			) . '</small></p>';
		}
		echo '<p>' . esc_html__( 'In WordPress, open SubtleForms › Settings › License and click Connect account, or paste the key and click Activate.', 'subtleforms-license-server' ) . '</p>';
	}
}
