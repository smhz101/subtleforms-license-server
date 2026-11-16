<?php
/**
 * Scheduled report emails, sent from the daily `sfls_daily` event.
 *
 * A report with schedule weekly or monthly is emailed once per ISO week or
 * calendar month. The period last sent is kept in the option sfls_report_sent
 * (report ID => period key), so a repeated run never sends twice.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schedule and mailer.
 */
final class SFLS_Report_Schedule {

	const SCHEDULES = array( 'none', 'weekly', 'monthly' );

	const OPTION = 'sfls_report_sent';

	const MAX_RECIPIENTS = 10;

	const MAIL_ROWS = 50;

	/**
	 * Hook the cron handler.
	 */
	public static function register(): void {
		add_action(
			SFLS_Reminders::HOOK,
			static function (): void {
				self::run_due();
			}
		);
	}

	/**
	 * Validate a comma-separated list of emails.
	 *
	 * @param string $text List.
	 * @return string[]|WP_Error Clean addresses.
	 */
	public static function parse_recipients( string $text ) {
		$out = array();
		foreach ( preg_split( '/[,\s;]+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $mail ) {
			$mail = strtolower( sanitize_email( $mail ) );
			if ( ! is_email( $mail ) ) {
				return new WP_Error( 'sfls_report_recipients', __( 'One of the recipient addresses is not a valid email.', 'subtleforms-license-server' ) );
			}
			$out[ $mail ] = $mail;
		}
		if ( count( $out ) > self::MAX_RECIPIENTS ) {
			return new WP_Error( 'sfls_report_recipients', __( 'Too many recipients.', 'subtleforms-license-server' ) );
		}
		return array_values( $out );
	}

	/**
	 * Period key of a schedule at a time.
	 *
	 * @param string $schedule weekly|monthly.
	 * @param int    $now      Unix time.
	 */
	public static function period( string $schedule, int $now ): string {
		return 'weekly' === $schedule ? gmdate( 'o-\WW', $now ) : gmdate( 'Y-m', $now );
	}

	/**
	 * Whether a report is due.
	 *
	 * @param array $report Report.
	 * @param int   $now    Unix time.
	 */
	public static function is_due( array $report, int $now ): bool {
		$schedule = (string) ( $report['schedule'] ?? '' );
		if ( ! in_array( $schedule, array( 'weekly', 'monthly' ), true ) || '' === trim( (string) ( $report['recipients'] ?? '' ) ) ) {
			return false;
		}
		$sent = (array) get_option( self::OPTION, array() );
		return ( $sent[ $report['id'] ] ?? '' ) !== self::period( $schedule, $now );
	}

	/**
	 * Forget the sent marker of a deleted report.
	 *
	 * @param int $id Report ID.
	 */
	public static function forget( int $id ): void {
		$sent = (array) get_option( self::OPTION, array() );
		unset( $sent[ $id ] );
		update_option( self::OPTION, $sent, false );
	}

	/**
	 * Send every due report.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return int Reports sent.
	 */
	public static function run_due( ?int $now = null ): int {
		global $wpdb;
		$now  = is_int( $now ) ? $now : time();
		$ids  = $wpdb->get_col( 'SELECT id FROM ' . SFLS_DB::reports() . " WHERE schedule IN ('weekly','monthly')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$sent = 0;
		foreach ( $ids as $id ) {
			$report = SFLS_Reports::find( (int) $id );
			if ( $report && self::is_due( $report, $now ) && self::send( $report ) ) {
				$map                  = (array) get_option( self::OPTION, array() );
				$map[ $report['id'] ] = self::period( $report['schedule'], $now );
				update_option( self::OPTION, $map, false );
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Email one report to its recipients, with the CSV attached.
	 *
	 * @param array $report Report.
	 */
	public static function send( array $report ): bool {
		$to = self::parse_recipients( (string) $report['recipients'] );
		if ( is_wp_error( $to ) || ! $to ) {
			SFLS_Log::payment( 'report-schedule', 'Report ' . (int) $report['id'] . ' has no valid recipients.' );
			return false;
		}
		$result = SFLS_Reports::run( $report );
		if ( is_wp_error( $result ) ) {
			SFLS_Log::payment( 'report-schedule', 'Report ' . (int) $report['id'] . ' failed: ' . SFLS_Log::text( $result ) );
			return false;
		}

		$table = '<table style="border-collapse:collapse;width:100%;font-size:13px;"><tr>';
		foreach ( $result['columns'] as $c ) {
			$table .= '<th style="text-align:left;border-bottom:1px solid #ddd;padding:4px 6px;">' . esc_html( $c['label'] ) . '</th>';
		}
		$table .= '</tr>';
		foreach ( array_slice( $result['rows'], 0, self::MAIL_ROWS ) as $row ) {
			$table .= '<tr>';
			foreach ( array_values( $row ) as $v ) {
				$table .= '<td style="border-bottom:1px solid #eee;padding:4px 6px;">' . esc_html( (string) $v ) . '</td>';
			}
			$table .= '</tr>';
		}
		$table .= '</table>';
		if ( count( $result['rows'] ) > self::MAIL_ROWS ) {
			/* translators: %d: rows shown */
			$table .= '<p>' . esc_html( sprintf( __( 'First %d rows shown. The full report is attached as CSV.', 'subtleforms-license-server' ), self::MAIL_ROWS ) ) . '</p>';
		}

		$file = wp_tempnam( 'sfls-report.csv' );
		if ( ! $file || false === file_put_contents( $file, SFLS_Reports_Admin::csv( $result ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			SFLS_Log::payment( 'report-schedule', 'Report ' . (int) $report['id'] . ': could not write the CSV.' );
			return false;
		}
		$name = sanitize_file_name( 'report-' . (int) $report['id'] . '-' . gmdate( 'Ymd' ) . '.csv' );
		$copy = dirname( $file ) . '/' . $name;
		$copy = ( $copy !== $file && rename( $file, $copy ) ) ? $copy : $file; // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

		/* translators: %s: report name */
		$subject = sprintf( __( 'Scheduled report: %s', 'subtleforms-license-server' ), $report['name'] );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$ok      = (bool) wp_mail( $to, $subject, '<html><body style="font-family:sans-serif;"><h2>' . esc_html( $report['name'] ) . '</h2>' . $table . '</body></html>', $headers, array( $copy ) );
		wp_delete_file( $copy );
		if ( ! $ok ) {
			SFLS_Log::payment( 'report-schedule', 'wp_mail failed for report ' . (int) $report['id'] . '.' );
		}
		return $ok;
	}
}
