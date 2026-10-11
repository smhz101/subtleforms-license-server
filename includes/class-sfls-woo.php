<?php
/**
 * WooCommerce: sell licenses, deliver keys, show them in My Account.
 *
 * Mark a product as a SubtleForms license in Product data › General. When an
 * order with it is completed, one key per quantity is issued, saved on the
 * order, added to the customer's emails and listed under My Account ›
 * Licenses. Refunded or cancelled orders suspend their keys. A product marked
 * as a renewal extends the customer's existing key for the same plan instead
 * of issuing a new one.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

final class SFLS_Woo {

	public function register(): void {
		add_action( 'init', array( $this, 'endpoint' ) );

		if ( ! class_exists( 'WooCommerce' ) && ! did_action( 'woocommerce_loaded' ) ) {
			add_action( 'woocommerce_loaded', array( $this, 'hooks' ) );
			return;
		}
		$this->hooks();
	}

	public function hooks(): void {
		// Product settings.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );

		// Issue / suspend.
		add_action( 'woocommerce_order_status_completed', array( $this, 'issue_for_order' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_issue_virtual' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'suspend_for_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'suspend_for_order' ) );

		// Delivery.
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_keys' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_keys' ) );

		// My Account.
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_licenses_endpoint', array( $this, 'account_page' ) );
		add_action( 'template_redirect', array( $this, 'handle_account_deactivate' ) );
	}

	public function endpoint(): void {
		add_rewrite_endpoint( 'licenses', EP_ROOT | EP_PAGES );
	}

	// ── Product settings ────────────────────────────────────────────────

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
	}

	// ── Issue / suspend ─────────────────────────────────────────────────

	/**
	 * Virtual-only orders often stop at "processing"; issue keys there too.
	 */
	public function maybe_issue_virtual( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && ! $order->needs_processing() ) {
			$this->issue_for_order( $order_id );
		}
	}

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

			$plan   = get_post_meta( $product_id, '_sfls_plan', true ) ?: 'pro';
			$sites  = get_post_meta( $product_id, '_sfls_sites', true );
			$months = get_post_meta( $product_id, '_sfls_months', true );
			$months = '' === $months ? 12 : (int) $months;
			$email  = $order->get_billing_email();
			$ids    = array();

			$renewal = 'yes' === get_post_meta( $product_id, '_sfls_renewal', true )
				? $this->renewable_license( (int) $order->get_customer_id(), $email, $plan )
				: null;
			if ( $renewal && 1 === (int) $item->get_quantity() ) {
				SFLS_Licenses::extend( (int) $renewal['id'], max( 1, $months ) );
				$ids[] = (int) $renewal['id'];
				$order->add_order_note( sprintf( 'SubtleForms license %s renewed.', $renewal['license_key'] ) );
			} else {
				for ( $i = 0; $i < max( 1, (int) $item->get_quantity() ); $i++ ) {
					$lic   = SFLS_Licenses::create(
						array(
							'plan'           => $plan,
							'sites_allowed'  => '' === $sites ? null : (int) $sites,
							'customer_email' => $email,
							'user_id'        => (int) $order->get_customer_id(),
							'order_id'       => $order_id,
							'product_id'     => $product_id,
							'months'         => $months,
						)
					);
					if ( '' === $sites ) {
						SFLS_Licenses::update( (int) $lic['id'], array( 'sites_allowed' => (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ) ) );
					}
					$ids[] = (int) $lic['id'];
				}
				$order->add_order_note( sprintf( '%d SubtleForms license key(s) issued.', count( $ids ) ) );
			}

			wc_update_order_item_meta( $item_id, '_sfls_license_ids', implode( ',', $ids ) );
		}
	}

	public function suspend_for_order( int $order_id ): void {
		foreach ( $this->order_licenses( $order_id ) as $lic ) {
			if ( (int) $lic['order_id'] === $order_id ) {
				SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'suspended' ) );
			}
		}
	}

	/**
	 * A license this customer could renew: same plan, issued earlier.
	 */
	private function renewable_license( int $user_id, string $email, string $plan ): ?array {
		global $wpdb;
		$table = SFLS_DB::licenses();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE plan = %s AND status <> 'suspended' AND ( ( user_id > 0 AND user_id = %d ) OR customer_email = %s ) AND expires_at IS NOT NULL ORDER BY expires_at ASC LIMIT 1", $plan, $user_id, $email ), ARRAY_A );
		return $row ?: null;
	}

	/**
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

	public function order_keys( $order ): void {
		$licenses = $this->order_licenses( $order->get_id() );
		if ( $licenses ) {
			$this->render_keys( $licenses, false );
		}
	}

	private function render_keys( array $licenses, bool $email ): void {
		$style = $email ? ' style="font-family:monospace;font-size:15px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;display:inline-block;"' : ' class="sfls-key"';
		echo '<h2>' . esc_html__( 'Your SubtleForms Pro license', 'subtleforms-license-server' ) . '</h2>';
		foreach ( $licenses as $lic ) {
			echo '<p><code' . $style . '>' . esc_html( $lic['license_key'] ) . '</code><br><small>' . esc_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $style is a fixed literal.
				sprintf(
					/* translators: 1: plan, 2: expiry date */
					__( '%1$s plan · renews %2$s', 'subtleforms-license-server' ),
					SFLS_Plans::label( $lic['plan'] ),
					$lic['expires_at'] ? wp_date( get_option( 'date_format' ), strtotime( $lic['expires_at'] . ' UTC' ) ) : __( 'never', 'subtleforms-license-server' )
				)
			) . '</small></p>';
		}
		echo '<p>' . esc_html__( 'In WordPress, open SubtleForms › Settings › License, paste the key and click Activate.', 'subtleforms-license-server' ) . '</p>';
	}

	// ── My Account ──────────────────────────────────────────────────────

	public function menu_item( array $items ): array {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new['licenses'] = __( 'Licenses', 'subtleforms-license-server' );
			}
		}
		return $new;
	}

	public function account_page(): void {
		$user = wp_get_current_user();
		global $wpdb;
		$table = SFLS_DB::licenses();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d OR customer_email = %s ORDER BY created_at DESC", $user->ID, $user->user_email ), ARRAY_A );

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'You have no licenses yet.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( home_url( '/pro/' ) ) . '">' . esc_html__( 'Get SubtleForms Pro', 'subtleforms-license-server' ) . '</a></p>';
			return;
		}

		foreach ( $rows as $lic ) {
			$state = SFLS_Licenses::state( $lic );
			$acts  = SFLS_Licenses::activations( (int) $lic['id'] );
			$limit = (int) $lic['sites_allowed'];
			echo '<section class="sfls-account-license" style="margin:0 0 24px;padding:16px;border:1px solid #dcdcde;border-radius:8px;">';
			echo '<p style="margin:0 0 8px;"><strong>' . esc_html( SFLS_Plans::label( $lic['plan'] ) ) . '</strong> &middot; ' . esc_html( ucfirst( str_replace( '_', ' ', $state['status'] ) ) ) . '</p>';
			echo '<p style="margin:0 0 8px;"><code style="font-size:15px;">' . esc_html( $lic['license_key'] ) . '</code></p>';
			echo '<p style="margin:0 0 12px;font-size:14px;">';
			printf(
				/* translators: 1: used sites, 2: allowed sites, 3: expiry */
				esc_html__( 'Sites: %1$d of %2$s · Expires: %3$s', 'subtleforms-license-server' ),
				(int) SFLS_Licenses::used_slots( (int) $lic['id'] ),
				$limit ? (int) $limit : esc_html__( 'unlimited', 'subtleforms-license-server' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$lic['expires_at'] ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $lic['expires_at'] . ' UTC' ) ) ) : esc_html__( 'never', 'subtleforms-license-server' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			echo '</p>';
			if ( $acts ) {
				echo '<table class="shop_table"><thead><tr><th>' . esc_html__( 'Site', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Activated', 'subtleforms-license-server' ) . '</th><th></th></tr></thead><tbody>';
				foreach ( $acts as $act ) {
					$url = wp_nonce_url( add_query_arg( array( 'sfls_deactivate' => (int) $lic['id'], 'domain' => rawurlencode( $act['domain'] ) ), wc_get_account_endpoint_url( 'licenses' ) ), 'sfls_deactivate_' . $lic['id'] );
					echo '<tr><td>' . esc_html( $act['domain'] ) . ( $act['is_local'] ? ' <small>(' . esc_html__( 'free: local/staging', 'subtleforms-license-server' ) . ')</small>' : '' ) . '</td><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $act['activated_at'] . ' UTC' ) ) ) . '</td><td><a href="' . esc_url( $url ) . '">' . esc_html__( 'Deactivate', 'subtleforms-license-server' ) . '</a></td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</section>';
		}
	}

	public function handle_account_deactivate(): void {
		if ( empty( $_GET['sfls_deactivate'] ) || ! is_user_logged_in() ) {
			return;
		}
		$id = absint( $_GET['sfls_deactivate'] );
		check_admin_referer( 'sfls_deactivate_' . $id );
		$lic  = SFLS_Licenses::find( $id );
		$user = wp_get_current_user();
		if ( ! $lic || ( (int) $lic['user_id'] !== (int) $user->ID && $lic['customer_email'] !== $user->user_email ) ) {
			return;
		}
		$domain = SFLS_Licenses::normalize_domain( sanitize_text_field( wp_unslash( $_GET['domain'] ?? '' ) ) );
		SFLS_Licenses::remove_activation( $id, $domain );
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Site deactivated. You can use the key on another site now.', 'subtleforms-license-server' ) );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( 'licenses' ) );
		exit;
	}
}
