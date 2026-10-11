<?php
/**
 * Plugin Name:       SubtleForms License Server
 * Plugin URI:        https://subtleforms.com
 * Description:       Issues and validates SubtleForms Pro license keys, sells them through WooCommerce, and serves Pro updates. Install on subtleforms.com only.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            SubtleForms
 * License:           GPL-2.0-or-later
 * Text Domain:       subtleforms-license-server
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

define( 'SFLS_VERSION', '1.0.0' );
define( 'SFLS_FILE', __FILE__ );
define( 'SFLS_DIR', plugin_dir_path( __FILE__ ) );

require_once SFLS_DIR . 'includes/class-sfls-plans.php';
require_once SFLS_DIR . 'includes/class-sfls-db.php';
require_once SFLS_DIR . 'includes/class-sfls-licenses.php';
require_once SFLS_DIR . 'includes/class-sfls-releases.php';
require_once SFLS_DIR . 'includes/class-sfls-rest.php';
require_once SFLS_DIR . 'includes/class-sfls-woo.php';
require_once SFLS_DIR . 'includes/class-sfls-admin.php';

register_activation_hook( __FILE__, array( 'SFLS_DB', 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		SFLS_DB::maybe_upgrade();
		( new SFLS_REST() )->register();
		( new SFLS_Woo() )->register();
		if ( is_admin() ) {
			( new SFLS_Admin() )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once SFLS_DIR . 'includes/class-sfls-cli.php';
			WP_CLI::add_command( 'sfls', 'SFLS_CLI' );
		}
	}
);
