<?php
/**
 * Database tables.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the license and activation tables.
 */
final class SFLS_DB {

	const SCHEMA_VERSION = '2';

	/**
	 * Licenses table name.
	 */
	public static function licenses(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_licenses';
	}

	/**
	 * Activations table name.
	 */
	public static function activations(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_activations';
	}

	/**
	 * Create or upgrade the tables and the private release folder.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$licenses = self::licenses();
		$acts     = self::activations();

		dbDelta(
			"CREATE TABLE {$licenses} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_key varchar(64) NOT NULL,
  plan varchar(32) NOT NULL DEFAULT 'pro',
  status varchar(20) NOT NULL DEFAULT 'active',
  sites_allowed int(11) NOT NULL DEFAULT 1,
  customer_email varchar(190) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  notes text NULL,
  reminders text NULL,
  expires_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY license_key (license_key),
  KEY customer_email (customer_email),
  KEY order_id (order_id),
  KEY user_id (user_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$acts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_id bigint(20) unsigned NOT NULL,
  domain varchar(190) NOT NULL,
  is_local tinyint(1) NOT NULL DEFAULT 0,
  plugin_version varchar(20) NOT NULL DEFAULT '',
  activated_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY license_domain (license_id,domain),
  KEY license_id (license_id)
) {$charset};"
		);

		update_option( 'sfls_schema_version', self::SCHEMA_VERSION );

		// Private folder for release packages.
		$dir = SFLS_Releases::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Run the installer when the schema changed, and ask for a rewrite flush
	 * when the plugin version changed.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'sfls_schema_version' ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
		if ( get_option( 'sfls_version' ) !== SFLS_VERSION ) {
			update_option( 'sfls_version', SFLS_VERSION );
			update_option( 'sfls_flush_rewrite', 1 );
		}
	}
}
