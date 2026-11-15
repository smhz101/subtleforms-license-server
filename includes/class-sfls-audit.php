<?php
/**
 * Audit log of admin changes to licenses.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Who changed what on a license. Visible to administrators only.
 */
final class SFLS_Audit {

	/**
	 * Fields whose changes are logged by diff().
	 */
	const FIELDS = array( 'status', 'expires_at', 'sites_allowed', 'customer_email', 'license_key' );

	/**
	 * Record one change.
	 *
	 * @param int    $license_id License ID.
	 * @param string $field      Field name.
	 * @param string $old        Old value.
	 * @param string $new        New value.
	 * @param string $context    Where it came from: admin, bulk, row_action.
	 */
	public static function record( int $license_id, string $field, string $old, string $new, string $context = 'admin' ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			SFLS_DB::audit_log(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => get_current_user_id(),
				'license_id' => $license_id,
				'field'      => substr( $field, 0, 40 ),
				'old_value'  => $old,
				'new_value'  => $new,
				'context'    => substr( $context, 0, 40 ),
			)
		);
	}

	/**
	 * Record every logged field that differs between a license and the data
	 * about to be saved on it. Keys are masked.
	 *
	 * @param array  $lic     License row before the change.
	 * @param array  $data    Columns about to be written.
	 * @param string $context Context.
	 */
	public static function diff( array $lic, array $data, string $context = 'admin' ): void {
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$old = (string) ( $lic[ $field ] ?? '' );
			$new = (string) ( $data[ $field ] ?? '' );
			if ( $old === $new ) {
				continue;
			}
			if ( 'license_key' === $field ) {
				$old = SFLS_Licenses::mask_key( $old );
				$new = SFLS_Licenses::mask_key( $new );
			}
			self::record( (int) $lic['id'], $field, $old, $new, $context );
		}
	}

	/**
	 * Latest entries of a license, newest first.
	 *
	 * @param int $license_id License ID.
	 * @param int $limit      Rows.
	 * @return array[]
	 */
	public static function entries( int $license_id, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SFLS_DB::audit_log() . ' WHERE license_id = %d ORDER BY id DESC LIMIT %d', $license_id, $limit ), ARRAY_A );
	}
}
