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
		add_action( self::HOOK, array( 'SFLS_Releases', 'purge_tokens' ) );
		add_action( self::HOOK, array( 'SFLS_DB', 'prune' ) );
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
	 * Whether an email with this exact marker ("paid|txn_123") was sent.
	 *
	 * @param array  $lic    License.
	 * @param string $marker Marker.
	 */
	public static function has_marker( array $lic, string $marker ): bool {
		$fresh = isset( $lic['id'] ) ? SFLS_Licenses::find( (int) $lic['id'] ) : null;
		return in_array( $marker, self::markers( $fresh ?? $lic ), true );
	}

	/**
	 * Remember an exact marker on a license (the last 20 are kept).
	 *
	 * @param array  $lic    License.
	 * @param string $marker Marker.
	 */
	public static function add_marker( array $lic, string $marker ): void {
		if ( empty( $lic['id'] ) ) {
			return;
		}
		$fresh   = SFLS_Licenses::find( (int) $lic['id'] ) ?? $lic;
		$list    = self::markers( $fresh );
		$list[]  = $marker;
		$list    = array_slice( array_values( array_unique( $list ) ), -20 );
		SFLS_Licenses::update( (int) $lic['id'], array( 'reminders' => wp_json_encode( $list ) ) );
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
			$from = gmdate( 'Y-m-d H:i:s', $now );
			$to   = gmdate( 'Y-m-d H:i:s', $now + max( $days ) * DAY_IN_SECONDS );
			foreach ( self::pages( static fn( int $after ) => SFLS_Licenses::expiring_between( $from, $to, $after ) ) as $lic ) {
				if ( SFLS_Licenses::is_paddle( $lic ) ) {
					continue; // Paddle renews by itself; see the trial and renewal notices below.
				}
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

		$sent += self::run_paddle_notices( $now, $dry_run );

		if ( SFLS_Settings::get( 'lapsed_reminder' ) ) {
			$from = gmdate( 'Y-m-d H:i:s', $now - 8 * DAY_IN_SECONDS );
			$to   = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
			foreach ( self::pages( static fn( int $after ) => SFLS_Licenses::expiring_between( $from, $to, $after ) ) as $lic ) {
				if ( self::was_sent( $lic, 'lapsed' ) ) {
					continue;
				}
				if ( ! empty( $lic['trial'] ) && 'canceled' === $lic['billing_status'] ) {
					continue; // A canceled trial was already told it ended.
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
	 * Paddle notices: the trial ends in a few days (default 3), and the
	 * yearly renewal is coming up (default 14 and 3 days before). Each goes
	 * out once per date, marked as "trial|<trial end>" or "r<days>|<renewal>".
	 *
	 * @param int  $now     Current time.
	 * @param bool $dry_run Only count.
	 */
	private static function run_paddle_notices( int $now, bool $dry_run ): int {
		$sent  = 0;
		$trial = SFLS_Settings::trial_notice_days();
		if ( $trial > 0 ) {
			$from = gmdate( 'Y-m-d H:i:s', $now );
			$to   = gmdate( 'Y-m-d H:i:s', $now + $trial * DAY_IN_SECONDS );
			foreach ( self::pages( static fn( int $after ) => SFLS_Licenses::paddle_due( 'trial_ends_at', $from, $to, $after ) ) as $lic ) {
				$marker = 'trial|' . $lic['trial_ends_at'];
				if ( self::has_marker( $lic, $marker ) ) {
					continue;
				}
				if ( ! $dry_run ) {
					SFLS_Billing_Emails::send_once( $lic, 'trial_ending', $marker );
				}
				++$sent;
			}
		}

		$days = SFLS_Settings::renewal_notice_days();
		if ( $days ) {
			$from = gmdate( 'Y-m-d H:i:s', $now );
			$to   = gmdate( 'Y-m-d H:i:s', $now + max( $days ) * DAY_IN_SECONDS );
			foreach ( self::pages( static fn( int $after ) => SFLS_Licenses::paddle_due( 'renews_at', $from, $to, $after ) ) as $lic ) {
				$left       = (int) ceil( ( strtotime( $lic['renews_at'] . ' UTC' ) - $now ) / DAY_IN_SECONDS );
				$applicable = array_values( array_filter( $days, static fn( $n ) => $left <= $n ) );
				if ( ! $applicable ) {
					continue;
				}
				$marker = 'r' . min( $applicable ) . '|' . $lic['renews_at'];
				if ( self::has_marker( $lic, $marker ) ) {
					continue;
				}
				if ( ! $dry_run ) {
					SFLS_Billing_Emails::send_once( $lic, 'renewal_notice', $marker );
					foreach ( $applicable as $n ) {
						self::add_marker( $lic, 'r' . $n . '|' . $lic['renews_at'] );
					}
				}
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Every row of a paged license query (LS-17: no cap of 500 per window).
	 *
	 * @param callable $fetch Returns the page after a license ID.
	 * @return Generator<array>
	 */
	private static function pages( callable $fetch ): Generator {
		$after = 0;
		do {
			$rows = (array) $fetch( $after );
			$full = count( $rows ) >= SFLS_Licenses::PAGE;
			foreach ( $rows as $row ) {
				yield $row;
			}
			$after = $rows ? (int) end( $rows )['id'] : 0;
		} while ( $full && $after > 0 );
	}

	/**
	 * Recipient address and first name for a license.
	 *
	 * @param array $lic License.
	 * @return array{0:string,1:string}
	 */
	public static function recipient( array $lic ): array {
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
	 * @param string $to    Recipient override (test sends).
	 * @param string $name  First name override.
	 * @param bool   $test  Mark the email as a test.
	 */
	public static function send( array $lic, string $stage, int $left, string $to = '', string $name = '', bool $test = false ): bool {
		if ( '' === $to ) {
			[ $to, $name ] = self::recipient( $lic );
		}
		if ( '' === $to ) {
			return false;
		}

		$plan  = SFLS_Plans::label( $lic['plan'] );
		$renew = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'licenses' ) : home_url( '/' );

		$paddle = SFLS_Licenses::is_paddle( $lic );
		if ( 'lapsed' === $stage && $paddle ) {
			$subject = __( 'Your SubtleForms Pro license has ended', 'subtleforms-license-server' );
			$heading = __( 'Your license has ended', 'subtleforms-license-server' );
			$intro   = ! empty( $lic['trial'] )
				? __( 'Your SubtleForms Pro free trial has ended and nothing was charged. Pro features and updates have stopped on your sites. Your forms and entries are safe. Pick a plan any time to turn Pro back on.', 'subtleforms-license-server' )
				: __( 'Your SubtleForms Pro subscription has ended, so Pro features, updates and support stop on your sites. Your forms and entries are safe. Subscribe again any time to turn Pro back on with the same key.', 'subtleforms-license-server' );
		} elseif ( 'lapsed' === $stage ) {
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
		$body .= self::button( $paddle ? SFLS_Settings::pricing_url() : $renew, $paddle ? __( 'See plans', 'subtleforms-license-server' ) : __( 'Renew your license', 'subtleforms-license-server' ) );
		$body .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: support email */
				__( 'Questions? Reply to this email or write to %s.', 'subtleforms-license-server' ),
				SFLS_Settings::support_email()
			)
		) . '</p>';

		$ok = self::deliver( $to, $subject, $heading, $body, $test ? __( 'This is a test of the renewal reminder. The license shown is an example and no customer received this email.', 'subtleforms-license-server' ) : '' );

		if ( ! $test ) {
			do_action( 'sfls_reminder_sent', $lic, $stage, $ok );
		}
		return $ok;
	}

	/**
	 * Email button HTML.
	 *
	 * @param string $url   Link.
	 * @param string $label Label.
	 */
	public static function button( string $url, string $label ): string {
		return '<p style="margin:24px 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1e1e1e;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600;">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Send one license email: the WooCommerce email template when available,
	 * a plain HTML layout otherwise, with the support address as Reply-To
	 * (and From when it is on this site's domain).
	 *
	 * @param string $to        Recipient.
	 * @param string $subject   Subject.
	 * @param string $heading   Heading.
	 * @param string $body      Body HTML (already escaped).
	 * @param string $test_note When set, the email is a test: "[Test]" subject and this note on top.
	 */
	public static function deliver( string $to, string $subject, string $heading, string $body, string $test_note = '' ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}
		if ( '' !== $test_note ) {
			/* translators: %s: email subject */
			$subject = sprintf( __( '[Test] %s', 'subtleforms-license-server' ), $subject );
			$body    = '<p style="padding:10px 12px;background:#fcf9e8;border:1px solid #f0e6b4;border-radius:4px;">' . esc_html( $test_note ) . '</p>' . $body;
		}

		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$support   = SFLS_Settings::support_email();
		$site_name = str_replace( array( '"', "\r", "\n" ), '', $site );
		$headers   = array( 'Reply-To: "' . $site_name . '" <' . $support . '>' );

		return (bool) SFLS_Delivery::as_license_mail(
			static function () use ( $to, $subject, $heading, $body, $headers, $site, $site_name, $support ) {
				if ( function_exists( 'WC' ) && WC()->mailer() ) {
					$mailer = WC()->mailer();
					$html   = $mailer->wrap_message( $heading, $body );
					return (bool) $mailer->send( $to, $subject, $html, $headers );
				}
				$html      = '<!doctype html><html><body style="margin:0;padding:24px;background:#f6f7f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1e1e1e;font-size:15px;line-height:1.5;"><div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e5e5;border-radius:8px;padding:28px;"><h1 style="font-size:20px;margin:0 0 16px;">' . esc_html( $heading ) . '</h1>' . $body . '<p style="color:#757575;font-size:13px;margin-top:24px;">' . esc_html( $site ) . '</p></div></body></html>';
				$headers[] = 'Content-Type: text/html; charset=UTF-8';
				if ( SFLS_Settings::support_email_on_site_domain() ) {
					$headers[] = 'From: "' . $site_name . '" <' . $support . '>';
				}
				return wp_mail( $to, $subject, $html, $headers );
			}
		);
	}

	/**
	 * Send an example reminder to an admin, without touching any license.
	 *
	 * @param string $to    Recipient.
	 * @param string $stage before | lapsed.
	 */
	public static function send_test( string $to, string $stage = 'before' ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$days = SFLS_Settings::reminder_days();
		$left = 'lapsed' === $stage ? 0 : ( $days ? (int) min( $days ) : 7 );
		$user = wp_get_current_user();
		$lic  = array(
			'id'             => 0,
			'license_key'    => 'SFP-EXAMPLE0-00000000-00000000-12345678',
			'plan'           => 'pro',
			'status'         => 'active',
			'sites_allowed'  => (int) ( SFLS_Plans::get( 'pro' )['sites'] ?? 5 ),
			'customer_email' => $to,
			'user_id'        => 0,
			'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + ( 'lapsed' === $stage ? -DAY_IN_SECONDS : $left * DAY_IN_SECONDS ) ),
		);
		$name = $user->ID ? ( $user->first_name ? $user->first_name : $user->display_name ) : '';
		return self::send( $lic, $stage, $left, $to, (string) $name, true );
	}
}
