<?php
/**
 * Claiming licenses that are not linked to an account yet.
 *
 * Licenses belong to a WordPress user ID. A license bought as a guest (or
 * issued by hand for an email address) has no user ID until its buyer proves
 * the address is theirs: when someone signs in to, or creates, an account
 * whose email matches unclaimed licenses, a one-time link goes to that
 * address. Opening it while signed in to that same account links the
 * licenses. Changing an account email never moves a license, because an
 * account email can be changed without any confirmation.
 *
 * The signed guest download link (SFLS_Guest) is the other way to claim.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verified email claims.
 */
final class SFLS_Claims {

	/**
	 * User meta with the pending claim: hash of the token, email, expiry.
	 */
	const META = '_sfls_claim';

	/**
	 * User meta with the time the last claim email was sent.
	 */
	const SENT_META = '_sfls_claim_sent';

	/**
	 * How long a claim link works.
	 */
	const TTL = 2 * DAY_IN_SECONDS;

	/**
	 * Minimum time between two claim emails asked for by the customer.
	 */
	const RESEND_AFTER = 10 * MINUTE_IN_SECONDS;

	/**
	 * Minimum time between two claim emails sent automatically at sign-in.
	 */
	const AUTO_AFTER = DAY_IN_SECONDS;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp_login', array( self::class, 'on_login' ), 20, 2 );
		add_action( 'user_register', array( self::class, 'on_register' ), 20 );
		add_action( 'template_redirect', array( self::class, 'handle' ), 4 );
	}

	/**
	 * Unclaimed licenses bought with an email address.
	 *
	 * @param string $email Email.
	 * @return array[]
	 */
	public static function unclaimed( string $email ): array {
		global $wpdb;
		$email = trim( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE user_id = 0 AND customer_email <> '' AND LOWER(customer_email) = LOWER(%s) ORDER BY id ASC", $email ), ARRAY_A );
	}

	/**
	 * Sign-in: offer the claim by email.
	 *
	 * @param string  $login User login.
	 * @param WP_User $user  User.
	 */
	public static function on_login( $login, $user = null ): void {
		unset( $login );
		if ( $user instanceof WP_User ) {
			self::send( $user, false );
		}
	}

	/**
	 * New account: offer the claim by email.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_register( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof WP_User ) {
			self::send( $user, false );
		}
	}

	/**
	 * Email a one-time claim link to the account's address when licenses
	 * bought with that address are unclaimed. Returns whether it was sent.
	 *
	 * @param WP_User $user   Account.
	 * @param bool    $manual Asked for by the customer (shorter wait between emails).
	 */
	public static function send( WP_User $user, bool $manual = true ): bool {
		$email    = (string) $user->user_email;
		$licenses = self::unclaimed( $email );
		if ( ! $licenses ) {
			return false;
		}
		$last = (int) get_user_meta( $user->ID, self::SENT_META, true );
		if ( $last && $last > time() - ( $manual ? self::RESEND_AFTER : self::AUTO_AFTER ) ) {
			return false;
		}

		$token = bin2hex( random_bytes( 32 ) );
		update_user_meta(
			$user->ID,
			self::META,
			array(
				'hash'    => hash( 'sha256', $token ),
				'email'   => strtolower( $email ),
				'expires' => time() + self::TTL,
			)
		);
		update_user_meta( $user->ID, self::SENT_META, time() );

		$count = count( $licenses );
		$url   = self::url( (int) $user->ID, $token );
		$body  = '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of licenses, 2: email address */
				_n( 'We found %1$d SubtleForms Pro license bought with %2$s that is not in your account yet.', 'We found %1$d SubtleForms Pro licenses bought with %2$s that are not in your account yet.', $count, 'subtleforms-license-server' ),
				$count,
				$email
			)
		) . '</p>';
		$body .= '<p>' . esc_html__( 'Confirm that this address is yours to add them to your account. Open the link while you are signed in to the same account. It works once, within 48 hours.', 'subtleforms-license-server' ) . '</p>';
		$body .= SFLS_Reminders::button( $url, __( 'Add the licenses to my account', 'subtleforms-license-server' ) );
		$body .= '<p style="color:#646970;font-size:13px;">' . esc_html__( 'If you did not sign in or ask for this, ignore this email. Nothing changes until the link is opened.', 'subtleforms-license-server' ) . '</p>';

		return SFLS_Reminders::deliver(
			$email,
			__( 'Confirm your email to add your SubtleForms Pro licenses', 'subtleforms-license-server' ),
			__( 'Add your licenses to your account', 'subtleforms-license-server' ),
			$body
		);
	}

	/**
	 * Claim link.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Raw token.
	 */
	public static function url( int $user_id, string $token ): string {
		return add_query_arg(
			array(
				'sfls_claim' => $token,
				'u'          => $user_id,
			),
			SFLS_Licenses::manage_url()
		);
	}

	/**
	 * Use a claim token for the signed-in account: links every unclaimed
	 * license bought with the confirmed address.
	 *
	 * @param WP_User $user  Signed-in account.
	 * @param int     $for   Account the link was sent to.
	 * @param string  $token Raw token.
	 * @return int|WP_Error Number of licenses linked.
	 */
	public static function claim( WP_User $user, int $for, string $token ) {
		$fail    = new WP_Error( 'sfls_claim', __( 'This confirmation link is invalid or has expired. Ask for a new one on the Licenses page.', 'subtleforms-license-server' ) );
		$pending = get_user_meta( $user->ID, self::META, true );
		if ( ! $user->ID || $for !== (int) $user->ID || ! is_array( $pending ) || empty( $pending['hash'] ) ) {
			return $fail;
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) || ! hash_equals( (string) $pending['hash'], hash( 'sha256', $token ) ) ) {
			return $fail;
		}
		delete_user_meta( $user->ID, self::META ); // One use, whatever happens next.
		if ( (int) ( $pending['expires'] ?? 0 ) < time() ) {
			return $fail;
		}
		// The link went to the address the account had then; it must still have it.
		if ( 0 !== strcasecmp( (string) $pending['email'], (string) $user->user_email ) ) {
			return $fail;
		}
		$linked = 0;
		foreach ( self::unclaimed( (string) $user->user_email ) as $lic ) {
			if ( self::link( $lic, $user ) ) {
				++$linked;
			}
		}
		return $linked;
	}

	/**
	 * Link an unclaimed license to an account. Returns false when it already
	 * belongs to someone.
	 *
	 * @param array   $lic  License.
	 * @param WP_User $user Account.
	 */
	public static function link( array $lic, WP_User $user ): bool {
		global $wpdb;
		if ( ! $user->ID ) {
			return false;
		}
		// Only ever fills an empty owner, so a race cannot move a claimed license.
		// updated_at stays: it dates a suspension, which limits the downloads.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$done = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}sfls_licenses SET user_id = %d WHERE id = %d AND user_id = 0", (int) $user->ID, (int) $lic['id'] ) );
		if ( $done ) {
			do_action( 'sfls_license_claimed', (int) $lic['id'], (int) $user->ID );
		}
		return $done > 0;
	}

	/**
	 * Claim link and "send the confirmation email" button.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- The claim token is the credential; the send button is nonce-checked below.
		if ( isset( $_GET['sfls_claim'] ) ) {
			$token = strtolower( sanitize_text_field( wp_unslash( $_GET['sfls_claim'] ) ) );
			$for   = absint( $_GET['u'] ?? 0 );
			// phpcs:enable
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( SFLS_Connect::login_url( self::url( $for, $token ) ) );
				exit;
			}
			$result = self::claim( wp_get_current_user(), $for, $token );
			if ( is_wp_error( $result ) ) {
				self::notice( $result->get_error_message(), 'error' );
			} elseif ( $result > 0 ) {
				/* translators: %d: number of licenses */
				self::notice( sprintf( _n( '%d license was added to your account.', '%d licenses were added to your account.', $result, 'subtleforms-license-server' ), $result ) );
			} else {
				self::notice( __( 'There were no licenses left to add.', 'subtleforms-license-server' ), 'notice' );
			}
			wp_safe_redirect( SFLS_Licenses::manage_url() );
			exit;
		}
		if ( isset( $_POST['sfls_claim_send'] ) && is_user_logged_in() ) {
			check_admin_referer( 'sfls_claim_send' );
			if ( self::send( wp_get_current_user(), true ) ) {
				self::notice( __( 'We sent a confirmation link to your email address. Open it while signed in to add the licenses.', 'subtleforms-license-server' ) );
			} else {
				self::notice( __( 'A confirmation link was sent a few minutes ago. Check your inbox and spam folder, or try again later.', 'subtleforms-license-server' ), 'notice' );
			}
			wp_safe_redirect( SFLS_Licenses::manage_url() );
			exit;
		}
	}

	/**
	 * Queue a notice for the next page.
	 *
	 * @param string $message Message.
	 * @param string $type    success | notice | error.
	 */
	private static function notice( string $message, string $type = 'success' ): void {
		if ( function_exists( 'wc_add_notice' ) && function_exists( 'WC' ) && WC()->session ) {
			wc_add_notice( $message, $type );
		}
	}

	/**
	 * Card on My Account › Licenses when licenses bought with the account's
	 * address are waiting to be claimed ('' when there are none).
	 *
	 * @param WP_User $user Account.
	 */
	public static function card_html( WP_User $user ): string {
		$count = count( self::unclaimed( (string) $user->user_email ) );
		if ( ! $user->ID || 0 === $count ) {
			return '';
		}
		$html  = '<div class="sfls-card sfls-claim-card"><h3>' . esc_html( _n( 'A license is waiting for you', 'Licenses are waiting for you', $count, 'subtleforms-license-server' ) ) . '</h3>';
		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of licenses, 2: email address */
				_n( '%1$d license was bought with %2$s. Confirm the address to add it to this account.', '%1$d licenses were bought with %2$s. Confirm the address to add them to this account.', $count, 'subtleforms-license-server' ),
				$count,
				$user->user_email
			)
		) . '</p>';
		$html .= '<form method="post" action="' . esc_url( SFLS_Licenses::manage_url() ) . '">' . wp_nonce_field( 'sfls_claim_send', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="sfls_claim_send" value="1"><button type="submit" class="sfls-btn sfls-btn--primary sfls-btn--sm">' . esc_html__( 'Email me a confirmation link', 'subtleforms-license-server' ) . '</button></form></div>';
		return $html;
	}
}
