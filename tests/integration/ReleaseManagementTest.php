<?php
/**
 * 1.6.0 release management: migration from the options, "latest" with
 * drafts, yanked, beta and pin, per-version signed links, the expired
 * license cutoff, the versions endpoint and the members area.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification, WordPress.DB.DirectDatabaseQuery, WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.ValidatedSanitizedInput -- Test code.

/**
 * Thrown by test hooks instead of streaming a file or redirecting.
 */
class SFLS_Test_Stop extends Exception {

	/**
	 * Data.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * Constructor.
	 *
	 * @param mixed $data Data.
	 */
	public function __construct( $data ) {
		parent::__construct( 'stop' );
		$this->data = $data;
	}
}

/**
 * Release management.
 */
class SFLS_Release_Management_Test extends SFLS_Test_Case {

	/**
	 * Copy a test zip into the private folder as a 1.5.1 release file.
	 *
	 * @param string $version Version.
	 */
	private function legacy_file( string $version ): string {
		$name = 'subtleforms-pro-' . $version . '.zip';
		$dest = trailingslashit( SFLS_Releases::dir() ) . $name;
		copy( $this->pro_zip( $version ), $dest );
		$this->temp_files[] = $dest;
		return $name;
	}

	/**
	 * The two 1.5.1 options are copied into the table once, with size and
	 * sha256 from the files, and are read as a fallback until then.
	 */
	public function test_migration_from_options(): void {
		$this->isolate_releases();
		$this->set_option( SFLS_Releases::MIGRATED_OPTION, null );
		$this->set_option(
			SFLS_Releases::OPTION,
			array(
				'version'   => '9.2.0',
				'file'      => $this->legacy_file( '9.2.0' ),
				'requires'  => '6.4',
				'tested'    => '7.1',
				'changelog' => '* Two',
				'updated'   => '2026-09-01',
			)
		);
		$this->set_option(
			SFLS_Releases::HISTORY_OPTION,
			array(
				array(
					'version' => '9.1.0',
					'file'    => $this->legacy_file( '9.1.0' ),
					'updated' => '2026-08-01',
				),
				array(
					'version' => '9.0.0',
					'file'    => 'subtleforms-pro-9.0.0-missing.zip',
					'updated' => '2026-07-01',
				),
			)
		);
		SFLS_Releases::flush();

		// Fallback before the migration ran.
		$this->assertFalse( SFLS_Releases::uses_table() );
		$this->assertSame( '9.2.0', SFLS_Releases::current()['version'] );
		$this->assertCount( 3, SFLS_Releases::all() );

		$this->assertSame( 3, SFLS_Releases::migrate_from_options() );
		$this->assertTrue( SFLS_Releases::uses_table() );
		$this->assertSame( 0, SFLS_Releases::migrate_from_options(), 'Runs once.' );

		$rows = SFLS_Releases::rows();
		$this->assertSame( array( '9.2.0', '9.1.0', '9.0.0' ), wp_list_pluck( $rows, 'version' ) );
		$new = SFLS_Releases::get( '9.2.0' );
		$this->assertSame( 'published', $new['status'] );
		$this->assertSame( 'stable', $new['channel'] );
		$this->assertSame( 'upload', $new['source'] );
		$this->assertSame( '* Two', $new['changelog'] );
		$this->assertSame( '2026-09-01 12:00:00', $new['released_at'] );
		$this->assertSame( hash_file( 'sha256', trailingslashit( SFLS_Releases::dir() ) . 'subtleforms-pro-9.2.0.zip' ), $new['sha256'] );
		$this->assertSame( filesize( trailingslashit( SFLS_Releases::dir() ) . 'subtleforms-pro-9.2.0.zip' ), $new['size'] );
		$this->assertSame( '', SFLS_Releases::get( '9.0.0' )['file'], 'A missing file is not referenced.' );
		$this->assertSame( '9.2.0', SFLS_Releases::current()['version'] );
		$this->assertSame( '', SFLS_Releases::pinned() );

		// The options are left alone and no longer written.
		$this->assertSame( '9.2.0', get_option( SFLS_Releases::OPTION )['version'] );
		SFLS_Releases::update( $new['id'], array( 'changelog' => '* Changed' ) );
		$this->assertSame( '* Two', get_option( SFLS_Releases::OPTION )['changelog'] );
	}

