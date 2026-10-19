<?php
/**
 * LS-01: licenses belong to a user ID, never to an email address.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * License ownership and verified claims.
 */
class SFLS_Ownership_Test extends SFLS_Test_Case {

	/**
	 * A guest order for an address.
	 *
	 * @param string $email Billing email.
	 * @return array{0:WC_Order,1:array} Order and its license.
	 */
	private function guest_purchase( string $email ): array {
		$order = $this->make_order( array( $this->make_license_product( 'starter' ) ) );
		$order->set_billing_email( $email );
		$order->save();
		$order->payment_complete( 'ch_test' );
		$licenses = $this->licenses_of_order( $order->get_id() );
		$this->assertCount( 1, $licenses );
		return array( wc_get_order( $order->get_id() ), $licenses[0] );
	}

	/**
	 * The claim token in the last claim email.
	 */
	private function claim_token(): string {
		foreach ( array_reverse( $this->mails ) as $mail ) {
			if ( preg_match( '/sfls_claim=([a-f0-9]{64})/', (string) $mail['message'], $m ) ) {
				return $m[1];
			}
		}
		$this->fail( 'No claim email was sent.' );
	}

	/**
	 * The repro: another customer changes their account email to the guest
	 * buyer's address and must not see, renew or connect the license.
	 */
	public function test_changed_account_email_does_not_reveal_a_guest_license(): void {
		$victim = 'victim' . wp_generate_password( 5, false, false ) . '@' . self::DOMAIN;
		[ , $lic ] = $this->guest_purchase( $victim );
		$this->assertSame( 0, (int) $lic['user_id'] );

		$other = $this->make_user();
		wp_update_user(
			array(
				'ID'         => $other->ID,
				'user_email' => $victim,
			)
		);
		$other = get_userdata( $other->ID );

		$this->assertFalse( SFLS_Licenses::user_owns( $lic, $other ) );
		$this->assertSame( array(), SFLS_Licenses::for_user( $other ) );
		$this->assertNull( SFLS_Account::license_for_release( $other, array( 'status' => 'published', 'released_at' => current_time( 'mysql', true ) ) ) );

		wp_set_current_user( $other->ID );
		ob_start();
		( new SFLS_Account() )->licenses_page();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( $lic['license_key'], $html );
		$this->assertStringNotContainsString( 'Deactivate', $html );
		$this->assertStringContainsString( 'Email me a confirmation link', $html, 'Only a verified claim can add it.' );
	}

	/**
	 * An account that already has the buyer's address is not linked when a
	 * guest order completes.
	 */
	public function test_guest_order_is_not_linked_by_email(): void {
		$user = $this->make_user();
		[ , $lic ] = $this->guest_purchase( $user->user_email );
		$this->assertSame( 0, (int) $lic['user_id'] );
		$this->assertFalse( SFLS_Licenses::user_owns( $lic, $user ) );

		// A license created by hand for that address is not linked either.
		$manual = $this->make_license( array( 'customer_email' => $user->user_email ) );
		$this->assertSame( 0, (int) $manual['user_id'] );
	}

