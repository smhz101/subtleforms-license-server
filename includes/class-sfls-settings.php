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
			'pricing_url'     => '',
			'reminder_days'   => '30,7',
			'lapsed_reminder' => 1,
			'support_email'   => '',
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
		$days = array_unique( array_filter( array_map( 'absint', explode( ',', (string) ( $input['reminder_days'] ?? '' ) ) ) ) );
		rsort( $days );
		update_option(
			self::OPTION,
			array(
				'pricing_url'     => esc_url_raw( (string) ( $input['pricing_url'] ?? '' ) ),
				'reminder_days'   => implode( ',', array_slice( $days, 0, 10 ) ),
				'lapsed_reminder' => empty( $input['lapsed_reminder'] ) ? 0 : 1,
				'support_email'   => sanitize_email( (string) ( $input['support_email'] ?? '' ) ),
			)
		);
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
}
