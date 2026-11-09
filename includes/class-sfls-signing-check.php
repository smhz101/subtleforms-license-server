<?php
/**
 * Signing key self-test (1.9.2).
 *
 * Pro refuses license answers that carry no valid signature, so a missing or
 * changed SFLS_SIGNING_SECRET_KEY would turn every site unlicensed once the
 * grace period runs out. This check signs a sample answer with the configured
 * key and verifies it with the public key Pro ships (SFLS_SIGNING_PUBLIC_KEY
 * when it is defined, else the one derived from the secret key).
 *
 * It runs from the daily `sfls_daily` event, when a Licenses screen loads and
 * when a signed route has to refuse because the key is missing. On failure it
 * shows an error notice on the admin screens and emails the admin address,
 * once a day while the check keeps failing. When it passes again the notice
 * goes away and one "recovered" email is sent.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily signing key check with admin notice and email.
 */
final class SFLS_Signing_Check {

	/**
	 * Option with the last result: ok, code, message, fix, checked_at,
	 * failing_since, last_mail.
	 */
	const OPTION = 'sfls_signing_check';

	/**
	 * Seconds between two failure emails.
	 */
	const MAIL_EVERY = DAY_IN_SECONDS;

	/**
	 * Seconds between two writes of an unchanged result (screen loads).
	 */
	const SAVE_EVERY = 60;

	/**
	 * Result of this request, so a screen that asks twice checks once.
	 *
	 * @var array|null
	 */
	private static ?array $current = null;

	/**
	 * Hook in. Called once the other classes are loaded.
	 */
	public static function register(): void {
		add_action( SFLS_Reminders::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'on_admin_load' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Check the key without storing or sending anything.
	 *
	 * @return array{ok:bool,code:string,message:string,fix:string}
	 */
	public static function diagnose(): array {
		if ( ! SFLS_Signing::sodium() ) {
			return self::failure(
				'sodium_missing',
				__( 'This PHP has no sodium (ed25519) support, so the server cannot sign its answers.', 'subtleforms-license-server' ),
				__( 'Enable the sodium extension in PHP, or ask the host to.', 'subtleforms-license-server' )
			);
		}
		switch ( SFLS_Signing::key_problem() ) {
			case 'missing':
				return self::failure(
					'key_missing',
					__( 'SFLS_SIGNING_SECRET_KEY is not defined, so the server cannot sign its answers and the signed routes answer 503.', 'subtleforms-license-server' ),
					__( 'Put the original SFLS_SIGNING_SECRET_KEY line back into wp-config.php. Pro sites keep their last known license state until the server signs again.', 'subtleforms-license-server' )
				);
			case 'malformed':
				return self::failure(
					'key_malformed',
					__( 'SFLS_SIGNING_SECRET_KEY is not a base64 ed25519 secret key (64 bytes), so the server cannot sign its answers and the signed routes answer 503.', 'subtleforms-license-server' ),
					__( 'Restore the original value in wp-config.php. Check that nothing was cut off or added around it.', 'subtleforms-license-server' )
				);
		}

		$sample    = array(
			'valid'     => true,
			'status'    => 'active',
			'signed_at' => time(),
			'sig_nonce' => bin2hex( random_bytes( 8 ) ),
		);
		$message   = SFLS_Signing::response_message( '/sfls/v1/license/validate', $sample );
		$signature = SFLS_Signing::sign( $message );
		$derived   = SFLS_Signing::public_key();
		if ( '' === $signature || '' === $derived || ! SFLS_Signing::verify( $message, $signature, $derived ) ) {
			return self::failure(
				'verify_failed',
				__( 'A sample answer signed with SFLS_SIGNING_SECRET_KEY does not verify, so Pro sites would refuse every answer.', 'subtleforms-license-server' ),
				__( 'The secret key value is damaged. Restore the original value in wp-config.php.', 'subtleforms-license-server' )
			);
		}
		$shipped = SFLS_Signing::shipped_public_key();
		if ( null !== $shipped && ! SFLS_Signing::verify( $message, $signature, $shipped ) ) {
			return self::failure(
				'public_key_mismatch',
				__( 'SFLS_SIGNING_SECRET_KEY does not belong to the public key Pro ships (SFLS_SIGNING_PUBLIC_KEY), so Pro sites would refuse every answer.', 'subtleforms-license-server' ),
				__( 'The secret key was changed. Put the original key back into wp-config.php. If the key was replaced on purpose, release a Pro version that ships the new public key first, then update SFLS_SIGNING_PUBLIC_KEY.', 'subtleforms-license-server' )
			);
		}
		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
			'fix'     => '',
		);
	}

	/**
	 * A failed result.
	 *
	 * @param string $code    Problem code.
	 * @param string $message What is wrong.
	 * @param string $fix     What to do.
	 * @return array{ok:bool,code:string,message:string,fix:string}
	 */
	private static function failure( string $code, string $message, string $fix ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'fix'     => $fix,
		);
	}

	/**
	 * The stored result of the last check.
	 *
	 * @return array{ok:bool,code:string,message:string,fix:string,checked_at:int,failing_since:int,last_mail:int}
	 */
	public static function state(): array {
		$state = (array) get_option( self::OPTION, array() );
		return array(
			'ok'            => ! isset( $state['ok'] ) || (bool) $state['ok'],
			'code'          => (string) ( $state['code'] ?? '' ),
			'message'       => (string) ( $state['message'] ?? '' ),
			'fix'           => (string) ( $state['fix'] ?? '' ),
			'checked_at'    => (int) ( $state['checked_at'] ?? 0 ),
			'failing_since' => (int) ( $state['failing_since'] ?? 0 ),
			'last_mail'     => (int) ( $state['last_mail'] ?? 0 ),
		);
	}

