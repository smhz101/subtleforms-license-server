<?php
/**
 * Trace for failures in payment paths: WooCommerce log (source sfls-paddle),
 * a private note on the order when there is one, and the `sfls_logged` action.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment failure log.
 */
final class SFLS_Log {

	const SOURCE = 'sfls-paddle';

	/**
	 * Record a failure. Never pass secrets; license keys in $data are masked.
	 *
	 * @param string        $context Where it happened, e.g. "poll".
	 * @param string        $message What went wrong.
	 * @param WC_Order|null $order   Order to add a private note to.
	 * @param array         $data    Extra values for the log line.
	 */
	public static function payment( string $context, string $message, ?WC_Order $order = null, array $data = array() ): void {
		foreach ( $data as $name => $value ) {
			if ( preg_match( '/secret|token|api_key|authorization|card|password/i', (string) $name ) ) {
				unset( $data[ $name ] );
			} elseif ( 'license_key' === $name ) {
				$data[ $name ] = SFLS_Licenses::mask_key( (string) $value );
			}
		}
		$line = '[' . $context . '] ' . $message . ( $data ? ' ' . wp_json_encode( $data ) : '' );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $line, array( 'source' => self::SOURCE ) );
		} else {
			error_log( '[' . self::SOURCE . '] ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		if ( $order ) {
			/* translators: %s: what went wrong */
			$order->add_order_note( sprintf( __( 'Paddle: %s', 'subtleforms-license-server' ), $message ) );
		}
		do_action( 'sfls_logged', $context, $message, $order );
	}

	/**
	 * Text of a WP_Error, or the value as a string.
	 *
	 * @param mixed $error WP_Error or anything else.
	 */
	public static function text( $error ): string {
		return $error instanceof WP_Error ? $error->get_error_code() . ': ' . $error->get_error_message() : (string) $error;
	}
}
