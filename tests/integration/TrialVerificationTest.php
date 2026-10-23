<?php
/**
 * Free trials: the emailed code before the Paddle checkout, the claim written
 * when the order is created, and the chargeback reversal.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification

/**
 * Codes, claims and chargebacks.
 */
class SFLS_Trial_Verification_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;
	use SFLS_Woo_Paddle_Helpers;

	/**
	 * Mock and products.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->woo_paddle();
		$this->settings(
			array(
				'trial_verify'     => 1,
				'block_disposable' => 1,
			)
		);
	}

	/**
	 * The six digit code in the last email.
	 */
	private function emailed_code(): string {
		$this->assertNotEmpty( $this->mails, 'A code was emailed.' );
		$mail = end( $this->mails );
		$this->assertSame( 1, preg_match( '/\b(\d{6})\b/', wp_strip_all_tags( (string) $mail['message'] ), $m ), 'The email carries a 6 digit code.' );
		return $m[1];
	}

	/**
	 * Pending claims for an order.
	 *
	 * @param int $order_id Order.
	 */
	private function pending_claims( int $order_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_trial_claims WHERE order_id = %d AND status = 'pending'", $order_id ) );
	}

	// ── The code ────────────────────────────────────────────────────────

	/**
	 * A code is emailed, stored hashed, valid 15 minutes and 5 tries.
	 */
	public function test_code_rules(): void {
		global $wpdb;
		$email = $this->email( 'code' );
		$this->assertFalse( SFLS_Trials::is_verified( $email ) );
		$this->assertTrue( SFLS_Trials::issue_code( $email ) );
		$code = $this->emailed_code();

		// Only a hash is kept.
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}sfls_trial_codes", ARRAY_A );
		$this->assertNotSame( $code, $row['code_hash'] );
		$this->assertSame( 64, strlen( $row['code_hash'] ) );
		$this->assertStringNotContainsString( $code, (string) wp_json_encode( $row ) );
		$this->assertEqualsWithDelta( time() + 15 * MINUTE_IN_SECONDS, strtotime( $row['expires_at'] . ' UTC' ), 5 );

		// A new code cannot be asked for again at once.
		$again = SFLS_Trials::issue_code( $email );
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'code_too_soon', $again->get_error_code() );

		// Wrong tries: four are answered "wrong", the right code still works on the fifth.
		$wrong = '000000' === $code ? '111111' : '000000';
		for ( $n = 1; $n <= 4; $n++ ) {
			$this->assertSame( 'code_wrong', SFLS_Trials::verify_code( $email, $wrong )->get_error_code(), "Try $n" );
		}
		$this->assertTrue( SFLS_Trials::verify_code( $email, $code ) );
		$this->assertTrue( SFLS_Trials::is_verified( $email ) );
		$this->assertTrue( SFLS_Trials::is_verified( strtoupper( $email ) ), 'Same address, any case.' );
		$this->assertSame( 'code_expired', SFLS_Trials::verify_code( $email, $code )->get_error_code(), 'A code works once.' );
	}

	/**
	 * Five wrong tries end the code, even the right one afterwards.
	 */
	public function test_five_tries_then_locked(): void {
		$email = $this->email( 'tries' );
		SFLS_Trials::issue_code( $email );
		$code  = $this->emailed_code();
		$wrong = '000000' === $code ? '111111' : '000000';
		for ( $n = 1; $n <= 5; $n++ ) {
			$this->assertSame( 'code_wrong', SFLS_Trials::verify_code( $email, $wrong )->get_error_code() );
		}
		$this->assertSame( 'code_expired', SFLS_Trials::verify_code( $email, $code )->get_error_code() );
		$this->assertFalse( SFLS_Trials::is_verified( $email ) );
	}

	/**
	 * A code older than 15 minutes is refused.
	 */
	public function test_code_expires(): void {
		global $wpdb;
		$email = $this->email( 'late' );
		SFLS_Trials::issue_code( $email );
		$code = $this->emailed_code();
		$wpdb->query( "UPDATE {$wpdb->prefix}sfls_trial_codes SET expires_at = '" . gmdate( 'Y-m-d H:i:s', time() - 5 ) . "'" );
		$this->assertSame( 'code_expired', SFLS_Trials::verify_code( $email, $code )->get_error_code() );
		$this->assertFalse( SFLS_Trials::is_verified( $email ) );
	}

	// ── Checkout ────────────────────────────────────────────────────────

	/**
	 * Place order for a trial does not open Paddle until the code is right: it
	 * emails the code and sends the customer to the payment page.
	 */
	public function test_trial_waits_for_the_code(): void {
		$email = $this->email( 'trial' );
		$this->cart_plan( 'pro', true );
		$order = $this->checkout_order( $email );

		$result = SFLS_Woo_Paddle::gateway()->process_payment( $order->get_id() );
		$this->assertSame( 'success', $result['result'] );
		$this->assertArrayNotHasKey( 'sfls_paddle_txn', $result );
		$this->assertStringContainsString( 'order-pay', $result['redirect'] );
		$this->assertCount( 0, $this->calls( 'POST', '/transactions' ), 'No Paddle transaction before the email is confirmed.' );
		$this->assertSame( 2, $this->pending_claims( $order->get_id() ), 'The trial is claimed (email and company domain) at order creation.' );
		$code = $this->emailed_code();

		// The payment page asks for the code.
		ob_start();
		do_action( 'woocommerce_receipt_sfls_paddle', $order->get_id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="sfls_trial_code"', $html );
		$this->assertCount( 0, $this->calls( 'POST', '/transactions' ) );

		// A wrong code is refused, the right one opens the Paddle checkout.
		$this->assertSame( 'code_wrong', SFLS_Trials::verify_code( $email, '000000' === $code ? '111111' : '000000' )->get_error_code() );
		$this->assertTrue( SFLS_Trials::verify_code( $email, $code ) );
		ob_start();
		do_action( 'woocommerce_receipt_sfls_paddle', $order->get_id() );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'name="sfls_trial_code"', $html );
		$this->assertCount( 1, $this->calls( 'POST', '/transactions' ) );
		$this->assertNotSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_sfls_paddle_txn_id' ) );
	}

	/**
	 * With the setting off, a trial goes straight to Paddle.
	 */
	public function test_setting_off_skips_the_code(): void {
		$this->settings( array( 'trial_verify' => 0 ) );
		$this->cart_plan( 'pro', true );
		$order  = $this->checkout_order( $this->email( 'nocode' ) );
		$result = SFLS_Woo_Paddle::gateway()->process_payment( $order->get_id() );
		$this->assertNotEmpty( $result['sfls_paddle_txn'] );
	}

	/**
	 * Throw-away addresses cannot start a trial.
	 */
	public function test_disposable_email_is_refused_at_payment(): void {
		$this->settings( array( 'trial_verify' => 0 ) );
		$this->cart_plan( 'pro', true );
		$order  = $this->checkout_order( 'sflstest' . strtolower( wp_generate_password( 5, false, false ) ) . '@mailinator.com' );
		$result = SFLS_Woo_Paddle::gateway()->process_payment( $order->get_id() );
		$this->assertSame( 'failure', $result['result'] );
		$this->assertNotEmpty( array_filter( $this->notices( 'error' ), static fn( $n ) => str_contains( $n, 'permanent email' ) ) );
	}

	// ── Claims at order creation ────────────────────────────────────────

	/**
	 * Two checkouts for the same email: the second is refused before any
	 * payment, and canceling the first frees the trial.
	 */
	public function test_claim_at_order_creation_and_release(): void {
		$email = 'sflstest' . strtolower( wp_generate_password( 6, false, false ) ) . '@gmail.com';
		$this->cart_plan( 'pro', true );
		$first = $this->checkout_order( $email );
		$this->mark_email_verified( $email );
		$this->assertNotEmpty( SFLS_Woo_Paddle::gateway()->process_payment( $first->get_id() )['sfls_paddle_txn'] );
		$this->assertSame( 1, $this->pending_claims( $first->get_id() ), 'Gmail: the email claim only.' );

		WC()->cart->empty_cart();
		$this->cart_plan( 'pro', true );
		$second = $this->checkout_order( $email );
		$result = SFLS_Woo_Paddle::gateway()->process_payment( $second->get_id() );
		$this->assertSame( 'failure', $result['result'], 'Parallel checkout with the same email is refused.' );
		$this->assertSame( 0, $this->pending_claims( $second->get_id() ) );
		$this->assertCount( 1, $this->calls( 'POST', '/transactions' ) );

		// The same order may be paid again: its own claim does not block it.
		$this->assertNotEmpty( SFLS_Woo_Paddle::gateway()->process_payment( $first->get_id() )['sfls_paddle_txn'] );

		// Canceling the unpaid order gives the trial back.
		$first = wc_get_order( $first->get_id() );
		$first->update_status( 'cancelled' );
		$this->assertSame( 0, $this->pending_claims( $first->get_id() ) );
		$this->assertTrue( SFLS_Trials::check( $email )['eligible'] );
		$this->assertNotEmpty( SFLS_Woo_Paddle::gateway()->process_payment( $second->get_id() )['sfls_paddle_txn'] );
	}

	/**
	 * A failed Paddle transaction releases the claim at once; a claim of an
	 * abandoned checkout stops blocking after 30 minutes.
	 */
	public function test_failed_start_and_stale_claims_release(): void {
		global $wpdb;
		$email = 'sflstest' . strtolower( wp_generate_password( 6, false, false ) ) . '@gmail.com';
		$this->mark_email_verified( $email );
		$this->cart_plan( 'pro', true );
		$order = $this->checkout_order( $email );
		$this->paddle_routes = array( 'POST /transactions' => array( 500, array( 'error' => array( 'code' => 'internal' ) ) ) ) + $this->paddle_routes;
		$result = SFLS_Woo_Paddle::gateway()->process_payment( $order->get_id() );
		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 0, $this->pending_claims( $order->get_id() ) );
		$this->assertTrue( SFLS_Trials::check( $email )['eligible'] );

		// An abandoned checkout.
		$reserved = SFLS_Trials::reserve( $email, 987001 );
		$this->assertTrue( $reserved['eligible'] );
		$this->assertFalse( SFLS_Trials::check( $email )['eligible'] );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}sfls_trial_claims SET created_at = %s WHERE order_id = 987001", gmdate( 'Y-m-d H:i:s', time() - 31 * MINUTE_IN_SECONDS ) ) );
		$this->assertTrue( SFLS_Trials::check( $email )['eligible'] );
		$wpdb->delete( $wpdb->prefix . 'sfls_trial_claims', array( 'order_id' => 987001 ) );
	}

	/**
	 * Payment turns the pending claims into the license's claims.
	 */
	public function test_payment_makes_the_claim_final(): void {
		global $wpdb;
		$email = 'sflstest' . strtolower( wp_generate_password( 6, false, false ) ) . '@gmail.com';
		$order = $this->buy_with_paddle( $email, 'pro', true );
		$lic   = $this->licenses_of_order( $order->get_id() )[0];
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT license_id, status FROM {$wpdb->prefix}sfls_trial_claims WHERE kind = 'email' AND order_id = %d", $order->get_id() ), ARRAY_A );
		$this->assertCount( 1, $rows );
		$this->assertSame( (int) $lic['id'], (int) $rows[0]['license_id'] );
		$this->assertSame( 'active', $rows[0]['status'] );
		$this->assertSame( 0, $this->pending_claims( $order->get_id() ) );
		$this->assertSame( 'email_used', SFLS_Trials::check( $email )['reason'] );
		$this->assertCount( 1, SFLS_Trials::claims_of( (int) $lic['id'] ), 'No duplicate claim.' );
	}

	// ── Chargeback ──────────────────────────────────────────────────────

	/**
	 * A chargeback and then its reversal: the license is suspended, then active
	 * again, and the refund the chargeback made is removed.
	 */
	public function test_chargeback_reverse_restores_the_license(): void {
		$order = $this->buy_with_paddle( $this->email( 'cb' ) );
		$txn   = $this->txns[ $order->get_transaction_id() ];
		$lic   = $this->licenses_of_order( $order->get_id() )[0];
		$total = (string) $txn['details']['totals']['total'];

		$this->webhook(
			'adjustment.created',
			array(
				'id'             => 'adj_chargeback01',
				'action'         => 'chargeback',
				'status'         => 'approved',
				'transaction_id' => $txn['id'],
				'totals'         => array(
					'total'         => $total,
					'currency_code' => 'USD',
				),
			)
		);
		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 'suspended', SFLS_Licenses::find( (int) $lic['id'] )['status'] );

		$this->webhook(
			'adjustment.created',
			array(
				'id'             => 'adj_reverse01',
				'action'         => 'chargeback_reverse',
				'status'         => 'approved',
				'transaction_id' => $txn['id'],
				'totals'         => array(
					'total'         => $total,
					'currency_code' => 'USD',
				),
			)
		);
		$this->assertSame( 'active', SFLS_Licenses::find( (int) $lic['id'] )['status'] );
		$after = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $after->get_status() );
		$this->assertCount( 0, wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order->get_id(), 'limit' => 5 ) ) );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'license is active again' ) ) );

		// A replay changes nothing.
		$this->webhook(
			'adjustment.created',
			array(
				'id'             => 'adj_reverse01',
				'action'         => 'chargeback_reverse',
				'status'         => 'approved',
				'transaction_id' => $txn['id'],
				'totals'         => array(
					'total'         => $total,
					'currency_code' => 'USD',
				),
			)
		);
		$this->assertSame( 'active', SFLS_Licenses::find( (int) $lic['id'] )['status'] );
	}

	/**
	 * A chargeback stored before the action was kept in the map is still
	 * recognised on reversal: its mirrored refund is removed.
	 */
	public function test_chargeback_reverse_handles_a_legacy_map_entry(): void {
		$order = $this->buy_with_paddle( $this->email( 'cbold' ) );
		$txn   = $this->txns[ $order->get_transaction_id() ];
		$lic   = $this->licenses_of_order( $order->get_id() )[0];
		$total = (string) $txn['details']['totals']['total'];
		$totals = array(
			'total'         => $total,
			'currency_code' => 'USD',
		);

		$this->webhook( 'adjustment.created', array( 'id' => 'adj_legacycb1', 'action' => 'chargeback', 'status' => 'approved', 'transaction_id' => $txn['id'], 'totals' => $totals ) );

		$fresh = wc_get_order( $order->get_id() );
		$map   = (array) $fresh->get_meta( '_sfls_paddle_adjustments' );
		unset( $map['adj_legacycb1']['action'] );
		$fresh->update_meta_data( '_sfls_paddle_adjustments', $map );
		$fresh->save_meta_data();

		$this->webhook( 'adjustment.created', array( 'id' => 'adj_legacyrev1', 'action' => 'chargeback_reverse', 'status' => 'approved', 'transaction_id' => $txn['id'], 'totals' => $totals ) );

		$this->assertSame( 'active', SFLS_Licenses::find( (int) $lic['id'] )['status'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 0, wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order->get_id(), 'limit' => 5 ) ) );
	}
}
