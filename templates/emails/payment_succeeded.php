<?php
/**
 * Email body: payment_succeeded.
 *
 * Override in a theme: subtleforms-license-server/emails/payment_succeeded.php
 *
 * @var array $args {
 *     @type array  $lic      License row.
 *     @type array  $extra    amount, is_upgrade, new_account.
 *     @type string $name     Customer first name (may be empty).
 *     @type string $plan     Plan label.
 *     @type string $amount   Formatted amount (may be empty).
 *     @type string $manage   URL to manage the license.
 *     @type string $hello    Greeting paragraph HTML.
 *     @type string $support  Support paragraph HTML.
 * }
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

$lic = $args['lic'];
if ( ! empty( $args['extra']['is_upgrade'] ) ) {
	$intro = sprintf(
		/* translators: 1: amount, 2: plan */
		__( 'We received %1$s for your upgrade. Your license is now on the %2$s plan with the same key.', 'subtleforms-license-server' ),
		$args['amount'],
		$args['plan']
	);
} else {
	$intro = sprintf(
		/* translators: 1: amount, 2: plan */
		__( 'We received %1$s and renewed your SubtleForms Pro %2$s license for another year.', 'subtleforms-license-server' ),
		$args['amount'],
		$args['plan']
	);
}
echo $args['hello']; // phpcs:ignore WordPress.Security.EscapeOutput
echo SFLS_Billing_Emails::p( $intro ); // phpcs:ignore WordPress.Security.EscapeOutput
echo SFLS_Billing_Emails::table( // phpcs:ignore WordPress.Security.EscapeOutput
	array(
		__( 'License', 'subtleforms-license-server' )      => SFLS_Licenses::mask_key( (string) $lic['license_key'] ),
		__( 'Next renewal', 'subtleforms-license-server' ) => empty( $lic['cancels_at'] ) ? SFLS_Billing_Emails::date( $lic['renews_at'] ?? null ) : '',
	)
);
echo SFLS_Billing_Emails::p( __( 'Paddle, our payment provider, emails the receipt separately.', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
echo $args['support']; // phpcs:ignore WordPress.Security.EscapeOutput
