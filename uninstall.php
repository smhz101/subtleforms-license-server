<?php
/**
 * Uninstall: removes data only when "Delete all ... when the plugin is
 * deleted" is ticked in Licenses › Settings (option sfls_delete_data_on_uninstall).
 * WooCommerce products and orders are never touched.
 *
 * @package SFLS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'sfls_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sfls_activations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sfls_licenses" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sfls_activation_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sfls_download_tokens" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sfls_trial_claims" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sfls_paddle_events" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sfls_releases" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sfls_github_deliveries" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sfls\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sfls\\_%'" );
// phpcs:enable

foreach ( array(
	'sfls_schema_version',
	'sfls_version',
	'sfls_flush_rewrite',
	'sfls_release',
	'sfls_release_history',
	'sfls_releases_migrated',
	'sfls_release_pin',
	'sfls_release_settings',
	'sfls_deploy_tokens',
	'sfls_github',
	'sfls_settings',
	'sfls_products',
	'sfls_paddle',
	'sfls_paddle_rejected',
	'sfls_migrated_150',
	'sfls_owners_migrated',
	'sfls_link_secret',
	'sfls_release_files_private',
	'woocommerce_sfls_paddle_settings',
	'sfls_delete_data_on_uninstall',
) as $sfls_option ) {
	delete_option( $sfls_option );
}

// User meta (pending claims, Paddle customer IDs) and leftover order locks.
require_once __DIR__ . '/includes/class-sfls-db.php';
SFLS_DB::remove_leftovers();

wp_clear_scheduled_hook( 'sfls_daily' );

// Release packages in uploads/sfls-private.
$sfls_uploads = wp_upload_dir( null, false );
$sfls_dir     = trailingslashit( $sfls_uploads['basedir'] ) . 'sfls-private';
if ( is_dir( $sfls_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( WP_Filesystem() ) {
		global $wp_filesystem;
		$wp_filesystem->rmdir( $sfls_dir, true );
	}
}
