<?php
/**
 * Standard (ready-made) reports.
 *
 * Most are predefined definitions run through SFLS_Report_Query; the few that
 * need more than a grouped query (cohorts, month series, dunning list) build the
 * same result shape from SFLS_Metrics.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Standard report catalogue.
 */
final class SFLS_Standard_Reports {

	/**
	 * Paid order statuses used by the revenue reports.
	 */
	const PAID = 'wc-processing,wc-completed,wc-refunded';

	/**
	 * Definition helper: orders / licenses / refunds / activations query.
	 *
	 * @param string $dataset Dataset.
	 * @param array  $extra   Definition keys.
	 */
	private static function def( string $dataset, array $extra ): array {
		return $extra + array(
			'dataset' => $dataset,
			'display' => 'bar',
		);
	}

	/**
	 * Every standard report: key => group, label, def (or null), run (or null).
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		$paid = array(
			array(
				'field' => 'status',
				'op'    => 'in',
				'value' => self::PAID,
			),
		);
		$std  = array(
			'revenue-month'    => array( __( 'Revenue', 'subtleforms-license-server' ), __( 'Revenue by month', 'subtleforms-license-server' ), null, array( self::class, 'revenue_month' ) ),
			'revenue-plan'     => array( __( 'Revenue', 'subtleforms-license-server' ), __( 'Revenue by plan', 'subtleforms-license-server' ), null, array( self::class, 'revenue_plan' ) ),
			'revenue-country'  => array( __( 'Revenue', 'subtleforms-license-server' ), __( 'Revenue by country', 'subtleforms-license-server' ), null, array( self::class, 'revenue_country' ) ),
			'licenses-month'   => array( __( 'Licenses', 'subtleforms-license-server' ), __( 'New, renewed and expired by month', 'subtleforms-license-server' ), null, array( self::class, 'licenses_month' ) ),
			'licenses-plan'    => array(
				__( 'Licenses', 'subtleforms-license-server' ),
				__( 'Licenses by plan and status', 'subtleforms-license-server' ),
				self::def(
					'licenses',
					array(
						'group_by' => array( 'plan', 'status' ),
						'measures' => array( array( 'fn' => 'count' ) ),
					)
				),
				null,
			),
			'renewals-cohort'  => array( __( 'Renewals and churn', 'subtleforms-license-server' ), __( 'Cohorts by purchase month', 'subtleforms-license-server' ), null, array( self::class, 'cohorts' ) ),
			'refunds-month'    => array(
				__( 'Refunds and chargebacks', 'subtleforms-license-server' ),
				__( 'Refunds by month', 'subtleforms-license-server' ),
				self::def(
					'refunds',
					array(
						'group_by' => array( 'refund_month' ),
						'measures' => array(
							array( 'fn' => 'count' ),
							array(
								'fn'    => 'sum',
								'field' => 'refunded',
							),
						),
					)
				),
				null,
			),
			'refunds-status'   => array(
				__( 'Refunds and chargebacks', 'subtleforms-license-server' ),
				__( 'Refunds by order status', 'subtleforms-license-server' ),
				self::def(
					'refunds',
					array(
						'group_by' => array( 'status' ),
						'measures' => array(
							array( 'fn' => 'count' ),
							array(
								'fn'    => 'sum',
								'field' => 'refunded',
							),
						),
					)
				),
				null,
			),
			'dunning'          => array( __( 'Dunning', 'subtleforms-license-server' ), __( 'Past due now and amount at risk', 'subtleforms-license-server' ), null, array( self::class, 'dunning' ) ),
			'activations-dist' => array(
				__( 'Activations', 'subtleforms-license-server' ),
				__( 'Sites per license', 'subtleforms-license-server' ),
				self::def(
					'licenses',
					array(
						'group_by' => array( 'sites_used' ),
						'measures' => array( array( 'fn' => 'count' ) ),
					)
				),
				null,
			),
			'activations-top'  => array(
				__( 'Activations', 'subtleforms-license-server' ),
				__( 'Top domains (non-local)', 'subtleforms-license-server' ),
				self::def(
					'activations',
					array(
						'group_by' => array( 'domain' ),
						'measures' => array( array( 'fn' => 'count' ) ),
						'filters'  => array(
							array(
								'field' => 'is_local',
								'op'    => 'eq',
								'value' => '0',
							),
						),
						'sort'     => 'count:desc',
						'limit'    => 25,
					)
				),
				null,
			),
			'activations-pro'  => array(
				__( 'Activations', 'subtleforms-license-server' ),
				__( 'Pro versions in use', 'subtleforms-license-server' ),
				self::def(
					'activations',
					array(
						'group_by' => array( 'pro_version' ),
						'measures' => array( array( 'fn' => 'count' ) ),
						'sort'     => 'count:desc',
					)
				),
				null,
			),
			'customers-ltv'    => array(
				__( 'Customers', 'subtleforms-license-server' ),
				__( 'Top 50 customers by lifetime value', 'subtleforms-license-server' ),
				self::def(
					'orders',
					array(
						'group_by' => array( 'customer' ),
						'measures' => array(
							array(
								'fn'    => 'sum',
								'field' => 'net_revenue',
							),
							array( 'fn' => 'count' ),
							array(
								'fn'    => 'sum',
								'field' => 'licenses',
							),
						),
						'filters'  => $paid,
						'sort'     => 'sum_net_revenue:desc',
						'limit'    => 50,
						'display'  => 'table',
					)
				),
				null,
			),
		);
		$out  = array();
		foreach ( $std as $key => $s ) {
			$out[ $key ] = array(
				'group' => $s[0],
				'label' => $s[1],
				'def'   => $s[2],
				'run'   => $s[3],
			);
		}
		return $out;
	}

	/**
	 * One standard report.
	 *
	 * @param string $key Key.
	 */
	public static function find( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Definition of a report for a date range ('' = open).
	 *
	 * @param array  $report Standard report.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 */
	public static function definition( array $report, string $from = '', string $to = '' ): array {
		return array(
			'from' => $from,
			'to'   => $to,
		) + (array) $report['def'];
	}

	/**
	 * Run a standard report.
	 *
	 * @param string $key  Key.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array|WP_Error
	 */
	public static function run( string $key, string $from = '', string $to = '' ) {
		$report = self::find( $key );
		if ( ! $report ) {
			return new WP_Error( 'sfls_report_unknown', __( 'Unknown report.', 'subtleforms-license-server' ) );
		}
		if ( $report['def'] ) {
			return SFLS_Report_Query::run( self::definition( $report, $from, $to ) );
		}
		return call_user_func( $report['run'], $from, $to );
	}

	/**
	 * Result from columns and rows.
	 *
	 * @param array $cols key => array( label, type, measure ).
	 * @param array $rows Rows keyed like the columns.
	 */
	private static function result( array $cols, array $rows ): array {
		$columns = array();
		foreach ( $cols as $key => $c ) {
			$columns[] = array(
				'key'     => $key,
				'label'   => $c[0],
				'type'    => $c[1],
				'measure' => ! empty( $c[2] ),
			);
		}
		$rows = array_map( static fn( $r ) => array_map( static fn( $v ) => null === $v ? '' : $v, $r ), $rows );
		return array(
			'columns'    => $columns,
			'rows'       => array_values( $rows ),
			'total_rows' => count( $rows ),
		);
	}

	/**
	 * Revenue by month: the same ledger as the dashboard (SFLS_Metrics::money).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function revenue_month( string $from = '', string $to = '' ): array {
		return self::ledger( 'purchase_month', __( 'Month', 'subtleforms-license-server' ), $from, $to );
	}

	/**
	 * Revenue by plan.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function revenue_plan( string $from = '', string $to = '' ): array {
		return self::ledger( 'plan', __( 'Plan', 'subtleforms-license-server' ), $from, $to );
	}

	/**
	 * Revenue by country.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function revenue_country( string $from = '', string $to = '' ): array {
		return self::ledger( 'country', __( 'Country', 'subtleforms-license-server' ), $from, $to );
	}

	/**
	 * Gross sales, refunds, net sales, fees and net earnings grouped by month
	 * (sales by paid date, refunds by refund date), plan or country.
	 *
	 * @param string $by    purchase_month, plan or country.
	 * @param string $label Label of the group column.
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d.
	 */
	private static function ledger( string $by, string $label, string $from, string $to ): array {
		$tz   = wp_timezone();
		$utc  = static fn( string $day, string $time ): string => ( new DateTimeImmutable( $day . ' ' . $time, $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$f    = '' !== $from ? $utc( $from, '00:00:00' ) : '1970-01-02 00:00:00';
		$t    = '' !== $to ? $utc( $to, '23:59:59' ) : '2100-01-01 00:00:00';
		$key  = static function ( array $r ) use ( $by ): string {
			if ( 'purchase_month' === $by ) {
				return wp_date( 'Y-m', (int) strtotime( $r['date'] . ' UTC' ) );
			}
			return (string) $r[ $by ];
		};
		$rows = array();
		$add  = static function ( array $r, bool $sale ) use ( &$rows, $key ): void {
			$k = $key( $r );
			if ( ! isset( $rows[ $k ] ) ) {
				$rows[ $k ] = array(
					'key'             => $k,
					'count'           => 0,
					'sum_revenue'     => 0.0,
					'sum_refunded'    => 0.0,
					'sum_net_revenue' => 0.0,
					'sum_tax'         => 0.0,
					'sum_fees'        => 0.0,
					'sum_earnings'    => 0.0,
				);
			}
			$g = &$rows[ $k ];
			if ( $sale ) {
				++$g['count'];
				$g['sum_revenue']     += $r['gross'];
				$g['sum_net_revenue'] += $r['gross'];
				$g['sum_tax']         += (float) $r['tax'];
				$g['sum_fees']        += (float) $r['fee'];
			} else {
				$g['sum_refunded']    += $r['amount'];
				$g['sum_net_revenue'] -= $r['amount'];
				$g['sum_tax']         -= $r['tax'];
				$g['sum_fees']        -= $r['fee'];
			}
			$g['sum_earnings'] += $r['earnings'];
		};
		foreach ( SFLS_Metrics::orders( $f, $t )['rows'] as $r ) {
			$add( $r, true );
		}
		foreach ( SFLS_Metrics::refunds( $f, $t ) as $r ) {
			$add( $r, false );
		}
		if ( 'purchase_month' === $by ) {
			ksort( $rows );
		} else {
			uasort( $rows, static fn( $a, $b ) => $b['sum_net_revenue'] <=> $a['sum_net_revenue'] );
		}
		$rows = array_map(
			static fn( $r ) => array( $by => $r['key'] ) + array_map( static fn( $v ) => is_float( $v ) ? round( $v, 2 ) : $v, array_diff_key( $r, array( 'key' => 1 ) ) ),
			$rows
		);
		return self::result(
			array(
				$by               => array( $label, 'purchase_month' === $by ? 'date' : 'string' ),
				'count'           => array( __( 'Orders', 'subtleforms-license-server' ), 'number', true ),
				'sum_revenue'     => array( __( 'Gross sales', 'subtleforms-license-server' ), 'money', true ),
				'sum_refunded'    => array( __( 'Refunds', 'subtleforms-license-server' ), 'money', true ),
				'sum_net_revenue' => array( __( 'Net sales', 'subtleforms-license-server' ), 'money', true ),
				'sum_tax'         => array( __( 'Tax (net of refunds)', 'subtleforms-license-server' ), 'money', true ),
				'sum_fees'        => array( __( 'Paddle fees', 'subtleforms-license-server' ), 'money', true ),
				'sum_earnings'    => array( __( 'Net earnings', 'subtleforms-license-server' ), 'money', true ),
			),
			$rows
		);
	}

	/**
	 * New, renewed and expired licenses per month (last 12 months by default).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function licenses_month( string $from = '', string $to = '' ): array {
		$m    = SFLS_Metrics::licenses_by_month( 12, '', $from, $to );
		$rows = array();
		foreach ( $m['labels'] as $i => $label ) {
			$rows[] = array(
				'month'   => $label,
				'new'     => $m['new'][ $i ],
				'renewed' => $m['renewed'][ $i ],
				'expired' => $m['churned'][ $i ],
			);
		}
		return self::result(
			array(
				'month'   => array( __( 'Month', 'subtleforms-license-server' ), 'date' ),
				'new'     => array( __( 'New', 'subtleforms-license-server' ), 'number', true ),
				'renewed' => array( __( 'Renewed', 'subtleforms-license-server' ), 'number', true ),
				'expired' => array( __( 'Expired or canceled', 'subtleforms-license-server' ), 'number', true ),
			),
			$rows
		);
	}

	/**
	 * Cohort table.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	public static function cohorts( string $from = '', string $to = '' ): array {
		$rows = array_map(
			static fn( $r ) => array(
				'month'    => $r['month'],
				'licenses' => $r['licenses'],
				'renewed'  => $r['renewed'],
				'active'   => $r['active'],
				'churned'  => $r['churned'],
			),
			SFLS_Metrics::cohorts( $from, $to )
		);
		return self::result(
			array(
				'month'    => array( __( 'Purchase month', 'subtleforms-license-server' ), 'date' ),
				'licenses' => array( __( 'Licenses', 'subtleforms-license-server' ), 'number' ),
				'renewed'  => array( __( 'Renewed after year 1 %', 'subtleforms-license-server' ), 'number', true ),
				'active'   => array( __( 'Still active %', 'subtleforms-license-server' ), 'number', true ),
				'churned'  => array( __( 'Churned %', 'subtleforms-license-server' ), 'number', true ),
			),
			$rows
		);
	}

	/**
	 * Past-due licenses and the amount at risk.
	 */
	public static function dunning(): array {
		$rows = array();
		foreach ( SFLS_Metrics::dunning_list() as $r ) {
			$minor  = (float) $r['billing_amount'];
			$cur    = (string) $r['billing_currency'];
			$rows[] = array(
				'customer' => $r['customer_email'],
				'plan'     => SFLS_Plans::label( (string) $r['plan'] ),
				'expires'  => $r['expires_at'],
				'amount'   => round( in_array( strtoupper( $cur ), SFLS_Metrics::ZERO_DECIMAL, true ) ? $minor : $minor / 100, 2 ),
				'currency' => strtoupper( $cur ),
			);
		}
		return self::result(
			array(
				'customer' => array( __( 'Customer', 'subtleforms-license-server' ), 'string' ),
				'plan'     => array( __( 'Plan', 'subtleforms-license-server' ), 'string' ),
				'expires'  => array( __( 'Expires', 'subtleforms-license-server' ), 'date' ),
				'amount'   => array( __( 'Amount at risk', 'subtleforms-license-server' ), 'money', true ),
				'currency' => array( __( 'Currency', 'subtleforms-license-server' ), 'string' ),
			),
			$rows
		);
	}
}
