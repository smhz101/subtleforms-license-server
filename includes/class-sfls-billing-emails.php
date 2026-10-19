<?php
/**
 * Emails for Paddle licenses. Paddle sends its own receipts; these carry
 * what only we know: the key, the site limit, dates and how to manage it.
 *
 *   welcome            The license key, Download Pro and the install steps
 *                      (and the trial end date for a trial).
 *   trial_ending       Before the trial ends: amount, date and how to cancel.
 *   renewal_notice     Before each yearly renewal: amount, date, how to cancel.
 *   payment_succeeded  A payment went through (trial converted, renewal, upgrade).
 *   payment_failed     A renewal charge failed: update the card.
 *   canceled           Auto-renew turned off (or the subscription ended).
 *   trial_rejected     A second trial was canceled before any charge.
 *
 * The license-ended email is the lapsed stage of SFLS_Reminders.
 *
 * Each license email goes out once per marker (see SFLS_Reminders::add_marker()).
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Billing email templates.
 */
final class SFLS_Billing_Emails {

	/**
	 * Templates for the Send test email select: slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function templates(): array {
		return array(
			'welcome'           => __( 'License key and install steps', 'subtleforms-license-server' ),
			'welcome_trial'     => __( 'Free trial started', 'subtleforms-license-server' ),
			'trial_ending'      => __( 'Trial ends soon', 'subtleforms-license-server' ),
			'renewal_notice'    => __( 'Renewal coming up', 'subtleforms-license-server' ),
			'payment_succeeded' => __( 'Payment received', 'subtleforms-license-server' ),
			'payment_failed'    => __( 'Payment failed', 'subtleforms-license-server' ),
			'canceled'          => __( 'Auto-renew turned off', 'subtleforms-license-server' ),
			'trial_rejected'    => __( 'Second trial canceled', 'subtleforms-license-server' ),
		);
	}

	/**
	 * Send a template to a license's customer once per marker.
	 *
	 * @param array  $lic      License.
	 * @param string $template Template slug.
	 * @param string $marker   Marker that makes it once-only.
	 * @param array  $extra    Template data.
	 */
	public static function send_once( array $lic, string $template, string $marker, array $extra = array() ): bool {
		if ( SFLS_Reminders::has_marker( $lic, $marker ) ) {
			return false;
		}
		[ $to, $name ] = SFLS_Reminders::recipient( $lic );
		if ( '' === $to ) {
			return false;
		}
		// Mark first: a webhook retried while the mail server is slow must not send twice.
		SFLS_Reminders::add_marker( $lic, $marker );
		[ $subject, $heading, $body ] = self::build( $template, $lic, $extra, $name );
		$ok                           = SFLS_Reminders::deliver( $to, $subject, $heading, $body );
		do_action( 'sfls_billing_email_sent', $lic, $template, $ok );
		return $ok;
	}

	/**
	 * Send a template to an address that has no license (trial_rejected).
	 *
	 * @param string $to       Recipient.
	 * @param string $name     First name.
	 * @param string $template Template slug.
	 * @param array  $extra    Template data.
	 */
	public static function send_to( string $to, string $name, string $template, array $extra = array() ): bool {
		[ $subject, $heading, $body ] = self::build( $template, array(), $extra, $name );
		return SFLS_Reminders::deliver( $to, $subject, $heading, $body );
	}

