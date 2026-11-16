<?php
/**
 * Finance ledger: Paddle's payout figures, the KPI definitions built on them,
 * the backfill and the menu highlight.
 *
 * Fixtures live in 2001 months no other test uses, with @sfls-test addresses
 * cleaned up by the base class.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals

/**
 * Finance tests.
 */
class SFLS_Finance_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;
	use SFLS_Woo_Paddle_Helpers;

	/**
	 * Store currency.
	 */
	private function cur(): string {
		return get_woocommerce_currency();
	}

	/**
	 * A transaction payload with payout totals (minor units).
	 *
	 * @param string $id       Transaction ID.
	 * @param int    $total    Customer total.
	 * @param int    $tax      Tax.
	 * @param int    $fee      Paddle fee.
	 * @param int    $earnings Earnings.
	 */
	private function payout_txn( string $id, int $total, int $tax, int $fee, int $earnings ): array {
		$cur = $this->cur();
		return array(
			'id'            => $id,
			'status'        => 'completed',
			'currency_code' => $cur,
			'details'       => array(
				'totals'        => array(
					'subtotal'      => (string) ( $total - $tax ),
					'tax'           => (string) $tax,
					'total'         => (string) $total,
					'currency_code' => $cur,
				),
				'payout_totals' => array(
					'subtotal'      => (string) ( $total - $tax ),
					'discount'      => '0',
					'tax'           => (string) $tax,
					'total'         => (string) $total,
					'fee'           => (string) $fee,
					'earnings'      => (string) $earnings,
					'currency_code' => $cur,
				),
			),
		);
	}

	/**
	 * A paid license order.
	 *
	 * @param float       $total Total.
	 * @param string      $paid  Paid date.
	 * @param string|null $txn   Paddle transaction it was paid by (null = not Paddle).
	 * @param array|null  $payload Transaction payload to store figures from.
	 */
	private function sale( float $total, string $paid, ?string $txn = null, ?array $payload = null ): WC_Order {
		$order = $this->make_order( array( $this->make_license_product( 'pro', 12, (string) $total ) ) );
		$order->set_total( (string) $total );
		$order->set_currency( $this->cur() );
		$order->set_date_paid( strtotime( $paid . ' 12:00:00 UTC' ) );
		$order->set_status( 'completed' );
		$order->update_meta_data( '_sfls_issued', 'yes' );
		if ( $txn ) {
			$order->set_transaction_id( $txn );
			$order->update_meta_data( '_sfls_paddle_paid_txn', $txn );
		}
		$order->save();
		$this->make_license(
			array(
				'plan'     => 'pro',
				'order_id' => $order->get_id(),
			)
		);
		if ( $payload ) {
			$this->assertTrue( SFLS_Finance::store_txn( $order, $payload ) );
		}
		SFLS_Metrics::flush();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * An approved refund adjustment as Paddle sends it.
	 *
	 * @param string $id       Adjustment ID.
	 * @param string $txn      Transaction ID.
	 * @param int    $total    Refunded (minor units).
	 * @param int    $fee      Fee returned.
	 * @param int    $earnings Earnings (negative).
	 */
	private function refund_adjustment( string $id, string $txn, int $total, int $fee, int $earnings, int $retained = 0 ): array {
		$cur = $this->cur();
		return array(
			'id'             => $id,
			'action'         => 'refund',
			'status'         => 'approved',
			'transaction_id' => $txn,
			'currency_code'  => $cur,
			'reason'         => 'finance test',
			'totals'         => array(
				'total'         => (string) $total,
				'currency_code' => $cur,
			),
			'payout_totals'  => array(
				'subtotal'      => (string) $total,
				'tax'           => '0',
				'total'         => (string) $total,
				'fee'           => (string) $fee,
				'retained_fee'  => (string) $retained,
				'earnings'      => (string) $earnings,
				'currency_code' => $cur,
			),
		);
	}

	/**
	 * Move the refunds of an order to a date.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $date  Date.
	 */
	private function date_refunds( WC_Order $order, string $date ): void {
		foreach ( wc_get_order( $order->get_id() )->get_refunds() as $refund ) {
			$refund->set_date_created( strtotime( $date . ' 12:00:00 UTC' ) );
			$refund->save();
		}
		SFLS_Metrics::flush();
	}

	/**
	 * The owner's order: 49 sold, fee 2.95, fully refunded, fee retained = a 2.95 loss.
	 */
	public function test_fully_refunded_order_is_a_loss(): void {
		$from = '2001-05-01 00:00:00';
		$to   = '2001-05-31 23:59:59';
		$txn  = 'txn_fin_owner';
		$order = $this->sale( 49, '2001-05-10', $txn, $this->payout_txn( $txn, 4900, 0, 295, 4605 ) );
		$lic   = $this->licenses_of_order( $order->get_id() )[0];
		$this->assertSame( 'active', $lic['status'] );
		$active = SFLS_Metrics::active_at( gmdate( 'Y-m-d H:i:s', time() + 60 ) );

		$result = SFLS_Woo_Paddle_Orders::adjustment( $this->refund_adjustment( 'adj_fin_owner', $txn, 4900, 295, 4605, 295 ) );
		$this->assertSame( 'refunded', $result['action'] );
		$this->date_refunds( $order, '2001-05-12' );

		$m = SFLS_Metrics::money( $from, $to );
		$this->assertEqualsWithDelta( 49.0, $m['gross']['amount'], 0.001 );
		$this->assertEqualsWithDelta( 49.0, $m['refunds']['amount'], 0.001 );
		$this->assertEqualsWithDelta( 0.0, $m['net_sales']['amount'], 0.001 );
		$this->assertEqualsWithDelta( 2.95, $m['fees']['amount'], 0.001 );
		$this->assertEqualsWithDelta( -2.95, $m['earnings']['amount'], 0.001 );
		$this->assertFalse( $m['earnings']['estimated'] );
		$this->assertSame( 1, $m['gross']['count'] );
		$this->assertEqualsWithDelta( -2.95, SFLS_Metrics::earnings( $from, $to ), 0.001 );

		$k = SFLS_Metrics::kpis( $from, $to );
		$this->assertEqualsWithDelta( -2.95, $k['earnings']['value'], 0.001 );
		$this->assertEqualsWithDelta( 49.0, $k['revenue']['value'], 0.001 );
		$this->assertEqualsWithDelta( 100.0, $k['refund_rate']['value'], 0.001 );
		$this->assertSame( 1, $k['refund_rate']['n'] );

		// The license of the refunded order no longer counts as active.
		$this->assertSame( $active - 1, SFLS_Metrics::active_at( gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );

		// The payout figures are kept in minor units.
		$stored = wc_get_order( $order->get_id() )->get_meta( '_sfls_paddle_totals' );
		$this->assertSame( 295, $stored['fee'] );
		$this->assertSame( 4605, $stored['earnings'] );
		$adj = wc_get_order( $order->get_id() )->get_meta( '_sfls_paddle_adjustments' );
		$this->assertSame( 4605, $adj['adj_fin_owner']['payout_totals']['earnings'] );
		$this->assertSame( 295, $adj['adj_fin_owner']['payout_totals']['retained_fee'] );
	}

	/**
	 * A refund that returns the fee in full (retained_fee 0) costs only the earnings.
	 */
	public function test_refund_with_fee_returned(): void {
		$txn   = 'txn_fin_fullfee';
		$order = $this->sale( 49, '2001-09-10', $txn, $this->payout_txn( $txn, 4900, 0, 295, 4605 ) );
		SFLS_Woo_Paddle_Orders::adjustment( $this->refund_adjustment( 'adj_fin_ff', $txn, 4900, 295, 4605, 0 ) );
		$this->date_refunds( $order, '2001-09-12' );
		$m = SFLS_Metrics::money( '2001-09-01 00:00:00', '2001-09-30 23:59:59' );
		$this->assertEqualsWithDelta( 0.0, $m['fees']['amount'], 0.001 );
		$this->assertEqualsWithDelta( 0.0, $m['earnings']['amount'], 0.001 );
	}

	/**
	 * Without a license order on the refunded order too: a full refund of a license not suspended by the plugin is still not "active".
	 */
	public function test_license_on_refunded_order_not_active(): void {
		$order  = $this->sale( 30, '2001-05-20' );
		$before = SFLS_Metrics::active_at( gmdate( 'Y-m-d H:i:s', time() + 60 ) );
		$lic    = $this->licenses_of_order( $order->get_id() )[0];
		SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'active' ) );
		$order->set_status( 'refunded' );
		$order->save();
		SFLS_Metrics::flush();
		$this->assertSame( $before - 1, SFLS_Metrics::active_at( gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );
	}

	/**
	 * A renewal order is counted in gross and earnings, with tax apart.
	 */
	public function test_renewal_order_in_gross_and_earnings(): void {
		$parent = $this->sale( 79, '2001-05-02' );
		$lic    = $this->licenses_of_order( $parent->get_id() )[0];
		SFLS_Licenses::update( (int) $lic['id'], array( 'billing' => 'paddle', 'billing_status' => 'active' ) );
		$txn   = $this->payout_txn( 'txn_fin_renew', 8900, 1000, 445, 7455 ) + array( 'subscription_id' => 'sub_fin_renew' );
		$order = SFLS_Woo_Paddle_Orders::renewal_order( SFLS_Licenses::find( (int) $lic['id'] ), $txn );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->orders[] = $order->get_id();
		$order->set_date_paid( strtotime( '2001-06-10 12:00:00 UTC' ) );
		$order->save();
		SFLS_Metrics::flush();

		$m = SFLS_Metrics::money( '2001-06-01 00:00:00', '2001-06-30 23:59:59' );
		$this->assertEqualsWithDelta( 89.0, $m['gross']['amount'], 0.001 );
		$this->assertEqualsWithDelta( 10.0, $m['tax']['amount'], 0.001 );
		$this->assertEqualsWithDelta( 4.45, $m['fees']['amount'], 0.001 );
		$this->assertEqualsWithDelta( 74.55, $m['earnings']['amount'], 0.001 );
		$this->assertFalse( $m['earnings']['estimated'] );
		$this->assertSame( 1, $m['gross']['count'] );
		// A repeated webhook does not store twice.
		$this->assertSame( $order->get_id(), SFLS_Woo_Paddle_Orders::renewal_order( SFLS_Licenses::find( (int) $lic['id'] ), $txn )->get_id() );
	}

	/**
	 * Orders without Paddle totals give estimated figures; the filter can drop an order.
	 */
	public function test_estimated_and_exclusion(): void {
		$from  = '2001-07-01 00:00:00';
		$to    = '2001-07-31 23:59:59';
		$plain = $this->sale( 30, '2001-07-05' );
		$m     = SFLS_Metrics::money( $from, $to );
		$this->assertTrue( $m['earnings']['estimated'] );
		$this->assertTrue( $m['fees']['estimated'] );
		$this->assertFalse( $m['gross']['estimated'] );
		$this->assertEqualsWithDelta( 30.0, $m['earnings']['amount'], 0.001 );
		$this->assertTrue( SFLS_Metrics::kpis( $from, $to )['earnings']['estimated'] );

		$this->filter( 'sfls_metrics_exclude_order', static fn( $skip, $order ) => $order->get_id() === $plain->get_id() ? true : $skip, 10, 2 );
		SFLS_Metrics::flush();
		$this->assertEqualsWithDelta( 0.0, SFLS_Metrics::money( $from, $to )['gross']['amount'], 0.001 );
	}

	/**
	 * MRR leaves out canceling subscriptions; projections and at-risk amounts.
	 */
	public function test_mrr_and_projections(): void {
		$cur  = $this->cur();
		$mrr  = SFLS_Metrics::mrr();
		$proj = SFLS_Metrics::projections();
		$dun  = SFLS_Metrics::dunning();
		$base = array(
			'billing'          => 'paddle',
			'billing_status'   => 'active',
			'billing_currency' => $cur,
		);
		$this->make_license( $base + array( 'billing_amount' => '2400', 'paddle_subscription_id' => 'sub_fin_a', 'renews_at' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ) );
		$this->make_license( $base + array( 'billing_amount' => '4800', 'paddle_subscription_id' => 'sub_fin_b', 'renews_at' => gmdate( 'Y-m-d H:i:s', time() + 45 * DAY_IN_SECONDS ) ) );
		$this->make_license( $base + array( 'billing_amount' => '9900', 'paddle_subscription_id' => 'sub_fin_c', 'cancels_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ) );
		$this->make_license( array_merge( $base, array( 'billing_status' => 'past_due' ) ) + array( 'billing_amount' => '1200', 'paddle_subscription_id' => 'sub_fin_d', 'renews_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
		SFLS_Metrics::flush();

		$this->assertEqualsWithDelta( $mrr + ( 24 + 48 + 12 ) / 12, SFLS_Metrics::mrr(), 0.02 );
		$after = SFLS_Metrics::projections();
		$this->assertSame( $proj[30]['count'] + 1, $after[30]['count'] );
		$this->assertEqualsWithDelta( $proj[30]['amount'] + 24, $after[30]['amount'], 0.001 );
		$this->assertSame( $proj[60]['count'] + 2, $after[60]['count'] );
		$this->assertEqualsWithDelta( $proj[90]['amount'] + 72, $after[90]['amount'], 0.001 );
		$this->assertEqualsWithDelta( $dun['amount'] + 12, SFLS_Metrics::dunning()['amount'], 0.001 );
	}

	/**
	 * Rates are null (with n = 0) when nothing is due; no percent change from zero.
	 */
	public function test_rates_and_deltas_without_denominator(): void {
		$k = SFLS_Metrics::kpis( '1990-01-01 00:00:00', '1990-01-31 23:59:59' );
		foreach ( array( 'renewal_rate', 'churn', 'refund_rate' ) as $key ) {
			$this->assertNull( $k[ $key ]['value'], $key );
			$this->assertSame( 0, $k[ $key ]['n'], $key );
			$this->assertSame( '—', SFLS_Dashboard::format_value( $k[ $key ]['value'], 'percent' ) );
		}
		$this->assertNull( SFLS_Dashboard::delta( 'revenue', array( 'value' => 10.0, 'previous' => 0.0, 'format' => 'money' ) ) );
		$this->assertNotNull( SFLS_Dashboard::delta( 'revenue', array( 'value' => 10.0, 'previous' => 5.0, 'format' => 'money' ) ) );
	}

	/**
	 * Paying customers leave out licenses issued by hand.
	 */
	public function test_paying_customers_skip_manual(): void {
		$before = SFLS_Metrics::paying_customers();
		$this->make_license( array( 'plan' => 'pro' ) );
		SFLS_Metrics::flush();
		$this->assertSame( $before, SFLS_Metrics::paying_customers(), 'A manual license is not a paying customer.' );
		$this->sale( 20, '2001-07-15' );
		$this->assertSame( $before + 1, SFLS_Metrics::paying_customers() );
	}

	/**
	 * Completing a Paddle checkout stores the payout figures.
	 */
	public function test_checkout_completion_stores_totals(): void {
		$this->woo_paddle();
		$this->cart_plan( 'pro' );
		$order = $this->checkout_order( $this->email() );
		$this->pay( $order );
		$payout = array(
			'subtotal'      => '7900',
			'tax'           => '0',
			'total'         => '7900',
			'fee'           => '445',
			'earnings'      => '7455',
			'currency_code' => 'USD',
		);
		$txn      = $this->completed_txn( $order, array( 'details' => array( 'payout_totals' => $payout ) ) );
		$response = $this->webhook( 'transaction.completed', $txn );
		$this->assertSame( 200, $response->get_status() );
		$totals = wc_get_order( $order->get_id() )->get_meta( '_sfls_paddle_totals' );
		$this->assertSame( 445, $totals['fee'] );
		$this->assertSame( 7455, $totals['earnings'] );
		$this->assertSame( 'USD', $totals['currency'] );
	}

	/**
	 * The backfill stores totals and refund payouts from Paddle, and is idempotent.
	 */
	public function test_backfill(): void {
		$this->paddle();
		$a   = $this->sale( 49, '2001-08-10', 'txn_fin_bf_a' );
		$b   = $this->sale( 20, '2001-08-11', 'txn_fin_bf_b' );
		$ids = array( $a->get_id(), $b->get_id() );
		$this->paddle_routes['#^GET /transactions/txn_fin_bf_\w$#'] = fn( $call ) => 'txn_fin_bf_a' === basename( $call['path'] )
			? $this->payout_txn( 'txn_fin_bf_a', 4900, 0, 295, 4605 )
			: $this->payout_txn( 'txn_fin_bf_b', 2000, 0, 130, 1870 );
		$this->paddle_routes['GET /adjustments']                    = fn( $call ) => 'txn_fin_bf_a' === ( $call['query']['transaction_id'] ?? '' )
			? array( $this->refund_adjustment( 'adj_fin_bf', 'txn_fin_bf_a', 4900, 295, 4605, 295 ) )
			: array();

		$dry  = array_filter( SFLS_Finance::backfill( 0, true ), static fn( $r ) => in_array( $r['order'], $ids, true ) );
		$this->assertCount( 2, $dry );
		$this->assertSame( '', (string) wc_get_order( $a->get_id() )->get_meta( '_sfls_paddle_totals' ) );

		$rows = array_filter( SFLS_Finance::backfill(), static fn( $r ) => in_array( $r['order'], $ids, true ) );
		$this->assertSame( array( 'stored', 'stored' ), array_values( wp_list_pluck( $rows, 'result' ) ) );
		$this->assertSame( 295, wc_get_order( $a->get_id() )->get_meta( '_sfls_paddle_totals' )['fee'] );
		$this->assertSame( 130, wc_get_order( $b->get_id() )->get_meta( '_sfls_paddle_totals' )['fee'] );
		$this->assertSame( 4605, wc_get_order( $a->get_id() )->get_meta( '_sfls_paddle_adjustments' )['adj_fin_bf']['payout_totals']['earnings'] );
		$this->assertCount( 0, $this->calls( 'POST', '/adjustments' ) );

		$fetched = count( $this->calls( 'GET', '#^/transactions/txn_fin_bf_\w$#' ) );
		$again   = array_filter( SFLS_Finance::backfill(), static fn( $r ) => in_array( $r['order'], $ids, true ) );
		$this->assertCount( 0, $again, 'Orders with figures are skipped.' );
		$this->assertCount( $fetched, $this->calls( 'GET', '#^/transactions/txn_fin_bf_\w$#' ), 'No second fetch.' );
	}

	/**
	 * The menu highlight survives the core plugin blanking $submenu_file.
	 */
	public function test_menu_filters(): void {
		SFLS_Admin::highlight_hooks();
		foreach ( array( 'sfls', 'sfls-dashboard', 'sfls-new', 'sfls-products', 'sfls-release', 'sfls-settings', 'sfls-reports' ) as $page ) {
			$_GET['page'] = $page;
			$this->assertSame( $page, apply_filters( 'submenu_file', '', 'sfls' ), $page );
			$this->assertSame( 'sfls', apply_filters( 'parent_file', 'index.php' ), $page );
		}
		$_GET['page'] = 'sfls-customer';
		$this->assertSame( 'sfls', apply_filters( 'submenu_file', '', 'options.php' ) );
		$this->assertSame( 'sfls', apply_filters( 'parent_file', 'options.php' ) );
		$_GET['page'] = 'something-else';
		$this->assertSame( 'x', apply_filters( 'submenu_file', 'x', 'y' ) );
		$this->assertSame( 'y', apply_filters( 'parent_file', 'y' ) );
		$_GET = array();
		remove_filter( 'submenu_file', array( 'SFLS_Admin', 'submenu_file' ), 20 );
		remove_filter( 'parent_file', array( 'SFLS_Admin', 'parent_file' ), 20 );
	}
}
