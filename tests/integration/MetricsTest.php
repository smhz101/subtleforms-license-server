<?php
/**
 * KPI dashboard: metrics against a hand-computed fixture, cache, screen.
 *
 * All fixture rows live in March 2001 with @sfls-metrics.example.net
 * addresses, so real data never falls inside the ranges.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals

/**
 * Metrics and dashboard.
 */
class SFLS_Metrics_Test extends SFLS_Test_Case {

	const MAIL = '@sfls-metrics.example.net';
	const FROM = '2001-03-01 00:00:00';
	const TO   = '2001-03-31 23:59:59';

	/**
	 * Licenses by name.
	 *
	 * @var int[]
	 */
	private array $lic = array();

	/**
	 * Baseline of the current-state KPIs before the fixture.
	 *
	 * @var array
	 */
	private array $base = array();

	/**
	 * Fixture.
	 */
	protected function setUp(): void {
		parent::setUp();
		SFLS_Metrics::flush();
		$this->base = array(
			'mrr'     => SFLS_Metrics::mrr(),
			'dunning' => SFLS_Metrics::dunning(),
		);
		$cur        = get_woocommerce_currency();
		$this->lic( 'A1', 'starter', '2001-01-10', '2002-01-10' );
		$this->lic( 'A2', 'pro', '2001-02-10', '2002-02-10', array( 'billing' => 'paddle', 'billing_status' => 'active', 'billing_amount' => '24000', 'billing_currency' => $cur, 'paddle_subscription_id' => 'sub_sfls_metrics_a2' ) );
		$this->lic( 'A3', 'pro', '2001-03-05', '2002-03-05' );
		$this->lic( 'A4', 'agency', '2001-03-10', '2002-03-10' );
		$this->lic( 'A5', 'pro', '2001-03-15', null );
		$this->lic( 'A6', 'starter', '2000-06-01', '2001-03-20' );
		$this->lic( 'A7', 'pro', '2000-03-25', '2002-03-25' );
		$this->lic( 'A8', 'pro', '2000-01-01', '2001-03-28' );
		$this->lic( 'A9', 'pro', '2001-01-20', '2002-01-20', array( 'billing' => 'paddle', 'billing_status' => 'canceled', 'updated_at' => '2001-03-12 10:00:00' ) );
		$this->lic( 'A10', 'starter', '2001-03-02', '2002-03-02', array( 'billing' => 'paddle', 'billing_status' => 'past_due', 'billing_amount' => '9900', 'billing_currency' => $cur, 'paddle_subscription_id' => 'sub_sfls_metrics_a10' ) );

		// Sites: A3 two live sites, one local; A1 and A4 seen earlier.
		$this->site( 'A3', 'one', '2001-03-20 10:00:00', false );
		$this->site( 'A3', 'two', '2001-03-10 10:00:00', false );
		$this->site( 'A3', 'three', '2001-03-20 10:00:00', true );
		$this->site( 'A1', 'four', '2001-02-20 10:00:00', false );
		$this->site( 'A4', 'five', '2001-02-01 10:00:00', false );

		$pro     = $this->make_license_product( 'pro', 12, '100' );
		$starter = $this->make_license_product( 'starter', 12, '50' );
		$this->order( $pro, 100, '2001-03-05' );
		$this->order( $pro, 200, '2001-03-12', array( '_sfls_renew_license' => $this->lic['A7'] ) );
		$o3 = $this->order( $starter, 50, '2001-03-20' );
		$o4 = $this->order( $starter, 150, '2001-03-25' );
		$this->order( $pro, 300, '2001-02-10' );
		$this->order( $this->make_physical_product(), 999, '2001-03-15', array(), false );
		$this->refund( $o3, 20, '2001-03-22' );
		$this->refund( $o4, 150, '2001-03-26' );
		SFLS_Metrics::flush();
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sfls_licenses WHERE customer_email LIKE %s", '%' . $wpdb->esc_like( self::MAIL ) ) );
		foreach ( $ids as $id ) {
			SFLS_Licenses::delete( (int) $id );
		}
		SFLS_Metrics::flush();
		parent::tearDown();
	}

	/**
	 * Create a license on fixed dates.
	 *
	 * @param string      $name    Key in $this->lic.
	 * @param string      $plan    Plan.
	 * @param string      $created Date.
	 * @param string|null $expires Date or null (lifetime).
	 * @param array       $extra   Extra columns.
	 */
	private function lic( string $name, string $plan, string $created, ?string $expires, array $extra = array() ): void {
		global $wpdb;
		$updated = $extra['updated_at'] ?? $created . ' 00:00:00';
		unset( $extra['updated_at'] );
		$row = SFLS_Licenses::create(
			array(
				'plan'           => $plan,
				'customer_email' => strtolower( $name ) . self::MAIL,
				'expires_at'     => $expires ? $expires . ' 00:00:00' : null,
			) + $extra
		);
		$wpdb->update( SFLS_DB::licenses(), array( 'created_at' => $created . ' 00:00:00', 'updated_at' => $updated ), array( 'id' => $row['id'] ) );
		$this->lic[ $name ] = (int) $row['id'];
	}

