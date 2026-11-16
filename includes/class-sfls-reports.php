<?php
/**
 * Saved custom reports (table sfls_reports).
 *
 * Private reports are visible to their owner only, shared reports to every
 * user who has the reports capability.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report store.
 */
final class SFLS_Reports {

	const DISPLAYS = array( 'table', 'bar', 'line', 'pie' );

	/**
	 * Capability needed for reports.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'sfls_reports_capability', SFLS_Admin::CAP );
	}

	/**
	 * Create or update a report.
	 *
	 * @param array $data name, shared, definition (array); optional id, user_id.
	 * @return int Report ID.
	 */
	public static function save( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = array(
			'name'       => mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 ),
			'shared'     => empty( $data['shared'] ) ? 0 : 1,
			'definition' => wp_json_encode( $data['definition'] ?? array() ),
			'updated_at' => $now,
		);
		$id  = (int) ( $data['id'] ?? 0 );
		if ( $id > 0 ) {
			$wpdb->update( SFLS_DB::reports(), $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $id;
		}
		$row['user_id']    = (int) ( $data['user_id'] ?? get_current_user_id() );
		$row['created_at'] = $now;
		$wpdb->insert( SFLS_DB::reports(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * One report, definition decoded.
	 *
	 * @param int $id Report ID.
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SFLS_DB::reports() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Reports a user may see, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	public static function visible_to( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SFLS_DB::reports() . ' WHERE shared = 1 OR user_id = %d ORDER BY updated_at DESC, id DESC', $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Delete a report.
	 *
	 * @param int $id Report ID.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( SFLS_DB::reports(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether a user may see (and so run, edit, delete) a report.
	 *
	 * @param array $report  Report.
	 * @param int   $user_id User ID.
	 */
	public static function can_view( array $report, int $user_id ): bool {
		if ( $user_id <= 0 || ! user_can( $user_id, self::capability() ) ) {
			return false;
		}
		return ! empty( $report['shared'] ) || (int) $report['user_id'] === $user_id;
	}

	/**
	 * Run a saved report.
	 *
	 * @param array $report Report.
	 * @return array|WP_Error
	 */
	public static function run( array $report ) {
		return SFLS_Report_Query::run( $report['definition'] );
	}

	/**
	 * Decode the definition column.
	 *
	 * @param array $row DB row.
	 */
	private static function hydrate( array $row ): array {
		$def               = json_decode( (string) $row['definition'], true );
		$row['id']         = (int) $row['id'];
		$row['user_id']    = (int) $row['user_id'];
		$row['shared']     = (int) $row['shared'];
		$row['definition'] = is_array( $def ) ? $def : array();
		return $row;
	}
}