	/**
	 * Drafts and yanked versions are never the latest; beta sites get betas;
	 * pin and unpin; yanking rolls back.
	 */
	public function test_latest_selection(): void {
		$this->add_release( '9.0.0' );
		$this->add_release( '9.1.0' );
		$this->add_release( '9.2.0', array( 'publish' => false ) ); // Draft.
		$this->add_release( '9.3.0-beta.1' ); // Beta from the hyphen.
		$this->add_release( '9.1.5' );

		$this->assertSame( 'beta', SFLS_Releases::get( '9.3.0-beta.1' )['channel'] );
		$this->assertSame( 'draft', SFLS_Releases::get( '9.2.0' )['status'] );
		$this->assertSame( '9.1.5', SFLS_Releases::latest( 'stable' )['version'] );
		$this->assertSame( '9.3.0-beta.1', SFLS_Releases::latest( 'beta' )['version'] );

		// Update API: stable by default, beta on request.
		$check = $this->rest( 'GET', '/sfls/v1/update/check' )->get_data();
		$this->assertSame( '9.1.5', $check['new_version'] );
		$this->assertSame( '9.3.0-beta.1', $this->rest( 'GET', '/sfls/v1/update/check', array( 'channel' => 'beta' ) )->get_data()['new_version'] );

		// Yank: the previous published version is the latest again.
		SFLS_Releases::yank( SFLS_Releases::get( '9.1.5' )['id'], 'Broken' );
		$this->assertSame( '9.1.0', SFLS_Releases::latest( 'stable' )['version'] );
		$this->assertSame( 'Broken', SFLS_Releases::get( '9.1.5' )['yank_reason'] );
		$this->assertNull( SFLS_Releases::find( '9.1.5' ), 'Yanked versions cannot be downloaded.' );
		$this->assertNotContains( '9.1.5', wp_list_pluck( SFLS_Releases::all(), 'version' ) );
		$this->assertNotContains( '9.2.0', wp_list_pluck( SFLS_Releases::all(), 'version' ) );

		// Publishing the draft makes it the latest.
		SFLS_Releases::publish( SFLS_Releases::get( '9.2.0' )['id'] );
		$this->assertSame( '9.2.0', SFLS_Releases::latest( 'stable' )['version'] );
		$this->assertNotEmpty( SFLS_Releases::get( '9.2.0' )['released_at'] );

		// Pin an older version.
		$this->assertTrue( SFLS_Releases::pin( '9.0.0' ) );
		$this->assertSame( '9.0.0', SFLS_Releases::latest( 'stable' )['version'] );
		$this->assertSame( '9.0.0', SFLS_Releases::current()['version'] );
		$this->assertSame( '9.3.0-beta.1', SFLS_Releases::latest( 'beta' )['version'], 'Beta sites still get the newer beta.' );
		$this->assertSame( '9.0.0', $this->rest( 'GET', '/sfls/v1/update/check' )->get_data()['new_version'] );
		$this->assertWPError( SFLS_Releases::pin( '9.3.0-beta.1' ) );
		$this->assertWPError( SFLS_Releases::pin( '9.1.5' ) );
		SFLS_Releases::unpin();
		$this->assertSame( '9.2.0', SFLS_Releases::latest( 'stable' )['version'] );

		// Yanking the pinned version unpins it.
		SFLS_Releases::pin( '9.1.0' );
		SFLS_Releases::yank( SFLS_Releases::get( '9.1.0' )['id'] );
		$this->assertSame( '', SFLS_Releases::pinned() );
		$this->assertSame( '9.2.0', SFLS_Releases::latest( 'stable' )['version'] );

		// Unpublish to draft.
		SFLS_Releases::unpublish( SFLS_Releases::get( '9.2.0' )['id'] );
		$this->assertSame( '9.0.0', SFLS_Releases::latest( 'stable' )['version'] );

		// Delete removes the file.
		$path = SFLS_Releases::package_path( SFLS_Releases::get( '9.0.0' ) );
		$this->assertFileExists( $path );
		SFLS_Releases::delete( SFLS_Releases::get( '9.0.0' )['id'] );
		$this->assertFileDoesNotExist( $path );
		$this->assertNull( SFLS_Releases::get( '9.0.0' ) );
	}

