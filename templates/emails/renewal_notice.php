<?php
/**
 * Email body: renewal_notice.
 *
 * Override in a theme: subtleforms-license-server/emails/renewal_notice.php
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
		/* translators: 1: plan, 2: date, 3: amount, 4: tax note */
		__( 'Your SubtleForms Pro %1$s license renews automatically on %2$s. We will charge %3$s (%4$s) to the card on file, and Pro keeps running with updates and support for another year.', 'subtleforms-license-server' ),
		$args['plan'],
		SFLS_Billing_Emails::date( $lic['renews_at'] ?? null ),
		'' !== $args['amount'] ? $args['amount'] : __( 'the plan price', 'subtleforms-license-server' ),
		__( 'plus sales tax or VAT where it applies', 'subtleforms-license-server' )
	)
);
echo SFLS_Billing_Emails::table( // phpcs:ignore WordPress.Security.EscapeOutput
	array(
		__( 'License', 'subtleforms-license-server' ) => SFLS_Licenses::mask_key( (string) $lic['license_key'] ),
		__( 'Sites', 'subtleforms-license-server' )   => ! empty( $lic['id'] ) ? SFLS_Licenses::usage_label( $lic ) : SFLS_Plans::sites_label( (int) $lic['sites_allowed'] ),
	)
);
echo SFLS_Billing_Emails::p( __( 'To change the card, or to stop the renewal, open My Account › Licenses and use Update payment method or Cancel auto-renew.', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
echo SFLS_Reminders::button( $args['manage'], __( 'Manage billing', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
echo $args['support']; // phpcs:ignore WordPress.Security.EscapeOutput
