<?php
/**
 * Item 9: key, Download Pro and install steps after purchase.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Order received page and order emails.
 */
class SFLS_Delivery_Test extends SFLS_Test_Case {

	/**
	 * The completed order email carries the key, Download Pro and the steps.
	 */
	public function test_completed_email_has_key_download_and_steps(): void {
		$this->add_release( '9.9.0' ); // Its own package, whatever the site has.
		$this->set_option( 'woocommerce_customer_completed_order_settings', array_merge( (array) get_option( 'woocommerce_customer_completed_order_settings', array() ), array( 'enabled' => 'yes' ) ) );
		$user  = $this->make_user();
		$order = $this->make_order( array( $this->make_license_product( 'pro' ) ), $user );
		$this->mails = array();

		$order->payment_complete( 'ch_test' );

		$completed = array_values( array_filter( $this->mails, static fn( $m ) => $m['to'] === $user->user_email || ( is_array( $m['to'] ) && in_array( $user->user_email, $m['to'], true ) ) ) );
		$this->assertNotEmpty( $completed, 'The customer gets the completed order email.' );
		$body = $completed[ count( $completed ) - 1 ]['message'];
		$lic  = $this->licenses_of_order( $order->get_id() )[0];

		$this->assertStringContainsString( $lic['license_key'], $body );
		$this->assertStringContainsString( 'Download Pro', $body );
		$this->assertStringContainsString( 'pro-downloads', $body );
		$this->assertStringContainsString( 'How to install Pro', $body );
		$this->assertStringContainsString( 'Download the zip', $body );
		$this->assertStringContainsString( 'Upload it to WordPress', $body );
		$this->assertStringContainsString( 'Connect your account', $body );
	}

	/**
	 * Plain text emails get the same content.
	 */
	public function test_plain_text_email(): void {
		$order = $this->make_order( array( $this->make_license_product( 'starter' ) ) );
		$order->payment_complete( 'ch_test' );
		$order = wc_get_order( $order->get_id() );
		ob_start();
		( new SFLS_Delivery() )->email( $order, false, true );
		$text = (string) ob_get_clean();
		$this->assertStringContainsString( $this->licenses_of_order( $order->get_id() )[0]['license_key'], $text );
		$this->assertStringContainsString( '1. Download the zip', $text );
		$this->assertStringContainsString( '3. Connect your account', $text );
	}

	/**
	 * Admin emails list the key without the customer instructions.
	 */
	public function test_admin_email_lists_keys_only(): void {
		$order = $this->make_order( array( $this->make_license_product() ) );
		$order->payment_complete( 'ch_test' );
		ob_start();
		( new SFLS_Delivery() )->email( wc_get_order( $order->get_id() ), true, false );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'SFP-', $html );
		$this->assertStringNotContainsString( 'How to install Pro', $html );
	}

	/**
	 * The order received page shows the panel once, right after the status
	 * block of the block theme, and not again further down.
	 */
	public function test_order_received_panel(): void {
		$order = $this->make_order( array( $this->make_license_product() ) );
		$order->payment_complete( 'ch_test' );
		$order = wc_get_order( $order->get_id() );
		set_query_var( 'order-received', $order->get_id() );
		$_GET['key'] = $order->get_order_key();

		$delivery = new SFLS_Delivery();
		$html     = $delivery->after_status_block( '<p>Thank you.</p>' );
		$this->assertStringStartsWith( '<p>Thank you.</p>', $html );
		$this->assertStringContainsString( $this->licenses_of_order( $order->get_id() )[0]['license_key'], $html );
		$this->assertStringContainsString( 'data-sfls-copy', $html );
		$this->assertStringContainsString( 'How to install Pro', $html );

		ob_start();
		$delivery->order_page( $order );
		$delivery->thankyou( $order->get_id() );
		$this->assertSame( '', (string) ob_get_clean(), 'Printed once per request.' );

		unset( $_GET['key'] );
		set_query_var( 'order-received', 0 );
	}

	/**
	 * Strangers with a wrong order key see nothing.
	 */
	public function test_order_received_needs_the_order_key(): void {
		$order = $this->make_order( array( $this->make_license_product() ) );
		$order->payment_complete( 'ch_test' );
		set_query_var( 'order-received', $order->get_id() );
		$_GET['key'] = 'wc_order_wrong';
		$this->assertSame( '<p>x</p>', ( new SFLS_Delivery() )->after_status_block( '<p>x</p>' ) );
		unset( $_GET['key'] );
		set_query_var( 'order-received', 0 );
	}

	/**
	 * Before payment the page says the key is on its way.
	 */
	public function test_unpaid_order_says_key_follows(): void {
		$order = $this->make_order( array( $this->make_license_product() ) );
		$html  = SFLS_Delivery::panel_html( $order );
		$this->assertStringContainsString( 'appears here as soon as your payment is confirmed', $html );
	}
}
