<?php
/**
 * Renewal reminder emails, sent by the daily `sfls_daily` cron event.
 *
 * Stages: one per configured "days before expiry" value (default 30 and 7)
 * plus an optional "lapsed" email one day after expiry. A sent stage is
 * remembered on the license as "<stage>|<expires_at>", so each stage goes out
 * once per term and a renewal (new expiry) starts afresh.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reminder scheduler and mailer.
 */
final class SFLS_Reminders {

	const HOOK = 'sfls_daily';

	/**
	 * Hook the cron handler.
	 */
	public function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Make sure the daily event exists.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the daily event.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Sent markers of a license.
	 *
	 * @param array $lic License.
	 * @return string[]
	 */
	private static function markers( array $lic ): array {
		$list = json_decode( (string) ( $lic['reminders'] ?? '' ), true );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}

	/**
	 * Remember stages as sent.
	 *
	 * @param array    $lic    License.
	 * @param string[] $stages Stage names.
	 */
	private static function mark( array $lic, array $stages ): void {
		$list = self::markers( $lic );
		foreach ( $stages as $stage ) {
			$list[] = $stage . '|' . $lic['expires_at'];
		}
		$list = array_slice( array_values( array_unique( $list ) ), -20 );
		SFLS_Licenses::update( (int) $lic['id'], array( 'reminders' => wp_json_encode( $list ) ) );
	}

	/**
	 * Whether a stage was sent for the license's current expiry.
	 *
	 * @param array  $lic   License.
	 * @param string $stage Stage name.
	 */
	private static function was_sent( array $lic, string $stage ): bool {
		return in_array( $stage . '|' . $lic['expires_at'], self::markers( $lic ), true );
	}

	/**
	 * Send whatever is due. Returns the number of emails sent.
	 *
	 * @param bool $dry_run Only count, do not send or mark.
	 */
	public static function run( bool $dry_run = false ): int {
		$sent = 0;
		$now  = time();
		$days = SFLS_Settings::reminder_days();

		if ( $days ) {
			$rows = SFLS_Licenses::expiring_between( gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + max( $days ) * DAY_IN_SECONDS ) );
			foreach ( $rows as $lic ) {
				$left       = (int) ceil( ( strtotime( $lic['expires_at'] . ' UTC' ) - $now ) / DAY_IN_SECONDS );
				$applicable = array_values( array_filter( $days, static fn( $n ) => $left <= $n ) );
				if ( ! $applicable ) {
					continue;
				}
				$stage = 'd' . min( $applicable ); // The most urgent stage that applies.
				if ( self::was_sent( $lic, $stage ) ) {
					continue;
				}
				if ( ! $dry_run ) {
					self::send( $lic, 'before', $left );
					// Mark every applicable stage, so a skipped 30-day email is not sent after the 7-day one.
					self::mark( $lic, array_map( static fn( $n ) => 'd' . $n, $applicable ) );
				}
				++$sent;
			}
		}

