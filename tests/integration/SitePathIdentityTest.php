<?php
/**
 * Pro issue 46: a seat belongs to a host and a path, so a copy of a site in a
 * subdirectory (example.com/staging) no longer shares the seat of the site
 * at the root (example.com).
 *
 * @package SFLS
 */

/**
 * Site identity with a path.
 */
class SFLS_Site_Path_Identity_Test extends SFLS_Test_Case {

	/**
	 * Mark an activation as last seen some time ago.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Domain.
	 * @param int    $ago        Seconds ago.
	 */
	private function seen_ago( int $license_id, string $domain, int $ago ): void {
		global $wpdb;
		$wpdb->update(
			SFLS_DB::activations(),
			array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - $ago ) ),
			array(
				'license_id' => $license_id,
				'domain'     => $domain,
			)
		);
	}

	/**
	 * Call a license route for a site.
	 *
	 * @param string $route Route under license/.
	 * @param array  $lic   License.
	 * @param string $site  Site address.
	 * @param string $verb  Method.
	 */
	private function call( string $route, array $lic, string $site, string $verb = 'POST' ): WP_REST_Response {
		return $this->rest(
			$verb,
			'/sfls/v1/license/' . $route,
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => $site,
			)
		);
	}

	/**
	 * Rows of a license, by domain.
	 *
	 * @param array $lic License.
	 * @return string[]
	 */
	private function domains( array $lic ): array {
		$list = wp_list_pluck( SFLS_Licenses::activations( (int) $lic['id'] ), 'domain' );
		sort( $list );
		return $list;
	}

	/**
	 * The identity is the host and the path, in one form.
	 */
	public function test_normalize_domain_keeps_the_path(): void {
		$cases = array(
			'https://example.com'                      => 'example.com',
			'https://example.com/'                     => 'example.com',
			'example.com'                              => 'example.com',
			'https://www.Example.COM./'                => 'example.com',
			'https://example.com/staging'              => 'example.com/staging',
			'https://example.com/staging/'             => 'example.com/staging',
			'https://EXAMPLE.com/Staging//Two/'        => 'example.com/staging/two',
			'https://www.example.com/shop?x=1#top'     => 'example.com/shop',
			'https://example.com:8443/staging'         => 'example.com/staging',
			'http://user:pass@example.com/staging/'    => 'example.com/staging',
			'example.com/staging'                      => 'example.com/staging',
			'https://example.com/a|b c/d'              => 'example.com/abc/d',
			'https://example.com/site-2/en_us'         => 'example.com/site-2/en_us',
			''                                         => '',
			'https:///staging'                         => '',
		);
		foreach ( $cases as $input => $expected ) {
			$this->assertSame( $expected, SFLS_Licenses::normalize_domain( $input ), $input );
		}
		$this->assertLessThanOrEqual( 190, strlen( SFLS_Licenses::normalize_domain( 'https://example.com/' . str_repeat( 'a/', 200 ) ) ) );

		// A stored identity normalises to itself.
		foreach ( array( 'example.com', 'example.com/staging', 'example.com/a/b' ) as $stored ) {
			$this->assertSame( $stored, SFLS_Licenses::normalize_domain( $stored ) );
		}

		$this->assertSame( 'example.com', SFLS_Licenses::host_of( 'example.com/staging/two' ) );
		$this->assertSame( 'example.com', SFLS_Licenses::host_of( 'example.com' ) );
	}

	/**
	 * Staging and local rules look at the host, whatever the path is.
	 */
	public function test_local_rules_use_the_host(): void {
		$this->assertTrue( SFLS_Licenses::is_local_domain( 'shop.test/staging' ) );
		$this->assertTrue( SFLS_Licenses::is_local_domain( 'localhost/site' ) );
		$this->assertTrue( SFLS_Licenses::is_local_domain( 'staging.client.com/blog' ) );
		$this->assertFalse( SFLS_Licenses::is_local_domain( 'client.com/staging' ), 'A path called staging is not a staging host.' );
		$this->assertFalse( SFLS_Licenses::is_local_domain( 'client.com/shop.test' ) );
		$this->assertTrue( SFLS_Licenses::is_dev_host( 'shop.test/x' ) );
	}

	/**
	 * Two paths on one host are two seats, and deactivating one leaves the
	 * other (the case in the issue).
	 */
	public function test_two_paths_are_two_seats_and_deactivating_one_keeps_the_other(): void {
		$lic = $this->make_license( array( 'plan' => 'pro' ) );

		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://live-seat.example.com' )->get_status() );
		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://live-seat.example.com/staging' )->get_status() );
		$this->assertSame( array( 'live-seat.example.com', 'live-seat.example.com/staging' ), $this->domains( $lic ) );
		$this->assertSame( 2, SFLS_Licenses::used_slots( (int) $lic['id'] ) );

		// Staging deactivates itself: production keeps its seat.
		$this->assertTrue( $this->call( 'deactivate', $lic, 'https://live-seat.example.com/staging/' )->get_data()['deactivated'] );
		$this->assertSame( array( 'live-seat.example.com' ), $this->domains( $lic ) );
		$data = $this->call( 'validate', $lic, 'https://live-seat.example.com' )->get_data();
		$this->assertTrue( $data['valid'] );
		$this->assertSame( 1, (int) $data['sites_used'] );

		// And the other way round.
		$this->call( 'activate', $lic, 'https://live-seat.example.com/staging' );
		$this->call( 'deactivate', $lic, 'https://live-seat.example.com' );
		$this->assertSame( array( 'live-seat.example.com/staging' ), $this->domains( $lic ) );
		$this->assertTrue( $this->call( 'validate', $lic, 'https://live-seat.example.com/staging' )->get_data()['valid'] );
		$this->assertSame( 'domain_not_activated', $this->call( 'validate', $lic, 'https://live-seat.example.com' )->get_data()['reason'] );
	}

	/**
	 * A one-site license: a copy in a subdirectory does not get production's
	 * seat, and does not disturb it.
	 */
	public function test_a_copy_in_a_subdirectory_needs_its_own_seat(): void {
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://one-seat.example.com' )->get_status() );

		$copy = $this->call( 'activate', $lic, 'https://one-seat.example.com/staging' );
		$this->assertSame( 400, $copy->get_status() );
		$this->assertSame( 'activation_limit', $copy->get_data()['reason'] );
		$this->assertSame( array( 'one-seat.example.com' ), $this->domains( $lic ) );
		$this->assertTrue( $this->call( 'validate', $lic, 'https://one-seat.example.com' )->get_data()['valid'] );
	}

	/**
	 * Servers before 1.9.2 and Pro before 2.8.2 send the host only. That
	 * keeps working for a site that holds a host-only row.
	 */
	public function test_host_only_requests_still_work(): void {
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'old-pro.example.com' );

		$this->assertTrue( $this->call( 'validate', $lic, 'https://old-pro.example.com' )->get_data()['valid'] );
		$this->assertTrue( $this->call( 'validate', $lic, 'old-pro.example.com' )->get_data()['valid'] );
		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://old-pro.example.com' )->get_status() );
		$this->assertSame( array( 'old-pro.example.com' ), $this->domains( $lic ) );
	}

	/**
	 * A host-only row that went quiet is taken over once by the site that now
	 * sends its path, with the same seat, and the change is logged.
	 */
	public function test_a_quiet_host_only_row_is_upgraded_in_place(): void {
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'legacy-sub.example.com', '2.8.1' );
		$this->seen_ago( (int) $lic['id'], 'legacy-sub.example.com', 3 * DAY_IN_SECONDS );

		$data = $this->call( 'validate', $lic, 'https://legacy-sub.example.com/shop/' )->get_data();

		$this->assertTrue( $data['valid'], 'The first validate with the new identity is accepted.' );
		$this->assertSame( array( 'legacy-sub.example.com/shop' ), $this->domains( $lic ) );
		$this->assertSame( 1, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
		$this->assertSame( '2.8.1', SFLS_Licenses::activation( (int) $lic['id'], 'legacy-sub.example.com/shop' )['plugin_version'], 'The row keeps its history.' );

		$events = wp_list_pluck( SFLS_Licenses::log_entries( (int) $lic['id'], 10 ), 'event' );
		$this->assertContains( 'upgrade', $events );

		// Once: the next validate finds the row, and the host-only name is gone.
		$this->assertTrue( $this->call( 'validate', $lic, 'https://legacy-sub.example.com/shop' )->get_data()['valid'] );
		$this->assertSame( 'domain_not_activated', $this->call( 'validate', $lic, 'https://legacy-sub.example.com' )->get_data()['reason'] );
	}

	/**
	 * The upgrade also happens when the site activates, and when it asks for
	 * its update package.
	 */
	public function test_the_upgrade_runs_on_activate_and_update_check(): void {
		$this->add_release( '9.9.1' );
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'legacy-act.example.com' );
		$this->seen_ago( (int) $lic['id'], 'legacy-act.example.com', 5 * DAY_IN_SECONDS );

		$activated = $this->call( 'activate', $lic, 'https://legacy-act.example.com/blog' );
		$this->assertSame( 200, $activated->get_status(), 'No second seat is needed.' );
		$this->assertSame( array( 'legacy-act.example.com/blog' ), $this->domains( $lic ) );

		$lic2 = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic2['id'], 'legacy-upd.example.com' );
		$this->seen_ago( (int) $lic2['id'], 'legacy-upd.example.com', 5 * DAY_IN_SECONDS );
		$check = $this->rest(
			'GET',
			'/sfls/v1/update/check',
			array(
				'license_key' => $lic2['license_key'],
				'site_url'    => 'https://legacy-upd.example.com/blog',
			)
		)->get_data();
		$this->assertNotSame( '', $check['package'], 'The update check sees the upgraded row.' );
		$this->assertSame( array( 'legacy-upd.example.com/blog' ), $this->domains( $lic2 ) );
	}

	/**
	 * The reason for the quiet period: a copy in a subdirectory, updated
	 * before the live site, must not take the live site's seat.
	 */
	public function test_a_live_host_only_row_is_never_taken_by_a_copy(): void {
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'live-root.example.com', '2.8.1' );
		$this->seen_ago( (int) $lic['id'], 'live-root.example.com', 6 * HOUR_IN_SECONDS ); // Validates every twelve hours.

		$validate = $this->call( 'validate', $lic, 'https://live-root.example.com/staging' )->get_data();
		$this->assertFalse( $validate['valid'] );
		$this->assertSame( 'domain_not_activated', $validate['reason'] );

		$activate = $this->call( 'activate', $lic, 'https://live-root.example.com/staging' );
		$this->assertSame( 'activation_limit', $activate->get_data()['reason'], 'The copy has no seat of its own.' );

		$this->assertSame( array( 'live-root.example.com' ), $this->domains( $lic ), 'The live site keeps its row.' );
		$this->assertTrue( $this->call( 'validate', $lic, 'https://live-root.example.com' )->get_data()['valid'] );
	}

	/**
	 * With a free seat the copy simply activates, next to the live site.
	 */
	public function test_a_copy_beside_a_live_host_only_row_activates_with_a_free_seat(): void {
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'live-free.example.com' );

		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://live-free.example.com/staging' )->get_status() );
		$this->assertSame( array( 'live-free.example.com', 'live-free.example.com/staging' ), $this->domains( $lic ) );
		$this->assertTrue( $this->call( 'validate', $lic, 'https://live-free.example.com' )->get_data()['valid'] );
	}

	/**
	 * A row of the same host on another key is never touched.
	 */
	public function test_the_upgrade_stays_inside_one_license(): void {
		$one = $this->make_license( array( 'plan' => 'pro' ) );
		$two = $this->make_license( array( 'plan' => 'pro' ) );
		SFLS_Licenses::add_activation( (int) $one['id'], 'shared-host.example.com' );
		$this->seen_ago( (int) $one['id'], 'shared-host.example.com', 4 * DAY_IN_SECONDS );

		$this->call( 'activate', $two, 'https://shared-host.example.com/shop' );

		$this->assertSame( array( 'shared-host.example.com' ), $this->domains( $one ) );
		$this->assertSame( array( 'shared-host.example.com/shop' ), $this->domains( $two ) );
	}

	/**
	 * Local and staging hosts stay free whatever their path.
	 */
	public function test_staging_hosts_with_a_path_stay_free(): void {
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://paid.example.com' )->get_status() );
		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://shop.test/a' )->get_status() );
		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://staging.paid.example.com/b' )->get_status() );
		$this->assertSame( 1, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
	}

	/**
	 * The update package address and its signed link carry the path.
	 */
	public function test_package_address_and_download_link_carry_the_path(): void {
		$this->add_release( '9.9.2' );
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		$this->call( 'activate', $lic, 'https://pkg.example.com/staging' );

		$check = $this->rest(
			'GET',
			'/sfls/v1/update/check',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://pkg.example.com/staging',
			)
		)->get_data();
		wp_parse_str( (string) wp_parse_url( $check['package'], PHP_URL_QUERY ), $q );
		$this->assertSame( 'pkg.example.com/staging', $q['site_url'] );

		$package = $this->rest( 'GET', '/sfls/v1/update/package', $q );
		$this->assertSame( 302, $package->get_status() );
		wp_parse_str( (string) wp_parse_url( $package->get_headers()['Location'], PHP_URL_QUERY ), $link );
		$this->assertSame( 'pkg.example.com/staging', $link['site'] );
		$this->assertTrue( SFLS_Releases::verify( $lic['license_key'], 'pkg.example.com/staging', (int) $link['expires'], $link['sig'], $link['token'], $link['version'] ) );

		// Production has no activation here, so its address gets no package.
		$root = $this->rest(
			'GET',
			'/sfls/v1/update/check',
			array(
				'license_key' => $lic['license_key'],
				'site_url'    => 'https://pkg.example.com',
			)
		)->get_data();
		$this->assertSame( '', $root['package'] );
	}

	/**
	 * The connect flow: Pro 2.8.2 (id=2) is known by host and path, an older
	 * Pro by its host, which is how it checks its license.
	 */
	public function test_connect_identity_depends_on_the_pro_version(): void {
		$this->assertSame( 'conn.example.com/staging', SFLS_Connect::site_identity( 'https://conn.example.com/staging/', '2' ) );
		$this->assertSame( 'conn.example.com', SFLS_Connect::site_identity( 'https://conn.example.com/staging/', '' ) );
		$this->assertSame( 'conn.example.com', SFLS_Connect::site_identity( 'https://conn.example.com/', '2' ) );
	}

	/**
	 * A trial belongs to the host: a path does not get a second trial.
	 */
	public function test_trials_are_per_host(): void {
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		SFLS_Trials::record_site( (int) $lic['id'], 'trial-host.example.com/staging' );
		$this->assertTrue( SFLS_Trials::site_used_by_other( 'trial-host.example.com', 0 ) );
		$this->assertTrue( SFLS_Trials::site_used_by_other( 'https://trial-host.example.com/other', 0 ) );
		$this->assertFalse( SFLS_Trials::site_used_by_other( 'trial-host.example.com', (int) $lic['id'] ) );
	}
}
