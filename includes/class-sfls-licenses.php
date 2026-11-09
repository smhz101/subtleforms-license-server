<?php
/**
 * License records and the rules that decide whether one is usable.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * License storage and business rules.
 */
final class SFLS_Licenses {

	const GRACE_DAYS = 14;

	/**
	 * Billing columns (schema 4) that create() accepts as they are.
	 */
	const BILLING_COLUMNS = array( 'billing_status', 'trial', 'trial_started_at', 'trial_ends_at', 'renews_at', 'cancels_at', 'billing_amount', 'billing_currency', 'paddle_customer_id', 'paddle_subscription_id', 'paddle_price_id', 'paddle_transaction_id', 'checkout_token_hash', 'billing_synced_at' );

	/**
	 * New key: SFP-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX (128 random bits).
	 */
	public static function generate_key(): string {
		$hex = strtoupper( bin2hex( random_bytes( 16 ) ) );
		return 'SFP-' . implode( '-', str_split( $hex, 8 ) );
	}

	/**
	 * Create a license.
	 *
	 * The license belongs to `user_id` only. It is never linked to an account
	 * by matching the email address: a license without a user ID stays
	 * unclaimed until its buyer confirms the address (SFLS_Claims).
	 *
	 * @param array $args plan, sites_allowed, customer_email, user_id, order_id, product_id, months, notes,
	 *                    and optionally billing (paddle | woocommerce | manual), expires_at (GMT datetime,
	 *                    overrides months) and any billing column (see SFLS_DB).
	 * @return array The stored license.
	 */
	public static function create( array $args ): array {
		global $wpdb;

		$plan   = sanitize_key( $args['plan'] ?? 'pro' );
		$def    = SFLS_Plans::get( $plan ) ?? SFLS_Plans::get( 'pro' );
		$now    = current_time( 'mysql', true );
		$months = isset( $args['months'] ) ? (int) $args['months'] : 12;
		$email  = sanitize_email( $args['customer_email'] ?? '' );
		$user   = (int) ( $args['user_id'] ?? 0 );

		$row = array(
			'license_key'    => self::generate_key(),
			'plan'           => $plan,
			'status'         => 'active',
			'sites_allowed'  => isset( $args['sites_allowed'] ) ? (int) $args['sites_allowed'] : (int) $def['sites'],
			'customer_email' => $email,
			'user_id'        => $user,
			'order_id'       => (int) ( $args['order_id'] ?? 0 ),
			'product_id'     => (int) ( $args['product_id'] ?? 0 ),
			'notes'          => sanitize_textarea_field( $args['notes'] ?? '' ),
			'expires_at'     => $months > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( "+{$months} months" ) ) : null,
			'created_at'     => $now,
			'updated_at'     => $now,
			'billing'        => in_array( $args['billing'] ?? '', array( 'paddle', 'woocommerce', 'manual' ), true ) ? $args['billing'] : ( ! empty( $args['order_id'] ) ? 'woocommerce' : 'manual' ),
		);
		if ( array_key_exists( 'expires_at', $args ) ) {
			$row['expires_at'] = $args['expires_at'];
		}
		foreach ( self::BILLING_COLUMNS as $column ) {
			if ( array_key_exists( $column, $args ) ) {
				$row[ $column ] = $args[ $column ];
			}
		}

