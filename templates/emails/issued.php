<?php
/**
 * Email body: licenses issued by hand.
 *
 * Override in a theme: subtleforms-license-server/emails/issued.php
 *
 * @var array $args {
 *     @type array[] $licenses Licenses (license_key, plan, expiry fields).
 *     @type bool    $many     More than one license.
 *     @type string  $url      Signed link to the license page.
 *     @type string  $support  Support email address.
 * }
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;
?>
<p><?php esc_html_e( 'Hi,', 'subtleforms-license-server' ); ?></p>
<p><?php echo esc_html( $args['many'] ? __( 'Here are your SubtleForms Pro license keys.', 'subtleforms-license-server' ) : __( 'Here is your SubtleForms Pro license key.', 'subtleforms-license-server' ) ); ?></p>
<table cellspacing="0" cellpadding="8" style="border-collapse:collapse;width:100%;margin:16px 0;border:1px solid #e5e5e5;">
<?php foreach ( $args['licenses'] as $lic ) : ?>
<tr><td style="border-bottom:1px solid #e5e5e5;"><code style="font-size:15px;"><?php echo esc_html( $lic['license_key'] ); ?></code><br><span style="color:#757575;"><?php echo esc_html( SFLS_Delivery::license_line( $lic ) ); ?></span></td></tr>
<?php endforeach; ?>
</table>
<?php echo SFLS_Reminders::button( $args['url'], __( 'Open your license page', 'subtleforms-license-server' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<p><?php esc_html_e( 'The link opens your license page and the Pro download for 30 days, no account needed.', 'subtleforms-license-server' ); ?></p>
<h3><?php esc_html_e( 'How to install Pro', 'subtleforms-license-server' ); ?></h3><ol>
<?php foreach ( SFLS_Delivery::steps( false ) as $step ) : ?>
<li><strong><?php echo esc_html( $step[0] ); ?></strong> <?php echo esc_html( $step[1] ); ?></li>
<?php endforeach; ?>
</ol>
<p><?php echo esc_html( sprintf( /* translators: %s: support email */ __( 'Questions? Reply to this email or write to %s.', 'subtleforms-license-server' ), $args['support'] ) ); ?></p>
