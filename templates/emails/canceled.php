<?php
/**
 * Email body: canceled.
 *
 * Override in a theme: subtleforms-license-server/emails/canceled.php
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
$until = ! empty( $lic['cancels_at'] ) ? SFLS_Billing_Emails::date( $lic['cancels_at'] ) : SFLS_Licenses::expiry_label( $lic );
echo $args['hello']; // phpcs:ignore WordPress.Security.EscapeOutput
/* translators: %s: date */
echo SFLS_Billing_Emails::p( sprintf( __( 'Auto-renew is off. Your license stays active until %s, the end of the period you paid for, and then Pro stops on your sites.', 'subtleforms-license-server' ), $until ) ); // phpcs:ignore WordPress.Security.EscapeOutput
if ( ! empty( $lic['cancels_at'] ) ) {
	echo SFLS_Billing_Emails::p( __( 'Changed your mind? Click Resume in My Account › Licenses before that date and nothing changes.', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo SFLS_Reminders::button( $args['manage'], __( 'Resume', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
}
echo $args['support']; // phpcs:ignore WordPress.Security.EscapeOutput
