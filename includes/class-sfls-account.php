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

		foreach ( $licenses as $lic ) {
			$this->license_card( $lic, $release );
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

		echo '<article class="sfls-card sfls-license" id="license-' . esc_attr( (string) $lic['id'] ) . '">';

		echo '<header class="sfls-license-head"><div class="sfls-license-title"><h3>' . esc_html( SFLS_Plans::label( $lic['plan'] ) ) . '</h3>';
		echo wp_kses_post( SFLS_Connect::pill( $state['status'] ) );
		echo '</div><div class="sfls-license-actions">';
		if ( $renew && 'suspended' !== $state['status'] ) {
			echo '<a class="sfls-btn sfls-btn--' . ( 'valid' === $state['status'] ? 'secondary' : 'primary' ) . ' sfls-btn--sm" href="' . esc_url( $renew ) . '">';
			self::print_icon( 'refresh' );
			echo esc_html__( 'Renew', 'subtleforms-license-server' ) . '</a>';
		}
		foreach ( $upgrades as $plan => $row ) {
			echo '<a class="sfls-btn sfls-btn--secondary sfls-btn--sm" href="' . esc_url( SFLS_Products::upgrade_url( $lic, $plan ) ) . '">';
			self::print_icon( 'up' );
			/* translators: %s: plan name */
			echo esc_html( sprintf( __( 'Upgrade to %s', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) ) ) . '</a>';
		}
		if ( $state['valid'] && SFLS_Releases::has_package() ) {
			echo '<a class="sfls-btn sfls-btn--secondary sfls-btn--sm" href="' . esc_url( self::download_url() ) . '">';
			self::print_icon( 'download' );
			echo esc_html__( 'Download Pro', 'subtleforms-license-server' ) . '</a>';
		}
		echo '</div></header>';

		if ( 'grace_period' === $state['status'] ) {
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
		echo '<div><dt>' . esc_html( empty( $lic['expires_at'] ) ? __( 'Term', 'subtleforms-license-server' ) : ( $state['valid'] && 'valid' === $state['status'] ? __( 'Renews', 'subtleforms-license-server' ) : __( 'Expired', 'subtleforms-license-server' ) ) ) . '</dt><dd>' . esc_html( SFLS_Licenses::expiry_label( $lic ) ) . '</dd></div>';
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
		SFLS_Licenses::remove_activation( $id, $domain );
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

		echo '<div class="sfls-tile"><span class="sfls-tile-label">' . esc_html__( 'Latest Pro version', 'subtleforms-license-server' ) . '</span><span class="sfls-tile-value">' . esc_html( $release['version'] ? $release['version'] : '–' ) . '</span>';
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