	/**
	 * An example of a template, sent to an admin. No license is touched.
	 *
	 * @param string $to       Recipient.
	 * @param string $template Template slug.
	 */
	public static function send_test( string $to, string $template ): bool {
		if ( ! is_email( $to ) || ! isset( self::templates()[ $template ] ) ) {
			return false;
		}
		$trial = in_array( $template, array( 'welcome_trial', 'trial_ending' ), true );
		$now   = time();
		$lic   = array(
			'id'               => 0,
			'license_key'      => 'SFP-EXAMPLE0-00000000-00000000-12345678',
			'plan'             => 'pro',
			'status'           => 'active',
			'sites_allowed'    => $trial ? 1 : (int) ( SFLS_Plans::get( 'pro' )['sites'] ?? 5 ),
			'customer_email'   => $to,
			'user_id'          => 0,
			'billing'          => 'paddle',
			'billing_status'   => $trial ? 'trialing' : ( 'payment_failed' === $template ? 'past_due' : 'active' ),
			'trial'            => $trial ? 1 : 0,
			'trial_ends_at'    => $trial ? gmdate( 'Y-m-d H:i:s', $now + 3 * DAY_IN_SECONDS ) : null,
			'renews_at'        => gmdate( 'Y-m-d H:i:s', $now + ( $trial ? 3 : 14 ) * DAY_IN_SECONDS ),
			'cancels_at'       => 'canceled' === $template ? gmdate( 'Y-m-d H:i:s', $now + 120 * DAY_IN_SECONDS ) : null,
			'expires_at'       => gmdate( 'Y-m-d H:i:s', $now + ( $trial ? 6 : 125 ) * DAY_IN_SECONDS ),
			'billing_amount'   => '7900',
			'billing_currency' => 'USD',
			'paddle_customer_id'     => 'ctm_example',
			'paddle_subscription_id' => 'sub_example',
		);
		$user  = wp_get_current_user();
		$name  = $user->ID ? ( $user->first_name ? $user->first_name : $user->display_name ) : '';
		$extra = array(
			'amount'      => SFLS_Paddle::format_amount( '7900', 'USD' ),
			'was_trial'   => false,
			'new_account' => false,
			'reason'      => __( 'This email address already had a free trial.', 'subtleforms-license-server' ),
		);
		[ $subject, $heading, $body ] = self::build( 'welcome_trial' === $template ? 'welcome' : $template, $lic, $extra, (string) $name );
		return SFLS_Reminders::deliver( $to, $subject, $heading, $body, __( 'This is a test. The license shown is an example and no customer received this email.', 'subtleforms-license-server' ) );
	}

	// ── Building ────────────────────────────────────────────────────────

	/**
	 * A date column for people.
	 *
	 * @param string|null $gmt GMT datetime.
	 */
	private static function date( $gmt ): string {
		return $gmt ? wp_date( get_option( 'date_format' ), strtotime( $gmt . ' UTC' ) ) : '';
	}

	/**
	 * My Account › Licenses, at the license.
	 *
	 * @param array $lic License.
	 */
	private static function manage_link( array $lic ): string {
		return SFLS_Licenses::manage_url() . ( ! empty( $lic['id'] ) ? '#license-' . (int) $lic['id'] : '' );
	}

