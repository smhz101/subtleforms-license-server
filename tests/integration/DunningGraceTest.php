<?php
/**
 * A failed Paddle payment keeps Pro on while Paddle retries.
 *
 * @package SFLS
 */

/**
 * Dunning grace.
 */
class SFLS_Dunning_Grace_Test extends SFLS_Test_Case {

	use SFLS_Paddle_Helpers;

	/**
	 * A past_due Paddle license that expired $days_ago days ago.
	 *
	 * @param int $days_ago Days since expiry.
	 */
	private function past_due( int $days_ago ): array {
		$sub = $this->subscription( array( 'status' => 'past_due' ) );
		$lic = $this->make_license( array( 'months' => 12 ) );
		SFLS_Licenses::update(
			(int) $lic['id'],
			array(
				'billing'                => 'paddle',
				'billing_status'         => 'past_due',
				'paddle_subscription_id' => $sub['id'],
				'expires_at'             => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS - HOUR_IN_SECONDS ),
			)
		);
		return SFLS_Licenses::find( (int) $lic['id'] );
	}

	/**
	 * Valid through the dunning window, not after it.
	 */
	public function test_past_due_stays_valid_for_dunning_days(): void {
		$this->paddle();
		$this->settings( array( 'dunning_grace_days' => 30 ) );
		foreach ( array( 1, 13, 20, 29 ) as $days ) {
			$this->assertTrue( SFLS_Licenses::state( $this->past_due( $days ) )['valid'], "+{$days} days" );
		}
		$this->assertFalse( SFLS_Licenses::state( $this->past_due( 31 ) )['valid'], '+31 days' );
	}

	/**
	 * 0 falls back to the generic 14 day grace.
	 */
	public function test_zero_setting_keeps_old_behaviour(): void {
		$this->paddle();
		$this->settings( array( 'dunning_grace_days' => 0 ) );
		$this->assertTrue( SFLS_Licenses::state( $this->past_due( 13 ) )['valid'] );
		$this->assertFalse( SFLS_Licenses::state( $this->past_due( 20 ) )['valid'] );
	}

	/**
	 * Only past_due Paddle licenses get the longer window.
	 */
	public function test_active_license_is_unchanged(): void {
		$this->paddle();
		$lic = $this->past_due( 20 );
		SFLS_Licenses::update( (int) $lic['id'], array( 'billing_status' => 'active' ) );
		$this->assertFalse( SFLS_Licenses::state( SFLS_Licenses::find( (int) $lic['id'] ) )['valid'] );
	}

	/**
	 * The setting is clamped to 0..60.
	 */
	public function test_setting_is_sanitised(): void {
		SFLS_Settings::save( array( 'dunning_grace_days' => '500' ) );
		$this->assertSame( 60, SFLS_Settings::dunning_grace_days() );
		SFLS_Settings::save( array( 'dunning_grace_days' => '-4' ) );
		$this->assertSame( 4, SFLS_Settings::dunning_grace_days() );
	}

	/**
	 * The API tells Pro the license is past due.
	 */
	public function test_payload_reports_past_due(): void {
		$this->paddle();
		$payload = SFLS_Licenses::payload( $this->past_due( 5 ), 'example.com' );
		$this->assertSame( 'past_due', $payload['billing_status'] );
		$this->assertTrue( $payload['valid'] );
	}

	/**
	 * My Account shows the notice and keeps the update card button.
	 */
	public function test_account_shows_past_due_notice(): void {
		$this->paddle();
		$user = $this->make_user();
		$lic  = $this->past_due( 2 );
		SFLS_Licenses::update( (int) $lic['id'], array( 'user_id' => (int) $user->ID ) );
		wp_set_current_user( $user->ID );
		ob_start();
		( new SFLS_Account() )->licenses_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Your last payment failed. Update your card to keep Pro working.', $html );
		$this->assertStringContainsString( 'value="payment"', $html );
	}
}