	/**
	 * Create an activation last seen at a time.
	 *
	 * @param string $name  License name.
	 * @param string $host  Host label.
	 * @param string $seen  Datetime.
	 * @param bool   $local Local site.
	 */
	private function site( string $name, string $host, string $seen, bool $local ): void {
		global $wpdb;
		SFLS_Licenses::add_activation( $this->lic[ $name ], $host . '.sfls-metrics.example.net', '9.0.0' );
		$wpdb->update( SFLS_DB::activations(), array( 'last_seen_at' => $seen, 'is_local' => $local ? 1 : 0 ), array( 'license_id' => $this->lic[ $name ], 'domain' => $host . '.sfls-metrics.example.net' ) );
	}

	/**
	 * A paid order.
	 *
	 * @param WC_Product $product Product.
	 * @param float      $total   Total.
	 * @param string     $paid    Paid date.
	 * @param array      $meta    Item meta.
	 * @param bool       $issued  Flag as a license order.
	 */
	private function order( WC_Product $product, float $total, string $paid, array $meta = array(), bool $issued = true ): WC_Order {
		$order = $this->make_order( array( $product ), null, array( $meta ) );
		if ( $issued ) {
			$order->update_meta_data( '_sfls_issued', 'yes' );
		}
		$order->set_total( (string) $total );
		$order->set_date_paid( strtotime( $paid . ' 12:00:00 UTC' ) );
		$order->set_status( 'completed' );
		$order->save();
		return $order;
	}

