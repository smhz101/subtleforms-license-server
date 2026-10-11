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
	 * New key: SFP-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX (128 random bits).
	 */
	public static function generate_key(): string {
		$hex = strtoupper( bin2hex( random_bytes( 16 ) ) );
		return 'SFP-' . implode( '-', str_split( $hex, 8 ) );
	}

	/**
	 * Create a license.
	 *
	 * @param array $args plan, sites_allowed, customer_email, user_id, order_id, product_id, months, notes.
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

		if ( ! $user && $email ) {
			$found = get_user_by( 'email', $email );
			$user  = $found ? (int) $found->ID : 0;
		}

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
		);

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
	 * Delete a license and its activations.
	 *
	 * @param int $id License ID.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( SFLS_DB::activations(), array( 'license_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( SFLS_DB::activation_log(), array( 'license_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * it has already lapsed).
	 *
	 * @param int $id     License ID.
	 * @param int $months Months to add.
	 */
	public static function extend( int $id, int $months = 12 ): void {
		$lic = self::find( $id );
		if ( ! $lic ) {
			return;
		}
		if ( empty( $lic['expires_at'] ) ) {
			self::update( $id, array( 'status' => 'active' ) );
			return; // Lifetime licenses have nothing to extend.
		}
		$current = strtotime( $lic['expires_at'] . ' UTC' );
		$base    = $current > time() ? $current : time();
		self::update(
			$id,
			array(
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( "+{$months} months", $base ) ),
				'status'     => 'active',
			)
		);
	}

	// ── Ownership ───────────────────────────────────────────────────────

	/**
	 * Whether a user owns a license (by user ID or by customer email).
	 *
	 * @param array   $lic  License.
	 * @param WP_User $user User.
	 */
	public static function user_owns( array $lic, WP_User $user ): bool {
		if ( ! $user->ID ) {
			return false;
		}
		if ( (int) $lic['user_id'] > 0 && (int) $lic['user_id'] === (int) $user->ID ) {
			return true;
		}
		return '' !== $lic['customer_email'] && 0 === strcasecmp( $lic['customer_email'], $user->user_email );
	}

	/**
	 * Licenses that belong to a user, newest first.
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
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE user_id = %d OR ( customer_email <> '' AND LOWER(customer_email) = LOWER(%s) ) ORDER BY created_at DESC, id DESC", $user->ID, $user->user_email ), ARRAY_A );
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
	 * Count activations that use up a site slot (local/dev sites are free).
	 *
	 * @param int $license_id License ID.
	 */
	public static function used_slots( int $license_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_activations WHERE license_id = %d AND is_local = 0", $license_id ) );
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

		$existing = self::activation( (int) $lic['id'], $domain );
		if ( ! $existing && ! self::is_local_domain( $domain ) && ! self::has_free_slot( $lic ) ) {
			$limit = (int) $lic['sites_allowed'];
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

		if ( $existing ) {
			self::touch_activation( (int) $lic['id'], $domain, $version );
		} else {
			self::add_activation( (int) $lic['id'], $domain, $version );
			self::log( (int) $lic['id'], $domain, 'activate', $source );
			do_action( 'sfls_domain_activated', $lic, $domain, $version );
		}

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
		if ( $version ) {
			$data['plugin_version'] = substr( sanitize_text_field( $version ), 0, 20 );
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
			self::log( $license_id, $domain, 'deactivate', $source );
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
	 * IP address of the current request ('' on the command line). Behind a
	 * proxy or CDN, filter `sfls_client_ip` to read the forwarded address.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
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
	 * Normalise a site URL or host to a bare lowercase host without "www.".
	 *
	 * @param string $url URL or host.
	 */
	public static function normalize_domain( string $url ): string {
		$url  = trim( strtolower( $url ) );
		$host = wp_parse_url( str_contains( $url, '://' ) ? $url : 'https://' . $url, PHP_URL_HOST );
		$host = (string) $host;
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Local, staging and development hosts do not use a site slot.
	 *
	 * @param string $domain Normalised domain.
	 */
	public static function is_local_domain( string $domain ): bool {
		if ( in_array( $domain, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		if ( preg_match( '/\.(local|test|localhost|invalid|example|dev\.cc|lndo\.site|ddev\.site)$/', $domain ) ) {
			return true;
		}
		if ( preg_match( '/^(staging|stage|dev|test)\./', $domain ) || str_contains( $domain, '.wpengine.com' ) || str_contains( $domain, '.kinsta.cloud' ) || str_contains( $domain, '.instawp.' ) ) {
			return true;
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
		$grace_end = $expires + self::GRACE_DAYS * DAY_IN_SECONDS;
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
	 * "2 of 5" / "1 of unlimited".
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
		);
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
		$out = array( array( 'id', 'license_key', 'plan', 'status', 'sites_allowed', 'sites_used', 'domains', 'customer_email', 'user_id', 'order_id', 'expires_at', 'created_at' ) );
		foreach ( $licenses as $lic ) {
			$domains = wp_list_pluck( self::activations( (int) $lic['id'] ), 'domain' );
			$out[]   = array(
				(int) $lic['id'],
				(string) $lic['license_key'],
				(string) $lic['plan'],
				self::state( $lic )['status'],
				(int) $lic['sites_allowed'],
				self::used_slots( (int) $lic['id'] ),
				implode( ' ', $domains ),
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
	 * Active, non-lifetime licenses whose expiry falls in a window.
	 *
	 * @param string $from GMT datetime (exclusive).
	 * @param string $to   GMT datetime (inclusive).
	 * @return array[]
	 */
	public static function expiring_between( string $from, string $to ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s ORDER BY expires_at ASC LIMIT 500", $from, $to ), ARRAY_A );
	}
}
