<?php
/**
 * S3: resend email, bulk actions and the customer view.
 *
 * @package SFLS
 */

/**
 * Admin support tools.
 */
class SFLS_Admin_Tools_Test extends SFLS_Test_Case {

	/**
	 * A license on a private email domain, so other tests' cleanup leaves it alone.
	 *
	 * @param array $args Create args.
	 */
	private function lic( array $args = array() ): array {
		return $this->make_license( $args + array( 'customer_email' => 'c' . wp_generate_password( 6, false ) . '@sfls-audit.example.net' ) );
	}

	/**
	 * A subscriber (no manage_options) on the private domain.
	 */
	private function sub(): int {
		$name = 's' . wp_generate_password( 8, false );
		return (int) wp_insert_user( array( 'user_login' => $name, 'user_email' => $name . '@sfls-audit.example.net', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	}

	protected function tearDown(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE '%@sfls-audit.example.net'" ) as $uid ) {
			wp_delete_user( (int) $uid );
		}
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

	/**
	 * Audit rows of a license by field.
	 *
	 * @param int    $id    License ID.
	 * @param string $field Field.
	 */
	private function audit( int $id, string $field ): array {
		return array_values( array_filter( SFLS_Audit::entries( $id ), static fn( $r ) => $r['field'] === $field ) );
	}

	public function test_resend_requires_capability_and_nonce(): void {
		wp_set_current_user( 1 );
		$lic = $this->lic();
		$id  = (int) $lic['id'];
		$sub = $this->sub();
		$req = array( 'id' => $id, 'do' => 'resend', '_wpnonce' => wp_create_nonce( 'sfls_action_' . $id ) );
		$h   = array( new SFLS_Admin(), 'handle_action' );

		$this->assertSame( 'die', $this->call( $h, $req, $sub, 'get' ), 'No capability.' );
		$this->assertSame( 'die', $this->call( $h, array( '_wpnonce' => 'bad' ) + $req, 1, 'get' ), 'Bad nonce.' );
		$this->assertSame( array(), $this->audit( $id, 'email_resent' ) );
		$this->assertCount( 0, $this->mails );

		$this->assertStringStartsWith( 'redirect:', $this->call( $h, $req, 1, 'get' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertCount( 1, $this->audit( $id, 'email_resent' ) );
		$this->assertSame( $lic['customer_email'], $this->audit( $id, 'email_resent' )[0]['new_value'] );
	}

	public function test_bulk_requires_capability_and_nonce(): void {
		wp_set_current_user( 1 );
		$lic  = $this->lic();
		$id   = (int) $lic['id'];
		$sub  = $this->sub();
		$post = array( 'bulk_action' => 'suspend', 'ids' => array( $id ), '_wpnonce' => wp_create_nonce( 'sfls_bulk' ) );
		$h    = array( new SFLS_Admin(), 'handle_bulk' );

		$this->assertSame( 'die', $this->call( $h, $post, $sub ) );
		$this->assertSame( 'die', $this->call( $h, array( '_wpnonce' => 'bad' ) + $post ) );
		$this->assertSame( 'active', SFLS_Licenses::find( $id )['status'] );

		$this->call( $h, $post );
		$this->assertSame( 'suspended', SFLS_Licenses::find( $id )['status'] );
		$this->assertSame( 'bulk', $this->audit( $id, 'status' )[0]['context'] );
		$this->assertSame( 'suspended', $this->audit( $id, 'status' )[0]['new_value'] );

		$this->call( $h, array( 'bulk_action' => 'resume' ) + $post );
		$this->assertSame( 'active', SFLS_Licenses::find( $id )['status'] );
		$this->assertCount( 2, $this->audit( $id, 'status' ) );
	}

	public function test_bulk_extend_math(): void {
		wp_set_current_user( 1 );
		$future = $this->lic();
		$past   = $this->lic();
		$none   = $this->lic();
		$fut_at = gmdate( 'Y-m-d H:i:s', time() + 100 * DAY_IN_SECONDS );
		SFLS_Licenses::update( (int) $future['id'], array( 'expires_at' => $fut_at ) );
		SFLS_Licenses::update( (int) $past['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 50 * DAY_IN_SECONDS ) ) );
		SFLS_Licenses::update( (int) $none['id'], array( 'expires_at' => null ) );

		$this->call(
			array( new SFLS_Admin(), 'handle_bulk' ),
			array( 'bulk_action' => 'extend', 'ids' => array( (int) $future['id'], (int) $past['id'], (int) $none['id'] ), '_wpnonce' => wp_create_nonce( 'sfls_bulk' ) )
		);
		$at = static fn( $l ) => strtotime( SFLS_Licenses::find( (int) $l['id'] )['expires_at'] . ' UTC' );
		$this->assertSame( strtotime( '+1 year', strtotime( $fut_at . ' UTC' ) ), $at( $future ) );
		$this->assertEqualsWithDelta( strtotime( '+1 year' ), $at( $past ), 5 );
		$this->assertEmpty( SFLS_Licenses::find( (int) $none['id'] )['expires_at'], 'Lifetime licenses keep no expiry.' );
		$this->assertCount( 0, $this->audit( (int) $none['id'], 'expires_at' ) );
		$this->assertCount( 1, $this->audit( (int) $past['id'], 'expires_at' ) );
	}

	public function test_customer_view_requires_capability_and_nonce_and_escapes(): void {
		wp_set_current_user( 1 );
		$email = 'cust' . wp_generate_password( 5, false ) . '@' . 'sfls-audit.example.net';
		$lic   = $this->lic( array( 'customer_email' => $email ) );
		SFLS_Licenses::update( (int) $lic['id'], array( 'paddle_subscription_id' => 'sub_<b>x</b>' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		$sub = $this->sub();
		$req = array( 'page' => 'sfls-customer', 'email' => $email, '_wpnonce' => wp_create_nonce( 'sfls_customer' ) );
		$h   = array( new SFLS_Admin(), 'page_customer' );

		$this->assertSame( 'die', $this->call( $h, $req, $sub, 'get' ) );
		$this->assertSame( 'die', $this->call( $h, array( '_wpnonce' => 'bad' ) + $req, 1, 'get' ) );

		wp_set_current_user( 1 );
		$_GET = $_REQUEST = $req;
		ob_start();
		( new SFLS_Admin() )->page_customer();
		$html = ob_get_clean();
		$_GET = $_REQUEST = array();
		$this->assertStringContainsString( $lic['license_key'], $html );
		$this->assertStringContainsString( 'client.example.com', $html );
		$this->assertStringContainsString( 'sub_&lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'sub_<b>', $html );
	}
}
