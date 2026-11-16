<?php
/**
 * Translations load on init.
 *
 * @package SFLS
 */

/**
 * Text domain.
 */
class SFLS_Textdomain_Test extends SFLS_Test_Case {

	/**
	 * The loader is hooked on init.
	 */
	public function test_hooked_on_init(): void {
		$found = false;
		foreach ( $GLOBALS['wp_filter']['init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( $cb['function'] instanceof Closure && ( new ReflectionFunction( $cb['function'] ) )->getFileName() === SFLS_FILE ) {
					$found = true;
				}
			}
		}
		$this->assertTrue( $found );
	}

	/**
	 * A .mo in the languages folder is picked up.
	 */
	public function test_mo_loaded(): void {
		$domain = 'subtleforms-license-server';
		$locale = static fn() => 'de_DE';
		add_filter( 'locale', $locale );
		add_filter( 'plugin_locale', $locale );
		$mo     = SFLS_DIR . 'languages/' . $domain . '-' . get_locale() . '.mo';
		$this->assertFileDoesNotExist( $mo );
		wp_mkdir_p( dirname( $mo ) );
		$entries = new MO();
		$entries->add_entry( new Translation_Entry( array( 'singular' => 'Hello', 'translations' => array( 'Hallo' ) ) ) );
		$entries->export_to_file( $mo );
		unload_textdomain( $domain );
		try {
			foreach ( $GLOBALS['wp_filter']['init']->callbacks as $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( $cb['function'] instanceof Closure && ( new ReflectionFunction( $cb['function'] ) )->getFileName() === SFLS_FILE ) {
						$cb['function']();
					}
				}
			}
			$this->assertSame( 'Hallo', __( 'Hello', $domain ) ); // Loaded just in time, from the registered path.
			$this->assertTrue( is_textdomain_loaded( $domain ) );
		} finally {
			unlink( $mo );
			if ( ! glob( SFLS_DIR . 'languages/*' ) ) {
				rmdir( SFLS_DIR . 'languages' );
			}
			unload_textdomain( $domain );
			remove_filter( 'locale', $locale );
			remove_filter( 'plugin_locale', $locale );
		}
	}
}
