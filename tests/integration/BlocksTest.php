<?php
/**
 * Items 15 and 16: blocks.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Server-rendered blocks.
 */
class SFLS_Blocks_Test extends SFLS_Test_Case {

	/**
	 * The four blocks are registered from block.json with the editor script.
	 */
	public function test_blocks_are_registered(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( array( 'sfls/pricing', 'sfls/changelog', 'sfls/latest-version', 'sfls/account-link' ) as $name ) {
			$block = $registry->get_registered( $name );
			$this->assertNotNull( $block, $name );
			$this->assertTrue( $block->is_dynamic(), $name );
			$this->assertContains( 'sfls-blocks-editor', $block->editor_script_handles, $name );
		}
		$pricing = $registry->get_registered( 'sfls/pricing' );
		foreach ( array( 'popular', 'period', 'showRenewalNote', 'buttonLabel', 'lifetimeButtonLabel' ) as $attr ) {
			$this->assertArrayHasKey( $attr, $pricing->attributes, $attr );
		}
		$this->assertTrue( wp_script_is( 'sfls-blocks-editor', 'registered' ) );
	}

	/**
	 * Pricing controls: highlighted plan, renewal note, default period and
	 * button labels.
	 */
	public function test_pricing_block_controls(): void {
		$html = do_blocks( '<!-- wp:sfls/pricing {"popular":"agency","period":"lifetime","showRenewalNote":true,"buttonLabel":"Get {plan} now","lifetimeButtonLabel":"Own {plan}"} /-->' );
		$this->assertStringContainsString( 'wp-block-sfls-pricing', $html );
		$this->assertMatchesRegularExpression( '/sfls-plan is-popular.*?Agency/s', $html );
		$this->assertStringContainsString( 'Get Pro now', $html );
		$this->assertStringContainsString( 'Own Agency', $html );
		$this->assertStringContainsString( 'Renews at', $html );
		$this->assertStringContainsString( 'Pay once.', $html );
		$this->assertMatchesRegularExpression( '/sfls-period-input--lifetime"[^>]*checked/', $html );

		$plain = do_blocks( '<!-- wp:sfls/pricing {"showRenewalNote":false} /-->' );
		$this->assertStringNotContainsString( 'sfls-plan-renewal', $plain );
		$this->assertStringContainsString( 'Buy Pro', $plain );
	}

	/**
	 * The shortcodes still work and stay as they were by default.
	 */
	public function test_shortcodes_still_work(): void {
		$html = do_shortcode( '[sfls_pricing]' );
		$this->assertStringContainsString( 'sfls-pricing', $html );
		$this->assertStringContainsString( 'Buy Starter', $html );
		$this->assertStringNotContainsString( 'sfls-plan-renewal', $html );
		$this->assertStringContainsString( 'Renews at', do_shortcode( '[sfls_pricing renewal_note="yes"]' ) );
		$this->assertNotSame( '[sfls_changelog]', do_shortcode( '[sfls_changelog]' ) );
		$this->assertNotSame( '', do_shortcode( '[sfls_latest_version]' ) );
	}

	/**
	 * Changelog and latest version blocks.
	 */
	public function test_changelog_and_version_blocks(): void {
		$this->set_option(
			SFLS_Releases::OPTION,
			array(
				'version'   => '3.0.0',
				'changelog' => "* One\n* Two",
				'updated'   => '2026-10-01',
			)
		);
		$this->set_option( SFLS_Releases::HISTORY_OPTION, array( array( 'version' => '2.9.0' ) ) );
		$all = do_blocks( '<!-- wp:sfls/changelog /-->' );
		$this->assertStringContainsString( '3.0.0', $all );
		$this->assertStringContainsString( '2.9.0', $all );
		$one = do_blocks( '<!-- wp:sfls/changelog {"limit":1} /-->' );
		$this->assertStringNotContainsString( '2.9.0', $one );
		$this->assertStringContainsString( 'Version 3.0.0', do_blocks( '<!-- wp:sfls/latest-version {"prefix":"Version"} /-->' ) );
	}

	/**
	 * Account link: Sign in when signed out, My licenses with an avatar when
	 * signed in.
	 */
	public function test_account_link(): void {
		wp_set_current_user( 0 );
		$out = do_blocks( '<!-- wp:sfls/account-link /-->' );
		$this->assertStringContainsString( '>Sign in</a>', $out );
		$this->assertStringContainsString( wc_get_page_permalink( 'myaccount' ), $out );
		$this->assertStringNotContainsString( '<img', $out );

		$user = $this->make_user();
		wp_set_current_user( $user->ID );
		$in = do_blocks( '<!-- wp:sfls/account-link /-->' );
		$this->assertStringContainsString( 'My licenses', $in );
		$this->assertStringContainsString( 'sfls-account-link__avatar', $in );
		$this->assertStringContainsString( 'licenses', $in );
		$this->assertStringNotContainsString( 'Sign in', $in );

		$custom = do_blocks( '<!-- wp:sfls/account-link {"accountLabel":"Account","showAvatar":false} /-->' );
		$this->assertStringContainsString( '>Account</span>', $custom );
		$this->assertStringNotContainsString( '<img', $custom );
	}
}
