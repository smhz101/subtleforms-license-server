<?php
/**
 * Plugin Name:       SubtleForms License Server
 * Plugin URI:        https://subtleforms.com
 * Description:       Issues and validates SubtleForms Pro license keys, sells them through WooCommerce, connects customer sites, runs the customer members area and serves Pro updates. Install on subtleforms.com only.
 * Version:           1.7.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            SubtleForms
 * License:           GPL-2.0-or-later
 * Text Domain:       subtleforms-license-server
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

define( 'SFLS_VERSION', '1.7.0' );
define( 'SFLS_FILE', __FILE__ );
define( 'SFLS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFLS_URL', plugin_dir_url( __FILE__ ) );

require_once SFLS_DIR . 'includes/class-sfls-settings.php';
require_once SFLS_DIR . 'includes/class-sfls-plans.php';
require_once SFLS_DIR . 'includes/class-sfls-db.php';
require_once SFLS_DIR . 'includes/class-sfls-licenses.php';
require_once SFLS_DIR . 'includes/class-sfls-claims.php';
require_once SFLS_DIR . 'includes/class-sfls-guest.php';
require_once SFLS_DIR . 'includes/class-sfls-releases.php';
require_once SFLS_DIR . 'includes/class-sfls-deploy-tokens.php';
require_once SFLS_DIR . 'includes/class-sfls-release-api.php';
require_once SFLS_DIR . 'includes/class-sfls-github.php';
require_once SFLS_DIR . 'includes/class-sfls-products.php';
require_once SFLS_DIR . 'includes/class-sfls-assets.php';
require_once SFLS_DIR . 'includes/class-sfls-connect.php';
require_once SFLS_DIR . 'includes/class-sfls-rest.php';
require_once SFLS_DIR . 'includes/class-sfls-woo.php';
require_once SFLS_DIR . 'includes/class-sfls-account.php';
require_once SFLS_DIR . 'includes/class-sfls-shortcodes.php';
require_once SFLS_DIR . 'includes/class-sfls-reminders.php';
require_once SFLS_DIR . 'includes/class-sfls-upgrades.php';
require_once SFLS_DIR . 'includes/class-sfls-checkout.php';
require_once SFLS_DIR . 'includes/class-sfls-delivery.php';
require_once SFLS_DIR . 'includes/class-sfls-blocks.php';
require_once SFLS_DIR . 'includes/class-sfls-paddle.php';
require_once SFLS_DIR . 'includes/class-sfls-trials.php';
require_once SFLS_DIR . 'includes/class-sfls-billing.php';
require_once SFLS_DIR . 'includes/class-sfls-billing-emails.php';
require_once SFLS_DIR . 'includes/class-sfls-paddle-rest.php';
require_once SFLS_DIR . 'includes/class-sfls-checkout-paddle.php';
require_once SFLS_DIR . 'includes/class-sfls-woo-paddle.php';
require_once SFLS_DIR . 'includes/class-sfls-woo-paddle-orders.php';
require_once SFLS_DIR . 'includes/class-sfls-admin.php';
require_once SFLS_DIR . 'includes/class-sfls-admin-releases.php';

register_activation_hook(
	__FILE__,
	static function () {
		SFLS_DB::install();
		SFLS_Settings::maybe_default_support_email();
		$previous = (string) get_option( 'sfls_version', '' );
		if ( '' !== $previous && version_compare( $previous, '1.5.0', '<' ) ) {
			SFLS_Settings::migrate_150();
		}
		update_option( 'sfls_version', SFLS_VERSION );
		update_option( 'sfls_flush_rewrite', 1 );
		SFLS_Reminders::schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		SFLS_Reminders::unschedule();
		delete_option( 'sfls_flush_rewrite' );
		flush_rewrite_rules();
	}
);

// Works with WooCommerce order storage (HPOS) and the Cart and Checkout blocks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SFLS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SFLS_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		SFLS_DB::maybe_upgrade();
		( new SFLS_REST() )->register();
		( new SFLS_Release_API() )->register();
		( new SFLS_GitHub() )->register();
		( new SFLS_Connect() )->register();
		( new SFLS_Assets() )->register();
		( new SFLS_Woo() )->register();
		( new SFLS_Account() )->register();
		( new SFLS_Claims() )->register();
		( new SFLS_Guest() )->register();
		( new SFLS_Shortcodes() )->register();
		( new SFLS_Reminders() )->register();
		( new SFLS_Upgrades() )->register();
		( new SFLS_Checkout() )->register();
		( new SFLS_Delivery() )->register();
		( new SFLS_Blocks() )->register();
		( new SFLS_Paddle_REST() )->register();
		( new SFLS_Checkout_Paddle() )->register();
		( new SFLS_Woo_Paddle() )->register();
		( new SFLS_Woo_Paddle_Orders() )->register();
		if ( is_admin() ) {
			( new SFLS_Admin() )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once SFLS_DIR . 'includes/class-sfls-cli.php';
			WP_CLI::add_command( 'sfls', 'SFLS_CLI' );
			WP_CLI::add_command( 'sfls release', 'SFLS_CLI_Releases' );
			WP_CLI::add_command( 'sfls release token', 'SFLS_CLI_Tokens' );
			WP_CLI::add_command( 'sfls token', 'SFLS_CLI_Tokens' );
		}
	}
);

// One-time data migrations that need WooCommerce orders.
add_action( 'init', array( 'SFLS_DB', 'run_migrations' ), 20 );

// Flush rewrite rules once after activation or an upgrade, when every rule
// (connect page, My Account endpoints) has been registered.
add_action(
	'init',
	static function () {
		if ( get_option( 'sfls_flush_rewrite' ) ) {
			delete_option( 'sfls_flush_rewrite' );
			flush_rewrite_rules();
		}
	},
	99
);
