<?php
/**
 * Items 3 and 4: feature labels and the "Coming soon" fallback.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Plan labels, latest version, no-release notice.
 */
class SFLS_Plans_And_Version_Test extends SFLS_Test_Case {

	/**
	 * Every feature slug of every plan has a label.
	 */
	public function test_every_feature_slug_has_a_label(): void {
		$labels = SFLS_Plans::feature_labels();
		foreach ( SFLS_Plans::all() as $slug => $plan ) {
			foreach ( $plan['features'] as $feature ) {
				$this->assertNotEmpty( $labels[ $feature ] ?? '', "$slug: $feature has no label" );
			}
		}
	}

	/**
	 * The wizard line never has stray separators, even with an empty label.
	 */
	public function test_feature_line_has_no_stray_separators(): void {
		$this->filter(
			'sfls_feature_labels',
			static function ( $labels ) {
				$labels['webhooks'] = ''; // Simulate a missing label.
				return $labels;
			}
		);
		foreach ( array_keys( SFLS_Plans::all() ) as $plan ) {
			$line = SFLS_Plans::feature_line( $plan );
			$this->assertDoesNotMatchRegularExpression( '/(^\s*·)|(·\s*$)|(·\s*·)|(,\s*,)|(,\s*$)/u', $line, $plan . ': ' . $line );
		}
		$this->assertSame( 'CSV export of entries', SFLS_Plans::feature_line( 'starter' ) );
	}

	/**
	 * Pricing cards list conditional logic once (it is a common item).
	 */
	public function test_pricing_cards_do_not_repeat_common_items(): void {
		$pro = SFLS_Plans::feature_label_list( 'pro', true );
		$this->assertNotContains( 'Advanced conditional logic', $pro );
		$this->assertContains( 'Webhooks with retries', $pro );
		$html = ( new SFLS_Shortcodes() )->pricing( array() );
		$this->assertStringNotContainsString( '<li></li>', $html );
		$cards = preg_match_all( '/<div class="sfls-plan[ "]/', $html );
		$this->assertGreaterThan( 0, $cards );
		$this->assertSame( $cards, substr_count( $html, 'Advanced conditional logic and conditional emails' ), 'Once per card.' );
	}

	/**
	 * With no release the shortcode, block and dashboard say "Coming soon".
	 */
	public function test_latest_version_fallback(): void {
		$this->isolate_releases();
		$this->assertSame( 'Coming soon', do_shortcode( '[sfls_latest_version]' ) );
		$this->assertStringContainsString( 'Coming soon', ( new SFLS_Blocks() )->render_latest_version( array() ) );

		$user = $this->make_user();
		wp_set_current_user( $user->ID );
		ob_start();
		( new SFLS_Account() )->dashboard();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Coming soon', $html );
		$this->assertStringNotContainsString( '–', $html );
	}

	/**
	 * A real version is shown as is.
	 */
	public function test_latest_version_when_released(): void {
		$this->add_release( '9.8.7' );
		$this->assertSame( '9.8.7', do_shortcode( '[sfls_latest_version]' ) );
	}

	/**
	 * Every Licenses screen warns while there is nothing to download.
	 */
	public function test_admin_notice_without_release(): void {
		wp_set_current_user( 1 );
		$this->isolate_releases();
		$admin = new SFLS_Admin();
		foreach ( array( 'page_settings', 'page_products', 'page_new', 'page_release' ) as $page ) {
			ob_start();
			$admin->$page();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'Customers cannot download Pro yet.', $html, $page );
			$this->assertStringContainsString( 'is-dismissible', $html, $page );
		}
	}

	/**
	 * No warning once a package exists.
	 */
	public function test_no_admin_notice_with_release(): void {
		$this->add_release( '9.9.0' ); // Its own package, whatever the site has.
		wp_set_current_user( 1 );
		ob_start();
		( new SFLS_Admin() )->page_settings();
		$this->assertStringNotContainsString( 'Customers cannot download Pro yet.', (string) ob_get_clean() );
	}
}
