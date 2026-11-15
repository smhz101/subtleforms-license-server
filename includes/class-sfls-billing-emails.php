<?php
/**
 * Emails for Paddle licenses. Paddle sends its own receipts; these carry
 * what only we know: the key, the site limit, dates and how to manage it.
 *
 *   welcome            The license key, Download Pro and the install steps.
 *   renewal_notice     Before each yearly renewal: amount, date, how to cancel.
 *   payment_succeeded  A payment went through (renewal, upgrade).
 *   payment_failed     A renewal charge failed: update the card.
 *   canceled           Auto-renew turned off (or the subscription ended).
 *
 * The license-ended email is the lapsed stage of SFLS_Reminders.
 *
 * Each license email goes out once per marker (see SFLS_Reminders::add_marker()).
 * The marker is written only after the mail was handed over; a failed send is
 * queued in `sfls_email_retry_queue` and retried by the daily job, three tries in all.
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
			'renewal_notice'    => __( 'Renewal coming up', 'subtleforms-license-server' ),
			'payment_succeeded' => __( 'Payment received', 'subtleforms-license-server' ),
			'payment_failed'    => __( 'Payment failed', 'subtleforms-license-server' ),
			'canceled'          => __( 'Auto-renew turned off', 'subtleforms-license-server' ),
		);
	}

	const QUEUE_OPTION  = 'sfls_email_retry_queue';
	const FAILED_OPTION = 'sfls_email_failures';
	const QUEUE_CAP     = 200;
	const MAX_ATTEMPTS  = 3;

	/**
	 * Hook the retry job and the failure notice.
	 */
	public static function register(): void {
		add_action( SFLS_Reminders::HOOK, array( self::class, 'retry_queue' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_init', array( self::class, 'dismiss_notice' ) );
	}

	/**
	 * Send a template to a license's customer once per marker. The marker is
	 * written only when the mail was sent; a failure is queued for a retry.
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
		[ $to ] = SFLS_Reminders::recipient( $lic );
		if ( '' === $to ) {
			return false;
		}
		foreach ( self::queue() as $entry ) {
			if ( (int) ( $entry['license_id'] ?? 0 ) === (int) ( $lic['id'] ?? 0 ) && ( $entry['marker'] ?? '' ) === $marker ) {
				return false; // The daily job is already retrying it.
			}
		}
		[ $ok, $error ] = self::attempt( $lic, $template, $marker, $extra );
		if ( ! $ok ) {
			self::failed( (int) ( $lic['id'] ?? 0 ), $template, $error );
			self::enqueue(
				array(
					'license_id' => (int) ( $lic['id'] ?? 0 ),
					'template'   => $template,
					'marker'     => $marker,
					'extra'      => $extra,
					'attempts'   => 1,
					'last_error' => $error,
					'queued_at'  => time(),
				)
			);
		}
		do_action( 'sfls_billing_email_sent', $lic, $template, $ok );
		return $ok;
	}

	/**
	 * Build and send once, and write the marker when it went out.
	 *
	 * @param array  $lic      License (current data).
	 * @param string $template Template slug.
	 * @param string $marker   Marker.
	 * @param array  $extra    Template data.
	 * @return array{0:bool,1:string} Sent, and the error when not.
	 */
	private static function attempt( array $lic, string $template, string $marker, array $extra ): array {
		[ $to, $name ] = SFLS_Reminders::recipient( $lic );
		$error         = '';
		$capture       = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error instanceof WP_Error ? $wp_error->get_error_message() : '';
		};
		add_action( 'wp_mail_failed', $capture );
		[ $subject, $heading, $body ] = self::build( $template, $lic, $extra, $name );
		$ok                           = '' !== $to && SFLS_Reminders::deliver( $to, $subject, $heading, $body );
		remove_action( 'wp_mail_failed', $capture );
		if ( $ok ) {
			SFLS_Reminders::add_marker( $lic, $marker );
			return array( true, '' );
		}
		return array( false, '' !== $error ? $error : __( 'wp_mail returned false', 'subtleforms-license-server' ) );
	}

	/**
	 * Tell listeners a billing email failed.
	 *
	 * @param int    $license_id License ID.
	 * @param string $template   Template slug.
	 * @param string $error      Error message.
	 */
	private static function failed( int $license_id, string $template, string $error ): void {
		do_action( 'sfls_billing_email_failed', $license_id, $template, $error );
	}

	/**
	 * The retry queue.
	 *
	 * @return array[]
	 */
	public static function queue(): array {
		return array_values( array_filter( (array) get_option( self::QUEUE_OPTION, array() ), 'is_array' ) );
	}

	/**
	 * Store the queue (not autoloaded), newest 200 kept.
	 *
	 * @param array[] $queue Entries.
	 */
	private static function save_queue( array $queue ): void {
		update_option( self::QUEUE_OPTION, array_slice( array_values( $queue ), -self::QUEUE_CAP ), false );
	}

	/**
	 * Add an entry to the queue.
	 *
	 * @param array $entry Entry.
	 */
	private static function enqueue( array $entry ): void {
		$queue   = self::queue();
		$queue[] = $entry;
		self::save_queue( $queue );
	}

	/**
	 * Retry queued emails (daily job). The email is built again from the
	 * license as it is now. A third failure drops it, leaves an admin notice
	 * and a private note on the license's order.
	 */
	public static function retry_queue(): void {
		$keep = array();
		foreach ( self::queue() as $entry ) {
			$lic = SFLS_Licenses::find( (int) ( $entry['license_id'] ?? 0 ) );
			if ( ! $lic || SFLS_Reminders::has_marker( $lic, (string) $entry['marker'] ) ) {
				continue; // Gone, or sent by another path meanwhile.
			}
			[ $ok, $error ] = self::attempt( $lic, (string) $entry['template'], (string) $entry['marker'], (array) ( $entry['extra'] ?? array() ) );
			if ( $ok ) {
				do_action( 'sfls_billing_email_sent', $lic, $entry['template'], true );
				continue;
			}
			$entry['attempts']   = (int) ( $entry['attempts'] ?? 1 ) + 1;
			$entry['last_error'] = $error;
			self::failed( (int) $lic['id'], (string) $entry['template'], $error );
			if ( $entry['attempts'] < self::MAX_ATTEMPTS ) {
				$keep[] = $entry;
				continue;
			}
			self::give_up( $lic, $entry );
		}
		self::save_queue( $keep );
	}

	/**
	 * Record a dropped email: admin notice and order note.
	 *
	 * @param array $lic   License.
	 * @param array $entry Queue entry.
	 */
	private static function give_up( array $lic, array $entry ): void {
		$failures   = (array) get_option( self::FAILED_OPTION, array() );
		$failures[] = array(
			'license_id' => (int) $lic['id'],
			'template'   => (string) $entry['template'],
			'error'      => (string) $entry['last_error'],
			'at'         => time(),
		);
		update_option( self::FAILED_OPTION, array_slice( $failures, -50 ), false );
		$order = ! empty( $lic['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $lic['order_id'] ) : null;
		if ( $order instanceof WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: email template, 2: error */
					__( 'The "%1$s" email to the customer could not be sent after 3 tries: %2$s', 'subtleforms-license-server' ),
					$entry['template'],
					$entry['last_error']
				)
			);
		}
	}

	/**
	 * Dismissible notice on the admin screens listing emails that were given up on.
	 */
	public static function notice(): void {
		$failures = (array) get_option( self::FAILED_OPTION, array() );
		if ( ! $failures || ! current_user_can( SFLS_Admin::CAP ) ) {
			return;
		}
		$url = wp_nonce_url( add_query_arg( 'sfls_dismiss_email_failures', 1 ), 'sfls_dismiss_email_failures' );
		echo '<div class="notice notice-error sfls-email-failures"><p><strong>' . esc_html__( 'SubtleForms license server: some customer emails could not be sent.', 'subtleforms-license-server' ) . '</strong></p><ul>';
		foreach ( $failures as $f ) {
			echo '<li>' . esc_html(
				sprintf(
					/* translators: 1: email template, 2: license ID, 3: error */
					__( '"%1$s" for license #%2$d: %3$s', 'subtleforms-license-server' ),
					(string) ( $f['template'] ?? '' ),
					(int) ( $f['license_id'] ?? 0 ),
					(string) ( $f['error'] ?? '' )
				)
			) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'subtleforms-license-server' ) . '</a></p></div>';
	}

	/**
	 * Clear the failure notice.
	 */
	public static function dismiss_notice(): void {
		if ( empty( $_GET['sfls_dismiss_email_failures'] ) || ! current_user_can( SFLS_Admin::CAP ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
			return;
		}
		check_admin_referer( 'sfls_dismiss_email_failures' );
		delete_option( self::FAILED_OPTION );
		wp_safe_redirect( remove_query_arg( array( 'sfls_dismiss_email_failures', '_wpnonce' ) ) );
		exit;
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
		$now = time();
		$lic = array(
			'id'               => 0,
			'license_key'      => 'SFP-EXAMPLE0-00000000-00000000-12345678',
			'plan'             => 'pro',
			'status'           => 'active',
			'sites_allowed'    => (int) ( SFLS_Plans::get( 'pro' )['sites'] ?? 5 ),
			'customer_email'   => $to,
			'user_id'          => 0,
			'billing'          => 'paddle',
			'billing_status'   => 'payment_failed' === $template ? 'past_due' : 'active',
			'renews_at'        => gmdate( 'Y-m-d H:i:s', $now + 14 * DAY_IN_SECONDS ),
			'cancels_at'       => 'canceled' === $template ? gmdate( 'Y-m-d H:i:s', $now + 120 * DAY_IN_SECONDS ) : null,
			'expires_at'       => gmdate( 'Y-m-d H:i:s', $now + 125 * DAY_IN_SECONDS ),
			'billing_amount'   => '7900',
			'billing_currency' => 'USD',
			'paddle_customer_id'     => 'ctm_example',
			'paddle_subscription_id' => 'sub_example',
		);
		$user  = wp_get_current_user();
		$name  = $user->ID ? ( $user->first_name ? $user->first_name : $user->display_name ) : '';
		$extra = array(
			'amount'      => SFLS_Paddle::format_amount( '7900', 'USD' ),
			'new_account' => false,
		);
		[ $subject, $heading, $body ] = self::build( $template, $lic, $extra, (string) $name );
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
	 * @param array  $lic      License .
	 * @param array  $extra    amount, is_upgrade, new_account.
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
		$manage  = $lic ? self::manage_link( $lic ) : SFLS_Settings::pricing_url();

		switch ( $template ) {
			case 'welcome':
				$subject = __( 'Your SubtleForms Pro license key', 'subtleforms-license-server' );
				$heading = __( 'Welcome to SubtleForms Pro', 'subtleforms-license-server' );
				$body    = $hello;
				$body   .= self::p( __( 'Thanks for buying SubtleForms Pro. Here is your license key.', 'subtleforms-license-server' ) );
				$body .= '<p style="margin:0 0 4px;"><code style="font-family:Menlo,Consolas,monospace;font-size:15px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;display:inline-block;word-break:break-all;">' . esc_html( (string) $lic['license_key'] ) . '</code></p>';
				$body .= self::table(
					array(
						__( 'Plan', 'subtleforms-license-server' )    => $plan,
						__( 'Sites', 'subtleforms-license-server' )   => SFLS_Plans::sites_label( (int) $lic['sites_allowed'] ),
						__( 'Renews', 'subtleforms-license-server' )  => self::date( $lic['renews_at'] ?? $lic['expires_at'] ?? null ),
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
				if ( ! empty( $extra['is_upgrade'] ) ) {
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
				/* translators: %s: date */
				$intro = sprintf( __( 'Auto-renew is off. Your license stays active until %s, the end of the period you paid for, and then Pro stops on your sites.', 'subtleforms-license-server' ), $until );
				$body = $hello . self::p( $intro );
				if ( ! empty( $lic['cancels_at'] ) ) {
					$body .= self::p( __( 'Changed your mind? Click Resume in My Account › Licenses before that date and nothing changes.', 'subtleforms-license-server' ) );
					$body .= SFLS_Reminders::button( $manage, __( 'Resume', 'subtleforms-license-server' ) );
				}
				break;

			default:
				return array( '', '', '' );
		}

		return array( $subject, $heading, $body . $support );
	}
}
