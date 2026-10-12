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
 * second trial.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trial claims and checks.
 */
final class SFLS_Trials {

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
	 * Whether a claim exists.
	 *
	 * @param string $kind              email | domain | site.
	 * @param string $value             Normalised value.
	 * @param int    $exclude_license   License whose own claims do not count.
	 */
	private static function claimed( string $kind, string $value, int $exclude_license = 0 ): bool {
		global $wpdb;
		if ( '' === $value ) {
			return false;
		}
		if ( $exclude_license > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sfls_trial_claims WHERE kind = %s AND value = %s AND license_id <> %d LIMIT 1", $kind, $value, $exclude_license ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sfls_trial_claims WHERE kind = %s AND value = %s LIMIT 1", $kind, $value ) );
	}

	/**
	 * Record a claim once.
	 *
	 * @param string $kind       email | domain | site.
	 * @param string $value      Normalised value.
	 * @param int    $license_id License.
	 */
	private static function claim( string $kind, string $value, int $license_id ): void {
		global $wpdb;
		if ( '' === $value ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sfls_trial_claims WHERE kind = %s AND value = %s AND license_id = %d LIMIT 1", $kind, $value, $license_id ) );
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
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Remember that a trial started for this email (and its company domain).
	 *
	 * @param int    $license_id License.
	 * @param string $email      Customer email.
	 */
	public static function record_start( int $license_id, string $email ): void {
		self::claim( 'email', self::normalize_email( $email ), $license_id );
		self::claim( 'domain', self::company_domain( $email ), $license_id );
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
	 * @return array{eligible:bool,reason:string,message:string}
	 */
	public static function check( string $email, string $site = '', int $exclude_license = 0 ): array {
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
		} elseif ( self::claimed( 'email', $normal, $exclude_license ) ) {
			$result = array(
				'eligible' => false,
				'reason'   => 'email_used',
				'message'  => __( 'This email address already had a free trial.', 'subtleforms-license-server' ),
			);
		} elseif ( self::claimed( 'domain', self::company_domain( $email ), $exclude_license ) ) {
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
