<?php
/**
 * Every failure in a payment path leaves a trace.
 *
 * @package SFLS
 */

/**
 * Payment log.
 */
class SFLS_Payment_Log_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;

	/**
	 * Captured sfls_logged calls: [ context, message ].
	 *
	 * @var array[]
	 */
	private array $logged = array();

	/**
	 * Set up Paddle and capture the log.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->paddle();
		$this->filter(
			'sfls_logged',
			function ( $context, $message ) {
				$this->logged[] = array( $context, $message );
			},
			10,
			2
		);
	}

	/**
	 * Private note texts of an order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function notes( WC_Order $order ): string {
		return implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	/**
	 * Contexts logged so far.
	 */
	private function contexts(): array {
		return wp_list_pluck( $this->logged, 0 );
	}

	/**
	 * A Paddle order with a transaction.
	 */
	private function paddle_order(): WC_Order {
		$order = $this->make_order( array( $this->make_license_product() ), $this->make_user() );
		$order->set_payment_method( SFLS_Woo_Paddle::GATEWAY );
		$order->update_meta_data( '_sfls_paddle_txn_id', 'txn_01log' );
		$order->save();
		return $order;
	}

	/**
	 * Log line and order note, key masked, secrets dropped.
	 */
	public function test_log_masks_and_notes(): void {
		$order = $this->make_order( array( $this->make_license_product() ), $this->make_user() );
		SFLS_Log::payment( 'test', 'It broke', $order, array( 'license_key' => 'SFP-AAAAAAAA-BBBBBBBB-CCCCCCCC-12345678', 'api_key' => 'pdl_secret' ) );
		$this->assertSame( array( array( 'test', 'It broke' ) ), $this->logged );
		$this->assertStringContainsString( 'Paddle: It broke', $this->notes( $order ) );
	}

	/**
	 * A failed transaction fetch in poll().
	 */
	public function test_poll_fetch_error_is_logged(): void {
		$this->paddle_routes['GET /transactions/txn_01log'] = array( 500, array( 'error' => array( 'code' => 'internal', 'detail' => 'Paddle is down' ) ) );
		$order = $this->paddle_order();
		SFLS_Woo_Paddle_Orders::poll( $order );
		$this->assertSame( array( 'poll' ), $this->contexts() );
		$this->assertStringContainsString( 'Paddle: Could not fetch transaction txn_01log', $this->notes( $order ) );
	}

	/**
	 * A failed cancel of the open transaction of a cancelled order.
	 */
	public function test_cancel_transaction_error_is_logged(): void {
		$this->paddle_routes['PATCH /transactions/txn_01log'] = array( 400, array( 'error' => array( 'code' => 'bad', 'detail' => 'Cannot cancel' ) ) );
		$order = $this->paddle_order();
		( new SFLS_Woo_Paddle() )->cancel_open_transaction( $order->get_id() );
		$this->assertSame( array( 'cancel_transaction' ), $this->contexts() );
		$this->assertStringContainsString( 'Could not cancel transaction txn_01log', $this->notes( $order ) );
	}

	/**
	 * Customer lookup fails: logged, the checkout email is still used.
	 */
	public function test_get_customer_error_is_logged_and_falls_back(): void {
		$this->paddle_routes['GET /customers/ctm_01log'] = array( 500, array( 'error' => array( 'code' => 'internal', 'detail' => 'down' ) ) );
		$sub = $this->subscription( array( 'status' => 'active', 'customer_id' => 'ctm_01log', 'custom_data' => array( 'sfls_email' => 'fallback@' . self::DOMAIN ) ) );
		$this->webhook( 'subscription.created', $sub );
		$this->assertContains( 'get_customer', $this->contexts() );
		$lic = SFLS_Licenses::find_by_subscription( $sub['id'] );
		$this->assertSame( 'fallback@' . self::DOMAIN, $lic['customer_email'] );
	}

	/**
	 * Account creation that fails returns 0 and is logged.
	 */
	public function test_customer_user_failures_are_logged(): void {
		$this->assertSame( array( 0, false ), SFLS_Billing::customer_user( 'not-an-email' ) );
		$this->assertSame( array( 'customer_user' ), $this->contexts() );

		$this->filter( 'wp_pre_insert_user_data', static fn() => new WP_Error( 'nope', 'Blocked' ) );
		$this->filter( 'pre_user_login', static fn() => '' );
		$this->assertSame( array( 0, false ), SFLS_Billing::customer_user( 'new-' . wp_generate_password( 5, false ) . '@' . self::DOMAIN, 'New Person' ) );
		$this->assertCount( 2, $this->logged );
	}

	/**
	 * A failing $wpdb call while claiming a webhook event is logged.
	 */
	public function test_webhook_db_failure_is_logged(): void {
		global $wpdb;
		$this->filter( 'query', static fn( $q ) => str_contains( $q, 'INSERT IGNORE INTO' ) && str_contains( $q, 'sfls_paddle_events' ) ? 'SELECT * FROM sfls_no_such_table_xyz' : $q );
		$wpdb->suppress_errors( true );
		$method = new ReflectionMethod( SFLS_Paddle_REST::class, 'claim' );
		$method->setAccessible( true );
		$this->assertFalse( $method->invoke( null, array( 'event_id' => 'evt_log1', 'event_type' => 'subscription.created' ) ) );
		$wpdb->suppress_errors( false );
		$this->assertSame( array( 'webhook_claim' ), $this->contexts() );
	}
}
