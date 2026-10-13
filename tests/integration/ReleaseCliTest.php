<?php
/**
 * 1.6.0 WP-CLI: wp sfls release list|add|publish|yank|latest|delete and
 * wp sfls release token create|list|revoke, run through the real wp binary
 * against the same site (set SFLS_WP_CLI to the wp-cli.phar path).
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions

/**
 * WP-CLI release commands.
 */
class SFLS_Release_Cli_Test extends SFLS_Test_Case {

	/**
	 * Run wp with arguments.
	 *
	 * @param string $args Arguments (already escaped).
	 * @return array{0:int,1:string}
	 */
	private function wp( string $args ): array {
		$phar = getenv( 'SFLS_WP_CLI' ) ? getenv( 'SFLS_WP_CLI' ) : '/tmp/claude-0/wp-cli.phar';
		$cmd  = 'php ' . escapeshellarg( $phar ) . ' --allow-root --path=' . escapeshellarg( ABSPATH ) . ' ' . $args . ' < /dev/null 2>&1';
		$out  = array();
		$code = 0;
		exec( $cmd, $out, $code );
		return array( $code, trim( implode( "\n", $out ) ) );
	}

	/**
	 * Release commands end to end.
	 */
	public function test_release_commands(): void {
		$phar = getenv( 'SFLS_WP_CLI' ) ? getenv( 'SFLS_WP_CLI' ) : '/tmp/claude-0/wp-cli.phar';
		if ( ! function_exists( 'exec' ) || ! is_file( $phar ) ) {
			$this->markTestSkipped( 'WP-CLI not found. Set SFLS_WP_CLI.' );
		}
		$this->isolate_releases();
		$log = wp_tempnam( 'sfls-changelog.txt' );
		file_put_contents( $log, "* From a file\n* Second line" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->temp_files[] = $log;

		[ $code, $out ] = $this->wp( 'sfls release add ' . escapeshellarg( $this->pro_zip( '9.1.0' ) ) . ' --publish --changelog=' . escapeshellarg( '@' . $log ) );
		$this->assertSame( 0, $code, $out );
		$this->assertStringContainsString( '9.1.0 created (stable, published', $out );

		[ $code, $out ] = $this->wp( 'sfls release add ' . escapeshellarg( $this->pro_zip( '9.2.0' ) ) . ' --channel=beta --porcelain' );
		$this->assertSame( 0, $code, $out );
		$this->assertSame( '9.2.0', $out );

		[ $code, $out ] = $this->wp( 'sfls release add ' . escapeshellarg( $this->pro_zip( '9.3.0' ) ) . ' --publish' );
		$this->assertSame( 0, $code, $out );

		[ , $json ] = $this->wp( 'sfls release list --format=json' );
		$rows       = array_column( (array) json_decode( $json, true ), null, 'version' );
		$this->assertSame( array( '9.3.0', '9.2.0', '9.1.0' ), array_keys( $rows ) );
		$this->assertSame( 'draft', $rows['9.2.0']['status'] );
		$this->assertSame( 'beta', $rows['9.2.0']['channel'] );
		$this->assertSame( 'cli', $rows['9.1.0']['source'] );
		$this->assertSame( 'latest', $rows['9.3.0']['flags'] );
		$this->assertSame( "* From a file\n* Second line", SFLS_Releases::get( '9.1.0', true )['changelog'] );

		[ $code, $out ] = $this->wp( 'sfls release publish 9.2.0' );
		$this->assertSame( 0, $code, $out );
		$this->assertSame( 'published', SFLS_Releases::get( '9.2.0', true )['status'] );

		[ $code, $out ] = $this->wp( 'sfls release latest 9.1.0' );
		$this->assertSame( 0, $code, $out );
		[ , $out ] = $this->wp( 'sfls release latest' );
		$this->assertSame( '9.1.0', $out, 'Pinned.' );
		[ $code ] = $this->wp( 'sfls release latest 9.2.0' );
		$this->assertSame( 1, $code, 'A beta cannot be pinned.' );
		$this->wp( 'sfls release latest --unpin' );
		[ , $out ] = $this->wp( 'sfls release latest' );
		$this->assertSame( '9.3.0', $out );

		[ $code, $out ] = $this->wp( 'sfls release yank 9.3.0 --reason=' . escapeshellarg( 'Broken build' ) );
		$this->assertSame( 0, $code, $out );
		$this->assertStringContainsString( 'Latest stable: 9.1.0', $out );
		$this->assertSame( 'Broken build', SFLS_Releases::get( '9.3.0', true )['yank_reason'] );

		$this->wp( 'sfls release delete 9.3.0' );
		$this->assertNotNull( SFLS_Releases::get( '9.3.0', true ), 'Nothing is deleted without --yes.' );
		$path     = SFLS_Releases::package_path( SFLS_Releases::get( '9.3.0', true ) );
		[ $code ] = $this->wp( 'sfls release delete 9.3.0 --yes' );
		$this->assertSame( 0, $code );
		$this->assertNull( SFLS_Releases::get( '9.3.0', true ) );
		$this->assertFileDoesNotExist( $path );

		[ $code, $out ] = $this->wp( 'sfls release add /nonexistent.zip' );
		$this->assertSame( 1, $code, $out );
	}

	/**
	 * Token commands.
	 */
	public function test_token_commands(): void {
		global $wpdb;
		$phar = getenv( 'SFLS_WP_CLI' ) ? getenv( 'SFLS_WP_CLI' ) : '/tmp/claude-0/wp-cli.phar';
		if ( ! function_exists( 'exec' ) || ! is_file( $phar ) ) {
			$this->markTestSkipped( 'WP-CLI not found. Set SFLS_WP_CLI.' );
		}
		$before = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", SFLS_Deploy_Tokens::OPTION ) );
		try {
			[ $code, $token ] = $this->wp( 'sfls release token create ' . escapeshellarg( 'CLI test' ) . ' --porcelain' );
			$this->assertSame( 0, $code, $token );
			$this->assertMatchesRegularExpression( '/^sflsdt_[a-f0-9]{48}$/', $token );

			[ , $json ] = $this->wp( 'sfls release token list --format=json' );
			$list       = array_values( array_filter( (array) json_decode( $json, true ), static fn( $t ) => 'CLI test' === $t['name'] ) );
			$this->assertCount( 1, $list );
			$this->assertSame( 'active', $list[0]['status'] );
			$this->assertStringNotContainsString( $token, $json, 'The token is never listed.' );

			$this->flush_options();
			$this->assertNotNull( SFLS_Deploy_Tokens::verify( $token ) );

			[ $code ] = $this->wp( 'sfls token revoke ' . (int) $list[0]['id'] );
			$this->assertSame( 0, $code );
			$this->flush_options();
			$this->assertNull( SFLS_Deploy_Tokens::verify( $token ) );
		} finally {
			if ( null === $before ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => SFLS_Deploy_Tokens::OPTION ) );
			} else {
				$wpdb->update( $wpdb->options, array( 'option_value' => $before ), array( 'option_name' => SFLS_Deploy_Tokens::OPTION ) );
			}
			$this->flush_options();
		}
	}

	/**
	 * Forget cached options the CLI process changed.
	 */
	private function flush_options(): void {
		foreach ( array( SFLS_Deploy_Tokens::OPTION, 'notoptions', 'alloptions' ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
	}
}
