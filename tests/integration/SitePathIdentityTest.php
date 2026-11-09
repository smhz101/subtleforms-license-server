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
				// Pro 2.8.2 and later send the path and report their version.
				'version'     => '2.8.2',
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


		// The update check does not rename anything: only activate and validate do.
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
		$this->assertSame( '', $check['package'] );
		$this->assertSame( array( 'legacy-upd.example.com' ), $this->domains( $lic2 ) );
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

		// The host-only row may be a root site on an older Pro, so the copy is
		// counted with it as one seat; it never takes the row over.
		$activate = $this->call( 'activate', $lic, 'https://live-root.example.com/staging' );
		$this->assertSame( 200, $activate->get_status() );
		$this->assertSame( 1, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
		$this->assertSame( array( 'live-root.example.com', 'live-root.example.com/staging' ), $this->domains( $lic ), 'The live site keeps its row.' );
		$this->assertTrue( $this->call( 'validate', $lic, 'https://live-root.example.com' )->get_data()['valid'] );
	}

	/**
	 * With a free seat the copy simply activates, next to the live site, and
	 * the two rows are one seat while the host-only row is a legacy one.
	 */
	public function test_a_copy_beside_a_live_host_only_row_activates_with_a_free_seat(): void {
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'live-free.example.com' );

		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://live-free.example.com/staging' )->get_status() );
		$this->assertSame( array( 'live-free.example.com', 'live-free.example.com/staging' ), $this->domains( $lic ) );
		$this->assertSame( 1, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
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

	/**
	 * Deactivating never runs the legacy rename: a copy that deactivates first
	 * leaves a quiet host-only row alone and still hears "deactivated".
	 */
	public function test_deactivate_from_a_path_leaves_a_quiet_host_only_row(): void {
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'quiet-prod.example.com', '2.8.1' );
		$this->seen_ago( (int) $lic['id'], 'quiet-prod.example.com', 4 * DAY_IN_SECONDS );

		$res = $this->call( 'deactivate', $lic, 'https://quiet-prod.example.com/staging' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['deactivated'] );
		$this->assertSame( array( 'quiet-prod.example.com' ), $this->domains( $lic ) );
		$this->assertNotContains( 'upgrade', wp_list_pluck( SFLS_Licenses::log_entries( (int) $lic['id'], 10 ), 'event' ) );
	}

	/**
	 * A subdirectory site that activates while its host-only row is quiet and
	 * the license has a free seat ends with one row, not two.
	 */
	public function test_subdirectory_upgrade_with_a_free_seat_ends_with_one_row(): void {
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'free-seat.example.com', '2.8.1' );
		$this->seen_ago( (int) $lic['id'], 'free-seat.example.com', 40 * HOUR_IN_SECONDS );

		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://free-seat.example.com/shop' )->get_status() );
		$this->assertSame( array( 'free-seat.example.com/shop' ), $this->domains( $lic ) );
		$this->assertSame( 1, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
	}

	/**
	 * The quiet window is 36 hours, shorter than Pro's 48 hour grace.
	 */
	public function test_the_quiet_window_is_shorter_than_the_pro_grace(): void {
		$this->assertSame( 36 * HOUR_IN_SECONDS, SFLS_Licenses::LEGACY_QUIET_SECONDS );
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'window.example.com', '2.8.1' );
		$this->seen_ago( (int) $lic['id'], 'window.example.com', 30 * HOUR_IN_SECONDS );
		$this->call( 'validate', $lic, 'https://window.example.com/a' );
		$this->assertSame( array( 'window.example.com' ), $this->domains( $lic ), 'Not quiet long enough.' );
		$this->seen_ago( (int) $lic['id'], 'window.example.com', 37 * HOUR_IN_SECONDS );
		$this->assertTrue( $this->call( 'validate', $lic, 'https://window.example.com/a' )->get_data()['valid'] );
		$this->assertSame( array( 'window.example.com/a' ), $this->domains( $lic ) );
	}

	/**
	 * A path multisite: the root keeps the legacy row alive, its subsites
	 * activate as host/path identities and count with it as one seat, even on a
	 * full license. Once the row goes quiet it is dropped and each site is a seat.
	 */
	public function test_path_multisite_root_and_subsites_share_one_seat_until_the_legacy_row_goes(): void {
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		SFLS_Licenses::add_activation( (int) $lic['id'], 'network.example.com', '2.8.1' );
		$this->seen_ago( (int) $lic['id'], 'network.example.com', 6 * HOUR_IN_SECONDS );

		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://network.example.com/one' )->get_status() );
		$this->assertSame( 200, $this->call( 'activate', $lic, 'https://network.example.com/two' )->get_status() );
		$this->assertSame( 3, count( $this->domains( $lic ) ) );
		$this->assertSame( 1, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
		$data = $this->call( 'validate', $lic, 'https://network.example.com/one' )->get_data();
		$this->assertSame( 1, (int) $data['sites_used'] );
		$this->assertTrue( $this->call( 'validate', $lic, 'https://network.example.com' )->get_data()['valid'], 'The root is untouched.' );

		// The root moves to the new identity (or is gone): its legacy row goes quiet and is dropped.
		$this->seen_ago( (int) $lic['id'], 'network.example.com', 40 * HOUR_IN_SECONDS );
		$this->assertSame( 2, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
		$this->assertSame( array( 'network.example.com/one', 'network.example.com/two' ), $this->domains( $lic ) );

		// A one-site license now refuses a third site: one seat per site.
		$third = $this->call( 'activate', $lic, 'https://network.example.com/three' );
		$this->assertSame( 'activation_limit', $third->get_data()['reason'] );
	}

	/**
	 * A host-only row of Pro 2.8.2 or later is a root site with a seat of its own.
	 */
	public function test_a_current_root_site_keeps_a_seat_apart_from_a_copy(): void {
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		$this->call( 'activate', $lic, 'https://current-root.example.com' );
		$this->assertSame( 'activation_limit', $this->call( 'activate', $lic, 'https://current-root.example.com/staging' )->get_data()['reason'] );
	}

	/**
	 * The connect exchange and the picker compute the same identity for a
	 * percent-encoded path.
	 */
	public function test_encoded_paths_give_one_identity_in_the_picker_and_the_exchange(): void {
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		$site = 'https://enc.example.com/caf%C3%A9';
		$picker = SFLS_Connect::site_identity( esc_url_raw( $site, array( 'http', 'https' ) ), '2' );
		$this->assertSame( 'enc.example.com/caf%c3%a9', $picker );

		$code = 'ENCODEDPATH' . wp_generate_password( 8, false );
		set_transient(
			'sfls_code_' . hash( 'sha256', $code ),
			array(
				'license_id' => (int) $lic['id'],
				'domain'     => $picker,
				'user_id'    => 1,
			),
			300
		);
		$res  = $this->rest(
			'POST',
			'/sfls/v1/connect/exchange',
			array(
				'code'     => $code,
				'site_url' => $site,
				'identity' => '2',
			)
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( $lic['license_key'], $res->get_data()['license_key'] );
	}

	/**
	 * A site removed through the account page or wp-admin answers site_removed;
	 * one removed through the API, or never activated, answers domain_not_activated.
	 */
	public function test_a_site_removed_in_the_account_answers_site_removed(): void {
		$lic = $this->make_license( array( 'plan' => 'pro' ) );
		$this->call( 'activate', $lic, 'https://removed.example.com' );
		SFLS_Licenses::remove_activation( (int) $lic['id'], 'removed.example.com', 'account' );

		$data = $this->call( 'validate', $lic, 'https://removed.example.com' )->get_data();
		$this->assertFalse( $data['valid'] );
		$this->assertSame( 'site_removed', $data['reason'] );

		$this->assertSame( 'domain_not_activated', $this->call( 'validate', $lic, 'https://never.example.com' )->get_data()['reason'] );

		// Activating again clears it; an API deactivate afterwards is not a removal.
		$this->call( 'activate', $lic, 'https://removed.example.com' );
		$this->assertTrue( $this->call( 'validate', $lic, 'https://removed.example.com' )->get_data()['valid'] );
		$this->call( 'deactivate', $lic, 'https://removed.example.com' );
		$this->assertSame( 'domain_not_activated', $this->call( 'validate', $lic, 'https://removed.example.com' )->get_data()['reason'] );

		// Admin removal counts too, and a marker older than 30 days is gone.
		$this->call( 'activate', $lic, 'https://removed.example.com' );
		SFLS_Licenses::remove_activation( (int) $lic['id'], 'removed.example.com', 'admin' );
		$this->assertSame( 'site_removed', $this->call( 'validate', $lic, 'https://removed.example.com' )->get_data()['reason'] );
		update_option( SFLS_Licenses::REMOVED_OPTION, array( $lic['id'] . '|removed.example.com' => time() - 31 * DAY_IN_SECONDS ) );
		$this->assertSame( 'domain_not_activated', $this->call( 'validate', $lic, 'https://removed.example.com' )->get_data()['reason'] );
	}
}
