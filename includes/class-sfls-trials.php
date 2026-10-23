<?php
/**
 * Free trial eligibility: one trial per customer.
 *
 * A trial is refused when any of these already had one:
 *   - the email address (case-insensitive, without a "+tag", and for Gmail
 *     without dots, so me+1@gmail.com and m.e@gmail.com count as me@gmail.com);
 *   - the company email domain (anyone @acme.com counts as acme.com), except
 *     on free mail domains (gmail.com, outlook.com, yahoo.com, hotmail.com,
 *     icloud.com, proton.me and the rest of SFLS_Settings::free_mail_domains()),
 *     where only the exact address counts;
 *   - the site: a domain that was activated on a trial license cannot be
 *     activated on another trial license (local and staging hosts excepted).
 *
 * Claims are kept in wp_sfls_trial_claims (kind email | domain | site) and
 * stay when a license is deleted, so deleting a license never hands out a
 * second trial. A claim is written as "pending" when the trial order is
 * created (reserve(), inside a transaction and a lock, so parallel checkouts
 * with the same email cannot both pass), becomes final when the payment
 * issues the license (record_start()) and is released when the order fails or
 * is cancelled (release_order()) or the checkout is left for PENDING_MINUTES.
 *
 * Before the Paddle checkout of a trial the customer confirms the email with
 * a one-time 6 digit code: valid CODE_MINUTES, at most CODE_ATTEMPTS tries,
 * stored hashed (issue_code(), verify_code(), is_verified()).
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trial claims and checks.
 */
final class SFLS_Trials {

	/**
	 * Minutes a pending claim of an unpaid checkout blocks other checkouts.
	 */
	const PENDING_MINUTES = 30;

	/**
	 * Minutes a confirmation code is valid, tries it allows, minutes an
	 * address stays confirmed, and seconds before a new code may be sent.
	 */
	const CODE_MINUTES     = 15;
	const CODE_ATTEMPTS    = 5;
	const VERIFIED_MINUTES = 60;
	const CODE_RESEND      = 30;

