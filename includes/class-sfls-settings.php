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
			// 1.5.0: woo_paddle (WooCommerce checkout, Paddle payments),
			// woocommerce (other gateways) or paddle (legacy overlay).
			'sell_with'        => 'woo_paddle',
			'renewal_notice'   => '14,3',
			'trial_notice'     => 3,
			// 1.7.0: hosts whose sites do not use a seat, one pattern per line.
			'staging_hosts'    => implode( "\n", SFLS_Licenses::default_staging_patterns() ),
			// 1.7.0: days to keep the activation log (IP addresses) and Paddle events, 0 = forever.
			'retention_days'   => 365,
			// Schema 8: behind a CDN or proxy, read the visitor address from its headers.
			'trusted_proxy'    => 0,
			// Schema 8: trials need a verified email, and refuse disposable addresses.
			'trial_verify'     => 1,
			'block_disposable' => 1,
			'disposable_extra' => '',
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
		$values = array(
			'pricing_url'      => esc_url_raw( (string) ( $input['pricing_url'] ?? '' ) ),
			'reminder_days'    => self::day_list( (string) ( $input['reminder_days'] ?? '' ) ),
			'lapsed_reminder'  => empty( $input['lapsed_reminder'] ) ? 0 : 1,
			'support_email'    => sanitize_email( (string) ( $input['support_email'] ?? '' ) ),
			'simplify_account' => empty( $input['simplify_account'] ) ? 0 : 1,
			'simple_checkout'  => empty( $input['simple_checkout'] ) ? 0 : 1,
		);
		// Checkboxes of the trial and proxy rules are saved only when the form says it has them.
		if ( ! empty( $input['rules_present'] ) ) {
			$values['trusted_proxy']    = empty( $input['trusted_proxy'] ) ? 0 : 1;
			$values['trial_verify']     = empty( $input['trial_verify'] ) ? 0 : 1;
			$values['block_disposable'] = empty( $input['block_disposable'] ) ? 0 : 1;
			$values['disposable_extra'] = implode( "\n", self::clean_domains( (string) ( $input['disposable_extra'] ?? '' ) ) );
		}
		if ( array_key_exists( 'retention_days', $input ) ) {
			$values['retention_days'] = min( 3650, absint( $input['retention_days'] ) );
		}
		if ( array_key_exists( 'staging_hosts', $input ) ) {
			$values['staging_hosts'] = implode( "\n", self::clean_patterns( (string) $input['staging_hosts'] ) );
		}
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * Staging host patterns from text: one per line (commas work too),
	 * lowercase, only letters, digits, dots, hyphens and "*", no duplicates.
	 *
	 * @param string $raw Text.
	 * @return string[]
	 */
	public static function clean_patterns( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( $raw ) ) as $line ) {
			$line = trim( (string) $line, '.' );
			if ( '' !== $line && preg_match( '/^[a-z0-9*][a-z0-9.*-]*$/', $line ) && '*' !== $line && str_contains( $line, '.' ) ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Mail domains from text: one per line (commas work too), lowercase, no
	 * "@", letters, digits, dots and hyphens, at least one dot, no duplicates.
	 *
	 * @param string $raw Text.
	 * @return string[]
	 */
	public static function clean_domains( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( $raw ) ) as $line ) {
			$line = trim( ltrim( (string) $line, '@' ), '.' );
			if ( '' !== $line && str_contains( $line, '.' ) && preg_match( '/^[a-z0-9][a-z0-9.-]*$/', $line ) ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether the site sits behind a proxy or CDN whose client address
	 * headers can be trusted.
	 */
	public static function trusted_proxy(): bool {
		return (bool) self::get( 'trusted_proxy' );
	}

	/**
	 * Whether a trial needs an email address confirmed with a code first.
	 */
	public static function trial_verify(): bool {
		return (bool) self::get( 'trial_verify' );
	}

	/**
	 * Whether disposable mail addresses are refused for free trials.
	 */
	public static function block_disposable(): bool {
		return (bool) self::get( 'block_disposable' );
	}

	/**
	 * Throw-away mail services: the built-in list, the extra domains of
	 * Licenses › Settings, filtered by `sfls_disposable_domains`.
	 *
	 * @return string[]
	 */
	public static function disposable_domains(): array {
		$builtin = array(
			'mailinator.com',
			'guerrillamail.com',
			'guerrillamail.net',
			'guerrillamail.org',
			'guerrillamailblock.com',
			'sharklasers.com',
			'grr.la',
			'10minutemail.com',
			'10minutemail.net',
			'tempmail.com',
			'temp-mail.org',
			'temp-mail.io',
			'tempmail.net',
			'tempail.com',
			'throwawaymail.com',
			'yopmail.com',
			'yopmail.net',
			'yopmail.fr',
			'trashmail.com',
			'trashmail.net',
			'trash-mail.com',
			'getnada.com',
			'nada.email',
			'maildrop.cc',
			'dispostable.com',
			'fakeinbox.com',
			'fakemail.net',
			'mintemail.com',
			'mohmal.com',
			'moakt.com',
			'emailondeck.com',
			'burnermail.io',
			'mailnesia.com',
			'mytemp.email',
			'tmpmail.org',
			'tmpmail.net',
			'discard.email',
			'spamgourmet.com',
			'mailcatch.com',
			'inboxkitten.com',
			'anonaddy.me',
			'33mail.com',
			'emailfake.com',
			'harakirimail.com',
			'tempinbox.com',
			'jetable.org',
			'mail.tm',
			'dropmail.me',
			'tempr.email',
		);
		$list    = array_merge( $builtin, self::clean_domains( (string) self::get( 'disposable_extra' ) ) );
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'sfls_disposable_domains', $list ) ) ) );
	}

	/**
	 * Whether an address is on a disposable mail domain (or a subdomain of one).
	 *
	 * @param string $email Address.
	 */
	public static function is_disposable( string $email ): bool {
		$domain = strtolower( (string) substr( strrchr( $email, '@' ), 1 ) );
		if ( '' === $domain ) {
			return false;
		}
		foreach ( self::disposable_domains() as $blocked ) {
			if ( $domain === $blocked || str_ends_with( $domain, '.' . $blocked ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Days the activation log and Paddle webhook events are kept (0 = forever).
	 */
	public static function retention_days(): int {
		return absint( self::get( 'retention_days' ) );
	}

	/**
	 * Staging host patterns of Licenses › Settings.
	 *
	 * @return string[]
	 */
	public static function staging_patterns(): array {
		return self::clean_patterns( (string) self::get( 'staging_hosts' ) );
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
					'sell_with'      => self::clean_sell_with( (string) ( $input['sell_with'] ?? '' ) ),
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
	 * Selling modes.
	 *
	 *   woo_paddle   WooCommerce cart and checkout, paid with the Paddle gateway (recommended).
	 *   woocommerce  WooCommerce cart and checkout with the shop's other gateways.
	 *   paddle       Legacy: pricing cards open the Paddle overlay directly, no WooCommerce order.
	 */
	const SELL_MODES = array( 'woo_paddle', 'woocommerce', 'paddle' );

	/**
	 * A valid selling mode ('woocommerce' for anything unknown).
	 *
	 * @param string $value Raw value.
	 */
	public static function clean_sell_with( string $value ): string {
		return in_array( $value, self::SELL_MODES, true ) ? $value : 'woocommerce';
	}

	/**
	 * Who sells new licenses: woo_paddle, woocommerce or paddle (legacy overlay).
	 */
	public static function sell_with(): string {
		return self::clean_sell_with( (string) self::get( 'sell_with' ) );
	}

	/**
	 * 1.5.0 migration: a shop that sold through the Paddle overlay in 1.4.0
	 * moves to the WooCommerce checkout with Paddle payments, and the Paddle
	 * gateway is switched on. Runs once.
	 */
	public static function migrate_150(): void {
		if ( get_option( 'sfls_migrated_150' ) ) {
			return;
		}
		update_option( 'sfls_migrated_150', 1, false );
		$stored = (array) get_option( self::OPTION, array() );
		if ( 'paddle' !== ( $stored['sell_with'] ?? '' ) ) {
			return;
		}
		$stored['sell_with'] = 'woo_paddle';
		update_option( self::OPTION, $stored );
		$gateway            = (array) get_option( 'woocommerce_sfls_paddle_settings', array() );
		$gateway['enabled'] = 'yes';
		update_option( 'woocommerce_sfls_paddle_settings', $gateway );
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
			array(
				'gmail.com',
				'googlemail.com',
				'outlook.com',
				'hotmail.com',
				'live.com',
				'msn.com',
				'yahoo.com',
				'ymail.com',
				'rocketmail.com',
				'aol.com',
				'icloud.com',
				'me.com',
				'mac.com',
				'proton.me',
				'protonmail.com',
				'protonmail.ch',
				'pm.me',
				'gmx.com',
				'gmx.net',
				'gmx.de',
				'gmx.at',
				'web.de',
				'mail.com',
				'zoho.com',
				'zohomail.com',
				'fastmail.com',
				'fastmail.fm',
				'yandex.com',
				'yandex.ru',
				'ya.ru',
				'mail.ru',
				'inbox.ru',
				'list.ru',
				'bk.ru',
				'qq.com',
				'163.com',
				'126.com',
				'sina.com',
				'naver.com',
				'rediffmail.com',
				'tutanota.com',
				'tuta.io',
				'hey.com',
				't-online.de',
				'libero.it',
				'laposte.net',
				'orange.fr',
				'free.fr',
			)
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
		return (bool) preg_match( '/^(yahoo|outlook|hotmail|live|gmx|yandex|zoho)\.[a-z.]+$/', $domain );
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
