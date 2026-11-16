<?php
/**
 * R3: the custom report query builder.
 *
 * Fixture licenses use the @sfls-reports.example.net domain and the billing
 * status "rpt-test", so the numbers hold on a site with real data.
 *
 * @package SFLS
 */

/**
 * Report query tests.
 */
class SFLS_Report_Query_Test extends SFLS_Test_Case {

	const MAIL = 'sfls-reports.example.net';

	/**
	 * Remove the fixtures.
	 */
	protected function tearDown(): void {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sfls_licenses WHERE customer_email LIKE %s", '%@' . self::MAIL ) ) as $id ) {
			SFLS_Licenses::delete( (int) $id );
		}
		parent::tearDown();
	}

	/**
	 * One fixture license.
	 *
	 * @param string $plan    Plan.
	 * @param int    $sites   Sites allowed.
	 * @param string $created Created at.
	 */
	private function fixture( string $plan, int $sites, string $created ): array {
		$lic = SFLS_Licenses::create(
			array(
				'plan'           => $plan,
				'sites_allowed'  => $sites,
				'customer_email' => 'l' . wp_generate_password( 5, false ) . '@' . self::MAIL,
			)
		);
		SFLS_Licenses::update(
			(int) $lic['id'],
			array(
				'billing_status' => 'rpt-test',
				'created_at'     => $created,
			)
		);
		return $lic;
	}

	/**
	 * Three licenses: pro 3 sites (Jan), pro 5 sites (Jan), agency 25 sites (Feb).
	 */
	private function seed(): void {
		$this->fixture( 'pro', 3, '2026-01-15 10:00:00' );
		$this->fixture( 'pro', 5, '2026-01-20 10:00:00' );
		$this->fixture( 'agency', 25, '2026-02-03 10:00:00' );
	}

	/**
	 * Definition on the fixture.
	 *
	 * @param array $extra Overrides.
	 */
	private function def( array $extra = array() ): array {
		return $extra + array(
			'dataset' => 'licenses',
			'filters' => array(
				array(
					'field' => 'billing_status',
					'op'    => 'eq',
					'value' => 'rpt-test',
				),
			),
		);
	}

	/**
	 * Count and sum by plan equal the hand count.
	 */
	public function test_group_by_plan_count_and_sum(): void {
		$this->seed();
		$res = SFLS_Report_Query::run(
			$this->def(
				array(
					'group_by' => array( 'plan' ),
					'measures' => array(
						array( 'fn' => 'count' ),
						array(
							'fn'    => 'sum',
							'field' => 'sites_allowed',
						),
						array(
							'fn'    => 'avg',
							'field' => 'sites_allowed',
						),
					),
				)
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array( 'plan', 'count', 'sum_sites_allowed', 'avg_sites_allowed' ), wp_list_pluck( $res['columns'], 'key' ) );
		$by = array_column( $res['rows'], null, 'plan' );
		$this->assertSame( 2, $res['total_rows'] );
		$this->assertSame( 2, $by['pro']['count'] );
		$this->assertEquals( 8, $by['pro']['sum_sites_allowed'] );
		$this->assertEquals( 4, $by['pro']['avg_sites_allowed'] );
		$this->assertSame( 1, $by['agency']['count'] );
		$this->assertEquals( 25, $by['agency']['sum_sites_allowed'] );
	}

	/**
	 * Dates group by month.
	 */
	public function test_group_by_month_and_two_levels(): void {
		$this->seed();
		$res = SFLS_Report_Query::run( $this->def( array( 'group_by' => array( 'purchase_month' ) ) ) );
		$this->assertSame(
			array(
				array(
					'purchase_month' => '2026-01',
					'count'          => 2,
				),
				array(
					'purchase_month' => '2026-02',
					'count'          => 1,
				),
			),
			$res['rows']
		);
		$two = SFLS_Report_Query::run( $this->def( array( 'group_by' => array( 'purchase_month', 'plan' ) ) ) );
		$this->assertSame( 2, $two['total_rows'] );
		$none = SFLS_Report_Query::run( $this->def( array( 'group_by' => array( 'plan', 'status', 'billing' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $none );
	}

	/**
	 * Date range, filters and sort.
	 */
	public function test_range_filters_and_sort(): void {
		$this->seed();
		$res = SFLS_Report_Query::run( $this->def( array( 'from' => '2026-01-16', 'to' => '2026-12-31' ) ) );
		$this->assertSame( 2, $res['rows'][0]['count'] );
		$res = SFLS_Report_Query::run(
			array(
				'dataset' => 'licenses',
				'filters' => array(
					array(
						'field' => 'billing_status',
						'op'    => 'in',
						'value' => array( 'rpt-test', 'other' ),
					),
					array(
						'field' => 'sites_allowed',
						'op'    => 'gt',
						'value' => 4,
					),
					array(
						'field' => 'purchase_month',
						'op'    => 'between',
						'value' => array( '2026-01-01', '2026-01-31' ),
					),
				),
			)
		);
		$this->assertSame( 1, $res['rows'][0]['count'] );
		$res = SFLS_Report_Query::run( $this->def( array( 'group_by' => array( 'plan' ), 'sort' => 'count:desc' ) ) );
		$this->assertSame( 'pro', $res['rows'][0]['plan'] );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( $this->def( array( 'sort' => 'l.plan; DROP TABLE x' ) ) ) );
	}

	/**
	 * Limit caps groups, never more than 10000.
	 */
	public function test_limit_cap(): void {
		$this->seed();
		$res = SFLS_Report_Query::run( $this->def( array( 'group_by' => array( 'plan' ), 'limit' => 1 ) ) );
		$this->assertCount( 1, $res['rows'] );
		$this->assertSame( 2, $res['total_rows'] );
		$this->assertSame( 10000, SFLS_Report_Query::MAX_GROUPS );
		$res = SFLS_Report_Query::run( $this->def( array( 'limit' => 99999999 ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Unknown things are rejected.
	 */
	public function test_rejects_unknown_input(): void {
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'users' ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses; DROP TABLE x' ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'group_by' => array( 'customer_email' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'group_by' => array( 'plan) UNION SELECT 1 -- ' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'measures' => array( array( 'fn' => 'sum', 'field' => 'plan' ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'measures' => array( array( 'fn' => 'max', 'field' => 'sites_allowed' ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'filters' => array( array( 'field' => 'plan', 'op' => 'LIKE', 'value' => 'x' ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'filters' => array( array( 'field' => 'plan', 'op' => 'gt', 'value' => 'x' ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'filters' => array( array( 'field' => 'sites_allowed', 'op' => 'eq', 'value' => '1 OR 1=1' ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'filters' => array( array( 'field' => 'purchase_month', 'op' => 'before', 'value' => "2026-01-01' OR '1'='1" ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'from' => "2026-01-01' OR 1=1 -- " ) ) );
	}

	/**
	 * A hostile string value is only ever data.
	 */
	public function test_injection_in_value_is_data(): void {
		global $wpdb;
		$this->seed();
		$evil = "x' OR '1'='1' -- %s %d";
		$res  = SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'filters' => array( array( 'field' => 'plan', 'op' => 'eq', 'value' => $evil ) ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 0, $res['rows'][0]['count'] );
		$res = SFLS_Report_Query::run( array( 'dataset' => 'licenses', 'filters' => array( array( 'field' => 'plan', 'op' => 'in', 'value' => array( $evil, 'pro' ) ) ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertGreaterThanOrEqual( 2, $res['rows'][0]['count'] );
		$this->assertSame( '', (string) $wpdb->last_error );
	}

	/**
	 * Every dataset runs and activations group by Pro version.
	 */
	public function test_every_dataset_and_activations(): void {
		$lic = $this->fixture( 'pro', 3, '2026-01-15 10:00:00' );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'a.example.com', 'rpt-9.9.9' );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'b.example.com', 'rpt-9.9.9' );
		$res = SFLS_Report_Query::run(
			array(
				'dataset'  => 'activations',
				'group_by' => array( 'pro_version' ),
				'filters'  => array( array( 'field' => 'pro_version', 'op' => 'eq', 'value' => 'rpt-9.9.9' ) ),
			)
		);
		$this->assertSame( 2, $res['rows'][0]['count'] );
		$this->assertSame( 2, (int) SFLS_Report_Query::run( $this->def( array( 'measures' => array( array( 'fn' => 'sum', 'field' => 'sites_used' ) ) ) ) )['rows'][0]['sum_sites_used'] );
		foreach ( array_keys( SFLS_Report_Query::datasets() ) as $ds ) {
			$this->assertNotInstanceOf( WP_Error::class, SFLS_Report_Query::run( array( 'dataset' => $ds, 'group_by' => array_slice( array_keys( array_filter( SFLS_Report_Query::fields( $ds ), fn( $f ) => $f['groupable'] ) ), 0, 2 ) ) ), $ds );
		}
	}

	/**
	 * Orders and refunds datasets: revenue, refunded amount, country.
	 */
	public function test_orders_and_refunds(): void {
		$product = $this->make_license_product( 'pro', 12, '1234.57' );
		$order   = $this->make_order( array( $product ) );
		$total   = (float) $order->get_total();
		$order->update_meta_data( '_sfls_issued', 'yes' );
		$order->save();
		$this->fixture( 'pro', 1, '2026-01-15 10:00:00' );
		$lic = $this->make_license( array( 'order_id' => $order->get_id() ) );
		SFLS_Licenses::update( (int) $lic['id'], array( 'customer_email' => 'o@' . self::MAIL ) );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '101',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $refund );
		$only = array( array( 'field' => 'revenue', 'op' => 'eq', 'value' => (string) $total ) );
		$res  = SFLS_Report_Query::run(
			array(
				'dataset'  => 'orders',
				'group_by' => array( 'country' ),
				'filters'  => $only,
				'measures' => array(
					array( 'fn' => 'sum', 'field' => 'revenue' ),
					array( 'fn' => 'sum', 'field' => 'refunded' ),
				),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$row = $res['rows'][0];
		$this->assertSame( 'US', $row['country'] );
		$this->assertEqualsWithDelta( $total, $row['sum_revenue'], 0.001 );
		$this->assertEqualsWithDelta( 101, $row['sum_refunded'], 0.001 );

		$res = SFLS_Report_Query::run(
			array(
				'dataset'  => 'refunds',
				'filters'  => array( array( 'field' => 'refunded', 'op' => 'eq', 'value' => '101' ) ),
				'measures' => array( array( 'fn' => 'sum', 'field' => 'refunded' ) ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertGreaterThanOrEqual( 101, $res['rows'][0]['sum_refunded'] );
	}

	/**
	 * Schema 14: reports table and the new indexes.
	 */
	public function test_schema_14(): void {
		global $wpdb;
		$this->assertSame( '14', SFLS_DB::SCHEMA_VERSION );
		SFLS_DB::install();
		$this->assertSame( SFLS_DB::reports(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', SFLS_DB::reports() ) ) );
		$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . SFLS_DB::reports() );
		foreach ( array( 'id', 'name', 'user_id', 'shared', 'definition', 'schedule', 'recipients', 'created_at', 'updated_at' ) as $c ) {
			$this->assertContains( $c, $cols );
		}
		$idx = $wpdb->get_col( 'SHOW INDEX FROM ' . SFLS_DB::licenses(), 2 );
		foreach ( array( 'created_at', 'expires_at', 'plan' ) as $k ) {
			$this->assertContains( $k, $idx );
		}
		$this->assertContains( 'last_seen_at', $wpdb->get_col( 'SHOW INDEX FROM ' . SFLS_DB::activations(), 2 ) );
	}
}
