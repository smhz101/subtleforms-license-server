<?php
/**
 * KPI dashboard (Licenses > Dashboard).
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only overview built on SFLS_Metrics.
 */
final class SFLS_Dashboard {

	const SLUG = 'sfls-dashboard';

	/**
	 * KPIs where a rise is bad news.
	 */
	const INVERSE = array( 'churn', 'refund_rate', 'dunning_count', 'dunning_amount' );

	/**
	 * Hook.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 5 );
		add_action( 'admin_post_sfls_metrics_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_sfls_finance_sync', array( 'SFLS_Finance', 'handle_sync' ) );
	}

	/**
	 * Capability for the dashboard and reports (read-only).
	 */
	public static function capability(): string {
		return (string) apply_filters( 'sfls_reports_capability', SFLS_Admin::CAP );
	}

	/**
	 * Submenu, first in the Licenses menu.
	 */
	public function menu(): void {
		add_submenu_page( 'sfls', __( 'Dashboard', 'subtleforms-license-server' ), __( 'Dashboard', 'subtleforms-license-server' ), self::capability(), self::SLUG, array( $this, 'page' ), 0 );
	}

	/**
	 * Register the chart script; enqueue it on this screen.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( string $hook ): void {
		if ( ! wp_script_is( 'sfls-charts', 'registered' ) ) {
			wp_register_script( 'sfls-charts', SFLS_URL . 'assets/js/sfls-charts.js', array(), SFLS_VERSION, true );
		}
		if ( str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			wp_enqueue_script( 'sfls-charts' );
		}
	}

	/**
	 * Refresh button: clear the cache and come back.
	 */
	public function handle_refresh(): void {
		check_admin_referer( 'sfls_metrics_refresh' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this page.', 'subtleforms-license-server' ), '', array( 'response' => 403 ) );
		}
		SFLS_Metrics::flush();
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Range and plan from the query string.
	 *
	 * @return array{range:string,from:string,to:string,plan:string}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$range = sanitize_key( wp_unslash( $_GET['range'] ?? '30' ) );
		$plan  = sanitize_key( wp_unslash( $_GET['plan'] ?? '' ) );
		$cfrom = sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) );
		$cto   = sanitize_text_field( wp_unslash( $_GET['to'] ?? '' ) );
		// phpcs:enable
		$plan = SFLS_Plans::get( $plan ) ? $plan : '';
		$now  = current_time( 'mysql', true );
		if ( 'custom' === $range && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $cfrom ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $cto ) && strtotime( $cfrom ) && strtotime( $cto ) && $cfrom <= $cto ) {
			$utc  = static fn( string $local ): string => ( new DateTimeImmutable( $local, wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$from = $utc( $cfrom . ' 00:00:00' );
			$to   = $utc( $cto . ' 23:59:59' );
		} elseif ( '12m' === $range ) {
			$range = '12m';
			$from  = gmdate( 'Y-m-d H:i:s', strtotime( '-12 months', strtotime( $now . ' UTC' ) ) );
			$to    = $now;
		} else {
			$days  = in_array( $range, array( '7', '30', '90' ), true ) ? (int) $range : 30;
			$range = (string) $days;
			$from  = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) - $days * DAY_IN_SECONDS );
			$to    = $now;
		}
		return array(
			'range' => $range,
			'from'  => $from,
			'to'    => $to,
			'plan'  => $plan,
		);
	}

	/**
	 * Format a KPI value.
	 *
	 * @param mixed  $value  Value or null.
	 * @param string $format number|money|percent.
	 */
	public static function format_value( $value, string $format ): string {
		if ( null === $value ) {
			return '—';
		}
		if ( 'money' === $format ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $value ) ), ENT_QUOTES, 'UTF-8' );
		}
		if ( 'percent' === $format ) {
			return number_format_i18n( (float) $value, 1 ) . '%';
		}
		return number_format_i18n( (float) $value );
	}

	/**
	 * Change vs the previous period: arrow, text and tone.
	 *
	 * @param string $key   KPI key.
	 * @param array  $kpi   KPI.
	 * @return array{text:string,tone:string}|null Null without a comparison.
	 */
	public static function delta( string $key, array $kpi ): ?array {
		$value = $kpi['value'];
		$prev  = $kpi['previous'];
		if ( null === $value || null === $prev ) {
			return null;
		}
		$diff = (float) $value - (float) $prev;
		if ( 'percent' === $kpi['format'] ) {
			$text = number_format_i18n( abs( $diff ), 1 ) . ' ' . __( 'pts', 'subtleforms-license-server' );
		} elseif ( 0.0 === (float) $prev ) {
			return null; // No percent change from nothing.
		} else {
			$text = number_format_i18n( abs( $diff / (float) $prev * 100 ), 1 ) . '%';
		}
		if ( abs( $diff ) < 0.005 ) {
			return array(
				'text' => '= ' . $text,
				'tone' => 'flat',
			);
		}
		$up   = $diff > 0;
		$good = in_array( $key, self::INVERSE, true ) ? ! $up : $up;
		return array(
			'text' => ( $up ? '▲ ' : '▼ ' ) . $text . ' ' . ( $up ? __( '(up)', 'subtleforms-license-server' ) : __( '(down)', 'subtleforms-license-server' ) ),
			'tone' => $good ? 'good' : 'bad',
		);
	}

	/**
	 * KPI labels.
	 *
	 * @return array<string,string>
	 */
	private static function labels(): array {
		return array(
			'active'         => __( 'Active licenses', 'subtleforms-license-server' ),
			'new'            => __( 'New licenses', 'subtleforms-license-server' ),
			'earnings'       => __( 'Net earnings (Paddle payout)', 'subtleforms-license-server' ),
			'revenue'        => __( 'Gross sales', 'subtleforms-license-server' ),
			'refunds'        => __( 'Refunds', 'subtleforms-license-server' ),
			'net_revenue'    => __( 'Net sales', 'subtleforms-license-server' ),
			'tax'            => __( 'Tax collected', 'subtleforms-license-server' ),
			'fees'           => __( 'Paddle fees', 'subtleforms-license-server' ),
			'paying'         => __( 'Paying customers', 'subtleforms-license-server' ),
			'mrr'            => __( 'MRR (billed amount)', 'subtleforms-license-server' ),
			'arr'            => __( 'ARR (billed amount)', 'subtleforms-license-server' ),
			'projected_30'   => __( 'Expected renewals, 30 days', 'subtleforms-license-server' ),
			'projected_60'   => __( 'Expected renewals, 60 days', 'subtleforms-license-server' ),
			'projected_90'   => __( 'Expected renewals, 90 days', 'subtleforms-license-server' ),
			'renewal_rate'   => __( 'Renewal rate', 'subtleforms-license-server' ),
			'churn'          => __( 'Churn', 'subtleforms-license-server' ),
			'refund_rate'    => __( 'Refund rate', 'subtleforms-license-server' ),
			'dunning_count'  => __( 'Past due', 'subtleforms-license-server' ),
			'dunning_amount' => __( 'At risk (past due)', 'subtleforms-license-server' ),
			'active_sites'   => __( 'Active sites', 'subtleforms-license-server' ),
		);
	}

	/**
	 * A chart container.
	 *
	 * @param string $title  Heading.
	 * @param array  $config Chart config.
	 */
	private static function chart_card( string $title, array $config ): void {
		$config['title'] = $title;
		$config['empty'] = __( 'No data yet.', 'subtleforms-license-server' );
		echo '<div class="sfls-card"><h2>' . esc_html( $title ) . '</h2><div class="sfls-chart" data-chart="' . esc_attr( (string) wp_json_encode( $config ) ) . '"></div></div>';
	}

	/**
	 * A list card (table or empty state).
	 *
	 * @param string   $title Heading.
	 * @param string[] $heads Column headings.
	 * @param array[]  $rows  Rows of escaped cell HTML.
	 * @param string   $none  Empty text.
	 */
	private static function list_card( string $title, array $heads, array $rows, string $none ): void {
		echo '<div class="sfls-card sfls-card--flush"><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="sfls-empty">' . esc_html( $none ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $heads as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . implode( '</td><td>', $row ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cells are escaped by the caller.
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Page.
	 */
	public function page(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this page.', 'subtleforms-license-server' ), '', array( 'response' => 403 ) );
		}
		$f        = self::filters();
		$kpis     = SFLS_Metrics::kpis( $f['from'], $f['to'], $f['plan'] );
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$fmt      = get_option( 'date_format' );
		$date     = static fn( $gmt ) => $gmt ? wp_date( $fmt, strtotime( $gmt . ' UTC' ) ) : '—';
		$money    = static fn( $amount ) => self::format_value( $amount, 'money' );
		$minor    = static fn( array $r ) => '' !== (string) $r['billing_amount'] && '' !== (string) $r['billing_currency'] ? SFLS_Paddle::format_amount( (string) $r['billing_amount'], (string) $r['billing_currency'] ) : '—';
		$edit     = static fn( array $r ) => '<a href="' . esc_url( admin_url( 'admin.php?page=sfls&edit=' . (int) $r['id'] ) ) . '">' . esc_html( (string) $r['customer_email'] ) . '</a>';

		( new SFLS_Admin() )->header(
			__( 'Dashboard', 'subtleforms-license-server' ),
			'',
			sprintf(
				/* translators: 1: from date, 2: to date (site time) */
				__( '%1$s to %2$s (site time), sales by paid date and refunds by refund date, compared with the previous period of the same length.', 'subtleforms-license-server' ),
				$date( $f['from'] ),
				$date( $f['to'] )
			)
		);

		// Controls.
		echo '<div class="sfls-card sfls-dash-controls"><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label>' . esc_html__( 'Range', 'subtleforms-license-server' ) . ' <select name="range">';
		$ranges = array(
			'7'      => __( 'Last 7 days', 'subtleforms-license-server' ),
			'30'     => __( 'Last 30 days', 'subtleforms-license-server' ),
			'90'     => __( 'Last 90 days', 'subtleforms-license-server' ),
			'12m'    => __( 'Last 12 months', 'subtleforms-license-server' ),
			'custom' => __( 'Custom', 'subtleforms-license-server' ),
		);
		foreach ( $ranges as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $f['range'], (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'From', 'subtleforms-license-server' ) . ' <input type="date" name="from" value="' . esc_attr( 'custom' === $f['range'] ? wp_date( 'Y-m-d', strtotime( $f['from'] . ' UTC' ) ) : '' ) . '"></label> ';
		echo '<label>' . esc_html__( 'To', 'subtleforms-license-server' ) . ' <input type="date" name="to" value="' . esc_attr( 'custom' === $f['range'] ? wp_date( 'Y-m-d', strtotime( $f['to'] . ' UTC' ) ) : '' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Plan', 'subtleforms-license-server' ) . ' <select name="plan"><option value="">' . esc_html__( 'All plans', 'subtleforms-license-server' ) . '</option>';
		foreach ( array_keys( SFLS_Plans::all() ) as $slug ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $f['plan'], $slug, false ) . '>' . esc_html( SFLS_Plans::label( $slug ) ) . '</option>';
		}
		echo '</select></label> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Apply', 'subtleforms-license-server' ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sfls_metrics_refresh">';
		wp_nonce_field( 'sfls_metrics_refresh' );
		echo '<button type="submit" class="button">' . esc_html__( 'Refresh', 'subtleforms-license-server' ) . '</button> <span class="sfls-muted">' . esc_html__( 'Figures are cached for 15 minutes.', 'subtleforms-license-server' ) . '</span></form></div>';

		// Estimated figures: Paddle's totals are missing on some orders.
		$estimated = array_filter( array_column( $kpis, 'estimated' ) );
		if ( $estimated || isset( $_GET['sfls_synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info inline"><p>';
			if ( isset( $_GET['sfls_synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo esc_html( sprintf( /* translators: %s: counts such as 3-5 */ __( 'Paddle sync: %s (stored-checked).', 'subtleforms-license-server' ), sanitize_text_field( wp_unslash( $_GET['sfls_synced'] ) ) ) ) . ' '; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( $estimated ) {
				echo esc_html__( 'Some figures are estimated (marked ~): Paddle fees and payout are not stored for every order.', 'subtleforms-license-server' ) . ' ';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="sfls_finance_sync">';
			wp_nonce_field( 'sfls_finance_sync' );
			echo '<button type="submit" class="button">' . esc_html__( 'Sync Paddle figures', 'subtleforms-license-server' ) . '</button></form></p></div>';
		}

		// KPI cards.
		echo '<div class="sfls-dash-kpis">';
		foreach ( self::labels() as $key => $label ) {
			$kpi   = $kpis[ $key ] ?? array(
				'value'    => null,
				'previous' => null,
				'format'   => 'number',
			);
			$delta = self::delta( $key, $kpi );
			echo '<div class="sfls-card sfls-kpi"><div class="sfls-kpi__label">' . esc_html( $label ) . '</div><div class="sfls-kpi__value">' . esc_html( ( ! empty( $kpi['estimated'] ) ? '~' : '' ) . self::format_value( $kpi['value'], $kpi['format'] ) ) . '</div>';
			if ( isset( $kpi['n'] ) ) {
				/* translators: %d: number of licenses or orders the rate is based on */
				echo '<div class="sfls-kpi__delta">' . esc_html( null === $kpi['value'] ? __( 'No data in this period', 'subtleforms-license-server' ) : sprintf( __( 'based on %d', 'subtleforms-license-server' ), $kpi['n'] ) ) . '</div>';
			} elseif ( isset( $kpi['count'] ) ) {
				/* translators: %d: number of orders */
				echo '<div class="sfls-kpi__delta">' . esc_html( sprintf( _n( '%d order', '%d orders', (int) $kpi['count'], 'subtleforms-license-server' ), (int) $kpi['count'] ) ) . '</div>';
			}
			if ( $delta ) {
				echo '<div class="sfls-kpi__delta is-' . esc_attr( $delta['tone'] ) . '">' . esc_html( $delta['text'] ) . '</div>';
			}
			echo '</div>';
		}
		echo '</div>';

		// Charts.
		echo '<div class="sfls-dash-grid">';
		$rev = SFLS_Metrics::revenue_by_month( 12, $f['plan'] );
		self::chart_card(
			__( 'Revenue by month', 'subtleforms-license-server' ),
			array(
				'type'     => 'bar',
				'labels'   => $rev['labels'],
				'series'   => array(
					array(
						'name' => __( 'Gross', 'subtleforms-license-server' ),
						'data' => $rev['gross'],
					),
					array(
						'name' => __( 'Net', 'subtleforms-license-server' ),
						'data' => $rev['net'],
					),
				),
				'format'   => 'money',
				'currency' => $currency,
			)
		);
		$lic = SFLS_Metrics::licenses_by_month( 12, $f['plan'] );
		self::chart_card(
			__( 'New, renewed and churned licenses', 'subtleforms-license-server' ),
			array(
				'type'    => 'bar',
				'labels'  => $lic['labels'],
				'series'  => array(
					array(
						'name' => __( 'New', 'subtleforms-license-server' ),
						'data' => $lic['new'],
					),
					array(
						'name' => __( 'Renewed', 'subtleforms-license-server' ),
						'data' => $lic['renewed'],
					),
					array(
						'name' => __( 'Churned', 'subtleforms-license-server' ),
						'data' => $lic['churned'],
					),
				),
				'format'  => 'number',
				'stacked' => true,
			)
		);
		$mix = SFLS_Metrics::plan_mix();
		self::chart_card(
			__( 'Plan mix (active licenses)', 'subtleforms-license-server' ),
			array(
				'type'   => 'pie',
				'labels' => array_map( array( 'SFLS_Plans', 'label' ), array_keys( $mix ) ),
				'series' => array(
					array(
						'name' => __( 'Licenses', 'subtleforms-license-server' ),
						'data' => array_values( $mix ),
					),
				),
				'format' => 'number',
			)
		);
		$due = SFLS_Metrics::renewals_due( array( 30, 60, 90 ), $f['plan'] );
		self::chart_card(
			__( 'Renewals due', 'subtleforms-license-server' ),
			array(
				'type'   => 'bar',
				'labels' => array_map(
					/* translators: %d: days */
					static fn( $d ) => sprintf( _n( 'Next %d day', 'Next %d days', (int) $d, 'subtleforms-license-server' ), $d ),
					array_keys( $due )
				),
				'series' => array(
					array(
						'name' => __( 'Licenses', 'subtleforms-license-server' ),
						'data' => array_values( $due ),
					),
				),
				'format' => 'number',
			)
		);
		$versions = SFLS_Metrics::version_adoption();
		if ( $versions ) {
			self::chart_card(
				__( 'Pro version adoption (sites seen in 30 days)', 'subtleforms-license-server' ),
				array(
					'type'   => 'bar',
					'labels' => array_map( 'strval', array_keys( $versions ) ),
					'series' => array(
						array(
							'name' => __( 'Sites', 'subtleforms-license-server' ),
							'data' => array_values( $versions ),
						),
					),
					'format' => 'number',
				)
			);
		}
		echo '</div>';

		// Lists.
		echo '<div class="sfls-dash-grid">';
		self::list_card(
			__( 'Renewals due this week', 'subtleforms-license-server' ),
			array( __( 'Customer', 'subtleforms-license-server' ), __( 'Plan', 'subtleforms-license-server' ), __( 'Expires', 'subtleforms-license-server' ), __( 'Amount', 'subtleforms-license-server' ) ),
			array_map( static fn( $r ) => array( $edit( $r ), esc_html( SFLS_Plans::label( (string) $r['plan'] ) ), esc_html( $date( $r['expires_at'] ) ), esc_html( $minor( $r ) ) ), SFLS_Metrics::renewals_due_list( 7, $f['plan'] ) ),
			__( 'Nothing is due this week.', 'subtleforms-license-server' )
		);
		self::list_card(
			__( 'Dunning (payment failed)', 'subtleforms-license-server' ),
			array( __( 'Customer', 'subtleforms-license-server' ), __( 'Plan', 'subtleforms-license-server' ), __( 'Expires', 'subtleforms-license-server' ), __( 'Amount', 'subtleforms-license-server' ) ),
			array_map( static fn( $r ) => array( $edit( $r ), esc_html( SFLS_Plans::label( (string) $r['plan'] ) ), esc_html( $date( $r['expires_at'] ) ), esc_html( $minor( $r ) ) ), SFLS_Metrics::dunning_list( $f['plan'] ) ),
			__( 'No payments are failing.', 'subtleforms-license-server' )
		);
		self::list_card(
			__( 'Latest refunds', 'subtleforms-license-server' ),
			array( __( 'Order', 'subtleforms-license-server' ), __( 'Customer', 'subtleforms-license-server' ), __( 'Amount', 'subtleforms-license-server' ), __( 'Date', 'subtleforms-license-server' ) ),
			array_map( static fn( $r ) => array( '#' . (int) $r['order_id'], esc_html( $r['email'] ), esc_html( $money( $r['amount'] ) ), esc_html( $date( $r['date'] ) ) ), SFLS_Metrics::latest_refunds( 10 ) ),
			__( 'No refunds yet.', 'subtleforms-license-server' )
		);
		self::list_card(
			__( 'Latest admin changes', 'subtleforms-license-server' ),
			array( __( 'When', 'subtleforms-license-server' ), __( 'Who', 'subtleforms-license-server' ), __( 'License', 'subtleforms-license-server' ), __( 'Change', 'subtleforms-license-server' ) ),
			array_map(
				static fn( $r ) => array(
					esc_html( $date( $r['created_at'] ) ),
					esc_html( (string) $r['user'] ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=sfls&edit=' . (int) $r['license_id'] ) ) . '">#' . (int) $r['license_id'] . '</a>',
					esc_html( $r['field'] . ': ' . $r['old_value'] . ' → ' . $r['new_value'] ),
				),
				SFLS_Metrics::latest_audit( 10 )
			),
			__( 'No changes logged yet.', 'subtleforms-license-server' )
		);
		echo '</div></div>';
	}
}
