<?php
/**
 * Item 1: paid license orders complete and issue keys exactly once.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Order completion and key issuing.
 */
class SFLS_Order_Completion_Test extends SFLS_Test_Case {

	/**
	 * A Stripe-paid order with only license items ends at Completed with one
	 * key, even though the product is virtual and not downloadable.
	 */
	public function test_license_only_order_completes_on_payment_and_issues_once(): void {
		$product = $this->make_license_product( 'pro' );
		$this->assertFalse( $product->is_downloadable(), 'The fix must not depend on the downloadable flag.' );
		$order = $this->make_order( array( $product ) );

		$order->payment_complete( 'ch_test' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'yes', $order->get_meta( '_sfls_issued' ) );
		$this->assertCount( 1, $this->licenses_of_order( $order->get_id() ) );

		// Every other path that issues keys must now be a no-op.
		do_action( 'woocommerce_order_status_processing', $order->get_id(), $order );
		do_action( 'woocommerce_order_status_completed', $order->get_id(), $order );
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'ch_test' );
		( new SFLS_Woo() )->issue_for_order( $order->get_id() );
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );

		$this->assertCount( 1, $this->licenses_of_order( $order->get_id() ), 'Keys must be issued exactly once.' );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, array_filter( $notes, static fn( $n ) => str_contains( $n->content, 'license key issued' ) ) );
	}

	/**
	 * The downloadable stopgap on the live site changes nothing.
	 */
	public function test_downloadable_license_product_behaves_the_same(): void {
		$product = $this->make_license_product( 'starter' );
		$product->set_downloadable( true );
		$product->save();
		$order = $this->make_order( array( $product ) );

		$order->payment_complete( 'ch_test' );

		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, $this->licenses_of_order( $order->get_id() ) );
	}

	/**
	 * A license plus a physical item stays at Processing (it must ship) but
	 * the key is issued when payment completes.
	 */
	public function test_mixed_cart_issues_keys_on_payment(): void {
		$license = $this->make_license_product( 'agency' );
		$shirt   = $this->make_physical_product();
		$order   = $this->make_order( array( $license, $shirt ) );

		$order->payment_complete( 'ch_test' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$licenses = $this->licenses_of_order( $order->get_id() );
		$this->assertCount( 1, $licenses );
		$this->assertSame( 'agency', $licenses[0]['plan'] );

		$order->update_status( 'completed' );
		$this->assertCount( 1, $this->licenses_of_order( $order->get_id() ) );
	}

	/**
	 * Processing alone (no payment yet, cash on delivery) does not issue.
	 */
	public function test_unpaid_cash_on_delivery_waits_for_completed(): void {
		$product = $this->make_license_product( 'pro' );
		$order   = $this->make_order( array( $product ) );
		$order->set_payment_method( 'cod' );
		$order->save();

		$order->update_status( 'processing' );
		$this->assertCount( 0, $this->licenses_of_order( $order->get_id() ) );

		$order->update_status( 'completed' );
		$this->assertCount( 1, $this->licenses_of_order( $order->get_id() ) );
	}

	/**
	 * Orders without license products are left alone.
	 */
	public function test_non_license_orders_are_untouched(): void {
		$order = $this->make_order( array( $this->make_physical_product() ) );
		$order->payment_complete( 'ch_test' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', (string) $order->get_meta( '_sfls_issued' ) );
	}

	/**
	 * Saving the Products wizard keeps the products working: same IDs, the
	 * license meta the fix relies on stays, and nothing is duplicated.
	 */
	public function test_saving_the_wizard_keeps_auto_complete_working(): void {
		$this->set_option( SFLS_Products::OPTION, get_option( SFLS_Products::OPTION ) );
		$before = wp_list_pluck( SFLS_Products::all(), 'id' );
		$yearly = SFLS_Products::find( 'pro', 'yearly' );
		$this->assertNotNull( $yearly, 'The test site needs the wizard products.' );
		$product = wc_get_product( $yearly['id'] );
		$was_dl  = $product->is_downloadable();
		$product->set_downloadable( true ); // The live stopgap.
		$product->save();

		SFLS_Products::save_wizard( SFLS_Products::wizard_values() );
		SFLS_Products::flush();

		$this->assertSame( $before, wp_list_pluck( SFLS_Products::all(), 'id' ), 'Saving must not create products.' );
		$product = wc_get_product( $yearly['id'] );
		$this->assertTrue( $product->is_downloadable(), 'The wizard leaves the downloadable flag alone.' );
		$this->assertSame( 'yes', $product->get_meta( '_sfls_enabled' ) );

		$order = $this->make_order( array( $product ) );
		$order->payment_complete( 'ch_test' );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );

		$product->set_downloadable( $was_dl );
		$product->save();
	}
}
