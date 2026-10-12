<?php
/**
 * Selling with Paddle on the marketing site.
 *
 * In the recommended mode (WooCommerce checkout with Paddle payments) the
 * pricing cards link to the WooCommerce cart instead ("Start 14-day free
 * trial" adds the plan with sfls_trial=1); the overlay checkout below is the
 * legacy "Paddle overlay" mode.
 *
 * - Pricing cards (the sfls/pricing block and [sfls_pricing]) open the Paddle
 *   overlay checkout. A plan with a trial shows "Start 14-day free trial" and
 *   "Then $79/year, cancel anytime"; the button first checks the trial rules
 *   (POST /sfls/v1/paddle/eligibility) with the signed-in account's email or
 *   an email the visitor types. Customers who already had a trial see
 *   "Buy now" with the reason.
 * - /license-welcome/ (or a page with [sfls_license_welcome]) is where the
 *   checkout lands. It waits for the webhook to issue the license, then shows
 *   the key, Download Pro, the install steps and the trial end date.
 *
 * The checkout passes custom data (plan, a random token, email) that Paddle
 * copies to the subscription. The webhook stores a hash of the token, so only
 * the browser that paid can read the key on the welcome page.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paddle checkout front end.
 */
final class SFLS_Checkout_Paddle {

	const QUERY_VAR = 'sfls_welcome';

