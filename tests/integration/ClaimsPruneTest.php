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
	/**
	 * A claim replaced after the cleanup read the row is not deleted.
	 */
	public function test_replaced_claim_survives(): void {
		global $wpdb;
		$user = $this->make_user()->ID;
		update_user_meta( $user, SFLS_Claims::META, array( 'expires' => time() - DAY_IN_SECONDS ) );
		$fresh = array( 'expires' => time() + DAY_IN_SECONDS );
		// Simulate SFLS_Claims::send() replacing the row between the cleanup's read and delete.
		$swap = static function ( $query ) use ( $wpdb, $user, $fresh, &$swap ) {
			if ( is_string( $query ) && 0 === strpos( ltrim( $query ), 'DELETE FROM ' . $wpdb->usermeta ) && false !== strpos( $query, 'umeta_id' ) ) {
				remove_filter( 'query', $swap );
				update_user_meta( $user, SFLS_Claims::META, $fresh );
			}
			return $query;
		};
		add_filter( 'query', $swap );
		SFLS_Claims::prune_expired();
		remove_filter( 'query', $swap );
		$this->assertSame( $fresh, get_user_meta( $user, SFLS_Claims::META, true ) );
	}
}
