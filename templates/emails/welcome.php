<?php
/**
 * Email body: welcome.
 *
 * Override in a theme: subtleforms-license-server/emails/welcome.php
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
$button = SFLS_Releases::has_package() && function_exists( 'wc_get_account_endpoint_url' );
echo $args['hello']; // phpcs:ignore WordPress.Security.EscapeOutput
echo SFLS_Billing_Emails::p( __( 'Thanks for buying SubtleForms Pro. Here is your license key.', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
?>
<p style="margin:0 0 4px;"><code style="font-family:Menlo,Consolas,monospace;font-size:15px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;display:inline-block;word-break:break-all;"><?php echo esc_html( (string) $lic['license_key'] ); ?></code></p>
<?php
echo SFLS_Billing_Emails::table( // phpcs:ignore WordPress.Security.EscapeOutput
	array(
		__( 'Plan', 'subtleforms-license-server' )   => $args['plan'],
		__( 'Sites', 'subtleforms-license-server' )  => SFLS_Plans::sites_label( (int) $lic['sites_allowed'] ),
		__( 'Renews', 'subtleforms-license-server' ) => SFLS_Billing_Emails::date( $lic['renews_at'] ?? $lic['expires_at'] ?? null ),
	)
);
if ( $button ) {
	echo SFLS_Reminders::button( wc_get_account_endpoint_url( SFLS_Account::EP_DOWNLOADS ), __( 'Download Pro', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
}
?>
<h3 style="margin:24px 0 8px;"><?php esc_html_e( 'How to install Pro', 'subtleforms-license-server' ); ?></h3><ol style="margin:0 0 0 20px;padding:0;">
<?php foreach ( SFLS_Delivery::steps( $button ) as $step ) : ?>
<li style="margin:0 0 8px;"><strong><?php echo esc_html( $step[0] ); ?>.</strong> <?php echo esc_html( $step[1] ); ?></li>
<?php endforeach; ?>
</ol>
<?php
if ( ! empty( $args['extra']['new_account'] ) ) {
	echo SFLS_Billing_Emails::p( __( 'We created an account for you with this email address. A separate email has a link to set your password, so you can sign in to download Pro and manage your license.', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
}
echo SFLS_Billing_Emails::p( __( 'Paddle, our payment provider, sends the receipt for the payment separately.', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
echo $args['support']; // phpcs:ignore WordPress.Security.EscapeOutput
