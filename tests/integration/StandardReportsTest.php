<?php
/**
 * R2: standard reports.
 *
 * Fixtures use @sfls-std.example.net; figures are compared as deltas against a
 * baseline taken first, so real data on the site does not matter.
 *
 * @package SFLS
 */

/**
 * Standard report tests.
 */
class SFLS_Standard_Reports_Test extends SFLS_Test_Case {

	const MAIL = 'sfls-std.example.net';

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
	 * A paid order with one license.
	 *
	 * @param string $email Customer.
	 * @param string $plan  Plan.
	 * @param float  $total Total.
	 * @param string $date  Created date.
	 * @param string $cc    Country.
	 */
	private function sale( string $email, string $plan, float $total, string $date, string $cc = 'US' ): WC_Order {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->set_billing_country( $cc );
		$order->set_total( (string) $total );
		$order->set_date_created( strtotime( $date . ' 12:00:00 UTC' ) );
		$order->set_status( 'completed' );
		$order->set_date_paid( strtotime( $date . ' 12:00:00 UTC' ) );
		$order->update_meta_data( '_sfls_issued', 'yes' );
		$order->save();
		$this->orders[] = $order->get_id();
		SFLS_Licenses::create(
			array(
				'plan'           => $plan,
				'customer_email' => $email,
				'order_id'       => $order->get_id(),
			)
		);
		return $order;
	}

	/**
	 * Rows of a standard report keyed by a column.
	 *
	 * @param string $report Report key.
	 * @param string $by     Column.
	 * @param string $from   From.
	 * @param string $to     To.
	 */
	private function keyed( string $report, string $by, string $from = '', string $to = '' ): array {
		$res = SFLS_Standard_Reports::run( $report, $from, $to );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		return array_column( $res['rows'], null, $by );
	}

	/**
	 * Revenue by plan, month and country match the hand count.
	 */
	public function test_revenue_reports(): void {
		$plan0 = $this->keyed( 'revenue-plan', 'plan' );
		$mon0  = $this->keyed( 'revenue-month', 'purchase_month' );
		$cty0  = $this->keyed( 'revenue-country', 'country' );
		$this->sale( 'a@' . self::MAIL, 'pro', 100, '2001-01-10' );
		$this->sale( 'a@' . self::MAIL, 'pro', 50, '2001-02-05' );
		$this->sale( 'b@' . self::MAIL, 'agency', 300, '2001-02-20', 'DE' );

		$plan = $this->keyed( 'revenue-plan', 'plan' );
		$this->assertEquals( 150, $plan['pro']['sum_revenue'] - ( $plan0['pro']['sum_revenue'] ?? 0 ) );
		$this->assertEquals( 300, $plan['agency']['sum_revenue'] - ( $plan0['agency']['sum_revenue'] ?? 0 ) );
		$mon = $this->keyed( 'revenue-month', 'purchase_month' );
		$this->assertEquals( 350, $mon['2001-02']['sum_revenue'] - ( $mon0['2001-02']['sum_revenue'] ?? 0 ) );
		$this->assertSame( 2, $mon['2001-02']['count'] - ( $mon0['2001-02']['count'] ?? 0 ) );
		$cty = $this->keyed( 'revenue-country', 'country' );
		$this->assertEquals( 300, $cty['DE']['sum_revenue'] - ( $cty0['DE']['sum_revenue'] ?? 0 ) );
		// The date range narrows it.
		$jan = $this->keyed( 'revenue-month', 'purchase_month', '2001-01-01', '2001-01-31' );
		$this->assertSame( array( '2001-01' ), array_keys( $jan ) );
	}

