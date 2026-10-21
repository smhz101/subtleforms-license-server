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

	const SCHEMA_VERSION = '7';

	/**
	 * Set once licenses were tied to the customer of the order that issued
	 * them (1.7.0).
	 */
	const OWNERS_MIGRATED = 'sfls_owners_migrated';

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
	 * Activation log table name.
	 */
	public static function activation_log(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_activation_log';
	}

	/**
	 * Used download tokens table name.
	 */
	public static function download_tokens(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_download_tokens';
	}

	/**
	 * Trial claims table name (who already had a free trial).
	 */
	public static function trial_claims(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_trial_claims';
	}

	/**
	 * Processed Paddle webhook events table name.
	 */
	public static function paddle_events(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_paddle_events';
	}

	/**
	 * Pro releases table name (1.6.0).
	 */
	public static function releases(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_releases';
	}

	/**
	 * GitHub webhook deliveries table name (1.6.0).
	 */
	public static function github_deliveries(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_github_deliveries';
	}

	/**
	 * Branding of each license (1.8.0).
	 */
	public static function branding(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_branding';
	}

	/**
	 * Branding revisions, the newest ten per license (1.8.0).
	 */
	public static function branding_revisions(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_branding_revisions';
	}

	/**
	 * Client sites that fetched the branding (1.8.0).
	 */
	public static function branding_sites(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_branding_sites';
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
		$log      = self::activation_log();
		$tokens   = self::download_tokens();
		$claims   = self::trial_claims();
		$events   = self::paddle_events();
		$releases = self::releases();
		$hooks    = self::github_deliveries();
		$brand    = self::branding();
		$brev     = self::branding_revisions();
		$bsites   = self::branding_sites();

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
  billing varchar(20) NOT NULL DEFAULT '',
  billing_status varchar(20) NOT NULL DEFAULT '',
  trial tinyint(1) NOT NULL DEFAULT 0,
  trial_started_at datetime NULL,
  trial_ends_at datetime NULL,
  renews_at datetime NULL,
  cancels_at datetime NULL,
  billing_amount varchar(32) NOT NULL DEFAULT '',
  billing_currency varchar(3) NOT NULL DEFAULT '',
  paddle_customer_id varchar(64) NOT NULL DEFAULT '',
  paddle_subscription_id varchar(64) NOT NULL DEFAULT '',
  paddle_price_id varchar(64) NOT NULL DEFAULT '',
  paddle_transaction_id varchar(64) NOT NULL DEFAULT '',
  checkout_token_hash varchar(64) NOT NULL DEFAULT '',
  billing_synced_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY license_key (license_key),
  KEY customer_email (customer_email),
  KEY order_id (order_id),
  KEY user_id (user_id),
  KEY paddle_subscription_id (paddle_subscription_id),
  KEY paddle_transaction_id (paddle_transaction_id)
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

		// Schema 3 (1.3.0): who activated or deactivated which site, from where.
		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_id bigint(20) unsigned NOT NULL,
  domain varchar(190) NOT NULL,
  event varchar(20) NOT NULL,
  source varchar(20) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY license_id (license_id),
  KEY created_at (created_at)
) {$charset};"
		);

		// Schema 3 (1.3.0): signed download links that were already used.
		dbDelta(
			"CREATE TABLE {$tokens} (
  token char(32) NOT NULL,
  used_at datetime NOT NULL,
  PRIMARY KEY  (token),
  KEY used_at (used_at)
) {$charset};"
		);

		// Schema 4 (1.4.0): free trials already used, by email, company domain or site.
		dbDelta(
			"CREATE TABLE {$claims} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(10) NOT NULL,
  value varchar(190) NOT NULL,
  license_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY kind_value (kind,value),
  KEY license_id (license_id)
) {$charset};"
		);

		// Schema 4 (1.4.0): Paddle webhook events already handled (idempotency).
		dbDelta(
			"CREATE TABLE {$events} (
  event_id varchar(64) NOT NULL,
  event_type varchar(64) NOT NULL DEFAULT '',
  occurred_at datetime NULL,
  received_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT '',
  license_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note text NULL,
  PRIMARY KEY  (event_id),
  KEY received_at (received_at)
) {$charset};"
		);

		// Schema 5 (1.6.0): every Pro release, with its channel, status and package.
		dbDelta(
			"CREATE TABLE {$releases} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  version varchar(40) NOT NULL,
  channel varchar(10) NOT NULL DEFAULT 'stable',
  status varchar(10) NOT NULL DEFAULT 'draft',
  file varchar(190) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL DEFAULT '',
  requires varchar(20) NOT NULL DEFAULT '',
  tested varchar(20) NOT NULL DEFAULT '',
  requires_php varchar(20) NOT NULL DEFAULT '',
  changelog text NULL,
  source varchar(10) NOT NULL DEFAULT 'upload',
  git_ref varchar(190) NOT NULL DEFAULT '',
  git_commit varchar(64) NOT NULL DEFAULT '',
  notes text NULL,
  yank_reason varchar(255) NOT NULL DEFAULT '',
  replaced_files text NULL,
  downloads bigint(20) unsigned NOT NULL DEFAULT 0,
  released_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY version (version),
  KEY status (status)
) {$charset};"
		);

		// Schema 5 (1.6.0): GitHub webhook deliveries, kept so they can be retried.
		dbDelta(
			"CREATE TABLE {$hooks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  delivery_id varchar(64) NOT NULL DEFAULT '',
  event varchar(40) NOT NULL DEFAULT '',
  action varchar(40) NOT NULL DEFAULT '',
  tag varchar(100) NOT NULL DEFAULT '',
  result varchar(20) NOT NULL DEFAULT '',
  message text NULL,
  release_version varchar(40) NOT NULL DEFAULT '',
  payload longtext NULL,
  received_at datetime NOT NULL,
  processed_at datetime NULL,
  PRIMARY KEY  (id),
  KEY delivery_id (delivery_id),
  KEY received_at (received_at)
) {$charset};"
		);

		// Schema 7 (1.8.0): white-label branding per license, its last ten
		// revisions and the client sites that fetched it. Existing sites get
		// the tables on the next load (maybe_upgrade), nothing needs copying.
		dbDelta(
			"CREATE TABLE {$brand} (
  license_id bigint(20) unsigned NOT NULL,
  version int(11) unsigned NOT NULL DEFAULT 0,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  profile longtext NULL,
  logo_file varchar(190) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (license_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$brev} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_id bigint(20) unsigned NOT NULL,
  version int(11) unsigned NOT NULL DEFAULT 0,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  profile longtext NULL,
  logo_file varchar(190) NOT NULL DEFAULT '',
  action varchar(20) NOT NULL DEFAULT 'save',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY license_id (license_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$bsites} (
  license_id bigint(20) unsigned NOT NULL,
  domain varchar(190) NOT NULL,
  site_url varchar(255) NOT NULL DEFAULT '',
  version int(11) unsigned NOT NULL DEFAULT 0,
  fetched_at datetime NOT NULL,
  PRIMARY KEY  (license_id,domain)
) {$charset};"
		);

		self::backfill_billing();

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

		// Schema 5 (1.6.0): move the releases out of the two options.
		SFLS_Releases::migrate_from_options();
	}

	/**
	 * Schema 4: licenses issued before 1.4.0 get their billing source:
	 * woocommerce when an order issued them, manual otherwise.
	 */
	private static function backfill_billing(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$wpdb->prefix}sfls_licenses SET billing = 'woocommerce' WHERE billing = '' AND order_id > 0" );
		$wpdb->query( "UPDATE {$wpdb->prefix}sfls_licenses SET billing = 'manual' WHERE billing = ''" );
		// phpcs:enable
	}

	/**
	 * One-time data migrations that need WooCommerce (run on init).
	 */
	public static function run_migrations(): void {
		if ( ! get_option( self::OWNERS_MIGRATED ) ) {
			self::migrate_owners();
		}
		if ( ! get_option( SFLS_Releases::PRIVATE_NAMES_OPTION ) ) {
			SFLS_Releases::make_names_private();
		}
	}

	/**
	 * Schema 6 (1.7.0): a license belongs to the customer account of the
	 * order that issued it, never to an account found by email. Licenses
	 * from guest orders become unclaimed (user_id 0) until their buyer
	 * confirms the address or uses the signed guest link. Licenses without
	 * an order (Paddle, manual) and licenses whose order is gone keep their
	 * owner. Only user_id changes, so entitlement dates stay as they are.
	 * Returns how many licenses changed owner.
	 */
	public static function migrate_owners(): int {
		global $wpdb;
		if ( ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		$changed = 0;
		$last    = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id, user_id FROM {$wpdb->prefix}sfls_licenses WHERE order_id > 0 AND id > %d ORDER BY id ASC LIMIT 200", $last ), ARRAY_A );
			foreach ( $rows as $row ) {
				$last  = (int) $row['id'];
				$order = wc_get_order( (int) $row['order_id'] );
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				$customer = (int) $order->get_customer_id();
				if ( $customer !== (int) $row['user_id'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( self::licenses(), array( 'user_id' => $customer ), array( 'id' => (int) $row['id'] ) );
					++$changed;
				}
			}
		} while ( count( $rows ) === 200 );
		update_option( self::OWNERS_MIGRATED, gmdate( 'c' ), true );
		return $changed;
	}

	/**
	 * Delete activation log entries (they hold IP addresses) and handled
	 * Paddle webhook events (they hold payload notes) older than the
	 * retention setting. Runs daily; 0 days keeps everything. Returns how
	 * many rows were deleted.
	 */
	public static function prune(): int {
		global $wpdb;
		$days = SFLS_Settings::retention_days();
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$log    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sfls_activation_log WHERE created_at < %s", $cutoff ) );
		$events = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sfls_paddle_events WHERE received_at < %s", $cutoff ) );
		// phpcs:enable
		return $log + $events;
	}

	/**
	 * User meta the plugin writes on accounts.
	 */
	const USER_META = array( '_sfls_claim', '_sfls_claim_sent', '_sfls_paddle_customer_id', '_sfls_paddle_customer_email' );

	/**
	 * Uninstall: remove the user meta the plugin wrote and the order lock
	 * options that requests which crashed left behind.
	 */
	public static function remove_leftovers(): void {
		global $wpdb;
		foreach ( self::USER_META as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sfls\\_issue\\_lock\\_%' OR option_name LIKE 'sfls\\_paddle\\_lock\\_%'" );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Run the installer when the schema changed, and ask for a rewrite flush
	 * when the plugin version changed.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'sfls_schema_version' ) !== self::SCHEMA_VERSION ) {
			self::install();
			// A new schema can come with new My Account endpoints (Branding, 1.8.0).
			update_option( 'sfls_flush_rewrite', 1 );
		}
		if ( get_option( 'sfls_version' ) !== SFLS_VERSION ) {
			$previous = (string) get_option( 'sfls_version', '' );
			if ( '' !== $previous && version_compare( $previous, '1.5.0', '<' ) ) {
				SFLS_Settings::migrate_150();
			}
			update_option( 'sfls_version', SFLS_VERSION );
			update_option( 'sfls_flush_rewrite', 1 );
			SFLS_Settings::maybe_default_support_email();
		}
	}
}
