<?php
/**
 * Pro issue: updates failed with "package does not match the signature"
 * after a new release was published. The package address of an update check
 * names the version the answer offered, so the download is that package.
 *
 * @package SFLS
 */

/**
 * Update downloads pinned to the offered version.
 */
class SFLS_Pinned_Download_Test extends SFLS_Test_Case {

	const SITE = 'https://pinned.example.com';

	/**
	 * A license activated on the test site.
	 */
	private function site_license(): array {
		$lic = $this->make_license();
		SFLS_Licenses::add_activation( (int) $lic['id'], 'pinned.example.com' );
		return $lic;
	}

	/**
	 * The update check of the test site.
	 *
	 * @param array  $lic     License.
	 * @param string $channel stable | beta.
	 */
	private function check( array $lic, string $channel = 'stable' ): array {
		return $this->rest(
			'GET',
			'/sfls/v1/update/check',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => self::SITE,
				'channel'     => $channel,
			)
		)->get_data();
	}

	/**
	 * Query arguments of an address.
	 *
	 * @param string $url Address.
	 */
	private function args( string $url ): array {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return $args;
	}

	/**
	 * Follow a package address to the signed download link.
	 *
	 * @param string $package Package address of an update check.
	 */
	private function package( string $package ): WP_REST_Response {
		return $this->rest( 'GET', '/sfls/v1/update/package', $this->args( $package ) );
	}

	/**
	 * The version a download link would stream. The download route streams
	 * and exits, so the test stops it at the action that runs just before.
	 *
	 * @param string $location Signed link.
	 */
	private function served_version( string $location ): string {
		$served = '';
		$hook   = static function ( $release ) use ( &$served ) {
			$served = $release['version'];
			throw new RuntimeException( 'stop before streaming' );
		};
		add_action( 'sfls_release_downloaded', $hook );
		try {
			$this->rest( 'GET', '/sfls/v1/update/download', $this->args( $location ) );
		} catch ( RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_action( 'sfls_release_downloaded', $hook );
		}
		return $served;
	}

	/**
	 * The address names the offered version, and a version published after
	 * the check does not change what that address downloads.
	 */
	public function test_package_address_carries_the_version_and_pins_the_download(): void {
		$this->add_release( '9.8.0' );
		$lic   = $this->site_license();
		$check = $this->check( $lic );

		$this->assertSame( '9.8.0', $check['new_version'] );
		$this->assertSame( '9.8.0', $this->args( $check['package'] )['version'] );

		// The release the site was offered is no longer the newest.
		$newer = $this->add_release( '9.8.1' );
		$this->assertSame( '9.8.1', $this->check( $lic )['new_version'] );

		$package = $this->package( $check['package'] );
		$this->assertSame( 302, $package->get_status() );
		$location = $package->get_headers()['Location'];
		$this->assertSame( '9.8.0', $this->args( $location )['version'] );
		$this->assertSame( '9.8.0', $this->served_version( $location ), 'The site gets the version whose sha256 it holds.' );
		$this->assertNotSame( $newer['sha256'], SFLS_Releases::get( '9.8.0' )['sha256'] );
	}

	/**
	 * A new check offers the new version with its own address.
	 */
	public function test_a_fresh_check_offers_the_new_version(): void {
		$this->add_release( '9.8.0' );
		$lic = $this->site_license();
		$this->add_release( '9.8.1' );

		$check = $this->check( $lic );

		$this->assertSame( '9.8.1', $check['new_version'] );
		$this->assertSame( '9.8.1', $this->args( $check['package'] )['version'] );
		$this->assertSame( '9.8.1', $this->served_version( $this->package( $check['package'] )->get_headers()['Location'] ) );
	}

	/**
	 * Beta sites keep their channel in the address.
	 */
	public function test_beta_address_has_channel_and_version(): void {
		$this->add_release( '9.8.0' );
		$this->add_release( '9.9.0-beta.1', array( 'channel' => 'beta' ) );
		$lic = $this->site_license();

		$args = $this->args( $this->check( $lic, 'beta' )['package'] );

		$this->assertSame( 'beta', $args['channel'] );
		$this->assertSame( '9.9.0-beta.1', $args['version'] );
	}

	/**
	 * Addresses cached before 1.9.2 have no version and still get the
	 * current release.
	 */
	public function test_an_address_without_a_version_still_works(): void {
		$this->add_release( '9.8.0' );
		$lic = $this->site_license();
		$old = SFLS_REST::package_address( $lic, 'pinned.example.com' );
		$this->assertArrayNotHasKey( 'version', $this->args( $old ) );
		$this->add_release( '9.8.1' );

		$package = $this->package( $old );

		$this->assertSame( 302, $package->get_status() );
		$this->assertSame( '9.8.1', $this->served_version( $package->get_headers()['Location'] ) );
	}

	/**
	 * A version that was yanked after the check answers 410 with a message
	 * that tells the site to check again, at the package address and at a
	 * download link handed out before the yank.
	 */
	public function test_a_yanked_version_answers_410(): void {
		$this->add_release( '9.8.0' );
		$lic   = $this->site_license();
		$check = $this->check( $lic );
		$link  = $this->package( $check['package'] )->get_headers()['Location'];

		SFLS_Releases::yank( (int) SFLS_Releases::get( '9.8.0' )['id'], 'bad build' );

		$package = $this->package( $check['package'] );
		$this->assertSame( 410, $package->get_status() );
		$this->assertSame( 'version_withdrawn', $package->get_data()['reason'] );
		$this->assertStringContainsString( 'Check for updates again', $package->get_data()['error'] );

		$download = $this->rest( 'GET', '/sfls/v1/update/download', $this->args( $link ) );
		$this->assertSame( 410, $download->get_status() );
		$this->assertSame( 'version_withdrawn', $download->get_data()['reason'] );

		// The link was not used up by the refused request.
		$this->assertSame( '', $this->served_version( $link ) );
	}

	/**
	 * After the yank the next check offers the version that is live.
	 */
	public function test_the_check_after_a_yank_offers_the_live_version(): void {
		$this->add_release( '9.8.0' );
		$this->add_release( '9.8.1' );
		$lic = $this->site_license();
		$old = $this->check( $lic );
		$this->assertSame( '9.8.1', $old['new_version'] );

		SFLS_Releases::yank( (int) SFLS_Releases::get( '9.8.1' )['id'] );

		$this->assertSame( 410, $this->package( $old['package'] )->get_status() );
		$fresh = $this->check( $lic );
		$this->assertSame( '9.8.0', $fresh['new_version'] );
		$this->assertSame( '9.8.0', $this->served_version( $this->package( $fresh['package'] )->get_headers()['Location'] ) );
	}

	/**
	 * A version that never existed is still "no package", not "withdrawn".
	 */
	public function test_an_unknown_version_is_not_found(): void {
		$this->add_release( '9.8.0' );
		$lic  = $this->site_license();
		$args = $this->args( $this->check( $lic )['package'] );
		$args['version'] = '0.0.1';

		$response = $this->rest( 'GET', '/sfls/v1/update/package', $args );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'no_package', $response->get_data()['reason'] );
	}

	/**
	 * "Make this the latest" rolls back below a version a site was offered:
	 * the cached address answers 410, the next check offers the pinned version.
	 */
	public function test_an_address_above_a_new_pin_answers_410(): void {
		$this->add_release( '9.8.0' );
		$this->add_release( '9.8.1' );
		$lic   = $this->site_license();
		$check = $this->check( $lic );
		$this->assertSame( '9.8.1', $check['new_version'] );

		$this->assertTrue( SFLS_Releases::pin( '9.8.0' ) );

		$package = $this->package( $check['package'] );
		$this->assertSame( 410, $package->get_status() );
		$this->assertSame( 'version_withdrawn', $package->get_data()['reason'] );
		$this->assertStringContainsString( 'Check for updates again', $package->get_data()['error'] );

		$fresh = $this->check( $lic );
		$this->assertSame( '9.8.0', $fresh['new_version'] );
		$this->assertSame( 302, $this->package( $fresh['package'] )->get_status() );

		// Without a pin the newer version is served again.
		SFLS_Releases::unpin();
		$this->assertSame( 302, $this->package( $check['package'] )->get_status() );
	}

	/**
	 * A version unpublished to a draft after the check answers 410, not 404, at
	 * the package address and at a link handed out before.
	 */
	public function test_an_unpublished_version_answers_410(): void {
		$this->add_release( '9.8.0' );
		$lic   = $this->site_license();
		$check = $this->check( $lic );
		$link  = $this->package( $check['package'] )->get_headers()['Location'];

		SFLS_Releases::unpublish( (int) SFLS_Releases::get( '9.8.0' )['id'] );

		$package = $this->package( $check['package'] );
		$this->assertSame( 410, $package->get_status() );
		$this->assertSame( 'version_withdrawn', $package->get_data()['reason'] );

		$download = $this->rest( 'GET', '/sfls/v1/update/download', $this->args( $link ) );
		$this->assertSame( 410, $download->get_status() );
		$this->assertSame( 'version_withdrawn', $download->get_data()['reason'] );
	}

	/**
	 * A stable site cannot fetch a beta by editing version=; a beta site can.
	 * A download link cannot be edited at all: the version is signed.
	 */
	public function test_a_stable_site_cannot_fetch_a_beta_by_editing_the_version(): void {
		$this->add_release( '9.8.0' );
		$this->add_release( '9.9.0-beta.1', array( 'channel' => 'beta' ) );
		$lic  = $this->site_license();
		$args = $this->args( $this->check( $lic )['package'] );
		$this->assertSame( '9.8.0', $args['version'] );

		$args['version'] = '9.9.0-beta.1';
		$edited          = $this->rest( 'GET', '/sfls/v1/update/package', $args );
		$this->assertSame( 410, $edited->get_status() );
		$this->assertSame( 'wrong_channel', $edited->get_data()['reason'] );

		// The same address from a beta site is served.
		$beta = $this->check( $lic, 'beta' );
		$this->assertSame( '9.9.0-beta.1', $beta['new_version'] );
		$this->assertSame( 302, $this->package( $beta['package'] )->get_status() );

		// Editing the version of a signed download link breaks the signature.
		$link             = $this->package( $this->check( $lic )['package'] )->get_headers()['Location'];
		$link_args        = $this->args( $link );
		$link_args['version'] = '9.9.0-beta.1';
		$download         = $this->rest( 'GET', '/sfls/v1/update/download', $link_args );
		$this->assertSame( 403, $download->get_status() );
		$this->assertSame( 'invalid_signature', $download->get_data()['reason'] );
	}

	/**
	 * The versions list of a site.
	 *
	 * @param array  $lic     License.
	 * @param string $channel stable | beta.
	 */
	private function versions( array $lic, string $channel = 'stable' ): array {
		$data = $this->rest(
			'GET',
			'/sfls/v1/update/versions',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => self::SITE,
				'channel'     => $channel,
			)
		)->get_data();
		return wp_list_pluck( $data['versions'], 'version' );
	}

	/**
	 * The versions list hides stable versions above a new pin, so it offers
	 * only versions the package address will serve.
	 */
	public function test_the_versions_list_hides_versions_above_the_pin(): void {
		$this->add_release( '9.8.0' );
		$this->add_release( '9.8.1' );
		$this->add_release( '9.8.2' );
		$lic = $this->site_license();
		$this->assertSame( array( '9.8.2', '9.8.1', '9.8.0' ), $this->versions( $lic ) );

		$this->assertTrue( SFLS_Releases::pin( '9.8.1' ) );

		$this->assertSame( array( '9.8.1', '9.8.0' ), $this->versions( $lic ) );

		// Every version the list still offers is served.
		$data = $this->rest( 'GET', '/sfls/v1/update/versions', array( 'license_key' => $lic['license_key'], 'site_url' => self::SITE ) )->get_data();
		foreach ( $data['versions'] as $row ) {
			$this->assertSame( 302, $this->package( $row['package'] )->get_status(), $row['version'] );
		}

		SFLS_Releases::unpin();
		$this->assertSame( array( '9.8.2', '9.8.1', '9.8.0' ), $this->versions( $lic ) );
	}

	/**
	 * A signed download link handed out before a rollback does not serve the
	 * version above the pin, and the refused request does not use the link up.
	 */
	public function test_a_signed_link_for_a_version_above_the_pin_answers_410(): void {
		$this->add_release( '9.8.0' );
		$this->add_release( '9.8.1' );
		$lic   = $this->site_license();
		$check = $this->check( $lic );
		$this->assertSame( '9.8.1', $check['new_version'] );
		$link = $this->package( $check['package'] )->get_headers()['Location'];

		$this->assertTrue( SFLS_Releases::pin( '9.8.0' ) );

		$download = $this->rest( 'GET', '/sfls/v1/update/download', $this->args( $link ) );
		$this->assertSame( 410, $download->get_status() );
		$this->assertSame( 'version_withdrawn', $download->get_data()['reason'] );
		$this->assertStringContainsString( 'Check for updates again', $download->get_data()['error'] );
		$this->assertSame( '', $this->served_version( $link ), 'The pinned server never streams the higher version.' );

		// Without the pin the same link is served, so the refusal did not burn it.
		SFLS_Releases::unpin();
		$this->assertSame( '9.8.1', $this->served_version( $link ) );
	}

	/**
	 * The version at the pin itself and below it still downloads on a signed link.
	 */
	public function test_a_signed_link_at_the_pin_is_served(): void {
		$this->add_release( '9.8.0' );
		$this->add_release( '9.8.1' );
		$lic   = $this->site_license();
		$this->assertTrue( SFLS_Releases::pin( '9.8.0' ) );
		$fresh = $this->check( $lic );
		$link  = $this->package( $fresh['package'] )->get_headers()['Location'];

		$this->assertSame( '9.8.0', $this->served_version( $link ) );
	}

	/**
	 * Beta sites are not hidden from their own channel: a beta above the pin
	 * stays in the list and downloads, while stable versions above the pin go.
	 */
	public function test_the_beta_channel_is_unaffected_by_the_pin(): void {
		$this->add_release( '9.8.0' );
		$this->add_release( '9.8.1' );
		$this->add_release( '9.9.0-beta.1', array( 'channel' => 'beta' ) );
		$lic = $this->site_license();
		$this->assertTrue( SFLS_Releases::pin( '9.8.0' ) );

		$this->assertSame( array( '9.9.0-beta.1', '9.8.0' ), $this->versions( $lic, 'beta' ) );
		$this->assertSame( array( '9.8.0' ), $this->versions( $lic, 'stable' ) );

		$beta = $this->check( $lic, 'beta' );
		$this->assertSame( '9.9.0-beta.1', $beta['new_version'] );
		$link = $this->package( $beta['package'] )->get_headers()['Location'];
		$this->assertSame( '9.9.0-beta.1', $this->served_version( $link ) );
	}
}
