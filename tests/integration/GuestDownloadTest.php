<?php
/**
 * LS-02: a guest buyer can download Pro and claim the license through a
 * signed link, without an account.
 *
 * @package SFLS
 */

if ( ! class_exists( 'SFLS_Test_Stop' ) ) {
	require_once __DIR__ . '/ReleaseManagementTest.php';
}

/**
 * Signed guest links.
 */
class SFLS_Guest_Download_Test extends SFLS_Test_Case {

	/**
	 * Clean request globals.
	 */
	protected function tearDown(): void {
		unset( $_GET['sfls_guest'], $_GET['expires'], $_GET['sig'], $_GET['download'] );
		parent::tearDown();
	}

	/**
	 * A completed guest order (cheque, completed by the admin).
	 */
	private function guest_order(): WC_Order {
		$this->set_option( 'woocommerce_customer_completed_order_settings', array_merge( (array) get_option( 'woocommerce_customer_completed_order_settings', array() ), array( 'enabled' => 'yes' ) ) );
		$order = $this->make_order( array( $this->make_license_product( 'starter' ) ) );
		$order->set_payment_method( 'cheque' );
		$order->save();
		$this->mails = array();
		$order->update_status( 'completed' );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Query args of a URL.
	 *
	 * @param string $url URL.
	 */
	private function query( string $url ): array {
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $q );
		return $q;
	}

	/**
	 * The guest link in the completed email and on the order received page
	 * downloads Pro without an account.
	 */
	public function test_guest_gets_a_working_download_link(): void {
		$this->add_release( '9.1.0' );
		$order = $this->guest_order();
		$lic   = $this->licenses_of_order( $order->get_id() )[0];
		$this->assertSame( 0, (int) $lic['user_id'] );

		$mail = array_values( array_filter( $this->mails, static fn( $m ) => $m['to'] === $order->get_billing_email() ) );
		$this->assertNotEmpty( $mail, 'The completed email went to the guest.' );
		$this->assertMatchesRegularExpression( '/href="([^"]*sfls_guest=[^"]+)"/', $mail[0]['message'] );
		preg_match( '/href="([^"]*sfls_guest=[^"]+)"/', $mail[0]['message'], $m );
		$q = $this->query( $m[1] );
		$this->assertSame( (int) $lic['id'], (int) $q['sfls_guest'] );
		$this->assertStringNotContainsString( 'pro-downloads', $mail[0]['message'], 'No login-only link for guests.' );

		// Valid for 30 days, tied to the license.
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, (int) $q['expires'], 5 );
		$this->assertNotNull( SFLS_Guest::verify( (int) $q['sfls_guest'], (int) $q['expires'], $q['sig'] ) );
		$this->assertNull( SFLS_Guest::verify( (int) $q['sfls_guest'], (int) $q['expires'] + 1, $q['sig'] ), 'Expiry is signed.' );
		$other = $this->make_license();
		$this->assertNull( SFLS_Guest::verify( (int) $other['id'], (int) $q['expires'], $q['sig'] ), 'Bound to the license.' );
		$past = time() - 1;
		$this->assertNull( SFLS_Guest::verify( (int) $lic['id'], $past, $this->query( SFLS_Guest::url( $lic, $past ) )['sig'] ), 'Expired.' );

		// Order received page: Download Pro goes straight to the zip.
		$panel = SFLS_Delivery::panel_html( $order );
		$this->assertStringContainsString( 'sfls_guest=' . $lic['id'], $panel );
		$this->assertStringContainsString( 'download=1', $panel );

		// Opening the link signed out leads to a signed download of 9.1.0.
		$_GET = array_merge( $_GET, $q, array( 'download' => '1' ) );
		$this->filter(
			'wp_redirect',
			static function ( $location ) {
				throw new SFLS_Test_Stop( $location );
			}
		);
		try {
			SFLS_Guest::handle();
			$this->fail( 'Expected a redirect.' );
		} catch ( SFLS_Test_Stop $stop ) {
			$location = (string) $stop->data;
		}
		$this->assertStringContainsString( '/sfls/v1/update/download', $location );
		$this->filter(
			'sfls_release_downloaded',
			static function ( $release, $l, $domain ) {
				throw new SFLS_Test_Stop( array( $release['version'], (int) $l['id'], $domain ) );
			},
			10,
			3
		);
		try {
			$this->rest( 'GET', '/sfls/v1/update/download', $this->query( $location ) );
			$this->fail( 'Expected the zip.' );
		} catch ( SFLS_Test_Stop $stop ) {
			$this->assertSame( array( '9.1.0', (int) $lic['id'], 'guest' ), $stop->data );
		}

		// A regenerated key kills old links.
		SFLS_Licenses::regenerate_key( (int) $lic['id'] );
		$this->assertNull( SFLS_Guest::verify( (int) $q['sfls_guest'], (int) $q['expires'], $q['sig'] ) );
	}

	/**
	 * A signed-in visitor with the link can add the license to the account;
	 * signed out, the page offers to sign in but needs no account.
	 */
	public function test_guest_link_claims_the_license(): void {
		$order = $this->guest_order();
		$lic   = $this->licenses_of_order( $order->get_id() )[0];
		$self  = SFLS_Guest::url( $lic );

		$html = SFLS_Guest::page_html( $lic, $self );
		$this->assertStringContainsString( $lic['license_key'], $html );
		$this->assertStringContainsString( 'Sign in', $html );
		$this->assertStringNotContainsString( 'Add to my account', $html );

		$user = $this->make_user();
		wp_set_current_user( $user->ID );
		$this->assertStringContainsString( 'Add to my account', SFLS_Guest::page_html( $lic, $self ) );
		$this->assertSame( 1, SFLS_Guest::claim( $lic, $user ) );
		$this->assertSame( (int) $user->ID, (int) SFLS_Licenses::find( (int) $lic['id'] )['user_id'] );
		$this->assertCount( 1, SFLS_Licenses::for_user( $user ) );

		// Nobody can take it over with the same link afterwards.
		$other = $this->make_user();
		$this->assertSame( 0, SFLS_Guest::claim( SFLS_Licenses::find( (int) $lic['id'] ), $other ) );
		$this->assertSame( (int) $user->ID, (int) SFLS_Licenses::find( (int) $lic['id'] )['user_id'] );
	}

	/**
	 * Orders of customers with an account keep the members area link.
	 */
	public function test_account_orders_keep_the_account_link(): void {
		$this->add_release( '9.1.0' );
		$user  = $this->make_user();
		$order = $this->make_order( array( $this->make_license_product() ), $user );
		$order->payment_complete( 'ch_test' );
		$this->assertSame( '', SFLS_Delivery::guest_link( wc_get_order( $order->get_id() ) ) );
		$this->assertStringContainsString( 'pro-downloads', SFLS_Delivery::download_link( true, wc_get_order( $order->get_id() ) ) );
	}
}
