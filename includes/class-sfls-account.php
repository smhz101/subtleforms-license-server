<?php
/**
 * Members area: My Account › Licenses, My Account › Pro downloads and a
 * summary on the account dashboard.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce My Account screens.
 */
final class SFLS_Account {

	const EP_LICENSES  = 'licenses';
	const EP_DOWNLOADS = 'pro-downloads';

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
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ) );
		add_filter( 'woocommerce_endpoint_' . self::EP_LICENSES . '_title', array( $this, 'title_licenses' ) );
		add_filter( 'woocommerce_endpoint_' . self::EP_DOWNLOADS . '_title', array( $this, 'title_downloads' ) );
		add_action( 'woocommerce_account_' . self::EP_LICENSES . '_endpoint', array( $this, 'licenses_page' ) );
		add_action( 'woocommerce_account_' . self::EP_DOWNLOADS . '_endpoint', array( $this, 'downloads_page' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'dashboard' ) );
		add_action( 'template_redirect', array( $this, 'handle_deactivate' ) );
		add_action( 'template_redirect', array( $this, 'handle_download' ) );
		add_action( 'template_redirect', array( $this, 'handle_billing' ) );
	}

	/**
	 * Register the endpoints with WooCommerce.
	 *
	 * @param array $vars Endpoint => slug.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[ self::EP_LICENSES ]  = self::EP_LICENSES;
		$vars[ self::EP_DOWNLOADS ] = self::EP_DOWNLOADS;
		return $vars;
	}

	/**
	 * Menu entries after Orders.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function menu_items( $items ) {
		if ( SFLS_Settings::simplify_account() && is_user_logged_in() && self::license_only_customer( get_current_user_id() ) ) {
			unset( $items['edit-address'], $items['downloads'] );
		}
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::EP_LICENSES ]  = __( 'Licenses', 'subtleforms-license-server' );
				$new[ self::EP_DOWNLOADS ] = __( 'Pro downloads', 'subtleforms-license-server' );
			}
		}
		if ( ! isset( $new[ self::EP_LICENSES ] ) ) {
			$logout = $new['customer-logout'] ?? null;
			unset( $new['customer-logout'] );
			$new[ self::EP_LICENSES ]  = __( 'Licenses', 'subtleforms-license-server' );
			$new[ self::EP_DOWNLOADS ] = __( 'Pro downloads', 'subtleforms-license-server' );
			if ( $logout ) {
				$new['customer-logout'] = $logout;
			}
		}
		return $new;
	}

	/**
	 * Licenses endpoint title.
	 */
	public function title_licenses(): string {
		return __( 'Licenses', 'subtleforms-license-server' );
	}

	/**
	 * Downloads endpoint title.
	 */
	public function title_downloads(): string {
		return __( 'Pro downloads', 'subtleforms-license-server' );
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Whether every order of a customer contains only license products (true
	 * for customers without orders). Addresses and WooCommerce downloads mean
	 * nothing to them, so those tabs are hidden.
	 *
	 * @param int $user_id User ID.
	 */
	public static function license_only_customer( int $user_id ): bool {
		static $cache = array();
		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}
		$only   = true;
		$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 200,
				'status'      => array_keys( wc_get_order_statuses() ),
				'return'      => 'objects',
			)
		) : array();
		foreach ( (array) $orders as $order ) {
			if ( $order instanceof WC_Order && SFLS_Woo::order_mix( $order )['other'] > 0 ) {
				$only = false;
				break;
			}
		}
		$cache[ $user_id ] = (bool) apply_filters( 'sfls_license_only_customer', $only, $user_id );
		return $cache[ $user_id ];
	}

	/**
	 * Whether the user has at least one license that may download Pro.
	 *
	 * @param WP_User $user User.
	 */
	public static function can_download( WP_User $user ): bool {
		foreach ( SFLS_Licenses::for_user( $user ) as $lic ) {
			if ( SFLS_Licenses::state( $lic )['valid'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Latest Pro version for display, "Coming soon" before the first release.
	 */
	public static function latest_version_label(): string {
		$version = (string) SFLS_Releases::current()['version'];
		return '' !== $version ? $version : __( 'Coming soon', 'subtleforms-license-server' );
	}

	/**
	 * Nonce-protected download link for a release.
	 *
	 * @param string $version Version, or 'latest'.
	 */
	public static function download_url( string $version = 'latest' ): string {
		return wp_nonce_url( add_query_arg( 'sfls_download', rawurlencode( $version ), wc_get_account_endpoint_url( self::EP_DOWNLOADS ) ), 'sfls_download' );
	}

	/**
	 * Small inline SVG icon.
	 *
	 * @param string $name Icon name.
	 */
	private static function icon( string $name ): string {
		$paths = array(
			'download' => '<path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>',
			'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 012-2h9"/>',
			'refresh'  => '<path d="M20 11a8 8 0 10-2.3 5.7M20 4v7h-7"/>',
			'up'       => '<path d="M12 19V5m0 0l-6 6m6-6l6 6"/>',
			'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.8 2.5 15.2 0 18M12 3c-2.5 2.8-2.5 15.2 0 18"/>',
		);
		return '<svg class="sfls-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	/**
	 * Allowed HTML for icons.
	 */
	private static function svg_kses(): array {
		$common = array(
			'd'            => true,
			'x'            => true,
			'y'            => true,
			'cx'           => true,
			'cy'           => true,
			'r'            => true,
			'rx'           => true,
			'width'        => true,
			'height'       => true,
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
		);
		return array(
			'svg'    => $common + array(
				'class'           => true,
				'viewbox'         => true,
				'aria-hidden'     => true,
				'focusable'       => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
			'path'   => $common,
			'rect'   => $common,
			'circle' => $common,
		);
	}

	/**
	 * Print an icon.
	 *
	 * @param string $name Icon name.
	 */
	private static function print_icon( string $name ): void {
		echo wp_kses( self::icon( $name ), self::svg_kses() );
	}

	// ── Licenses ────────────────────────────────────────────────────────

	/**
	 * My Account › Licenses.
	 */
	public function licenses_page(): void {
		SFLS_Assets::enqueue();
		$user     = wp_get_current_user();
		$licenses = SFLS_Licenses::for_user( $user );
		$release  = SFLS_Releases::current();

		echo '<div class="sfls sfls-account">';
		if ( ! $licenses ) {
			echo '<div class="sfls-card sfls-empty-card"><h3>' . esc_html__( 'No licenses yet', 'subtleforms-license-server' ) . '</h3><p>' . esc_html__( 'Buy SubtleForms Pro and your license key shows up here, ready to connect to your sites.', 'subtleforms-license-server' ) . '</p><p><a class="sfls-btn sfls-btn--primary" href="' . esc_url( SFLS_Settings::pricing_url() ) . '">' . esc_html__( 'See plans', 'subtleforms-license-server' ) . '</a></p></div></div>';
			return;
		}

		echo '<p class="sfls-intro">' . esc_html__( 'Connect a site from SubtleForms › Settings › License in WordPress, or paste the key there. Local and staging sites never count towards your limit.', 'subtleforms-license-server' ) . '</p>';

		$any_valid = false;
		foreach ( $licenses as $lic ) {
			$this->license_card( $lic, $release );
			$any_valid = $any_valid || SFLS_Licenses::state( $lic )['valid'];
		}

		if ( $any_valid ) {
			echo '<article class="sfls-card sfls-install-card">';
			echo wp_kses_post( SFLS_Delivery::steps_html() );
			if ( ! SFLS_Releases::has_package() ) {
				echo '<p class="sfls-muted">' . esc_html__( 'The Pro download appears here as soon as the first release is published.', 'subtleforms-license-server' ) . '</p>';
			}
			echo '</article>';
		}
		echo '</div>';
	}

	/**
	 * One license card.
	 *
	 * @param array $lic     License.
	 * @param array $release Current release.
	 */
	private function license_card( array $lic, array $release ): void {
		$state    = SFLS_Licenses::state( $lic );
		$acts     = SFLS_Licenses::activations( (int) $lic['id'] );
		$renew    = SFLS_Products::renew_url( $lic );
		$upgrades = 'suspended' === $state['status'] ? array() : SFLS_Products::upgrade_options( $lic );
		$date_fmt = get_option( 'date_format' );
		$paddle   = SFLS_Licenses::is_paddle( $lic );

		echo '<article class="sfls-card sfls-license" id="license-' . esc_attr( (string) $lic['id'] ) . '">';

		echo '<header class="sfls-license-head"><div class="sfls-license-title"><h3>' . esc_html( SFLS_Plans::label( $lic['plan'] ) ) . '</h3>';
		echo wp_kses_post( SFLS_Connect::pill( $state['status'] ) );
		if ( $paddle && ! empty( $lic['trial'] ) && $state['valid'] ) {
			echo '<span class="sfls-tag sfls-tag--trial">' . esc_html__( 'Free trial', 'subtleforms-license-server' ) . '</span>';
		}
		echo '</div><div class="sfls-license-actions">';
		if ( $state['valid'] && SFLS_Releases::has_package() ) {
			echo '<a class="sfls-btn sfls-btn--primary sfls-btn--sm" href="' . esc_url( self::download_url() ) . '">';
			self::print_icon( 'download' );
			echo esc_html__( 'Download Pro', 'subtleforms-license-server' ) . '</a>';
		}
		if ( $renew && 'suspended' !== $state['status'] ) {
			echo '<a class="sfls-btn sfls-btn--' . ( 'valid' === $state['status'] ? 'secondary' : 'primary' ) . ' sfls-btn--sm" href="' . esc_url( $renew ) . '">';
			self::print_icon( 'refresh' );
			echo esc_html__( 'Renew', 'subtleforms-license-server' ) . '</a>';
		}
		foreach ( $upgrades as $plan => $row ) {
			$quote = SFLS_Upgrades::quote( $lic, (int) $row['id'] );
			$title = $quote ? sprintf(
				/* translators: 1: plan name, 2: price */
				__( 'Upgrade to %1$s for %2$s today. Same key, more sites.', 'subtleforms-license-server' ),
				SFLS_Plans::label( $plan ),
				SFLS_Upgrades::plain_price( $quote['charge'] )
			) : '';
			echo '<a class="sfls-btn sfls-btn--secondary sfls-btn--sm" href="' . esc_url( SFLS_Products::upgrade_url( $lic, $plan ) ) . '" title="' . esc_attr( $title ) . '">';
			self::print_icon( 'up' );
			/* translators: %s: plan name */
			echo esc_html( sprintf( __( 'Upgrade to %s', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) ) );
			if ( $quote ) {
				echo ' <span class="sfls-btn-note">' . esc_html( SFLS_Upgrades::plain_price( $quote['charge'] ) ) . '</span>';
			}
			echo '</a>';
		}
		if ( $paddle && $state['valid'] ) {
			foreach ( self::paddle_upgrades( $lic ) as $plan ) {
				echo '<form method="post" class="sfls-inline-form" action="' . esc_url( wc_get_account_endpoint_url( self::EP_LICENSES ) ) . '">';
				self::billing_fields( $lic, 'change_plan' );
				echo '<input type="hidden" name="plan" value="' . esc_attr( $plan ) . '">';
				/* translators: 1: plan name, 2: sites */
				$confirm = sprintf( __( 'Upgrade to %1$s (%2$s)? You pay the prorated difference for the rest of this billing year now, and the new limits apply right away. Same key.', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ), strtolower( SFLS_Plans::sites_label( (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ) ) ) );
				if ( ! empty( $lic['trial'] ) ) {
					/* translators: %s: plan name */
					$confirm = sprintf( __( 'Switch your trial to %s? Nothing is charged now. When the trial ends you are billed for the new plan.', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) );
				}
				echo '<button type="submit" class="sfls-btn sfls-btn--secondary sfls-btn--sm" data-sfls-confirm="' . esc_attr( $confirm ) . '">';
				self::print_icon( 'up' );
				/* translators: %s: plan name */
				echo esc_html( sprintf( __( 'Upgrade to %s', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) ) ) . '</button></form>';
			}
		}
		echo '</div></header>';

		if ( $paddle ) {
			$this->billing_panel( $lic, $state );
		}

		if ( $paddle ) {
			// The billing panel explains a live subscription; only say when it is over.
			if ( ! $state['valid'] ) {
				echo '<div class="sfls-alert sfls-alert--error">' . esc_html__( 'This subscription has ended. Pick a plan to turn Pro back on.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( SFLS_Settings::pricing_url() ) . '">' . esc_html__( 'See plans', 'subtleforms-license-server' ) . '</a></div>';
			}
		} elseif ( 'grace_period' === $state['status'] ) {
			echo '<div class="sfls-alert sfls-alert--warn">' . esc_html__( 'This license has expired. Pro keeps working for a short grace period. Renew now to keep updates and support.', 'subtleforms-license-server' ) . '</div>';
		} elseif ( 'expired' === $state['status'] ) {
			echo '<div class="sfls-alert sfls-alert--error">' . esc_html__( 'This license has expired. Renew it to turn Pro features and updates back on.', 'subtleforms-license-server' ) . '</div>';
		} elseif ( 'suspended' === $state['status'] ) {
			echo '<div class="sfls-alert sfls-alert--error">' . esc_html__( 'This license is suspended. Contact support if you think this is a mistake.', 'subtleforms-license-server' ) . '</div>';
		}

		echo '<div class="sfls-keyrow"><code class="sfls-key">' . esc_html( $lic['license_key'] ) . '</code>';
		echo '<button type="button" class="sfls-btn sfls-btn--ghost sfls-btn--sm sfls-copy" data-sfls-copy="' . esc_attr( $lic['license_key'] ) . '" data-sfls-copied="' . esc_attr__( 'Copied', 'subtleforms-license-server' ) . '">';
		self::print_icon( 'copy' );
		echo '<span class="sfls-copy-label">' . esc_html__( 'Copy', 'subtleforms-license-server' ) . '</span></button></div>';

		$limit = (int) $lic['sites_allowed'];
		echo '<dl class="sfls-stats">';
		if ( $paddle ) {
			[ $term_label, $term_value ] = self::paddle_term( $lic, $state );
			echo '<div><dt>' . esc_html( $term_label ) . '</dt><dd>' . esc_html( $term_value ) . '</dd></div>';
		} else {
			echo '<div><dt>' . esc_html( empty( $lic['expires_at'] ) ? __( 'Term', 'subtleforms-license-server' ) : ( $state['valid'] && 'valid' === $state['status'] ? __( 'Renews', 'subtleforms-license-server' ) : __( 'Expired', 'subtleforms-license-server' ) ) ) . '</dt><dd>' . esc_html( SFLS_Licenses::expiry_label( $lic ) ) . '</dd></div>';
		}
		echo '<div><dt>' . esc_html__( 'Sites', 'subtleforms-license-server' ) . '</dt><dd>' . esc_html( SFLS_Licenses::used_slots( (int) $lic['id'] ) . ' / ' . ( $limit > 0 ? number_format_i18n( $limit ) : __( 'Unlimited', 'subtleforms-license-server' ) ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Purchased', 'subtleforms-license-server' ) . '</dt><dd>' . esc_html( wp_date( $date_fmt, strtotime( $lic['created_at'] . ' UTC' ) ) );
		if ( (int) $lic['order_id'] && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $lic['order_id'] );
			if ( $order ) {
				echo ' <a href="' . esc_url( $order->get_view_order_url() ) . '">' . esc_html( '#' . $order->get_order_number() ) . '</a>';
			}
		}
		echo '</dd></div>';
		echo '</dl>';

		echo '<div class="sfls-sites"><h4>' . esc_html__( 'Connected sites', 'subtleforms-license-server' ) . '</h4>';
		if ( ! $acts ) {
			echo '<p class="sfls-muted">' . esc_html__( 'No sites yet. In WordPress, go to SubtleForms › Settings › License and click Connect account.', 'subtleforms-license-server' ) . '</p>';
		} else {
			echo '<ul class="sfls-site-list">';
			foreach ( $acts as $act ) {
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'sfls_deactivate' => (int) $lic['id'],
							'domain'          => rawurlencode( $act['domain'] ),
						),
						wc_get_account_endpoint_url( self::EP_LICENSES )
					),
					'sfls_deactivate_' . $lic['id']
				);
				echo '<li class="sfls-site"><span class="sfls-site-icon">';
				self::print_icon( 'globe' );
				echo '</span><span class="sfls-site-main"><span class="sfls-site-domain">' . esc_html( $act['domain'] ) . '</span>';
				if ( $act['is_local'] ) {
					echo ' <span class="sfls-tag">' . esc_html__( 'Staging, free', 'subtleforms-license-server' ) . '</span>';
				}
				echo '<span class="sfls-site-meta">';
				echo esc_html(
					sprintf(
						/* translators: %s: human time difference */
						__( 'Last seen %s ago', 'subtleforms-license-server' ),
						human_time_diff( strtotime( $act['last_seen_at'] . ' UTC' ), time() )
					)
				);
				if ( $act['plugin_version'] ) {
					/* translators: %s: version */
					echo ' · ' . esc_html( sprintf( __( 'Pro %s', 'subtleforms-license-server' ), $act['plugin_version'] ) );
				}
				echo '</span></span>';
				/* translators: %s: domain */
				echo '<a class="sfls-btn sfls-btn--ghost sfls-btn--sm sfls-btn--danger" href="' . esc_url( $url ) . '" data-sfls-confirm="' . esc_attr( sprintf( __( 'Disconnect %s? Pro stops working there until it is connected again.', 'subtleforms-license-server' ), $act['domain'] ) ) . '">' . esc_html__( 'Deactivate', 'subtleforms-license-server' ) . '</a>';
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
		echo '</article>';
	}

	// ── Paddle billing ──────────────────────────────────────────────────

	/**
	 * Hidden fields of a billing action form.
	 *
	 * @param array  $lic    License.
	 * @param string $action payment | cancel | resume | change_plan.
	 */
	private static function billing_fields( array $lic, string $action ): void {
		wp_nonce_field( 'sfls_billing_' . $lic['id'], '_sfls_billing_nonce', false );
		echo '<input type="hidden" name="sfls_billing" value="' . esc_attr( $action ) . '"><input type="hidden" name="license_id" value="' . esc_attr( (string) $lic['id'] ) . '">';
	}

	/**
	 * Plans a Paddle license can move up to.
	 *
	 * @param array $lic License.
	 * @return string[]
	 */
	public static function paddle_upgrades( array $lic ): array {
		// With the WooCommerce checkout taking Paddle payments, upgrades go
		// through the cart (SFLS_Products::upgrade_options()) so each one is an order.
		if ( SFLS_Paddle::woo_selling() || ! in_array( $lic['billing_status'], array( 'active', 'trialing' ), true ) || ! SFLS_Paddle::has_api_key() ) {
			return array();
		}
		return array_values( array_filter( SFLS_Plans::higher( (string) $lic['plan'] ), static fn( $plan ) => '' !== SFLS_Paddle::plan( $plan )['price_id'] ) );
	}

	/**
	 * First stat of a Paddle license card: "Trial ends", "Renews", "Ends".
	 *
	 * @param array $lic   License.
	 * @param array $state State.
	 * @return array{0:string,1:string}
	 */
	private static function paddle_term( array $lic, array $state ): array {
		$fmt  = get_option( 'date_format' );
		$date = static fn( $gmt ) => wp_date( $fmt, strtotime( $gmt . ' UTC' ) );
		if ( ! $state['valid'] ) {
			return array( __( 'Ended', 'subtleforms-license-server' ), SFLS_Licenses::expiry_label( $lic ) );
		}
		if ( ! empty( $lic['cancels_at'] ) ) {
			return array( __( 'Ends', 'subtleforms-license-server' ), $date( $lic['cancels_at'] ) );
		}
		if ( ! empty( $lic['trial'] ) && ! empty( $lic['trial_ends_at'] ) ) {
			return array( __( 'Trial ends', 'subtleforms-license-server' ), $date( $lic['trial_ends_at'] ) );
		}
		if ( ! empty( $lic['renews_at'] ) ) {
			return array( __( 'Renews', 'subtleforms-license-server' ), $date( $lic['renews_at'] ) );
		}
		return array( __( 'Paid until', 'subtleforms-license-server' ), SFLS_Licenses::expiry_label( $lic ) );
	}

	/**
	 * Billing state and buttons of a Paddle license.
	 *
	 * @param array $lic   License.
	 * @param array $state State.
	 */
	private function billing_panel( array $lic, array $state ): void {
		$line = SFLS_Billing::state_line( $lic );
		if ( ! $line || ! $state['valid'] ) {
			return;
		}
		$status = (string) $lic['billing_status'];
		$live   = in_array( $status, array( 'active', 'trialing', 'past_due' ), true );
		$action = wc_get_account_endpoint_url( self::EP_LICENSES );

		echo '<div class="sfls-billing sfls-billing--' . esc_attr( $line[0] ) . '" data-billing-status="' . esc_attr( $status ) . '">';
		echo '<div class="sfls-billing-text"><strong>' . esc_html( $line[1] ) . '</strong>';
		if ( ! empty( $lic['trial'] ) ) {
			$full = (int) ( SFLS_Plans::get( (string) $lic['plan'] )['sites'] ?? 1 );
			echo '<span>' . esc_html(
				sprintf(
					/* translators: %s: sites of the full plan, e.g. "5 sites" */
					__( 'Trial covers 1 site. After the first payment it covers %s.', 'subtleforms-license-server' ),
					strtolower( SFLS_Plans::sites_label( $full ) )
				)
			) . '</span>';
		}
		echo '</div><div class="sfls-billing-actions">';
		if ( $live && SFLS_Paddle::has_api_key() ) {
			echo '<form method="post" class="sfls-inline-form" action="' . esc_url( $action ) . '">';
			self::billing_fields( $lic, 'payment' );
			echo '<button type="submit" class="sfls-btn sfls-btn--' . ( 'past_due' === $status ? 'primary' : 'secondary' ) . ' sfls-btn--sm">' . esc_html__( 'Update payment method', 'subtleforms-license-server' ) . '</button></form>';
			echo '<form method="post" class="sfls-inline-form" action="' . esc_url( $action ) . '">';
			if ( ! empty( $lic['cancels_at'] ) ) {
				self::billing_fields( $lic, 'resume' );
				echo '<button type="submit" class="sfls-btn sfls-btn--primary sfls-btn--sm">' . esc_html__( 'Resume', 'subtleforms-license-server' ) . '</button>';
			} else {
				self::billing_fields( $lic, 'cancel' );
				$confirm = ! empty( $lic['trial'] )
					? __( 'Cancel auto-renew? Pro keeps working until the trial ends and you will not be charged.', 'subtleforms-license-server' )
					: __( 'Cancel auto-renew? Pro keeps working until the end of the period you paid for. You can resume before then.', 'subtleforms-license-server' );
				echo '<button type="submit" class="sfls-btn sfls-btn--ghost sfls-btn--sm" data-sfls-confirm="' . esc_attr( $confirm ) . '">' . esc_html__( 'Cancel auto-renew', 'subtleforms-license-server' ) . '</button>';
			}
			echo '</form>';
		}
		echo '</div></div>';
	}

	/**
	 * Billing buttons on My Account › Licenses: Update payment method (Paddle
	 * customer portal), Cancel auto-renew, Resume and Upgrade.
	 */
	public function handle_billing(): void {
		if ( empty( $_POST['sfls_billing'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
			return;
		}
		$id    = absint( $_POST['license_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked next.
		$nonce = sanitize_text_field( wp_unslash( $_POST['_sfls_billing_nonce'] ?? '' ) );
		$back  = wc_get_account_endpoint_url( self::EP_LICENSES );
		if ( ! wp_verify_nonce( $nonce, 'sfls_billing_' . $id ) ) {
			wc_add_notice( __( 'Your session expired. Try again.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$lic = SFLS_Licenses::find( $id );
		if ( ! $lic || ! SFLS_Licenses::user_owns( $lic, wp_get_current_user() ) || ! SFLS_Licenses::is_paddle( $lic ) ) {
			wc_add_notice( __( 'That license was not found in your account.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$do     = sanitize_key( wp_unslash( $_POST['sfls_billing'] ) );
		$back  .= '#license-' . $id;
		$result = null;
		$fmt    = get_option( 'date_format' );

		switch ( $do ) {
			case 'payment':
			case 'overview':
				$url = SFLS_Billing::portal_url( $lic, $do );
				if ( is_wp_error( $url ) ) {
					$result = $url;
					break;
				}
				$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
				$allow = static function ( $hosts ) use ( $host ) {
					if ( str_ends_with( $host, '.paddle.com' ) ) {
						$hosts[] = $host;
					}
					return $hosts;
				};
				add_filter( 'allowed_redirect_hosts', $allow );
				wp_safe_redirect( $url, 303, 'SubtleForms' );
				remove_filter( 'allowed_redirect_hosts', $allow );
				exit;
			case 'cancel':
				$result = SFLS_Billing::cancel_auto_renew( $lic );
				if ( ! is_wp_error( $result ) ) {
					$until = ! empty( $result['cancels_at'] ) ? wp_date( $fmt, strtotime( $result['cancels_at'] . ' UTC' ) ) : SFLS_Licenses::expiry_label( $result );
					/* translators: %s: date */
					wc_add_notice( sprintf( __( 'Auto-renew is off. Pro keeps working until %s, and you can resume before then.', 'subtleforms-license-server' ), $until ) );
				}
				break;
			case 'resume':
				$result = SFLS_Billing::resume( $lic );
				if ( ! is_wp_error( $result ) ) {
					wc_add_notice( __( 'Auto-renew is back on. Nothing else changes.', 'subtleforms-license-server' ) );
				}
				break;
			case 'change_plan':
				$plan = sanitize_key( wp_unslash( $_POST['plan'] ?? '' ) );
				if ( ! in_array( $plan, self::paddle_upgrades( $lic ), true ) ) {
					$result = new WP_Error( 'sfls_plan', __( 'That plan is not available as an upgrade.', 'subtleforms-license-server' ) );
					break;
				}
				$result = SFLS_Billing::change_plan( $lic, $plan );
				if ( ! is_wp_error( $result ) ) {
					wc_add_notice(
						sprintf(
							/* translators: 1: plan, 2: sites */
							__( 'Upgraded to %1$s. Same key, now %2$s.', 'subtleforms-license-server' ),
							SFLS_Plans::label( $plan ),
							strtolower( SFLS_Plans::sites_label( (int) $result['sites_allowed'] ) )
						)
					);
				}
				break;
		}
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Deactivate a site from My Account.
	 */
	public function handle_deactivate(): void {
		if ( empty( $_GET['sfls_deactivate'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
			return;
		}
		$id = absint( $_GET['sfls_deactivate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		check_admin_referer( 'sfls_deactivate_' . $id );
		$lic  = SFLS_Licenses::find( $id );
		$back = wc_get_account_endpoint_url( self::EP_LICENSES );
		if ( ! $lic || ! SFLS_Licenses::user_owns( $lic, wp_get_current_user() ) ) {
			wc_add_notice( __( 'That license was not found in your account.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$domain = SFLS_Licenses::normalize_domain( sanitize_text_field( wp_unslash( $_GET['domain'] ?? '' ) ) );
		SFLS_Licenses::remove_activation( $id, $domain, 'account' );
		/* translators: %s: domain */
		wc_add_notice( sprintf( __( '%s was disconnected. You can use the key on another site now.', 'subtleforms-license-server' ), $domain ) );
		wp_safe_redirect( $back . '#license-' . $id );
		exit;
	}

	// ── Downloads ───────────────────────────────────────────────────────

	/**
	 * My Account › Pro downloads.
	 */
	public function downloads_page(): void {
		SFLS_Assets::enqueue();
		$user     = wp_get_current_user();
		$allowed  = self::can_download( $user );
		$releases = SFLS_Releases::all();
		$latest   = $releases[0] ?? null;

		echo '<div class="sfls sfls-account">';

		if ( ! $latest ) {
			echo '<div class="sfls-card sfls-empty-card"><h3>' . esc_html__( 'No releases yet', 'subtleforms-license-server' ) . '</h3><p>' . esc_html__( 'The first SubtleForms Pro release will be available here.', 'subtleforms-license-server' ) . '</p></div></div>';
			return;
		}

		if ( ! $allowed ) {
			echo '<div class="sfls-alert sfls-alert--warn">' . esc_html__( 'Downloads need an active license.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( SFLS_Settings::pricing_url() ) . '">' . esc_html__( 'See plans', 'subtleforms-license-server' ) . '</a></div>';
		}

		echo '<article class="sfls-card sfls-release">';
		echo '<header class="sfls-release-head"><div><span class="sfls-eyebrow">' . esc_html__( 'Latest release', 'subtleforms-license-server' ) . '</span>';
		/* translators: %s: version */
		echo '<h3>' . esc_html( sprintf( __( 'SubtleForms Pro %s', 'subtleforms-license-server' ), $latest['version'] ) ) . '</h3>';
		$meta = array_filter(
			array(
				SFLS_Releases::date_label( $latest ),
				/* translators: %s: WordPress version */
				$latest['requires'] ? sprintf( __( 'WordPress %s+', 'subtleforms-license-server' ), $latest['requires'] ) : '',
				/* translators: %s: PHP version */
				$latest['requires_php'] ? sprintf( __( 'PHP %s+', 'subtleforms-license-server' ), $latest['requires_php'] ) : '',
			)
		);
		echo '<p class="sfls-muted">' . esc_html( implode( ' · ', $meta ) ) . '</p></div>';
		if ( $allowed && SFLS_Releases::has_package( $latest ) ) {
			echo '<a class="sfls-btn sfls-btn--primary" href="' . esc_url( self::download_url( $latest['version'] ) ) . '">';
			self::print_icon( 'download' );
			echo esc_html__( 'Download zip', 'subtleforms-license-server' ) . '</a>';
		}
		echo '</header>';

		$log = SFLS_Releases::changelog_html( $latest );
		if ( '' !== $log ) {
			echo '<div class="sfls-changelog"><h4>' . esc_html__( 'What is new', 'subtleforms-license-server' ) . '</h4>' . wp_kses_post( $log ) . '</div>';
		}
		echo '<p class="sfls-muted sfls-install-hint">' . esc_html__( 'Install it in WordPress from Plugins › Add New › Upload Plugin. Connected sites also get updates automatically in Dashboard › Updates.', 'subtleforms-license-server' ) . '</p>';
		echo '</article>';

		$older = array_slice( $releases, 1 );
		if ( $older ) {
			echo '<article class="sfls-card"><h4 class="sfls-card-title">' . esc_html__( 'Older versions', 'subtleforms-license-server' ) . '</h4><ul class="sfls-version-list">';
			foreach ( $older as $rel ) {
				echo '<li><span class="sfls-version">' . esc_html( $rel['version'] ) . '</span><span class="sfls-muted">' . esc_html( SFLS_Releases::date_label( $rel ) ) . '</span>';
				if ( $allowed && SFLS_Releases::has_package( $rel ) ) {
					echo '<a class="sfls-btn sfls-btn--ghost sfls-btn--sm" href="' . esc_url( self::download_url( $rel['version'] ) ) . '">';
					self::print_icon( 'download' );
					echo esc_html__( 'Download', 'subtleforms-license-server' ) . '</a>';
				} else {
					echo '<span class="sfls-muted">' . esc_html__( 'Not available', 'subtleforms-license-server' ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul></article>';
		}
		echo '</div>';
	}

	/**
	 * Stream a package to a customer with a valid license.
	 */
	public function handle_download(): void {
		if ( ! isset( $_GET['sfls_download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}
		check_admin_referer( 'sfls_download' );
		if ( ! self::can_download( wp_get_current_user() ) ) {
			wc_add_notice( __( 'Downloads need an active license.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( self::EP_DOWNLOADS ) );
			exit;
		}
		$version = sanitize_text_field( wp_unslash( $_GET['sfls_download'] ) );
		$release = 'latest' === $version ? SFLS_Releases::current() : SFLS_Releases::find( $version );
		if ( ! $release || ! SFLS_Releases::has_package( $release ) ) {
			wc_add_notice( __( 'That version is not available for download.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( self::EP_DOWNLOADS ) );
			exit;
		}
		SFLS_Releases::stream( $release );
	}

	// ── Dashboard ───────────────────────────────────────────────────────

	/**
	 * Summary on My Account › Dashboard.
	 */
	public function dashboard(): void {
		SFLS_Assets::enqueue();
		$user     = wp_get_current_user();
		$licenses = SFLS_Licenses::for_user( $user );
		$release  = SFLS_Releases::current();
		$active   = 0;
		$next     = null;
		$lifetime = false;
		foreach ( $licenses as $lic ) {
			$state = SFLS_Licenses::state( $lic );
			if ( ! $state['valid'] ) {
				continue;
			}
			++$active;
			if ( empty( $lic['expires_at'] ) ) {
				$lifetime = true;
				continue;
			}
			$ts   = strtotime( $lic['expires_at'] . ' UTC' );
			$next = null === $next ? $ts : min( $next, $ts );
		}

		echo '<section class="sfls sfls-summary" aria-label="' . esc_attr__( 'SubtleForms Pro', 'subtleforms-license-server' ) . '">';
		echo '<div class="sfls-summary-head"><h3>' . esc_html__( 'SubtleForms Pro', 'subtleforms-license-server' ) . '</h3><a href="' . esc_url( wc_get_account_endpoint_url( self::EP_LICENSES ) ) . '">' . esc_html__( 'Manage licenses', 'subtleforms-license-server' ) . ' &rarr;</a></div>';
		echo '<div class="sfls-tiles">';

		echo '<div class="sfls-tile"><span class="sfls-tile-label">' . esc_html__( 'Active licenses', 'subtleforms-license-server' ) . '</span><span class="sfls-tile-value">' . esc_html( number_format_i18n( $active ) ) . '</span></div>';

		if ( null !== $next ) {
			$value = wp_date( get_option( 'date_format' ), $next );
		} elseif ( $lifetime ) {
			$value = __( 'Lifetime', 'subtleforms-license-server' );
		} else {
			$value = __( 'None', 'subtleforms-license-server' );
		}
		echo '<div class="sfls-tile"><span class="sfls-tile-label">' . esc_html__( 'Next renewal', 'subtleforms-license-server' ) . '</span><span class="sfls-tile-value">' . esc_html( $value ) . '</span></div>';

		echo '<div class="sfls-tile"><span class="sfls-tile-label">' . esc_html__( 'Latest Pro version', 'subtleforms-license-server' ) . '</span><span class="sfls-tile-value">' . esc_html( self::latest_version_label() ) . '</span>';
		if ( $active && $release['version'] && SFLS_Releases::has_package() ) {
			echo '<a class="sfls-tile-link" href="' . esc_url( self::download_url() ) . '">';
			self::print_icon( 'download' );
			echo esc_html__( 'Download', 'subtleforms-license-server' ) . '</a>';
		}
		echo '</div>';

		echo '</div>';
		if ( ! $licenses ) {
			echo '<p class="sfls-muted">' . esc_html__( 'You do not have a license yet.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( SFLS_Settings::pricing_url() ) . '">' . esc_html__( 'See plans', 'subtleforms-license-server' ) . '</a></p>';
		}
		echo '</section>';
	}
}