	/**
	 * Key/value table.
	 *
	 * @param array<string,string> $rows Rows.
	 */
	private static function table( array $rows ): string {
		$html = '<table cellspacing="0" cellpadding="8" style="border-collapse:collapse;width:100%;margin:16px 0;border:1px solid #e5e5e5;">';
		foreach ( array_filter( $rows, 'strlen' ) as $label => $value ) {
			$html .= '<tr><th style="text-align:left;border-bottom:1px solid #e5e5e5;width:35%;">' . esc_html( $label ) . '</th><td style="border-bottom:1px solid #e5e5e5;">' . esc_html( $value ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Paragraph.
	 *
	 * @param string $text Text.
	 */
	private static function p( string $text ): string {
		return '<p>' . esc_html( $text ) . '</p>';
	}

	/**
	 * Subject, heading and body HTML of a template.
	 *
	 * @param string $template Template slug.
	 * @param array  $lic      License (empty for trial_rejected).
	 * @param array  $extra    amount, was_trial, is_upgrade, new_account, reason.
	 * @param string $name     First name.
	 * @return array{0:string,1:string,2:string}
	 */
	public static function build( string $template, array $lic, array $extra = array(), string $name = '' ): array {
		$plan    = isset( $lic['plan'] ) ? SFLS_Plans::label( (string) $lic['plan'] ) : '';
		$amount  = (string) ( $extra['amount'] ?? ( $lic ? (string) SFLS_Licenses::renewal_amount( $lic ) : '' ) );
		$hello   = self::p( '' !== $name ? sprintf( /* translators: %s: first name */ __( 'Hi %s,', 'subtleforms-license-server' ), $name ) : __( 'Hi,', 'subtleforms-license-server' ) );
		$support = self::p(
			sprintf(
				/* translators: %s: support email */
				__( 'Questions? Reply to this email or write to %s.', 'subtleforms-license-server' ),
				SFLS_Settings::support_email()
			)
		);
		$tax     = __( 'plus sales tax or VAT where it applies', 'subtleforms-license-server' );
		$sites   = $lic ? SFLS_Plans::sites_label( (int) ( SFLS_Plans::get( (string) $lic['plan'] )['sites'] ?? 1 ) ) : '';
		$manage  = $lic ? self::manage_link( $lic ) : SFLS_Settings::pricing_url();

		switch ( $template ) {
			case 'welcome':
				$trial   = ! empty( $lic['trial'] );
				$subject = $trial ? __( 'Your SubtleForms Pro free trial has started', 'subtleforms-license-server' ) : __( 'Your SubtleForms Pro license key', 'subtleforms-license-server' );
				$heading = $trial ? __( 'Your free trial has started', 'subtleforms-license-server' ) : __( 'Welcome to SubtleForms Pro', 'subtleforms-license-server' );
				$body    = $hello;
				$body   .= self::p(
					$trial
						? sprintf(
							/* translators: 1: plan, 2: date, 3: amount */
							__( 'Your free trial of SubtleForms Pro %1$s is ready. It covers 1 site and ends on %2$s. Then your card is charged %3$s for the first year, unless you cancel before.', 'subtleforms-license-server' ),
							$plan,
							self::date( $lic['trial_ends_at'] ?? null ),
							'' !== $amount ? $amount : __( 'the plan price', 'subtleforms-license-server' )
						)
						: __( 'Thanks for buying SubtleForms Pro. Here is your license key.', 'subtleforms-license-server' )
				);
				$body .= '<p style="margin:0 0 4px;"><code style="font-family:Menlo,Consolas,monospace;font-size:15px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;display:inline-block;word-break:break-all;">' . esc_html( (string) $lic['license_key'] ) . '</code></p>';
				$body .= self::table(
					array(
						__( 'Plan', 'subtleforms-license-server' )        => $plan,
						__( 'Sites', 'subtleforms-license-server' )       => SFLS_Plans::sites_label( (int) $lic['sites_allowed'] ),
						__( 'Trial ends', 'subtleforms-license-server' )  => $trial ? self::date( $lic['trial_ends_at'] ?? null ) : '',
						__( 'Renews', 'subtleforms-license-server' )      => ! $trial ? self::date( $lic['renews_at'] ?? $lic['expires_at'] ?? null ) : '',
					)
				);

				$button = SFLS_Releases::has_package() && function_exists( 'wc_get_account_endpoint_url' );
				if ( $button ) {
					$body .= SFLS_Reminders::button( wc_get_account_endpoint_url( SFLS_Account::EP_DOWNLOADS ), __( 'Download Pro', 'subtleforms-license-server' ) );
				}
				$body .= '<h3 style="margin:24px 0 8px;">' . esc_html__( 'How to install Pro', 'subtleforms-license-server' ) . '</h3><ol style="margin:0 0 0 20px;padding:0;">';
				foreach ( SFLS_Delivery::steps( $button ) as $step ) {
					$body .= '<li style="margin:0 0 8px;"><strong>' . esc_html( $step[0] ) . '.</strong> ' . esc_html( $step[1] ) . '</li>';
				}
				$body .= '</ol>';
				if ( ! empty( $extra['new_account'] ) ) {
					$body .= self::p( __( 'We created an account for you with this email address. A separate email has a link to set your password, so you can sign in to download Pro and manage your license.', 'subtleforms-license-server' ) );
				}
				$body .= self::p( __( 'Paddle, our payment provider, sends the receipt for the payment separately.', 'subtleforms-license-server' ) );
				break;

			case 'trial_ending':
				$date    = self::date( $lic['trial_ends_at'] ?? null );
				/* translators: %s: date */
				$subject = sprintf( __( 'Your SubtleForms Pro trial ends on %s', 'subtleforms-license-server' ), $date );
				$heading = __( 'Your free trial ends soon', 'subtleforms-license-server' );
				$body    = $hello . self::p(
					sprintf(
						/* translators: 1: date, 2: amount, 3: tax note, 4: plan, 5: sites */
						__( 'Your SubtleForms Pro free trial ends on %1$s. On that day your card is charged %2$s (%3$s) for a year of the %4$s plan, and your license grows from 1 site to %5$s. You do not need to do anything to keep Pro.', 'subtleforms-license-server' ),
						$date,
						'' !== $amount ? $amount : __( 'the plan price', 'subtleforms-license-server' ),
						$tax,
						$plan,
						strtolower( $sites )
					)
				);
				$body .= self::p( __( 'Not for you? Cancel before the trial ends in My Account › Licenses with Cancel auto-renew, and you will not be charged.', 'subtleforms-license-server' ) );
				$body .= SFLS_Reminders::button( $manage, __( 'Manage billing', 'subtleforms-license-server' ) );
				break;

			case 'renewal_notice':
				$date    = self::date( $lic['renews_at'] ?? null );
				/* translators: %s: date */
				$subject = sprintf( __( 'SubtleForms Pro renews on %s', 'subtleforms-license-server' ), $date );
				$heading = __( 'Your yearly renewal is coming up', 'subtleforms-license-server' );
				$body    = $hello . self::p(
					sprintf(
						/* translators: 1: plan, 2: date, 3: amount, 4: tax note */
						__( 'Your SubtleForms Pro %1$s license renews automatically on %2$s. We will charge %3$s (%4$s) to the card on file, and Pro keeps running with updates and support for another year.', 'subtleforms-license-server' ),
						$plan,
						$date,
						'' !== $amount ? $amount : __( 'the plan price', 'subtleforms-license-server' ),
						$tax
					)
				);
				$body .= self::table(
					array(
						__( 'License', 'subtleforms-license-server' ) => SFLS_Licenses::mask_key( (string) $lic['license_key'] ),
						__( 'Sites', 'subtleforms-license-server' )   => ! empty( $lic['id'] ) ? SFLS_Licenses::usage_label( $lic ) : SFLS_Plans::sites_label( (int) $lic['sites_allowed'] ),
					)
				);
				$body .= self::p( __( 'To change the card, or to stop the renewal, open My Account › Licenses and use Update payment method or Cancel auto-renew.', 'subtleforms-license-server' ) );
				$body .= SFLS_Reminders::button( $manage, __( 'Manage billing', 'subtleforms-license-server' ) );
				break;

			case 'payment_succeeded':
				$subject = __( 'Payment received for SubtleForms Pro', 'subtleforms-license-server' );
				$heading = __( 'Thanks, your payment went through', 'subtleforms-license-server' );
				if ( ! empty( $extra['was_trial'] ) ) {
					$intro = sprintf(
						/* translators: 1: amount, 2: plan, 3: sites */
						__( 'Your free trial is over and we received %1$s. Your %2$s license now covers %3$s.', 'subtleforms-license-server' ),
						$amount,
						$plan,
						strtolower( SFLS_Plans::sites_label( (int) $lic['sites_allowed'] ) )
					);
				} elseif ( ! empty( $extra['is_upgrade'] ) ) {
					$intro = sprintf(
						/* translators: 1: amount, 2: plan */
						__( 'We received %1$s for your upgrade. Your license is now on the %2$s plan with the same key.', 'subtleforms-license-server' ),
						$amount,
						$plan
					);
				} else {
					$intro = sprintf(
						/* translators: 1: amount, 2: plan */
						__( 'We received %1$s and renewed your SubtleForms Pro %2$s license for another year.', 'subtleforms-license-server' ),
						$amount,
						$plan
					);
				}
				$body  = $hello . self::p( $intro );
				$body .= self::table(
					array(
						__( 'License', 'subtleforms-license-server' )      => SFLS_Licenses::mask_key( (string) $lic['license_key'] ),
						__( 'Next renewal', 'subtleforms-license-server' ) => empty( $lic['cancels_at'] ) ? self::date( $lic['renews_at'] ?? null ) : '',
					)
				);
				$body .= self::p( __( 'Paddle, our payment provider, emails the receipt separately.', 'subtleforms-license-server' ) );
				break;

			case 'payment_failed':
				$subject = __( 'Payment failed for SubtleForms Pro', 'subtleforms-license-server' );
				$heading = __( 'We could not charge your card', 'subtleforms-license-server' );
				$body    = $hello . self::p(
					sprintf(
						/* translators: %s: plan */
						__( 'The payment for your SubtleForms Pro %s license did not go through. Paddle will try again over the next days.', 'subtleforms-license-server' ),
						$plan
					)
				);
				$body .= self::p(
					sprintf(
						/* translators: %s: date */
						__( 'Pro keeps working until %s. Update your payment method to keep it running without a break.', 'subtleforms-license-server' ),
						SFLS_Licenses::expiry_label( $lic )
					)
				);
				$body .= SFLS_Reminders::button( $manage, __( 'Update payment method', 'subtleforms-license-server' ) );
				break;

			case 'canceled':
				$until   = ! empty( $lic['cancels_at'] ) ? self::date( $lic['cancels_at'] ) : SFLS_Licenses::expiry_label( $lic );
				$subject = __( 'Auto-renew is off for SubtleForms Pro', 'subtleforms-license-server' );
				$heading = __( 'Your subscription is canceled', 'subtleforms-license-server' );
				if ( ! empty( $lic['trial'] ) ) {
					$intro = ! empty( $lic['cancels_at'] )
						/* translators: %s: date */
						? sprintf( __( 'Your free trial will not turn into a paid plan. Pro keeps working until %s and you will not be charged.', 'subtleforms-license-server' ), $until )
						: __( 'Your free trial is canceled and you will not be charged.', 'subtleforms-license-server' );
				} else {
					/* translators: %s: date */
					$intro = sprintf( __( 'Auto-renew is off. Your license stays active until %s, the end of the period you paid for, and then Pro stops on your sites.', 'subtleforms-license-server' ), $until );
				}
				$body = $hello . self::p( $intro );
				if ( ! empty( $lic['cancels_at'] ) ) {
					$body .= self::p( __( 'Changed your mind? Click Resume in My Account › Licenses before that date and nothing changes.', 'subtleforms-license-server' ) );
					$body .= SFLS_Reminders::button( $manage, __( 'Resume', 'subtleforms-license-server' ) );
				}
				break;

			case 'trial_rejected':
			default:
				$subject = __( 'We could not start your SubtleForms Pro trial', 'subtleforms-license-server' );
				$heading = __( 'Your free trial was not started', 'subtleforms-license-server' );
				$body    = $hello . self::p( trim( (string) ( $extra['reason'] ?? '' ) . ' ' . __( 'Each customer, company and site can try SubtleForms Pro for free once, so we canceled the new subscription before any charge. You have not been billed.', 'subtleforms-license-server' ) ) );
				$body   .= self::p( __( 'You can buy SubtleForms Pro straight away from the pricing page.', 'subtleforms-license-server' ) );
				$body   .= SFLS_Reminders::button( SFLS_Settings::pricing_url(), __( 'See plans', 'subtleforms-license-server' ) );
				break;
		}

		return array( $subject, $heading, $body . $support );
	}
}
