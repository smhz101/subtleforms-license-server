<?php
/**
 * LS-13: linking an order to its subscription stamps the event time, so a
 * later event is not skipped as stale.
 *
 * @package SFLS
 */

/**
 * Paddle subscription link time.
 */
class SFLS_Paddle_Link_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;
	use SFLS_Woo_Paddle_Helpers;

	/**
	 * Mock and products.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->woo_paddle();
	}

	/**
	 * The repro: subscription.activated links the order; past_due happened
	 * after activated but before the link was processed, and is applied.
	 */
	public function test_event_after_the_link_event_is_applied(): void {
		$this->cart_plan( 'pro' );
		$order = $this->checkout_order( $this->email() );
		$this->pay( $order );
		$txn = $this->completed_txn( $order );
		$sub = $this->subs[ $txn['subscription_id'] ];

		$activated = gmdate( 'Y-m-d\TH:i:s.000000\Z', time() - 300 );
		$response  = $this->webhook( 'subscription.activated', $sub + array( 'transaction_id' => $txn['id'] ), array( 'occurred_at' => $activated ) );
		$this->assertSame( 'completed', $response->get_data()['action'], wp_json_encode( $response->get_data() ) );
		$lic = $this->licenses_of_order( $order->get_id() )[0];
		$this->assertSame( $sub['id'], $lic['paddle_subscription_id'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $activated ) ), $lic['billing_synced_at'], 'Stamped with the event time.' );

		$past_due = array_replace( $sub, array( 'status' => 'past_due' ) );
		$response = $this->webhook( 'subscription.past_due', $past_due, array( 'occurred_at' => gmdate( 'Y-m-d\TH:i:s.000000\Z', time() - 120 ) ) );
		$this->assertSame( 'updated', $response->get_data()['action'] );
		$this->assertSame( 'past_due', SFLS_Licenses::find( (int) $lic['id'] )['billing_status'] );

		// An event from before the link is still skipped.
		$old = $this->webhook( 'subscription.updated', $sub, array( 'occurred_at' => gmdate( 'Y-m-d\TH:i:s.000000\Z', time() - 600 ) ) );
		$this->assertSame( 'stale', $old->get_data()['action'] );
	}
}
