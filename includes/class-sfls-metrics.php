<?php
/**
 * Business metrics for the dashboard and reports.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only queries over licenses, activations, WooCommerce orders and the
 * audit log. Ranges are UTC 'Y-m-d H:i:s' (both ends inclusive); results are
 * cached for 15 minutes.
 *
 * Definitions (money is in the store currency; every figure also has the
 * number of orders and an `estimated` flag, true when an order lacks Paddle's
 * payout totals, see SFLS_Finance):
 * - A license order is a paid order (processing, completed, refunded) with the
 *   `_sfls_issued` flag. Sales are counted by paid date, refunds by refund date.
 * - Gross sales: order totals paid in the range, tax included. Refunds: refund
 *   amounts created in the range. Net sales: gross minus refunds.
 * - Tax: tax collected by Paddle. Fees: Paddle fees on the sales minus fees
 *   returned on refunds (a fee kept on a refunded order stays a cost).
 * - Net earnings: Paddle's earnings (payout) of the sales plus the earnings of
 *   the refunds (negative). Without Paddle totals: total - tax, and refunds
 *   in full, marked estimated.
 * - Active licenses: status active, not expired, not on a fully refunded
 *   order, and when billed by Paddle with billing status active, trialing or
 *   past due. Paying customers: distinct emails of active licenses that were
 *   bought (not issued manually).
 * - MRR: billed amount (the subscription's unit price, before tax) / 12 of
 *   Paddle subscriptions that are active, trialing or past due and not
 *   canceling; ARR is MRR x 12. Projections: billed amounts of non-canceling
 *   subscriptions renewing in the next 30/60/90 days; at risk: past due.
 * - Renewed: licenses named by `_sfls_renew_license` on paid orders in the
 *   range. Due: renewed plus active licenses whose expiry still lies in the
 *   range. Churned: licenses whose Paddle subscription was canceled in the
 *   range (by last update), plus active licenses that expired in the range.
 *   Rates are null without a denominator and come with it (`n`).
 * - Active sites: non-local activations seen in the 30 days up to the end.
 * - Filter `sfls_metrics_exclude_order` (false, WC_Order) drops an order.
 */
final class SFLS_Metrics {

	const TTL = 900;

	/**
	 * Currencies without minor units (as in SFLS_Paddle).
	 */
	const ZERO_DECIMAL = array( 'JPY', 'KRW', 'VND', 'CLP', 'HUF', 'TWD' );

	/**
	 * Bust the cache.
	 */
	public static function flush(): void {
		global $wpdb;
		update_option( 'sfls_metrics_v', (int) get_option( 'sfls_metrics_v', 0 ) + 1, false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sfls\\_m\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sfls\\_m\\_%'" );
	}

	/**
	 * Cache a computation.
	 *
	 * @param string   $name Name.
	 * @param array    $args Arguments the result depends on.
	 * @param callable $compute Computation.
	 * @return mixed
	 */
	private static function cached( string $name, array $args, callable $compute ) {
		$key = 'sfls_m_' . md5( $name . wp_json_encode( $args ) . (int) get_option( 'sfls_metrics_v', 0 ) );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $hit;
		}
		$value = $compute();
		set_transient( $key, $value, self::TTL );
		return $value;
	}

	/**
	 * The previous period of the same length, ending one second before $from.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return string[] Two datetimes.
	 */
	public static function previous_range( string $from, string $to ): array {
		$start = strtotime( $from . ' UTC' );
		$len   = strtotime( $to . ' UTC' ) - $start;
		$end   = $start - 1;
		return array( gmdate( 'Y-m-d H:i:s', $end - $len ), gmdate( 'Y-m-d H:i:s', $end ) );
	}

	/**
	 * Plan clause for a licenses query.
	 *
	 * @param string $plan  Plan slug or ''.
	 * @param string $alias Table alias.
	 * @return array{0:string,1:array} SQL and arguments.
	 */
	private static function plan_sql( string $plan, string $alias = 'l' ): array {
		return '' === $plan ? array( '', array() ) : array( " AND {$alias}.plan = %s", array( $plan ) );
	}

