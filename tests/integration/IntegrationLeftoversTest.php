<?php
/**
 * Domain normalisation before seat checks, the uniform anonymous trial
 * eligibility answer and the uninstall list.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Leftovers after merging the business rules and the signing work.
 */
class SFLS_Integration_Leftovers_Test extends SFLS_Test_Case {
	use SFLS_Paddle_Helpers;

	/**
	 * Case, a trailing dot, www and URL forms all give one host.
	 */
	public function test_normalize_domain(): void {
		foreach ( array( 'Example.COM', 'example.com.', 'www.example.com', 'WWW.Example.Com.', 'https://www.EXAMPLE.com./path?x=1', ' example.com ' ) as $in ) {
			$this->assertSame( 'example.com', SFLS_Licenses::normalize_domain( $in ), $in );
		}
		$this->assertSame( 'shop.example.com', SFLS_Licenses::normalize_domain( 'https://Shop.Example.com' ) );
		$this->assertSame( '', SFLS_Licenses::normalize_domain( '' ) );
	}

	/**
	 * An internationalised name is converted to ASCII when intl is there and
	 * left as is when it is not.
	 */
	public function test_normalize_domain_idn(): void {
		$out = SFLS_Licenses::normalize_domain( 'https://www.Bücher.example/' );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$this->assertSame( 'xn--bcher-kva.example', $out );
		} else {
			$this->assertSame( 'bücher.example', $out );
		}
	}

	/**
	 * Spelling variants of one site take one seat, not several.
	 */
	public function test_variants_share_one_seat(): void {
		$lic = $this->make_license( array( 'sites_allowed' => 1 ) );
		$this->assertNotInstanceOf( WP_Error::class, SFLS_Licenses::activate_domain( $lic, 'Customer-Shop.com.' ) );
		foreach ( array( 'www.customer-shop.com', 'CUSTOMER-SHOP.COM', 'customer-shop.com.' ) as $variant ) {
			$this->assertNotInstanceOf( WP_Error::class, SFLS_Licenses::activate_domain( $lic, $variant ), $variant );
		}
		$this->assertCount( 1, SFLS_Licenses::activations( (int) $lic['id'] ) );
		$other = SFLS_Licenses::activate_domain( SFLS_Licenses::find( (int) $lic['id'] ), 'other-shop.com' );
		$this->assertInstanceOf( WP_Error::class, $other, 'The one seat is taken.' );
	}

	/**
	 * An anonymous caller learns nothing about earlier trials; a signed-in
	 * customer still gets the real answer.
	 */
	public function test_anonymous_eligibility_is_uniform(): void {
		$this->paddle();
		$used = 'used' . wp_generate_password( 6, false ) . '@' . self::DOMAIN;
		$lic  = $this->make_license( array( 'billing' => 'paddle', 'trial' => 1, 'trial_started_at' => current_time( 'mysql', true ) ) );
		SFLS_Trials::record_start( (int) $lic['id'], $used );
		$fresh = 'fresh' . wp_generate_password( 6, false ) . '@' . self::DOMAIN;

		$a = $this->rest( 'POST', '/sfls/v1/paddle/eligibility', array( 'email' => $used, 'plan' => 'pro' ) )->get_data();
		$b = $this->rest( 'POST', '/sfls/v1/paddle/eligibility', array( 'email' => $fresh, 'plan' => 'pro' ) )->get_data();
		$this->assertTrue( $a['eligible'] );
		$this->assertTrue( $b['eligible'] );
		$this->assertSame( $a['reason'], $b['reason'] );

		$bad = $this->rest( 'POST', '/sfls/v1/paddle/eligibility', array( 'email' => 'nope', 'plan' => 'pro' ) )->get_data();
		$this->assertSame( 'invalid_email', $bad['reason'] );
	}

	/**
	 * Uninstall drops the rate limit and code tables and the signature options.
	 */
	public function test_uninstall_lists_new_data(): void {
		$source = (string) file_get_contents( SFLS_DIR . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( array( 'sfls_rate_limits', 'sfls_trial_codes' ) as $table ) {
			$this->assertStringContainsString( '{$wpdb->prefix}' . $table . '"', $source, $table );
		}
		$this->assertStringContainsString( "'sfls_release_signatures'", $source );
	}
}
