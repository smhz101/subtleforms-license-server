<?php
/**
 * LS-03: refunding or cancelling a renewal or upgrade order undoes it.
 *
 * @package SFLS
 */

/**
 * Refunds of renewals and upgrades.
 */
class SFLS_Refund_Test extends SFLS_Test_Case {

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
	 * Refund an order in full.
	 *
	 * @param WC_Order $order Order.
	 */
	private function refund_all( WC_Order $order ): void {
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $order->get_total(),
				'reason'   => 'Test',
			)
		);
		$this->assertNotWPError( $refund );
		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Not a WP_Error.
	 *
	 * @param mixed $value Value.
	 */
	private function assertNotWPError( $value ): void {
		$this->assertFalse( is_wp_error( $value ), is_wp_error( $value ) ? $value->get_error_message() : '' );
	}

	/**
	 * The repro, upgrade half: Starter upgraded to Pro, refunded in full,
	 * goes back to Starter with its old site limit and expiry.
	 */
	public function test_refunded_upgrade_restores_the_previous_plan(): void {
		$user    = $this->make_user();
		$lic     = $this->owned_license( $user, 'starter', 100 );
		$product = $this->make_license_product( 'pro', 12, '50' );
		$order   = $this->make_order( array( $product ), $user, array( array( '_sfls_upgrade_license' => (int) $lic['id'] ) ) );
		$order->payment_complete( 'ch_test' );

		$up = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( 'pro', $up['plan'] );
		$this->assertSame( (int) SFLS_Plans::get( 'pro' )['sites'], (int) $up['sites_allowed'] );

		$this->refund_all( wc_get_order( $order->get_id() ) );

		$back = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertSame( 'starter', $back['plan'] );
		$this->assertSame( (int) $lic['sites_allowed'], (int) $back['sites_allowed'] );
		$this->assertSame( $lic['expires_at'], $back['expires_at'] );
		$this->assertSame( 'active', $back['status'], 'The key itself keeps working.' );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'Upgrade of SubtleForms license' ) ) );

		// Cancelling afterwards changes nothing more.
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$this->assertSame( $back, SFLS_Licenses::find( (int) $lic['id'] ) );
	}

	/**
	 * The repro, renewal half: the year a refunded renewal added is removed.
	 */
	public function test_refunded_renewal_removes_the_year(): void {
		$user  = $this->make_user();
		$lic   = $this->owned_license( $user, 'pro', 30 );
		$order = $this->renew( $user, $lic );
		$this->assertGreaterThan( strtotime( '+11 months' ), strtotime( SFLS_Licenses::find( (int) $lic['id'] )['expires_at'] . ' UTC' ) );

		$this->refund_all( $order );
		$this->assertSame( $lic['expires_at'], SFLS_Licenses::find( (int) $lic['id'] )['expires_at'] );
	}

	/**
	 * A cancelled renewal order is undone too, and only its own year goes
	 * when a later renewal also added one.
	 */
	public function test_cancelled_renewal_keeps_later_renewals(): void {
		$user   = $this->make_user();
		$lic    = $this->owned_license( $user, 'pro', 30 );
		$first  = $this->renew( $user, $lic );
		$middle = SFLS_Licenses::find( (int) $lic['id'] )['expires_at'];
		$this->renew( $user, SFLS_Licenses::find( (int) $lic['id'] ) );
		$two = SFLS_Licenses::find( (int) $lic['id'] )['expires_at'];
		$this->assertNotSame( $middle, $two );

		$first->update_status( 'cancelled' );
		$after = SFLS_Licenses::find( (int) $lic['id'] )['expires_at'];
		$this->assertSame(
			strtotime( $two . ' UTC' ) - ( strtotime( $middle . ' UTC' ) - strtotime( $lic['expires_at'] . ' UTC' ) ),
			strtotime( $after . ' UTC' )
		);
	}

	/**
	 * A partial refund leaves the license alone.
	 */
	public function test_partial_refund_changes_nothing(): void {
		$user  = $this->make_user();
		$lic   = $this->owned_license( $user, 'pro', 30 );
		$order = $this->renew( $user, $lic );
		$now   = SFLS_Licenses::find( (int) $lic['id'] );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '5',
			)
		);
		$this->assertNotWPError( $refund );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( $now['expires_at'], SFLS_Licenses::find( (int) $lic['id'] )['expires_at'] );
	}
}
