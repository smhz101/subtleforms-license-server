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

	const SCHEMA_VERSION = '6';

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
	 * Audit log table name (1.7.0): who changed what on a license.
	 */
	public static function audit(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_audit';
	}

	/**
	 * License transfers table name (1.7.0).
	 */
	public static function transfers(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_transfers';
	}

	/**
	 * Outgoing webhook deliveries table name (1.7.0).
	 */
	public static function webhook_log(): string {
		global $wpdb;
		return $wpdb->prefix . 'sfls_webhook_log';
	}

	/**
	 * Create or upgrade the tables and the private release folder.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$licenses  = self::licenses();
		$acts      = self::activations();
		$log       = self::activation_log();
		$tokens    = self::download_tokens();
		$claims    = self::trial_claims();
		$events    = self::paddle_events();
		$releases  = self::releases();
		$hooks     = self::github_deliveries();
		$audit     = self::audit();
		$transfers = self::transfers();
		$deliveries = self::webhook_log();

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
  order_email varchar(190) NOT NULL DEFAULT '',
  claimed_at datetime NULL,
  flagged_at datetime NULL,
  flag_reason varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY license_key (license_key),
  KEY customer_email (customer_email),
  KEY order_email (order_email),
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
  is_network tinyint(1) NOT NULL DEFAULT 0,
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
		// Schema 6 (1.7.0): multi-use links (the order email) count their uses.
		dbDelta(
			"CREATE TABLE {$tokens} (
  token char(32) NOT NULL,
  used_at datetime NOT NULL,
  uses int(11) NOT NULL DEFAULT 1,
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

		// Schema 6 (1.7.0): audit log of every admin, CLI, webhook and billing change.
		dbDelta(
			"CREATE TABLE {$audit} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(40) NOT NULL,
  source varchar(20) NOT NULL DEFAULT '',
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor varchar(190) NOT NULL DEFAULT '',
  before_data text NULL,
  after_data text NULL,
  note text NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY license_id (license_id),
  KEY action (action),
  KEY created_at (created_at)
) {$charset};"
		);

		// Schema 6 (1.7.0): pending customer-to-customer license transfers.
		dbDelta(
			"CREATE TABLE {$transfers} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_id bigint(20) unsigned NOT NULL,
  from_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  to_email varchar(190) NOT NULL,
  token_hash char(64) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  accepted_at datetime NULL,
  PRIMARY KEY  (id),
  KEY license_id (license_id),
  KEY token_hash (token_hash)
) {$charset};"
		);

		// Schema 6 (1.7.0): outgoing webhook deliveries with retries.
		dbDelta(
			"CREATE TABLE {$deliveries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  endpoint varchar(255) NOT NULL,
  event varchar(40) NOT NULL,
  license_id bigint(20) unsigned NOT NULL DEFAULT 0,
  payload longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts int(11) NOT NULL DEFAULT 0,
  response_code int(11) NOT NULL DEFAULT 0,
  response text NULL,
  next_attempt_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY next_attempt_at (next_attempt_at),
  KEY license_id (license_id)
) {$charset};"
		);

		self::backfill_billing();
		self::backfill_order_email();

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

		// Schema 6 (1.7.0): packages move to random names in random folders.
		SFLS_Releases::migrate_random_names();
	}

	/**
	 * Schema 6: licenses issued by an order before 1.7.0 remember the email
	 * the order was placed with. Until now customer_email held exactly that
	 * (it was copied from the billing email), so it is the best record there is.
	 */
	private static function backfill_order_email(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$wpdb->prefix}sfls_licenses SET order_email = customer_email WHERE order_email = '' AND order_id > 0" );
		$wpdb->query( "UPDATE {$wpdb->prefix}sfls_licenses SET claimed_at = created_at WHERE claimed_at IS NULL AND user_id > 0" );
		// phpcs:enable
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
	 * Run the installer when the schema changed, and ask for a rewrite flush
	 * when the plugin version changed.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'sfls_schema_version' ) !== self::SCHEMA_VERSION ) {
			self::install();
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
