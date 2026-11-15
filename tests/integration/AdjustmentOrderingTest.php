<?php
/**
 * A stale adjustment event does not undo a newer one.
 *
 * @package SFLS
 */

/**
 * Adjustment event ordering.
 */
class SFLS_Adjustment_Ordering_Test extends SFLS_Test_Case {

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
	 * Approved, then an older pending event: the refund stays.
	 */
	public function test_stale_pending_after_approved_is_ignored(): void {
		$order = $this->buy_with_paddle( $this->email() );
		$txn   = $this->txns[ $order->get_transaction_id() ];
		$adj   = array(
			'id'             => 'adj_order01',
			'action'         => 'refund',
			'status'         => 'approved',
			'transaction_id' => $txn['id'],
			'totals'         => array(
				'total'         => '1500',
				'currency_code' => 'USD',
			),
		);
		$this->webhook( 'adjustment.updated', $adj, array( 'occurred_at' => self::when( 0 ) ) );
		$count = count( $order->get_refunds() ) ?: count( wc_get_order( $order->get_id() )->get_refunds() );
		$this->assertSame( 1, $count );

		$adj['status'] = 'pending_approval';
		$this->webhook( 'adjustment.updated', $adj, array( 'occurred_at' => self::when( -600 ) ) );
		$map = wc_get_order( $order->get_id() )->get_meta( '_sfls_paddle_adjustments' );
		$this->assertSame( 'approved', $map['adj_order01']['status'] );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
	}
}