	/**
	 * Count licenses.
	 *
	 * @param string $where Condition (uses alias l and %s placeholders).
	 * @param array  $args  Arguments.
	 * @param string $plan  Plan.
	 */
	private static function count( string $where, array $args, string $plan ): int {
		global $wpdb;
		[ $psql, $pargs ] = self::plan_sql( $plan );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SFLS_DB::licenses() . " l WHERE {$where}{$psql}", array_merge( $args, $pargs ) ) );
	}

	/**
	 * Licenses that were active at a moment.
	 *
	 * @param string $at   Datetime.
	 * @param string $plan Plan.
	 */
	public static function active_at( string $at, string $plan = '' ): int {
		return self::count( 'l.created_at <= %s AND ' . self::active_clause(), array( $at, $at ), $plan );
	}

	/**
	 * SQL for an active license (one %s: the moment).
	 */
	private static function active_clause(): string {
		$sql = "l.status = 'active' AND ( l.expires_at IS NULL OR l.expires_at > %s ) AND ( l.billing <> 'paddle' OR l.billing_status IN ( 'active', 'trialing', 'past_due' ) )";
		$ids = function_exists( 'wc_get_orders' ) ? array_map(
			'intval',
			(array) wc_get_orders(
				array(
					'type'   => 'shop_order',
					'status' => 'refunded',
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		) : array();
		return $ids ? $sql . ' AND l.order_id NOT IN ( ' . implode( ',', $ids ) . ' )' : $sql;
	}

	/**
	 * Distinct customers with an active, bought (not issued by hand) license.
	 *
	 * @param string $plan Plan.
	 */
	public static function paying_customers( string $plan = '' ): int {
		global $wpdb;
		[ $psql, $pargs ] = self::plan_sql( $plan );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT LOWER(l.customer_email)) FROM ' . SFLS_DB::licenses() . ' l WHERE l.billing <> \'manual\' AND ( l.billing = \'paddle\' OR l.order_id > 0 ) AND ' . self::active_clause() . $psql, array_merge( array( current_time( 'mysql', true ) ), $pargs ) ) );
	}

	/**
	 * Licenses created in a range.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function new_licenses( string $from, string $to, string $plan = '' ): int {
		return self::count( 'l.created_at >= %s AND l.created_at <= %s', array( $from, $to ), $plan );
	}

	/**
	 * Whether an order item sells the plan.
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @param string                $plan Plan or ''.
	 */
	private static function item_matches( $item, string $plan ): bool {
		return '' === $plan || 0 === strcmp( $plan, (string) get_post_meta( (int) $item->get_product_id(), '_sfls_plan', true ) );
	}

	/**
	 * Whether a license order (any item) sells the plan.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $plan  Plan.
	 */
	private static function order_matches( $order, string $plan ): bool {
		if ( '' === $plan ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && self::item_matches( $item, $plan ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Minor units to a major amount.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency Currency.
	 */
	private static function major( int $minor, string $currency ): float {
		return (float) SFLS_Paddle::to_decimal( (string) $minor, $currency );
	}

	/**
	 * Plan of an order: the plan of the license it renewed, else of its product.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function plan_of( WC_Order $order ): string {
		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			$id = (int) $item->get_meta( '_sfls_renew_license' );
			if ( $id > 0 ) {
				$lic = SFLS_Licenses::find( $id );
				return $lic ? (string) $lic['plan'] : '';
			}
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$plan = (string) get_post_meta( (int) $item->get_product_id(), '_sfls_plan', true );
				if ( '' !== $plan ) {
					return $plan;
				}
			}
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(plan) FROM ' . SFLS_DB::licenses() . ' WHERE order_id = %d', $order->get_id() ) );
	}

	/**
	 * Ledger row of a paid order: what the customer paid and, from Paddle's
	 * payout totals when stored, tax, fee and earnings.
	 *
	 * @param WC_Order $order Order.
	 * @return array{order_id:int,date:string,plan:string,country:string,gross:float,tax:float,fee:?float,earnings:float,estimated:bool}
	 */
	private static function sale_row( WC_Order $order ): array {
		$cur   = strtoupper( (string) $order->get_currency() );
		$t     = $order->get_meta( SFLS_Finance::META );
		$gross = (float) $order->get_total();
		$paid  = $order->get_date_paid();
		$row   = array(
			'order_id'  => $order->get_id(),
			'date'      => $paid ? gmdate( 'Y-m-d H:i:s', $paid->getTimestamp() ) : '',
			'plan'      => self::plan_of( $order ),
			'country'   => (string) $order->get_billing_country(),
			'gross'     => $gross,
			'tax'       => (float) $order->get_total_tax(),
			'fee'       => null,
			'earnings'  => $gross - (float) $order->get_total_tax(),
			'estimated' => true,
		);
		if ( is_array( $t ) && strtoupper( (string) ( $t['currency'] ?? '' ) ) === $cur && '1' === (string) ( $t['rate'] ?? '1' ) ) {
			$row['tax']       = self::major( (int) $t['tax'], $cur );
			$row['fee']       = self::major( (int) $t['fee'], $cur );
			$row['earnings']  = self::major( (int) $t['earnings'], $cur );
			$row['estimated'] = false;
		}
		return $row;
	}

	/**
	 * Paid license orders of a range.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 * @return array{paid:int,gross:float,refunded:int,renewed:int[],rows:array[]}
	 */
	public static function orders( string $from, string $to, string $plan = '' ): array {
		return self::cached(
			'orders',
			array( $from, $to, $plan ),
			static function () use ( $from, $to, $plan ) {
				$out    = array(
					'paid'     => 0,
					'gross'    => 0.0,
					'refunded' => 0,
					'renewed'  => array(),
					'rows'     => array(),
				);
				$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders(
					array(
						'type'       => 'shop_order',
						'status'     => array( 'processing', 'completed', 'refunded' ),
						'date_paid'  => strtotime( $from . ' UTC' ) . '...' . strtotime( $to . ' UTC' ),
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'   => '_sfls_issued',
								'value' => 'yes',
							),
						),
						'limit'      => -1,
					)
				) : array();
				foreach ( $orders as $order ) {
					if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( '_sfls_issued' ) || ! self::order_matches( $order, $plan ) || apply_filters( 'sfls_metrics_exclude_order', false, $order ) ) {
						continue;
					}
					++$out['paid'];
					$out['gross'] += (float) $order->get_total();
					$out['rows'][] = self::sale_row( $order );
					if ( (float) $order->get_total_refunded() > 0 ) {
						++$out['refunded'];
					}
					foreach ( $order->get_items() as $item ) {
						$id = (int) $item->get_meta( '_sfls_renew_license' );
						if ( $id > 0 ) {
							$out['renewed'][ $id ] = $id;
						}
					}
				}
				$out['renewed'] = array_values( $out['renewed'] );
				return $out;
			}
		);
	}

	/**
	 * Refunds of license orders created in a range, newest first.
	 *
	 * @param string $from  From.
	 * @param string $to    To.
	 * @param string $plan  Plan.
	 * @param int    $limit Max rows (0 = all).
	 * @return array[] Rows: order_id, amount, date, email, reason.
	 */
	public static function refunds( string $from, string $to, string $plan = '', int $limit = 0 ): array {
		return self::cached(
			'refunds',
			array( $from, $to, $plan, $limit ),
			static function () use ( $from, $to, $plan, $limit ) {
				$rows = array();
				/**
				 * Typed loosely: the stubs promise orders only.
				 *
				 * @var array<int,mixed> $refunds
				 */
				$refunds = function_exists( 'wc_get_orders' ) ? wc_get_orders(
					array(
						'type'         => 'shop_order_refund',
						'date_created' => strtotime( $from . ' UTC' ) . '...' . strtotime( $to . ' UTC' ),
						'orderby'      => 'date',
						'order'        => 'DESC',
						'limit'        => -1,
					)
				) : array();
				foreach ( $refunds as $refund ) {
					$parent = $refund instanceof WC_Order_Refund ? wc_get_order( $refund->get_parent_id() ) : false;
					if ( ! $parent instanceof WC_Order || 'yes' !== $parent->get_meta( '_sfls_issued' ) || ! self::order_matches( $parent, $plan ) ) {
						continue;
					}
					if ( apply_filters( 'sfls_metrics_exclude_order', false, $parent ) ) {
						continue;
					}
					$created = $refund->get_date_created();
					$amount  = abs( (float) $refund->get_amount() );
					$cur     = strtoupper( (string) $parent->get_currency() );
					$adjs    = $parent->get_meta( '_sfls_paddle_adjustments' );
					$payout  = is_array( $adjs ) ? ( $adjs[ (string) $refund->get_meta( '_sfls_paddle_adjustment_id' ) ]['payout_totals'] ?? null ) : null;
					$known   = is_array( $payout ) && strtoupper( (string) ( $payout['currency'] ?? '' ) ) === $cur;
					$rows[]  = array(
						'order_id'  => $parent->get_id(),
						'plan'      => self::plan_of( $parent ),
						'country'   => (string) $parent->get_billing_country(),
						'fee'       => $known ? self::major( (int) $payout['fee'] - (int) ( $payout['retained_fee'] ?? 0 ), $cur ) : 0.0,
						'earnings'  => $known ? -self::major( (int) $payout['earnings'] + (int) ( $payout['retained_fee'] ?? 0 ), $cur ) : -$amount,
						'estimated' => ! $known,
						'amount'    => $amount,
						'date'      => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : '',
						'email'     => (string) $parent->get_billing_email(),
						'reason'    => (string) $refund->get_reason(),
					);
					if ( $limit > 0 && count( $rows ) >= $limit ) {
						break;
					}
				}
				return $rows;
			}
		);
	}

	/**
	 * Refunded amount in a range.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function refunded_amount( string $from, string $to, string $plan = '' ): float {
		return (float) array_sum( wp_list_pluck( self::refunds( $from, $to, $plan ), 'amount' ) );
	}

	/**
	 * Money figures of a range (see the class doc): gross, refunds, net_sales,
	 * tax, fees, earnings, each with amount, count of orders and estimated.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 * @return array<string,array{amount:float,count:int,estimated:bool}>
	 */
	public static function money( string $from, string $to, string $plan = '' ): array {
		return self::cached(
			'money',
			array( $from, $to, $plan ),
			static function () use ( $from, $to, $plan ) {
				$sales    = self::orders( $from, $to, $plan )['rows'];
				$refunds  = self::refunds( $from, $to, $plan );
				$sum      = static fn( array $rows, string $key ): float => (float) array_sum( array_map( static fn( $r ) => (float) $r[ $key ], $rows ) );
				$est      = static fn( array $rows ): bool => (bool) array_filter( $rows, static fn( $r ) => $r['estimated'] );
				$fig      = static fn( float $amount, int $count, bool $estimated ): array => array(
					'amount'    => round( $amount, 2 ),
					'count'     => $count,
					'estimated' => $estimated,
				);
				$n        = count( $sales );
				$refunded = count( array_unique( wp_list_pluck( $refunds, 'order_id' ) ) );
				$both     = $est( $sales ) || $est( $refunds );
				return array(
					'gross'     => $fig( $sum( $sales, 'gross' ), $n, false ),
					'refunds'   => $fig( $sum( $refunds, 'amount' ), $refunded, false ),
					'net_sales' => $fig( $sum( $sales, 'gross' ) - $sum( $refunds, 'amount' ), $n, false ),
					'tax'       => $fig( $sum( $sales, 'tax' ), $n, $est( $sales ) ),
					'fees'      => $fig( $sum( $sales, 'fee' ) - $sum( $refunds, 'fee' ), $n, $both ),
					'earnings'  => $fig( $sum( $sales, 'earnings' ) + $sum( $refunds, 'earnings' ), count( array_unique( array_merge( wp_list_pluck( $sales, 'order_id' ), wp_list_pluck( $refunds, 'order_id' ) ) ) ), $both ),
				);
			}
		);
	}

	/**
	 * Gross sales of a range.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function revenue( string $from, string $to, string $plan = '' ): float {
		return self::money( $from, $to, $plan )['gross']['amount'];
	}

	/**
	 * Gross sales minus refunds created in the range.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function net_revenue( string $from, string $to, string $plan = '' ): float {
		return self::money( $from, $to, $plan )['net_sales']['amount'];
	}

	/**
	 * Paddle fees kept in a range.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function fees( string $from, string $to, string $plan = '' ): float {
		return self::money( $from, $to, $plan )['fees']['amount'];
	}

	/**
	 * Net earnings (Paddle payout) of a range.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function earnings( string $from, string $to, string $plan = '' ): float {
		return self::money( $from, $to, $plan )['earnings']['amount'];
	}

	/**
	 * Refunded orders / paid orders, percent (null without paid orders).
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function refund_rate( string $from, string $to, string $plan = '' ): ?float {
		return self::refund_stats( $from, $to, $plan )['rate'];
	}

	/**
	 * Refund rate with its denominator.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 * @return array{rate:?float,n:int}
	 */
	public static function refund_stats( string $from, string $to, string $plan = '' ): array {
		$orders = self::orders( $from, $to, $plan );
		return array(
			'rate' => $orders['paid'] > 0 ? round( $orders['refunded'] / $orders['paid'] * 100, 1 ) : null,
			'n'    => $orders['paid'],
		);
	}

	/**
	 * Active licenses that expired in a range (ended by the clock, not canceled).
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	private static function expired( string $from, string $to, string $plan ): int {
		$to = min( $to, current_time( 'mysql', true ) );
		return self::count( "l.status = 'active' AND l.billing_status <> 'canceled' AND l.expires_at >= %s AND l.expires_at <= %s", array( $from, $to ), $plan );
	}

	/**
	 * Licenses lost in a range: canceled subscriptions plus expired licenses.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function churned( string $from, string $to, string $plan = '' ): int {
		return self::count( "l.status = 'active' AND l.billing_status = 'canceled' AND l.updated_at >= %s AND l.updated_at <= %s", array( $from, $to ), $plan )
			+ self::expired( $from, $to, $plan );
	}

	/**
	 * Churned / active at the start, percent (null when nothing was active).
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function churn_rate( string $from, string $to, string $plan = '' ): ?float {
		return self::churn_stats( $from, $to, $plan )['rate'];
	}

	/**
	 * Churn rate with its denominator (licenses active at the start).
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 * @return array{rate:?float,n:int}
	 */
	public static function churn_stats( string $from, string $to, string $plan = '' ): array {
		$start = self::count( "l.status = 'active' AND l.created_at < %s AND ( l.expires_at IS NULL OR l.expires_at > %s )", array( $from, $from ), $plan );
		return array(
			'rate' => $start > 0 ? round( self::churned( $from, $to, $plan ) / $start * 100, 1 ) : null,
			'n'    => $start,
		);
	}

	/**
	 * Renewed / due, percent (null when nothing was due).
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 */
	public static function renewal_rate( string $from, string $to, string $plan = '' ): ?float {
		return self::renewal_stats( $from, $to, $plan )['rate'];
	}

	/**
	 * Renewal rate with its denominator (licenses due).
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @param string $plan Plan.
	 * @return array{rate:?float,n:int}
	 */
	public static function renewal_stats( string $from, string $to, string $plan = '' ): array {
		$renewed = count( self::orders( $from, $to, $plan )['renewed'] );
		$lapsed  = self::count( "l.status = 'active' AND l.expires_at >= %s AND l.expires_at <= %s", array( $from, $to ), $plan );
		$due     = $renewed + $lapsed;
		return array(
			'rate' => $due > 0 ? round( $renewed / $due * 100, 1 ) : null,
			'n'    => $due,
		);
	}

	/**
	 * Monthly recurring revenue of Paddle subscriptions in the store currency.
	 *
	 * @param string $plan   Plan.
	 * @param string $before Only licenses created up to this time ('' = all).
	 */
	public static function mrr( string $plan = '', string $before = '' ): float {
		global $wpdb;
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$where    = "l.billing = 'paddle' AND l.paddle_subscription_id <> '' AND l.billing_status IN ( 'active', 'trialing', 'past_due' ) AND l.cancels_at IS NULL AND l.status = 'active' AND l.billing_currency = %s";
		$args     = array( $currency );
		if ( '' !== $before ) {
			$where .= ' AND l.created_at <= %s';
			$args[] = $before;
		}
		[ $psql, $pargs ] = self::plan_sql( $plan );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$minor   = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(CAST(l.billing_amount AS UNSIGNED)) FROM ' . SFLS_DB::licenses() . " l WHERE {$where}{$psql}", array_merge( $args, $pargs ) ) );
		$divisor = in_array( $currency, self::ZERO_DECIMAL, true ) ? 1 : 100;
		return round( $minor / $divisor / 12, 2 );
	}

	/**
	 * Past-due licenses: count and amount at risk (store currency).
	 *
	 * @param string $plan Plan.
	 * @return array{count:int,amount:float}
	 */
	public static function dunning( string $plan = '' ): array {
		global $wpdb;
		$currency         = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		[ $psql, $pargs ] = self::plan_sql( $plan );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$row     = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, SUM( IF( l.billing_currency = %s, CAST(l.billing_amount AS UNSIGNED), 0 ) ) AS minor FROM ' . SFLS_DB::licenses() . " l WHERE l.billing_status = 'past_due'{$psql}", array_merge( array( $currency ), $pargs ) ), ARRAY_A );
		$divisor = in_array( $currency, self::ZERO_DECIMAL, true ) ? 1 : 100;
		return array(
			'count'  => (int) ( $row['n'] ?? 0 ),
			'amount' => round( (float) ( $row['minor'] ?? 0 ) / $divisor, 2 ),
		);
	}

	/**
	 * Expected renewal revenue: billed amounts of active, non-canceling Paddle
	 * subscriptions renewing within each number of days (cumulative), store currency.
	 *
	 * @param int[]  $days Day counts.
	 * @param string $plan Plan.
	 * @return array<int,array{count:int,amount:float}> Days => figures.
	 */
	public static function projections( array $days = array( 30, 60, 90 ), string $plan = '' ): array {
		return self::cached(
			'projections',
			array( $days, $plan ),
			static function () use ( $days, $plan ) {
				global $wpdb;
				$currency         = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
				$divisor          = in_array( $currency, self::ZERO_DECIMAL, true ) ? 1 : 100;
				$now              = current_time( 'mysql', true );
				[ $psql, $pargs ] = self::plan_sql( $plan );
				$out              = array();
				foreach ( $days as $d ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
					$row             = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, SUM(CAST(l.billing_amount AS UNSIGNED)) AS minor FROM ' . SFLS_DB::licenses() . " l WHERE l.billing = 'paddle' AND l.status = 'active' AND l.billing_status IN ( 'active', 'trialing' ) AND l.cancels_at IS NULL AND l.billing_currency = %s AND l.renews_at > %s AND l.renews_at <= %s{$psql}", array_merge( array( $currency, $now, gmdate( 'Y-m-d H:i:s', time() + (int) $d * DAY_IN_SECONDS ) ), $pargs ) ), ARRAY_A );
					$out[ (int) $d ] = array(
						'count'  => (int) ( $row['n'] ?? 0 ),
						'amount' => round( (float) ( $row['minor'] ?? 0 ) / $divisor, 2 ),
					);
				}
				return $out;
			}
		);
	}

	/**
	 * Sites (non-local activations) seen in the 30 days up to a moment.
	 *
	 * @param string $at   Datetime.
	 * @param string $plan Plan.
	 */
	public static function active_sites( string $at, string $plan = '' ): int {
		global $wpdb;
		[ $psql, $pargs ] = self::plan_sql( $plan );
		$since            = gmdate( 'Y-m-d H:i:s', strtotime( $at . ' UTC' ) - 30 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SFLS_DB::activations() . ' a INNER JOIN ' . SFLS_DB::licenses() . " l ON l.id = a.license_id WHERE a.is_local = 0 AND a.last_seen_at > %s AND a.last_seen_at <= %s{$psql}", array_merge( array( $since, $at ), $pargs ) ) );
	}

	/**
	 * Every KPI for a range with the previous period. Entries have value,
	 * previous, format and, where they apply, `n` (rate denominator), `count`
	 * (orders) and `estimated`.
	 *
	 * @param string $from From (UTC).
	 * @param string $to   To (UTC).
	 * @param string $plan Plan filter or ''.
	 * @return array<string,array<string,mixed>>
	 */
	public static function kpis( string $from, string $to, string $plan = '' ): array {
		return self::cached(
			'kpis',
			array( $from, $to, $plan ),
			static function () use ( $from, $to, $plan ) {
				[ $pfrom, $pto ] = self::previous_range( $from, $to );
				$both            = static fn( callable $calc, string $format ): array => array(
					'value'    => $calc( $from, $to ),
					'previous' => $calc( $pfrom, $pto ),
					'format'   => $format,
				);
				$money           = static function ( string $key ) use ( $from, $to, $pfrom, $pto, $plan ): array {
					$now = self::money( $from, $to, $plan )[ $key ];
					return array(
						'value'     => $now['amount'],
						'previous'  => self::money( $pfrom, $pto, $plan )[ $key ]['amount'],
						'format'    => 'money',
						'count'     => $now['count'],
						'estimated' => $now['estimated'],
					);
				};
				$rate            = static function ( callable $stats ) use ( $from, $to, $pfrom, $pto ): array {
					$now = $stats( $from, $to );
					return array(
						'value'    => $now['rate'],
						'previous' => $stats( $pfrom, $pto )['rate'],
						'format'   => 'percent',
						'n'        => $now['n'],
					);
				};
				$mrr             = self::mrr( $plan );
				$dunning         = self::dunning( $plan );
				$proj            = self::projections( array( 30, 60, 90 ), $plan );
				$out             = array(
					'active'         => array(
						'value'    => self::active_at( $to, $plan ),
						'previous' => self::active_at( $pto, $plan ),
						'format'   => 'number',
					),
					'paying'         => array(
						'value'    => self::paying_customers( $plan ),
						'previous' => null,
						'format'   => 'number',
					),
					'new'            => $both( static fn( $a, $b ) => self::new_licenses( $a, $b, $plan ), 'number' ),
					'revenue'        => $money( 'gross' ),
					'refunds'        => $money( 'refunds' ),
					'net_revenue'    => $money( 'net_sales' ),
					'tax'            => $money( 'tax' ),
					'fees'           => $money( 'fees' ),
					'earnings'       => $money( 'earnings' ),
					'mrr'            => array(
						'value'    => $mrr,
						'previous' => self::mrr( $plan, $pto ),
						'format'   => 'money',
					),
					'arr'            => array(
						'value'    => round( $mrr * 12, 2 ),
						'previous' => round( self::mrr( $plan, $pto ) * 12, 2 ),
						'format'   => 'money',
					),
					'renewal_rate'   => $rate( static fn( $a, $b ) => self::renewal_stats( $a, $b, $plan ) ),
					'churn'          => $rate( static fn( $a, $b ) => self::churn_stats( $a, $b, $plan ) ),
					'refund_rate'    => $rate( static fn( $a, $b ) => self::refund_stats( $a, $b, $plan ) ),
					'dunning_count'  => array(
						'value'    => $dunning['count'],
						'previous' => null,
						'format'   => 'number',
					),
					'dunning_amount' => array(
						'value'    => $dunning['amount'],
						'previous' => null,
						'format'   => 'money',
					),
					'active_sites'   => array(
						'value'    => self::active_sites( $to, $plan ),
						'previous' => self::active_sites( $pto, $plan ),
						'format'   => 'number',
					),
				);
				foreach ( $proj as $days => $p ) {
					$out[ 'projected_' . $days ] = array(
						'value'    => $p['amount'],
						'previous' => null,
						'format'   => 'money',
						'count'    => $p['count'],
					);
				}
				return $out;
			}
		);
	}

	// ── Series ──────────────────────────────────────────────────────────

	/**
	 * Calendar months in the site timezone, oldest first, ending with the current one.
	 *
	 * @param int    $months Count (ignored when a from bound is given).
	 * @param string $from   Y-m-d first day's month, or empty.
	 * @param string $to     Y-m-d last day's month, or empty for the current one.
	 * @return array[] Each: label (Y-m), from, to (UTC datetimes).
	 */
	private static function months( int $months, string $from = '', string $to = '' ): array {
		$tz    = wp_timezone();
		$utc   = new DateTimeZone( 'UTC' );
		$month = static fn( string $d ): ?DateTimeImmutable => preg_match( '/^\d{4}-\d{2}/', $d ) ? new DateTimeImmutable( substr( $d, 0, 7 ) . '-01 00:00:00', $tz ) : null;
		$last  = $month( $to ) ?? ( new DateTimeImmutable( 'now', $tz ) )->modify( 'first day of this month' )->setTime( 0, 0 );
		$first = $month( $from );
		if ( $first ) {
			$months = max( 1, min( 120, ( (int) $last->format( 'Y' ) - (int) $first->format( 'Y' ) ) * 12 + (int) $last->format( 'n' ) - (int) $first->format( 'n' ) + 1 ) );
		}
		$out = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$start = $last->modify( "-{$i} months" );
			$end   = $start->modify( '+1 month' )->modify( '-1 second' );
			$out[] = array(
				'label' => $start->format( 'Y-m' ),
				'from'  => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'to'    => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			);
		}
		return $out;
	}

	/**
	 * Gross and net revenue per month.
	 *
	 * @param int    $months Months.
	 * @param string $plan   Plan.
	 * @return array{labels:string[],gross:float[],net:float[]}
	 */
	public static function revenue_by_month( int $months = 12, string $plan = '' ): array {
		$out = array(
			'labels' => array(),
			'gross'  => array(),
			'net'    => array(),
		);
		foreach ( self::months( $months ) as $m ) {
			$out['labels'][] = $m['label'];
			$out['gross'][]  = self::revenue( $m['from'], $m['to'], $plan );
			$out['net'][]    = self::net_revenue( $m['from'], $m['to'], $plan );
		}
		return $out;
	}

	/**
	 * New, renewed and churned licenses per month.
	 *
	 * @param int    $months Months.
	 * @param string $plan   Plan.
	 * @param string $from   Y-m-d, start of the series (overrides $months).
	 * @param string $to     Y-m-d, end of the series.
	 * @return array{labels:string[],new:int[],renewed:int[],churned:int[]}
	 */
	public static function licenses_by_month( int $months = 12, string $plan = '', string $from = '', string $to = '' ): array {
		$out = array(
			'labels'  => array(),
			'new'     => array(),
			'renewed' => array(),
			'churned' => array(),
		);
		foreach ( self::months( $months, $from, $to ) as $m ) {
			$out['labels'][]  = $m['label'];
			$out['new'][]     = self::new_licenses( $m['from'], $m['to'], $plan );
			$out['renewed'][] = count( self::orders( $m['from'], $m['to'], $plan )['renewed'] );
			$out['churned'][] = self::churned( $m['from'], $m['to'], $plan );
		}
		return $out;
	}

	/**
	 * Active licenses per plan.
	 *
	 * @return array<string,int> Plan slug => count.
	 */
	public static function plan_mix(): array {
		return self::cached(
			'plan_mix',
			array(),
			static function () {
				global $wpdb;
				$now = current_time( 'mysql', true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT plan, COUNT(*) AS n FROM ' . SFLS_DB::licenses() . ' l WHERE ' . self::active_clause() . ' GROUP BY plan ORDER BY n DESC', $now ), ARRAY_A );
				return array_column( array_map( static fn( $r ) => array( $r['plan'], (int) $r['n'] ), $rows ), 1, 0 );
			}
		);
	}

	/**
	 * Active licenses expiring within each number of days (cumulative).
	 *
	 * @param int[]  $days Day counts.
	 * @param string $plan Plan.
	 * @return array<int,int> Days => count.
	 */
	public static function renewals_due( array $days = array( 30, 60, 90 ), string $plan = '' ): array {
		return self::cached(
			'renewals_due',
			array( $days, $plan ),
			static function () use ( $days, $plan ) {
				$now = current_time( 'mysql', true );
				$out = array();
				foreach ( $days as $d ) {
					$out[ (int) $d ] = self::count( "l.status = 'active' AND l.expires_at > %s AND l.expires_at <= %s", array( $now, gmdate( 'Y-m-d H:i:s', time() + (int) $d * DAY_IN_SECONDS ) ), $plan );
				}
				return $out;
			}
		);
	}

	/**
	 * Plugin versions reported by recently seen sites (empty when none).
	 *
	 * @return array<string,int> Version => sites, most used first.
	 */
	public static function version_adoption(): array {
		return self::cached(
			'versions',
			array(),
			static function () {
				global $wpdb;
				$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT plugin_version AS v, COUNT(*) AS n FROM ' . SFLS_DB::activations() . " WHERE is_local = 0 AND plugin_version <> '' AND last_seen_at > %s GROUP BY plugin_version ORDER BY n DESC LIMIT 8", $since ), ARRAY_A );
				return array_column( array_map( static fn( $r ) => array( $r['v'], (int) $r['n'] ), $rows ), 1, 0 );
			}
		);
	}

	/**
	 * Purchase-month cohorts: renewed after year 1, still active, churned (percent).
	 * Renewed = the expiry now lies past the first anniversary; shown only once
	 * the cohort has had its full first year. Churned = not active any more.
	 *
	 * @param string $from From (Y-m-d, optional).
	 * @param string $to   To (Y-m-d, optional).
	 * @return array[] Rows: month, licenses, renewed, active, churned (percent or null).
	 */
	public static function cohorts( string $from = '', string $to = '' ): array {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$where = array( '1=1' );
		$args  = array( $now );
		if ( '' !== $from ) {
			$where[] = 'l.created_at >= %s';
			$args[]  = $from . ' 00:00:00';
		}
		if ( '' !== $to ) {
			$where[] = 'l.created_at <= %s';
			$args[]  = $to . ' 23:59:59';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(l.created_at, '%%Y-%%m') AS m, COUNT(*) AS n, SUM(l.expires_at > DATE_ADD(l.created_at, INTERVAL 1 YEAR)) AS r, SUM(l.status = 'active' AND l.billing_status <> 'canceled' AND (l.expires_at IS NULL OR l.expires_at > %s)) AS a FROM " . SFLS_DB::licenses() . ' l WHERE ' . implode( ' AND ', $where ) . ' GROUP BY m ORDER BY m ASC', $args ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$n     = (int) $r['n'];
			$full  = strtotime( $r['m'] . '-01 +1 month +1 year UTC' ) <= time();
			$out[] = array(
				'month'    => $r['m'],
				'licenses' => $n,
				'renewed'  => $full ? round( (int) $r['r'] / $n * 100, 1 ) : null,
				'active'   => round( (int) $r['a'] / $n * 100, 1 ),
				'churned'  => round( ( $n - (int) $r['a'] ) / $n * 100, 1 ),
			);
		}
		return $out;
	}

	// ── Lists ───────────────────────────────────────────────────────────

	/**
	 * Licenses with a given condition, as list rows.
	 *
	 * @param string $where Condition.
	 * @param array  $args  Arguments.
	 * @param string $order Order by.
	 * @param string $plan  Plan.
	 * @return array[]
	 */
	private static function license_rows( string $where, array $args, string $order, string $plan ): array {
		global $wpdb;
		[ $psql, $pargs ] = self::plan_sql( $plan );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = 'SELECT l.id, l.plan, l.customer_email, l.expires_at, l.billing_status, l.billing_amount, l.billing_currency, l.renews_at FROM ' . SFLS_DB::licenses() . " l WHERE {$where}{$psql} ORDER BY {$order} LIMIT 50";
		$all = array_merge( $args, $pargs );
		return (array) $wpdb->get_results( $all ? $wpdb->prepare( $sql, $all ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Active licenses expiring within the next days, soonest first.
	 *
	 * @param int    $days Days.
	 * @param string $plan Plan.
	 * @return array[]
	 */
	public static function renewals_due_list( int $days = 7, string $plan = '' ): array {
		return self::cached(
			'due_list',
			array( $days, $plan ),
			static fn() => self::license_rows( "l.status = 'active' AND l.expires_at > %s AND l.expires_at <= %s", array( current_time( 'mysql', true ), gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) ), 'l.expires_at ASC', $plan )
		);
	}

	/**
	 * Past-due licenses, oldest expiry first.
	 *
	 * @param string $plan Plan.
	 * @return array[]
	 */
	public static function dunning_list( string $plan = '' ): array {
		return self::cached(
			'dunning_list',
			array( $plan ),
			static fn() => self::license_rows( "l.billing_status = 'past_due'", array(), 'l.expires_at ASC', $plan )
		);
	}

	/**
	 * Latest refunds of license orders.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function latest_refunds( int $limit = 10 ): array {
		return self::refunds( '1970-01-02 00:00:00', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), '', $limit );
	}

	/**
	 * Latest audit log entries.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function latest_audit( int $limit = 10 ): array {
		return self::cached(
			'audit',
			array( $limit ),
			static function () use ( $limit ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SFLS_DB::audit_log() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
				foreach ( $rows as &$row ) {
					$user        = get_userdata( (int) $row['user_id'] );
					$row['user'] = $user ? $user->display_name : '';
				}
				return $rows;
			}
		);
	}
}
