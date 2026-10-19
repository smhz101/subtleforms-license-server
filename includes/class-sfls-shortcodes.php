<?php
/**
 * Shortcodes for the marketing site.
 *
 *   [sfls_pricing period="yearly|lifetime" popular="pro" renewal_note="no"
 *                 button="Buy {plan}" button_lifetime="Buy {plan}"]
 *                                           Pricing cards from the license products.
 *   [sfls_changelog limit="0"]               Every Pro release, newest first.
 *   [sfls_renewal_note]                      "Renew at 20% off: $39, $79 or $159.", from the
 *                                           yearly and renewal products (LS-05).
 *   [sfls_latest_version]                    The current Pro version number
 *                                           ("Coming soon" before the first release).
 *   [sfls_downloads]                         The signed-in customer's Pro downloads: the
 *                                           latest release and every older version.
 *   [sfls_license_welcome]                   Landing page after a Paddle checkout
 *                                           (see SFLS_Checkout_Paddle).
 *
 * When Licenses › Settings › Paddle sells with Paddle (WooCommerce checkout
 * with Paddle payments, or the legacy overlay), [sfls_pricing] shows yearly
 * Paddle subscriptions with free trials. In the WooCommerce mode the Buy and
 * Start trial buttons put the plan in the cart and open the checkout.
 *
 * The same output is available as blocks (see SFLS_Blocks).
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode renderers.
 */