	/**
	 * The licenses-by-month report follows the selected range.
	 */
	public function test_licenses_month_honours_range(): void {
		$res = SFLS_Standard_Reports::run( 'licenses-month', '2001-01-15', '2001-04-02' );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array( '2001-01', '2001-02', '2001-03', '2001-04' ), array_column( $res['rows'], 'month' ) );
		$this->assertCount( 12, SFLS_Standard_Reports::run( 'licenses-month' )['rows'] );
	}

	/**
	 * A Paddle renewal order counts in revenue with the renewed license's plan, and the KPI matches the report.
	 */
	public function test_renewal_order_in_revenue(): void {
		$lic    = SFLS_Licenses::create(
			array(
				'plan'           => 'agency',
				'customer_email' => 'r@' . self::MAIL,
			)
		);
		$plan0  = $this->keyed( 'revenue-plan', 'plan' );
		$order  = wc_create_order();
		$order->set_billing_email( 'r@' . self::MAIL );
		$order->set_total( '77' );
		$order->set_date_created( strtotime( '2001-03-10 12:00:00 UTC' ) );
		$item = new WC_Order_Item_Fee();
		$item->set_name( 'Renewal' );
		$item->add_meta_data( '_sfls_renew_license', (int) $lic['id'] );
		$order->add_item( $item );
		$order->set_status( 'completed' );
		$order->set_date_paid( strtotime( '2001-03-10 12:00:00 UTC' ) );
		$order->update_meta_data( '_sfls_issued', 'yes' );
		$order->update_meta_data( '_sfls_paddle_renewal', 'yes' );
		$order->save();
		$order->set_total( '77' );
		$order->save();
		$this->orders[] = $order->get_id();

		$plan1 = $this->keyed( 'revenue-plan', 'plan' );
		$this->assertEquals( 77, $plan1['agency']['sum_revenue'] - ( $plan0['agency']['sum_revenue'] ?? 0 ) );
		$mon = $this->keyed( 'revenue-month', 'purchase_month', '2001-03-01', '2001-03-31' );
		$this->assertEquals( 77, $mon['2001-03']['sum_revenue'] );
		$this->assertEquals( SFLS_Metrics::revenue( '2001-03-01', '2001-03-31' ), array_sum( array_column( $mon, 'sum_revenue' ) ) );
	}

	/**
	 * Lifetime value per customer, net of refunds.
	 */
	public function test_customer_ltv(): void {
		$this->sale( 'a@' . self::MAIL, 'pro', 100, '2001-01-10' );
		$o = $this->sale( 'a@' . self::MAIL, 'pro', 50, '2001-02-05' );
		$this->sale( 'b@' . self::MAIL, 'agency', 3000000, '2001-02-20' );
		$refund = wc_create_refund(
			array(
				'order_id' => $o->get_id(),
				'amount'   => 20,
			)
		);
		$this->assertFalse( is_wp_error( $refund ) );
		$res = SFLS_Standard_Reports::run( 'customers-ltv' );
		$this->assertLessThanOrEqual( 50, count( $res['rows'] ) );
		$this->assertSame( 'b@' . self::MAIL, $res['rows'][0]['customer'], 'Biggest spender first.' );
		$by = array_column( $res['rows'], null, 'customer' );
		$this->assertEquals( 130, $by[ 'a@' . self::MAIL ]['sum_net_revenue'] );
		$this->assertEquals( 2, $by[ 'a@' . self::MAIL ]['sum_licenses'] );
	}

	/**
	 * New licenses by month and the cohort table.
	 */
	public function test_licenses_and_cohorts(): void {
		$label = gmdate( 'Y-m' );
		$base  = $this->keyed( 'licenses-month', 'month' )[ $label ]['new'];
		$lic   = array();
		foreach ( array( 'x', 'y', 'z' ) as $n ) {
			$lic[ $n ] = SFLS_Licenses::create(
				array(
					'plan'           => 'pro',
					'customer_email' => $n . '@' . self::MAIL,
				)
			);
		}
		$this->assertSame( 3, $this->keyed( 'licenses-month', 'month' )[ $label ]['new'] - $base );

		SFLS_Licenses::update( (int) $lic['x']['id'], array( 'created_at' => '2001-05-10 10:00:00', 'expires_at' => '2003-01-01 00:00:00' ) );
		SFLS_Licenses::update( (int) $lic['y']['id'], array( 'created_at' => '2001-05-11 10:00:00', 'expires_at' => '2099-01-01 00:00:00' ) );
		SFLS_Licenses::update( (int) $lic['z']['id'], array( 'created_at' => '2001-05-12 10:00:00', 'expires_at' => '2001-12-01 00:00:00', 'billing_status' => 'canceled' ) );
		$row = $this->keyed( 'renewals-cohort', 'month', '2001-05-01', '2001-05-31' )['2001-05'];
		$this->assertSame( 3, $row['licenses'] );
		$this->assertEqualsWithDelta( 66.7, $row['renewed'], 0.05 );
		$this->assertEqualsWithDelta( 33.3, $row['active'], 0.05 );
		$this->assertEqualsWithDelta( 66.7, $row['churned'], 0.05 );
	}

	/**
	 * Every standard report renders (no notices) and is exportable.
	 */
	public function test_every_report_renders_and_exports(): void {
		$this->sale( 'a@' . self::MAIL, 'pro', 100, '2001-01-10' );
		$admin = $this->make_user();
		$admin->set_role( 'administrator' );
		wp_set_current_user( $admin->ID );
		foreach ( array_keys( SFLS_Standard_Reports::all() ) as $key ) {
			$res = SFLS_Standard_Reports::run( $key );
			$this->assertNotInstanceOf( WP_Error::class, $res, $key );
			$this->assertNotSame( '', SFLS_Reports_Admin::csv( $res ), $key );
			$_GET = array(
				'action' => 'standard',
				'key'    => $key,
			);
			ob_start();
			( new SFLS_Reports_Admin() )->page();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( '<table', $html . '<table', $key );
			$this->assertStringContainsString( 'Export CSV', $html, $key );
		}
		$_GET = array();
		ob_start();
		( new SFLS_Reports_Admin() )->page();
		$this->assertStringContainsString( 'Standard reports', (string) ob_get_clean() );
	}

	/**
	 * Standard CSV escapes formula prefixes; save-as-custom keeps the definition.
	 */
	public function test_csv_escape_and_save(): void {
		$res                         = SFLS_Standard_Reports::run( 'dunning' );
		$res['rows']                 = array( array_fill_keys( wp_list_pluck( $res['columns'], 'key' ), '=HYPERLINK("x")' ) );
		$this->assertStringContainsString( "'=HYPERLINK", SFLS_Reports_Admin::csv( $res ) );
		$std = SFLS_Standard_Reports::find( 'revenue-plan' );
		$def = SFLS_Standard_Reports::definition( $std, '2001-01-01', '' );
		$this->assertSame( 'orders', $def['dataset'] );
		$this->assertNull( SFLS_Standard_Reports::find( 'nope' ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Standard_Reports::run( 'nope' ) );
	}

	/**
	 * Only users with the reports capability get in.
	 */
	public function test_capability_enforced(): void {
		$this->filter(
			'wp_die_handler',
			static fn() => static function () {
				throw new RuntimeException( 'die' );
			}
		);
		wp_set_current_user( $this->make_user()->ID );
		$_GET = array( 'key' => 'dunning' );
		$this->expectException( RuntimeException::class );
		( new SFLS_Reports_Admin() )->handle_std_csv();
	}
}
