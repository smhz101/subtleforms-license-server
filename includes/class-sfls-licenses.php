<?php
/**
 * License records and the rules that decide whether one is usable.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

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

		$plan  = sanitize_key( $args['plan'] ?? 'pro' );
		$def   = SFLS_Plans::get( $plan ) ?? SFLS_Plans::get( 'pro' );
		$now   = current_time( 'mysql', true );
		$months = isset( $args['months'] ) ? (int) $args['months'] : 12;

		$row = array(
			'license_key'    => self::generate_key(),
			'plan'           => $plan,
			'status'         => 'active',
			'sites_allowed'  => isset( $args['sites_allowed'] ) ? (int) $args['sites_allowed'] : (int) $def['sites'],
			'customer_email' => sanitize_email( $args['customer_email'] ?? '' ),
			'user_id'        => (int) ( $args['user_id'] ?? 0 ),
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

	public static function find_by_key( string $key ): ?array {
		global $wpdb;
		$table = SFLS_DB::licenses();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_key = %s", strtoupper( trim( $key ) ) ), ARRAY_A );
		return $row ?: null;
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		$table = SFLS_DB::licenses();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( SFLS_DB::licenses(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( SFLS_DB::activations(), array( 'license_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( SFLS_DB::licenses(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Extend a license by a number of months from its expiry (or from today if
	 * it has already lapsed).
	 */
	public static function extend( int $id, int $months = 12 ): void {
		$lic  = self::find( $id );
		if ( ! $lic ) {
			return;
		}
		$base = ( $lic['expires_at'] && strtotime( $lic['expires_at'] ) > time() ) ? strtotime( $lic['expires_at'] ) : time();
		self::update(
			$id,
			array(
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( "+{$months} months", $base ) ),
				'status'     => 'active',
			)
		);
	}

	/**
	 * @return array[] Activations for a license.
	 */
	public static function activations( int $license_id ): array {
		global $wpdb;
		$table = SFLS_DB::activations();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_id = %d ORDER BY activated_at ASC", $license_id ), ARRAY_A );
	}

	public static function activation( int $license_id, string $domain ): ?array {
		global $wpdb;
		$table = SFLS_DB::activations();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_id = %d AND domain = %s", $license_id, $domain ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Count activations that use up a site slot (local/dev sites are free).
	 */
	public static function used_slots( int $license_id ): int {
		global $wpdb;
		$table = SFLS_DB::activations();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE license_id = %d AND is_local = 0", $license_id ) );
	}

	public static function add_activation( int $license_id, string $domain, string $version = '' ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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

	public static function touch_activation( int $license_id, string $domain, string $version = '' ): void {
		global $wpdb;
		$data = array( 'last_seen_at' => current_time( 'mysql', true ) );
		if ( $version ) {
			$data['plugin_version'] = substr( sanitize_text_field( $version ), 0, 20 );
		}
		$wpdb->update( SFLS_DB::activations(), $data, array( 'license_id' => $license_id, 'domain' => $domain ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public static function remove_activation( int $license_id, string $domain ): void {
		global $wpdb;
		$wpdb->delete( SFLS_DB::activations(), array( 'license_id' => $license_id, 'domain' => $domain ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Normalise a site URL or host to a bare lowercase host without "www.".
	 */
	public static function normalize_domain( string $url ): string {
		$url  = trim( strtolower( $url ) );
		$host = wp_parse_url( str_contains( $url, '://' ) ? $url : 'https://' . $url, PHP_URL_HOST );
		$host = (string) $host;
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Local, staging and development hosts do not use a site slot.
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

	/**
	 * The status a client should see right now.
	 *
	 * @return array{status:string,valid:bool,grace_period_ends_at:?string}
	 */
	public static function state( array $lic ): array {
		if ( 'suspended' === $lic['status'] || 'refunded' === $lic['status'] ) {
			return array( 'status' => 'suspended', 'valid' => false, 'grace_period_ends_at' => null );
		}
		if ( empty( $lic['expires_at'] ) ) {
			return array( 'status' => 'valid', 'valid' => true, 'grace_period_ends_at' => null );
		}
		$expires = strtotime( $lic['expires_at'] . ' UTC' );
		if ( $expires > time() ) {
			return array( 'status' => 'valid', 'valid' => true, 'grace_period_ends_at' => null );
		}
		$grace_end = $expires + self::GRACE_DAYS * DAY_IN_SECONDS;
		if ( $grace_end > time() ) {
			return array( 'status' => 'grace_period', 'valid' => true, 'grace_period_ends_at' => gmdate( 'c', $grace_end ) );
		}
		return array( 'status' => 'expired', 'valid' => false, 'grace_period_ends_at' => null );
	}

	/**
	 * The payload SubtleForms Pro stores for a license.
	 */
	public static function payload( array $lic ): array {
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
		);
	}
}
