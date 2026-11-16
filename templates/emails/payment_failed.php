<?php
/**
 * Email body: payment_failed.
 *
 * Override in a theme: subtleforms-license-server/emails/payment_failed.php
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
echo $args['hello']; // phpcs:ignore WordPress.Security.EscapeOutput
echo SFLS_Billing_Emails::p( // phpcs:ignore WordPress.Security.EscapeOutput
	sprintf(
		/* translators: %s: plan */
		__( 'The payment for your SubtleForms Pro %s license did not go through. Paddle will try again over the next days.', 'subtleforms-license-server' ),
		$args['plan']
	)
);
echo SFLS_Billing_Emails::p( // phpcs:ignore WordPress.Security.EscapeOutput
	sprintf(
		/* translators: %s: date */
		__( 'Pro keeps working until %s. Update your payment method to keep it running without a break.', 'subtleforms-license-server' ),
		SFLS_Licenses::expiry_label( $lic )
	)
);
echo SFLS_Reminders::button( $args['manage'], __( 'Update payment method', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
echo $args['support']; // phpcs:ignore WordPress.Security.EscapeOutput
