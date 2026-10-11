<?php
/**
 * Item 5: support email default, free-mail warning, sender of license emails.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Support address.
 */
class SFLS_Support_Email_Test extends SFLS_Test_Case {

	/**
	 * Pretend the store runs on www.example.com.
	 */
	private function on_example_com(): void {
		$this->filter( 'home_url', static fn() => 'https://www.example.com' );
	}

	/**
	 * Activation defaults to support@{site domain}.
	 */
	public function test_activation_defaults_support_email(): void {
		$this->on_example_com();
		$this->settings( array( 'support_email' => '' ) );
		$this->set_option( 'admin_email', 'owner.personal@gmail.com' );

		SFLS_Settings::maybe_default_support_email();

		$this->assertSame( 'support@example.com', SFLS_Settings::get( 'support_email' ) );
		$this->assertSame( 'support@example.com', SFLS_Settings::support_email() );
	}

	/**
	 * An address the owner set is never replaced.
	 */
	public function test_existing_support_email_is_kept(): void {
		$this->on_example_com();
		$this->settings( array( 'support_email' => 'help@example.com' ) );
		SFLS_Settings::maybe_default_support_email();
		$this->assertSame( 'help@example.com', SFLS_Settings::get( 'support_email' ) );
	}

	/**
	 * Nothing to default to when it equals the admin email or is invalid.
	 */
	public function test_no_default_when_same_as_admin_or_invalid(): void {
		$this->on_example_com();
		$this->set_option( 'admin_email', 'support@example.com' );
		$this->assertSame( '', SFLS_Settings::default_support_email() );
		remove_all_filters( 'home_url' );
		$this->filter( 'home_url', static fn() => 'http://localhost:8899' );
		$this->assertSame( '', SFLS_Settings::default_support_email() );
	}

	/**
	 * Free mail detection.
	 */
	public function test_free_mail_detection(): void {
		foreach ( array( 'a@gmail.com', 'a@outlook.com', 'a@yahoo.com', 'a@yahoo.co.uk', 'a@hotmail.fr', 'a@icloud.com' ) as $email ) {
			$this->assertTrue( SFLS_Settings::is_free_mail( $email ), $email );
		}
		foreach ( array( 'support@subtleforms.com', 'me@gmail.company.com', 'x@outlookshop.com' ) as $email ) {
			$this->assertFalse( SFLS_Settings::is_free_mail( $email ), $email );
		}
	}

	/**
	 * The Settings screen warns about a free-mail support address.
	 */
	public function test_settings_screen_warns_about_free_mail(): void {
		wp_set_current_user( 1 );
		$this->settings( array( 'support_email' => 'owner@gmail.com' ) );
		ob_start();
		( new SFLS_Admin() )->page_settings();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'sfls-free-mail', $html );
		$this->assertStringContainsString( 'owner@gmail.com is a free mail address', $html );

		$this->settings( array( 'support_email' => 'support@subtleforms.com' ) );
		ob_start();
		( new SFLS_Admin() )->page_settings();
		$this->assertStringNotContainsString( 'sfls-free-mail', (string) ob_get_clean() );
	}

	/**
	 * Order emails of license orders reply to support, and come from it when
	 * it is on the site's domain.
	 */
	public function test_license_order_email_sender(): void {
		$this->on_example_com();
		$this->settings( array( 'support_email' => 'help@example.com' ) );
		$order = $this->make_order( array( $this->make_license_product() ) );
		$order->payment_complete( 'ch_test' );
		$order = wc_get_order( $order->get_id() );

		$emails = WC()->mailer()->get_emails();
		$email  = $emails['WC_Email_Customer_Completed_Order'];
		$email->object = $order;

		$headers = apply_filters( 'woocommerce_email_headers', "Content-Type: text/html\r\nReply-to: Shop <shop@other.test>\r\n", 'customer_completed_order', $order, $email );
		$this->assertStringContainsString( 'Reply-To: "', $headers );
		$this->assertStringContainsString( '<help@example.com>', $headers );
		$this->assertStringNotContainsString( 'shop@other.test', $headers );
		$this->assertSame( 'help@example.com', apply_filters( 'woocommerce_email_from_address', 'shop@other.test', $email ) );

		// Free-mail support address: Reply-To only, From unchanged (DMARC).
		$this->settings( array( 'support_email' => 'owner@gmail.com' ) );
		$this->assertSame( 'shop@other.test', apply_filters( 'woocommerce_email_from_address', 'shop@other.test', $email ) );

		// Orders without licenses are left alone.
		$plain = $this->make_order( array( $this->make_physical_product() ) );
		$email->object = $plain;
		$this->assertStringContainsString( 'shop@other.test', apply_filters( 'woocommerce_email_headers', "Reply-to: Shop <shop@other.test>\r\n", 'customer_completed_order', $plain, $email ) );
	}

	/**
	 * Renewal reminders use the support address too.
	 */
	public function test_reminder_uses_support_address(): void {
		$this->settings( array( 'support_email' => 'help@sfls-test.invalid' ) );
		$this->assertTrue( SFLS_Reminders::send_test( 'admin@' . self::DOMAIN ) );
		$mail    = end( $this->mails );
		$headers = implode( "\n", (array) $mail['headers'] );
		$this->assertStringContainsString( 'Reply-To: "', $headers );
		$this->assertStringContainsString( 'help@sfls-test.invalid', $headers );
	}
}
