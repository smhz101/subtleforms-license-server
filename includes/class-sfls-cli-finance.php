<?php
/**
 * WP-CLI: wp sfls finance backfill
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finance ledger: Paddle's fee and payout figures.
 *
 * ## EXAMPLES
 *
 *     wp sfls finance backfill --dry-run
 *     wp sfls finance backfill
 */
final class SFLS_CLI_Finance {

	/**
	 * Fetch Paddle's payout totals (and refund adjustments) for Paddle-paid
	 * orders that have none stored. Read-only towards Paddle; safe to repeat.
	 *
	 * [--dry-run]
	 * : Show what would be stored without saving anything.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function backfill( $args, $assoc_args ): void {
		$dry  = ! empty( $assoc_args['dry-run'] );
		$rows = SFLS_Finance::backfill( 0, $dry );
		if ( ! $rows ) {
			WP_CLI::success( 'Every Paddle-paid order already has its figures.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'order', 'txn', 'result', 'fee', 'earnings', 'refunds' ) );
		SFLS_Metrics::flush();
		WP_CLI::success( sprintf( '%d order(s) %s.', count( $rows ), $dry ? 'checked (dry run)' : 'processed' ) );
	}
}