	/**
	 * Check the key, store the result, and send the failure or recovery email
	 * when it is due. Returns the stored state.
	 *
	 * @return array{ok:bool,code:string,message:string,fix:string,checked_at:int,failing_since:int,last_mail:int}
	 */
	public static function run(): array {
		$now    = time();
		$result = self::diagnose();
		$old    = self::state();
		$new    = array_merge(
			$result,
			array(
				'checked_at'    => $now,
				'failing_since' => 0,
				'last_mail'     => 0,
			)
		);
		$dirty  = $old['ok'] !== $result['ok'] || $old['code'] !== $result['code'] || $now - $old['checked_at'] >= self::SAVE_EVERY;

		if ( ! $result['ok'] ) {
			$new['failing_since'] = ! $old['ok'] && $old['failing_since'] > 0 ? $old['failing_since'] : $now;
			$new['last_mail']     = $old['ok'] ? 0 : $old['last_mail'];
			if ( $now - $new['last_mail'] >= self::MAIL_EVERY ) {
				$new['last_mail'] = $now;
				self::mail_failure( $new );
				$dirty = true;
			}
		} elseif ( ! $old['ok'] ) {
			self::mail_recovery( $old, $now );
			$dirty = true;
		}

		if ( $dirty ) {
			update_option( self::OPTION, $new, false );
		}
		self::$current = $new;
		return $new;
	}

	/**
	 * Daily event and screen loads share this: the result of this request.
	 */
	public static function current(): array {
		return null !== self::$current ? self::$current : self::run();
	}

	/**
	 * Check when a Licenses screen loads, so the owner sees a problem at once.
	 */
	public static function on_admin_load(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen name.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 === strpos( $page, 'sfls' ) && current_user_can( SFLS_Admin::CAP ) ) {
			self::current();
		}
	}

	/**
	 * Persistent error notice on the admin screens while the check fails.
	 */
	public static function notice(): void {
		if ( ! current_user_can( SFLS_Admin::CAP ) ) {
			return;
		}
		$state = null !== self::$current ? self::$current : self::state();
		if ( $state['ok'] ) {
			return;
		}
		echo '<div class="notice notice-error sfls-signing-notice"><p><strong>' . esc_html__( 'SubtleForms license server: signing key check failed.', 'subtleforms-license-server' ) . '</strong> ' . esc_html( $state['message'] ) . '</p><p>' . esc_html( $state['fix'] ) . '</p></div>';
	}

	/**
	 * One line for status screens: "Signing: OK, checked <time>" or the problem.
	 *
	 * @param array|null $state State from run() or state(), null for the stored one.
	 */
	public static function summary( ?array $state = null ): string {
		$state = null === $state ? self::state() : $state;
		$time  = $state['checked_at'] > 0 ? gmdate( 'Y-m-d H:i', $state['checked_at'] ) . ' UTC' : __( 'never', 'subtleforms-license-server' );
		if ( $state['ok'] ) {
			/* translators: %s: date and time of the last check */
			return sprintf( __( 'Signing: OK, checked %s', 'subtleforms-license-server' ), $time );
		}
		/* translators: 1: what is wrong, 2: date and time of the last check */
		return sprintf( __( 'Signing: FAILED, %1$s Checked %2$s', 'subtleforms-license-server' ), $state['message'], $time );
	}

	/**
	 * Email the failure to the admin address.
	 *
	 * @param array $state New state.
	 */
	private static function mail_failure( array $state ): void {
		$body = array(
			__( 'The signing key check on the SubtleForms license server failed.', 'subtleforms-license-server' ),
			'',
			$state['message'],
			'',
			__( 'What to do:', 'subtleforms-license-server' ) . ' ' . $state['fix'],
			'',
			/* translators: %s: date and time the problem was first seen */
			sprintf( __( 'First seen: %s', 'subtleforms-license-server' ), gmdate( 'Y-m-d H:i', $state['failing_since'] ) . ' UTC' ),
			__( 'You get this email once a day while the problem lasts.', 'subtleforms-license-server' ),
		);
		wp_mail( (string) get_option( 'admin_email' ), 'SubtleForms license server: signing key check failed', implode( "\n", $body ) );
	}

	/**
	 * Email that the check passes again.
	 *
	 * @param array $old Stored state while it was failing.
	 * @param int   $now Time of the passing check.
	 */
	private static function mail_recovery( array $old, int $now ): void {
		$body = array(
			__( 'The signing key check on the SubtleForms license server passes again.', 'subtleforms-license-server' ),
			'',
			/* translators: %s: date and time the problem was first seen */
			sprintf( __( 'The problem was first seen: %s', 'subtleforms-license-server' ), gmdate( 'Y-m-d H:i', max( 0, $old['failing_since'] ) ) . ' UTC' ),
			/* translators: %s: date and time of the passing check */
			sprintf( __( 'Fixed as of: %s', 'subtleforms-license-server' ), gmdate( 'Y-m-d H:i', $now ) . ' UTC' ),
		);
		wp_mail( (string) get_option( 'admin_email' ), 'SubtleForms license server: signing key check recovered', implode( "\n", $body ) );
	}
}