		$wpdb->insert( SFLS_DB::licenses(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row['id'] = (int) $wpdb->insert_id;

		do_action( 'sfls_license_created', $row );

		return $row;
	}

	/**
	 * Find a license by key.
	 *
	 * @param string $key License key.
	 */
	public static function find_by_key( string $key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE license_key = %s", strtoupper( trim( $key ) ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Find the license of a Paddle subscription.
	 *
	 * @param string $subscription_id Paddle subscription ID (sub_...).
	 */
	public static function find_by_subscription( string $subscription_id ): ?array {
		global $wpdb;
		if ( '' === $subscription_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE paddle_subscription_id = %s ORDER BY id ASC LIMIT 1", $subscription_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Find the license created by a Paddle checkout transaction.
	 *
	 * @param string $transaction_id Paddle transaction ID (txn_...).
	 */
	public static function find_by_transaction( string $transaction_id ): ?array {
		global $wpdb;
		if ( '' === $transaction_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE paddle_transaction_id = %s ORDER BY id ASC LIMIT 1", $transaction_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Find a license by ID.
	 *
	 * @param int $id License ID.
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE id = %d", $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Update columns of a license.
	 *
	 * @param int   $id   License ID.
	 * @param array $data Columns.
	 */
	public static function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( SFLS_DB::licenses(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Delete a license, its activations and its branding.
	 *
	 * @param int $id License ID.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( SFLS_DB::activations(), array( 'license_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( SFLS_DB::activation_log(), array( 'license_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		SFLS_Branding::purge( $id );
		$wpdb->delete( SFLS_DB::licenses(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Replace a license key with a fresh one. Activations stay attached.
	 *
	 * @param int $id License ID.
	 * @return string The new key.
	 */
	public static function regenerate_key( int $id ): string {
		$key = self::generate_key();
		self::update( $id, array( 'license_key' => $key ) );
		return $key;
	}

	/**
	 * Extend a license by a number of months from its expiry (or from today if
	 * it has already lapsed). Only the expiry changes: a suspended or refunded
	 * license stays that way (use resume() or the editor to change a status).
	 *
	 * @param int $id     License ID.
	 * @param int $months Months to add.
	 */
	public static function extend( int $id, int $months = 12 ): void {
		$lic = self::find( $id );
		if ( ! $lic || empty( $lic['expires_at'] ) ) {
			return; // Lifetime licenses have nothing to extend.
		}
		$current = strtotime( $lic['expires_at'] . ' UTC' );
		$base    = $current > time() ? $current : time();
		self::update( $id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( "+{$months} months", $base ) ) ) );
	}

	/**
	 * Lift a suspension. A refunded license is not resumed here: its status
	 * only changes by an explicit choice in the license editor.
	 *
	 * @param int $id License ID.
	 * @return bool Whether the license was suspended and is active again.
	 */
	public static function resume( int $id ): bool {
		$lic = self::find( $id );
		if ( ! $lic || 'suspended' !== $lic['status'] ) {
			return false;
		}
		self::update( $id, array( 'status' => 'active' ) );
		return true;
	}

	// ── Ownership ───────────────────────────────────────────────────────

	/**
	 * Whether a user owns a license. Ownership is the user ID stored on the
	 * license, never the email address: an account email can be changed
	 * without any confirmation, so it proves nothing.
	 *
	 * @param array   $lic  License.
	 * @param WP_User $user User.
	 */
	public static function user_owns( array $lic, WP_User $user ): bool {
		return $user->ID > 0 && (int) $lic['user_id'] === (int) $user->ID;
	}

	/**
	 * Licenses that belong to a user (by user ID), newest first.
	 *
	 * @param WP_User $user User.
	 * @return array[]
	 */
	public static function for_user( WP_User $user ): array {
		global $wpdb;
		if ( ! $user->ID ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE user_id = %d ORDER BY created_at DESC, id DESC", $user->ID ), ARRAY_A );
		return (array) $rows;
	}

	// ── Activations ─────────────────────────────────────────────────────

	/**
	 * Activations for a license.
	 *
	 * @param int $license_id License ID.
	 * @return array[]
	 */
	public static function activations( int $license_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_activations WHERE license_id = %d ORDER BY activated_at ASC", $license_id ), ARRAY_A );
	}

	/**
	 * One activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain.
	 */
	public static function activation( int $license_id, string $domain ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_activations WHERE license_id = %d AND domain = %s", $license_id, $domain ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Count the sites that use up a seat (local/dev sites are free).
	 *
	 * A legacy host-only row (see legacy_hosts()) and the one host/path row that
	 * joined it are one seat. Every other row is a seat of its own. Reading the
	 * count never deletes a row.
	 *
	 * @param int $license_id License ID.
	 */
	public static function used_slots( int $license_id ): int {
		$rows   = self::seat_rows( $license_id );
		$legacy = self::legacy_hosts( $rows );
		$seats  = array();
		foreach ( $rows as $row ) {
			$domain = (string) $row['domain'];
			$host   = self::host_of( $domain );
			// The one host/path row that joined a legacy row is the same seat.
			$seats[ ( $host !== $domain && isset( $legacy[ $host ] ) && $legacy[ $host ] === $domain ) ? $host : $domain ] = true;
		}
		return count( $seats );
	}

	/**
	 * Whether a license has a free site slot.
	 *
	 * @param array $lic License.
	 */
	public static function has_free_slot( array $lic ): bool {
		$limit = (int) $lic['sites_allowed'];
		return $limit <= 0 || self::used_slots( (int) $lic['id'] ) < $limit;
	}

	/**
	 * Whether a domain can be activated without taking a new seat: it has a
	 * path, a legacy host-only row of its host is on the license, and no other
	 * path has joined that row yet. One legacy row protects one migrated site.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain.
	 */
	public static function joins_existing_seat( int $license_id, string $domain ): bool {
		$host = self::host_of( $domain );
		if ( $host === $domain || self::is_local_domain( $domain ) ) {
			return false;
		}
		$legacy = self::legacy_hosts( self::seat_rows( $license_id ) );
		return isset( $legacy[ $host ] ) && '' === $legacy[ $host ];
	}

	/**
	 * Whether a license has a seat for this domain, counting a legacy row of
	 * the same host as the seat the domain would join.
	 *
	 * @param array  $lic    License.
	 * @param string $domain Normalised domain.
	 */
	public static function has_seat_for( array $lic, string $domain ): bool {
		return self::joins_existing_seat( (int) $lic['id'], $domain ) || self::has_free_slot( $lic );
	}

	/**
	 * Non-local rows of a license.
	 *
	 * @param int $license_id License ID.
	 * @return array[]
	 */
	private static function seat_rows( int $license_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT domain, plugin_version, last_seen_at, legacy, legacy_joined_by FROM {$wpdb->prefix}sfls_activations WHERE license_id = %d AND is_local = 0", $license_id ), ARRAY_A );
	}

	/**
	 * The legacy host-only rows in a list of rows, each with the host/path row
	 * that joined it ('' while the join is still open).
	 *
	 * "Legacy" is decided once, by the schema 11 migration (the legacy column).
	 * It is never worked out from the version a site reports, because the site
	 * chooses that. A legacy row stands for a site from before Pro sent its
	 * path: if that site moves into a subdirectory it asks for host/path and
	 * must not need a second seat. At most one path joins a legacy row; a join
	 * whose row is gone counts as open again.
	 *
	 * @param array[] $rows Rows with domain, legacy and legacy_joined_by.
	 * @return array<string,string> Host => the domain that joined, or ''.
	 */
	private static function legacy_hosts( array $rows ): array {
		$names = array();
		foreach ( $rows as $row ) {
			$names[ (string) $row['domain'] ] = true;
		}
		$hosts = array();
		foreach ( $rows as $row ) {
			$domain = (string) $row['domain'];
			if ( ! empty( $row['legacy'] ) && self::host_of( $domain ) === $domain ) {
				$joined          = (string) ( $row['legacy_joined_by'] ?? '' );
				$hosts[ $domain ] = isset( $names[ $joined ] ) ? $joined : '';
			}
		}
		return $hosts;
	}

	/**
	 * Delete the legacy host-only rows that went quiet for LEGACY_QUIET_SECONDS
	 * and have a joined host/path row: the joined row holds the seat now. Runs
	 * from the daily job only, never when the seat count is read. Returns how
	 * many rows were deleted.
	 */
	public static function prune_legacy_rows(): int {
		global $wpdb;
		$table = SFLS_DB::activations();
		$quiet = gmdate( 'Y-m-d H:i:s', time() - self::LEGACY_QUIET_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT l.license_id, l.domain FROM %i l INNER JOIN %i j ON j.license_id = l.license_id AND j.domain = l.legacy_joined_by WHERE l.legacy = 1 AND l.legacy_joined_by <> %s AND l.last_seen_at <= %s', $table, $table, '', $quiet ), ARRAY_A );
		$count = 0;
		foreach ( $rows as $row ) {
			$deleted = (int) $wpdb->delete(
				$table,
				array(
					'license_id' => (int) $row['license_id'],
					'domain'     => (string) $row['domain'],
				)
			);
			if ( $deleted ) {
				self::log( (int) $row['license_id'], (string) $row['domain'], 'upgrade', 'cleanup' );
				$count += $deleted;
			}
		}
		// phpcs:enable
		return $count;
	}

	/**
	 * Activate a domain on a license, applying every rule the REST activate
	 * endpoint applies. Re-activating a known domain only refreshes it.
	 *
	 * @param array  $lic     License.
	 * @param string $domain  Normalised domain.
	 * @param string $version Pro version reported by the site.
	 * @param string $source  Where the request came from (api, connect, admin) for the log.
	 * @return true|WP_Error WP_Error data carries the HTTP status.
	 */
	public static function activate_domain( array $lic, string $domain, string $version = '', string $source = 'api' ) {
		// Seat checks always compare the normalised host and path, whichever caller sent it.
		$domain = self::normalize_domain( $domain );
		if ( '' === $domain ) {
			return new WP_Error( 'missing_parameters', __( 'license_key and site_url are required.', 'subtleforms-license-server' ), array( 'status' => 400 ) );
		}

		$state = self::state( $lic );
		if ( ! $state['valid'] ) {
			return new WP_Error(
				$state['status'],
				'expired' === $state['status']
					? __( 'This license has expired. Renew it from your SubtleForms account.', 'subtleforms-license-server' )
					: __( 'This license is not active. Contact SubtleForms support.', 'subtleforms-license-server' ),
				array( 'status' => 403 )
			);
		}

		self::upgrade_legacy_activation( (int) $lic['id'], $domain );
		$existing = self::activation( (int) $lic['id'], $domain );
		if ( ! $existing && ! empty( $lic['trial'] ) && ! self::is_local_domain( $domain ) && SFLS_Trials::site_used_by_other( $domain, (int) $lic['id'] ) ) {
			return new WP_Error(
				'trial_site_used',
				__( 'This site already had a SubtleForms Pro free trial. Buy a license to use Pro here, or start the trial on a site that has not had one.', 'subtleforms-license-server' ),
				array( 'status' => 403 )
			);
		}
		if ( ! $existing && ! self::is_local_domain( $domain ) && ! self::has_seat_for( $lic, $domain ) ) {
			return self::seat_error( (int) $lic['sites_allowed'] );
		}

		if ( $existing ) {
			self::touch_activation( (int) $lic['id'], $domain, $version );
		} else {
			if ( self::is_local_domain( $domain ) ) {
				self::add_activation( (int) $lic['id'], $domain, $version );
			} elseif ( ! self::add_activation_in_seat( (int) $lic['id'], $domain, $version ) ) {
				return self::seat_error( (int) $lic['sites_allowed'] );
			}
			self::log( (int) $lic['id'], $domain, 'activate', $source );
			if ( ! empty( $lic['trial'] ) && ! self::is_local_domain( $domain ) ) {
				SFLS_Trials::record_site( (int) $lic['id'], $domain );
			}
			do_action( 'sfls_domain_activated', $lic, $domain, $version );
		}

		return true;
	}

	/**
	 * The error for a license with no free site slot.
	 *
	 * @param int $limit Sites allowed.
	 */
	private static function seat_error( int $limit ): WP_Error {
		return new WP_Error(
			'activation_limit',
			sprintf(
				/* translators: %d: number of sites */
				_n( 'This license is already active on %d site. Deactivate it there, or upgrade your plan.', 'This license is already active on %d sites. Deactivate one, or upgrade your plan.', $limit, 'subtleforms-license-server' ),
				$limit
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Take a site slot: lock the license row, count the used slots again and
	 * insert the activation in one transaction, so two sites activating at the
	 * same moment cannot both take the last slot.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain.
	 * @param string $version    Pro version.
	 * @return bool False when no slot is free.
	 */
	private static function add_activation_in_seat( int $license_id, string $domain, string $version ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sfls_licenses WHERE id = %d FOR UPDATE", $license_id ) );
		$fresh = self::find( $license_id );
		if ( ! $fresh || ! self::has_seat_for( $fresh, $domain ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$joins = self::joins_existing_seat( $license_id, $domain );
		self::add_activation( $license_id, $domain, $version );
		if ( $joins ) {
			// The one path that joins a legacy row: later paths need seats of their own.
			$wpdb->update(
				SFLS_DB::activations(),
				array( 'legacy_joined_by' => $domain ),
				array(
					'license_id'       => $license_id,
					'domain'           => self::host_of( $domain ),
					'legacy'           => 1,
					'legacy_joined_by' => '',
				)
			);
		}
		$wpdb->query( 'COMMIT' );
		// phpcs:enable
		return true;
	}

	/**
	 * Store a new activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Domain.
	 * @param string $version    Pro version.
	 */
	public static function add_activation( int $license_id, string $domain, string $version = '' ): void {
		global $wpdb;
		self::clear_removed( $license_id, $domain );
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			SFLS_DB::activations(),
			array(
				'license_id'     => $license_id,
				'domain'         => $domain,
				'is_local'       => self::is_local_domain( $domain ) ? 1 : 0,
				'plugin_version' => substr( sanitize_text_field( $version ), 0, 20 ),
				'activated_at'   => $now,
				'last_seen_at'   => $now,
			)
		);
	}

	/**
	 * Refresh last-seen (and version) of an activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Domain.
	 * @param string $version    Pro version.
	 */
	public static function touch_activation( int $license_id, string $domain, string $version = '' ): void {
		global $wpdb;
		$data = array( 'last_seen_at' => current_time( 'mysql', true ) );
		$version = substr( sanitize_text_field( $version ), 0, 20 );
		if ( '' !== $version ) {
			// A stored version never moves down: the site reports it, so a lower one is ignored.
			$current = self::activation( $license_id, $domain );
			if ( $current && ( '' === (string) $current['plugin_version'] || version_compare( $version, (string) $current['plugin_version'], '>' ) ) ) {
				$data['plugin_version'] = $version;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SFLS_DB::activations(),
			$data,
			array(
				'license_id' => $license_id,
				'domain'     => $domain,
			)
		);
	}

	/**
	 * Remove an activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Domain.
	 * @param string $source     Who removed it (api, account, admin) for the log.
	 */
	public static function remove_activation( int $license_id, string $domain, string $source = 'api' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			SFLS_DB::activations(),
			array(
				'license_id' => $license_id,
				'domain'     => $domain,
			)
		);
		if ( $deleted ) {
			// A removed joiner frees the join of its legacy row.
			$wpdb->update(
				SFLS_DB::activations(),
				array( 'legacy_joined_by' => '' ),
				array(
					'license_id'       => $license_id,
					'legacy_joined_by' => $domain,
				)
			);
			self::log( $license_id, $domain, 'deactivate', $source );
			if ( in_array( $source, array( 'account', 'admin' ), true ) ) {
				self::mark_removed( $license_id, $domain );
			}
		}
	}

	/**
	 * Option that remembers sites removed through the account page or wp-admin.
	 */
	const REMOVED_OPTION = 'sfls_removed_sites';

	/**
	 * Days a removal is remembered.
	 */
	const REMOVED_DAYS = 30;

	/**
	 * Remember that a site was removed on purpose (account page or wp-admin),
	 * so its next validate answers site_removed and Pro does not take the seat
	 * back. Entries older than 30 days are dropped whenever one is written.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain.
	 */
	private static function mark_removed( int $license_id, string $domain ): void {
		$list = self::removed_list();
		$list[ $license_id . '|' . $domain ] = time();
		update_option( self::REMOVED_OPTION, $list, false );
	}

	/**
	 * The removal markers younger than 30 days.
	 *
	 * @return array<string,int>
	 */
	private static function removed_list(): array {
		$list   = get_option( self::REMOVED_OPTION, array() );
		$cutoff = time() - self::REMOVED_DAYS * DAY_IN_SECONDS;
		return array_filter( is_array( $list ) ? $list : array(), static fn( $at ) => (int) $at > $cutoff );
	}

	/**
	 * Whether a site was removed through the account page or wp-admin within
	 * the last 30 days and has not been activated again since.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain.
	 */
	public static function was_removed( int $license_id, string $domain ): bool {
		return isset( self::removed_list()[ $license_id . '|' . $domain ] );
	}

	/**
	 * Forget a removal (the site was activated again).
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain.
	 */
	private static function clear_removed( int $license_id, string $domain ): void {
		$list = self::removed_list();
		if ( isset( $list[ $license_id . '|' . $domain ] ) ) {
			unset( $list[ $license_id . '|' . $domain ] );
			update_option( self::REMOVED_OPTION, $list, false );
		}
	}

	/**
	 * Remove every activation of a license.
	 *
	 * @param int    $license_id License ID.
	 * @param string $source     Who removed them, for the log.
	 */
	public static function reset_activations( int $license_id, string $source = 'admin' ): void {
		foreach ( self::activations( $license_id ) as $act ) {
			self::remove_activation( $license_id, (string) $act['domain'], $source );
		}
	}

	// ── Activity log ────────────────────────────────────────────────────

	/**
	 * IP address of the current request ('' on the command line).
	 *
	 * REMOTE_ADDR, unless Licenses › Settings says the site is behind a trusted
	 * proxy or CDN: then CF-Connecting-IP, else the first address of
	 * X-Forwarded-For. Turn that on only when every request really comes
	 * through the proxy, because the headers can be forged otherwise. The
	 * `sfls_client_ip` filter has the last word.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( SFLS_Settings::trusted_proxy() ) {
			$forwarded = '';
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$parts     = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
				$forwarded = trim( (string) $parts[0] );
			}
			if ( false !== filter_var( $forwarded, FILTER_VALIDATE_IP ) ) {
				$ip = $forwarded;
			}
		}
		$ip = (string) apply_filters( 'sfls_client_ip', $ip );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Record an activation or deactivation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Domain.
	 * @param string $event      activate | deactivate.
	 * @param string $source     api | connect | account | admin | cli.
	 */
	public static function log( int $license_id, string $domain, string $event, string $source = '' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			SFLS_DB::activation_log(),
			array(
				'license_id' => $license_id,
				'domain'     => substr( $domain, 0, 190 ),
				'event'      => sanitize_key( $event ),
				'source'     => sanitize_key( ( defined( 'WP_CLI' ) && WP_CLI && 'api' === $source ) ? 'cli' : $source ),
				'ip'         => self::client_ip(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Log entries of a license, newest first.
	 *
	 * @param int $license_id License ID.
	 * @param int $limit      Max rows.
	 * @return array[]
	 */
	public static function log_entries( int $license_id, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_activation_log WHERE license_id = %d ORDER BY id DESC LIMIT %d", $license_id, $limit ), ARRAY_A );
	}

	// ── Domains ─────────────────────────────────────────────────────────

	/**
	 * Normalise a site URL or host to the identity a seat is kept for: the
	 * lowercase host plus the path of the site, as in "example.com" for a site
	 * at the root and "example.com/staging" for a copy in a subdirectory.
	 *
	 * The host has no trailing dot and no "www.", and internationalised names
	 * are in ASCII (punycode) when intl is available. The path has no query,
	 * fragment, trailing slash or repeated slash. Port, scheme and credentials
	 * are not part of it. Seat checks compare this form, so "Example.COM.",
	 * "www.example.com/" and "example.com" are one site, and "example.com/shop"
	 * is another.
	 *
	 * @param string $url URL or host.
	 */
	public static function normalize_domain( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( str_contains( $url, '://' ) ? $url : 'https://' . $url );
		$host  = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		if ( '' !== $host && preg_match( '/[^\x00-\x7F]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : idn_to_ascii( $host );
			if ( is_string( $ascii ) && '' !== $ascii ) {
				$host = strtolower( $ascii );
			}
		}
		$host = rtrim( (string) preg_replace( '/^www\./', '', $host ), '.' );
		if ( '' === $host ) {
			return '';
		}

		// Only characters that cannot break a signed link or a query string stay in the path.
		$path = strtolower( (string) preg_replace( '/[^a-z0-9._~%\/-]/', '', strtolower( (string) ( $parts['path'] ?? '' ) ) ) );
		$path = trim( (string) preg_replace( '#/{2,}#', '/', $path ), '/' );

		// The columns hold 190 characters.
		return rtrim( substr( '' === $path ? $host : $host . '/' . $path, 0, 190 ), '/' );
	}

	/**
	 * The host part of a site identity: "example.com" for "example.com/staging".
	 * Staging and local rules, trials and the connect check look at the host.
	 *
	 * @param string $domain Normalised domain, or a bare host.
	 */
	public static function host_of( string $domain ): string {
		return explode( '/', trim( $domain ), 2 )[0];
	}

	/**
	 * First Pro version that sends its site address with the path.
	 */
	const SITE_PATH_PRO_VERSION = '2.8.2';

	/**
	 * Seconds a legacy host-only activation must have been quiet before a site
	 * that sends its path may take it over, or before it is dropped because
	 * host/path rows of the same host exist (see upgrade_legacy_activation()).
	 *
	 * 36 hours, not 48: a root site on an older Pro validates at least every 12
	 * hours, so a live row never goes quiet for 36. A row that has been quiet
	 * that long belongs to a site that moved to the new identity or is gone,
	 * and it is released before the 48 hour fallback grace of a Pro that still
	 * sends the host only has run out, so a full license does not leave a site
	 * without Pro while the old row waits to become eligible.
	 */
	const LEGACY_QUIET_SECONDS = 36 * HOUR_IN_SECONDS;

	/**
	 * Hand a host-only activation to the site that now identifies itself with
	 * host and path.
	 *
	 * Before 1.9.2 a seat was kept for the host only, and Pro sent the host
	 * only. A site in a subdirectory (example.com/shop) therefore holds a row
	 * named "example.com", and so does a root site on that host. When the
	 * subdirectory site updates Pro it asks for "example.com/shop" and finds no
	 * row. Taking over the host-only row keeps it from using a second seat.
	 *
	 * Only a legacy row (marked once by the schema 11 migration) with no joined
	 * path is taken over. The server cannot tell that site from a root site, or
	 * from a staging copy of a root site, that still sends the host only. A root
	 * site on an older Pro validates at least every twelve hours and keeps its
	 * row alive, so a row is taken over only when it has been quiet for 36 hours. A copy
	 * of a live site never takes the seat of the site it was copied from; it
	 * activates like any other new site.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain with a path.
	 * @return bool True when a host-only row was renamed.
	 */
	public static function upgrade_legacy_activation( int $license_id, string $domain ): bool {
		global $wpdb;
		$host = self::host_of( $domain );
		if ( $host === $domain || '' === $host || self::activation( $license_id, $domain ) ) {
			return false;
		}
		$table = SFLS_DB::activations();
		$quiet = gmdate( 'Y-m-d H:i:s', time() - self::LEGACY_QUIET_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET domain = %s, last_seen_at = %s, legacy = 0, legacy_joined_by = %s WHERE license_id = %d AND domain = %s AND legacy = 1 AND legacy_joined_by = %s AND last_seen_at <= %s", $table, $domain, current_time( 'mysql', true ), '', $license_id, $host, '', $quiet ) );
		if ( 1 === $changed ) {
			self::log( $license_id, $domain, 'upgrade', 'api' );
			return true;
		}
		return false;
	}

	/**
	 * Hosts on the developer's own machine: localhost, loopback addresses and
	 * reserved or local-only names (exact last-label match), plus the
	 * wildcard domains of local development tools.
	 *
	 * @param string $domain Normalised domain.
	 */
	public static function is_dev_host( string $domain ): bool {
		$domain = strtolower( trim( self::host_of( $domain ), " \t\n\r\0\x0B." ) );
		if ( in_array( $domain, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(local|test|localhost|invalid|example|dev\.cc|lndo\.site|ddev\.site)$/', $domain );
	}

	/**
	 * Known staging host patterns of hosting companies. Production hosts
	 * such as *.wpengine.com, *.wpenginepowered.com and *.kinsta.cloud are
	 * deliberately not here: only their staging and development installs are.
	 *
	 * @return string[]
	 */
	public static function default_staging_patterns(): array {
		return array(
			'staging.*',
			'*stg.wpengine.com',
			'*dev.wpengine.com',
			'*stg.wpenginepowered.com',
			'*dev.wpenginepowered.com',
			'staging-*.kinsta.cloud',
			'*.flywheelstaging.com',
			'dev-*.pantheonsite.io',
			'test-*.pantheonsite.io',
			'*.instawp.xyz',
			'*.instawp.co',
			'*.instawp.link',
		);
	}

	/**
	 * Staging host patterns in use: Licenses › Settings, filtered by
	 * `sfls_staging_patterns`.
	 *
	 * @return string[]
	 */
	public static function staging_patterns(): array {
		$list = SFLS_Settings::staging_patterns();
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'sfls_staging_patterns', $list ) ) ) );
	}

	/**
	 * Whether a host matches a staging pattern. "*.example.com" matches any
	 * subdomain of example.com, "staging.*" matches "staging." in front of
	 * another domain, a "*" anywhere else matches letters, digits and hyphens
	 * inside one name, and anything else must match exactly.
	 *
	 * @param string $host    Normalised host.
	 * @param string $pattern Pattern.
	 */
	public static function host_matches( string $host, string $pattern ): bool {
		$host    = strtolower( $host );
		$pattern = strtolower( trim( $pattern ) );
		if ( '' === $pattern || '' === $host ) {
			return false;
		}
		if ( str_starts_with( $pattern, '*.' ) && ! str_contains( substr( $pattern, 2 ), '*' ) ) {
			$suffix = substr( $pattern, 1 );
			return strlen( $host ) > strlen( $suffix ) && str_ends_with( $host, $suffix );
		}
		if ( str_ends_with( $pattern, '.*' ) && ! str_contains( substr( $pattern, 0, -2 ), '*' ) ) {
			$prefix = substr( $pattern, 0, -1 );
			$rest   = (string) substr( $host, strlen( $prefix ) );
			return str_starts_with( $host, $prefix ) && str_contains( $rest, '.' ) && ! str_starts_with( $rest, '.' );
		}
		$regex = '/^' . str_replace( '\\*', '[a-z0-9-]*', preg_quote( $pattern, '/' ) ) . '$/';
		return (bool) preg_match( $regex, $host );
	}

	/**
	 * Local, staging and development hosts do not use a site slot: the
	 * developer's own machine (is_dev_host()) and the staging patterns of
	 * Licenses › Settings. Other hosts can be added with the
	 * `sfls_is_local_domain` filter.
	 *
	 * @param string $domain Normalised domain; only its host is looked at.
	 */
	public static function is_local_domain( string $domain ): bool {
		$domain = self::host_of( $domain );
		if ( self::is_dev_host( $domain ) ) {
			return true;
		}
		foreach ( self::staging_patterns() as $pattern ) {
			if ( self::host_matches( $domain, $pattern ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'sfls_is_local_domain', false, $domain );
	}

	// ── State ───────────────────────────────────────────────────────────

	/**
	 * The status a client should see right now.
	 *
	 * @param array $lic License.
	 * @return array{status:string,valid:bool,grace_period_ends_at:?string}
	 */
	public static function state( array $lic ): array {
		if ( 'suspended' === $lic['status'] || 'refunded' === $lic['status'] ) {
			return array(
				'status'               => 'suspended',
				'valid'                => false,
				'grace_period_ends_at' => null,
			);
		}
		if ( empty( $lic['expires_at'] ) ) {
			return array(
				'status'               => 'valid',
				'valid'                => true,
				'grace_period_ends_at' => null,
			);
		}
		$expires = strtotime( $lic['expires_at'] . ' UTC' );
		if ( $expires > time() ) {
			return array(
				'status'               => 'valid',
				'valid'                => true,
				'grace_period_ends_at' => null,
			);
		}
		// A free trial that ended without a payment has no grace period.
		$grace_end = ! empty( $lic['trial'] ) ? $expires : $expires + self::GRACE_DAYS * DAY_IN_SECONDS;
		if ( $grace_end > time() ) {
			return array(
				'status'               => 'grace_period',
				'valid'                => true,
				'grace_period_ends_at' => gmdate( 'c', $grace_end ),
			);
		}
		return array(
			'status'               => 'expired',
			'valid'                => false,
			'grace_period_ends_at' => null,
		);
	}

	/**
	 * Human label for a state slug.
	 *
	 * @param string $status State slug from state().
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'valid'        => __( 'Active', 'subtleforms-license-server' ),
			'grace_period' => __( 'Grace period', 'subtleforms-license-server' ),
			'expired'      => __( 'Expired', 'subtleforms-license-server' ),
			'suspended'    => __( 'Suspended', 'subtleforms-license-server' ),
		);
		return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
	}

	/**
	 * Key with everything but the last 8 characters hidden.
	 *
	 * @param string $key License key.
	 */
	public static function mask_key( string $key ): string {
		return 'SFP-••••-' . substr( $key, -8 );
	}

	/**
	 * Expiry formatted for people ("Lifetime" when it never expires).
	 *
	 * @param array $lic License.
	 */
	public static function expiry_label( array $lic ): string {
		if ( empty( $lic['expires_at'] ) ) {
			return __( 'Lifetime', 'subtleforms-license-server' );
		}
		return wp_date( get_option( 'date_format' ), strtotime( $lic['expires_at'] . ' UTC' ) );
	}

	/**
	 * "2 of 5 sites used" / "1 site used, unlimited" (limit set to 0).
	 *
	 * @param array $lic License.
	 */
	public static function usage_label( array $lic ): string {
		$used  = self::used_slots( (int) $lic['id'] );
		$limit = (int) $lic['sites_allowed'];
		if ( $limit <= 0 ) {
			/* translators: %d: sites used */
			return sprintf( _n( '%d site used, unlimited', '%d sites used, unlimited', $used, 'subtleforms-license-server' ), $used );
		}
		/* translators: 1: sites used, 2: sites allowed */
		return sprintf( _n( '%1$d of %2$d site used', '%1$d of %2$d sites used', $limit, 'subtleforms-license-server' ), $used, $limit );
	}

	/**
	 * The payload SubtleForms Pro stores for a license.
	 *
	 * `is_local` tells the requesting site whether it is recognised as a local or
	 * staging site that does not use a seat.
	 *
	 * @param array  $lic    License.
	 * @param string $domain Normalised domain of the requesting site ('' when unknown).
	 */
	public static function payload( array $lic, string $domain = '' ): array {
		$state = self::state( $lic );
		$limit = (int) $lic['sites_allowed'];
		return array(
			'valid'                => $state['valid'],
			'status'               => $state['status'],
			'plan'                 => $lic['plan'],
			'features'             => SFLS_Plans::features( $lic['plan'] ),
			'expires_at'           => $lic['expires_at'] ? gmdate( 'c', strtotime( $lic['expires_at'] . ' UTC' ) ) : null,
			'grace_period_ends_at' => $state['grace_period_ends_at'],
			'sites_allowed'        => $limit,
			'sites_used'           => self::used_slots( (int) $lic['id'] ),
			'check_again_in'       => 12 * HOUR_IN_SECONDS,
			'is_local'             => '' !== $domain && self::is_local_domain( $domain ),
			// Billing (1.4.0). During a free trial the license stays valid with
			// status "valid", so older Pro versions keep working.
			'billing'              => self::billing_source( $lic ),
			'billing_status'       => 'paddle' === self::billing_source( $lic ) && '' !== (string) ( $lic['billing_status'] ?? '' ) ? (string) $lic['billing_status'] : null,
			'trial'                => ! empty( $lic['trial'] ),
			'trial_ends_at'        => self::iso( $lic['trial_ends_at'] ?? null ),
			'renews_at'            => self::iso( $lic['renews_at'] ?? null ),
			'cancels_at'           => self::iso( $lic['cancels_at'] ?? null ),
			'renewal_amount'       => self::renewal_amount( $lic ),
			'manage_url'           => self::manage_url(),
			// Branding (1.8.0): lets a site learn from its daily check that
			// the agency changed the branding. 0 when there is none.
			'branding_version'     => SFLS_Branding::version_for( $lic ),
		);
	}

	/**
	 * Where the license is billed: paddle, woocommerce or manual.
	 *
	 * @param array $lic License.
	 */
	public static function billing_source( array $lic ): string {
		$billing = (string) ( $lic['billing'] ?? '' );
		if ( in_array( $billing, array( 'paddle', 'woocommerce', 'manual' ), true ) ) {
			return $billing;
		}
		return (int) ( $lic['order_id'] ?? 0 ) > 0 ? 'woocommerce' : 'manual';
	}

	/**
	 * Whether a license renews through a Paddle subscription.
	 *
	 * @param array $lic License.
	 */
	public static function renewal_confirmed( array $lic ): bool {
		if ( 'paddle' !== self::billing_source( $lic ) ) {
			return true;
		}
		return '' !== (string) ( $lic['paddle_subscription_id'] ?? '' ) && in_array( (string) ( $lic['billing_status'] ?? '' ), array( 'active', 'trialing', 'past_due' ), true );
	}

	/**
	 * Whether a Paddle license is tied to a subscription.
	 *
	 * @param array $lic License.
	 */
	public static function is_paddle( array $lic ): bool {
		return 'paddle' === self::billing_source( $lic ) && '' !== (string) ( $lic['paddle_subscription_id'] ?? '' );
	}

	/**
	 * GMT datetime column as ISO 8601, or null.
	 *
	 * @param string|null $value GMT datetime.
	 */
	public static function iso( $value ): ?string {
		return empty( $value ) ? null : gmdate( 'c', strtotime( $value . ' UTC' ) );
	}

	/**
	 * Formatted next charge ("$79.00"), or null when unknown.
	 *
	 * @param array $lic License.
	 */
	public static function renewal_amount( array $lic ): ?string {
		if ( ! self::is_paddle( $lic ) || '' === (string) ( $lic['billing_amount'] ?? '' ) || '' === (string) ( $lic['billing_currency'] ?? '' ) || ! empty( $lic['cancels_at'] ) ) {
			return null;
		}
		return SFLS_Paddle::format_amount( (string) $lic['billing_amount'], (string) $lic['billing_currency'] );
	}

	/**
	 * My Account › Licenses on this site (where customers manage billing).
	 */
	public static function manage_url(): string {
		if ( function_exists( 'wc_get_account_endpoint_url' ) && function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'myaccount' ) > 0 ) {
			return wc_get_account_endpoint_url( 'licenses' );
		}
		return home_url( '/my-account/licenses/' );
	}

	// ── Admin queries ───────────────────────────────────────────────────

	/**
	 * Search licenses for the admin list and the CSV export.
	 *
	 * @param array $args {
	 *     @type string $search Key, email or activated domain fragment.
	 *     @type string $status '', active, expiring (in 30 days), expired, suspended.
	 *     @type string $plan   Plan slug or ''.
	 *     @type int    $per    Rows per page (0 = all).
	 *     @type int    $offset Offset.
	 * }
	 * @return array{0:array[],1:int} Rows and total.
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$search = trim( (string) ( $args['search'] ?? '' ) );
		$status = (string) ( $args['status'] ?? '' );
		$plan   = sanitize_key( (string) ( $args['plan'] ?? '' ) );
		$per    = max( 0, (int) ( $args['per'] ?? 20 ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$like = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';
		$now  = current_time( 'mysql', true );
		$soon = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$vals = array( $like, $like, $like, $like, $plan, $plan, $status, $status, $now, $status, $now, $soon, $status, $now, $status );
		$per  = $per > 0 ? $per : 1000000; // 0 = everything (CSV export).

		// The replacements are passed as one array, which the placeholder-count sniff cannot read.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.* FROM {$wpdb->prefix}sfls_licenses l
				WHERE ( %s = '' OR l.license_key LIKE %s OR l.customer_email LIKE %s
					OR EXISTS ( SELECT 1 FROM {$wpdb->prefix}sfls_activations a WHERE a.license_id = l.id AND a.domain LIKE %s ) )
				AND ( %s = '' OR l.plan = %s )
				AND ( %s = ''
					OR ( %s = 'active' AND l.status = 'active' AND ( l.expires_at IS NULL OR l.expires_at > %s ) )
					OR ( %s = 'expiring' AND l.status = 'active' AND l.expires_at > %s AND l.expires_at <= %s )
					OR ( %s = 'expired' AND l.status = 'active' AND l.expires_at <= %s )
					OR ( %s = 'suspended' AND l.status IN ( 'suspended', 'refunded' ) ) )
				ORDER BY l.id DESC LIMIT %d OFFSET %d",
				array_merge( $vals, array( $per, $offset ) )
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses l
				WHERE ( %s = '' OR l.license_key LIKE %s OR l.customer_email LIKE %s
					OR EXISTS ( SELECT 1 FROM {$wpdb->prefix}sfls_activations a WHERE a.license_id = l.id AND a.domain LIKE %s ) )
				AND ( %s = '' OR l.plan = %s )
				AND ( %s = ''
					OR ( %s = 'active' AND l.status = 'active' AND ( l.expires_at IS NULL OR l.expires_at > %s ) )
					OR ( %s = 'expiring' AND l.status = 'active' AND l.expires_at > %s AND l.expires_at <= %s )
					OR ( %s = 'expired' AND l.status = 'active' AND l.expires_at <= %s )
					OR ( %s = 'suspended' AND l.status IN ( 'suspended', 'refunded' ) ) )",
				$vals
			)
		);
		// phpcs:enable

		return array( (array) $rows, $total );
	}

	/**
	 * Rows for the CSV export, one per license.
	 *
	 * @param array[] $licenses Licenses.
	 * @return array[] First row is the header.
	 */
	public static function csv_rows( array $licenses ): array {
		$out   = array( array( 'id', 'license_key', 'plan', 'status', 'sites_allowed', 'sites_used', 'domains', 'customer_email', 'user_id', 'order_id', 'expires_at', 'created_at' ) );
		$sites = self::activations_by_license( array_map( 'intval', wp_list_pluck( $licenses, 'id' ) ) );
		foreach ( $licenses as $lic ) {
			$rows  = $sites[ (int) $lic['id'] ] ?? array();
			$out[] = array(
				(int) $lic['id'],
				(string) $lic['license_key'],
				(string) $lic['plan'],
				self::state( $lic )['status'],
				(int) $lic['sites_allowed'],
				count( array_filter( $rows, static fn( $row ) => empty( $row['is_local'] ) ) ),
				implode( ' ', wp_list_pluck( $rows, 'domain' ) ),
				(string) $lic['customer_email'],
				(int) $lic['user_id'],
				(int) $lic['order_id'],
				(string) ( $lic['expires_at'] ?? '' ),
				(string) $lic['created_at'],
			);
		}
		return array_map( array( self::class, 'csv_safe_row' ), $out );
	}

	/**
	 * Activations of many licenses, one query per 1,000 licenses instead of
	 * two per license (LS-09).
	 *
	 * @param int[] $ids License IDs.
	 * @return array<int,array[]> License ID => activations, oldest first.
	 */
	public static function activations_by_license( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built from the chunk size.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT license_id, domain, is_local FROM {$wpdb->prefix}sfls_activations WHERE license_id IN ({$in}) ORDER BY activated_at ASC, id ASC", $chunk ), ARRAY_A );
			foreach ( $rows as $row ) {
				$out[ (int) $row['license_id'] ][] = $row;
			}
		}
		return $out;
	}

	/**
	 * Neutralise spreadsheet formulas (=, +, -, @) in CSV cells.
	 *
	 * @param array $row Cells.
	 * @return array
	 */
	public static function csv_safe_row( array $row ): array {
		return array_map(
			static function ( $cell ) {
				$cell = (string) $cell;
				return ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $cell : $cell;
			},
			$row
		);
	}

	/**
	 * Numbers for the overview cards.
	 *
	 * @return array{active:int,activations:int,expiring:int}
	 */
	public static function stats(): array {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$soon = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND ( expires_at IS NULL OR expires_at > %s )", $now ) );
		$activations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_activations" );
		$expiring    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND expires_at > %s AND expires_at <= %s", $now, $soon ) );
		// phpcs:enable
		return array(
			'active'      => $active,
			'activations' => $activations,
			'expiring'    => $expiring,
		);
	}

	/**
	 * Rows per page for the reminder queries below. Callers page with
	 * $after_id until a page comes back shorter (LS-17).
	 */
	const PAGE = 500;

	/**
	 * Active, non-lifetime licenses whose expiry falls in a window, one page
	 * at a time in ID order.
	 *
	 * @param string $from     GMT datetime (exclusive).
	 * @param string $to       GMT datetime (inclusive).
	 * @param int    $after_id Last ID of the previous page (0 for the first).
	 * @return array[]
	 */
	public static function expiring_between( string $from, string $to, int $after_id = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s AND id > %d ORDER BY id ASC LIMIT %d", $from, $to, $after_id, self::PAGE ), ARRAY_A );
	}

	/**
	 * Paddle licenses whose trial ends, or whose subscription renews, in a
	 * window, and that are not set to cancel.
	 *
	 * @param string $column   trial_ends_at | renews_at.
	 * @param string $from     GMT datetime (exclusive).
	 * @param string $to       GMT datetime (inclusive).
	 * @param int    $after_id Last ID of the previous page (0 for the first).
	 * @return array[]
	 */
	public static function paddle_due( string $column, string $from, string $to, int $after_id = 0 ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 'trial_ends_at' === $column ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND billing = 'paddle' AND trial = 1 AND billing_status = 'trialing' AND cancels_at IS NULL AND trial_ends_at IS NOT NULL AND trial_ends_at > %s AND trial_ends_at <= %s AND id > %d ORDER BY id ASC LIMIT %d", $from, $to, $after_id, self::PAGE ), ARRAY_A );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND billing = 'paddle' AND trial = 0 AND billing_status IN ( 'active', 'past_due' ) AND cancels_at IS NULL AND renews_at IS NOT NULL AND renews_at > %s AND renews_at <= %s AND id > %d ORDER BY id ASC LIMIT %d", $from, $to, $after_id, self::PAGE ), ARRAY_A );
		// phpcs:enable
	}
}
