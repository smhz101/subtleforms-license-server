<?php
/**
 * Prorated upgrades.
 *
 * An upgrade started from My Account › Licenses keeps the license key and
 * moves it to a bigger plan. The customer pays only the difference between the
 * two plans for the time left on the current term:
 *
 *   charge = ( new plan price - current plan price ) × days left / 365
 *
 * and the expiry date stays where it is. A lifetime license upgrading to a
 * lifetime plan pays the difference between the two lifetime prices. A license
 * that has already expired (grace period) pays the full price and starts a new
 * term from today. Prices are the current prices of the plans' license
 * products.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upgrade quotes and cart pricing.
 */
final class SFLS_Upgrades {

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
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line_item' ), 20, 3 );
	}

	/**
	 * Current price of a plan's license product.
	 *
	 * @param string $plan Plan slug.
	 * @param string $type yearly | lifetime.
	 */
	public static function plan_price( string $plan, string $type ): ?float {
		$row = SFLS_Products::find( $plan, $type );
		if ( ! $row ) {
			return null;
		}
		$product = wc_get_product( $row['id'] );
		return $product ? (float) $product->get_price( 'edit' ) : null;
	}

	/**
	 * Price an upgrade.
	 *
	 * @param array    $lic        License being upgraded.
	 * @param int      $product_id Target license product.
	 * @param int|null $now        Timestamp (for tests).
	 * @return array{full:float,charge:float,credit:float,ratio:float,keep_expiry:bool,days_left:int}|null
	 */
	public static function quote( array $lic, int $product_id, ?int $now = null ): ?array {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! SFLS_Woo::is_license_product( $product ) ) {
			return null;
		}
		$now      = $now ?? time();
		$full     = (float) $product->get_price( 'edit' );
		$type     = SFLS_Products::type_of( $product_id );
		$state    = SFLS_Licenses::state( $lic );
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$quote    = array(
			'full'        => $full,
			'charge'      => $full,
			'credit'      => 0.0,
			'ratio'       => 1.0,
			'keep_expiry' => false,
			'days_left'   => 0,
		);

		if ( 'valid' !== $state['status'] ) {
			return $quote; // Expired or in grace: full price, new term.
		}

		// A Paddle subscription: nothing is charged during a trial (the new
		// plan is billed when it ends), otherwise the difference for the rest
		// of the billing year, which Paddle charges with prorated_immediately.
		if ( SFLS_Licenses::is_paddle( $lic ) && 'lifetime' !== $type ) {
			if ( 'trialing' === $lic['billing_status'] ) {
				$quote['charge']      = 0.0;
				$quote['credit']      = $full;
				$quote['keep_expiry'] = true;
				return $quote;
			}
			if ( ! empty( $lic['renews_at'] ) ) {
				$lic['expires_at'] = $lic['renews_at'];
			}
		}

		if ( empty( $lic['expires_at'] ) ) {
			if ( 'lifetime' === $type ) {
				$current = self::plan_price( (string) $lic['plan'], 'lifetime' );
				if ( null !== $current ) {
					$quote['charge']      = round( max( 0.0, $full - $current ), $decimals );
					$quote['keep_expiry'] = true;
				}
			}
			$quote['credit'] = round( $full - $quote['charge'], $decimals );
			return $quote;
		}

		if ( 'lifetime' === $type ) {
			return $quote; // Yearly to lifetime: a new lifetime license price.
		}

		$current = self::plan_price( (string) $lic['plan'], 'yearly' );
		if ( null === $current ) {
			return $quote;
		}
		$left  = max( 0, strtotime( $lic['expires_at'] . ' UTC' ) - $now );
		$ratio = min( 1.0, $left / ( 365 * DAY_IN_SECONDS ) );

		$quote['ratio']       = $ratio;
		$quote['days_left']   = (int) ceil( $left / DAY_IN_SECONDS );
		$quote['charge']      = round( max( 0.0, ( $full - $current ) * $ratio ), $decimals );
		$quote['credit']      = round( $full - $quote['charge'], $decimals );
		$quote['keep_expiry'] = true;
		return $quote;
	}

	/**
	 * Quote for a cart item, or null when it is not an upgrade the current
	 * customer may make.
	 *
	 * @param array $cart_item Cart item.
	 */
	private static function cart_quote( array $cart_item ): ?array {
		if ( empty( $cart_item['sfls_upgrade_license'] ) ) {
			return null;
		}
		$lic  = SFLS_Licenses::find( (int) $cart_item['sfls_upgrade_license'] );
		$user = wp_get_current_user();
		if ( ! $lic || ! $user->ID || ! SFLS_Licenses::user_owns( $lic, $user ) ) {
			return null;
		}
		return self::quote( $lic, (int) $cart_item['product_id'] );
	}

	/**
	 * Charge the prorated amount for upgrade lines.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public function apply_prices( $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			$quote = self::cart_quote( $item );
			if ( $quote && isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
				$item['data']->set_price( $quote['charge'] );
			}
		}
	}

	/**
	 * Explain the price in cart and checkout.
	 *
	 * @param array $item_data Display rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function item_data( $item_data, $cart_item ) {
		$quote = self::cart_quote( (array) $cart_item );
		if ( $quote && $quote['credit'] > 0 ) {
			$item_data[] = array(
				'key'   => __( 'Prorated discount', 'subtleforms-license-server' ),
				'value' => '-' . self::plain_price( $quote['credit'] ),
			);
			if ( $quote['days_left'] > 0 ) {
				$item_data[] = array(
					'key'   => __( 'Covers', 'subtleforms-license-server' ),
					'value' => sprintf(
						/* translators: %d: days */
						_n( 'the %d day left on your license, same key and renewal date', 'the %d days left on your license, same key and renewal date', $quote['days_left'], 'subtleforms-license-server' ),
						$quote['days_left']
					),
				);
			}
		}
		return $item_data;
	}

	/**
	 * Remember on the order line how the upgrade was priced.
	 *
	 * @param WC_Order_Item_Product $item     Line item.
	 * @param string                $cart_key Cart key.
	 * @param array                 $values   Cart item.
	 */
	public function order_line_item( $item, $cart_key, $values ): void {
		$quote = self::cart_quote( (array) $values );
		if ( $quote ) {
			$item->add_meta_data( '_sfls_upgrade_credit', wc_format_decimal( $quote['credit'] ), true );
			$item->add_meta_data( '_sfls_upgrade_keep_expiry', $quote['keep_expiry'] ? 'yes' : 'no', true );
		}
	}

	/**
	 * Price as plain text ("$12.30").
	 *
	 * @param float $amount Amount.
	 */
	public static function plain_price( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
