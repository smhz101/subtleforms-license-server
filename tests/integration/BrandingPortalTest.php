<?php
/**
 * My Account › Branding and the license screen panel (1.8.0).
 *
 * @package SFLS
 */

/**
 * Stops a handler at its redirect.
 */
class SFLS_Branding_Stop extends Exception {

	/**
	 * Where it would have gone.
	 *
	 * @var string
	 */
	public string $location = '';
}

/**
 * The branding portal.
 */
class SFLS_Branding_Portal_Test extends SFLS_Test_Case {

	/**
	 * A small valid PNG.
	 */
	private function png_file(): string {
		$path               = wp_tempnam( 'sfls-portal-logo' );
		$this->temp_files[] = $path;
		file_put_contents( $path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return $path;
	}

	/**
	 * An Agency license owned by a new customer.
	 *
	 * @param string $plan Plan.
	 * @return array{0:array,1:WP_User}
	 */
	private function agency( string $plan = 'agency' ): array {
		$user = $this->make_user();
		return array( $this->make_license( array( 'plan' => $plan, 'user_id' => $user->ID ) ), $user );
	}

	/**
	 * Post a branding form as a user and return where it redirected.
	 *
	 * @param WP_User|null $user   Who posts (null: signed out).
	 * @param array        $fields Form fields.
	 * @param bool         $nonce  Send a valid nonce for the posted license.
	 * @param array        $files  $_FILES.
	 */
	private function post( ?WP_User $user, array $fields, bool $nonce = true, array $files = array() ): string {
		wp_set_current_user( $user ? $user->ID : 0 );
		$_POST = $fields;
		if ( $nonce ) {
			$_POST['_sfls_branding_nonce'] = wp_create_nonce( 'sfls_branding_' . ( $fields['license_id'] ?? 0 ) );
		}
		$_FILES = $files;
		$this->filter(
			'wp_redirect',
			static function ( $location ) {
				$stop           = new SFLS_Branding_Stop( 'redirect' );
				$stop->location = (string) $location;
				throw $stop;
			}
		);
		wc_clear_notices();
		try {
			( new SFLS_Account() )->handle_branding();
			$location = '';
		} catch ( SFLS_Branding_Stop $stop ) {
			$location = $stop->location;
		} finally {
			$_POST  = array();
			$_FILES = array();
		}
		return $location;
	}

	/**
	 * The page as HTML.
	 *
	 * @param WP_User $user User.
	 * @param array   $get  Query string.
	 */
	private function page( WP_User $user, array $get = array() ): string {
		wp_set_current_user( $user->ID );
		$_GET = $get;
		ob_start();
		( new SFLS_Account() )->branding_page();
		$html = (string) ob_get_clean();
		$_GET = array();
		return $html;
	}

	// ── Menu and page ───────────────────────────────────────────────────

	/**
	 * The menu entry is only for customers with an eligible license.
	 */
	public function test_menu_entry_only_for_eligible_customers(): void {
		[ , $agency ] = $this->agency();
		[ , $pro ]    = $this->agency( 'pro' );
		$nobody       = $this->make_user();

		wp_set_current_user( $agency->ID );
		$menu = wc_get_account_menu_items();
		$this->assertArrayHasKey( 'branding', $menu );
		$this->assertSame( 'Branding', $menu['branding'] );
		$keys = array_keys( $menu );
		$this->assertGreaterThan( array_search( 'licenses', $keys, true ), array_search( 'branding', $keys, true ), 'After Licenses.' );

		foreach ( array( $pro, $nobody ) as $user ) {
			wp_set_current_user( $user->ID );
			$this->assertArrayNotHasKey( 'branding', wc_get_account_menu_items() );
		}
		wp_set_current_user( 0 );
		$this->assertArrayNotHasKey( 'branding', wc_get_account_menu_items() );

		$expired = $this->make_user();
		$this->make_license( array( 'plan' => 'agency', 'user_id' => $expired->ID, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		wp_set_current_user( $expired->ID );
		$this->assertArrayNotHasKey( 'branding', wc_get_account_menu_items(), 'A lapsed license has no branding screen.' );
		$this->assertTrue( in_array( 'branding', array_keys( WC()->query->get_query_vars() ), true ), 'The endpoint is registered.' );
	}

	/**
	 * Customers without an eligible license see why, not a form.
	 */
	public function test_page_for_a_plan_without_branding(): void {
		[ , $pro ] = $this->agency( 'pro' );
		$html      = $this->page( $pro );
		$this->assertStringContainsString( 'Branding is part of the Agency plan', $html );
		$this->assertStringNotContainsString( 'name="sfls_branding"', $html );
	}

	/**
	 * The form has every field, the preview shows the saved values, the
	 * sites and revisions are listed.
	 */
	public function test_page_shows_form_preview_sites_and_revisions(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Branding::save( $lic, array( 'name' => 'Acme Forms', 'menu_name' => 'Acme', 'author' => 'Acme Studio', 'description' => 'Forms by Acme.' ), $owner->ID );
		SFLS_Branding::save( $lic, array( 'name' => 'Acme Forms 2', 'menu_name' => 'Acme' ), $owner->ID );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'quiet.example.com' );
		$this->rest( 'POST', '/sfls/v1/branding', array( 'license_key' => $lic['license_key'], 'site_url' => 'https://client.example.com' ) );

		$html = $this->page( $owner );
		foreach ( array( 'name', 'menu_name', 'logo_url', 'support_url', 'hide_credits', 'restrict_screens', 'author', 'author_url', 'description', 'logo' ) as $field ) {
			$this->assertStringContainsString( 'name="' . $field . '"', $html, $field );
		}
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( '_sfls_branding_nonce', $html );
		$this->assertStringContainsString( 'value="Acme Forms 2"', $html );
		$this->assertStringContainsString( 'data-sfls-bp="name">Acme Forms 2<', $html, 'The preview uses the saved name.' );
		$this->assertStringContainsString( 'data-sfls-bp="menu">Acme<', $html );
		$this->assertStringContainsString( 'Live, version 2', $html );
		$this->assertStringContainsString( 'client.example.com', $html );
		$this->assertStringContainsString( 'Version 2, fetched', $html );
		$this->assertStringContainsString( 'Up to date', $html );
		$this->assertStringContainsString( 'Has not fetched the branding yet', $html );
		$this->assertStringContainsString( 'Version 1', $html );
		$this->assertStringContainsString( 'value="restore"', $html );
		$this->assertSame( 1, substr_count( $html, 'value="restore"' ), 'The current version has no Restore.' );
		$this->assertStringNotContainsString( 'sfls-license-switch', $html, 'One license needs no selector.' );
	}

	/**
	 * With several licenses there is a selector, and a license that is not
	 * the customer's is never shown.
	 */
	public function test_license_selector_and_foreign_license(): void {
		[ $first, $owner ] = $this->agency();
		$second            = $this->make_license( array( 'plan' => 'agency', 'user_id' => $owner->ID ) );
		[ $foreign ]       = $this->agency();
		SFLS_Branding::save( $foreign, array( 'name' => 'Not yours' ), (int) $foreign['user_id'] );
		SFLS_Branding::save( $second, array( 'name' => 'Second license' ), $owner->ID );

		$html = $this->page( $owner );
		$this->assertStringContainsString( 'sfls-license-switch', $html );
		$this->assertStringContainsString( 'license=' . $first['id'], $html );
		$this->assertStringContainsString( 'license=' . $second['id'], $html );

		$picked = $this->page( $owner, array( 'license' => (string) $second['id'] ) );
		$this->assertStringContainsString( 'value="Second license"', $picked );
		$this->assertStringContainsString( 'name="license_id" value="' . $second['id'] . '"', $picked );

		$other = $this->page( $owner, array( 'license' => (string) $foreign['id'] ) );
		$this->assertStringNotContainsString( 'Not yours', $other );
		$this->assertStringNotContainsString( 'value="' . $foreign['id'] . '"', $other );
	}

	// ── Actions ─────────────────────────────────────────────────────────

	/**
	 * Saving through the form stores the profile and bumps the version.
	 */
	public function test_save_action(): void {
		[ $lic, $owner ] = $this->agency();
		$location        = $this->post(
			$owner,
			array(
				'sfls_branding'    => 'save',
				'license_id'       => (string) $lic['id'],
				'name'             => 'Acme Forms',
				'menu_name'        => 'Acme',
				'support_url'      => 'https://acme.example/help',
				'hide_credits'     => '1',
				'restrict_screens' => '1',
				'author'           => 'Acme',
			)
		);
		$this->assertStringContainsString( 'license=' . $lic['id'], $location );
		$state = SFLS_Branding::get( (int) $lic['id'] );
		$this->assertSame( 1, $state['version'] );
		$this->assertSame( 'Acme Forms', $state['profile']['name'] );
		$this->assertTrue( $state['profile']['hide_credits'] );
		$this->assertTrue( $state['profile']['restrict_screens'] );
		$this->assertSame( $owner->ID, $state['updated_by'] );
		$this->assertStringContainsString( 'Branding saved as version 1', implode( ' ', $this->notices( 'success' ) ) );
	}

	/**
	 * No nonce, a nonce for another license, another customer, a plan
	 * without branding and a signed-out visitor change nothing.
	 */
	public function test_actions_are_guarded(): void {
		[ $lic, $owner ] = $this->agency();
		[ $pro, $pro_owner ] = $this->agency( 'pro' );
		$stranger        = $this->make_user();
		$fields          = array( 'sfls_branding' => 'save', 'license_id' => (string) $lic['id'], 'name' => 'Hijacked' );

		// No nonce.
		$this->post( $owner, $fields, false );
		$this->assertNull( SFLS_Branding::get( (int) $lic['id'] ) );
		$this->assertStringContainsString( 'session expired', implode( ' ', $this->notices( 'error' ) ) );

		// A nonce made for another license.
		$this->post( $owner, $fields + array( '_sfls_branding_nonce' => wp_create_nonce( 'sfls_branding_999999' ) ), false );
		$this->assertNull( SFLS_Branding::get( (int) $lic['id'] ) );

		// A valid nonce of someone who does not own the license.
		$this->post( $stranger, $fields );
		$this->assertNull( SFLS_Branding::get( (int) $lic['id'] ), 'Ownership is checked.' );
		$this->assertStringContainsString( 'not found in your account', implode( ' ', $this->notices( 'error' ) ) );

		// The owner of a plan without white label.
		$this->post( $pro_owner, array( 'sfls_branding' => 'save', 'license_id' => (string) $pro['id'], 'name' => 'Nope' ) );
		$this->assertNull( SFLS_Branding::get( (int) $pro['id'] ) );
		$this->assertStringContainsString( 'needs an active Agency license', implode( ' ', $this->notices( 'error' ) ) );

		// Signed out.
		$this->post( null, $fields );
		$this->assertNull( SFLS_Branding::get( (int) $lic['id'] ) );

		// Restore and remove are guarded the same way.
		SFLS_Branding::save( $lic, array( 'name' => 'Real' ), $owner->ID );
		$revision = SFLS_Branding::revisions( (int) $lic['id'] )[0]['id'];
		$this->post( $stranger, array( 'sfls_branding' => 'restore', 'license_id' => (string) $lic['id'], 'revision_id' => (string) $revision ) );
		$this->post( $stranger, array( 'sfls_branding' => 'reset', 'license_id' => (string) $lic['id'] ) );
		$this->post( $owner, array( 'sfls_branding' => 'reset', 'license_id' => (string) $lic['id'] ), false );
		$state = SFLS_Branding::get( (int) $lic['id'] );
		$this->assertSame( 1, $state['version'] );
		$this->assertTrue( $state['enabled'] );
	}

	/**
	 * A lapsed license cannot change branding even for its owner.
	 */
	public function test_lapsed_license_cannot_save(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$this->post( $owner, array( 'sfls_branding' => 'save', 'license_id' => (string) $lic['id'], 'name' => 'Late' ) );
		$this->assertNull( SFLS_Branding::get( (int) $lic['id'] ) );
	}

	/**
	 * Invalid input is refused with a notice and the form is filled in again.
	 */
	public function test_invalid_input_keeps_what_was_typed(): void {
		[ $lic, $owner ] = $this->agency();
		$this->post(
			$owner,
			array(
				'sfls_branding' => 'save',
				'license_id'    => (string) $lic['id'],
				'name'          => str_repeat( 'x', 61 ),
				'menu_name'     => 'Typed menu',
				'support_url'   => 'javascript:alert(1)',
			)
		);
		$this->assertNull( SFLS_Branding::get( (int) $lic['id'] ) );
		$errors = implode( ' ', $this->notices( 'error' ) );
		$this->assertStringContainsString( 'up to 60 characters', $errors );
		$this->assertStringContainsString( 'Support address', $errors );

		$html = $this->page( $owner );
		$this->assertStringContainsString( 'value="Typed menu"', $html, 'The form shows what was typed.' );
		$again = $this->page( $owner );
		$this->assertStringNotContainsString( 'value="Typed menu"', $again, 'Shown once.' );
	}

	/**
	 * Uploading a logo through the form, and refusing a PHP file named .png.
	 */
	public function test_logo_upload_through_the_form(): void {
		[ $lic, $owner ] = $this->agency();
		$this->post(
			$owner,
			array( 'sfls_branding' => 'save', 'license_id' => (string) $lic['id'], 'name' => 'Acme' ),
			true,
			array( 'logo' => array( 'name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $this->png_file(), 'error' => UPLOAD_ERR_OK, 'size' => 100 ) )
		);
		$state = SFLS_Branding::get( (int) $lic['id'] );
		$this->assertNotSame( '', $state['logo_file'] );
		$html = $this->page( $owner );
		$this->assertStringContainsString( 'sfls-logo-thumb', $html );
		$this->assertStringContainsString( $state['logo_file'], $html );
		$this->assertStringContainsString( 'name="remove_logo"', $html );

		$php                = wp_tempnam( 'evil' ) . '.png';
		$this->temp_files[] = $php;
		file_put_contents( $php, "<?php echo 'owned';" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->post(
			$owner,
			array( 'sfls_branding' => 'save', 'license_id' => (string) $lic['id'], 'name' => 'Changed' ),
			true,
			array( 'logo' => array( 'name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $php, 'error' => UPLOAD_ERR_OK, 'size' => 20 ) )
		);
		$after = SFLS_Branding::get( (int) $lic['id'] );
		$this->assertSame( 1, $after['version'], 'A refused upload saves nothing.' );
		$this->assertSame( $state['logo_file'], $after['logo_file'] );
		$this->assertStringContainsString( 'PNG, JPEG, GIF or WebP', implode( ' ', $this->notices( 'error' ) ) );

		// Remove the logo.
		$this->post( $owner, array( 'sfls_branding' => 'save', 'license_id' => (string) $lic['id'], 'name' => 'Acme', 'remove_logo' => '1' ) );
		$this->assertSame( '', SFLS_Branding::get( (int) $lic['id'] )['logo_file'] );
		$this->assertFileDoesNotExist( SFLS_Branding::logo_dir() . '/' . $state['logo_file'] );
	}

	/**
	 * Restore and remove through the portal.
	 */
	public function test_restore_and_reset_actions(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Branding::save( $lic, array( 'name' => 'One' ), $owner->ID );
		SFLS_Branding::save( $lic, array( 'name' => 'Two' ), $owner->ID );
		$one = array_values( array_filter( SFLS_Branding::revisions( (int) $lic['id'] ), static fn( $r ) => 1 === $r['version'] ) )[0];

		$this->post( $owner, array( 'sfls_branding' => 'restore', 'license_id' => (string) $lic['id'], 'revision_id' => (string) $one['id'] ) );
		$state = SFLS_Branding::get( (int) $lic['id'] );
		$this->assertSame( 3, $state['version'] );
		$this->assertSame( 'One', $state['profile']['name'] );
		$this->assertStringContainsString( 'Restored and published as version 3', implode( ' ', $this->notices( 'success' ) ) );

		[ $other, $other_owner ] = $this->agency();
		SFLS_Branding::save( $other, array( 'name' => 'Other' ), $other_owner->ID );
		$foreign = SFLS_Branding::revisions( (int) $other['id'] )[0]['id'];
		$this->post( $owner, array( 'sfls_branding' => 'restore', 'license_id' => (string) $lic['id'], 'revision_id' => (string) $foreign ) );
		$this->assertSame( 3, SFLS_Branding::get( (int) $lic['id'] )['version'], 'Another license revision is refused.' );
		$this->assertNotEmpty( $this->notices( 'error' ) );

		$this->post( $owner, array( 'sfls_branding' => 'reset', 'license_id' => (string) $lic['id'] ) );
		$state = SFLS_Branding::get( (int) $lic['id'] );
		$this->assertFalse( $state['enabled'] );
		$this->assertSame( 4, $state['version'] );
	}

	// ── Admin ───────────────────────────────────────────────────────────

	/**
	 * Support sees the version, sites and revisions on the license screen.
	 */
	public function test_admin_panel_for_support(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Branding::save( $lic, array( 'name' => 'Acme Forms' ), $owner->ID );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		$this->rest( 'POST', '/sfls/v1/branding', array( 'license_key' => $lic['license_key'], 'site_url' => 'https://client.example.com' ) );

		$method = new ReflectionMethod( SFLS_Admin::class, 'branding_panel' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SFLS_Admin(), SFLS_Licenses::find( (int) $lic['id'] ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="sfls-branding"', $html );
		$this->assertStringContainsString( 'Live', $html );
		$this->assertStringContainsString( 'Acme Forms', $html );
		$this->assertStringContainsString( 'client.example.com', $html );
		$this->assertStringContainsString( 'Revisions', $html );

		[ $pro ] = $this->agency( 'pro' );
		ob_start();
		$method->invoke( new SFLS_Admin(), $pro );
		$this->assertSame( '', ob_get_clean(), 'No panel for a plan without branding that never saved any.' );
	}
}
