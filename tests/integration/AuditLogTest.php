<?php
/**
 * S3: the admin audit log.
 *
 * @package SFLS
 */

/**
 * Audit log.
 */
class SFLS_Audit_Log_Test extends SFLS_Test_Case {

	/**
	 * A license on a private email domain, so other tests' cleanup leaves it alone.
	 *
	 * @param array $args Create args.
	 */
	private function lic( array $args = array() ): array {
		return $this->make_license( $args + array( 'customer_email' => 'c' . wp_generate_password( 6, false ) . '@sfls-audit.example.net' ) );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}sfls_licenses WHERE customer_email LIKE '%@sfls-audit.example.net'" ) as $id ) {
			SFLS_Licenses::delete( (int) $id );
			$wpdb->delete( SFLS_DB::audit_log(), array( 'license_id' => (int) $id ) );
		}
		parent::tearDown();
	}

	/**
	 * Run a handler; return the redirect location, or "die" when wp_die() ran.
	 *
	 * @param callable $handler Handler.
	 * @param array    $post    Request values.
	 * @param int      $user    User ID.
	 * @param string   $method  post | get.
	 */
	private function call( callable $handler, array $post, int $user = 1, string $method = 'post' ): string {
		wp_set_current_user( $user );
		$_REQUEST = $post;
		if ( 'post' === $method ) {
			$_POST = $post;
		} else {
			$_GET = $post;
		}
		$this->filter( 'wp_redirect', static fn( $l ) => throw new RuntimeException( "redirect:$l" ) );
		$this->filter( 'wp_die_handler', static fn() => static function () {
			throw new RuntimeException( 'die' );
		} );
		ob_start();
		try {
			$handler();
			return '';
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			ob_end_clean();
			$_POST = $_GET = $_REQUEST = array();
		}
	}

	public function test_schema_13_creates_table(): void {
		wp_set_current_user( 1 );
		global $wpdb;
		$this->assertSame( '16', SFLS_DB::SCHEMA_VERSION );
		SFLS_DB::install();
		$this->assertSame( SFLS_DB::audit_log(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', SFLS_DB::audit_log() ) ) );
	}

	public function test_editor_save_writes_rows(): void {
		wp_set_current_user( 1 );
		$lic = $this->lic( array( 'sites_allowed' => 1 ) );
		$id  = (int) $lic['id'];
		$this->call(
			array( new SFLS_Admin(), 'handle_save' ),
			array(
				'id'             => $id,
				'_wpnonce'       => wp_create_nonce( 'sfls_save_' . $id ),
				'plan'           => 'pro',
				'sites_allowed'  => '7',
				'status'         => 'suspended',
				'customer_email' => 'new' . wp_generate_password( 4, false ) . '@' . 'sfls-audit.example.net',
				'expires_at'     => '2031-01-15',
			)
		);
		$fields = wp_list_pluck( SFLS_Audit::entries( $id ), 'new_value', 'field' );
		$this->assertSame( array( 'customer_email', 'expires_at', 'sites_allowed', 'status' ), array_values( array_intersect( array( 'customer_email', 'expires_at', 'sites_allowed', 'status' ), array_keys( $fields ) ) ) );
		$this->assertSame( '7', $fields['sites_allowed'] );
		$this->assertSame( 'suspended', $fields['status'] );
		$this->assertSame( get_current_user_id(), (int) SFLS_Audit::entries( $id )[0]['user_id'] );
		$this->assertCount( 4, SFLS_Audit::entries( $id ) );

		// Saving again without changes adds nothing.
		$cur = SFLS_Licenses::find( $id );
		$this->call(
			array( new SFLS_Admin(), 'handle_save' ),
			array( 'id' => $id, '_wpnonce' => wp_create_nonce( 'sfls_save_' . $id ), 'plan' => 'pro', 'sites_allowed' => '7', 'status' => 'suspended', 'customer_email' => $cur['customer_email'], 'expires_at' => '2031-01-15' )
		);
		$this->assertCount( 4, SFLS_Audit::entries( $id ) );
	}

	public function test_key_regeneration_is_logged_masked(): void {
		wp_set_current_user( 1 );
		$lic = $this->lic();
		$id  = (int) $lic['id'];
		$this->call( array( new SFLS_Admin(), 'handle_action' ), array( 'id' => $id, 'do' => 'regenerate', '_wpnonce' => wp_create_nonce( 'sfls_action_' . $id ) ), 1, 'get' );
		$rows = SFLS_Audit::entries( $id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'license_key', $rows[0]['field'] );
		$this->assertSame( SFLS_Licenses::mask_key( $lic['license_key'] ), $rows[0]['old_value'] );
		$this->assertNotSame( $rows[0]['old_value'], $rows[0]['new_value'] );
	}

	public function test_prune_deletes_old_rows(): void {
		wp_set_current_user( 1 );
		global $wpdb;
		$lic = $this->lic();
		$id  = (int) $lic['id'];
		SFLS_Audit::record( $id, 'status', 'a', 'b' );
		SFLS_Audit::record( $id, 'status', 'b', 'c' );
		$old = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM ' . SFLS_DB::audit_log() . ' WHERE license_id = %d', $id ) );
		$wpdb->update( SFLS_DB::audit_log(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );
		$this->set_option( 'sfls_settings', array( 'retention_days' => 30 ) );
		SFLS_DB::prune();
		$this->assertCount( 1, SFLS_Audit::entries( $id ) );
	}
}