final class SFLS_Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'sfls_pricing', array( $this, 'pricing' ) );
		add_shortcode( 'sfls_changelog', array( $this, 'changelog' ) );
		add_shortcode( 'sfls_latest_version', array( $this, 'latest_version' ) );
		add_shortcode( 'sfls_renewal_note', array( $this, 'renewal_note_line' ) );
		add_shortcode( 'sfls_downloads', array( $this, 'downloads' ) );
	}

	/**
	 * Load the stylesheet for a rendered shortcode.
	 */
	private function styles(): void {
		SFLS_Assets::register_handles();
		wp_enqueue_style( 'sfls-front' );
	}

	/**
	 * [sfls_pricing]
	 *
	 * @param array|string $atts Attributes.
	 */
	public function pricing( $atts ): string {
		$atts = shortcode_atts(
			array(
				'period'          => 'yearly',
				'popular'         => 'pro',
				'renewal_note'    => 'no',
				'button'          => '',
				'button_lifetime' => '',
			),
			$atts,
			'sfls_pricing'
		);
		$atts['renewal_note'] = in_array( strtolower( (string) $atts['renewal_note'] ), array( '1', 'yes', 'true', 'on' ), true );

		// Selling with Paddle: yearly subscriptions with optional free trials.
		// With the WooCommerce checkout and Paddle payments, the buttons go to the cart.
		if ( SFLS_Paddle::any_selling() ) {
			return SFLS_Checkout_Paddle::pricing_html( $atts );
		}

		if ( ! SFLS_Products::woo_active() ) {
			return '';
		}

		$this->styles();
		$sets = array(
			'yearly'   => SFLS_Products::sellable( 'yearly' ),
			'lifetime' => SFLS_Products::sellable( 'lifetime' ),
		);
		$sets = array_filter( $sets );

		if ( ! $sets ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="sfls sfls-notice-box">' . esc_html__( 'No license products are on sale yet. Create them in Licenses › Products.', 'subtleforms-license-server' ) . '</div>';
			}
			return '';
		}

		$period = in_array( $atts['period'], array( 'yearly', 'lifetime' ), true ) ? $atts['period'] : 'yearly';
		if ( ! isset( $sets[ $period ] ) ) {
			$period = (string) array_key_first( $sets );
		}
		$uid    = wp_unique_id( 'sfls-pricing-' );
		$toggle = count( $sets ) > 1;

		ob_start();
		echo '<div class="sfls sfls-pricing" id="' . esc_attr( $uid ) . '">';
		if ( $toggle ) {
			foreach ( array_keys( $sets ) as $p ) {
				echo '<input type="radio" class="sfls-period-input sfls-period-input--' . esc_attr( $p ) . '" name="' . esc_attr( $uid ) . '-period" id="' . esc_attr( $uid . '-' . $p ) . '"' . checked( $period, $p, false ) . '>';
			}
			echo '<div class="sfls-period-toggle">';
			echo '<label class="sfls-period-label sfls-period-label--yearly" for="' . esc_attr( $uid . '-yearly' ) . '">' . esc_html__( 'Yearly', 'subtleforms-license-server' ) . '</label>';
			echo '<label class="sfls-period-label sfls-period-label--lifetime" for="' . esc_attr( $uid . '-lifetime' ) . '">' . esc_html__( 'Lifetime', 'subtleforms-license-server' ) . '</label>';
			echo '</div>';
		}
		foreach ( $sets as $p => $rows ) {
			if ( ! $toggle && $p !== $period ) {
				continue;
			}
			echo '<div class="sfls-pricing-grid sfls-pricing-grid--' . esc_attr( $p ) . '">';
			foreach ( $rows as $plan => $row ) {
				$this->pricing_card( $plan, $row, $plan === $atts['popular'], $atts );
			}
			echo '</div>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * One pricing card.
	 *
	 * @param string $plan    Plan slug.
	 * @param array  $row     Product row from SFLS_Products.
	 * @param bool   $popular Highlight.
	 * @param array  $atts    Shortcode attributes (renewal_note, button, button_lifetime).
	 */
	private function pricing_card( string $plan, array $row, bool $popular, array $atts = array() ): void {
		$product = wc_get_product( $row['id'] );
		if ( ! $product ) {
			return;
		}
		$features = array_values( array_unique( array_merge( SFLS_Plans::feature_label_list( $plan, true ), array_filter( SFLS_Plans::common_items(), 'strlen' ) ) ) );

		if ( 0 === $row['months'] ) {
			$suffix = __( 'one-time', 'subtleforms-license-server' );
		} elseif ( 12 === $row['months'] ) {
			$suffix = __( '/year', 'subtleforms-license-server' );
		} else {
			/* translators: %d: months */
			$suffix = sprintf( _n( '/ %d month', '/ %d months', $row['months'], 'subtleforms-license-server' ), $row['months'] );
		}

		$price   = (float) wc_get_price_to_display( $product );
		$regular = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );

		echo '<div class="sfls-plan' . ( $popular ? ' is-popular' : '' ) . '">';
		if ( $popular ) {
			echo '<span class="sfls-plan-badge">' . esc_html__( 'Most popular', 'subtleforms-license-server' ) . '</span>';
		}
		echo '<h3 class="sfls-plan-name">' . esc_html( SFLS_Plans::label( $plan ) ) . '</h3>';
		echo '<p class="sfls-plan-sites">' . esc_html( SFLS_Plans::sites_label( (int) $row['sites'] ) ) . '</p>';
		echo '<p class="sfls-plan-price">';
		if ( $product->is_on_sale() && $regular > $price ) {
			echo '<del class="sfls-plan-was">' . wp_kses_post( wc_price( $regular ) ) . '</del> ';
		}
		echo '<span class="sfls-plan-amount">' . wp_kses_post( wc_price( $price ) ) . '</span> <span class="sfls-plan-period">' . esc_html( $suffix ) . '</span></p>';
		if ( ! empty( $atts['renewal_note'] ) ) {
			$note = $this->renewal_note( $plan, $row );
			if ( '' !== $note ) {
				echo '<p class="sfls-plan-renewal">' . esc_html( $note ) . '</p>';
			}
		}
		$template = (string) ( 0 === $row['months'] ? ( $atts['button_lifetime'] ?? '' ) : ( $atts['button'] ?? '' ) );
		/* translators: %s: plan name */
		$label = '' !== trim( $template ) ? str_replace( '{plan}', SFLS_Plans::label( $plan ), $template ) : sprintf( __( 'Buy %s', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) );
		echo '<a class="sfls-btn sfls-btn--' . ( $popular ? 'primary' : 'secondary' ) . ' sfls-btn--block" href="' . esc_url( SFLS_Products::buy_url( $row['id'] ) ) . '" rel="nofollow">';
		echo esc_html( $label ) . '</a>';
		echo '<ul class="sfls-plan-features">';
		foreach ( $features as $feature ) {
			echo '<li>' . esc_html( $feature ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * "Renews at $39 a year" for yearly cards, "Pay once, updates for life"
	 * for lifetime cards.
	 *
	 * @param string $plan Plan slug.
	 * @param array  $row  Product row.
	 */
	private function renewal_note( string $plan, array $row ): string {
		if ( 0 === $row['months'] ) {
			return __( 'Pay once. Updates and support for life.', 'subtleforms-license-server' );
		}
		$renewal = SFLS_Products::find( $plan, 'renewal' );
		$product = $renewal ? wc_get_product( $renewal['id'] ) : null;
		if ( ! $product || '' === (string) $product->get_price() ) {
			return __( 'Renews yearly. Cancel any time.', 'subtleforms-license-server' );
		}
		return sprintf(
			/* translators: %s: renewal price */
			__( 'Renews at %s a year. Cancel any time.', 'subtleforms-license-server' ),
			html_entity_decode( wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' )
		);
	}

	/**
	 * [sfls_renewal_note]: renewal prices for marketing copy, computed from
	 * the products instead of typed into the page (LS-05).
	 */
	public function renewal_note_line(): string {
		$line = SFLS_Products::renewal_summary();
		return '' === $line ? '' : '<span class="sfls-renewal-note">' . esc_html( $line ) . '</span>';
	}

	/**
	 * [sfls_changelog]
	 *
	 * @param array|string $atts Attributes.
	 */
	public function changelog( $atts ): string {
		$atts     = shortcode_atts( array( 'limit' => 0 ), $atts, 'sfls_changelog' );
		$releases = SFLS_Releases::all();
		if ( (int) $atts['limit'] > 0 ) {
			$releases = array_slice( $releases, 0, (int) $atts['limit'] );
		}
		if ( ! $releases ) {
			return '';
		}
		$this->styles();
		ob_start();
		echo '<div class="sfls sfls-changelog-list">';
		foreach ( $releases as $i => $rel ) {
			echo '<article class="sfls-changelog-entry" id="' . esc_attr( 'v' . sanitize_title( $rel['version'] ) ) . '">';
			echo '<header><h3>' . esc_html( $rel['version'] ) . '</h3>';
			if ( 0 === $i && 'stable' === $rel['channel'] ) {
				echo '<span class="sfls-pill sfls-pill--ok">' . esc_html__( 'Latest', 'subtleforms-license-server' ) . '</span>';
			}
			if ( 'beta' === $rel['channel'] ) {
				echo '<span class="sfls-tag sfls-tag--beta">' . esc_html__( 'Beta', 'subtleforms-license-server' ) . '</span>';
			}
			$date = SFLS_Releases::date_label( $rel );
			if ( '' !== $date ) {
				echo '<time datetime="' . esc_attr( (string) $rel['updated'] ) . '">' . esc_html( $date ) . '</time>';
			}
			echo '</header>';
			echo '<div class="sfls-changelog-body">' . wp_kses_post( SFLS_Releases::changelog_html( $rel ) ) . '</div>';
			echo '</article>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * [sfls_downloads]: the members area download list, for any page.
	 */
	public function downloads(): string {
		if ( ! is_user_logged_in() ) {
			$this->styles();
			$account = function_exists( 'wc_get_page_permalink' ) && wc_get_page_id( 'myaccount' ) > 0 ? wc_get_page_permalink( 'myaccount' ) : wp_login_url( get_permalink() );
			return '<div class="sfls sfls-notice-box">' . esc_html__( 'Sign in to download SubtleForms Pro.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( $account ) . '">' . esc_html__( 'Sign in', 'subtleforms-license-server' ) . '</a></div>';
		}
		if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return '';
		}
		$this->styles();
		return SFLS_Account::downloads_html( wp_get_current_user() );
	}

	/**
	 * [sfls_latest_version]
	 */
	public function latest_version(): string {
		return esc_html( SFLS_Account::latest_version_label() );
	}
}