	/**
	 * Normalised email for trial claims ('' when invalid).
	 *
	 * @param string $email Email address.
	 */
	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return '';
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		$local              = (string) preg_replace( '/\+.*$/', '', $local );
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}
		return '' === $local ? '' : $local . '@' . $domain;
	}

	/**
	 * Company domain of an email, '' for free mail addresses.
	 *
	 * @param string $email Email address.
	 */
	public static function company_domain( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) || SFLS_Settings::is_free_mail( $email ) ) {
			return '';
		}
		return (string) substr( strrchr( $email, '@' ), 1 );
	}

	/**
	 * Whether a claim exists. Pending claims of checkouts older than
	 * PENDING_MINUTES do not count.
	 *
	 * @param string $kind            email | domain | site.
	 * @param string $value           Normalised value.
	 * @param int    $exclude_license License whose own claims do not count.
	 * @param int    $exclude_order   Order whose own pending claims do not count.
	 */
	private static function claimed( string $kind, string $value, int $exclude_license = 0, int $exclude_order = 0 ): bool {
		global $wpdb;
		if ( '' === $value ) {
			return false;
		}
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::PENDING_MINUTES * MINUTE_IN_SECONDS );
		$sql   = "SELECT id FROM {$wpdb->prefix}sfls_trial_claims WHERE kind = %s AND value = %s AND ( status <> 'pending' OR created_at > %s )";
		$args  = array( $kind, $value, $stale );
		if ( $exclude_license > 0 ) {
			$sql   .= ' AND license_id <> %d';
			$args[] = $exclude_license;
		}
		if ( $exclude_order > 0 ) {
			$sql   .= ' AND order_id <> %d';
			$args[] = $exclude_order;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed fragments, values prepared.
		return (bool) $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $args ) );
	}

	/**
	 * Record a claim once.
	 *
	 * @param string $kind       email | domain | site.
	 * @param string $value      Normalised value.
	 * @param int    $license_id License.
	 * @param int    $order_id   Order of a pending claim.
	 * @param string $status     active | pending.
	 */
	private static function claim( string $kind, string $value, int $license_id, int $order_id = 0, string $status = 'active' ): void {
		global $wpdb;
		if ( '' === $value ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sfls_trial_claims WHERE kind = %s AND value = %s AND license_id = %d AND ( %d = 0 OR order_id = %d ) LIMIT 1", $kind, $value, $license_id, $license_id > 0 ? 0 : $order_id, $order_id ) );
		if ( $exists ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			SFLS_DB::trial_claims(),
			array(
				'kind'       => $kind,
				'value'      => substr( $value, 0, 190 ),
				'license_id' => $license_id,
				'order_id'   => $order_id,
				'status'     => $status,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Remember that a trial started for this email (and its company domain).
	 * Pending claims of the license's order become final.
	 *
	 * @param int    $license_id License.
	 * @param string $email      Customer email.
	 */
	public static function record_start( int $license_id, string $email ): void {
		global $wpdb;
		$lic = SFLS_Licenses::find( $license_id );
		if ( $lic && (int) $lic['order_id'] > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				SFLS_DB::trial_claims(),
				array(
					'license_id' => $license_id,
					'status'     => 'active',
				),
				array(
					'order_id' => (int) $lic['order_id'],
					'status'   => 'pending',
				)
			);
		}
		self::claim( 'email', self::normalize_email( $email ), $license_id );
		self::claim( 'domain', self::company_domain( $email ), $license_id );
	}

	/**
	 * Reserve the trial of an email for a new order, before any payment: check
	 * the rules and write pending claims in one transaction, under a lock per
	 * email, so two checkouts that start together cannot both pass. The
	 * reservation ends with record_start() (paid) or release_order() (failed,
	 * canceled).
	 *
	 * @param string $email    Customer email.
	 * @param int    $order_id Order.
	 * @return array{eligible:bool,reason:string,message:string}
	 */
	public static function reserve( string $email, int $order_id ): array {
		global $wpdb;
		$normal = self::normalize_email( $email );
		$lock   = 'sfls_trial_lock_' . md5( '' === $normal ? $email : $normal );
		if ( ! self::lock( $lock ) ) {
			return array(
				'eligible' => false,
				'reason'   => 'busy',
				'message'  => __( 'Your free trial is being set up in another window. Wait a moment and try again.', 'subtleforms-license-server' ),
			);
		}
		try {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'START TRANSACTION' );
			$check = self::check( $email, '', 0, $order_id );
			if ( $check['eligible'] ) {
				self::claim( 'email', $normal, 0, $order_id, 'pending' );
				self::claim( 'domain', self::company_domain( $email ), 0, $order_id, 'pending' );
				$wpdb->query( 'COMMIT' );
			} else {
				$wpdb->query( 'ROLLBACK' );
			}
			// phpcs:enable
			return $check;
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Release the pending claims of an order whose payment failed or that was
	 * canceled, so the customer can start the trial again.
	 *
	 * @param int $order_id Order.
	 */
	public static function release_order( int $order_id ): void {
		global $wpdb;
		if ( $order_id <= 0 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			SFLS_DB::trial_claims(),
			array(
				'order_id' => $order_id,
				'status'   => 'pending',
			)
		);
	}

	/**
	 * Take a short lock (an option row, so taking it is one atomic INSERT).
	 *
	 * @param string $name Option name.
	 */
	private static function lock( string $name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $name, (string) time() ) );
		if ( ! $got ) {
			$since = (int) get_option( $name );
			if ( $since && $since > time() - 30 ) {
				return false;
			}
			update_option( $name, (string) time(), false );
		}
		return true;
	}

	/**
	 * Remember that a site was activated on a trial license.
	 *
	 * @param int    $license_id License.
	 * @param string $domain     Normalised domain.
	 */
	public static function record_site( int $license_id, string $domain ): void {
		self::claim( 'site', SFLS_Licenses::normalize_domain( $domain ), $license_id );
	}

	/**
	 * Whether a site already had a trial on another license.
	 *
	 * @param string $domain     Domain.
	 * @param int    $license_id The license asking (its own claims do not count).
	 */
	public static function site_used_by_other( string $domain, int $license_id = 0 ): bool {
		$domain = SFLS_Licenses::normalize_domain( $domain );
		if ( '' === $domain || SFLS_Licenses::is_local_domain( $domain ) ) {
			return false;
		}
		return self::claimed( 'site', $domain, $license_id );
	}

	/**
	 * Can this customer start a free trial?
	 *
	 * @param string $email           Customer email.
	 * @param string $site            Optional site URL or domain the trial is for.
	 * @param int    $exclude_license License whose own claims do not count.
	 * @param int    $exclude_order   Order whose own pending claims do not count.
	 * @return array{eligible:bool,reason:string,message:string}
	 */
	public static function check( string $email, string $site = '', int $exclude_license = 0, int $exclude_order = 0 ): array {
		$result = array(
			'eligible' => true,
			'reason'   => '',
			'message'  => '',
		);
		$normal = self::normalize_email( $email );
		if ( '' === $normal ) {
			$result = array(
				'eligible' => false,
				'reason'   => 'invalid_email',
				'message'  => __( 'Enter a valid email address.', 'subtleforms-license-server' ),
			);
		} elseif ( SFLS_Settings::block_disposable() && SFLS_Settings::is_disposable( $email ) ) {
			$result = array(
				'eligible' => false,
				'reason'   => 'disposable_email',
				'message'  => __( 'Use a permanent email address for the free trial. Throw-away addresses are not accepted.', 'subtleforms-license-server' ),
			);
		} elseif ( self::claimed( 'email', $normal, $exclude_license, $exclude_order ) ) {
			$result = array(
				'eligible' => false,
				'reason'   => 'email_used',
				'message'  => __( 'This email address already had a free trial.', 'subtleforms-license-server' ),
			);
		} elseif ( self::claimed( 'domain', self::company_domain( $email ), $exclude_license, $exclude_order ) ) {
			$result = array(
				'eligible' => false,
				'reason'   => 'domain_used',
				/* translators: %s: email domain, e.g. acme.com */
				'message'  => sprintf( __( 'Someone at %s already had a free trial.', 'subtleforms-license-server' ), self::company_domain( $email ) ),
			);
		} elseif ( '' !== $site && self::site_used_by_other( $site, $exclude_license ) ) {
			$result = array(
				'eligible' => false,
				'reason'   => 'site_used',
				'message'  => __( 'This site already had a free trial.', 'subtleforms-license-server' ),
			);
		}

		/**
		 * Filter whether a customer may start a free trial.
		 *
		 * @param array  $result { eligible, reason, message }.
		 * @param string $email  Email address.
		 * @param string $site   Site, when known.
		 */
		$result = (array) apply_filters( 'sfls_trial_eligibility', $result, $email, $site );
		return array(
			'eligible' => (bool) ( $result['eligible'] ?? false ),
			'reason'   => (string) ( $result['reason'] ?? '' ),
			'message'  => (string) ( $result['message'] ?? '' ),
		);
	}

	// ── Email confirmation ──────────────────────────────────────────────

	/**
	 * Hash that identifies an address in the codes table.
	 *
	 * @param string $email Email address.
	 */
	private static function email_hash( string $email ): string {
		return hash_hmac( 'sha256', self::normalize_email( $email ), wp_salt( 'auth' ) );
	}

	/**
	 * Hash of a code. Only this is stored.
	 *
	 * @param string $email Email address.
	 * @param string $code  Six digit code.
	 */
	private static function code_hash( string $email, string $code ): string {
		return hash_hmac( 'sha256', $code, self::email_hash( $email ) . wp_salt( 'secure_auth' ) );
	}

	/**
	 * Whether the address was confirmed with a code in the last
	 * VERIFIED_MINUTES.
	 *
	 * @param string $email Email address.
	 */
	public static function is_verified( string $email ): bool {
		global $wpdb;
		if ( '' === self::normalize_email( $email ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$until = $wpdb->get_var( $wpdb->prepare( "SELECT verified_until FROM {$wpdb->prefix}sfls_trial_codes WHERE email_hash = %s", self::email_hash( $email ) ) );
		return $until && strtotime( $until . ' UTC' ) > time();
	}

	/**
	 * Email a new one-time code (a new code replaces the previous one and
	 * resets its tries).
	 *
	 * @param string $email Email address.
	 * @return true|WP_Error
	 */
	public static function issue_code( string $email ) {
		global $wpdb;
		$normal = self::normalize_email( $email );
		if ( '' === $normal ) {
			return new WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'subtleforms-license-server' ) );
		}
		$hash = self::email_hash( $email );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}sfls_trial_codes WHERE email_hash = %s", $hash ), ARRAY_A );
		if ( $row && strtotime( $row['created_at'] . ' UTC' ) > time() - self::CODE_RESEND ) {
			return new WP_Error( 'code_too_soon', __( 'A code was just sent. Check your inbox, or wait a moment to ask for another.', 'subtleforms-license-server' ) );
		}
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			SFLS_DB::trial_codes(),
			array(
				'email_hash'     => $hash,
				'code_hash'      => self::code_hash( $email, $code ),
				'attempts'       => 0,
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + self::CODE_MINUTES * MINUTE_IN_SECONDS ),
				'verified_until' => null,
				'created_at'     => current_time( 'mysql', true ),
			)
		);

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your %s confirmation code', 'subtleforms-license-server' ), $site );
		$body    = '<p>' . esc_html__( 'Use this code to confirm your email address and start your free trial:', 'subtleforms-license-server' ) . '</p>'
			. '<p style="font-size:28px;letter-spacing:6px;font-weight:700;margin:16px 0;">' . esc_html( $code ) . '</p>'
			/* translators: %d: minutes */
			. '<p>' . esc_html( sprintf( __( 'It works for %d minutes. If you did not ask for it, ignore this email.', 'subtleforms-license-server' ), self::CODE_MINUTES ) ) . '</p>';
		if ( ! SFLS_Reminders::deliver( $email, $subject, __( 'Confirm your email', 'subtleforms-license-server' ), $body ) ) {
			return new WP_Error( 'code_not_sent', __( 'We could not send the code. Check the email address and try again.', 'subtleforms-license-server' ) );
		}
		return true;
	}

	/**
	 * Check a code. Every try is counted before it is compared, so five wrong
	 * tries (at most CODE_ATTEMPTS) end the code even when they come at once.
	 *
	 * @param string $email Email address.
	 * @param string $code  Code typed by the customer.
	 * @return true|WP_Error
	 */
	public static function verify_code( string $email, string $code ) {
		global $wpdb;
		$code = preg_replace( '/\D/', '', $code );
		$hash = self::email_hash( $email );
		$now  = gmdate( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counted = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}sfls_trial_codes SET attempts = attempts + 1 WHERE email_hash = %s AND code_hash <> '' AND attempts < %d AND expires_at > %s", $hash, self::CODE_ATTEMPTS, $now ) );
		if ( ! $counted ) {
			return new WP_Error( 'code_expired', __( 'That code has expired or was tried too many times. Ask for a new one.', 'subtleforms-license-server' ) );
		}
		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT code_hash FROM {$wpdb->prefix}sfls_trial_codes WHERE email_hash = %s", $hash ) );
		if ( 6 !== strlen( $code ) || '' === $stored || ! hash_equals( $stored, self::code_hash( $email, $code ) ) ) {
			return new WP_Error( 'code_wrong', __( 'That code is not right. Check the email and try again.', 'subtleforms-license-server' ) );
		}
		$wpdb->update(
			SFLS_DB::trial_codes(),
			array(
				'code_hash'      => '',
				'verified_until' => gmdate( 'Y-m-d H:i:s', time() + self::VERIFIED_MINUTES * MINUTE_IN_SECONDS ),
			),
			array( 'email_hash' => $hash )
		);
		// phpcs:enable
		return true;
	}

	/**
	 * Claims of a license (admin screen).
	 *
	 * @param int $license_id License.
	 * @return array[]
	 */
	public static function claims_of( int $license_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT kind, value, created_at FROM {$wpdb->prefix}sfls_trial_claims WHERE license_id = %d ORDER BY id ASC", $license_id ), ARRAY_A );
	}

	/**
	 * Forget the claims of a license, so the customer may trial again.
	 *
	 * @param int $license_id License.
	 */
	public static function release( int $license_id ): void {
		global $wpdb;
		$wpdb->delete( SFLS_DB::trial_claims(), array( 'license_id' => $license_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