	/**
	 * Signing in sends a one-time link to the account's address; only that
	 * account, still holding that address, can use it, once.
	 */
	public function test_claim_needs_the_emailed_link(): void {
		$email = 'buyer' . wp_generate_password( 5, false, false ) . '@' . self::DOMAIN;
		[ , $lic ] = $this->guest_purchase( $email );

		$user = $this->make_user( 'buyer' . wp_generate_password( 4, false, false ) );
		wp_update_user(
			array(
				'ID'         => $user->ID,
				'user_email' => $email,
			)
		);
		$user        = get_userdata( $user->ID );
		$this->mails = array();
		do_action( 'wp_login', $user->user_login, $user );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( $email, $this->mails[0]['to'] );
		$token = $this->claim_token();

		// Still unclaimed until the link is opened.
		$this->assertSame( 0, (int) SFLS_Licenses::find( (int) $lic['id'] )['user_id'] );

		// Another signed-in account cannot use the link.
		$intruder = $this->make_user();
		$this->assertInstanceOf( WP_Error::class, SFLS_Claims::claim( $intruder, (int) $user->ID, $token ) );
		$this->assertSame( 0, (int) SFLS_Licenses::find( (int) $lic['id'] )['user_id'] );

		// A wrong token fails; the right one links the license once.
		$this->assertInstanceOf( WP_Error::class, SFLS_Claims::claim( $user, (int) $user->ID, str_repeat( 'a', 64 ) ) );
		do_action( 'wp_login', $user->user_login, $user ); // A second sign-in does not send again today.
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 1, SFLS_Claims::claim( $user, (int) $user->ID, $token ) );
		$this->assertSame( (int) $user->ID, (int) SFLS_Licenses::find( (int) $lic['id'] )['user_id'] );
		$this->assertInstanceOf( WP_Error::class, SFLS_Claims::claim( $user, (int) $user->ID, $token ), 'One use.' );
		$this->assertCount( 1, SFLS_Licenses::for_user( $user ) );
	}

	/**
	 * A link sent before the account email changed does not work after.
	 */
	public function test_claim_link_is_tied_to_the_address(): void {
		$email = 'moved' . wp_generate_password( 5, false, false ) . '@' . self::DOMAIN;
		[ , $lic ] = $this->guest_purchase( $email );
		$user = $this->make_user();
		wp_update_user(
			array(
				'ID'         => $user->ID,
				'user_email' => $email,
			)
		);
		$user = get_userdata( $user->ID );
		$this->assertTrue( SFLS_Claims::send( $user ) );
		$token = $this->claim_token();
		wp_update_user(
			array(
				'ID'         => $user->ID,
				'user_email' => 'elsewhere' . wp_generate_password( 5, false, false ) . '@' . self::DOMAIN,
			)
		);
		$this->assertInstanceOf( WP_Error::class, SFLS_Claims::claim( get_userdata( $user->ID ), (int) $user->ID, $token ) );
		$this->assertSame( 0, (int) SFLS_Licenses::find( (int) $lic['id'] )['user_id'] );
	}

	/**
	 * Changing an account email never moves a license.
	 */
	public function test_email_change_keeps_the_owner(): void {
		$owner = $this->make_user();
		$old   = $owner->user_email;
		$lic   = $this->make_license(
			array(
				'customer_email' => $old,
				'user_id'        => $owner->ID,
			)
		);
		wp_update_user(
			array(
				'ID'         => $owner->ID,
				'user_email' => 'new' . wp_generate_password( 5, false, false ) . '@' . self::DOMAIN,
			)
		);
		$this->assertTrue( SFLS_Licenses::user_owns( $lic, get_userdata( $owner->ID ) ) );

		$taker = $this->make_user();
		wp_update_user(
			array(
				'ID'         => $taker->ID,
				'user_email' => $old,
			)
		);
		$taker = get_userdata( $taker->ID );
		$this->assertFalse( SFLS_Licenses::user_owns( $lic, $taker ) );
		$this->assertFalse( SFLS_Claims::send( $taker ), 'A claimed license is never offered again.' );
	}

	/**
	 * The one-time migration ties licenses to their order's customer.
	 */
	public function test_migration_uses_the_order_customer(): void {
		global $wpdb;
		$buyer    = $this->make_user();
		$stranger = $this->make_user();

		// Guest order whose license an old version linked to a same-email account.
		[ , $guest ] = $this->guest_purchase( 'g' . wp_generate_password( 5, false, false ) . '@' . self::DOMAIN );
		$wpdb->update( SFLS_DB::licenses(), array( 'user_id' => $stranger->ID ), array( 'id' => (int) $guest['id'] ) );

		// Customer order whose license has no owner.
		$order = $this->make_order( array( $this->make_license_product( 'pro' ) ), $buyer );
		$order->payment_complete( 'ch_test' );
		$own = $this->licenses_of_order( $order->get_id() )[0];
		$wpdb->update( SFLS_DB::licenses(), array( 'user_id' => 0 ), array( 'id' => (int) $own['id'] ) );

		// A manual license keeps its owner.
		$manual = $this->make_license( array( 'user_id' => $stranger->ID ) );
		$before = SFLS_Licenses::find( (int) $own['id'] )['updated_at'];

		$this->set_option( SFLS_DB::OWNERS_MIGRATED, null );
		$this->assertGreaterThanOrEqual( 2, SFLS_DB::migrate_owners() );
		$this->assertSame( 0, (int) SFLS_Licenses::find( (int) $guest['id'] )['user_id'], 'Guest licenses stay unlinked until claimed.' );
		$this->assertSame( (int) $buyer->ID, (int) SFLS_Licenses::find( (int) $own['id'] )['user_id'] );
		$this->assertSame( (int) $stranger->ID, (int) SFLS_Licenses::find( (int) $manual['id'] )['user_id'] );
		$this->assertSame( $before, SFLS_Licenses::find( (int) $own['id'] )['updated_at'], 'Dates are not touched.' );
		$this->assertNotEmpty( get_option( SFLS_DB::OWNERS_MIGRATED ) );
		$this->assertSame( '6', SFLS_DB::SCHEMA_VERSION );
	}
}
