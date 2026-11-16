<?php
/**
 * Pruning expired claims.
 *
 * @package SFLS
 */

/**
 * Claim cleanup.
 */
class SFLS_Claims_Prune_Test extends SFLS_Test_Case {

	/**
	 * Fresh kept, old and legacy removed.
	 */
	public function test_prune_expired(): void {
		$fresh  = $this->make_user();
		$old    = $this->make_user();
		$legacy = $this->make_user();
		update_user_meta( $fresh->ID, SFLS_Claims::META, array( 'hash' => 'a', 'email' => 'a@example.com', 'expires' => time() + DAY_IN_SECONDS ) );
		update_user_meta( $old->ID, SFLS_Claims::META, array( 'hash' => 'a', 'email' => 'a@example.com', 'expires' => time() - DAY_IN_SECONDS ) );
		update_user_meta( $legacy->ID, SFLS_Claims::META, array( 'hash' => 'a', 'email' => 'a@example.com' ) );
		SFLS_Claims::prune_expired();
		$this->assertNotEmpty( get_user_meta( $fresh->ID, SFLS_Claims::META, true ) );
		$this->assertEmpty( get_user_meta( $old->ID, SFLS_Claims::META, true ) );
		$this->assertEmpty( get_user_meta( $legacy->ID, SFLS_Claims::META, true ) );
	}
}