	/**
	 * Refund an order on a date.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 * @param string   $date   Date.
	 */
	private function refund( WC_Order $order, float $amount, string $date ): void {
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $amount,
				'reason'   => 'metrics test',
			)
		);
		$this->assertNotWPError( $refund );
		$refund->set_date_created( strtotime( $date . ' 12:00:00 UTC' ) );
		$refund->save();
	}

	/**
	 * Assert WP_Error is not returned.
	 *
	 * @param mixed $value Value.
	 */
	private function assertNotWPError( $value ): void {
		$this->assertFalse( is_wp_error( $value ), is_wp_error( $value ) ? $value->get_error_message() : '' );
	}

	/**
	 * Every KPI equals the hand-computed value, with the previous period.
	 */
	public function test_kpis_match_the_fixture(): void {
		$k = SFLS_Metrics::kpis( self::FROM, self::TO );

		$this->assertSame( 8, $k['active']['value'] );
		$this->assertSame( 6, $k['active']['previous'] );
		$this->assertSame( 4, $k['new']['value'] );
		$this->assertSame( 1, $k['new']['previous'] );
		$this->assertEqualsWithDelta( 500.0, $k['revenue']['value'], 0.001 );
		$this->assertEqualsWithDelta( 300.0, $k['revenue']['previous'], 0.001 );
		$this->assertEqualsWithDelta( 330.0, $k['net_revenue']['value'], 0.001 );
		$this->assertEqualsWithDelta( 300.0, $k['net_revenue']['previous'], 0.001 );
		$this->assertEqualsWithDelta( 33.3, $k['renewal_rate']['value'], 0.001 );
		$this->assertNull( $k['renewal_rate']['previous'] );
		$this->assertEqualsWithDelta( 50.0, $k['churn']['value'], 0.001 );
		$this->assertEqualsWithDelta( 0.0, $k['churn']['previous'], 0.001 );
		$this->assertEqualsWithDelta( 50.0, $k['refund_rate']['value'], 0.001 );
		$this->assertEqualsWithDelta( 0.0, $k['refund_rate']['previous'], 0.001 );
		$this->assertSame( 2, $k['active_sites']['value'] );
		$this->assertSame( 2, $k['active_sites']['previous'] );

		// MRR and dunning describe now: compare with the site before the fixture.
		$this->assertEqualsWithDelta( $this->base['mrr'] + ( 240 + 99 ) / 12, $k['mrr']['value'], 0.01 );
		$this->assertEqualsWithDelta( 20.0, $k['mrr']['previous'], 0.01 );
		$this->assertEqualsWithDelta( $k['mrr']['value'] * 12, $k['arr']['value'], 0.02 );
		$this->assertSame( $this->base['dunning']['count'] + 1, $k['dunning_count']['value'] );
		$this->assertEqualsWithDelta( $this->base['dunning']['amount'] + 99, $k['dunning_amount']['value'], 0.001 );
		$this->assertSame( 'money', $k['mrr']['format'] );
		$this->assertSame( 'percent', $k['churn']['format'] );
	}

	/**
	 * Plan filter narrows orders and licenses.
	 */
	public function test_plan_filter(): void {
		$k = SFLS_Metrics::kpis( self::FROM, self::TO, 'starter' );
		$this->assertEqualsWithDelta( 200.0, $k['revenue']['value'], 0.001 );
		$this->assertEqualsWithDelta( 30.0, $k['net_revenue']['value'], 0.001 );
		$this->assertSame( 1, $k['new']['value'] );
		$this->assertSame( 2, $k['active']['value'] );
		$this->assertEqualsWithDelta( 100.0, $k['refund_rate']['value'], 0.001 );
	}

	/**
	 * Previous range, refund rows, series and lists.
	 */
	public function test_series_and_lists(): void {
		$this->assertSame( array( '2001-01-29 00:00:00', '2001-02-28 23:59:59' ), SFLS_Metrics::previous_range( self::FROM, self::TO ) );

		$refunds = SFLS_Metrics::refunds( self::FROM, self::TO );
		$this->assertSame( array( 150.0, 20.0 ), wp_list_pluck( $refunds, 'amount' ) );

		$rev = SFLS_Metrics::revenue_by_month( 3 );
		$this->assertCount( 3, $rev['labels'] );
		$this->assertCount( 3, $rev['net'] );
		$mix = SFLS_Metrics::plan_mix();
		$this->assertGreaterThanOrEqual( 3, $mix['pro'] ?? 0 );
		$this->assertSame( array( 30, 60, 90 ), array_keys( SFLS_Metrics::renewals_due( array( 30, 60, 90 ) ) ) );
		$this->assertIsArray( SFLS_Metrics::version_adoption() );
		$this->assertIsArray( SFLS_Metrics::latest_refunds( 10 ) );
		$this->assertIsArray( SFLS_Metrics::latest_audit( 10 ) );
		$this->assertContains( 'a10' . self::MAIL, wp_list_pluck( SFLS_Metrics::dunning_list(), 'customer_email' ) );
	}

	/**
	 * Results are cached for the request window until flushed.
	 */
	public function test_cache_and_flush(): void {
		$this->assertSame( 4, SFLS_Metrics::kpis( self::FROM, self::TO )['new']['value'] );
		$this->lic( 'A11', 'pro', '2001-03-20', '2002-03-20' );
		$this->assertSame( 4, SFLS_Metrics::kpis( self::FROM, self::TO )['new']['value'], 'Served from the cache.' );
		SFLS_Metrics::flush();
		$this->assertSame( 5, SFLS_Metrics::kpis( self::FROM, self::TO )['new']['value'] );
	}

	/**
	 * Deltas read right: arrow, percent and good or bad colour.
	 */
	public function test_delta(): void {
		$up = SFLS_Dashboard::delta( 'new', array( 'value' => 4, 'previous' => 2, 'format' => 'number' ) );
		$this->assertSame( 'good', $up['tone'] );
		$this->assertStringContainsString( '▲ 100', $up['text'] );
		$bad = SFLS_Dashboard::delta( 'churn', array( 'value' => 5.0, 'previous' => 2.0, 'format' => 'percent' ) );
		$this->assertSame( 'bad', $bad['tone'] );
		$this->assertNull( SFLS_Dashboard::delta( 'mrr', array( 'value' => 1, 'previous' => null, 'format' => 'money' ) ) );
	}

	/**
	 * The page needs the capability (filterable) and renders cleanly.
	 */
	public function test_dashboard_page(): void {
		$page = new SFLS_Dashboard();
		$this->filter(
			'wp_die_handler',
			static fn() => static function () {
				throw new RuntimeException( 'die' );
			}
		);
		wp_set_current_user( 0 );
		try {
			$page->page();
			$this->fail( 'Anonymous users must not see the dashboard.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'die', $e->getMessage() );
		}

		$editor = $this->make_user( 'editor' );
		$editor->set_role( 'editor' );
		wp_set_current_user( $editor->ID );
		try {
			$page->page();
			$this->fail( 'Editors must not see the dashboard.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'die', $e->getMessage() );
		}
		$this->filter( 'sfls_reports_capability', static fn() => 'edit_posts' );
		$this->assertSame( 'edit_posts', SFLS_Dashboard::capability() );

		wp_set_current_user( 1 );
		$this->filter( 'sfls_reports_capability', static fn() => 'manage_options', 20 );
		$_GET = array(
			'page'  => 'sfls-dashboard',
			'range' => 'custom',
			'from'  => '2001-03-01',
			'to'    => '2001-03-31',
			'plan'  => 'pro',
		);
		ob_start();
		$page->page();
		$html = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'sfls-chart', $html );
		$this->assertStringContainsString( 'sfls-kpi', $html );
		$this->assertStringContainsString( 'name="sfls_metrics_refresh"', str_replace( 'value="sfls_metrics_refresh"', 'name="sfls_metrics_refresh"', $html ) );
		$this->assertStringNotContainsString( 'Notice:', $html );
		$this->assertStringNotContainsString( 'Warning:', $html );
		$this->assertStringNotContainsString( 'Deprecated:', $html );

		// Hostile input is ignored, not echoed.
		$_GET = array( 'range' => '"><script>', 'plan' => '<b>', 'from' => 'x', 'to' => 'y' );
		$f    = SFLS_Dashboard::filters();
		$_GET = array();
		$this->assertSame( '30', $f['range'] );
		$this->assertSame( '', $f['plan'] );
	}
}
