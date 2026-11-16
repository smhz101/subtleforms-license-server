<?php
/**
 * Email templates: theme override, preview, sample rendering.
 *
 * @package SFLS
 */

/**
 * Email templates.
 */
class SFLS_Email_Templates_Test extends SFLS_Test_Case {

	/**
	 * The plugin's own template is used by default.
	 */
	public function test_plugin_default_is_located(): void {
		$this->assertSame( SFLS_DIR . 'templates/emails/welcome.php', SFLS_Templates::locate( 'welcome' ) );
	}

	/**
	 * A file the filter points to wins and is rendered with the args.
	 */
	public function test_override_via_filter_is_rendered(): void {
		$file = wp_tempnam( 'sfls-tpl' );
		file_put_contents( $file, '<?php echo "OVERRIDE " . esc_html( $args["plan"] );' );
		$this->filter( 'sfls_email_template', static fn( $path, $name ) => 'canceled' === $name ? $file : $path, 10, 2 );
		$this->assertSame( 'OVERRIDE Pro', SFLS_Templates::render( 'canceled', array( 'plan' => 'Pro' ) ) );
		unlink( $file );
	}

	/**
	 * A theme's subtleforms-license-server/emails/<name>.php is preferred.
	 */
	public function test_theme_file_is_preferred(): void {
		$dir = get_stylesheet_directory() . '/subtleforms-license-server/emails';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/payment_failed.php', 'THEME' );
		try {
			$this->assertSame( $dir . '/payment_failed.php', SFLS_Templates::locate( 'payment_failed' ) );
			$this->assertSame( 'THEME', SFLS_Templates::render( 'payment_failed', array() ) );
		} finally {
			unlink( $dir . '/payment_failed.php' );
			rmdir( $dir );
			rmdir( dirname( $dir ) );
		}
	}

	/**
	 * Every template renders with sample data and no notices.
	 */
	public function test_every_template_renders_with_sample_data(): void {
		foreach ( array_keys( SFLS_Templates::previews() ) as $name ) {
			$html = SFLS_Templates::preview_html( $name );
			$this->assertStringContainsString( '<p', $html, $name );
		}
	}

	/**
	 * The preview needs the capability and a valid nonce.
	 */
	public function test_preview_requires_capability_and_nonce(): void {
		$_GET['template'] = 'welcome';
		$this->die_throws();
		$this->expectException( RuntimeException::class );
		SFLS_Templates::handle_preview();
	}

	/**
	 * A valid nonce without the capability is refused too.
	 */
	public function test_preview_refuses_non_admin(): void {
		wp_set_current_user( $this->make_user()->ID );
		$_GET['template'] = 'welcome';
		$_GET['_wpnonce'] = wp_create_nonce( SFLS_Templates::PREVIEW_ACTION );
		$this->die_throws();
		$this->expectException( RuntimeException::class );
		SFLS_Templates::handle_preview();
	}

	/**
	 * Make wp_die throw instead of ending the run.
	 */
	private function die_throws(): void {
		$this->filter( 'wp_die_handler', static fn() => static function ( $message ) {
			throw new RuntimeException( 'die' );
		} );
	}

	/**
	 * Clean request globals.
	 */
	protected function tearDown(): void {
		unset( $_GET['template'], $_GET['_wpnonce'] );
		parent::tearDown();
	}
}
