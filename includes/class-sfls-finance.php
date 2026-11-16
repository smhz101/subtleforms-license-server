<?php
/**
 * Finance ledger: Paddle's own payout figures stored on WooCommerce orders.
 *
 * Paddle Billing is the merchant of record, so the truth about fees and
 * earnings is `details.payout_totals` of a transaction and `payout_totals` of
 * an adjustment (refund or chargeback). Amounts are kept as minor-unit
 * integers: `_sfls_paddle_totals` on the order, and `payout_totals` inside
 * the order's `_sfls_paddle_adjustments` entries. Nothing here changes
 * billing; it only records and reads.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capture and backfill Paddle money figures.
 */
final class SFLS_Finance {

	const META = '_sfls_paddle_totals';

	/**
	 * Hooks: figures follow order and refund changes without waiting for the cache.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_changed', array( 'SFLS_Metrics', 'flush' ) );
		add_action( 'woocommerce_order_refunded', array( 'SFLS_Metrics', 'flush' ) );
		add_action( 'woocommerce_delete_order', array( 'SFLS_Metrics', 'flush' ) );
	}

	/**
	 * Payout figures of a transaction as stored on an order.
	 *
	 * @param array $txn Paddle transaction.
	 * @return array|null Null when the transaction carries no payout totals yet.
	 */
	public static function txn_totals( array $txn ): ?array {
		$payout = $txn['details']['payout_totals'] ?? null;
		if ( ! is_array( $payout ) || ! isset( $payout['earnings'] ) ) {
			return null;
		}
		$out      = array(
			'currency' => strtoupper( (string) ( $payout['currency_code'] ?? $txn['currency_code'] ?? '' ) ),
			'subtotal' => (int) ( $payout['subtotal'] ?? 0 ),
			'tax'      => (int) ( $payout['tax'] ?? 0 ),
			'total'    => (int) ( $payout['total'] ?? 0 ),
			'fee'      => (int) ( $payout['fee'] ?? 0 ),
			'earnings' => (int) $payout['earnings'],
			'rate'     => (string) ( $payout['exchange_rate'] ?? '1' ),
		);
		$customer = $txn['details']['totals'] ?? null;
		if ( is_array( $customer ) && strtoupper( (string) ( $customer['currency_code'] ?? '' ) ) !== $out['currency'] ) {
			$out['customer'] = array(
				'currency' => strtoupper( (string) ( $customer['currency_code'] ?? '' ) ),
				'tax'      => (int) ( $customer['tax'] ?? 0 ),
				'total'    => (int) ( $customer['total'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Save a transaction's payout figures on an order (once).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $txn   Paddle transaction.
	 * @return bool Whether figures are stored afterwards.
	 */
	public static function store_txn( WC_Order $order, array $txn ): bool {
		if ( is_array( $order->get_meta( self::META ) ) && $order->get_meta( self::META ) ) {
			return true;
		}
		$totals = self::txn_totals( $txn );
		if ( ! $totals ) {
			return false;
		}
		$order->update_meta_data( self::META, $totals );
		$order->save_meta_data();
		SFLS_Metrics::flush();
		return true;
	}

	/**
	 * Payout figures of an approved adjustment, raw as Paddle sends them
	 * (all positive). The impact on earnings is -(earnings + retained_fee);
	 * the fee returned is fee - retained_fee.
	 *
	 * @param array $adj Paddle adjustment.
	 * @return array|null
	 */
	public static function adjustment_totals( array $adj ): ?array {
		$payout = $adj['payout_totals'] ?? null;
		if ( ! is_array( $payout ) || ! isset( $payout['earnings'] ) ) {
			return null;
		}
		return array(
			'currency'     => strtoupper( (string) ( $payout['currency_code'] ?? $adj['currency_code'] ?? '' ) ),
			'total'        => (int) ( $payout['total'] ?? 0 ),
			'tax'          => (int) ( $payout['tax'] ?? 0 ),
			'fee'          => (int) ( $payout['fee'] ?? 0 ),
			'retained_fee' => (int) ( $payout['retained_fee'] ?? 0 ),
			'earnings'     => (int) $payout['earnings'],
		);
	}

	/**
	 * Save an approved adjustment's payout figures in the order's adjustment map.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $adj   Paddle adjustment.
	 */
	public static function store_adjustment( WC_Order $order, array $adj ): void {
		$id     = (string) ( $adj['id'] ?? '' );
		$totals = self::adjustment_totals( $adj );
		if ( '' === $id || 'approved' !== (string) ( $adj['status'] ?? '' ) || ! in_array( (string) ( $adj['action'] ?? '' ), array( 'refund', 'chargeback' ), true ) || ! $totals ) {
			return;
		}
		$map = $order->get_meta( '_sfls_paddle_adjustments' );
		$map = is_array( $map ) ? $map : array();
		if ( ( $map[ $id ]['payout_totals'] ?? null ) === $totals ) {
			return;
		}
		$map[ $id ] = array_merge(
			(array) ( $map[ $id ] ?? array(
				'source' => 'paddle',
				'action' => (string) ( $adj['action'] ?? '' ),
				'status' => 'approved',
			) ),
			array( 'payout_totals' => $totals )
		);
		$order->update_meta_data( '_sfls_paddle_adjustments', $map );
		$order->save_meta_data();
		SFLS_Metrics::flush();
	}

	/**
	 * Paddle-paid orders without stored figures.
	 *
	 * @param int $limit Max orders (0 = all).
	 * @return WC_Order[]
	 */
	public static function missing( int $limit = 0 ): array {
		$orders = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => array( 'processing', 'completed', 'refunded' ),
				'limit'      => $limit > 0 ? $limit : -1,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_sfls_paddle_paid_txn',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return array_values(
			array_filter(
				$orders,
				static fn( $o ) => $o instanceof WC_Order && str_starts_with( (string) $o->get_meta( '_sfls_paddle_paid_txn' ), 'txn_' )
			)
		);
	}

	/**
	 * Fetch figures from Paddle (read-only) for orders that lack them.
	 *
	 * @param int  $limit   Max orders (0 = all).
	 * @param bool $dry_run Fetch but store nothing.
	 * @return array[] Rows: order, txn, result, fee, earnings, refunds.
	 */
	public static function backfill( int $limit = 0, bool $dry_run = false ): array {
		$rows = array();
		foreach ( self::missing( $limit ) as $order ) {
			$txn_id = (string) $order->get_meta( '_sfls_paddle_paid_txn' );
			$row    = array(
				'order'    => $order->get_id(),
				'txn'      => $txn_id,
				'result'   => '',
				'fee'      => '',
				'earnings' => '',
				'refunds'  => 0,
			);
			$txn    = SFLS_Paddle::get_transaction( $txn_id );
			if ( is_wp_error( $txn ) ) {
				$row['result'] = 'error: ' . $txn->get_error_message();
				$rows[]        = $row;
				continue;
			}
			$totals = self::txn_totals( $txn );
			if ( ! $totals ) {
				$row['result'] = 'no payout totals yet';
				$rows[]        = $row;
				continue;
			}
			$row['fee']      = SFLS_Paddle::to_decimal( (string) $totals['fee'], $totals['currency'] );
			$row['earnings'] = SFLS_Paddle::to_decimal( (string) $totals['earnings'], $totals['currency'] );
			$adjustments     = SFLS_Paddle::request( 'GET', '/adjustments', null, array( 'transaction_id' => $txn_id ) );
			$adjustments     = is_wp_error( $adjustments ) ? array() : array_filter( $adjustments, static fn( $a, $k ) => '_meta' !== $k && is_array( $a ) && isset( $a['id'] ), ARRAY_FILTER_USE_BOTH );
			if ( ! $dry_run ) {
				self::store_txn( $order, $txn );
				foreach ( $adjustments as $adj ) {
					self::store_adjustment( $order, $adj );
				}
			}
			foreach ( $adjustments as $adj ) {
				$row['refunds'] += self::adjustment_totals( $adj ) && 'approved' === ( $adj['status'] ?? '' ) ? 1 : 0;
			}
			$row['result'] = $dry_run ? 'would store' : 'stored';
			$rows[]        = $row;
		}
		return $rows;
	}

	/**
	 * Admin button: backfill up to 50 orders.
	 */
	public static function handle_sync(): void {
		check_admin_referer( 'sfls_finance_sync' );
		if ( ! current_user_can( SFLS_Dashboard::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this page.', 'subtleforms-license-server' ), '', array( 'response' => 403 ) );
		}
		$rows = self::backfill( 50 );
		SFLS_Metrics::flush();
		$ok   = count( array_filter( $rows, static fn( $r ) => 'stored' === $r['result'] ) );
		$back = wp_get_referer();
		$back = $back ? $back : admin_url( 'admin.php?page=' . SFLS_Dashboard::SLUG );
		wp_safe_redirect( add_query_arg( array( 'sfls_synced' => $ok . '-' . count( $rows ) ), $back ) );
		exit;
	}
}