	/**
	 * Links are bound to the version; links without one still verify.
	 */
	public function test_signed_links_are_bound_to_the_version(): void {
		$url = SFLS_Releases::signed_url( 'SFP-AAAA', 'example.com', '9.1.0' );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( '9.1.0', $q['version'] );
		$this->assertTrue( SFLS_Releases::verify( 'SFP-AAAA', 'example.com', (int) $q['expires'], $q['sig'], $q['token'], '9.1.0' ) );
		$this->assertFalse( SFLS_Releases::verify( 'SFP-AAAA', 'example.com', (int) $q['expires'], $q['sig'], $q['token'], '9.2.0' ), 'Another version.' );
		$this->assertFalse( SFLS_Releases::verify( 'SFP-AAAA', 'example.com', (int) $q['expires'], $q['sig'], $q['token'] ), 'Version dropped.' );

		$old = SFLS_Releases::signed_url( 'SFP-AAAA', 'example.com' );
		wp_parse_str( (string) wp_parse_url( $old, PHP_URL_QUERY ), $o );
		$this->assertArrayNotHasKey( 'version', $o );
		$this->assertTrue( SFLS_Releases::verify( 'SFP-AAAA', 'example.com', (int) $o['expires'], $o['sig'], $o['token'] ), '1.5 links keep working.' );
	}

	/**
	 * Stop at the moment a download would stream.
	 */
	private function catch_downloads(): void {
		$this->filter(
			'sfls_release_downloaded',
			static function ( $release, $lic, $domain ) {
				throw new SFLS_Test_Stop( array( $release['version'], $lic['license_key'], $domain ) );
			},
			10,
			3
		);
	}

	/**
	 * Run a download request and return what would have been streamed, or
	 * the error response.
	 *
	 * @param array $query Query args of a signed link.
	 * @return array|WP_REST_Response
	 */
	private function download( array $query ) {
		try {
			return $this->rest( 'GET', '/sfls/v1/update/download', $query );
		} catch ( SFLS_Test_Stop $stop ) {
			return $stop->data;
		}
	}

	/**
	 * An expired license keeps the versions released while it was valid,
	 * over REST (package, download, versions) and with a counted download.
	 */
	public function test_expired_license_cutoff(): void {
		$this->catch_downloads();
		$old = $this->add_release( '9.1.0', array( 'released_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$this->add_release( '9.2.0', array( 'released_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );
		$this->add_release( '9.3.0', array( 'publish' => false ) );

		$lic = $this->make_license();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'shop.example.com' );
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
		$lic = SFLS_Licenses::find( (int) $lic['id'] );
		$this->assertFalse( SFLS_Licenses::state( $lic )['valid'] );

		$this->assertTrue( SFLS_Releases::allowed( $lic, SFLS_Releases::get( '9.1.0' ) ) );
		$this->assertFalse( SFLS_Releases::allowed( $lic, SFLS_Releases::get( '9.2.0' ) ) );
		$this->assertFalse( SFLS_Releases::allowed( $lic, SFLS_Releases::get( '9.3.0' ) ), 'Drafts never.' );

		$site = array(
			'license_key' => $lic['license_key'],
			'site_url'    => 'https://shop.example.com',
		);

		// Versions endpoint.
		$versions = $this->rest( 'GET', '/sfls/v1/update/versions', $site )->get_data();
		$this->assertSame( array( '9.1.0' ), wp_list_pluck( $versions['versions'], 'version' ) );
		$this->assertSame( 'expired', $versions['status'] );
		$this->assertStringContainsString( 'version=9.1.0', $versions['versions'][0]['package'] );

		// Package: the latest needs a valid license, an older version does not.
		$this->assertSame( 'expired', $this->rest( 'GET', '/sfls/v1/update/package', $site )->get_data()['reason'] );
		$this->assertSame( 403, $this->rest( 'GET', '/sfls/v1/update/package', $site + array( 'version' => '9.2.0' ) )->get_status() );
		$package = $this->rest( 'GET', '/sfls/v1/update/package', $site + array( 'version' => '9.1.0' ) );
		$this->assertSame( 302, $package->get_status() );
		wp_parse_str( (string) wp_parse_url( $package->get_headers()['Location'], PHP_URL_QUERY ), $q );
		$this->assertSame( '9.1.0', $q['version'] );

		// The download streams 9.1.0 and counts it.
		$this->assertSame( array( '9.1.0', $lic['license_key'], 'shop.example.com' ), $this->download( $q ) );
		$this->assertSame( $old['downloads'] + 1, SFLS_Releases::get( '9.1.0', true )['downloads'] );
		$again = $this->download( $q );
		$this->assertSame( 'link_used', $again->get_data()['reason'], 'Single use.' );

		// A link signed for 9.2.0 is refused for this license.
		$url = SFLS_Releases::signed_url( $lic['license_key'], 'shop.example.com', '9.2.0' );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $n );
		$this->assertSame( 'expired', $this->download( $n )->get_data()['reason'] );

