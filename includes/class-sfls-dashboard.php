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
			return self::money_text( (float) $value );
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
	 * @return array{text:string,short:string,dir:string,tone:string}|null Null without a comparison.
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
				'text'  => '= ' . $text,
				'short' => $text,
				'dir'   => '',
				'tone'  => 'flat',
			);
		}
		$up   = $diff > 0;
		$good = in_array( $key, self::INVERSE, true ) ? ! $up : $up;
		$dir  = $up ? __( '(up)', 'subtleforms-license-server' ) : __( '(down)', 'subtleforms-license-server' );
		return array(
			'text'  => ( $up ? '▲ ' : '▼ ' ) . $text . ' ' . $dir,
			'short' => ( $up ? '▲ ' : '▼ ' ) . $text,
			'dir'   => $dir,
			'tone'  => $good ? 'good' : 'bad',
		);
	}

	/**
	 * Money as plain text in the store currency, with a true minus sign.
	 *
	 * @param float $value   Amount.
	 * @param bool  $compact Shorten thousands (12.4k).
	 */
	public static function money_text( float $value, bool $compact = false ): string {
		return SFLS_Metrics::format_money( $value, '', $compact );
	}

	/**
	 * "in 5 days" / "5 days ago".
	 *
	 * @param string $gmt UTC datetime.
	 */
	public static function relative( string $gmt ): string {
		$ts = $gmt ? strtotime( $gmt . ' UTC' ) : false;
		if ( ! $ts ) {
			return '—';
		}
		$diff = human_time_diff( $ts, time() );
		/* translators: %s: time span such as "5 days" */
		return $ts > time() ? sprintf( __( 'in %s', 'subtleforms-license-server' ), $diff ) : sprintf( __( '%s ago', 'subtleforms-license-server' ), $diff );
	}

	/**
	 * Nonce protected link to the customer view.
	 *
	 * @param string $email Customer email.
	 */
	private static function customer_url( string $email ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'  => 'sfls-customer',
					'email' => rawurlencode( $email ),
				),
				admin_url( 'admin.php' )
			),
			'sfls_customer'
		);
	}

	/**
	 * Net earnings per day (per month for long ranges) from the ledger rows.
	 *
	 * @param array $f Filters.
	 * @return array{labels:string[],data:float[]}
	 */
	private static function spark( array $f ): array {
		$tz      = wp_timezone();
		$start   = strtotime( $f['from'] . ' UTC' );
		$end     = strtotime( $f['to'] . ' UTC' );
		$monthly = $end - $start > 100 * DAY_IN_SECONDS;
		$key     = $monthly ? 'Y-m' : 'Y-m-d';
		$buckets = array();
		$cursor  = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( $tz )->setTime( 0, 0 );
		$cursor  = $monthly ? $cursor->modify( 'first day of this month' ) : $cursor;
		$i       = 0;
		while ( $i++ < 400 && $cursor->getTimestamp() <= $end ) {
			$buckets[ $cursor->format( $key ) ] = 0.0;
			$cursor                             = $cursor->modify( $monthly ? '+1 month' : '+1 day' );
		}
		$rows = array_merge( SFLS_Metrics::orders( $f['from'], $f['to'], $f['plan'] )['rows'], SFLS_Metrics::refunds( $f['from'], $f['to'], $f['plan'] ) );
		foreach ( $rows as $row ) {
			$ts = $row['date'] ? strtotime( $row['date'] . ' UTC' ) : false;
			$k  = $ts ? wp_date( $key, $ts ) : '';
			if ( isset( $buckets[ $k ] ) ) {
				$buckets[ $k ] += (float) $row['earnings'];
			}
		}
		$labels = array();
		foreach ( array_keys( $buckets ) as $k ) {
			$labels[] = wp_date( $monthly ? 'M Y' : 'M j', strtotime( $k . ( $monthly ? '-15' : '' ) . ' 12:00:00 UTC' ) );
		}
		return array(
			'labels' => $labels,
			'data'   => array_map( static fn( $v ) => round( $v, 2 ), array_values( $buckets ) ),
		);
	}

	/**
	 * Gross sales and net earnings of the last 12 months.
	 *
	 * @param string $plan Plan.
	 * @return array{labels:string[],gross:float[],earnings:float[]}
	 */
	private static function months( string $plan ): array {
		$out   = array(
			'labels'   => array(),
			'gross'    => array(),
			'earnings' => array(),
		);
		$tz    = wp_timezone();
		$utc   = new DateTimeZone( 'UTC' );
		$first = ( new DateTimeImmutable( 'now', $tz ) )->modify( 'first day of this month' )->setTime( 0, 0 );
		for ( $i = 11; $i >= 0; $i-- ) {
			$start             = $first->modify( "-{$i} months" );
			$money             = SFLS_Metrics::money( $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $start->modify( '+1 month' )->modify( '-1 second' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $plan );
			$out['labels'][]   = wp_date( 'M y', $start->getTimestamp() );
			$out['gross'][]    = $money['gross']['amount'];
			$out['earnings'][] = $money['earnings']['amount'];
		}
		return $out;
	}

	/**
	 * Everything the page shows (filter: sfls_dashboard_data).
	 *
	 * @param array $f Filters from self::filters().
	 */
	public static function view( array $f ): array {
		$ver = (int) get_option( 'sfls_metrics_v', 0 );
		$gen = (int) get_transient( 'sfls_dash_gen_' . $ver );
		if ( ! $gen ) {
			$gen = time();
			set_transient( 'sfls_dash_gen_' . $ver, $gen, SFLS_Metrics::TTL );
		}
		$lic  = SFLS_Metrics::licenses_by_month( 12, $f['plan'] );
		$data = array(
			'filters'     => $f,
			'generated'   => $gen,
			'kpis'        => SFLS_Metrics::kpis( $f['from'], $f['to'], $f['plan'] ),
			'money'       => SFLS_Metrics::money( $f['from'], $f['to'], $f['plan'] ),
			'spark'       => self::spark( $f ),
			'months'      => self::months( $f['plan'] ),
			'licenses'    => array(
				'labels'  => array_map( static fn( $l ) => wp_date( 'M y', strtotime( $l . '-15 12:00:00 UTC' ) ), $lic['labels'] ),
				'new'     => $lic['new'],
				'churned' => $lic['churned'],
			),
			'mix'         => SFLS_Metrics::plan_mix(),
			'projections' => SFLS_Metrics::projections( array( 30, 60, 90 ), $f['plan'] ),
			'due'         => array_slice( SFLS_Metrics::renewals_due_list( 30, $f['plan'] ), 0, 8 ),
			'failed'      => array_slice( SFLS_Metrics::dunning_list( $f['plan'] ), 0, 8 ),
			'refunds'     => SFLS_Metrics::latest_refunds( 8 ),
			'audit'       => SFLS_Metrics::latest_audit( 8 ),
		);
		/**
		 * Filter the assembled dashboard view-model.
		 *
		 * @param array $data Data.
		 * @param array $f    Filters.
		 */
		return (array) apply_filters( 'sfls_dashboard_data', $data, $f );
	}

	/**
	 * Delta pill.
	 *
	 * @param array|null $delta From self::delta().
	 */
	private static function pill( ?array $delta ): void {
		if ( ! $delta ) {
			echo '<span class="sfls-pill is-flat" title="' . esc_attr__( 'No previous period to compare with', 'subtleforms-license-server' ) . '">—</span>';
			return;
		}
		echo '<span class="sfls-pill is-' . esc_attr( $delta['tone'] ) . '" title="' . esc_attr__( 'Compared with the previous period', 'subtleforms-license-server' ) . '">' . esc_html( $delta['short'] ) . ( $delta['dir'] ? ' <span class="screen-reader-text">' . esc_html( $delta['dir'] ) . '</span>' : '' ) . '</span>';
	}

	/**
	 * Empty state.
	 *
	 * @param string $icon   Dashicon name.
	 * @param string $text   One line.
	 * @param string $action Optional action label.
	 * @param string $url    Action URL.
	 */
	private static function empty_state( string $icon, string $text, string $action = '', string $url = '' ): void {
		echo '<div class="sfls-empty-state"><span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span><p>' . esc_html( $text ) . '</p>';
		if ( $action ) {
			echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html( $action ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * A chart card.
	 *
	 * @param string $title   Heading.
	 * @param array  $config  Chart config.
	 * @param bool   $has     Whether there is data to draw.
	 * @param array  $blank_state   Empty state: icon, text, action, url.
	 * @param string $sub     Muted line under the heading.
	 */
	private static function chart_card( string $title, array $config, bool $has, array $blank_state, string $sub = '' ): void {
		$config['title'] = $title;
		echo '<section class="sfls-card sfls-dcard"><header class="sfls-dcard__head"><h2>' . esc_html( $title ) . '</h2>' . ( $sub ? '<span class="sfls-muted">' . esc_html( $sub ) . '</span>' : '' ) . '</header>';
		if ( $has ) {
			echo '<div class="sfls-chart" data-chart="' . esc_attr( (string) wp_json_encode( $config ) ) . '"></div>';
		} else {
			self::empty_state( ...$blank_state );
		}
		echo '</section>';
	}

	/**
	 * A compact list card.
	 *
	 * @param string   $title Heading.
	 * @param string[] $heads Column headings.
	 * @param array[]  $rows  Rows of escaped cell HTML.
	 * @param array    $blank_state Empty state: icon, text, action, url.
	 */
	private static function list_card( string $title, array $heads, array $rows, array $blank_state ): void {
		echo '<section class="sfls-card sfls-dcard sfls-dcard--list"><header class="sfls-dcard__head"><h2>' . esc_html( $title ) . '</h2></header>';
		if ( ! $rows ) {
			self::empty_state( ...$blank_state );
			echo '</section>';
			return;
		}
		echo '<div class="sfls-dtable-wrap"><table class="sfls-dtable"><thead><tr>';
		foreach ( $heads as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . implode( '</td><td>', $row ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cells are escaped by the caller.
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * Header controls: range, plan, refresh, sync.
	 *
	 * @param array $f         Filters.
	 * @param bool  $estimated Any estimated figure.
	 */
	private static function controls( array $f, bool $estimated ): string {
		$custom = 'custom' === $f['range'];
		$ranges = array(
			'7'      => __( '7D', 'subtleforms-license-server' ),
			'30'     => __( '30D', 'subtleforms-license-server' ),
			'90'     => __( '90D', 'subtleforms-license-server' ),
			'12m'    => __( '12M', 'subtleforms-license-server' ),
			'custom' => __( 'Custom', 'subtleforms-license-server' ),
		);
		ob_start();
		echo '<div class="sfls-dash-controls">';
		echo '<form method="get" class="sfls-dash-filter' . ( $custom ? ' is-custom' : '' ) . '" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<div class="sfls-seg" role="radiogroup" aria-label="' . esc_attr__( 'Range', 'subtleforms-license-server' ) . '">';
		foreach ( $ranges as $value => $label ) {
			echo '<label class="sfls-seg__opt"><input type="radio" name="range" value="' . esc_attr( (string) $value ) . '"' . checked( $f['range'], (string) $value, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div>';
		echo '<span class="sfls-dash-dates"><label><span class="screen-reader-text">' . esc_html__( 'From', 'subtleforms-license-server' ) . '</span><input type="date" name="from" value="' . esc_attr( $custom ? wp_date( 'Y-m-d', strtotime( $f['from'] . ' UTC' ) ) : '' ) . '"></label><span aria-hidden="true">–</span><label><span class="screen-reader-text">' . esc_html__( 'To', 'subtleforms-license-server' ) . '</span><input type="date" name="to" value="' . esc_attr( $custom ? wp_date( 'Y-m-d', strtotime( $f['to'] . ' UTC' ) ) : '' ) . '"></label></span>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Plan', 'subtleforms-license-server' ) . '</span><select name="plan"><option value="">' . esc_html__( 'All plans', 'subtleforms-license-server' ) . '</option>';
		foreach ( array_keys( SFLS_Plans::all() ) as $slug ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $f['plan'], $slug, false ) . '>' . esc_html( SFLS_Plans::label( $slug ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<button type="submit" class="button sfls-dash-apply">' . esc_html__( 'Apply', 'subtleforms-license-server' ) . '</button></form>';
		echo '<noscript><style>.sfls-dash-apply{display:inline-flex!important}.sfls-dash-dates{display:inline-flex!important}</style></noscript>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sfls_metrics_refresh">';
		wp_nonce_field( 'sfls_metrics_refresh' );
		echo '<button type="submit" class="button sfls-icon-btn" title="' . esc_attr__( 'Refresh (figures are cached for 15 minutes)', 'subtleforms-license-server' ) . '"><span class="dashicons dashicons-update" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Refresh', 'subtleforms-license-server' ) . '</span></button></form>';
		if ( $estimated ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sfls_finance_sync">';
			wp_nonce_field( 'sfls_finance_sync' );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Sync Paddle figures', 'subtleforms-license-server' ) . '</button></form>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Page.
	 */
	public function page(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this page.', 'subtleforms-license-server' ), '', array( 'response' => 403 ) );
		}
		$f         = self::filters();
		$v         = self::view( $f );
		$kpis      = (array) $v['kpis'];
		$money     = (array) $v['money'];
		$currency  = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$fmt       = get_option( 'date_format' );
		$date      = static fn( $gmt ) => $gmt ? wp_date( $fmt, strtotime( $gmt . ' UTC' ) ) : '—';
		$m         = static fn( $amount, bool $compact = false ) => self::money_text( (float) $amount, $compact );
		$minor     = static fn( array $r ) => '' !== (string) $r['billing_amount'] && '' !== (string) $r['billing_currency'] ? SFLS_Paddle::format_amount( (string) $r['billing_amount'], (string) $r['billing_currency'] ) : '—';
		$cust      = static fn( string $email ) => '<a href="' . esc_url( self::customer_url( $email ) ) . '">' . esc_html( $email ) . '</a>';
		$plan      = static fn( string $slug ) => '<span class="sfls-badge is-neutral">' . esc_html( SFLS_Plans::label( $slug ) ) . '</span>';
		$licenses  = admin_url( 'admin.php?page=sfls' );
		$estimated = (bool) array_filter( array_column( $money, 'estimated' ) );
		$synced    = isset( $_GET['sfls_synced'] ) ? sanitize_text_field( wp_unslash( $_GET['sfls_synced'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		( new SFLS_Admin() )->header(
			__( 'Dashboard', 'subtleforms-license-server' ),
			'',
			sprintf(
				/* translators: %s: how long ago the figures were computed */
				__( 'Figures from Paddle payouts · updated %s', 'subtleforms-license-server' ),
				self::relative( gmdate( 'Y-m-d H:i:s', (int) $v['generated'] ) )
			),
			self::controls( $f, $estimated )
		);

		echo '<div class="sfls-dash">';
		if ( $synced ) {
			/* translators: %s: counts such as 3-5 */
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'Paddle sync: %s (stored-checked).', 'subtleforms-license-server' ), $synced ) ) . '</p></div>';
		}
		$failed = isset( $_GET['sfls_sync_failed'] ) ? sanitize_text_field( wp_unslash( $_GET['sfls_sync_failed'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $failed ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $failed ) . '</p></div>';
		}
		if ( $estimated ) {
			echo '<p class="sfls-dash-note"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span>' . esc_html__( 'Some figures are estimated (marked ~). Paddle has not reported its fees and payout for every order yet, so they are calculated from order totals. Sync Paddle figures to fetch the real amounts.', 'subtleforms-license-server' ) . '</span></p>';
		}

		// Hero.
		$earn      = (array) ( $kpis['earnings'] ?? array(
			'value'    => null,
			'previous' => null,
			'format'   => 'money',
		) );
		$ev        = null === $earn['value'] ? null : (float) $earn['value'];
		$spark     = (array) $v['spark'];
		$has_spark = (bool) array_filter( $spark['data'], static fn( $x ) => 0.0 !== (float) $x );
		$figure    = static fn( string $k ): array => (array) ( $money[ $k ] ?? array(
			'amount'    => 0.0,
			'count'     => 0,
			'estimated' => false,
		) );
		$rows      = array(
			array( 'gross', '', __( 'Gross sales', 'subtleforms-license-server' ), $figure( 'gross' ), 'orders' ),
			array( 'refunds', '−', __( 'Refunds', 'subtleforms-license-server' ), $figure( 'refunds' ), 'refunds' ),
			array( 'tax', '−', __( 'Tax (net of refunds)', 'subtleforms-license-server' ), $figure( 'tax' ), 'orders' ),
			array( 'fees', '−', __( 'Paddle fees', 'subtleforms-license-server' ), $figure( 'fees' ), 'orders' ),
			array( 'earnings', '=', __( 'Net earnings', 'subtleforms-license-server' ), $figure( 'earnings' ), 'orders' ),
		);
		$scale     = max( 0.01, abs( $figure( 'gross' )['amount'] ), abs( $figure( 'refunds' )['amount'] ), abs( $figure( 'tax' )['amount'] ), abs( $figure( 'fees' )['amount'] ), abs( $figure( 'earnings' )['amount'] ) );
		$running   = (float) $figure( 'gross' )['amount'];

		echo '<section class="sfls-card sfls-hero"><div class="sfls-hero__main">';
		echo '<div class="sfls-kpi__label">' . esc_html__( 'Net earnings', 'subtleforms-license-server' ) . ( ! empty( $earn['estimated'] ) ? ' <span class="sfls-badge is-past_due">' . esc_html__( 'Estimated', 'subtleforms-license-server' ) . '</span>' : '' ) . '</div>';
		echo '<div class="sfls-hero__value' . ( null !== $ev && $ev < 0 ? ' is-negative' : '' ) . '" title="' . esc_attr( null === $ev ? '' : $m( $ev ) ) . '">' . esc_html( null === $ev ? '—' : $m( $ev ) ) . '</div>';
		echo '<div class="sfls-hero__meta">';
		self::pill( null === $ev ? null : self::delta( 'earnings', $earn ) );
		echo '<span class="sfls-muted">' . esc_html(
			sprintf(
				/* translators: 1: from date, 2: to date (site time) */
				__( 'vs previous period · %1$s – %2$s', 'subtleforms-license-server' ),
				$date( $f['from'] ),
				$date( $f['to'] )
			)
		) . '</span></div>';
		if ( $has_spark ) {
			echo '<div class="sfls-chart sfls-chart--spark" data-chart="' . esc_attr(
				(string) wp_json_encode(
					array(
						'type'     => 'spark',
						'title'    => __( 'Net earnings', 'subtleforms-license-server' ),
						'labels'   => $spark['labels'],
						'series'   => array(
							array(
								'name' => __( 'Net earnings', 'subtleforms-license-server' ),
								'data' => $spark['data'],
							),
						),
						'format'   => 'money',
						'currency' => $currency,
					)
				)
			) . '"></div>';
		} else {
			self::empty_state( 'chart-area', __( 'No earnings in this period.', 'subtleforms-license-server' ) );
		}
		echo '</div><div class="sfls-hero__side"><h2>' . esc_html__( 'Earnings breakdown', 'subtleforms-license-server' ) . '</h2><ul class="sfls-wf">';
		foreach ( $rows as $row ) {
			list( $key, $op, $label, $fig, $unit ) = $row;
			$amt                                   = (float) $fig['amount'];
			if ( 'earnings' === $key ) {
				$left  = 0.0;
				$width = abs( $amt ) / $scale * 100;
				$tone  = $amt < 0 ? 'neg' : 'pos';
			} elseif ( 'gross' === $key ) {
				$left  = 0.0;
				$width = $amt / $scale * 100;
				$tone  = 'accent';
			} else {
				$left     = max( 0.0, $running - $amt ) / $scale * 100;
				$width    = min( $amt, max( $running, 0.0 ) ) / $scale * 100;
				$running -= $amt;
				$tone     = 'refunds' === $key ? 'neg' : 'warn';
			}
			$count = 'refunds' === $unit
				/* translators: %d: number of refunded orders */
				? sprintf( _n( '%d refund', '%d refunds', (int) $fig['count'], 'subtleforms-license-server' ), (int) $fig['count'] )
				/* translators: %d: number of orders */
				: sprintf( _n( '%d order', '%d orders', (int) $fig['count'], 'subtleforms-license-server' ), (int) $fig['count'] );
			if ( 'earnings' === $key ) {
				// Net earnings combine sales and refunds: name both instead of adding them up.
				$sales_n  = (int) ( $figure( 'gross' )['count'] ?? 0 );
				$refund_n = (int) ( $figure( 'refunds' )['count'] ?? 0 );
				/* translators: %d: number of orders */
				$count = sprintf( _n( '%d order', '%d orders', $sales_n, 'subtleforms-license-server' ), $sales_n );
				if ( $refund_n > 0 ) {
					/* translators: %d: number of refunds */
					$count .= ' · ' . sprintf( _n( '%d refund', '%d refunds', $refund_n, 'subtleforms-license-server' ), $refund_n );
				}
			}
			echo '<li class="sfls-wf__row' . ( 'earnings' === $key ? ' is-total' : '' ) . '"><div class="sfls-wf__line"><span class="sfls-wf__label"><span class="sfls-wf__op" aria-hidden="true">' . esc_html( $op ) . '</span>' . esc_html( $label ) . '</span>';
			echo '<span class="sfls-wf__amount' . ( 'earnings' === $key && $amt < 0 ? ' is-negative' : '' ) . '">' . esc_html( ( ! empty( $fig['estimated'] ) ? '~' : '' ) . $m( $amt ) ) . '</span></div>';
			echo '<div class="sfls-wf__track" aria-hidden="true"><span class="sfls-wf__bar is-' . esc_attr( $tone ) . '" style="left:' . esc_attr( (string) round( $left, 2 ) ) . '%;width:' . esc_attr( (string) round( min( $width, 100 - $left ), 2 ) ) . '%"></span></div>';
			echo '<div class="sfls-muted sfls-wf__count">' . esc_html( $count ) . '</div></li>';
		}
		echo '</ul></div></section>';

		// KPI grid.
		$cards = array(
			'net_revenue'  => array( __( 'Net sales', 'subtleforms-license-server' ), '' ),
			'mrr'          => array( __( 'MRR', 'subtleforms-license-server' ), __( 'excl. canceling subscriptions', 'subtleforms-license-server' ) ),
			'arr'          => array( __( 'ARR', 'subtleforms-license-server' ), __( 'MRR × 12', 'subtleforms-license-server' ) ),
			'active'       => array( __( 'Active licenses', 'subtleforms-license-server' ), '' ),
			'paying'       => array( __( 'Paying customers', 'subtleforms-license-server' ), __( 'bought, not issued manually', 'subtleforms-license-server' ) ),
			'renewal_rate' => array( __( 'Renewal rate', 'subtleforms-license-server' ), '' ),
			'churn'        => array( __( 'Churn', 'subtleforms-license-server' ), '' ),
			'refund_rate'  => array( __( 'Refund rate', 'subtleforms-license-server' ), '' ),
		);
		echo '<div class="sfls-kpis">';
		foreach ( $cards as $key => $meta ) {
			$kpi   = (array) ( $kpis[ $key ] ?? array(
				'value'    => null,
				'previous' => null,
				'format'   => 'number',
			) );
			$val   = $kpi['value'];
			$rate  = 'percent' === $kpi['format'];
			$blank = null === $val || ( ! $rate && 0.0 === (float) $val && empty( $kpi['count'] ) );
			if ( $blank ) {
				$text = '—';
				$full = '';
			} elseif ( 'money' === $kpi['format'] ) {
				$text = $m( $val, true );
				$full = $m( $val );
			} else {
				$text = self::format_value( $val, $kpi['format'] );
				$full = $text;
			}
			if ( $blank ) {
				$foot = __( 'Not enough data yet', 'subtleforms-license-server' );
			} elseif ( isset( $kpi['n'] ) ) {
				$foot = sprintf(
					/* translators: %d: number of renewals, licenses or orders */
					'renewal_rate' === $key ? _n( 'based on %d renewal due', 'based on %d renewals due', (int) $kpi['n'], 'subtleforms-license-server' ) : ( 'refund_rate' === $key ? _n( 'based on %d order', 'based on %d orders', (int) $kpi['n'], 'subtleforms-license-server' ) : _n( 'based on %d license', 'based on %d licenses', (int) $kpi['n'], 'subtleforms-license-server' ) ),
					(int) $kpi['n']
				);
			} elseif ( isset( $kpi['count'] ) ) {
				/* translators: %d: number of orders */
				$foot = sprintf( _n( '%d order', '%d orders', (int) $kpi['count'], 'subtleforms-license-server' ), (int) $kpi['count'] );
			} else {
				$foot = $meta[1];
			}
			echo '<div class="sfls-card sfls-kpi"><div class="sfls-kpi__label">' . esc_html( $meta[0] ) . '</div><div class="sfls-kpi__value' . ( $blank ? ' is-empty' : '' ) . ( ! $blank && (float) $val < 0 ? ' is-negative' : '' ) . '" title="' . esc_attr( $full ) . '">' . esc_html( $text ) . '</div><div class="sfls-kpi__foot">';
			self::pill( $blank ? null : self::delta( $key, $kpi ) );
			echo '</div><div class="sfls-muted sfls-kpi__note">' . esc_html( $foot ) . '</div></div>';
		}
		echo '</div>';

		// Forecast and plan mix.
		echo '<div class="sfls-dash-row">';
		$proj    = (array) $v['projections'];
		$has_due = (bool) array_filter( array_column( $proj, 'count' ) );
		$risk_n  = (int) ( $kpis['dunning_count']['value'] ?? 0 );
		$risk_a  = (float) ( $kpis['dunning_amount']['value'] ?? 0 );
		echo '<section class="sfls-card sfls-dcard"><header class="sfls-dcard__head"><h2>' . esc_html__( 'Renewal forecast', 'subtleforms-license-server' ) . '</h2><span class="sfls-muted">' . esc_html__( 'non-canceling subscriptions', 'subtleforms-license-server' ) . '</span></header>';
		if ( $has_due ) {
			echo '<div class="sfls-tiles">';
			foreach ( $proj as $days => $p ) {
				/* translators: %d: days */
				echo '<div class="sfls-tile"><div class="sfls-kpi__label">' . esc_html( sprintf( __( 'Next %d days', 'subtleforms-license-server' ), (int) $days ) ) . '</div><div class="sfls-tile__value" title="' . esc_attr( $m( $p['amount'] ) ) . '">' . esc_html( $m( $p['amount'], true ) ) . '</div><div class="sfls-muted">' . esc_html( sprintf( _n( '%d renewal', '%d renewals', (int) $p['count'], 'subtleforms-license-server' ), (int) $p['count'] ) ) . '</div></div>';
			}
			echo '</div>';
		} else {
			self::empty_state( 'calendar-alt', __( 'No renewals are scheduled in the next 90 days.', 'subtleforms-license-server' ), __( 'View licenses', 'subtleforms-license-server' ), $licenses );
		}
		if ( $risk_n > 0 ) {
			/* translators: %d: number of subscriptions */
			echo '<div class="sfls-risk"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span class="sfls-risk__label">' . esc_html__( 'At risk (failed payments)', 'subtleforms-license-server' ) . '</span><strong title="' . esc_attr( $m( $risk_a ) ) . '">' . esc_html( $m( $risk_a, true ) ) . '</strong><span>' . esc_html( sprintf( _n( '%d subscription', '%d subscriptions', $risk_n, 'subtleforms-license-server' ), $risk_n ) ) . '</span></div>';
		}
		echo '</section>';

		$mix = (array) $v['mix'];
		self::chart_card(
			__( 'Plan mix', 'subtleforms-license-server' ),
			array(
				'type'   => 'donut',
				'labels' => array_map( array( 'SFLS_Plans', 'label' ), array_map( 'strval', array_keys( $mix ) ) ),
				'series' => array(
					array(
						'name' => __( 'Active licenses', 'subtleforms-license-server' ),
						'data' => array_values( $mix ),
					),
				),
				'format' => 'number',
				'unit'   => __( 'licenses', 'subtleforms-license-server' ),
			),
			(bool) array_filter( $mix ),
			array( 'chart-pie', __( 'No active licenses yet.', 'subtleforms-license-server' ), __( 'View licenses', 'subtleforms-license-server' ), $licenses ),
			__( 'active licenses', 'subtleforms-license-server' )
		);
		echo '</div>';

		// Earnings by month.
		$mo = (array) $v['months'];
		self::chart_card(
			__( 'Earnings by month', 'subtleforms-license-server' ),
			array(
				'type'     => 'bar',
				'labels'   => $mo['labels'],
				'series'   => array(
					array(
						'name' => __( 'Net earnings', 'subtleforms-license-server' ),
						'data' => $mo['earnings'],
						'kind' => 'bar',
					),
					array(
						'name' => __( 'Gross sales', 'subtleforms-license-server' ),
						'data' => $mo['gross'],
						'kind' => 'line',
					),
				),
				'format'   => 'money',
				'currency' => $currency,
			),
			(bool) array_filter( array_merge( $mo['earnings'], $mo['gross'] ), static fn( $x ) => 0.0 !== (float) $x ),
			array( 'chart-bar', __( 'No sales or refunds in the last 12 months.', 'subtleforms-license-server' ), __( 'View licenses', 'subtleforms-license-server' ), $licenses ),
			__( 'last 12 months', 'subtleforms-license-server' )
		);

		// Licenses by month.
		$lm = (array) $v['licenses'];
		self::chart_card(
			__( 'Licenses', 'subtleforms-license-server' ),
			array(
				'type'   => 'bar',
				'labels' => $lm['labels'],
				'series' => array(
					array(
						'name' => __( 'New', 'subtleforms-license-server' ),
						'data' => $lm['new'],
					),
					array(
						'name'  => __( 'Churned', 'subtleforms-license-server' ),
						'data'  => $lm['churned'],
						'color' => '#d63638',
					),
				),
				'format' => 'number',
			),
			(bool) array_filter( array_merge( $lm['new'], $lm['churned'] ) ),
			array( 'groups', __( 'No new or churned licenses in the last 12 months.', 'subtleforms-license-server' ), __( 'View licenses', 'subtleforms-license-server' ), $licenses ),
			__( 'new vs churned, last 12 months', 'subtleforms-license-server' )
		);

		// Tables.
		$heads = array( __( 'Customer', 'subtleforms-license-server' ), __( 'Plan', 'subtleforms-license-server' ), __( 'Amount', 'subtleforms-license-server' ), __( 'Date', 'subtleforms-license-server' ) );
		echo '<div class="sfls-dash-row">';
		self::list_card(
			__( 'Upcoming renewals', 'subtleforms-license-server' ),
			$heads,
			array_map( static fn( $r ) => array( $cust( (string) $r['customer_email'] ), $plan( (string) $r['plan'] ), esc_html( $minor( $r ) ), '<span title="' . esc_attr( $date( $r['expires_at'] ) ) . '">' . esc_html( self::relative( (string) $r['expires_at'] ) ) . '</span>' ), (array) $v['due'] ),
			array( 'calendar-alt', __( 'Nothing is due in the next 30 days.', 'subtleforms-license-server' ), __( 'View licenses', 'subtleforms-license-server' ), $licenses )
		);
		self::list_card(
			__( 'Failed payments', 'subtleforms-license-server' ),
			$heads,
			array_map( static fn( $r ) => array( $cust( (string) $r['customer_email'] ), $plan( (string) $r['plan'] ) . ' <span class="sfls-badge is-past_due">' . esc_html__( 'Past due', 'subtleforms-license-server' ) . '</span>', esc_html( $minor( $r ) ), '<span title="' . esc_attr( $date( $r['expires_at'] ) ) . '">' . esc_html( self::relative( (string) $r['expires_at'] ) ) . '</span>' ), (array) $v['failed'] ),
			array( 'yes-alt', __( 'No payments are failing.', 'subtleforms-license-server' ) )
		);
		self::list_card(
			__( 'Recent refunds', 'subtleforms-license-server' ),
			array( __( 'Customer', 'subtleforms-license-server' ), __( 'Order', 'subtleforms-license-server' ), __( 'Amount', 'subtleforms-license-server' ), __( 'Date', 'subtleforms-license-server' ) ),
			array_map( static fn( $r ) => array( $cust( (string) $r['email'] ), '#' . (int) $r['order_id'], esc_html( $m( - (float) $r['amount'] ) ), '<span title="' . esc_attr( $date( $r['date'] ) ) . '">' . esc_html( self::relative( (string) $r['date'] ) ) . '</span>' ), (array) $v['refunds'] ),
			array( 'yes-alt', __( 'No refunds yet.', 'subtleforms-license-server' ) )
		);
		self::list_card(
			__( 'Recent admin changes', 'subtleforms-license-server' ),
			array( __( 'Who', 'subtleforms-license-server' ), __( 'License', 'subtleforms-license-server' ), __( 'Change', 'subtleforms-license-server' ), __( 'When', 'subtleforms-license-server' ) ),
			array_map(
				static fn( $r ) => array(
					esc_html( (string) $r['user'] ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=sfls&edit=' . (int) $r['license_id'] ) ) . '">#' . (int) $r['license_id'] . '</a>',
					'<span class="sfls-dtable__change" title="' . esc_attr( $r['field'] . ': ' . $r['old_value'] . ' → ' . $r['new_value'] ) . '">' . esc_html( $r['field'] . ': ' . $r['old_value'] . ' → ' . $r['new_value'] ) . '</span>',
					'<span title="' . esc_attr( $date( $r['created_at'] ) ) . '">' . esc_html( self::relative( (string) $r['created_at'] ) ) . '</span>',
				),
				(array) $v['audit']
			),
			array( 'edit', __( 'No changes logged yet.', 'subtleforms-license-server' ) )
		);
		echo '</div></div></div>';
	}
}