	/**
	 * Hook.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'maybe_welcome' ), 0 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ), 5 );
		add_shortcode( 'sfls_license_welcome', array( $this, 'welcome_shortcode' ) );
	}

	/**
	 * Rewrite rule for /license-welcome/.
	 */
	public static function rewrite(): void {
		add_rewrite_rule( '^license-welcome/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Where the checkout sends the customer.
	 */
	public static function welcome_url(): string {
		$url = get_option( 'permalink_structure' ) ? home_url( '/license-welcome/' ) : add_query_arg( self::QUERY_VAR, 1, home_url( '/' ) );
		/**
		 * Filter the page the Paddle checkout lands on. Point it at a page with
		 * the [sfls_license_welcome] shortcode to use your theme's layout.
		 *
		 * @param string $url Welcome URL.
		 */
		return (string) apply_filters( 'sfls_welcome_url', $url );
	}

	// ── Assets ──────────────────────────────────────────────────────────

	/**
	 * Register Paddle.js, the pricing script and the welcome script.
	 */
	public static function register_assets(): void {
		if ( ! wp_script_is( 'sfls-paddle-js', 'registered' ) ) {
			// Paddle requires Paddle.js to be loaded from its CDN (it is not allowed to be self-hosted).
			wp_register_script( 'sfls-paddle-js', SFLS_Paddle::JS_URL, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Paddle serves the current v2 build.
		}
		if ( ! wp_script_is( 'sfls-paddle', 'registered' ) ) {
			wp_register_script( 'sfls-paddle', SFLS_URL . 'assets/js/sfls-paddle.js', array( 'sfls-paddle-js' ), SFLS_VERSION, true );
		}
		if ( ! wp_script_is( 'sfls-welcome', 'registered' ) ) {
			wp_register_script( 'sfls-welcome', SFLS_URL . 'assets/js/sfls-welcome.js', array(), SFLS_VERSION, true );
		}
	}

	/**
	 * Load the checkout on a page that shows pricing.
	 */
	public static function enqueue_checkout(): void {
		self::register_assets();
		if ( wp_script_is( 'sfls-paddle', 'enqueued' ) ) {
			return;
		}
		$config = SFLS_Paddle::config();
		$user   = wp_get_current_user();
		wp_enqueue_script( 'sfls-paddle' );
		wp_localize_script(
			'sfls-paddle',
			'sflsPaddle',
			array(
				'token'          => $config['client_token'],
				'environment'    => $config['env'],
				'eligibilityUrl' => rest_url( SFLS_REST::NS . '/paddle/eligibility' ),
				'nonce'          => $user->ID ? wp_create_nonce( 'wp_rest' ) : '',
				'email'          => $user->ID ? $user->user_email : '',
				'welcomeUrl'     => self::welcome_url(),
				'locale'         => substr( determine_locale(), 0, 2 ),
				'i18n'           => array(
					'buyNow'     => __( 'Buy now', 'subtleforms-license-server' ),
					'checking'   => __( 'Checking…', 'subtleforms-license-server' ),
					'opening'    => __( 'Opening checkout…', 'subtleforms-license-server' ),
					'buyInstead' => __( 'You can buy the plan right away instead.', 'subtleforms-license-server' ),
					'error'      => __( 'Something went wrong. Try again in a moment.', 'subtleforms-license-server' ),
					'noPaddle'   => __( 'The checkout could not load. Check your connection or turn off content blockers, then try again.', 'subtleforms-license-server' ),
				),
			)
		);
	}

	// ── Pricing cards ───────────────────────────────────────────────────

	/**
	 * Yearly price for display: the Paddle price stored by Sync products,
	 * else the WooCommerce yearly product price.
	 *
	 * @param string $plan Plan slug.
	 * @return array{amount:string,plain:string} amount is HTML-safe text, plain is "$79.00".
	 */
	public static function display_price( string $plan ): array {
		$map = SFLS_Paddle::plan( $plan );
		if ( '' !== $map['amount'] && '' !== $map['currency'] ) {
			$plain = SFLS_Paddle::format_amount( $map['amount'], $map['currency'], true );
			return array(
				'amount' => $plain,
				'plain'  => $plain,
			);
		}
		if ( SFLS_Products::woo_active() ) {
			$row     = SFLS_Products::find( $plan, 'yearly' );
			$product = $row ? wc_get_product( $row['id'] ) : null;
			if ( $product ) {
				$plain = html_entity_decode( wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' );
				return array(
					'amount' => $plain,
					'plain'  => $plain,
				);
			}
		}
		return array(
			'amount' => '',
			'plain'  => '',
		);
	}

	/**
	 * Pricing cards for Paddle.
	 *
	 * @param array $atts popular, button (label template).
	 */
	public static function pricing_html( array $atts ): string {
		$woo   = SFLS_Paddle::woo_selling();
		$plans = array_filter( SFLS_Paddle::plans(), static fn( $row ) => '' !== $row['price_id'] );
		if ( $woo ) {
			// The cart needs the plan's yearly WooCommerce product.
			foreach ( array_keys( $plans ) as $plan ) {
				$product = SFLS_Products::find( $plan, 'yearly' );
				if ( $product ) {
					$plans[ $plan ]['wc_product_id'] = (int) $product['id'];
				} else {
					unset( $plans[ $plan ] );
				}
			}
		}
		if ( ! $plans ) {
			return current_user_can( 'manage_options' ) ? '<div class="sfls sfls-notice-box">' . esc_html(
				$woo
					? __( 'Map your plans to Paddle prices in Licenses › Settings › Paddle, and create the yearly license products in Licenses › Products.', 'subtleforms-license-server' )
					: __( 'Map your plans to Paddle prices in Licenses › Settings › Paddle.', 'subtleforms-license-server' )
			) . '</div>' : '';
		}
		if ( ! $woo ) {
			self::enqueue_checkout();
		}
		SFLS_Assets::register_handles();
		wp_enqueue_style( 'sfls-front' );

		$user        = wp_get_current_user();
		$eligibility = $user->ID ? SFLS_Trials::check( $user->user_email ) : null;

		ob_start();
		echo '<div class="sfls sfls-pricing sfls-pricing--paddle" data-sfls-paddle-pricing>';
		echo '<div class="sfls-pricing-grid sfls-pricing-grid--yearly">';
		foreach ( $plans as $plan => $row ) {
			self::card( $plan, $row, $plan === ( $atts['popular'] ?? 'pro' ), $atts, $eligibility );
		}
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/**
	 * One Paddle pricing card.
	 *
	 * @param string     $plan        Plan slug.
	 * @param array      $row         Paddle mapping.
	 * @param bool       $popular     Highlight.
	 * @param array      $atts        Attributes.
	 * @param array|null $eligibility Signed-in customer's trial check.
	 */
	private static function card( string $plan, array $row, bool $popular, array $atts, ?array $eligibility ): void {
		$label    = SFLS_Plans::label( $plan );
		$price    = self::display_price( $plan );
		$trial    = SFLS_Paddle::plan_has_trial( $plan );
		$eligible = ! $eligibility || $eligibility['eligible'];
		$mode     = $trial && $eligible ? 'trial' : 'buy';
		$features = array_values( array_unique( array_merge( SFLS_Plans::feature_label_list( $plan, true ), array_filter( SFLS_Plans::common_items(), 'strlen' ) ) ) );
		$template = trim( (string) ( $atts['button'] ?? '' ) );
		$buy      = '' !== $template ? str_replace( '{plan}', $label, $template ) : sprintf( /* translators: %s: plan name */ __( 'Buy %s', 'subtleforms-license-server' ), $label );
		/* translators: %d: number of days */
		$trial_label = sprintf( __( 'Start %d-day free trial', 'subtleforms-license-server' ), (int) $row['trial_days'] );

		echo '<div class="sfls-plan' . ( $popular ? ' is-popular' : '' ) . '" data-sfls-plan="' . esc_attr( $plan ) . '">';
		if ( $popular ) {
			echo '<span class="sfls-plan-badge">' . esc_html__( 'Most popular', 'subtleforms-license-server' ) . '</span>';
		}
		echo '<h3 class="sfls-plan-name">' . esc_html( $label ) . '</h3>';
		echo '<p class="sfls-plan-sites">' . esc_html( SFLS_Plans::sites_label( (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ) ) ) . '</p>';
		if ( '' !== $price['amount'] ) {
			echo '<p class="sfls-plan-price"><span class="sfls-plan-amount">' . esc_html( $price['amount'] ) . '</span> <span class="sfls-plan-period">' . esc_html__( '/year', 'subtleforms-license-server' ) . '</span></p>';
		}

		if ( ! empty( $row['wc_product_id'] ) ) {
			// WooCommerce checkout with Paddle payments: links to the cart.
			$buy_url = SFLS_Products::buy_url( (int) $row['wc_product_id'] );
			echo '<a class="sfls-btn sfls-btn--' . ( $popular ? 'primary' : 'secondary' ) . ' sfls-btn--block" href="' . esc_url( 'trial' === $mode ? add_query_arg( 'sfls_trial', '1', $buy_url ) : $buy_url ) . '" rel="nofollow" data-mode="' . esc_attr( $mode ) . '">'
				. esc_html( 'trial' === $mode ? $trial_label : ( $trial ? __( 'Buy now', 'subtleforms-license-server' ) : $buy ) ) . '</a>';
		} else {
			echo '<button type="button" class="sfls-btn sfls-btn--' . ( $popular ? 'primary' : 'secondary' ) . ' sfls-btn--block sfls-paddle-buy"'
				. ' data-mode="' . esc_attr( $mode ) . '"'
				. ' data-plan="' . esc_attr( $plan ) . '"'
				. ' data-price="' . esc_attr( $row['price_id'] ) . '"'
				. ' data-trial-price="' . esc_attr( $trial ? $row['trial_price_id'] : '' ) . '"'
				. ' data-buy-label="' . esc_attr( __( 'Buy now', 'subtleforms-license-server' ) ) . '">'
				. esc_html( 'trial' === $mode ? $trial_label : ( $trial ? __( 'Buy now', 'subtleforms-license-server' ) : $buy ) ) . '</button>';
		}

		if ( $trial ) {
			$then = '' !== $price['plain']
				/* translators: %s: yearly price */
				? sprintf( __( 'Then %s/year, cancel anytime', 'subtleforms-license-server' ), $price['plain'] )
				: __( 'Then billed yearly, cancel anytime', 'subtleforms-license-server' );
			echo '<p class="sfls-plan-trial-note"' . ( 'trial' === $mode ? '' : ' hidden' ) . '>' . esc_html( $then ) . '</p>';
		} else {
			echo '<p class="sfls-plan-trial-note">' . esc_html__( 'Billed yearly, cancel anytime', 'subtleforms-license-server' ) . '</p>';
		}
		if ( ! empty( $row['wc_product_id'] ) && 'trial' === $mode ) {
			echo '<a class="sfls-plan-buy-now" href="' . esc_url( SFLS_Products::buy_url( (int) $row['wc_product_id'] ) ) . '" rel="nofollow">' . esc_html__( 'or buy now without a trial', 'subtleforms-license-server' ) . '</a>';
		}
		$reason = $trial && ! $eligible ? trim( $eligibility['message'] . ' ' . __( 'You can buy the plan right away instead.', 'subtleforms-license-server' ) ) : '';
		echo '<p class="sfls-plan-reason" role="status"' . ( '' === $reason ? ' hidden' : '' ) . '>' . esc_html( $reason ) . '</p>';

		if ( $trial && empty( $row['wc_product_id'] ) ) {
			$field = wp_unique_id( 'sfls-trial-email-' );
			echo '<form class="sfls-trial-email" hidden novalidate>';
			echo '<label for="' . esc_attr( $field ) . '">' . esc_html__( 'Your email', 'subtleforms-license-server' ) . '</label>';
			echo '<div class="sfls-trial-email-row"><input type="email" id="' . esc_attr( $field ) . '" name="email" autocomplete="email" required placeholder="' . esc_attr__( 'you@company.com', 'subtleforms-license-server' ) . '">';
			echo '<button type="submit" class="sfls-btn sfls-btn--primary">' . esc_html__( 'Continue', 'subtleforms-license-server' ) . '</button></div>';
			echo '<p class="sfls-trial-email-help">' . esc_html__( 'Your license is sent here. One free trial per person, company and site.', 'subtleforms-license-server' ) . '</p>';
			echo '<p class="sfls-trial-email-error" role="alert" hidden></p>';
			echo '</form>';
		}

		echo '<ul class="sfls-plan-features">';
		foreach ( $features as $feature ) {
			echo '<li>' . esc_html( $feature ) . '</li>';
		}
		echo '</ul></div>';
	}

	// ── Welcome page ────────────────────────────────────────────────────

	/**
	 * Take over the request for /license-welcome/.
	 *
	 * @param WP $wp Request.
	 */
	public function maybe_welcome( WP $wp ): void {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		status_header( 200 );
		SFLS_Connect::standalone_page(
			__( 'Welcome to SubtleForms Pro', 'subtleforms-license-server' ),
			static function () {
				self::localize_welcome();
				echo self::welcome_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				wp_print_scripts( array( 'sfls-front', 'sfls-welcome' ) );
			}
		);
		exit;
	}

	/**
	 * [sfls_license_welcome]: the same card inside a themed page.
	 */
	public function welcome_shortcode(): string {
		SFLS_Assets::enqueue();
		self::localize_welcome();
		wp_enqueue_script( 'sfls-welcome' );
		return '<div class="sfls sfls-welcome-page">' . self::welcome_markup() . '</div>';
	}

	/**
	 * Give the welcome script its settings.
	 */
	private static function localize_welcome(): void {
		self::register_assets();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the transaction ID only selects what to poll for.
		$txn = isset( $_GET['txn'] ) ? sanitize_text_field( wp_unslash( $_GET['txn'] ) ) : '';
		wp_localize_script(
			'sfls-welcome',
			'sflsWelcome',
			array(
				'endpoint' => rest_url( SFLS_REST::NS . '/paddle/checkout' ),
				'txn'      => preg_match( '/^txn_[a-z0-9]+$/', $txn ) ? $txn : '',
				'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'interval' => 2000,
				'attempts' => 45,
				'i18n'     => array(
					/* translators: %s: date */
					'trialEnds' => __( 'Your free trial ends on %s. Cancel before then and you will not be charged.', 'subtleforms-license-server' ),
					/* translators: 1: date, 2: amount */
					'trialThen' => __( 'Your free trial ends on %1$s. Then %2$s a year, cancel anytime from your account.', 'subtleforms-license-server' ),
					/* translators: %s: date */
					'renews'    => __( 'Renews automatically on %s.', 'subtleforms-license-server' ),
					/* translators: %s: email */
					'emailed'   => __( 'We also emailed the key to %s.', 'subtleforms-license-server' ),
					'copied'    => __( 'Copied', 'subtleforms-license-server' ),
				),
			)
		);
	}

	/**
	 * Welcome card: waiting, ready and slow states (the script switches them).
	 */
	public static function welcome_markup(): string {
		$download = SFLS_Releases::has_package();
		$html     = '<div class="sfls-welcome" data-sfls-welcome>';

		$html .= '<div class="sfls-welcome-state" data-state="waiting">';
		$html .= '<div class="sfls-welcome-spinner" aria-hidden="true"></div>';
		$html .= '<h1>' . esc_html__( 'Setting up your license', 'subtleforms-license-server' ) . '</h1>';
		$html .= '<p class="sfls-connect-lead">' . esc_html__( 'Thanks! Your checkout went through. Your license key appears here in a few seconds.', 'subtleforms-license-server' ) . '</p>';
		$html .= '</div>';

		$html .= '<div class="sfls-welcome-state" data-state="ready" hidden>';
		$html .= '<div class="sfls-connect-icon sfls-connect-icon--ok" aria-hidden="true">&#10003;</div>';
		$html .= '<h1>' . esc_html__( 'Welcome to SubtleForms Pro', 'subtleforms-license-server' ) . '</h1>';
		$html .= '<p class="sfls-connect-lead" data-field="summary"></p>';
		$html .= '<div class="sfls-keyrow"><code class="sfls-key" data-field="key"></code><button type="button" class="sfls-btn sfls-btn--ghost sfls-btn--sm sfls-copy" data-sfls-copy="" data-sfls-copied="' . esc_attr__( 'Copied', 'subtleforms-license-server' ) . '"><span class="sfls-copy-label">' . esc_html__( 'Copy', 'subtleforms-license-server' ) . '</span></button></div>';
		$html .= '<p class="sfls-welcome-trial" data-field="trial" hidden></p>';
		$html .= '<p class="sfls-muted" data-field="emailed"></p>';
		$html .= '<div class="sfls-actions sfls-delivery-actions">';
		if ( $download ) {
			$html .= '<a class="sfls-btn sfls-btn--primary" data-field="download" href="' . esc_url( SFLS_Licenses::manage_url() ) . '">' . esc_html__( 'Download Pro', 'subtleforms-license-server' ) . '</a>';
		}
		$html .= '<a class="sfls-btn sfls-btn--secondary" data-field="account" href="' . esc_url( SFLS_Licenses::manage_url() ) . '">' . esc_html__( 'Manage licenses', 'subtleforms-license-server' ) . '</a></div>';
		if ( ! $download ) {
			$html .= '<p class="sfls-muted">' . esc_html__( 'The Pro download appears in My Account › Pro downloads as soon as the first release is published.', 'subtleforms-license-server' ) . '</p>';
		}
		$html .= SFLS_Delivery::steps_html();
		$html .= '</div>';

		$html .= '<div class="sfls-welcome-state" data-state="signin" hidden>';
		$html .= '<div class="sfls-connect-icon sfls-connect-icon--ok" aria-hidden="true">&#10003;</div>';
		$html .= '<h1>' . esc_html__( 'Your license is ready', 'subtleforms-license-server' ) . '</h1>';
		$html .= '<p class="sfls-connect-lead" data-field="message"></p>';
		$html .= '<div class="sfls-actions"><a class="sfls-btn sfls-btn--primary" href="' . esc_url( SFLS_Licenses::manage_url() ) . '">' . esc_html__( 'Sign in to My Account', 'subtleforms-license-server' ) . '</a></div>';
		$html .= '</div>';

		$html .= '<div class="sfls-welcome-state" data-state="slow" hidden>';
		$html .= '<h1>' . esc_html__( 'Almost there', 'subtleforms-license-server' ) . '</h1>';
		$html .= '<p class="sfls-connect-lead">' . esc_html__( 'Your payment details were accepted, but your license is taking longer than usual. It will be emailed to you within a few minutes and will appear in My Account › Licenses.', 'subtleforms-license-server' ) . '</p>';
		$html .= '<div class="sfls-actions"><a class="sfls-btn sfls-btn--secondary" href="' . esc_url( SFLS_Licenses::manage_url() ) . '">' . esc_html__( 'Go to My Account', 'subtleforms-license-server' ) . '</a></div>';
		$html .= '</div>';

		return $html . '</div>';
	}
}
