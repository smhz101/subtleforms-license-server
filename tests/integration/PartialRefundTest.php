<?php
/**
 * Partial refunds: noted below the suspend threshold, suspend at it.
 *
 * @package SFLS
 */

/**
 * Partial refund policy.
 */
class SFLS_Partial_Refund_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;

	/**
	 * A license of a customer that expires in some days.
	 *
	 * @param WP_User $user Owner.
	 * @param string  $plan Plan.
	 * @param int     $days Days left.
	 */
	private function owned_license( WP_User $user, string $plan, int $days ): array {
		$lic = $this->make_license(
			array(
				'plan'           => $plan,
				'customer_email' => $user->user_email,
				'user_id'        => $user->ID,
			)
		);
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) ) );
		return SFLS_Licenses::find( (int) $lic['id'] );
	}

	/**
	 * A renewal product.
	 *
	 * @param string $plan Plan.
	 */
	private function renewal_product( string $plan ): WC_Product_Simple {
		$product = $this->make_license_product( $plan, 12, '39' );
		$product->update_meta_data( '_sfls_renewal', 'yes' );
		$product->save();
		return $product;
	}

	/**
	 * Paid renewal order for a license.
	 *
	 * @param WP_User $user User.
	 * @param array   $lic  License.
	 */
	private function renew( WP_User $user, array $lic ): WC_Order {
		$order = $this->make_order( array( $this->renewal_product( $lic['plan'] ) ), $user, array( array( '_sfls_renew_license' => (int) $lic['id'] ) ) );
		$order->payment_complete( 'ch_test' );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * A Paddle renewal order for a license billed by a subscription, paid.
	 *
	 * @param string $license_sub Subscription on the license.
	 * @param string $order_sub   Subscription on the order.
	 * @return array{0:WC_Order,1:array} Order and license.
	 */
	private function paddle_renewal( string $license_sub = 'sub_01renew', string $order_sub = 'sub_01renew' ): array {
		$this->paddle();
		$this->paddle_routes['#^POST /subscriptions/sub_\w+/cancel$#'] = fn( $call ) => array( 'id' => basename( dirname( $call['path'] ) ) ) + $this->subscription( array( 'status' => 'canceled' ) );
		$user = $this->make_user();
		$lic  = $this->owned_license( $user, 'pro', 30 );
		SFLS_Licenses::update(
			(int) $lic['id'],
			array(
				'paddle_subscription_id' => $license_sub,
				'billing'                => 'paddle',
				'billing_status'         => 'active',
			)
		);
		$order = $this->renew( $user, SFLS_Licenses::find( (int) $lic['id'] ) );
		$order->set_payment_method( SFLS_Woo_Paddle::GATEWAY );
		$order->update_meta_data( '_sfls_paddle_renewal', 'yes' );
		$order->update_meta_data( '_sfls_paddle_subscription_id', $order_sub );
		$order->save();
		return array( wc_get_order( $order->get_id() ), SFLS_Licenses::find( (int) $lic['id'] ) );
	}

	/**
	 * Refund an amount.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $amount Amount.
	 */
	private function refund( WC_Order $order, string $amount ): void {
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $amount,
			)
		);
		$this->assertFalse( is_wp_error( $refund ) );
	}

	/**
	 * Order notes.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private function notes( WC_Order $order ): array {
		return wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
	}

	/**
	 * Below the threshold: a note; at it: suspended and subscription canceled, once.
	 */
	public function test_threshold_suspends_and_cancels(): void {
		$this->set_option( SFLS_Settings::OPTION, array_merge( SFLS_Settings::all(), array( 'refund_suspend_threshold' => 50 ) ) );
		[ $order, $lic ] = $this->paddle_renewal();
		$this->refund( $order, '11.70' );
		$order = wc_get_order( $order->get_id() );
		$this->assertNotEmpty( array_filter( $this->notes( $order ), static fn( $n ) => str_contains( $n, 'Refund of 30.8% recorded; license(s) unchanged (suspends at 50%)' ) ) );
		$this->assertSame( 'active', SFLS_Licenses::find( (int) $lic['id'] )['status'] );
		$this->assertSame( array(), $this->calls( 'POST', '#^/subscriptions/sub_\w+/cancel$#' ) );

		$this->refund( $order, '12' );
		$this->assertSame( 'suspended', SFLS_Licenses::find( (int) $lic['id'] )['status'] );
		$this->assertCount( 1, $this->calls( 'POST', '#^/subscriptions/sub_\w+/cancel$#' ) );

		$this->refund( wc_get_order( $order->get_id() ), '1' );
		$this->assertCount( 1, $this->calls( 'POST', '#^/subscriptions/sub_\w+/cancel$#' ), 'Not cancelled twice.' );
	}

	/**
	 * Default threshold is 100: a partial refund only leaves a note.
	 */
	public function test_default_threshold_notes_only(): void {
		$this->set_option( SFLS_Settings::OPTION, array() );
		$this->assertSame( 100, SFLS_Settings::refund_suspend_threshold() );
		[ $order, $lic ] = $this->paddle_renewal();
		$this->refund( $order, '11.70' );
		$this->assertNotEmpty( array_filter( $this->notes( $order ), static fn( $n ) => str_contains( $n, 'suspends at 100%' ) ) );
		$this->assertSame( 'active', SFLS_Licenses::find( (int) $lic['id'] )['status'] );
		$this->assertSame( array(), $this->calls( 'POST', '#^/subscriptions/sub_\w+/cancel$#' ) );
	}
}