		// A suspended license keeps what was released before it was suspended.
		$sus = $this->make_license( array( 'months' => 0 ) );
		SFLS_Licenses::update( (int) $sus['id'], array( 'status' => 'suspended' ) );
		global $wpdb;
		$wpdb->update( SFLS_DB::licenses(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), array( 'id' => (int) $sus['id'] ) );
		$sus = SFLS_Licenses::find( (int) $sus['id'] );
		$this->assertSame( array( '9.1.0' ), wp_list_pluck( SFLS_Releases::allowed_for( $sus ), 'version' ) );

		// A valid license gets everything published.
		$valid = $this->make_license();
		$this->assertSame( array( '9.2.0', '9.1.0' ), wp_list_pluck( SFLS_Releases::allowed_for( $valid ), 'version' ) );
	}

	/**
	 * The versions endpoint: stable by default, betas on request, package
	 * links only for activated sites, latest marked.
	 */
	public function test_versions_endpoint(): void {
		$this->add_release( '9.1.0', array( 'changelog' => '* One' ) );
		$this->add_release( '9.2.0' );
		$this->add_release( '9.3.0-beta.1' );
		$y = $this->add_release( '9.2.1' );
		SFLS_Releases::yank( $y['id'] );

		$lic  = $this->make_license();
		$args = array(
			'license_key' => $lic['license_key'],
			'site_url'    => 'https://new.example.com',
		);
		$data = $this->rest( 'GET', '/sfls/v1/update/versions', $args )->get_data();
		$this->assertFalse( $data['activated'] );
		$this->assertSame( array( '9.2.0', '9.1.0' ), wp_list_pluck( $data['versions'], 'version' ) );
		$this->assertTrue( $data['versions'][0]['latest'] );
		$this->assertSame( '', $data['versions'][0]['package'], 'No package link before the site is activated.' );
		$this->assertSame( '* One', $data['versions'][1]['changelog'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $data['versions'][1]['sha256'] );

		SFLS_Licenses::add_activation( (int) $lic['id'], 'new.example.com' );
		$beta = $this->rest( 'GET', '/sfls/v1/update/versions', $args + array( 'channel' => 'beta' ) )->get_data();
		$this->assertSame( array( '9.3.0-beta.1', '9.2.0', '9.1.0' ), wp_list_pluck( $beta['versions'], 'version' ) );
		$this->assertStringContainsString( '/sfls/v1/update/package', $beta['versions'][0]['package'] );

		$this->assertSame( 404, $this->rest( 'GET', '/sfls/v1/update/versions', array( 'license_key' => 'SFP-NOPE', 'site_url' => 'x.com' ) )->get_status() );

		// Package with a beta channel serves the beta.
		$package = $this->rest( 'GET', '/sfls/v1/update/package', $args + array( 'channel' => 'beta' ) );
		$this->assertStringContainsString( 'version=9.3.0-beta.1', $package->get_headers()['Location'] );
		$check = $this->rest( 'GET', '/sfls/v1/update/check', $args + array( 'channel' => 'beta' ) )->get_data();
		$this->assertStringContainsString( 'channel=beta', $check['package'] );
	}

	/**
	 * A 1.5 link without a version still downloads the current release.
	 */
	public function test_old_link_downloads_current_release(): void {
		$this->catch_downloads();
		$this->add_release( '9.1.0' );
		$this->add_release( '9.2.0' );
		$lic = $this->make_license();
		wp_parse_str( (string) wp_parse_url( SFLS_Releases::signed_url( $lic['license_key'], 'shop.example.com' ), PHP_URL_QUERY ), $q );
		$this->assertSame( '9.2.0', $this->download( $q )[0] );
	}

	/**
	 * Members area: latest release prominently, every older published version
	 * with its changelog and a download button; an expired license only gets
	 * buttons for versions released while it was valid. The button leads to
	 * a signed link for that license and version.
	 */
	public function test_members_area_older_versions(): void {
		$this->add_release( '9.0.0', array( 'released_at' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) ) );
		$this->add_release(
			'9.1.0',
			array(
				'released_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ),
				'changelog'   => "* Older change\n* Another",
			)
		);
		$this->add_release( '9.2.0-beta.1', array( 'released_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ) ) );
		$this->add_release( '9.2.0', array( 'released_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );
		$this->add_release( '9.3.0', array( 'publish' => false ) );
		$y = $this->add_release( '9.1.1' );
		SFLS_Releases::yank( $y['id'] );

		$user = $this->make_user();
		$lic  = $this->make_license(
			array(
				'customer_email' => $user->user_email,
				'user_id'        => $user->ID,
			)
		);
		wp_set_current_user( $user->ID );

		$html = SFLS_Account::downloads_html( $user );
		$this->assertStringContainsString( 'SubtleForms Pro 9.2.0', $html );
		$this->assertStringContainsString( 'Older versions', $html );
		$this->assertStringContainsString( 'version-9-1-0', $html );
		$this->assertStringContainsString( 'Older change', $html );
		$this->assertStringContainsString( 'What changed', $html );
		$this->assertStringContainsString( 'sfls-tag--beta', $html );
		$this->assertStringNotContainsString( '9.3.0', $html, 'Drafts are hidden.' );
		$this->assertStringNotContainsString( '9.1.1', $html, 'Yanked versions are hidden.' );
		$this->assertSame( 4, substr_count( $html, 'sfls_download=' ), 'Latest plus three older versions.' );

		// The shortcode shows the same list.
		$this->assertStringContainsString( 'Older versions', do_shortcode( '[sfls_downloads]' ) );

		// Expired: only versions released while it was valid.
		SFLS_Licenses::update( (int) $lic['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
		$html = SFLS_Account::downloads_html( $user );
		$this->assertSame( 2, substr_count( $html, 'sfls_download=' ), '9.1.0 and 9.0.0 only.' );
		$this->assertStringContainsString( 'Renew to download', $html );
		$this->assertStringContainsString( 'You can still download the versions released while it was active', $html );

		// The button sends the browser to a fresh signed link for that version.
		$_GET['sfls_download']  = '9.1.0';
		$_GET['_wpnonce']       = wp_create_nonce( 'sfls_download' );
		$_REQUEST['_wpnonce']   = $_GET['_wpnonce'];
		$this->filter(
			'wp_redirect',
			static function ( $location ) {
				throw new SFLS_Test_Stop( $location );
			}
		);
		try {
			( new SFLS_Account() )->handle_download();
			$this->fail( 'Expected a redirect.' );
		} catch ( SFLS_Test_Stop $stop ) {
			$location = (string) $stop->data;
		} finally {
			unset( $_GET['sfls_download'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );
		}
		$this->assertStringContainsString( '/sfls/v1/update/download', $location );
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $q );
		$this->assertSame( '9.1.0', $q['version'] );
		$this->assertSame( 'account', $q['site'] );
		$this->assertTrue( SFLS_Releases::verify( $lic['license_key'], 'account', (int) $q['expires'], $q['sig'], $q['token'], '9.1.0' ) );
	}

	/**
	 * The admin Releases screen lists every version with its actions, and the
	 * settings tab has tokens, the CI API and GitHub.
	 */
	public function test_admin_screens_render(): void {
		$this->add_release( '9.0.0' );
		$this->add_release( '9.1.0', array( 'git_ref' => 'v9.1.0', 'git_commit' => str_repeat( 'a', 40 ) ) );
		$this->add_release( '9.2.0', array( 'publish' => false ) );
		$this->set_option( SFLS_GitHub::OPTION, array( 'repo' => 'acme/subtleforms-pro' ) );
		wp_set_current_user( 1 );
		ob_start();
		( new SFLS_Admin() )->page_release();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'sfls-release-table', $html );
		$this->assertStringContainsString( 'Make this the latest', $html );
		$this->assertStringContainsString( 'Unpublish to draft', $html );
		$this->assertStringContainsString( 'Download zip', $html );
		$this->assertStringContainsString( 'https://github.com/acme/subtleforms-pro/commit/' . str_repeat( 'a', 40 ), $html );
		$this->assertStringContainsString( 'https://github.com/acme/subtleforms-pro/releases/tag/v9.1.0', $html );
		$this->assertStringContainsString( 'Upload a release', $html );

		$_GET['tab'] = 'releases';
		ob_start();
		( new SFLS_Admin() )->page_settings();
		$settings = (string) ob_get_clean();
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'Deploy tokens', $settings );
		$this->assertStringContainsString( 'Auto publish CI releases', $settings );
		$this->assertStringContainsString( 'GitHub connection', $settings );
		$this->assertStringContainsString( '/sfls/v1/github/webhook', $settings );
		$this->assertStringContainsString( 'Recent GitHub deliveries', $settings );
	}

	/**
	 * Helper.
	 *
	 * @param mixed $value Value.
	 */
	private function assertWPError( $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
	}
}
