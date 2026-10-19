<?php
/**
 * UX2-30: Pro downloads says why a version cannot be downloaded.
 *
 * @package SFLS
 */

/**
 * Download labels and install steps.
 */
class SFLS_Download_Labels_Test extends SFLS_Test_Case {

	/**
	 * A customer with a license.
	 *
	 * @param array $args License args.
	 */
	private function customer( array $args = array() ): WP_User {
		$user = $this->make_user();
		$this->make_license(
			$args + array(
				'customer_email' => $user->user_email,
				'user_id'        => $user->ID,
			)
		);
		return $user;
	}

	/**
	 * The repro: with an active license and a release that has no package,
	 * Pro downloads said "Renew to download". It says "Not available yet";
	 * "Renew to download" is for licenses that do not cover the version.
	 */
	public function test_labels(): void {
		$release = $this->add_release( '9.6.0' );
		$active  = $this->customer();
		$this->assertSame( '', SFLS_Account::locked_label( $active, $release, SFLS_Licenses::for_user( $active ) ), 'Downloadable.' );

		$expired = $this->customer();
		foreach ( SFLS_Licenses::for_user( $expired ) as $lic ) {
			SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
		}
		$this->assertSame( 'Renew to download', SFLS_Account::locked_label( $expired, $release, SFLS_Licenses::for_user( $expired ) ) );

		unlink( SFLS_Releases::package_path( $release ) );
		SFLS_Releases::flush();
		$this->assertSame( 'Not available yet', SFLS_Account::locked_label( $active, $release, SFLS_Licenses::for_user( $active ) ) );
		$html = SFLS_Account::downloads_html( $active );
		$this->assertStringContainsString( 'Not available yet', $html );
		$this->assertStringNotContainsString( 'Renew to download', $html );
	}

	/**
	 * Install steps only mention the Download Pro button when one is shown.
	 */
	public function test_steps_match_the_button(): void {
		$this->assertStringContainsString( 'Use the Download Pro button', SFLS_Delivery::steps_html( true ) );
		$this->assertStringNotContainsString( 'Download Pro button', SFLS_Delivery::steps_html( false ) );
		$this->assertStringContainsString( 'My Account › Pro downloads', SFLS_Delivery::steps_html( false ) );

		$this->isolate_releases();
		$order = $this->make_order( array( $this->make_license_product() ) );
		$order->payment_complete( 'ch_test' );
		$html = SFLS_Delivery::panel_html( wc_get_order( $order->get_id() ) );
		$this->assertStringNotContainsString( 'Download Pro button', $html, 'No release, no button, no mention of one.' );
	}

	/**
	 * The account link shows an initial under the photo, so a photo that
	 * does not load is never a broken image.
	 */
	public function test_account_link_avatar_has_an_initial(): void {
		$user = $this->make_user( 'avatar' );
		wp_update_user(
			array(
				'ID'         => $user->ID,
				'first_name' => 'Zoe',
			)
		);
		wp_set_current_user( $user->ID );
		$html = do_blocks( '<!-- wp:sfls/account-link /-->' );
		$this->assertMatchesRegularExpression( '#<span class="sfls-account-link__avatar" aria-hidden="true"><span class="sfls-account-link__initial">Z</span><img[^>]+sfls-account-link__photo#', $html );
		wp_set_current_user( 0 );
	}
}
