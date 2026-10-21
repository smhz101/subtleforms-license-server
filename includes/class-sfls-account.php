<?php
/**
 * Members area: My Account › Licenses, My Account › Pro downloads, My Account
 * › Branding (Agency plan) and a summary on the account dashboard.
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
	const EP_BRANDING  = 'branding';

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
		add_filter( 'woocommerce_endpoint_' . self::EP_BRANDING . '_title', array( $this, 'title_branding' ) );
		add_action( 'woocommerce_account_' . self::EP_LICENSES . '_endpoint', array( $this, 'licenses_page' ) );
		add_action( 'woocommerce_account_' . self::EP_DOWNLOADS . '_endpoint', array( $this, 'downloads_page' ) );
		add_action( 'woocommerce_account_' . self::EP_BRANDING . '_endpoint', array( $this, 'branding_page' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'dashboard' ) );
		add_action( 'template_redirect', array( $this, 'handle_deactivate' ) );
		add_action( 'template_redirect', array( $this, 'handle_download' ) );
		add_action( 'template_redirect', array( $this, 'handle_billing' ) );
		add_action( 'template_redirect', array( $this, 'handle_branding' ) );
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
		$vars[ self::EP_BRANDING ]  = self::EP_BRANDING;
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
		// Branding is for customers with an active license whose plan includes it.
		$branding = is_user_logged_in() && SFLS_Branding::eligible_licenses( wp_get_current_user() );
		$new      = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::EP_LICENSES ]  = __( 'Licenses', 'subtleforms-license-server' );
				$new[ self::EP_DOWNLOADS ] = __( 'Pro downloads', 'subtleforms-license-server' );
				if ( $branding ) {
					$new[ self::EP_BRANDING ] = __( 'Branding', 'subtleforms-license-server' );
				}
			}
		}
		if ( ! isset( $new[ self::EP_LICENSES ] ) ) {
			$logout = $new['customer-logout'] ?? null;
			unset( $new['customer-logout'] );
			$new[ self::EP_LICENSES ]  = __( 'Licenses', 'subtleforms-license-server' );
			$new[ self::EP_DOWNLOADS ] = __( 'Pro downloads', 'subtleforms-license-server' );
			if ( $branding ) {
				$new[ self::EP_BRANDING ] = __( 'Branding', 'subtleforms-license-server' );
			}
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

	/**
	 * Branding endpoint title.
	 */
	public function title_branding(): string {
		return __( 'Branding', 'subtleforms-license-server' );
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
	 * The license of a user that may download a release, valid licenses
	 * first. An expired or revoked license still covers the versions released
	 * while it was valid.
	 *
	 * @param WP_User $user    User.
	 * @param array   $release Release.
	 */
	public static function license_for_release( WP_User $user, array $release ): ?array {
		$licenses = SFLS_Licenses::for_user( $user );
		usort( $licenses, static fn( $a, $b ) => (int) SFLS_Licenses::state( $b )['valid'] <=> (int) SFLS_Licenses::state( $a )['valid'] );
		foreach ( $licenses as $lic ) {
			if ( SFLS_Releases::allowed( $lic, $release ) ) {
				return $lic;
			}
		}
		return null;
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
		echo SFLS_Claims::card_html( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in card_html().
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
			echo wp_kses_post( SFLS_Delivery::steps_html( SFLS_Releases::has_package() ) );
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
		echo self::downloads_html( wp_get_current_user() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in downloads_html().
	}

	/**
	 * The latest release with its changelog, then every older published
	 * version (betas included, yanked and draft versions never) with its own
	 * changelog and download button. Each button leads to a fresh signed,
	 * single-use link for that license and version.
	 *
	 * @param WP_User $user Customer.
	 */
	public static function downloads_html( WP_User $user ): string {
		$latest = SFLS_Releases::latest( 'stable' );
		$all    = SFLS_Releases::published();
		if ( ! $latest && $all ) {
			$latest = $all[0]; // Only betas so far.
		}

		ob_start();
		echo '<div class="sfls sfls-account sfls-downloads">';

		if ( ! $latest ) {
			echo '<div class="sfls-card sfls-empty-card"><h3>' . esc_html__( 'No releases yet', 'subtleforms-license-server' ) . '</h3><p>' . esc_html__( 'The first SubtleForms Pro release will be available here.', 'subtleforms-license-server' ) . '</p></div></div>';
			return (string) ob_get_clean();
		}

		$licenses = SFLS_Licenses::for_user( $user );
		$valid    = self::can_download( $user );
		$may      = static fn( array $release ): bool => null !== self::license_for_release( $user, $release ) && SFLS_Releases::has_package( $release );

		if ( ! $valid ) {
			$renew = '';
			foreach ( $licenses as $lic ) {
				$renew = '' !== $renew ? $renew : (string) SFLS_Products::renew_url( $lic );
			}
			echo '<div class="sfls-alert sfls-alert--warn">';
			if ( $licenses ) {
				echo esc_html__( 'Your license is not active, so new versions are not available. You can still download the versions released while it was active.', 'subtleforms-license-server' );
				echo ' <a href="' . esc_url( '' !== $renew ? $renew : SFLS_Settings::pricing_url() ) . '">' . esc_html( '' !== $renew ? __( 'Renew', 'subtleforms-license-server' ) : __( 'See plans', 'subtleforms-license-server' ) ) . '</a>';
			} else {
				echo esc_html__( 'Downloads need an active license.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( SFLS_Settings::pricing_url() ) . '">' . esc_html__( 'See plans', 'subtleforms-license-server' ) . '</a>';
			}
			echo '</div>';
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
				$latest['size'] ? size_format( (int) $latest['size'] ) : '',
			)
		);
		echo '<p class="sfls-muted">' . esc_html( implode( ' · ', $meta ) ) . '</p></div>';
		if ( $may( $latest ) ) {
			echo '<a class="sfls-btn sfls-btn--primary" href="' . esc_url( self::download_url( $latest['version'] ) ) . '">';
			self::print_icon( 'download' );
			echo esc_html__( 'Download zip', 'subtleforms-license-server' ) . '</a>';
		} elseif ( '' !== self::locked_label( $user, $latest, $licenses ) ) {
			echo '<span class="sfls-muted sfls-locked">' . esc_html( self::locked_label( $user, $latest, $licenses ) ) . '</span>';
		}
		echo '</header>';

		$log = SFLS_Releases::changelog_html( $latest );
		if ( '' !== $log ) {
			echo '<div class="sfls-changelog"><h4>' . esc_html__( 'What is new', 'subtleforms-license-server' ) . '</h4>' . wp_kses_post( $log ) . '</div>';
		}
		echo '<p class="sfls-muted sfls-install-hint">' . esc_html__( 'Install it in WordPress from Plugins › Add New › Upload Plugin. Connected sites also get updates automatically in Dashboard › Updates.', 'subtleforms-license-server' ) . '</p>';
		if ( $latest['sha256'] ) {
			echo '<p class="sfls-muted sfls-checksum">' . esc_html__( 'SHA-256', 'subtleforms-license-server' ) . ' <code>' . esc_html( $latest['sha256'] ) . '</code></p>';
		}
		echo '</article>';

		$older = array_values( array_filter( $all, static fn( $r ) => $r['version'] !== $latest['version'] ) );
		if ( $older ) {
			echo '<article class="sfls-card sfls-older"><h4 class="sfls-card-title">' . esc_html__( 'Older versions', 'subtleforms-license-server' ) . '</h4>';
			echo '<p class="sfls-muted sfls-older-intro">' . esc_html__( 'Every version stays available. Use an older one to roll back a site or to test an upgrade.', 'subtleforms-license-server' ) . '</p>';
			echo '<ul class="sfls-version-list">';
			foreach ( $older as $rel ) {
				echo '<li class="sfls-version-row" id="' . esc_attr( 'version-' . sanitize_title( $rel['version'] ) ) . '"><div class="sfls-version-main"><span class="sfls-version">' . esc_html( $rel['version'] ) . '</span>';
				if ( 'beta' === $rel['channel'] ) {
					echo '<span class="sfls-tag sfls-tag--beta">' . esc_html__( 'Beta', 'subtleforms-license-server' ) . '</span>';
				}
				echo '<span class="sfls-muted">' . esc_html( SFLS_Releases::date_label( $rel ) ) . '</span></div>';
				if ( $may( $rel ) ) {
					echo '<a class="sfls-btn sfls-btn--ghost sfls-btn--sm" href="' . esc_url( self::download_url( $rel['version'] ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: version */ __( 'Download version %s', 'subtleforms-license-server' ), $rel['version'] ) ) . '">';
					self::print_icon( 'download' );
					echo esc_html__( 'Download', 'subtleforms-license-server' ) . '</a>';
				} else {
					$locked = self::locked_label( $user, $rel, $licenses );
					echo '<span class="sfls-muted sfls-locked">' . esc_html( '' !== $locked ? $locked : __( 'Not available', 'subtleforms-license-server' ) ) . '</span>';
				}
				$log = SFLS_Releases::changelog_html( $rel );
				if ( '' !== $log ) {
					echo '<details class="sfls-version-notes"><summary>' . esc_html__( 'What changed', 'subtleforms-license-server' ) . '</summary><div class="sfls-changelog-body">' . wp_kses_post( $log ) . '</div></details>';
				}
				echo '</li>';
			}
			echo '</ul></article>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Why a release cannot be downloaded (UX2-30): "Not available yet" when
	 * it has no package, "Renew to download" only when the customer's
	 * licenses do not cover it, '' when there is nothing to say.
	 *
	 * @param WP_User $user     Customer.
	 * @param array   $release  Release.
	 * @param array[] $licenses The customer's licenses.
	 */
	public static function locked_label( WP_User $user, array $release, array $licenses ): string {
		if ( ! SFLS_Releases::has_package( $release ) ) {
			return __( 'Not available yet', 'subtleforms-license-server' );
		}
		if ( $licenses && null === self::license_for_release( $user, $release ) ) {
			return __( 'Renew to download', 'subtleforms-license-server' );
		}
		return '';
	}

	/**
	 * A download button: checks the nonce and the customer's licenses, then
	 * sends the browser to a fresh signed link for that license and version
	 * (15 minutes, single use; see SFLS_REST::download()).
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
		$back    = wc_get_account_endpoint_url( self::EP_DOWNLOADS );
		$version = sanitize_text_field( wp_unslash( $_GET['sfls_download'] ) );
		$release = 'latest' === $version ? SFLS_Releases::latest( 'stable' ) : SFLS_Releases::find( $version );
		if ( ! $release || ! SFLS_Releases::has_package( $release ) ) {
			wc_add_notice( __( 'That version is not available for download.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$lic = self::license_for_release( wp_get_current_user(), $release );
		if ( ! $lic ) {
			wc_add_notice(
				self::can_download( wp_get_current_user() ) || ! SFLS_Licenses::for_user( wp_get_current_user() )
					? __( 'Downloads need an active license.', 'subtleforms-license-server' )
					: __( 'This version was released after your license expired. Renew to download it.', 'subtleforms-license-server' ),
				'error'
			);
			wp_safe_redirect( $back );
			exit;
		}
		wp_safe_redirect( SFLS_Releases::signed_url( $lic['license_key'], 'account', $release['version'] ) );
		exit;
	}

	// ── Branding ────────────────────────────────────────────────────────

	/**
	 * Address of the branding screen for a license.
	 *
	 * @param int $license_id License ID (0 for the default).
	 */
	public static function branding_url( int $license_id = 0 ): string {
		$url = wc_get_account_endpoint_url( self::EP_BRANDING );
		return $license_id > 0 ? add_query_arg( 'license', $license_id, $url ) : $url;
	}

	/**
	 * Short-lived storage for what a customer typed when saving failed, so
	 * the form is filled in again.
	 *
	 * @param int $user_id User ID.
	 */
	private static function draft_key( int $user_id ): string {
		return 'sfls_branding_draft_' . $user_id;
	}

	/**
	 * My Account › Branding.
	 */
	public function branding_page(): void {
		SFLS_Assets::enqueue();
		wp_enqueue_script( 'sfls-branding', SFLS_URL . 'assets/js/sfls-branding.js', array(), SFLS_VERSION, true );
		$user     = wp_get_current_user();
		$eligible = SFLS_Branding::eligible_licenses( $user );

		echo '<div class="sfls sfls-account sfls-branding">';
		if ( ! $eligible ) {
			$owned = SFLS_Licenses::for_user( $user );
			echo '<div class="sfls-card sfls-empty-card"><h3>' . esc_html__( 'Branding is part of the Agency plan', 'subtleforms-license-server' ) . '</h3>';
			echo '<p>' . esc_html(
				$owned
					? __( 'With an active Agency license you can set your own plugin name, logo and links once, and every client site that uses your key shows them.', 'subtleforms-license-server' )
					: __( 'Buy an Agency license to set your own plugin name, logo and links once and show them on every client site.', 'subtleforms-license-server' )
			) . '</p>';
			echo '<p><a class="sfls-btn sfls-btn--primary" href="' . esc_url( $owned ? wc_get_account_endpoint_url( self::EP_LICENSES ) : SFLS_Settings::pricing_url() ) . '">' . esc_html( $owned ? __( 'Go to licenses', 'subtleforms-license-server' ) : __( 'See plans', 'subtleforms-license-server' ) ) . '</a></p></div></div>';
			return;
		}

		$wanted = absint( $_GET['license'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which of the customer's own licenses to show.
		$lic    = $eligible[0];
		foreach ( $eligible as $candidate ) {
			if ( (int) $candidate['id'] === $wanted ) {
				$lic = $candidate;
			}
		}
		$id    = (int) $lic['id'];
		$state = SFLS_Branding::get( $id );
		$draft = get_transient( self::draft_key( $user->ID ) );
		delete_transient( self::draft_key( $user->ID ) );
		$draft = is_array( $draft ) && (int) ( $draft['license_id'] ?? 0 ) === $id ? $draft['input'] : null;

		echo '<p class="sfls-intro">' . esc_html__( 'Set the name, logo and links your clients see in SubtleForms. Every site that uses this license key applies them, usually within a day.', 'subtleforms-license-server' ) . '</p>';

		if ( count( $eligible ) > 1 ) {
			echo '<nav class="sfls-license-switch" aria-label="' . esc_attr__( 'Choose a license', 'subtleforms-license-server' ) . '"><span class="sfls-muted">' . esc_html__( 'License', 'subtleforms-license-server' ) . '</span><ul>';
			foreach ( $eligible as $candidate ) {
				$label = SFLS_Plans::label( $candidate['plan'] ) . ' · ' . SFLS_Licenses::mask_key( $candidate['license_key'] );
				echo '<li><a href="' . esc_url( self::branding_url( (int) $candidate['id'] ) ) . '"' . ( (int) $candidate['id'] === $id ? ' class="is-current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
			}
			echo '</ul></nav>';
		}

		$profile = $draft ? SFLS_Branding::validate( $draft )['profile'] : ( $state ? $state['profile'] : SFLS_Branding::empty_profile() );
		if ( $draft && $state && '' !== $state['logo_file'] ) {
			$profile['logo_url'] = '';
		}
		$logo_now = $state ? SFLS_Branding::display_url( $state['profile']['logo_url'] ) : '';

		$saved = $state ? $state['profile'] : SFLS_Branding::empty_profile();
		$saved['logo_url'] = SFLS_Branding::display_url( $saved['logo_url'] );
		$this->branding_preview( $saved, $state );
		$this->branding_form( $lic, $profile, $state, $logo_now );
		$this->branding_sites( $lic, $state );
		$this->branding_revisions( $lic, $state );
		echo '</div>';
	}

	/**
	 * Preview of the plugin header, menu entry and Plugins screen row, from
	 * the saved branding. A little script updates it while the form is edited.
	 *
	 * @param array      $profile Saved profile.
	 * @param array|null $state   Stored state.
	 */
	private function branding_preview( array $profile, ?array $state ): void {
		$name = '' !== $profile['name'] ? $profile['name'] : 'SubtleForms';
		$menu = '' !== $profile['menu_name'] ? $profile['menu_name'] : $name;
		$live = $state && $state['enabled'];

		echo '<article class="sfls-card sfls-bp" data-default-name="SubtleForms" data-default-description="' . esc_attr__( 'A calm, fast form builder for WordPress.', 'subtleforms-license-server' ) . '">';
		echo '<header class="sfls-bp-title"><h3>' . esc_html__( 'Preview', 'subtleforms-license-server' ) . '</h3>';
		if ( $live ) {
			/* translators: %d: branding version */
			echo '<span class="sfls-tag sfls-tag--live">' . esc_html( sprintf( __( 'Live, version %d', 'subtleforms-license-server' ), $state['version'] ) ) . '</span>';
		} else {
			echo '<span class="sfls-tag">' . esc_html__( 'Not set, sites show SubtleForms', 'subtleforms-license-server' ) . '</span>';
		}
		echo '</header>';
		echo '<p class="sfls-muted">' . esc_html__( 'This is how the saved branding looks on a client site. It updates as you type, and goes live when you save.', 'subtleforms-license-server' ) . '</p>';
		echo '<div class="sfls-bp-grid">';

		echo '<div class="sfls-bp-block"><span class="sfls-bp-label">' . esc_html__( 'App header', 'subtleforms-license-server' ) . '</span><div class="sfls-bp-header">';
		echo '<span class="sfls-bp-logo" data-sfls-bp="logo">' . ( '' !== $profile['logo_url'] ? '<img src="' . esc_url( $profile['logo_url'] ) . '" alt="">' : '<span class="sfls-bp-mark" aria-hidden="true">S</span>' ) . '</span>';
		echo '<strong class="sfls-bp-name" data-sfls-bp="name">' . esc_html( $name ) . '</strong></div></div>';

		echo '<div class="sfls-bp-block"><span class="sfls-bp-label">' . esc_html__( 'Admin menu entry', 'subtleforms-license-server' ) . '</span><div class="sfls-bp-menu"><span class="sfls-bp-menu-item"><span class="sfls-bp-dot" aria-hidden="true"></span><span data-sfls-bp="menu">' . esc_html( $menu ) . '</span></span></div></div>';

		echo '<div class="sfls-bp-block sfls-bp-block--wide"><span class="sfls-bp-label">' . esc_html__( 'Plugins screen', 'subtleforms-license-server' ) . '</span><div class="sfls-bp-plugin"><strong data-sfls-bp="name">' . esc_html( $name ) . '</strong>';
		$by = '' !== $profile['author'] ? $profile['author'] : 'SubtleForms';
		echo '<p data-sfls-bp="description">' . esc_html( '' !== $profile['description'] ? $profile['description'] : __( 'A calm, fast form builder for WordPress.', 'subtleforms-license-server' ) ) . '</p>';
		echo '<span class="sfls-muted">' . esc_html__( 'By', 'subtleforms-license-server' ) . ' <span data-sfls-bp="author">' . esc_html( $by ) . '</span></span></div></div>';

		echo '</div></article>';
	}

	/**
	 * The branding form.
	 *
	 * @param array      $lic      License.
	 * @param array      $profile  Values to show.
	 * @param array|null $state    Stored state.
	 * @param string     $logo_now Address of the saved logo.
	 */
	private function branding_form( array $lic, array $profile, ?array $state, string $logo_now ): void {
		$id       = (int) $lic['id'];
		$uploaded = $state && '' !== $state['logo_file'];
		$limits   = SFLS_Branding::TEXT_FIELDS;
		$field    = static function ( string $key, string $label, string $hint, string $type = 'text', string $extra = '' ) use ( $profile, $limits ): void {
			$max = $limits[ $key ] ?? SFLS_Branding::URL_FIELDS[ $key ] ?? 0;
			echo '<div class="sfls-field"><label for="sfls-b-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
			echo '<input type="' . esc_attr( $type ) . '" id="sfls-b-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $profile[ $key ] ) . '"' . ( $max ? ' maxlength="' . (int) $max . '"' : '' ) . ' ' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attributes from this file.
			echo '<span class="sfls-hint">' . esc_html( $hint ) . '</span></div>';
		};

		echo '<form method="post" class="sfls-card sfls-bform" enctype="multipart/form-data" action="' . esc_url( self::branding_url( $id ) ) . '">';
		wp_nonce_field( 'sfls_branding_' . $id, '_sfls_branding_nonce', false );
		echo '<input type="hidden" name="sfls_branding" value="save"><input type="hidden" name="license_id" value="' . esc_attr( (string) $id ) . '">';
		echo '<h3>' . esc_html__( 'Branding', 'subtleforms-license-server' ) . '</h3>';

		echo '<div class="sfls-form-grid">';
		$field( 'name', __( 'Plugin name', 'subtleforms-license-server' ), __( 'Shown in the app header and wherever the plugin is named. Empty means SubtleForms. Up to 60 characters.', 'subtleforms-license-server' ), 'text', 'data-sfls-bind="name" placeholder="SubtleForms"' );
		$field( 'menu_name', __( 'Menu name', 'subtleforms-license-server' ), __( 'The name in the WordPress admin menu. Empty uses the plugin name. Up to 40 characters.', 'subtleforms-license-server' ), 'text', 'data-sfls-bind="menu"' );
		echo '</div>';

		echo '<fieldset class="sfls-field sfls-logo-field"><legend>' . esc_html__( 'Logo', 'subtleforms-license-server' ) . '</legend>';
		if ( '' !== $logo_now ) {
			echo '<div class="sfls-logo-current"><img src="' . esc_url( $logo_now ) . '" alt="" class="sfls-logo-thumb"><label class="sfls-check"><input type="checkbox" name="remove_logo" value="1"> ' . esc_html__( 'Remove the current logo', 'subtleforms-license-server' ) . '</label></div>';
		}
		echo '<label class="sfls-sublabel" for="sfls-b-logo">' . esc_html__( 'Upload a logo', 'subtleforms-license-server' ) . '</label>';
		echo '<input type="file" id="sfls-b-logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" data-sfls-bind="logo-file">';
		echo '<span class="sfls-hint">' . esc_html__( 'PNG, JPEG, GIF or WebP, up to 1 MB. A square image of at least 64 by 64 pixels works best.', 'subtleforms-license-server' ) . '</span>';
		echo '<label class="sfls-sublabel" for="sfls-b-logo_url">' . esc_html__( 'Or use the https address of an image', 'subtleforms-license-server' ) . '</label>';
		echo '<input type="url" id="sfls-b-logo_url" name="logo_url" value="' . esc_attr( $uploaded ? '' : (string) $profile['logo_url'] ) . '" maxlength="500" placeholder="https://" data-sfls-bind="logo-url">';
		echo '<span class="sfls-hint">' . esc_html__( 'An uploaded file wins over an address. PNG, JPG, GIF, WebP or SVG.', 'subtleforms-license-server' ) . '</span></fieldset>';

		echo '<div class="sfls-form-grid">';
		$field( 'support_url', __( 'Support address', 'subtleforms-license-server' ), __( 'Where "Get support" goes on your clients\' sites. Empty uses the SubtleForms support page.', 'subtleforms-license-server' ), 'url', 'placeholder="https://"' );
		echo '</div>';

		echo '<div class="sfls-field"><label class="sfls-check"><input type="checkbox" name="hide_credits" value="1"' . checked( $profile['hide_credits'], true, false ) . '> ' . esc_html__( 'Hide SubtleForms credits, help links and upgrade prompts', 'subtleforms-license-server' ) . '</label></div>';
		echo '<div class="sfls-field"><label class="sfls-check"><input type="checkbox" name="restrict_screens" value="1"' . checked( $profile['restrict_screens'], true, false ) . '> ' . esc_html__( 'Limit the License screen to the admin who activated the license on the client site', 'subtleforms-license-server' ) . '</label></div>';

		echo '<h4 class="sfls-form-subhead">' . esc_html__( 'Plugins screen', 'subtleforms-license-server' ) . '</h4>';
		echo '<div class="sfls-form-grid">';
		$field( 'author', __( 'Author', 'subtleforms-license-server' ), __( 'Shown on the Plugins screen rows for SubtleForms and SubtleForms Pro. Up to 60 characters.', 'subtleforms-license-server' ), 'text', 'data-sfls-bind="author"' );
		$field( 'author_url', __( 'Author address', 'subtleforms-license-server' ), __( 'Links the author name. http or https.', 'subtleforms-license-server' ), 'url', 'placeholder="https://"' );
		echo '</div>';
		echo '<div class="sfls-field"><label for="sfls-b-description">' . esc_html__( 'Description', 'subtleforms-license-server' ) . '</label><textarea id="sfls-b-description" name="description" rows="3" maxlength="200" data-sfls-bind="description">' . esc_textarea( (string) $profile['description'] ) . '</textarea><span class="sfls-hint">' . esc_html__( 'Up to 200 characters.', 'subtleforms-license-server' ) . '</span></div>';

		echo '<div class="sfls-form-actions"><button type="submit" class="sfls-btn sfls-btn--primary">' . esc_html__( 'Save branding', 'subtleforms-license-server' ) . '</button>';
		echo '<span class="sfls-muted">' . esc_html__( 'Saving publishes a new version. Connected sites pick it up on their next check.', 'subtleforms-license-server' ) . '</span></div>';
		echo '</form>';

		if ( $state && $state['enabled'] ) {
			echo '<form method="post" class="sfls-inline-form sfls-reset-form" action="' . esc_url( self::branding_url( $id ) ) . '">';
			wp_nonce_field( 'sfls_branding_' . $id, '_sfls_branding_nonce', false );
			echo '<input type="hidden" name="sfls_branding" value="reset"><input type="hidden" name="license_id" value="' . esc_attr( (string) $id ) . '">';
			echo '<button type="submit" class="sfls-btn sfls-btn--ghost sfls-btn--sm sfls-btn--danger" data-sfls-confirm="' . esc_attr__( 'Remove your branding? Your clients\' sites go back to the SubtleForms name and logo. You can restore it from the list of versions.', 'subtleforms-license-server' ) . '">' . esc_html__( 'Remove branding', 'subtleforms-license-server' ) . '</button></form>';
		}
	}

	/**
	 * Client sites and the branding version each last fetched.
	 *
	 * @param array      $lic   License.
	 * @param array|null $state Stored state.
	 */
	private function branding_sites( array $lic, ?array $state ): void {
		$sites   = SFLS_Branding::client_sites( (int) $lic['id'] );
		$current = $state ? $state['version'] : 0;
		echo '<article class="sfls-card sfls-bsites"><h3>' . esc_html__( 'Client sites', 'subtleforms-license-server' ) . '</h3>';
		if ( ! $sites ) {
			echo '<p class="sfls-muted">' . esc_html__( 'No sites use this license yet. Sites appear here after they fetch your branding.', 'subtleforms-license-server' ) . '</p></article>';
			return;
		}
		echo '<ul class="sfls-site-list">';
		foreach ( $sites as $site ) {
			echo '<li class="sfls-site"><span class="sfls-site-icon">';
			self::print_icon( 'globe' );
			echo '</span><span class="sfls-site-main"><span class="sfls-site-domain">' . esc_html( $site['domain'] ) . '</span><span class="sfls-site-meta">';
			if ( null === $site['fetched_at'] ) {
				echo esc_html__( 'Has not fetched the branding yet', 'subtleforms-license-server' );
			} else {
				echo esc_html(
					sprintf(
						/* translators: 1: branding version, 2: human time difference */
						__( 'Version %1$d, fetched %2$s ago', 'subtleforms-license-server' ),
						$site['version'],
						human_time_diff( strtotime( $site['fetched_at'] . ' UTC' ), time() )
					)
				);
			}
			echo '</span></span>';
			if ( null !== $site['fetched_at'] && $current > 0 ) {
				echo $site['version'] === $current
					? '<span class="sfls-tag sfls-tag--live">' . esc_html__( 'Up to date', 'subtleforms-license-server' ) . '</span>'
					: '<span class="sfls-tag">' . esc_html__( 'Update pending', 'subtleforms-license-server' ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul></article>';
	}

	/**
	 * Earlier versions with a Restore button.
	 *
	 * @param array      $lic   License.
	 * @param array|null $state Stored state.
	 */
	private function branding_revisions( array $lic, ?array $state ): void {
		$id        = (int) $lic['id'];
		$revisions = SFLS_Branding::revisions( $id );
		if ( ! $revisions ) {
			return;
		}
		$actions = array(
			'save'    => __( 'Saved', 'subtleforms-license-server' ),
			'restore' => __( 'Restored', 'subtleforms-license-server' ),
			'reset'   => __( 'Removed', 'subtleforms-license-server' ),
		);
		$fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo '<article class="sfls-card sfls-brevisions"><h3>' . esc_html__( 'Versions', 'subtleforms-license-server' ) . '</h3>';
		/* translators: %d: number of versions kept */
		echo '<p class="sfls-muted">' . esc_html( sprintf( __( 'The last %d versions are kept. Restoring one publishes it as a new version.', 'subtleforms-license-server' ), SFLS_Branding::MAX_REVISIONS ) ) . '</p>';
		echo '<ul class="sfls-version-list">';
		foreach ( $revisions as $rev ) {
			$user    = $rev['updated_by'] ? get_userdata( $rev['updated_by'] ) : false;
			$is_now  = $state && $rev['version'] === $state['version'];
			$summary = 'reset' === $rev['action'] ? __( 'Default SubtleForms branding', 'subtleforms-license-server' ) : ( '' !== $rev['profile']['name'] ? $rev['profile']['name'] : 'SubtleForms' );
			echo '<li class="sfls-version-row"><div class="sfls-version-main"><span class="sfls-version">' . esc_html( sprintf( /* translators: %d: version */ __( 'Version %d', 'subtleforms-license-server' ), $rev['version'] ) ) . '</span>';
			if ( $is_now ) {
				echo '<span class="sfls-tag sfls-tag--live">' . esc_html__( 'Current', 'subtleforms-license-server' ) . '</span>';
			}
			echo '<span class="sfls-muted">' . esc_html( $summary . ' · ' . ( $actions[ $rev['action'] ] ?? $rev['action'] ) . ( $user ? ' · ' . $user->display_name : '' ) . ' · ' . wp_date( $fmt, strtotime( $rev['updated_at'] . ' UTC' ) ) ) . '</span></div>';
			if ( ! $is_now ) {
				echo '<form method="post" class="sfls-inline-form" action="' . esc_url( self::branding_url( $id ) ) . '">';
				wp_nonce_field( 'sfls_branding_' . $id, '_sfls_branding_nonce', false );
				echo '<input type="hidden" name="sfls_branding" value="restore"><input type="hidden" name="license_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="revision_id" value="' . esc_attr( (string) $rev['id'] ) . '">';
				echo '<button type="submit" class="sfls-btn sfls-btn--secondary sfls-btn--sm" data-sfls-confirm="' . esc_attr( sprintf( /* translators: %d: version */ __( 'Restore version %d? It is published as a new version.', 'subtleforms-license-server' ), $rev['version'] ) ) . '">' . esc_html__( 'Restore', 'subtleforms-license-server' ) . '</button></form>';
			}
			echo '</li>';
		}
		echo '</ul></article>';
	}

	/**
	 * Save, restore and remove on My Account › Branding. Every action needs
	 * the nonce of its license, a signed-in customer who owns that license,
	 * and a license that is active on a plan with branding.
	 */
	public function handle_branding(): void {
		if ( empty( $_POST['sfls_branding'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
			return;
		}
		$back = self::branding_url();
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}
		$id    = absint( $_POST['license_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked next.
		$nonce = sanitize_text_field( wp_unslash( $_POST['_sfls_branding_nonce'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the nonce.
		if ( ! wp_verify_nonce( $nonce, 'sfls_branding_' . $id ) ) {
			wc_add_notice( __( 'Your session expired. Try again.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$user = wp_get_current_user();
		$lic  = SFLS_Licenses::find( $id );
		if ( ! $lic || ! SFLS_Licenses::user_owns( $lic, $user ) ) {
			wc_add_notice( __( 'That license was not found in your account.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$back = self::branding_url( $id );
		if ( ! SFLS_Branding::can_manage( $lic, $user ) ) {
			wc_add_notice( __( 'Branding needs an active Agency license.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$do     = sanitize_key( wp_unslash( $_POST['sfls_branding'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = null;
		switch ( $do ) {
			case 'save':
				$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated field by field in SFLS_Branding::validate().
				$logo  = array( 'remove' => ! empty( $input['remove_logo'] ) );
				if ( isset( $_FILES['logo'] ) && is_array( $_FILES['logo'] ) ) {
					$logo['file'] = array(
						'tmp_name' => (string) ( $_FILES['logo']['tmp_name'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- A path PHP made; the content is checked in store_logo().
						'error'    => (int) ( $_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
					);
				}
				$result = SFLS_Branding::save( $lic, is_array( $input ) ? $input : array(), $user->ID, $logo );
				if ( is_wp_error( $result ) ) {
					set_transient(
						self::draft_key( $user->ID ),
						array(
							'license_id' => $id,
							'input'      => array_diff_key( is_array( $input ) ? $input : array(), array( '_sfls_branding_nonce' => 1 ) ),
						),
						10 * MINUTE_IN_SECONDS
					);
					$fields = (array) ( $result->get_error_data()['fields'] ?? array() );
					foreach ( $fields ? $fields : array( $result->get_error_message() ) as $message ) {
						wc_add_notice( (string) $message, 'error' );
					}
					wp_safe_redirect( $back );
					exit;
				}
				wc_add_notice(
					sprintf(
						/* translators: %d: branding version */
						__( 'Branding saved as version %d. Connected sites pick it up on their next check.', 'subtleforms-license-server' ),
						$result['version']
					)
				);
				break;
			case 'restore':
				$restored = SFLS_Branding::restore( $lic, absint( $_POST['revision_id'] ?? 0 ), $user->ID ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( is_wp_error( $restored ) ) {
					wc_add_notice( $restored->get_error_message(), 'error' );
					break;
				}
				wc_add_notice(
					sprintf(
						/* translators: %d: branding version */
						__( 'Restored and published as version %d.', 'subtleforms-license-server' ),
						$restored['state']['version']
					)
				);
				if ( $restored['logo_lost'] ) {
					wc_add_notice( __( 'The logo of that version is no longer stored. Upload it again if you need it.', 'subtleforms-license-server' ), 'notice' );
				}
				break;
			case 'reset':
				$state = SFLS_Branding::reset( $lic, $user->ID );
				wc_add_notice(
					sprintf(
						/* translators: %d: branding version */
						__( 'Branding removed (version %d). Your clients\' sites show the SubtleForms name and logo again.', 'subtleforms-license-server' ),
						$state['version']
					)
				);
				break;
		}
		wp_safe_redirect( $back );
		exit;
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
