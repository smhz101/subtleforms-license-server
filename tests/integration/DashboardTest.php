<?php
/**
 * Dashboard screen: states, minus sign, empty values, sync button, capability.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Dashboard page.
 */
class SFLS_Dashboard_Test extends SFLS_Test_Case {

	/**
	 * Fake view-model: the owner's case, one refunded order.
	 *
	 * @param bool $estimated Whether fees and earnings are estimated.
	 */
	private function owner_data( bool $estimated = false ): array {
		$fig = static fn( $a, $c, $e = false ) => array(
			'amount'    => $a,
			'count'     => $c,
			'estimated' => $e,
		);
		$k   = static fn( $v, $p, $f, $x = array() ) => array_merge(
			array(
				'value'    => $v,
				'previous' => $p,
				'format'   => $f,
			),
			$x
		);
		$mo  = array_fill( 0, 12, 0.0 );
		$sp  = $mo;
		$sp[5] = -2.95;
		$mo[11] = -2.95;
		return array(
			'generated'   => time(),
			'kpis'        => array(
				'earnings'     => $k( -2.95, 0.0, 'money', array( 'count' => 1, 'estimated' => $estimated ) ),
				'net_revenue'  => $k( 0.0, 0.0, 'money', array( 'count' => 1 ) ),
				'mrr'          => $k( 0.0, 0.0, 'money' ),
				'arr'          => $k( 0.0, 0.0, 'money' ),
				'active'       => $k( 0, 0, 'number' ),
				'paying'       => $k( 0, null, 'number' ),
				'renewal_rate' => $k( null, null, 'percent', array( 'n' => 0 ) ),
				'churn'        => $k( null, null, 'percent', array( 'n' => 0 ) ),
				'refund_rate'  => $k( null, null, 'percent', array( 'n' => 0 ) ),
			),
			'money'       => array(
				'gross'    => $fig( 29.0, 1 ),
				'refunds'  => $fig( 29.0, 1 ),
				'tax'      => $fig( 0.0, 1 ),
				'fees'     => $fig( 2.95, 1, $estimated ),
				'earnings' => $fig( -2.95, 1, $estimated ),
			),
			'spark'       => array(
				'labels' => array_map( 'strval', array_keys( $sp ) ),
				'data'   => $sp,
			),
			'months'      => array(
				'labels'   => array_map( 'strval', array_keys( $mo ) ),
				'gross'    => $mo,
				'earnings' => $mo,
			),
			'licenses'    => array(
				'labels'  => array_map( 'strval', array_keys( $mo ) ),
				'new'     => $mo,
				'churned' => $mo,
			),
			'mix'         => array(),
			'projections' => array(
				30 => array( 'count' => 0, 'amount' => 0.0 ),
				60 => array( 'count' => 0, 'amount' => 0.0 ),
				90 => array( 'count' => 0, 'amount' => 0.0 ),
			),
			'due'         => array(),
			'failed'      => array(),
			'refunds'     => array(),
			'audit'       => array(),
		);
	}

	/**
	 * Render the page with a view-model.
	 *
	 * @param array $data View-model.
	 */
	private function render( array $data ): string {
		wp_set_current_user( 1 );
		$this->filter( 'wc_get_price_decimals', static fn() => 2 );
		$this->filter( 'sfls_reports_capability', static fn() => 'manage_options', 20 );
		$this->filter( 'sfls_dashboard_data', static fn( $d ) => array_merge( $d, $data ) );
		ob_start();
		( new SFLS_Dashboard() )->page();
		return (string) ob_get_clean();
	}

	/**
	 * Negative earnings read with a minus sign and the negative class; null
	 * rates are dashes; no Sync button without estimated figures.
	 */
	public function test_owner_state(): void {
		$html = $this->render( $this->owner_data() );
		$this->assertStringContainsString( 'sfls-hero__value is-negative', $html );
		$this->assertMatchesRegularExpression( '/is-negative"[^>]*>−\p{Sc}2\.95</u', $html );
		$this->assertStringContainsString( 'Not enough data yet', $html );
		$this->assertStringContainsString( '<div class="sfls-kpi__value is-empty"', $html );
		$this->assertStringNotContainsString( 'Sync Paddle figures', $html );
		$this->assertStringNotContainsString( 'Notice:', $html );
		$this->assertStringNotContainsString( 'Warning:', $html );
		$this->assertStringNotContainsString( 'Deprecated:', $html );
	}

	/**
	 * Estimated figures show the notice and the Sync button.
	 */
	public function test_sync_button_when_estimated(): void {
		$html = $this->render( $this->owner_data( true ) );
		$this->assertStringContainsString( 'Sync Paddle figures', $html );
		$this->assertStringContainsString( 'sfls-dash-note', $html );
		$this->assertStringContainsString( 'value="sfls_finance_sync"', $html );
	}

	/**
	 * The real view-model builds without notices (no fixture, whatever the DB holds).
	 */
	public function test_real_data_renders(): void {
		wp_set_current_user( 1 );
		$this->filter( 'sfls_reports_capability', static fn() => 'manage_options', 20 );
		ob_start();
		( new SFLS_Dashboard() )->page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'sfls-hero', $html );
		$this->assertStringNotContainsString( 'Notice:', $html );
		$this->assertStringNotContainsString( 'Warning:', $html );
	}

	/**
	 * Money text: true minus, compact thousands.
	 */
	public function test_money_text(): void {
		$this->assertStringStartsWith( '−', SFLS_Dashboard::money_text( -2.95 ) );
		$this->assertStringEndsWith( 'k', SFLS_Dashboard::money_text( 12400.0, true ) );
		$this->assertStringNotContainsString( '−', SFLS_Dashboard::money_text( -0.001 ) );
	}

	/**
	 * Without the capability the page dies.
	 */
	public function test_capability(): void {
		$this->filter(
			'wp_die_handler',
			static fn() => static function () {
				throw new RuntimeException( 'die' );
			}
		);
		wp_set_current_user( 0 );
		$this->expectException( RuntimeException::class );
		( new SFLS_Dashboard() )->page();
	}
}
