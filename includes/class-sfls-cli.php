<?php
/**
 * WP-CLI: wp sfls create | list | show
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

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
	 * : Customer email.
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
	 * ## EXAMPLES
	 *
	 *     wp sfls create --plan=agency --email=me@example.com --months=0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function create( $args, $assoc_args ): void {
		$count = max( 1, (int) ( $assoc_args['count'] ?? 1 ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$data = array(
				'plan'           => $assoc_args['plan'] ?? 'pro',
				'customer_email' => $assoc_args['email'] ?? '',
				'months'         => (int) ( $assoc_args['months'] ?? 12 ),
			);
			if ( isset( $assoc_args['sites'] ) ) {
				$data['sites_allowed'] = (int) $assoc_args['sites'];
			}
			$lic = SFLS_Licenses::create( $data );
			WP_CLI::line( $lic['license_key'] . "\t" . $lic['plan'] . "\t" . ( $lic['expires_at'] ?? 'lifetime' ) );
		}
		WP_CLI::success( sprintf( '%d license(s) issued.', $count ) );
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
		$table = SFLS_DB::licenses();
		$rows  = $wpdb->get_results( "SELECT license_key, plan, status, sites_allowed, customer_email, expires_at FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'license_key', 'plan', 'status', 'sites_allowed', 'customer_email', 'expires_at' ) );
	}
}
