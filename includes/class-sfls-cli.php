<?php
/**
 * WP-CLI: wp sfls create | list | remind
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage SubtleForms licenses.
 */
final class SFLS_CLI {

	/**
	 * Issue license keys.
	 *
	 * ## OPTIONS
	 *
	 * [--plan=<plan>]
	 * : starter, pro or agency.
	 * ---
	 * default: pro
	 * ---
	 *
	 * [--email=<email>]
	 * : Customer email. Links the key to the account with this email.
	 *
	 * [--user=<user>]
	 * : User ID, login or email that owns the key.
	 *
	 * [--sites=<n>]
	 * : Site limit (0 = unlimited). Defaults to the plan.
	 *
	 * [--months=<n>]
	 * : Duration in months (0 = lifetime).
	 * ---
	 * default: 12
	 * ---
	 *
	 * [--count=<n>]
	 * : How many keys.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--porcelain]
	 * : Output only the new keys.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sfls create --plan=agency --email=me@example.com --months=0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function create( $args, $assoc_args ): void {
		$count   = max( 1, (int) ( $assoc_args['count'] ?? 1 ) );
		$user_id = 0;
		$email   = (string) ( $assoc_args['email'] ?? '' );
		if ( ! empty( $assoc_args['user'] ) ) {
			$u    = $assoc_args['user'];
			$user = is_numeric( $u ) ? get_userdata( (int) $u ) : ( get_user_by( 'login', $u ) ? get_user_by( 'login', $u ) : get_user_by( 'email', $u ) );
			if ( ! $user ) {
				WP_CLI::error( 'User not found.' );
			}
			$user_id = (int) $user->ID;
			$email   = '' !== $email ? $email : $user->user_email;
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$data = array(
				'plan'           => $assoc_args['plan'] ?? 'pro',
				'customer_email' => $email,
				'user_id'        => $user_id,
				'months'         => (int) ( $assoc_args['months'] ?? 12 ),
			);
			if ( isset( $assoc_args['sites'] ) ) {
				$data['sites_allowed'] = (int) $assoc_args['sites'];
			}
			$lic = SFLS_Licenses::create( $data );
			if ( isset( $assoc_args['porcelain'] ) ) {
				WP_CLI::line( $lic['license_key'] );
				continue;
			}
			WP_CLI::line( $lic['license_key'] . "\t" . $lic['plan'] . "\t" . ( $lic['expires_at'] ?? 'lifetime' ) );
		}
		if ( ! isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::success( sprintf( '%d license(s) issued.', $count ) );
		}
	}

	/**
	 * List licenses.
	 *
	 * [--format=<format>]
	 * : table, csv, json.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function list( $args, $assoc_args ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, license_key, plan, status, sites_allowed, customer_email, user_id, expires_at FROM {$wpdb->prefix}sfls_licenses ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'license_key', 'plan', 'status', 'sites_allowed', 'customer_email', 'user_id', 'expires_at' ) );
	}

	/**
	 * Send renewal reminders that are due now (what the daily cron does).
	 *
	 * [--dry-run]
	 * : Only count what would be sent.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function remind( $args, $assoc_args ): void {
		$dry  = isset( $assoc_args['dry-run'] );
		$sent = SFLS_Reminders::run( $dry );
		WP_CLI::success( sprintf( $dry ? '%d reminder(s) due.' : '%d reminder(s) sent.', $sent ) );
	}
}
