<?php
/**
 * Plugin settings (Licenses › Settings).
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the sfls_settings option.
 */
final class SFLS_Settings {

	const OPTION = 'sfls_settings';

	/**
	 * Defaults.
	 */
	public static function defaults(): array {
		return array(
			'pricing_url'      => '',
			'reminder_days'    => '30,7',
			'lapsed_reminder'  => 1,
			'support_email'    => '',
			'simplify_account' => 1,
			'simple_checkout'  => 1,
			// 1.4.0: who takes the money for new licenses, and Paddle notices.
			'sell_with'        => 'woocommerce',
			'renewal_notice'   => '14,3',
			'trial_notice'     => 3,
		);
	}

	/**
	 * All settings merged with defaults.
	 */
	public static function all(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Sanitize and store settings.
	 *
	 * @param array $input Raw values (already unslashed).
	 */
	public static function save( array $input ): void {
		update_option(
			self::OPTION,
			array_merge(
				self::all(),
				array(
					'pricing_url'      => esc_url_raw( (string) ( $input['pricing_url'] ?? '' ) ),
					'reminder_days'    => self::day_list( (string) ( $input['reminder_days'] ?? '' ) ),
					'lapsed_reminder'  => empty( $input['lapsed_reminder'] ) ? 0 : 1,
					'support_email'    => sanitize_email( (string) ( $input['support_email'] ?? '' ) ),
					'simplify_account' => empty( $input['simplify_account'] ) ? 0 : 1,
					'simple_checkout'  => empty( $input['simple_checkout'] ) ? 0 : 1,
				)
			)
		);
	}

	/**
	 * Store the selling and notice settings of the Paddle tab.
	 *
	 * @param array $input Raw values (already unslashed).
	 */
	public static function save_selling( array $input ): void {
		update_option(
			self::OPTION,
			array_merge(
				self::all(),
				array(
					'sell_with'      => 'paddle' === ( $input['sell_with'] ?? '' ) ? 'paddle' : 'woocommerce',
					'renewal_notice' => self::day_list( (string) ( $input['renewal_notice'] ?? '' ) ),
					'trial_notice'   => min( 30, absint( $input['trial_notice'] ?? 3 ) ),
				)
			)
		);
	}

	/**
	 * "30, 7,x,7" → "30,7": unique positive days, largest first, at most 10.
	 *
	 * @param string $raw Comma separated days.
	 */
	public static function day_list( string $raw ): string {
		$days = array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
		rsort( $days );
		return implode( ',', array_slice( $days, 0, 10 ) );
	}

	/**
	 * Who sells new licenses: paddle or woocommerce.
	 */
	public static function sell_with(): string {
		return 'paddle' === self::get( 'sell_with' ) ? 'paddle' : 'woocommerce';
	}

	/**
	 * Days before a Paddle renewal to notify the customer, largest first.
	 *
	 * @return int[]
	 */
	public static function renewal_notice_days(): array {
		$days = array_unique( array_filter( array_map( 'absint', explode( ',', (string) self::get( 'renewal_notice' ) ) ) ) );
		rsort( $days );
		return array_values( $days );
	}

	/**
	 * Days before a free trial ends to notify the customer (0 = off).
	 */
	public static function trial_notice_days(): int {
		return absint( self::get( 'trial_notice' ) );
	}

	/**
	 * Where "See plans" links go.
	 */
	public static function pricing_url(): string {
		$url = (string) self::get( 'pricing_url' );
		return '' !== $url ? $url : home_url( '/pricing/' );
	}

	/**
	 * Reminder offsets in days, largest first.
	 *
	 * @return int[]
	 */
	public static function reminder_days(): array {
		$days = array_unique( array_filter( array_map( 'absint', explode( ',', (string) self::get( 'reminder_days' ) ) ) ) );
		rsort( $days );
		return array_values( $days );
	}

	/**
	 * Support address (falls back to the site admin email).
	 */
	public static function support_email(): string {
		$email = (string) self::get( 'support_email' );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Bare site domain without "www.", e.g. subtleforms.com.
	 */
	public static function site_domain(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * The address a fresh install should use for support: support@{site domain}.
	 * Returns '' when that is not a valid address (for example on localhost) or
	 * when it is the same as the admin email anyway.
	 */
	public static function default_support_email(): string {
		$email = 'support@' . self::site_domain();
		if ( ! is_email( $email ) || 0 === strcasecmp( $email, (string) get_option( 'admin_email' ) ) ) {
			return '';
		}
		return $email;
	}

	/**
	 * Fill in the support email on activation (or the first upgrade to 1.3.0)
	 * so customers do not see the site admin's personal address.
	 */
	public static function maybe_default_support_email(): void {
		$all = self::all();
		if ( '' !== (string) $all['support_email'] ) {
			return;
		}
		$default = self::default_support_email();
		if ( '' === $default ) {
			return;
		}
		$all['support_email'] = $default;
		update_option( self::OPTION, $all );
	}

	/**
	 * Free mail domains that should not be a business support address.
	 *
	 * @return string[]
	 */
	public static function free_mail_domains(): array {
		return (array) apply_filters(
			'sfls_free_mail_domains',
			array( 'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'ymail.com', 'aol.com', 'icloud.com', 'me.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com' )
		);
	}

	/**
	 * Whether an address is on a free mail service (gmail, outlook, yahoo, ...).
	 *
	 * @param string $email Address.
	 */
	public static function is_free_mail( string $email ): bool {
		$domain = strtolower( (string) substr( strrchr( $email, '@' ), 1 ) );
		if ( '' === $domain ) {
			return false;
		}
		if ( in_array( $domain, self::free_mail_domains(), true ) ) {
			return true;
		}
		// Regional variants such as yahoo.co.uk, outlook.de, hotmail.fr.
		return (bool) preg_match( '/^(yahoo|outlook|hotmail|live)\.[a-z.]+$/', $domain );
	}

	/**
	 * Whether the support address is on this site's own domain, so it can be
	 * used as the From address without failing SPF/DMARC.
	 */
	public static function support_email_on_site_domain(): bool {
		$email  = self::support_email();
		$domain = strtolower( (string) substr( strrchr( $email, '@' ), 1 ) );
		$site   = self::site_domain();
		return '' !== $domain && ( $domain === $site || str_ends_with( $site, '.' . $domain ) || str_ends_with( $domain, '.' . $site ) );
	}

	/**
	 * Simplified My Account for license-only customers.
	 */
	public static function simplify_account(): bool {
		return (bool) self::get( 'simplify_account' );
	}

	/**
	 * Name, email and country only at checkout for license-only carts.
	 */
	public static function simple_checkout(): bool {
		return (bool) self::get( 'simple_checkout' );
	}
}
