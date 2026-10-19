<?php
/**
 * Items 6 and 8: simplified My Account and the Licenses screen.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Members area.
 */
class SFLS_Account_Test extends SFLS_Test_Case {

	/**
	 * Menu for the current user.
	 */
	private function menu(): array {
		return wc_get_account_menu_items();
	}

	/**
	 * License-only customers do not see Addresses and Downloads.
	 */
	public function test_license_only_customer_menu(): void {
		$this->settings( array( 'simplify_account' => 1 ) );
		$user  = $this->make_user();
		$order = $this->make_order( array( $this->make_license_product() ), $user );
		$order->payment_complete( 'ch_test' );
		wp_set_current_user( $user->ID );

		$menu = $this->menu();
		$this->assertArrayNotHasKey( 'edit-address', $menu );
		$this->assertArrayNotHasKey( 'downloads', $menu );
		foreach ( array( 'orders', 'licenses', 'pro-downloads', 'edit-account', 'customer-logout' ) as $key ) {
			$this->assertArrayHasKey( $key, $menu, $key );
		}
	}

	/**
	 * Customers who bought something else keep every tab.
	 */
	public function test_mixed_customer_keeps_tabs(): void {
		$this->settings( array( 'simplify_account' => 1 ) );
		$user = $this->make_user();
		$this->make_order( array( $this->make_physical_product() ), $user );
		wp_set_current_user( $user->ID );
		$menu = $this->menu();
		$this->assertArrayHasKey( 'edit-address', $menu );
		$this->assertArrayHasKey( 'downloads', $menu );
	}

	/**
	 * The setting turns it off.
	 */
	public function test_setting_off_keeps_tabs(): void {
		$this->settings( array( 'simplify_account' => 0 ) );
		$user = $this->make_user();
		wp_set_current_user( $user->ID );
		$this->assertArrayHasKey( 'edit-address', $this->menu() );
	}

	/**
	 * Active license cards have Download Pro, and the page explains the install.
	 */
	public function test_licenses_screen_download_and_steps(): void {
		$this->add_release( '9.9.0' ); // Its own package, whatever the site has.
		$user = $this->make_user();
		$this->make_license(
			array(
				'customer_email' => $user->user_email,
				'user_id'        => $user->ID,
			)
		);
		$this->make_license(
			array(
				'customer_email' => $user->user_email,
				'user_id'        => $user->ID,
				'plan'           => 'starter',
			)
		);
		wp_set_current_user( $user->ID );
		ob_start();
		( new SFLS_Account() )->licenses_page();
		$html = (string) ob_get_clean();

		$this->assertSame( 2, substr_count( $html, '>Download Pro</a>' ) );
		$this->assertStringContainsString( 'sfls_download=latest', $html );
		$this->assertStringContainsString( 'How to install Pro', $html );
		$this->assertStringContainsString( 'Download the zip', $html );
		$this->assertStringContainsString( 'Plugins › Add New › Upload Plugin', $html );
		$this->assertStringContainsString( 'SubtleForms › Settings › License and click Connect account', $html );
		$this->assertSame( 1, substr_count( $html, 'How to install Pro' ) );
	}

	/**
	 * Expired licenses get no download button.
	 */
	public function test_expired_license_has_no_download(): void {
		$user = $this->make_user();
		$lic  = $this->make_license(
			array(
				'customer_email' => $user->user_email,
				'user_id'        => $user->ID,
			)
		);
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
		wp_set_current_user( $user->ID );
		ob_start();
		( new SFLS_Account() )->licenses_page();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '>Download Pro</a>', $html );
		$this->assertStringNotContainsString( 'How to install Pro', $html );
	}
}
