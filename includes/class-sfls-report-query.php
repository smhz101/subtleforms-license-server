<?php
/**
 * Custom report query builder.
 *
 * A report definition is validated against a whitelist of fields per dataset.
 * Field names never reach SQL: they are looked up, and the fixed expression of
 * the whitelist is used. Operators map to fixed fragments and every value goes
 * through $wpdb->prepare().
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs a report definition.
 */
final class SFLS_Report_Query {

	const MAX_GROUPS = 10000;

	const DEFAULT_LIMIT = 500;

	const OPS = array( 'eq', 'in', 'between', 'before', 'after', 'gt', 'lt' );

	/**
	 * Whether orders live in the HPOS tables.
	 */
	private static function hpos(): bool {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Field spec helper.
	 *
	 * @param string $label   Label.
	 * @param string $expr    SQL expression (fixed, never user input).
	 * @param string $type    string|number|money|date.
	 * @param string $flags   g = groupable, f = filterable, m = measurable.
	 */
	private static function f( string $label, string $expr, string $type, string $flags ): array {
		return array(
			'label'      => $label,
			'expr'       => $expr,
			'type'       => $type,
			'groupable'  => str_contains( $flags, 'g' ),
			'filterable' => str_contains( $flags, 'f' ),
			'measurable' => str_contains( $flags, 'm' ),
		);
	}

	/**
	 * Dataset definitions: label, FROM clause, base WHERE, date field.
	 *
	 * @return array<string,array>
	 */
	public static function datasets(): array {
		global $wpdb;
		$lic   = SFLS_DB::licenses();
		$acts  = SFLS_DB::activations();
		$hpos  = self::hpos();
		$ord   = $wpdb->prefix . 'wc_orders';
		$adr   = $wpdb->prefix . 'wc_order_addresses';
		$posts = $wpdb->posts;
		$meta  = $wpdb->postmeta;

		// Billing country of the order a licence came from.
		$lic_country_join = $hpos
			? "LEFT JOIN {$adr} ad ON ad.order_id = l.order_id AND ad.address_type = 'billing'"
			: "LEFT JOIN {$meta} bc ON bc.post_id = l.order_id AND bc.meta_key = '_billing_country'";
		$lic_country      = $hpos ? 'ad.country' : 'bc.meta_value';
		$lic_country      = "COALESCE(NULLIF({$lic_country}, ''), '')";

		$license_fields = array(
			'plan'           => self::f( __( 'Plan', 'subtleforms-license-server' ), 'l.plan', 'string', 'gf' ),
			'status'         => self::f( __( 'Status', 'subtleforms-license-server' ), 'l.status', 'string', 'gf' ),
			'billing'        => self::f( __( 'Billing source', 'subtleforms-license-server' ), 'l.billing', 'string', 'gf' ),
			'billing_status' => self::f( __( 'Billing status', 'subtleforms-license-server' ), 'l.billing_status', 'string', 'gf' ),
			'country'        => self::f( __( 'Country', 'subtleforms-license-server' ), $lic_country, 'string', 'gf' ),
			'product'        => self::f( __( 'Product', 'subtleforms-license-server' ), "COALESCE(pr.post_title, '')", 'string', 'gf' ),
			'purchase_month' => self::f( __( 'Purchase month', 'subtleforms-license-server' ), 'l.created_at', 'date', 'gf' ),
			'expiry_month'   => self::f( __( 'Expiry month', 'subtleforms-license-server' ), 'l.expires_at', 'date', 'gf' ),
			'sites_allowed'  => self::f( __( 'Sites allowed', 'subtleforms-license-server' ), 'l.sites_allowed', 'number', 'fm' ),
			'sites_used'     => self::f( __( 'Sites used', 'subtleforms-license-server' ), "(SELECT COUNT(*) FROM {$acts} sa WHERE sa.license_id = l.id)", 'number', 'fm' ),
			'days_to_expiry' => self::f( __( 'Days to expiry', 'subtleforms-license-server' ), 'DATEDIFF(l.expires_at, UTC_TIMESTAMP())', 'number', 'fm' ),
			'billing_amount' => self::f( __( 'Billing amount', 'subtleforms-license-server' ), 'CAST(NULLIF(l.billing_amount, \'\') AS DECIMAL(18,2))', 'money', 'fm' ),
		);

		$license_from = "{$lic} l {$lic_country_join} LEFT JOIN {$posts} pr ON pr.ID = l.product_id";

		// Orders that issued a licence, and their refunds.
		if ( $hpos ) {
			$orders_from   = "{$ord} o LEFT JOIN {$adr} ad ON ad.order_id = o.id AND ad.address_type = 'billing'";
			$orders_where  = "o.type = 'shop_order' AND o.id IN (SELECT order_id FROM {$lic} WHERE order_id > 0)";
			$o_status      = 'o.status';
			$o_date        = 'o.date_created_gmt';
			$o_currency    = 'o.currency';
			$o_total       = 'o.total_amount';
			$o_country     = "COALESCE(NULLIF(ad.country, ''), '')";
			$o_refunded    = "(SELECT COALESCE(-SUM(r.total_amount), 0) FROM {$ord} r WHERE r.parent_order_id = o.id AND r.type = 'shop_order_refund')";
			$o_licenses    = "(SELECT COUNT(*) FROM {$lic} ol WHERE ol.order_id = o.id)";
			$refunds_from  = "{$ord} r JOIN {$ord} o ON o.id = r.parent_order_id LEFT JOIN {$adr} ad ON ad.order_id = o.id AND ad.address_type = 'billing'";
			$refunds_where = "r.type = 'shop_order_refund' AND o.id IN (SELECT order_id FROM {$lic} WHERE order_id > 0)";
			$r_date        = 'r.date_created_gmt';
			$r_amount      = '-r.total_amount';
			$r_status      = 'o.status';
			$r_currency    = 'o.currency';
			$r_country     = $o_country;
		} else {
			$orders_from   = "{$posts} o LEFT JOIN {$meta} bc ON bc.post_id = o.ID AND bc.meta_key = '_billing_country' LEFT JOIN {$meta} tot ON tot.post_id = o.ID AND tot.meta_key = '_order_total' LEFT JOIN {$meta} cur ON cur.post_id = o.ID AND cur.meta_key = '_order_currency'";
			$orders_where  = "o.post_type = 'shop_order' AND o.ID IN (SELECT order_id FROM {$lic} WHERE order_id > 0)";
			$o_status      = 'o.post_status';
			$o_date        = 'o.post_date_gmt';
			$o_currency    = "COALESCE(cur.meta_value, '')";
			$o_total       = 'CAST(tot.meta_value AS DECIMAL(18,2))';
			$o_country     = "COALESCE(NULLIF(bc.meta_value, ''), '')";
			$o_refunded    = "(SELECT COALESCE(SUM(ABS(rm.meta_value)), 0) FROM {$posts} r JOIN {$meta} rm ON rm.post_id = r.ID AND rm.meta_key = '_refund_amount' WHERE r.post_parent = o.ID AND r.post_type = 'shop_order_refund')";
			$o_licenses    = "(SELECT COUNT(*) FROM {$lic} ol WHERE ol.order_id = o.ID)";
			$refunds_from  = "{$posts} r JOIN {$posts} o ON o.ID = r.post_parent LEFT JOIN {$meta} bc ON bc.post_id = o.ID AND bc.meta_key = '_billing_country' LEFT JOIN {$meta} ra ON ra.post_id = r.ID AND ra.meta_key = '_refund_amount' LEFT JOIN {$meta} cur ON cur.post_id = o.ID AND cur.meta_key = '_order_currency'";
			$refunds_where = "r.post_type = 'shop_order_refund' AND o.ID IN (SELECT order_id FROM {$lic} WHERE order_id > 0)";
			$r_date        = 'r.post_date_gmt';
			$r_amount      = 'ABS(CAST(ra.meta_value AS DECIMAL(18,2)))';
			$r_status      = 'o.post_status';
			$r_currency    = "COALESCE(cur.meta_value, '')";
			$r_country     = $o_country;
		}

		$billing_fields = array_intersect_key( $license_fields, array_flip( array( 'plan', 'status', 'billing_status', 'country', 'product', 'purchase_month', 'billing_amount' ) ) );

		return array(
			'licenses'      => array(
				'label'  => __( 'Licenses', 'subtleforms-license-server' ),
				'from'   => $license_from,
				'where'  => '1=1',
				'date'   => 'l.created_at',
				'fields' => $license_fields,
			),
			'orders'        => array(
				'label'  => __( 'Orders', 'subtleforms-license-server' ),
				'from'   => $orders_from,
				'where'  => $orders_where,
				'date'   => $o_date,
				'fields' => array(
					'status'         => self::f( __( 'Order status', 'subtleforms-license-server' ), $o_status, 'string', 'gf' ),
					'country'        => self::f( __( 'Country', 'subtleforms-license-server' ), $o_country, 'string', 'gf' ),
					'currency'       => self::f( __( 'Currency', 'subtleforms-license-server' ), $o_currency, 'string', 'gf' ),
					'purchase_month' => self::f( __( 'Purchase month', 'subtleforms-license-server' ), $o_date, 'date', 'gf' ),
					'revenue'        => self::f( __( 'Revenue', 'subtleforms-license-server' ), $o_total, 'money', 'fm' ),
					'refunded'       => self::f( __( 'Refunded amount', 'subtleforms-license-server' ), $o_refunded, 'money', 'fm' ),
					'licenses'       => self::f( __( 'Licenses issued', 'subtleforms-license-server' ), $o_licenses, 'number', 'fm' ),
				),
			),
			'refunds'       => array(
				'label'  => __( 'Refunds', 'subtleforms-license-server' ),
				'from'   => $refunds_from,
				'where'  => $refunds_where,
				'date'   => $r_date,
				'fields' => array(
					'status'       => self::f( __( 'Order status', 'subtleforms-license-server' ), $r_status, 'string', 'gf' ),
					'country'      => self::f( __( 'Country', 'subtleforms-license-server' ), $r_country, 'string', 'gf' ),
					'currency'     => self::f( __( 'Currency', 'subtleforms-license-server' ), $r_currency, 'string', 'gf' ),
					'refund_month' => self::f( __( 'Refund month', 'subtleforms-license-server' ), $r_date, 'date', 'gf' ),
					'refunded'     => self::f( __( 'Refunded amount', 'subtleforms-license-server' ), $r_amount, 'money', 'fm' ),
				),
			),
			'subscriptions' => array(
				'label'  => __( 'Subscriptions', 'subtleforms-license-server' ),
				'from'   => $license_from,
				'where'  => "l.billing = 'paddle'",
				'date'   => 'l.created_at',
				'fields' => $billing_fields + array(
					'renewal_month' => self::f( __( 'Renewal month', 'subtleforms-license-server' ), 'l.renews_at', 'date', 'gf' ),
					'currency'      => self::f( __( 'Billing currency', 'subtleforms-license-server' ), 'l.billing_currency', 'string', 'gf' ),
				),
			),
			'activations'   => array(
				'label'  => __( 'Activations', 'subtleforms-license-server' ),
				'from'   => "{$acts} a JOIN {$lic} l ON l.id = a.license_id",
				'where'  => '1=1',
				'date'   => 'a.activated_at',
				'fields' => array(
					'plan'            => self::f( __( 'Plan', 'subtleforms-license-server' ), 'l.plan', 'string', 'gf' ),
					'status'          => self::f( __( 'License status', 'subtleforms-license-server' ), 'l.status', 'string', 'gf' ),
					'pro_version'     => self::f( __( 'Pro version', 'subtleforms-license-server' ), 'a.plugin_version', 'string', 'gf' ),
					'is_local'        => self::f( __( 'Staging site', 'subtleforms-license-server' ), 'a.is_local', 'number', 'gf' ),
					'activated_month' => self::f( __( 'Activated month', 'subtleforms-license-server' ), 'a.activated_at', 'date', 'gf' ),
					'last_seen_month' => self::f( __( 'Last seen month', 'subtleforms-license-server' ), 'a.last_seen_at', 'date', 'gf' ),
				),
			),
		);
	}

	/**
	 * Fields offered by a dataset, for the builder.
	 *
	 * @param string $dataset Dataset key.
	 * @return array<string,array>
	 */
	public static function fields( string $dataset ): array {
		$all = self::datasets();
		return $all[ $dataset ]['fields'] ?? array();
	}

	/**
	 * SQL condition for one filter.
	 *
	 * @param array $spec   Field spec.
	 * @param array $filter Filter.
	 * @return string|WP_Error
	 */
	private static function condition( array $spec, array $filter ) {
		global $wpdb;
		$op   = (string) ( $filter['op'] ?? '' );
		$val  = $filter['value'] ?? '';
		$expr = $spec['expr'];
		$type = $spec['type'];

		if ( ! in_array( $op, self::OPS, true ) ) {
			return new WP_Error( 'sfls_report_op', __( 'Unknown filter operator.', 'subtleforms-license-server' ) );
		}
		$is_date   = 'date' === $type;
		$is_number = in_array( $type, array( 'number', 'money' ), true );
		if ( ( in_array( $op, array( 'before', 'after' ), true ) && ! $is_date ) || ( in_array( $op, array( 'gt', 'lt' ), true ) && ! $is_number ) || ( 'between' === $op && ! $is_date && ! $is_number ) ) {
			return new WP_Error( 'sfls_report_op', __( 'That operator does not fit this field.', 'subtleforms-license-server' ) );
		}

		$one = static function ( $v ) use ( $is_date, $is_number ) {
			if ( ! is_scalar( $v ) ) {
				return null;
			}
			$v = (string) $v;
			if ( $is_number ) {
				return is_numeric( $v ) ? $v : null;
			}
			if ( $is_date ) {
				return preg_match( '/^\d{4}-\d{2}(-\d{2})?$/', $v ) ? $v : null;
			}
			return strlen( $v ) <= 190 ? $v : null;
		};

		if ( 'in' === $op ) {
			$list = is_array( $val ) ? $val : array_map( 'trim', explode( ',', (string) $val ) );
			$list = array_slice( array_values( $list ), 0, 100 );
			$vals = array();
			foreach ( $list as $v ) {
				$v = $one( $v );
				if ( null === $v ) {
					return new WP_Error( 'sfls_report_value', __( 'Invalid filter value.', 'subtleforms-license-server' ) );
				}
				$vals[] = $v;
			}
			if ( ! $vals ) {
				return new WP_Error( 'sfls_report_value', __( 'Invalid filter value.', 'subtleforms-license-server' ) );
			}
			$target = $is_date ? "DATE_FORMAT({$expr}, '%Y-%m')" : $expr;
			$marks  = $is_number ? implode( ',', array_fill( 0, count( $vals ), '%f' ) ) : implode( ',', array_fill( 0, count( $vals ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder
			return $target . $wpdb->prepare( " IN ({$marks})", $vals );
		}

		if ( 'between' === $op ) {
			$pair = is_array( $val ) ? array_values( $val ) : array_map( 'trim', explode( ',', (string) $val ) );
			$a    = $one( $pair[0] ?? null );
			$b    = $one( $pair[1] ?? null );
			if ( null === $a || null === $b ) {
				return new WP_Error( 'sfls_report_value', __( 'Invalid filter value.', 'subtleforms-license-server' ) );
			}
			if ( $is_date ) {
				return $expr . $wpdb->prepare( ' >= %s AND ', self::day_start( $a ) ) . $expr . $wpdb->prepare( ' <= %s', self::day_end( $b ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			return $expr . $wpdb->prepare( ' BETWEEN %f AND %f', $a, $b ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$v = $one( is_array( $val ) ? ( $val[0] ?? null ) : $val );
		if ( null === $v ) {
			return new WP_Error( 'sfls_report_value', __( 'Invalid filter value.', 'subtleforms-license-server' ) );
		}
		if ( $is_date ) {
			switch ( $op ) {
				case 'before':
					return $expr . $wpdb->prepare( ' < %s', self::day_start( $v ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				case 'after':
					return $expr . $wpdb->prepare( ' > %s', self::day_end( $v ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				default:
					return "DATE_FORMAT({$expr}, '%Y-%m')" . $wpdb->prepare( ' = %s', substr( $v, 0, 7 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		$sql = array(
			'eq' => '=',
			'gt' => '>',
			'lt' => '<',
		)[ $op ];
		$fmt = $is_number ? '%f' : '%s';
		return $expr . $wpdb->prepare( " {$sql} {$fmt}", $v ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * First second of a day or month.
	 *
	 * @param string $d Y-m or Y-m-d.
	 */
	private static function day_start( string $d ): string {
		return ( 7 === strlen( $d ) ? $d . '-01' : $d ) . ' 00:00:00';
	}

	/**
	 * Last second of a day or month.
	 *
	 * @param string $d Y-m or Y-m-d.
	 */
	private static function day_end( string $d ): string {
		if ( 7 === strlen( $d ) ) {
			return gmdate( 'Y-m-t', (int) strtotime( $d . '-01' ) ) . ' 23:59:59';
		}
		return $d . ' 23:59:59';
	}

	/**
	 * Run a definition.
	 *
	 * @param array $def Definition.
	 * @return array{columns:array,rows:array,total_rows:int}|WP_Error
	 */
	public static function run( array $def ) {
		global $wpdb;

		$datasets = self::datasets();
		$dataset  = is_string( $def['dataset'] ?? null ) ? $def['dataset'] : '';
		if ( ! isset( $datasets[ $dataset ] ) ) {
			return new WP_Error( 'sfls_report_dataset', __( 'Unknown dataset.', 'subtleforms-license-server' ) );
		}
		$ds     = $datasets[ $dataset ];
		$fields = $ds['fields'];

		$group_by = array_values( (array) ( $def['group_by'] ?? array() ) );
		if ( count( $group_by ) > 2 ) {
			return new WP_Error( 'sfls_report_group', __( 'Group by at most two fields.', 'subtleforms-license-server' ) );
		}

		$select  = array();
		$groups  = array();
		$columns = array();
		$alias   = array();
		foreach ( $group_by as $i => $key ) {
			if ( ! is_string( $key ) || ! isset( $fields[ $key ] ) || ! $fields[ $key ]['groupable'] ) {
				return new WP_Error( 'sfls_report_field', __( 'Unknown or not groupable field.', 'subtleforms-license-server' ) );
			}
			$spec          = $fields[ $key ];
			$expr          = 'date' === $spec['type'] ? "DATE_FORMAT({$spec['expr']}, '%Y-%m')" : $spec['expr'];
			$select[]      = "{$expr} AS g{$i}";
			$groups[]      = "g{$i}";
			$columns[]     = array(
				'key'   => $key,
				'label' => $spec['label'],
				'type'  => $spec['type'],
			);
			$alias[ $key ] = "g{$i}";
		}

		$measures = array_values( (array) ( $def['measures'] ?? array() ) );
		if ( ! $measures ) {
			$measures = array( array( 'fn' => 'count' ) );
		}
		if ( count( $measures ) > 6 ) {
			return new WP_Error( 'sfls_report_measure', __( 'Too many measures.', 'subtleforms-license-server' ) );
		}
		foreach ( $measures as $i => $m ) {
			$fn = is_array( $m ) && is_string( $m['fn'] ?? null ) ? $m['fn'] : '';
			if ( ! in_array( $fn, array( 'count', 'sum', 'avg' ), true ) ) {
				return new WP_Error( 'sfls_report_measure', __( 'Unknown measure.', 'subtleforms-license-server' ) );
			}
			if ( 'count' === $fn ) {
				$select[] = "COUNT(*) AS m{$i}";
				$key      = 'count';
				$label    = __( 'Count', 'subtleforms-license-server' );
				$type     = 'number';
			} else {
				$field = is_string( $m['field'] ?? null ) ? $m['field'] : '';
				if ( ! isset( $fields[ $field ] ) || ! $fields[ $field ]['measurable'] ) {
					return new WP_Error( 'sfls_report_field', __( 'Unknown or not measurable field.', 'subtleforms-license-server' ) );
				}
				$spec     = $fields[ $field ];
				$agg      = 'sum' === $fn ? 'SUM' : 'AVG';
				$select[] = "ROUND({$agg}({$spec['expr']}), 2) AS m{$i}";
				$key      = "{$fn}_{$field}";
				$label    = ( 'sum' === $fn ? __( 'Total', 'subtleforms-license-server' ) : __( 'Average', 'subtleforms-license-server' ) ) . ' ' . strtolower( $spec['label'] );
				$type     = $spec['type'];
			}
			$columns[]     = array(
				'key'   => $key,
				'label' => $label,
				'type'  => $type,
			);
			$alias[ $key ] = "m{$i}";
		}

		$where = array( $ds['where'] );
		foreach ( array_slice( array_values( (array) ( $def['filters'] ?? array() ) ), 0, 20 ) as $filter ) {
			$field = is_array( $filter ) && is_string( $filter['field'] ?? null ) ? $filter['field'] : '';
			if ( ! isset( $fields[ $field ] ) || ! $fields[ $field ]['filterable'] ) {
				return new WP_Error( 'sfls_report_field', __( 'Unknown or not filterable field.', 'subtleforms-license-server' ) );
			}
			$cond = self::condition( $fields[ $field ], $filter );
			if ( is_wp_error( $cond ) ) {
				return $cond;
			}
			$where[] = '(' . $cond . ')';
		}
		foreach ( array(
			'from' => array( '>=', ' 00:00:00' ),
			'to'   => array( '<=', ' 23:59:59' ),
		) as $key => $bound ) {
			$d = $def[ $key ] ?? '';
			if ( ! $d ) {
				continue;
			}
			if ( ! is_string( $d ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				return new WP_Error( 'sfls_report_value', __( 'Invalid date.', 'subtleforms-license-server' ) );
			}
			$where[] = $ds['date'] . $wpdb->prepare( " {$bound[0]} %s", $d . $bound[1] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		// Sort: "key" or "key:asc|desc", on an output column only.
		$order = $groups ? $groups[0] . ' ASC' : '';
		$sort  = is_string( $def['sort'] ?? null ) ? $def['sort'] : '';
		if ( '' !== $sort ) {
			$parts = explode( ':', $sort, 2 );
			if ( ! isset( $alias[ $parts[0] ] ) ) {
				return new WP_Error( 'sfls_report_sort', __( 'Cannot sort on that column.', 'subtleforms-license-server' ) );
			}
			$order = $alias[ $parts[0] ] . ( 'desc' === strtolower( $parts[1] ?? '' ) ? ' DESC' : ' ASC' );
		}

		$limit = (int) ( $def['limit'] ?? self::DEFAULT_LIMIT );
		$limit = max( 1, min( self::MAX_GROUPS, $limit ) );

		$base = 'FROM ' . $ds['from'] . ' WHERE ' . implode( ' AND ', $where ) . ( $groups ? ' GROUP BY ' . implode( ', ', $groups ) : '' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $groups ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM (SELECT ' . implode( ', ', array_slice( $select, 0, count( $groups ) ) ) . ' ' . $base . ') t' ) : 1;
		$sql   = 'SELECT ' . implode( ', ', $select ) . ' ' . $base . ( $order ? ' ORDER BY ' . $order : '' ) . $wpdb->prepare( ' LIMIT %d', $limit );
		$raw   = $wpdb->get_results( $sql, ARRAY_N );
		// phpcs:enable

		$rows = array();
		foreach ( (array) $raw as $r ) {
			$row = array();
			foreach ( $columns as $i => $col ) {
				$v = $r[ $i ];
				if ( in_array( $col['type'], array( 'number', 'money' ), true ) && is_numeric( $v ) ) {
					$v = $v + 0;
				}
				$row[ $col['key'] ] = null === $v ? '' : $v;
			}
			$rows[] = $row;
		}

		return array(
			'columns'    => $columns,
			'rows'       => $rows,
			'total_rows' => $total,
		);
	}
}
