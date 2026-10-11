<?php
/**
 * Item 11: wizard mapping chips and no duplicates.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tests fire WooCommerce hooks on purpose.

/**
 * Products wizard.
 */
class SFLS_Products_Wizard_Test extends SFLS_Test_Case {

	/**
	 * Count license products.
	 */
	private function count_products(): int {
		SFLS_Products::flush();
		return count( SFLS_Products::all() );
	}

	/**
	 * Saving twice, or with the mapping option lost, creates nothing.
	 */
	public function test_saving_never_duplicates(): void {
		$this->set_option( SFLS_Products::OPTION, get_option( SFLS_Products::OPTION ) );
		$before = $this->count_products();
		$values = SFLS_Products::wizard_values();

		SFLS_Products::save_wizard( $values );
		$this->assertSame( $before, $this->count_products() );

		delete_option( SFLS_Products::OPTION );
		SFLS_Products::flush();
		$result = SFLS_Products::save_wizard( $values );
		$this->assertSame( 0, $result['created'] );
		$this->assertSame( $before, $this->count_products() );
	}

	/**
	 * A double-clicked Save is ignored while the first one runs.
	 */
	public function test_double_submit_is_ignored(): void {
		set_transient( 'sfls_wizard_lock', 1, 30 );
		$result = SFLS_Products::save_wizard( SFLS_Products::wizard_values() );
		delete_transient( 'sfls_wizard_lock' );
		$this->assertSame( array( 'created' => 0, 'updated' => 0 ), $result );
	}

	/**
	 * The mapping reports the product and status of every price.
	 */
	public function test_mapping_statuses(): void {
		$map     = SFLS_Products::map();
		$mapping = SFLS_Products::mapping();
		$this->assertSame( 'publish', $mapping['pro']['yearly']['status'] );
		$this->assertSame( (int) $map['pro']['yearly'], $mapping['pro']['yearly']['id'] );
		$this->assertNotSame( '', $mapping['pro']['yearly']['name'] );

		// A deleted product shows as Missing.
		$ghost = $this->make_license_product( 'agency' );
		$this->set_option( SFLS_Products::OPTION, array_replace_recursive( $map, array( 'agency' => array( 'renewal' => $ghost->get_id() ) ) ) );
		wp_delete_post( $ghost->get_id(), true );
		SFLS_Products::flush();
		$this->assertSame( 'missing', SFLS_Products::mapping()['agency']['renewal']['status'] );
	}

	/**
	 * The screen shows a chip with a link per price.
	 */
	public function test_screen_shows_chips(): void {
		wp_set_current_user( 1 );
		ob_start();
		( new SFLS_Admin() )->page_products();
		$html = (string) ob_get_clean();
		$this->assertGreaterThanOrEqual( 9, substr_count( $html, 'class="sfls-map"' ) );
		$this->assertStringContainsString( 'sfls-admin-pill--ok">Published', $html );
		$this->assertStringContainsString( 'post.php?post=', $html );
		$this->assertStringNotContainsString( ', ,', $html );
	}
}
