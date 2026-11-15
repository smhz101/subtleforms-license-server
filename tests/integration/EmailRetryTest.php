<?php
/**
 * Billing emails are marked sent only after they were sent.
 *
 * @package SFLS
 */

/**
 * Email retry queue.
 */
class SFLS_Email_Retry_Test extends SFLS_Test_Case {

	/**
	 * Whether mail fails.
	 */
	private bool $fail = true;

	/**
	 * Mail fails while $this->fail is true.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( SFLS_Billing_Emails::QUEUE_OPTION );
		delete_option( SFLS_Billing_Emails::FAILED_OPTION );
		$this->filter( 'pre_wp_mail', fn( $pre ) => $this->fail ? false : $pre, 20 );
	}

	/**
	 * Leave no queue behind.
	 */
	protected function tearDown(): void {
		delete_option( SFLS_Billing_Emails::QUEUE_OPTION );
		delete_option( SFLS_Billing_Emails::FAILED_OPTION );
		parent::tearDown();
	}

	/**
	 * Queue entries of one license (other tests and cron may queue their own).
	 *
	 * @param array $lic License.
	 */
	private function queued( array $lic ): array {
		return array_values( array_filter( SFLS_Billing_Emails::queue(), static fn( $e ) => (int) $e['license_id'] === (int) $lic['id'] ) );
	}

	/**
	 * A Paddle license with a customer.
	 */
	private function paddle_license( array $args = array() ): array {
		$lic = $this->make_license( $args );
		SFLS_Licenses::update( (int) $lic['id'], array( 'billing' => 'paddle', 'billing_status' => 'active', 'paddle_subscription_id' => 'sub_x' . $lic['id'] ) );
		return SFLS_Licenses::find( (int) $lic['id'] );
	}

	/**
	 * A failed send leaves no marker and is queued; the next daily run sends it.
	 */
	public function test_failure_queues_and_daily_run_sends(): void {
		$lic     = $this->paddle_license();
		$failed  = array();
		$this->filter(
			'sfls_billing_email_failed',
			function ( $id, $template, $error ) use ( &$failed ) {
				$failed[] = array( $id, $template, $error );
			},
			10,
			3
		);

		$this->assertFalse( SFLS_Billing_Emails::send_once( $lic, 'welcome', 'welcome' ) );
		$this->assertFalse( SFLS_Reminders::has_marker( $lic, 'welcome' ) );
		$queue = $this->queued( $lic );
		$this->assertCount( 1, $queue );
		$this->assertSame( 'welcome', $queue[0]['template'] );
		$this->assertSame( (int) $lic['id'], $queue[0]['license_id'] );
		$this->assertSame( 1, $queue[0]['attempts'] );
		$mine = array_values( array_filter( $failed, static fn( $f ) => (int) $f[0] === (int) $lic['id'] ) );
		$this->assertCount( 1, $mine );
		$this->assertSame( 'welcome', $mine[0][1] );
		$this->assertFalse( autoload_of_queue() );

		$this->fail  = false;
		$this->mails = array();
		do_action( SFLS_Reminders::HOOK );
		$this->assertTrue( SFLS_Reminders::has_marker( $lic, 'welcome' ) );
		$this->assertSame( array(), $this->queued( $lic ) );
	}

	/**
	 * Three failures drop the email, leave a notice and an order note.
	 */
	public function test_third_failure_drops_notes_and_notifies(): void {
		$user  = $this->make_user();
		$order = $this->make_order( array( $this->make_license_product() ), $user );
		$lic   = $this->paddle_license( array( 'order_id' => $order->get_id() ) );

		SFLS_Billing_Emails::send_once( $lic, 'payment_failed', 'failed|x' );
		SFLS_Billing_Emails::send_once( $lic, 'payment_failed', 'failed|x' );
		$this->assertCount( 1, $this->queued( $lic ), 'No double queueing.' );
		SFLS_Billing_Emails::retry_queue();
		$this->assertSame( 2, $this->queued( $lic )[0]['attempts'] );
		SFLS_Billing_Emails::retry_queue();

		$this->assertSame( array(), $this->queued( $lic ) );
		$this->assertFalse( SFLS_Reminders::has_marker( $lic, 'failed|x' ) );
		$this->assertCount( 1, (array) get_option( SFLS_Billing_Emails::FAILED_OPTION ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'payment_failed', $notes );

		$admin = $this->make_user();
		$admin->add_role( 'administrator' );
		wp_set_current_user( $admin->ID );
		ob_start();
		SFLS_Billing_Emails::notice();
		$this->assertStringContainsString( 'sfls-email-failures', (string) ob_get_clean() );
	}

	/**
	 * The queue holds 200 entries, oldest dropped.
	 */
	public function test_queue_is_capped(): void {
		$lic = $this->paddle_license();
		for ( $i = 0; $i < 205; $i++ ) {
			SFLS_Billing_Emails::send_once( $lic, 'welcome', 'm' . $i );
		}
		$queue = SFLS_Billing_Emails::queue();
		$this->assertCount( 200, $queue );
		$this->assertSame( 'm204', $queue[199]['marker'] );
		$this->assertSame( 'm5', $queue[0]['marker'] );
	}

	/**
	 * Reminder stages are marked only after a successful send.
	 */
	public function test_reminder_marker_after_success(): void {
		$this->settings( array( 'reminder_days' => '7', 'lapsed_reminder' => 0 ) );
		$lic = $this->make_license();
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) ) );
		$lic = SFLS_Licenses::find( (int) $lic['id'] );
		if ( SFLS_Reminders::run( true ) !== 1 ) {
			$this->markTestIncomplete( 'The site has its own licenses due.' );
		}
		SFLS_Reminders::run();
		$this->assertSame( array(), json_decode( (string) SFLS_Licenses::find( (int) $lic['id'] )['reminders'], true ) ?: array() );
		$this->fail = false;
		SFLS_Reminders::run();
		$this->assertNotEmpty( json_decode( (string) SFLS_Licenses::find( (int) $lic['id'] )['reminders'], true ) );
	}
}

/**
 * Whether the queue option autoloads.
 */
function autoload_of_queue(): bool {
	global $wpdb;
	return 'yes' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", SFLS_Billing_Emails::QUEUE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
