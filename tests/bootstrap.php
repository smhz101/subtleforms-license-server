<?php
/**
 * PHPUnit bootstrap: load the WordPress site the plugin runs in.
 *
 * These are integration tests: WooCommerce orders, carts, emails and REST
 * requests run for real. Point SFLS_WP_PATH at a disposable WordPress install
 * with WooCommerce and this plugin active.
 *
 * @package SFLS
 */

// Command line only (PHPUnit); never reachable over the web.
if ( ! defined( 'ABSPATH' ) ) {
	'cli' === PHP_SAPI || exit;
}

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.ValidatedSanitizedInput -- Test bootstrap, not plugin code.

$sfls_wp_path = getenv( 'SFLS_WP_PATH' ) ? getenv( 'SFLS_WP_PATH' ) : '/home/claude/wp/site';
if ( ! file_exists( $sfls_wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress not found at {$sfls_wp_path}. Set SFLS_WP_PATH.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
define( 'SFLS_TESTING', true );

$_SERVER['HTTP_HOST']       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME']     = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['REQUEST_URI']     = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD']  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REMOTE_ADDR']     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

// PHPUnit includes this file inside a function, so declare what wp-config.php
// and wp-settings.php expect to be global.
global $wpdb, $table_prefix, $wp, $wp_query, $wp_the_query, $wp_rewrite, $wp_filter, $wp_actions, $wp_current_filter,
	$wp_version, $wp_db_version, $tinymce_version, $required_php_version, $required_mysql_version, $wp_local_package,
	$shortcode_tags, $wp_roles, $wp_locale, $wp_embed, $wp_widget_factory, $wp_post_types, $wp_taxonomies,
	$wp_scripts, $wp_styles, $pagenow, $current_user, $post, $allowedposttags, $allowedtags, $allowedentitynames,
	$wp_textdomain_registry, $l10n, $l10n_unloaded, $wp_plugin_paths, $wp_theme_directories, $wp_object_cache, $blog_id,
	$wp_meta_boxes, $wp_registered_sidebars, $wp_registered_widgets, $wp_rest_server, $woocommerce, $phpmailer;

require_once $sfls_wp_path . '/wp-load.php';

if ( ! defined( 'SFLS_VERSION' ) || ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "The SubtleForms License Server plugin and WooCommerce must be active on the test site.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

// Admin screens are rendered by some tests.
require_once ABSPATH . 'wp-admin/includes/admin.php';

require_once __DIR__ . '/class-sfls-test-case.php';
require_once __DIR__ . '/class-sfls-paddle-helpers.php';
require_once __DIR__ . '/class-sfls-woo-paddle-helpers.php';
