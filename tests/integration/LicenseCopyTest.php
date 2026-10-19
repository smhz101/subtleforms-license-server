<?php
/**
 * LS-11: order emails and pages talk about a digital license.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests run WooCommerce filters on purpose.

/**
 * License order copy.
 */
class SFLS_License_Copy_Test extends SFLS_Test_Case {

	/**
	 * A renewal product.
	 */
	private function renewal_product(): WC_Product_Simple {
		$product = $this->make_license_product( 'pro', 12, '39' );
		$product->update_meta_data( '_sfls_renewal', 'yes' );
		$product->save();
		return $product;
	}

	/**
	 * The repro, part one: the completed email of a license order used the
	 * WooCommerce subject "Your order from SubtleForms is on its way!".
	 */
	public function test_completed_subject_fits_a_license(): void {
		$user    = $this->make_user();
		$lic     = $this->make_license( array( 'user_id' => $user->ID, 'customer_email' => $user->user_email ) );
		$new     = $this->make_order( array( $this->make_license_product() ), $user );
		$renewal = $this->make_order( array( $this->renewal_product() ), $user, array( array( '_sfls_renew_license' => (int) $lic['id'] ) ) );
		$mixed   = $this->make_order( array( $this->make_license_product(), $this->make_physical_product() ), $user );
		$default = 'Your order from SubtleForms is on its way!';

		$this->assertSame( 'new', SFLS_Woo::order_kind( $new ) );
		$this->assertSame( 'renewal', SFLS_Woo::order_kind( $renewal ) );
		$this->assertSame( 'Your SubtleForms Pro license key', apply_filters( 'woocommerce_email_subject_customer_completed_order', $default, $new, null ) );
		$this->assertSame( 'Your SubtleForms Pro license is renewed', apply_filters( 'woocommerce_email_subject_customer_completed_order', $default, $renewal, null ) );
		$this->assertSame( $default, apply_filters( 'woocommerce_email_subject_customer_completed_order', $default, $mixed, null ), 'Orders with shipped goods keep the WooCommerce subject.' );
	}

	/**
	 * The repro, part two: the renewal thank-you page said "Your license key
	 * appears here as soon as your payment is confirmed." A renewal keeps
	 * its key, so the page says the license is renewed.
	 */
	public function test_unpaid_renewal_page_keeps_the_key(): void {
		$user    = $this->make_user();
		$lic     = $this->make_license( array( 'user_id' => $user->ID, 'customer_email' => $user->user_email ) );
		$renewal = $this->make_order( array( $this->renewal_product() ), $user, array( array( '_sfls_renew_license' => (int) $lic['id'] ) ) );
		$html    = SFLS_Delivery::panel_html( $renewal );

		$this->assertStringContainsString( 'Your license is renewed as soon as your payment is confirmed. Your key stays the same', $html );
		$this->assertStringNotContainsString( 'license key appears here', $html );
		$this->assertStringContainsString( 'once it is paid, with the same key', SFLS_Delivery::waiting_text( $renewal, true ) );
	}
}
