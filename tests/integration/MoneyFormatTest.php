<?php
/**
 * Money precision and $0 orders.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Minor-unit money formatting and free-order exclusion.
 */
class SFLS_Money_Format_Test extends SFLS_Test_Case {

	/**
	 * Cents survive a zero-decimal WooCommerce display setting.
	 */
	public function test_cents_ignore_woo_display_decimals(): void {
		$this->filter( 'woocommerce_currency', static fn() => 'USD' );
		$this->filter( 'wc_get_price_decimals', static fn() => 0 );
		$this->filter( 'pre_option_woocommerce_price_num_decimals', static fn() => 0 );
		$this->assertSame( '−$2.95', SFLS_Dashboard::money_text( -2.95 ) );
		$this->assertSame( '−$2.95', SFLS_Dashboard::format_value( -2.95, 'money' ) );
		$this->assertSame( '$2.95', SFLS_Metrics::format_money( 2.95, 'USD', true ) );
		$this->assertSame( '$1.2k', SFLS_Metrics::format_money( 1234.0, 'USD', true ) );
		$this->assertStringNotContainsString( '.', SFLS_Metrics::format_money( 1500.0, 'JPY' ) );
		$this->assertSame( 0, SFLS_Metrics::decimals( 'JPY' ) );
		$this->assertSame( 2, SFLS_Metrics::decimals( 'USD' ) );
	}

	/**
	 * A $0 order is neither paid nor refunded for the rate.
	 */
	public function test_free_orders_are_not_counted(): void {
		$product = $this->make_license_product( 'pro', 12, '49' );
		foreach ( array( 0, 49 ) as $total ) {
			$order = $this->make_order( array( $product ), null, array( array() ) );
			$order->update_meta_data( '_sfls_issued', 'yes' );
			$order->set_total( (string) $total );
			$order->set_date_paid( strtotime( '2003-03-05 12:00:00 UTC' ) );
			$order->set_status( 'completed' );
			$order->save();
		}
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 49, 'reason' => 'x' ) );
		$this->assertFalse( is_wp_error( $refund ) );
		SFLS_Metrics::flush();
		$stats = SFLS_Metrics::refund_stats( '2003-03-01 00:00:00', '2003-03-31 23:59:59' );
		$this->assertSame( 1, $stats['n'] );
		$this->assertEqualsWithDelta( 100.0, $stats['rate'], 0.001 );
		$this->assertSame( 1, SFLS_Metrics::money( '2003-03-01 00:00:00', '2003-03-31 23:59:59' )['gross']['count'] );
	}
}
