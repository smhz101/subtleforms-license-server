<?php
/**
 * LS-17: renewal reminders are not capped at 500 per window.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Reminder paging.
 */
class SFLS_Reminder_Paging_Test extends SFLS_Test_Case {

	/**
	 * The repro: with more than 500 licenses expiring within 30 days, a dry
	 * run reported 499 due and the rest went out on later days. Every due
	 * license is counted now, and a real run marks every one of them.
	 */
	public function test_all_due_reminders_are_counted(): void {
		global $wpdb;
		$this->settings( array( 'reminder_days' => '30,7', 'lapsed_reminder' => 0 ) );
		$before = SFLS_Reminders::run( true );

		$count   = SFLS_Licenses::PAGE + 25;
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
		$tag     = strtolower( wp_generate_password( 6, false ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'sfls_licenses',
				array(
					'license_key'    => 'SFLS-PAGE-' . $tag . '-' . $i,
					'plan'           => 'pro',
					'status'         => 'active',
					'sites_allowed'  => 1,
					'customer_email' => "page{$i}-{$tag}@" . self::DOMAIN,
					'expires_at'     => $expires,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			);
		}

		$this->assertSame( $before + $count, SFLS_Reminders::run( true ), 'Every due license is counted.' );

		if ( 0 !== $before ) {
			$this->markTestIncomplete( 'The site has its own licenses due; the sending half runs only on a site without them.' );
		}
		$this->mails = array();
		SFLS_Reminders::run();
		$like    = '%' . $wpdb->esc_like( $tag . '@' . self::DOMAIN );
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE customer_email LIKE %s AND ( reminders IS NULL OR reminders = '' )", $like ) );
		$this->assertSame( 0, $pending, 'Every license got its reminder today.' );
		$this->assertGreaterThanOrEqual( $count, count( $this->mails ) );
		$this->assertSame( 0, SFLS_Reminders::run( true ), 'Nothing is due twice.' );
	}
}
