<?php
/**
 * 1.9.1: CLI user lookup (L1), local trial code bypass (L5), renewal date
 * hidden until the subscription is active (L4).
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

require_once SFLS_DIR . 'includes/class-sfls-cli.php';

/**
 * 1.9.1 fixes.
 */
class SFLS_Qa_Fixes_Test extends SFLS_Test_Case {

	/**
	 * A user is found by ID, login or email, and unknown values fail.
	 */
	public function test_find_user_accepts_id_login_and_email(): void {
		$user = $this->make_user();
		$this->assertSame( $user->ID, SFLS_CLI::find_user( (string) $user->ID )->ID );
		$this->assertSame( $user->ID, SFLS_CLI::find_user( $user->user_login )->ID );
		$this->assertSame( $user->ID, SFLS_CLI::find_user( $user->user_email )->ID );
		$this->assertFalse( SFLS_CLI::find_user( 'no-such-user-' . wp_generate_password( 6, false ) ) );
		$this->assertFalse( SFLS_CLI::find_user( '' ) );
	}

	/**
	 * The code is skipped only on local and development by default, and the filter wins.
	 */
	public function test_trial_code_skip_follows_environment_and_filter(): void {
		$expected = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		$this->assertSame( $expected, SFLS_Trials::skip_code() );

		add_filter( 'sfls_trial_skip_email_code', '__return_false' );
		$this->assertFalse( SFLS_Trials::skip_code() );
		remove_filter( 'sfls_trial_skip_email_code', '__return_false' );
	}

	/**
	 * A Paddle license shows its renewal date only once the subscription is active.
	 */
	public function test_renewal_date_waits_for_active_subscription(): void {
		$base = array(
			'billing'                => 'paddle',
			'billing_status'         => 'active',
			'paddle_subscription_id' => '',
		);
		$this->assertFalse( SFLS_Licenses::renewal_confirmed( $base ) );
		$this->assertTrue( SFLS_Licenses::renewal_confirmed( array_merge( $base, array( 'paddle_subscription_id' => 'sub_01' ) ) ) );
		$this->assertTrue( SFLS_Licenses::renewal_confirmed( array( 'billing' => 'manual' ) ) );
	}
}
