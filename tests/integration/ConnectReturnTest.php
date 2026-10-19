<?php
/**
 * LS-15: the connect flow only returns the one-time code over https, except
 * to local sites.
 *
 * @package SFLS
 */

/**
 * Connect return address.
 */
class SFLS_Connect_Return_Test extends SFLS_Test_Case {

	/**
	 * Why /connect-site/ refuses these arguments ('' when it accepts them).
	 *
	 * @param string $site   Site URL.
	 * @param string $return Return URL.
	 */
	private function reason( string $site, string $return ): string {
		$method = new ReflectionMethod( SFLS_Connect::class, 'invalid_reason' );
		$method->setAccessible( true );
		return (string) $method->invoke(
			new SFLS_Connect(),
			array(
				'site'   => $site,
				'return' => $return,
				'state'  => 's',
				'name'   => '',
				'v'      => '',
			)
		);
	}

	/**
	 * The repro: an http return address on a real site is refused.
	 */
	public function test_http_return_is_refused_outside_local_sites(): void {
		$this->assertStringContainsString( 'https', $this->reason( 'http://shop.example.com', 'http://shop.example.com/wp-admin/admin.php?page=sf' ) );
		$this->assertStringContainsString( 'https', $this->reason( 'http://myshop.wpengine.com', 'http://myshop.wpengine.com/wp-admin/' ) );
		$this->assertSame( '', $this->reason( 'https://shop.example.com', 'https://shop.example.com/wp-admin/admin.php?page=sf' ) );
	}

	/**
	 * Local development sites may use http.
	 */
	public function test_local_sites_may_use_http(): void {
		foreach ( array( 'http://localhost:10003', 'http://mysite.local', 'http://mysite.test', 'http://127.0.0.1:8080' ) as $site ) {
			$this->assertSame( '', $this->reason( $site, $site . '/wp-admin/' ), $site );
		}
	}
}
