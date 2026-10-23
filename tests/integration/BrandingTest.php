<?php
/**
 * Branding portal storage and the POST /sfls/v1/branding contract (1.8.0).
 *
 * @package SFLS
 */

/**
 * Branding per license.
 */
class SFLS_Branding_Test extends SFLS_Test_Case {

	/**
	 * A small valid PNG.
	 */
	private function png(): string {
		return (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * A temp file with contents.
	 *
	 * @param string $contents Contents.
	 */
	private function temp( string $contents ): string {
		$path               = wp_tempnam( 'sfls-logo' );
		$this->temp_files[] = $path;
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
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
	 * Ask for the branding as a client site.
	 *
	 * @param array  $lic     License.
	 * @param string $site    Site URL.
	 * @param mixed  $version Version the site has (null: none sent).
	 */
	private function fetch( array $lic, string $site = 'https://client.example.com', $version = null ): WP_REST_Response {
		$params = array(
			'license_key' => $lic['license_key'],
			'site_url'    => $site,
		);
		if ( null !== $version ) {
			$params['version'] = $version;
		}
		return $this->rest( 'POST', '/sfls/v1/branding', $params );
	}

	// ── Plan and ownership ──────────────────────────────────────────────

	/**
	 * Only plans with white_label may have branding, and the customer must own
	 * an active license of such a plan.
	 */
	public function test_plan_gating_and_ownership(): void {
		[ $agency, $owner ] = $this->agency();
		[ $pro, $pro_owner ] = $this->agency( 'pro' );
		$stranger            = $this->make_user();

		$this->assertTrue( SFLS_Branding::can_manage( $agency, $owner ) );
		$this->assertFalse( SFLS_Branding::can_manage( $agency, $stranger ), 'Not the owner.' );
		$this->assertFalse( SFLS_Branding::can_manage( $agency, new WP_User( 0 ) ), 'Not signed in.' );
		$this->assertFalse( SFLS_Branding::can_manage( $pro, $pro_owner ), 'Pro has no white label.' );
		$this->assertSame( array(), SFLS_Branding::eligible_licenses( $pro_owner ) );
		$this->assertCount( 1, SFLS_Branding::eligible_licenses( $owner ) );

		$error = SFLS_Branding::save( $pro, array( 'name' => 'Nope' ), $pro_owner->ID );
		$this->assertWPError( $error );
		$this->assertSame( 'sfls_branding_plan', $error->get_error_code() );
		$this->assertNull( SFLS_Branding::get( (int) $pro['id'] ) );

		SFLS_Licenses::update( (int) $agency['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$lapsed = SFLS_Licenses::find( (int) $agency['id'] );
		$this->assertFalse( SFLS_Branding::can_manage( $lapsed, $owner ), 'A lapsed license cannot change branding.' );
	}

	// ── Validation ──────────────────────────────────────────────────────

	/**
	 * Lengths, addresses, booleans and unknown keys.
	 */
	public function test_validation_rules(): void {
		$ok = SFLS_Branding::validate(
			array(
				'name'             => '  <b>Acme</b> Forms ',
				'menu_name'        => 'Acme',
				'logo_url'         => 'https://cdn.example.com/logo.svg',
				'support_url'      => 'http://acme.example/support',
				'hide_credits'     => 'on',
				'restrict_screens' => '0',
				'author'           => 'Acme Studio',
				'author_url'       => 'https://acme.example',
				'description'      => 'Forms by Acme.',
				'evil'             => 'dropped',
			)
		);
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame( 'Acme Forms', $ok['profile']['name'] );
		$this->assertTrue( $ok['profile']['hide_credits'] );
		$this->assertFalse( $ok['profile']['restrict_screens'] );
		$this->assertArrayNotHasKey( 'evil', $ok['profile'] );
		$this->assertSame( array_keys( SFLS_Branding::empty_profile() ), array_keys( $ok['profile'] ) );

		$long = SFLS_Branding::validate(
			array(
				'name'        => str_repeat( 'a', 61 ),
				'menu_name'   => str_repeat( 'a', 41 ),
				'author'      => str_repeat( 'a', 61 ),
				'description' => str_repeat( 'a', 201 ),
			)
		);
		$this->assertEqualsCanonicalizing( array( 'name', 'menu_name', 'author', 'description' ), array_keys( $long['errors'] ) );
		$edge = SFLS_Branding::validate( array( 'name' => str_repeat( 'a', 60 ), 'menu_name' => str_repeat( 'é', 40 ), 'description' => str_repeat( 'a', 200 ) ) );
		$this->assertSame( array(), $edge['errors'], 'The limits count characters, not bytes.' );

		foreach ( array( 'http://cdn.example.com/logo.png', 'javascript:alert(1)', 'https://cdn.example.com/logo.php', 'https://cdn.example.com/logo', 'https://user:pw@cdn.example.com/logo.png', '//cdn.example.com/logo.png', 'ftp://cdn.example.com/logo.png', 'https://cdn.example.com/' . str_repeat( 'a', 500 ) . '.png' ) as $bad ) {
			$this->assertArrayHasKey( 'logo_url', SFLS_Branding::validate( array( 'logo_url' => $bad ) )['errors'], $bad );
		}
		foreach ( array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ) as $ext ) {
			$this->assertSame( array(), SFLS_Branding::validate( array( 'logo_url' => 'https://cdn.example.com/a/logo.' . $ext ) )['errors'], $ext );
		}
		foreach ( array( 'support_url', 'author_url' ) as $field ) {
			foreach ( array( 'javascript:alert(1)', 'data:text/html,x', 'ftp://x.example', 'https://u:p@x.example' ) as $bad ) {
				$this->assertArrayHasKey( $field, SFLS_Branding::validate( array( $field => $bad ) )['errors'], $field . ' ' . $bad );
			}
		}
	}

	// ── Storage, versions and revisions ─────────────────────────────────

	/**
	 * Every save bumps the version, keeps a revision, and only ten are kept.
	 */
	public function test_version_bump_and_revision_cap(): void {
		[ $lic, $owner ] = $this->agency();
		$id              = (int) $lic['id'];
		$this->assertSame( 0, SFLS_Branding::version_for( $lic ) );

		for ( $n = 1; $n <= 12; $n++ ) {
			$state = SFLS_Branding::save( $lic, array( 'name' => 'Name ' . $n ), $owner->ID );
			$this->assertIsArray( $state );
			$this->assertSame( $n, $state['version'] );
			$this->assertTrue( $state['enabled'] );
		}
		$this->assertSame( 12, SFLS_Branding::version_for( $lic ) );
		$revisions = SFLS_Branding::revisions( $id );
		$this->assertCount( 10, $revisions );
		$this->assertSame( 12, $revisions[0]['version'] );
		$this->assertSame( 3, $revisions[9]['version'] );
		$this->assertSame( $owner->ID, $revisions[0]['updated_by'], 'Who changed it is recorded.' );
		$this->assertSame( 'Name 12', SFLS_Branding::get( $id )['profile']['name'] );
	}

	/**
	 * Restore puts an earlier state back as a new version.
	 */
	public function test_restore_revision(): void {
		[ $lic, $owner ] = $this->agency();
		$id              = (int) $lic['id'];
		SFLS_Branding::save( $lic, array( 'name' => 'First', 'hide_credits' => '1' ), $owner->ID );
		SFLS_Branding::save( $lic, array( 'name' => 'Second' ), $owner->ID );
		$first = null;
		foreach ( SFLS_Branding::revisions( $id ) as $rev ) {
			if ( 1 === $rev['version'] ) {
				$first = $rev;
			}
		}
		$this->assertNotNull( $first );

		$result = SFLS_Branding::restore( $lic, $first['id'], $owner->ID );
		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['state']['version'] );
		$this->assertSame( 'First', $result['state']['profile']['name'] );
		$this->assertTrue( $result['state']['profile']['hide_credits'] );
		$this->assertSame( 'restore', SFLS_Branding::revisions( $id )[0]['action'] );

		// A revision of another license cannot be restored.
		[ $other, $other_owner ] = $this->agency();
		SFLS_Branding::save( $other, array( 'name' => 'Other' ), $other_owner->ID );
		$foreign = SFLS_Branding::revisions( (int) $other['id'] )[0]['id'];
		$this->assertWPError( SFLS_Branding::restore( $lic, $foreign, $owner->ID ) );
		$this->assertSame( 'First', SFLS_Branding::get( $id )['profile']['name'] );
	}

	/**
	 * Removing branding disables it and still bumps the version.
	 */
	public function test_reset_disables_and_bumps(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Branding::save( $lic, array( 'name' => 'Acme' ), $owner->ID );
		$state = SFLS_Branding::reset( $lic, $owner->ID );
		$this->assertFalse( $state['enabled'] );
		$this->assertSame( 2, $state['version'] );
		$this->assertSame( '', $state['profile']['name'] );

		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		$data = $this->fetch( $lic, 'https://client.example.com', 1 )->get_data()['data'];
		$this->assertFalse( $data['enabled'] );
		$this->assertSame( 2, $data['version'] );
		$this->assertArrayNotHasKey( 'profile', $data );
		$this->assertFalse( $data['unchanged'] );
	}

	/**
	 * Deleting the license removes its branding and logo.
	 */
	public function test_license_delete_purges_branding(): void {
		global $wpdb;
		[ $lic, $owner ] = $this->agency();
		$file            = $this->temp( $this->png() );
		SFLS_Branding::save( $lic, array( 'name' => 'Acme' ), $owner->ID, array( 'file' => array( 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK ) ) );
		$logo = SFLS_Branding::logo_dir() . '/' . SFLS_Branding::get( (int) $lic['id'] )['logo_file'];
		$this->assertFileExists( $logo );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		$this->fetch( $lic );

		SFLS_Licenses::delete( (int) $lic['id'] );
		$this->assertFileDoesNotExist( $logo );
		foreach ( array( SFLS_DB::branding(), SFLS_DB::branding_revisions(), SFLS_DB::branding_sites() ) as $table ) {
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE license_id = %d", $lic['id'] ) ), $table ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Deleting a license also removes the files only kept for revisions.
	 */
	public function test_license_delete_removes_files_kept_for_revisions(): void {
		[ $lic, $owner ] = $this->agency();
		$one             = SFLS_Branding::save( $lic, array( 'name' => 'One' ), $owner->ID, array( 'file' => array( 'tmp_name' => $this->temp( $this->png() ), 'error' => UPLOAD_ERR_OK ) ) );
		SFLS_Branding::save( $lic, array( 'name' => 'Two' ), $owner->ID, array( 'remove' => true ) );
		$path = SFLS_Branding::logo_dir() . '/' . $one['logo_file'];
		$this->assertFileExists( $path );
		SFLS_Licenses::delete( (int) $lic['id'] );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * The uninstall cleanup drops the branding tables and the logo folder
	 * (every logo file lives in it).
	 */
	public function test_uninstall_cleans_branding(): void {
		$source = (string) file_get_contents( SFLS_DIR . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( array( 'sfls_branding', 'sfls_branding_revisions', 'sfls_branding_sites' ) as $table ) {
			$this->assertStringContainsString( '{$wpdb->prefix}' . $table . '"', $source, $table );
		}
		$this->assertStringContainsString( "'sfls-branding'", $source );
		$this->assertStringContainsString( SFLS_Branding::LOGO_DIR, 'sfls-branding' );
	}

	/**
	 * The schema carries the new tables.
	 */
	public function test_schema_has_branding_tables(): void {
		global $wpdb;
		$this->assertSame( '8', SFLS_DB::SCHEMA_VERSION );
		foreach ( array( SFLS_DB::branding(), SFLS_DB::branding_revisions(), SFLS_DB::branding_sites() ) as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $table );
		}
	}

	// ── Logo upload ─────────────────────────────────────────────────────

	/**
	 * A real image is stored under a random name; the address is https.
	 */
	public function test_logo_upload_accepts_an_image(): void {
		[ $lic, $owner ] = $this->agency();
		$state           = SFLS_Branding::save( $lic, array( 'name' => 'Acme' ), $owner->ID, array( 'file' => array( 'tmp_name' => $this->temp( $this->png() ), 'error' => UPLOAD_ERR_OK ) ) );
		$this->assertIsArray( $state );
		$this->assertMatchesRegularExpression( '/^license-' . $lic['id'] . '-[a-z0-9]{12}\.png$/', $state['logo_file'] );
		$this->assertFileExists( SFLS_Branding::logo_dir() . '/' . $state['logo_file'] );
		$this->assertStringStartsWith( 'https://', $state['profile']['logo_url'] );
		$this->assertStringEndsWith( '/' . SFLS_Branding::LOGO_DIR . '/' . $state['logo_file'], $state['profile']['logo_url'] );
	}

	/**
	 * A PHP file renamed .png, text, and an oversize image are refused and
	 * nothing is stored or changed.
	 */
	public function test_logo_upload_rejects_bad_files(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Branding::save( $lic, array( 'name' => 'Before' ), $owner->ID );
		$before = glob( SFLS_Branding::logo_dir() . '/license-' . $lic['id'] . '-*' ) ?: array();

		$cases = array(
			'php renamed png'   => "<?php echo 'x';",
			'png with php tail' => $this->png() . "\n<?php system(\$_GET['c']); ?>",
			'svg with script'   => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
			'plain text'        => 'hello',
			'html'              => '<html><body>x</body></html>',
			'too big'           => $this->png() . str_repeat( "\0", SFLS_Branding::MAX_LOGO_BYTES ),
			'empty'             => '',
		);
		foreach ( $cases as $label => $contents ) {
			$path = $this->temp( $contents );
			// The file name says .png; only the content counts.
			$png = $path . '.png';
			rename( $path, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			$this->temp_files[] = $png;
			$result             = SFLS_Branding::save( $lic, array( 'name' => 'After' ), $owner->ID, array( 'file' => array( 'tmp_name' => $png, 'name' => 'logo.png', 'type' => 'image/png', 'error' => UPLOAD_ERR_OK ) ) );
			$this->assertWPError( $result, $label );
			$this->assertArrayHasKey( 'logo', $result->get_error_data()['fields'], $label );
		}
		$this->assertSame( 'Before', SFLS_Branding::get( (int) $lic['id'] )['profile']['name'], 'A refused logo changes nothing.' );
		$this->assertSame( 1, SFLS_Branding::get( (int) $lic['id'] )['version'] );
		$this->assertSame( $before, glob( SFLS_Branding::logo_dir() . '/license-' . $lic['id'] . '-*' ) ?: array() );

		$limit = SFLS_Branding::save( $lic, array( 'name' => 'After' ), $owner->ID, array( 'file' => array( 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE ) ) );
		$this->assertWPError( $limit );
	}

	/**
	 * Replacing or removing the logo keeps the old file while a revision uses it; an address replaces
	 * an uploaded logo; saving without a logo choice keeps it.
	 */
	public function test_logo_replace_remove_and_keep(): void {
		[ $lic, $owner ] = $this->agency();
		$upload          = static fn( $path ) => array( 'file' => array( 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK ) );
		$first           = SFLS_Branding::save( $lic, array( 'name' => 'A' ), $owner->ID, $upload( $this->temp( $this->png() ) ) );
		$first_path      = SFLS_Branding::logo_dir() . '/' . $first['logo_file'];

		$kept = SFLS_Branding::save( $lic, array( 'name' => 'B' ), $owner->ID );
		$this->assertSame( $first['logo_file'], $kept['logo_file'], 'No logo choice keeps the logo.' );
		$this->assertSame( $first['profile']['logo_url'], $kept['profile']['logo_url'] );
		$this->assertFileExists( $first_path );

		$second = SFLS_Branding::save( $lic, array( 'name' => 'C' ), $owner->ID, $upload( $this->temp( $this->png() ) ) );
		$this->assertNotSame( $first['logo_file'], $second['logo_file'] );
		$this->assertFileExists( $first_path, 'A kept revision still uses the replaced file.' );
		$second_path = SFLS_Branding::logo_dir() . '/' . $second['logo_file'];
		$this->assertFileExists( $second_path );

		$external = SFLS_Branding::save( $lic, array( 'name' => 'D', 'logo_url' => 'https://cdn.example.com/logo.webp' ), $owner->ID );
		$this->assertSame( '', $external['logo_file'] );
		$this->assertSame( 'https://cdn.example.com/logo.webp', $external['profile']['logo_url'] );
		$this->assertFileExists( $second_path );

		$third      = SFLS_Branding::save( $lic, array( 'name' => 'E' ), $owner->ID, $upload( $this->temp( $this->png() ) ) );
		$third_path = SFLS_Branding::logo_dir() . '/' . $third['logo_file'];
		$removed    = SFLS_Branding::save( $lic, array( 'name' => 'F' ), $owner->ID, array( 'remove' => true ) );
		$this->assertSame( '', $removed['logo_file'] );
		$this->assertSame( '', $removed['profile']['logo_url'] );
		$this->assertFileExists( $third_path );
	}

	/**
	 * Remove then restore, and replace then restore, bring the logo back.
	 */
	public function test_restore_brings_back_the_logo(): void {
		[ $lic, $owner ] = $this->agency();
		$id              = (int) $lic['id'];
		$upload          = fn() => array( 'file' => array( 'tmp_name' => $this->temp( $this->png() ), 'error' => UPLOAD_ERR_OK ) );
		$by_version      = static function ( int $version ) use ( $id ) {
			return array_values( array_filter( SFLS_Branding::revisions( $id ), static fn( $r ) => $version === $r['version'] ) )[0];
		};

		$one  = SFLS_Branding::save( $lic, array( 'name' => 'One' ), $owner->ID, $upload() );
		$path = SFLS_Branding::logo_dir() . '/' . $one['logo_file'];
		SFLS_Branding::save( $lic, array( 'name' => 'Two' ), $owner->ID, array( 'remove' => true ) );
		$this->assertFileExists( $path, 'Removing the logo keeps the file for the revision.' );
		$result = SFLS_Branding::restore( $lic, $by_version( 1 )['id'], $owner->ID );
		$this->assertFalse( $result['logo_lost'] );
		$this->assertSame( $one['logo_file'], $result['state']['logo_file'] );
		$this->assertSame( SFLS_Branding::logo_url( $one['logo_file'] ), $result['state']['profile']['logo_url'] );

		$two = SFLS_Branding::save( $lic, array( 'name' => 'Three' ), $owner->ID, $upload() );
		$this->assertNotSame( $one['logo_file'], $two['logo_file'] );
		$this->assertFileExists( $path, 'Replacing the logo keeps the old file for the revision.' );
		$result = SFLS_Branding::restore( $lic, $by_version( 3 )['id'], $owner->ID );
		$this->assertFalse( $result['logo_lost'] );
		$this->assertSame( $one['logo_file'], $result['state']['logo_file'] );
		$this->assertFileExists( SFLS_Branding::logo_dir() . '/' . $two['logo_file'], 'Still used by a kept revision.' );
	}

	/**
	 * A file goes when the last revision that uses it is pruned, never before.
	 */
	public function test_pruned_revisions_delete_orphaned_logo_files(): void {
		[ $lic, $owner ] = $this->agency();
		$first           = SFLS_Branding::save( $lic, array( 'name' => 'Logo' ), $owner->ID, array( 'file' => array( 'tmp_name' => $this->temp( $this->png() ), 'error' => UPLOAD_ERR_OK ) ) );
		$path            = SFLS_Branding::logo_dir() . '/' . $first['logo_file'];
		SFLS_Branding::save( $lic, array( 'name' => 'Gone' ), $owner->ID, array( 'remove' => true ) );
		// Versions 3 to 10 keep version 1 inside the last ten.
		for ( $n = 3; $n <= 10; $n++ ) {
			SFLS_Branding::save( $lic, array( 'name' => 'N' . $n ), $owner->ID );
			$this->assertFileExists( $path, "Version 1 is still kept at version $n." );
		}
		SFLS_Branding::save( $lic, array( 'name' => 'N11' ), $owner->ID );
		$this->assertSame( 2, SFLS_Branding::revisions( (int) $lic['id'] )[9]['version'], 'Version 1 was pruned.' );
		$this->assertFileDoesNotExist( $path, 'The orphaned file is deleted with its last revision.' );
	}

	/**
	 * When the file is truly gone, restore says so.
	 */
	public function test_restore_with_a_missing_logo_file(): void {
		[ $lic, $owner ] = $this->agency();
		$one             = SFLS_Branding::save( $lic, array( 'name' => 'With logo' ), $owner->ID, array( 'file' => array( 'tmp_name' => $this->temp( $this->png() ), 'error' => UPLOAD_ERR_OK ) ) );
		SFLS_Branding::save( $lic, array( 'name' => 'No logo' ), $owner->ID, array( 'remove' => true ) );
		unlink( SFLS_Branding::logo_dir() . '/' . $one['logo_file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$old = array_values( array_filter( SFLS_Branding::revisions( (int) $lic['id'] ), static fn( $r ) => 1 === $r['version'] ) )[0];

		$result = SFLS_Branding::restore( $lic, $old['id'], $owner->ID );
		$this->assertTrue( $result['logo_lost'] );
		$this->assertSame( 'With logo', $result['state']['profile']['name'] );
		$this->assertSame( '', $result['state']['profile']['logo_url'] );
	}

	// ── REST ────────────────────────────────────────────────────────────

	/**
	 * The success answer, unchanged answers and the fetch log.
	 */
	public function test_rest_answer_unchanged_and_fetch_log(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'other.example.com' );

		// Nothing saved yet: enabled false, version 0.
		$empty = $this->fetch( $lic );
		$this->assertSame( 200, $empty->get_status() );
		$this->assertTrue( $empty->get_data()['success'] );
		$this->assertSame( array( 'enabled' => false, 'version' => 0, 'unchanged' => false, 'updated_at' => null ), $empty->get_data()['data'] );

		SFLS_Branding::save( $lic, array( 'name' => 'Acme Forms', 'menu_name' => 'Acme', 'support_url' => 'https://acme.example/help', 'hide_credits' => '1' ), $owner->ID );
		$res  = $this->fetch( $lic, 'https://client.example.com/', 0 );
		$data = $res->get_data()['data'];
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $data['enabled'] );
		$this->assertSame( 1, $data['version'] );
		$this->assertFalse( $data['unchanged'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $data['updated_at'] );
		$this->assertSame( 'Acme Forms', $data['profile']['name'] );
		$this->assertSame( 'Acme', $data['profile']['menu_name'] );
		$this->assertTrue( $data['profile']['hide_credits'] );
		$this->assertFalse( $data['profile']['restrict_screens'] );
		$this->assertSame( array_keys( SFLS_Branding::empty_profile() ), array_keys( $data['profile'] ) );

		// The site sends the current version: unchanged, no profile.
		$same = $this->fetch( $lic, 'https://client.example.com', '1' )->get_data()['data'];
		$this->assertTrue( $same['unchanged'] );
		$this->assertArrayNotHasKey( 'profile', $same );
		$this->assertSame( 1, $same['version'] );
		$this->assertTrue( $same['enabled'] );

		// An older version gets the profile again.
		SFLS_Branding::save( $lic, array( 'name' => 'Acme Two' ), $owner->ID );
		$newer = $this->fetch( $lic, 'https://client.example.com', 1 )->get_data()['data'];
		$this->assertFalse( $newer['unchanged'] );
		$this->assertSame( 2, $newer['version'] );
		$this->assertSame( 'Acme Two', $newer['profile']['name'] );

		// The fetch log: one entry per site, with the version it fetched.
		$sites = array();
		foreach ( SFLS_Branding::client_sites( (int) $lic['id'] ) as $site ) {
			$sites[ $site['domain'] ] = $site;
		}
		$this->assertSame( 2, $sites['client.example.com']['version'] );
		$this->assertSame( 'https://client.example.com', $sites['client.example.com']['site_url'] );
		$this->assertNotEmpty( $sites['client.example.com']['fetched_at'] );
		$this->assertNull( $sites['other.example.com']['fetched_at'], 'A site that never fetched is listed without a time.' );
	}

	/**
	 * A plan without white label gets "enabled: false, version: 0", also
	 * when branding was stored before a downgrade.
	 */
	public function test_rest_plan_without_white_label(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		SFLS_Branding::save( $lic, array( 'name' => 'Acme' ), $owner->ID );
		$this->assertSame( 1, $this->fetch( $lic )->get_data()['data']['version'] );

		SFLS_Licenses::update( (int) $lic['id'], array( 'plan' => 'pro' ) );
		$data = $this->fetch( $lic )->get_data()['data'];
		$this->assertFalse( $data['enabled'] );
		$this->assertSame( 0, $data['version'] );
		$this->assertArrayNotHasKey( 'profile', $data );

		$validate = $this->rest( 'POST', '/sfls/v1/license/validate', array( 'license_key' => $lic['license_key'], 'site_url' => 'https://client.example.com' ) )->get_data();
		$this->assertSame( 0, $validate['branding_version'] );
	}

	/**
	 * The error cases use validate's shape and codes.
	 */
	public function test_rest_errors(): void {
		[ $lic ] = $this->agency();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );

		$wrong_site = $this->fetch( $lic, 'https://not-activated.example.com' );
		$this->assertSame( 403, $wrong_site->get_status() );
		$this->assertSame( array( 'valid' => false, 'reason' => 'domain_not_activated', 'error' => 'This site is not activated for this license.' ), $wrong_site->get_data() );

		$unknown = $this->rest( 'POST', '/sfls/v1/branding', array( 'license_key' => 'SFLS-NOPE-NOPE-NOPE', 'site_url' => 'https://client.example.com' ) );
		$this->assertSame( 404, $unknown->get_status() );
		$this->assertSame( 'license_not_found', $unknown->get_data()['reason'] );
		$this->assertSame( array( 'valid', 'reason', 'error' ), array_keys( $unknown->get_data() ) );

		$missing = $this->rest( 'POST', '/sfls/v1/branding', array( 'site_url' => 'https://client.example.com' ) );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'missing_parameters', $missing->get_data()['reason'] );

		$bad = $this->fetch( $lic, 'https://client.example.com', 'abc' );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'invalid_version', $bad->get_data()['reason'] );
		$this->assertSame( 400, $this->fetch( $lic, 'https://client.example.com', -3 )->get_status() );
		$this->assertSame( 400, $this->fetch( $lic, 'https://client.example.com', 1.5 )->get_status() );

		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$expired = $this->fetch( $lic );
		$this->assertSame( 403, $expired->get_status() );
		$this->assertSame( 'expired', $expired->get_data()['reason'] );
		$this->assertFalse( $expired->get_data()['valid'] );

		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => null, 'status' => 'suspended' ) );
		$suspended = $this->fetch( $lic );
		$this->assertSame( 403, $suspended->get_status() );
		$this->assertSame( 'suspended', $suspended->get_data()['reason'] );
	}

	/**
	 * A license in its grace period is still served, like validate.
	 */
	public function test_rest_grace_period_is_served(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		SFLS_Branding::save( $lic, array( 'name' => 'Acme' ), $owner->ID );
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 'grace_period', SFLS_Licenses::state( SFLS_Licenses::find( (int) $lic['id'] ) )['status'] );
		$this->assertSame( 200, $this->fetch( $lic )->get_status() );
	}

	/**
	 * The endpoint is rate limited like validate.
	 */
	public function test_rest_is_rate_limited(): void {
		$this->filter( 'sfls_rate_limit_enabled', '__return_true' );
		$this->filter(
			'sfls_rate_limit',
			static fn() => array(
				'limit'  => 2,
				'window' => 600,
			)
		);
		[ $lic ] = $this->agency();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.40';
		$this->assertSame( 200, $this->fetch( $lic )->get_status() );
		$this->assertSame( 200, $this->fetch( $lic )->get_status() );
		$limited = $this->fetch( $lic );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'rate_limited', $limited->get_data()['reason'] );
		$this->assertNotEmpty( $limited->get_headers()['Retry-After'] );
	}

	/**
	 * Validate and status carry the branding version, flat and in data.
	 */
	public function test_validate_and_status_carry_branding_version(): void {
		[ $lic, $owner ] = $this->agency();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'client.example.com' );
		$params = array( 'license_key' => $lic['license_key'], 'site_url' => 'https://client.example.com' );

		foreach ( array( array( 'POST', '/sfls/v1/license/validate' ), array( 'GET', '/sfls/v1/license/status' ) ) as [ $method, $route ] ) {
			$data = $this->rest( $method, $route, $params )->get_data();
			$this->assertSame( 0, $data['branding_version'], $route );
			$this->assertSame( 0, $data['data']['branding_version'], $route );
		}
		SFLS_Branding::save( $lic, array( 'name' => 'Acme' ), $owner->ID );
		SFLS_Branding::save( $lic, array( 'name' => 'Acme 2' ), $owner->ID );
		foreach ( array( array( 'POST', '/sfls/v1/license/validate' ), array( 'GET', '/sfls/v1/license/status' ) ) as [ $method, $route ] ) {
			$data = $this->rest( $method, $route, $params )->get_data();
			$this->assertSame( 2, $data['branding_version'], $route );
			$this->assertSame( 2, $data['data']['branding_version'], $route );
			$this->assertTrue( $data['valid'] );
		}
		$this->assertSame( 2, $this->rest( 'POST', '/sfls/v1/license/activate', $params )->get_data()['branding_version'] );
	}

	/**
	 * Assert that a value is a WP_Error.
	 *
	 * @param mixed  $value   Value.
	 * @param string $message Failure message.
	 */
	private function assertWPError( $value, string $message = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $value, $message );
	}
}