		if ( SFLS_Settings::get( 'lapsed_reminder' ) ) {
			$rows = SFLS_Licenses::expiring_between( gmdate( 'Y-m-d H:i:s', $now - 8 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) );
			foreach ( $rows as $lic ) {
				if ( self::was_sent( $lic, 'lapsed' ) ) {
					continue;
				}
				if ( ! $dry_run ) {
					self::send( $lic, 'lapsed', 0 );
					self::mark( $lic, array( 'lapsed' ) );
				}
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * Recipient address and first name for a license.
	 *
	 * @param array $lic License.
	 * @return array{0:string,1:string}
	 */
	private static function recipient( array $lic ): array {
		$user  = (int) $lic['user_id'] ? get_userdata( (int) $lic['user_id'] ) : false;
		$email = is_email( $lic['customer_email'] ) ? $lic['customer_email'] : ( $user ? $user->user_email : '' );
		$name  = $user ? ( $user->first_name ? $user->first_name : $user->display_name ) : '';
		return array( (string) $email, (string) $name );
	}

	/**
	 * Build and send one reminder.
	 *
	 * @param array  $lic   License.
	 * @param string $stage before | lapsed.
	 * @param int    $left  Days left.
	 */
	public static function send( array $lic, string $stage, int $left ): bool {
		[ $to, $name ] = self::recipient( $lic );
		if ( '' === $to ) {
			return false;
		}

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$plan  = SFLS_Plans::label( $lic['plan'] );
		$renew = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'licenses' ) : home_url( '/' );

		if ( 'lapsed' === $stage ) {
			$subject = __( 'Your SubtleForms Pro license has expired', 'subtleforms-license-server' );
			$heading = __( 'Your license has expired', 'subtleforms-license-server' );
			$intro   = sprintf(
				/* translators: %d: grace period days */
				__( 'Your SubtleForms Pro license expired yesterday. Pro keeps working for %d days, after which Pro features, updates and support stop on your sites. Renew now to keep everything running.', 'subtleforms-license-server' ),
				SFLS_Licenses::GRACE_DAYS
			);
		} else {
			$subject = 1 >= $left
				? __( 'Your SubtleForms Pro license expires tomorrow', 'subtleforms-license-server' )
				/* translators: %d: days */
				: sprintf( _n( 'Your SubtleForms Pro license expires in %d day', 'Your SubtleForms Pro license expires in %d days', $left, 'subtleforms-license-server' ), $left );
			$heading = __( 'Time to renew SubtleForms Pro', 'subtleforms-license-server' );
			$intro   = __( 'Your SubtleForms Pro license is coming up for renewal. Renew it to keep getting updates, new features and priority support without a gap.', 'subtleforms-license-server' );
		}

		$rows = array(
			__( 'Plan', 'subtleforms-license-server' )    => $plan,
			__( 'License', 'subtleforms-license-server' ) => SFLS_Licenses::mask_key( $lic['license_key'] ),
			( 'lapsed' === $stage ? __( 'Expired', 'subtleforms-license-server' ) : __( 'Expires', 'subtleforms-license-server' ) ) => SFLS_Licenses::expiry_label( $lic ),
			__( 'Sites', 'subtleforms-license-server' )   => SFLS_Licenses::usage_label( $lic ),
		);

		$body  = '<p>' . esc_html( '' !== $name ? sprintf( /* translators: %s: first name */ __( 'Hi %s,', 'subtleforms-license-server' ), $name ) : __( 'Hi,', 'subtleforms-license-server' ) ) . '</p>';
		$body .= '<p>' . esc_html( $intro ) . '</p>';
		$body .= '<table cellspacing="0" cellpadding="8" style="border-collapse:collapse;width:100%;margin:16px 0;border:1px solid #e5e5e5;">';
		foreach ( $rows as $label => $value ) {
			$body .= '<tr><th style="text-align:left;border-bottom:1px solid #e5e5e5;width:35%;">' . esc_html( $label ) . '</th><td style="border-bottom:1px solid #e5e5e5;">' . esc_html( $value ) . '</td></tr>';
		}
		$body .= '</table>';
		$body .= '<p style="margin:24px 0;"><a href="' . esc_url( $renew ) . '" style="display:inline-block;background:#1e1e1e;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600;">' . esc_html__( 'Renew your license', 'subtleforms-license-server' ) . '</a></p>';
		$body .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: support email */
				__( 'Questions? Reply to this email or write to %s.', 'subtleforms-license-server' ),
				SFLS_Settings::support_email()
			)
		) . '</p>';

		$headers = array( 'Reply-To: ' . SFLS_Settings::support_email() );

		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$mailer = WC()->mailer();
			$html   = $mailer->wrap_message( $heading, $body );
			$ok     = (bool) $mailer->send( $to, $subject, $html, $headers );
		} else {
			$html      = '<!doctype html><html><body style="margin:0;padding:24px;background:#f6f7f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1e1e1e;font-size:15px;line-height:1.5;"><div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e5e5;border-radius:8px;padding:28px;"><h1 style="font-size:20px;margin:0 0 16px;">' . esc_html( $heading ) . '</h1>' . $body . '<p style="color:#757575;font-size:13px;margin-top:24px;">' . esc_html( $site ) . '</p></div></body></html>';
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			$ok        = wp_mail( $to, $subject, $html, $headers );
		}

		do_action( 'sfls_reminder_sent', $lic, $stage, $ok );
		return $ok;
	}
}
