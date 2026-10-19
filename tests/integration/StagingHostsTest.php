<?php
/**
 * LS-07: production hosts use a seat; only exact local and known staging
 * patterns are free.
 *
 * @package SFLS
 */

/**
 * Local and staging hosts.
 */
class SFLS_Staging_Hosts_Test extends SFLS_Test_Case {

	/**
	 * Which hosts are free by default.
	 */
	public function test_default_rules(): void {
		$free = array( 'localhost', '127.0.0.1', 'shop.local', 'shop.test', 'a.example', 'b.invalid', 'site.lndo.site', 'mysitestg.wpengine.com', 'mysitedev.wpengine.com', 'mysite-stg.wpengine.com', 'mysitestg.wpenginepowered.com', 'staging-mysite.kinsta.cloud', 'staging.shop.example.com', 'dev-site.pantheonsite.io', 'x.instawp.xyz' );
		foreach ( $free as $host ) {
			$this->assertTrue( SFLS_Licenses::is_local_domain( $host ), $host );
		}
		$paid = array( 'myshop.wpengine.com', 'myshop.wpenginepowered.com', 'mysite.kinsta.cloud', 'dev.realclient.com', 'test.realclient.com', 'stage.realclient.com', 'shop.example.com', 'evil.wpengine.com.attacker.com', 'evil.kinsta.cloud.attacker.com', 'local.realclient.com', 'shop.testing.com', 'live-site.pantheonsite.io', 'x.instawp.com', 'staging.com' );
		foreach ( $paid as $host ) {
			$this->assertFalse( SFLS_Licenses::is_local_domain( $host ), $host );
		}
	}

	/**
	 * The repro: a Starter key already on one site cannot add WP Engine or
	 * "dev." production sites for free.
	 */
	public function test_production_hosts_use_a_seat(): void {
		$lic = $this->make_license( array( 'plan' => 'starter' ) );
		$this->assertSame( 1, (int) $lic['sites_allowed'] );
		$this->assertTrue( SFLS_Licenses::activate_domain( $lic, 'first.example.com' ) );
		foreach ( array( 'myshop.wpengine.com', 'dev.realclient.com' ) as $host ) {
			$result = SFLS_Licenses::activate_domain( $lic, $host );
			$this->assertInstanceOf( WP_Error::class, $result, $host );
			$this->assertSame( 'activation_limit', $result->get_error_code() );
		}
		$this->assertTrue( SFLS_Licenses::activate_domain( $lic, 'myshopstg.wpengine.com' ) );
		$this->assertSame( 1, SFLS_Licenses::used_slots( (int) $lic['id'] ) );
	}

	/**
	 * The list is edited in Settings and can be filtered.
	 */
	public function test_admin_list_and_filter(): void {
		$this->settings( array() );
		SFLS_Settings::save(
			array_merge(
				SFLS_Settings::all(),
				array( 'staging_hosts' => "*.mystaging.io\n  BAD HOST!! \nqa-*.agency.dev\n*" )
			)
		);
		$this->assertSame( array( '*.mystaging.io', 'qa-*.agency.dev' ), SFLS_Settings::staging_patterns() );
		$this->assertTrue( SFLS_Licenses::is_local_domain( 'client.mystaging.io' ) );
		$this->assertTrue( SFLS_Licenses::is_local_domain( 'qa-client.agency.dev' ) );
		$this->assertFalse( SFLS_Licenses::is_local_domain( 'staging.shop.example.com' ), 'Removed from the list.' );
		$this->assertTrue( SFLS_Licenses::is_local_domain( 'shop.local' ), 'Local hosts are always free.' );

		// Saving other settings keeps the list.
		SFLS_Settings::save( array( 'reminder_days' => '30' ) );
		$this->assertSame( array( '*.mystaging.io', 'qa-*.agency.dev' ), SFLS_Settings::staging_patterns() );

		$this->filter(
			'sfls_staging_patterns',
			static function ( $list ) {
				$list[] = '*.preview.example.net';
				return $list;
			}
		);
		$this->assertTrue( SFLS_Licenses::is_local_domain( 'a.preview.example.net' ) );

		// The settings screen shows the field.
		wp_set_current_user( 1 );
		ob_start();
		( new SFLS_Admin() )->page_settings();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="staging_hosts"', $html );
		$this->assertStringContainsString( 'qa-*.agency.dev', $html );
	}
}
