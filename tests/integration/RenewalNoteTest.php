<?php
/**
 * LS-05: the renewal line comes from the products.
 *
 * @package SFLS
 */

/**
 * Renewal line for marketing pages.
 */
class SFLS_Renewal_Note_Test extends SFLS_Test_Case {

	/**
	 * Yearly and renewal products for every plan, mapped as the wizard does.
	 *
	 * @param array<string,array{0:string,1:string}> $prices plan => [ yearly, renewal ].
	 */
	private function products( array $prices ): void {
		$map = array();
		foreach ( $prices as $plan => [ $yearly, $renewal ] ) {
			$y       = $this->make_license_product( $plan, 12, $yearly );
			$r       = $this->make_license_product( $plan, 12, $renewal );
			$r->update_meta_data( '_sfls_renewal', 'yes' );
			$r->save();
			$map[ $plan ] = array(
				'yearly'  => $y->get_id(),
				'renewal' => $r->get_id(),
			);
		}
		$this->set_option( SFLS_Products::OPTION, $map );
		SFLS_Products::flush();
	}

	/**
	 * The repro: the pricing page said "Renew at 30% off: $34, $69 or $139"
	 * while the renewal products cost $39, $79 and $159. The line is now
	 * computed from those products.
	 */
	public function test_line_matches_the_products(): void {
		$this->products(
			array(
				'starter' => array( '49', '39' ),
				'pro'     => array( '99', '79' ),
				'agency'  => array( '199', '159' ),
			)
		);
		$this->assertSame( 'Renew at 20% off: $39.00, $79.00 or $159.00.', SFLS_Products::renewal_summary() );
		$this->assertSame( '<span class="sfls-renewal-note">Renew at 20% off: $39.00, $79.00 or $159.00.</span>', do_shortcode( '[sfls_renewal_note]' ) );
	}

	/**
	 * Different discounts per plan list the prices without a percentage; a
	 * plan without a renewal price prints nothing rather than a guess.
	 */
	public function test_mixed_discounts_and_missing_products(): void {
		$this->products(
			array(
				'starter' => array( '49', '39' ),
				'pro'     => array( '99', '69' ),
				'agency'  => array( '199', '159' ),
			)
		);
		$this->assertSame( 'Renewals: $39.00, $69.00 or $159.00 a year.', SFLS_Products::renewal_summary() );

		$agency = wc_get_product( SFLS_Products::map()['agency']['renewal'] );
		$agency->set_regular_price( '' );
		$agency->save();
		SFLS_Products::flush();
		$this->assertSame( '', SFLS_Products::renewal_summary() );
		$this->assertSame( '', do_shortcode( '[sfls_renewal_note]' ) );
	}
}
