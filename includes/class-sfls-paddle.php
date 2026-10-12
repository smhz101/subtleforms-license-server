<?php
/**
 * Paddle Billing (API v2): configuration and API client.
 *
 * Settings live in the `sfls_paddle` option (not autoloaded):
 *   env             sandbox | live
 *   api_key         server-side API key (Bearer)
 *   client_token    client-side token for Paddle.js
 *   webhook_secret  secret key of the notification destination
 *   plans           plan => { product_id, price_id, trial_price_id, trial_days, amount, currency }
 *
 * `price_id` is the yearly price without a trial (Buy now, upgrades). The
 * optional `trial_price_id` is the same yearly price with a 7 or 14 day trial
 * that requires a payment method.
 *
 * Every API call goes through wp_remote_request(), so tests intercept it with
 * the pre_http_request filter.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paddle configuration and API calls.
 */
final class SFLS_Paddle {

	const OPTION      = 'sfls_paddle';
	const API_LIVE    = 'https://api.paddle.com';
	const API_SANDBOX = 'https://sandbox-api.paddle.com';
	const JS_URL      = 'https://cdn.paddle.com/paddle/v2/paddle.js';

	/**
	 * Days the license stays valid after a Paddle billing date, so a charge
	 * that is a few minutes or hours late never shows as expired.
	 */
	const BILLING_GRACE_DAYS = 3;

	/**
	 * Configuration with defaults.
	 *
	 * @return array{env:string,api_key:string,client_token:string,webhook_secret:string,plans:array}
	 */
	public static function config(): array {
		$config = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'env'            => 'sandbox',
				'api_key'        => '',
				'client_token'   => '',
				'webhook_secret' => '',
				'plans'          => array(),
			)
		);
		$config['env']   = 'live' === $config['env'] ? 'live' : 'sandbox';
		$config['plans'] = is_array( $config['plans'] ) ? $config['plans'] : array();
		return $config;
	}

	/**
	 * Store configuration (merged with what is stored).
	 *
	 * @param array $values Values to change.
	 */
	public static function save_config( array $values ): void {
		update_option( self::OPTION, array_merge( self::config(), $values ), false );
	}

	/**
	 * Plan mapping: plan => { product_id, price_id, trial_price_id, trial_days, amount, currency }.
	 *
	 * @return array<string,array{product_id:string,price_id:string,trial_price_id:string,trial_days:int,amount:string,currency:string}>
	 */
	public static function plans(): array {
		$stored = self::config()['plans'];
		$out    = array();
		foreach ( array_keys( SFLS_Plans::all() ) as $plan ) {
			$row          = (array) ( $stored[ $plan ] ?? array() );
			$out[ $plan ] = array(
				'product_id'     => (string) ( $row['product_id'] ?? '' ),
				'price_id'       => (string) ( $row['price_id'] ?? '' ),
				'trial_price_id' => (string) ( $row['trial_price_id'] ?? '' ),
				'trial_days'     => (int) ( $row['trial_days'] ?? 0 ),
				'amount'         => (string) ( $row['amount'] ?? '' ),
				'currency'       => (string) ( $row['currency'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * One plan's mapping.
	 *
	 * @param string $plan Plan slug.
	 */
	public static function plan( string $plan ): array {
		return self::plans()[ $plan ] ?? array(
			'product_id'     => '',
			'price_id'       => '',
			'trial_price_id' => '',
			'trial_days'     => 0,
			'amount'         => '',
			'currency'       => '',
		);
	}

	/**
	 * Whether a plan offers a free trial at checkout.
	 *
	 * @param string $plan Plan slug.
	 */
	public static function plan_has_trial( string $plan ): bool {
		$row = self::plan( $plan );
		return '' !== $row['trial_price_id'] && $row['trial_days'] > 0;
	}

	/**
	 * Plan slug of a Paddle price ID ('' when it is not mapped).
	 *
	 * @param string $price_id Paddle price ID.
	 */
	public static function plan_for_price( string $price_id ): string {
		if ( '' === $price_id ) {
			return '';
		}
		foreach ( self::plans() as $plan => $row ) {
			if ( $price_id === $row['price_id'] || $price_id === $row['trial_price_id'] ) {
				return $plan;
			}
		}
		return '';
	}

	/**
	 * Whether API calls can be made.
	 */
	public static function has_api_key(): bool {
		return '' !== self::config()['api_key'];
	}

	/**
	 * Whether checkout, webhooks and the API are all set up.
	 */
	public static function configured(): bool {
		$c = self::config();
		if ( '' === $c['api_key'] || '' === $c['client_token'] || '' === $c['webhook_secret'] ) {
			return false;
		}
		foreach ( self::plans() as $row ) {
			if ( '' !== $row['price_id'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether new licenses are sold through Paddle right now.
	 */
	public static function selling(): bool {
		return 'paddle' === SFLS_Settings::sell_with() && self::configured();
	}

	/**
	 * API base URL for the environment.
	 */
	public static function api_base(): string {
		return 'live' === self::config()['env'] ? self::API_LIVE : self::API_SANDBOX;
	}

	/**
	 * Subscription page in the Paddle dashboard.
	 *
	 * @param string $subscription_id Subscription ID.
	 */
	public static function dashboard_url( string $subscription_id ): string {
		$host = 'live' === self::config()['env'] ? 'https://vendors.paddle.com' : 'https://sandbox-vendors.paddle.com';
		return $host . '/subscriptions-v2/' . rawurlencode( $subscription_id );
	}

	/**
	 * Webhook URL to paste into Paddle › Developer tools › Notifications.
	 */
	public static function webhook_url(): string {
		return rest_url( SFLS_REST::NS . '/paddle/webhook' );
	}

	// ── Money ───────────────────────────────────────────────────────────

	/**
	 * Currencies without minor units in Paddle amounts.
	 */
	private const ZERO_DECIMAL = array( 'JPY', 'KRW', 'VND', 'CLP', 'HUF', 'TWD' );

	/**
	 * Amount in minor units ("7900") as a decimal ("79.00").
	 *
	 * @param string $minor    Amount in the lowest denomination.
	 * @param string $currency ISO 4217 code.
	 */
	public static function to_decimal( string $minor, string $currency ): string {
		$decimals = in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? 0 : 2;
		return number_format( (int) $minor / ( 10 ** $decimals ), $decimals, '.', '' );
	}

	/**
	 * Decimal amount ("79" or "79.5") to minor units ("7900", "7950").
	 *
	 * @param string $amount   Decimal amount.
	 * @param string $currency ISO 4217 code.
	 */
	public static function to_minor( string $amount, string $currency ): string {
		$decimals = in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? 0 : 2;
		return (string) (int) round( (float) $amount * ( 10 ** $decimals ) );
	}

	/**
	 * "$79.00" (or "USD 79.00" without WooCommerce).
	 *
	 * @param string $minor     Amount in the lowest denomination.
	 * @param string $currency  ISO 4217 code.
	 * @param bool   $trim_zero "$79" instead of "$79.00" for whole amounts (pricing cards).
	 */
	public static function format_amount( string $minor, string $currency, bool $trim_zero = false ): string {
		$currency = strtoupper( $currency );
		$decimals = in_array( $currency, self::ZERO_DECIMAL, true ) ? 0 : 2;
		if ( $trim_zero && 2 === $decimals && 0 === (int) $minor % 100 ) {
			$decimals = 0;
			$minor    = (string) ( (int) $minor / 100 );
		}
		$number   = number_format_i18n( (int) $minor / ( 10 ** $decimals ), $decimals );
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' );
			if ( '' !== $symbol && $symbol !== $currency ) {
				return $symbol . $number;
			}
		}
		return $currency . ' ' . $number;
	}

	// ── API ─────────────────────────────────────────────────────────────

	/**
	 * Call the Paddle API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path, e.g. /subscriptions/sub_123.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query args.
	 * @return array|WP_Error Decoded `data` (and `meta` under `_meta`), or an error.
	 */
	public static function request( string $method, string $path, ?array $body = null, array $query = array() ) {
		$key = self::config()['api_key'];
		if ( '' === $key ) {
			return new WP_Error( 'paddle_not_configured', __( 'Add your Paddle API key in Licenses › Settings › Paddle.', 'subtleforms-license-server' ) );
		}
		$url  = add_query_arg( array_map( 'rawurlencode', $query ), self::api_base() . $path );
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 15,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $key,
				'Paddle-Version' => '1',
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( (object) $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'paddle_unreachable', sprintf( /* translators: %s: error message */ __( 'Paddle could not be reached: %s', 'subtleforms-license-server' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$json = is_array( $json ) ? $json : array();
		if ( $code < 200 || $code >= 300 ) {
			$detail = (string) ( $json['error']['detail'] ?? $json['error']['code'] ?? '' );
			return new WP_Error(
				'paddle_' . sanitize_key( (string) ( $json['error']['code'] ?? 'error' ) ),
				sprintf(
					/* translators: 1: HTTP status, 2: Paddle's error message */
					__( 'Paddle answered %1$d: %2$s', 'subtleforms-license-server' ),
					$code,
					'' !== $detail ? $detail : __( 'unknown error', 'subtleforms-license-server' )
				),
				array( 'status' => $code )
			);
		}
		$data = $json['data'] ?? array();
		$data = is_array( $data ) ? $data : array();
		if ( isset( $json['meta'] ) ) {
			$data['_meta'] = $json['meta'];
		}
		return $data;
	}

	/**
	 * Check the API key (lists one event type, which every key may read).
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		$result = self::request( 'GET', '/event-types' );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * A subscription.
	 *
	 * @param string $id Subscription ID.
	 * @return array|WP_Error
	 */
	public static function get_subscription( string $id ) {
		return self::request( 'GET', '/subscriptions/' . rawurlencode( $id ) );
	}

	/**
	 * A customer (for the email address).
	 *
	 * @param string $id Customer ID.
	 * @return array|WP_Error
	 */
	public static function get_customer( string $id ) {
		return self::request( 'GET', '/customers/' . rawurlencode( $id ) );
	}

	/**
	 * Cancel a subscription now or at the end of the billing period.
	 *
	 * @param string $id          Subscription ID.
	 * @param bool   $immediately Cancel now (no further charge) instead of at the period end.
	 * @return array|WP_Error Updated subscription.
	 */
	public static function cancel_subscription( string $id, bool $immediately = false ) {
		return self::request( 'POST', '/subscriptions/' . rawurlencode( $id ) . '/cancel', array( 'effective_from' => $immediately ? 'immediately' : 'next_billing_period' ) );
	}

	/**
	 * Update a subscription.
	 *
	 * @param string $id   Subscription ID.
	 * @param array  $body Fields (items, proration_billing_mode, scheduled_change, ...).
	 * @return array|WP_Error Updated subscription.
	 */
	public static function update_subscription( string $id, array $body ) {
		return self::request( 'PATCH', '/subscriptions/' . rawurlencode( $id ), $body );
	}

	/**
	 * Authenticated customer portal links for one subscription.
	 *
	 * @param string $customer_id     Customer ID.
	 * @param string $subscription_id Subscription ID ('' for the overview only).
	 * @return array|WP_Error { overview, cancel_subscription, update_subscription_payment_method, view_subscription }
	 */
	public static function portal_urls( string $customer_id, string $subscription_id = '' ) {
		$result = self::request( 'POST', '/customers/' . rawurlencode( $customer_id ) . '/portal-sessions', '' !== $subscription_id ? array( 'subscription_ids' => array( $subscription_id ) ) : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$urls = array( 'overview' => (string) ( $result['urls']['general']['overview'] ?? '' ) );
		foreach ( (array) ( $result['urls']['subscriptions'] ?? array() ) as $row ) {
			if ( ( $row['id'] ?? '' ) === $subscription_id ) {
				$urls += array(
					'cancel_subscription'                => (string) ( $row['cancel_subscription'] ?? '' ),
					'update_subscription_payment_method' => (string) ( $row['update_subscription_payment_method'] ?? '' ),
					'view_subscription'                  => (string) ( $row['view_subscription'] ?? '' ),
				);
			}
		}
		return $urls;
	}

	/**
	 * Every active price with its product (follows pagination, at most 1000).
	 *
	 * @return array[]|WP_Error
	 */
	public static function list_prices() {
		$out   = array();
		$after = '';
		for ( $page = 0; $page < 5; $page++ ) {
			$query = array(
				'include'  => 'product',
				'status'   => 'active',
				'per_page' => '200',
			);
			if ( '' !== $after ) {
				$query['after'] = $after;
			}
			$result = self::request( 'GET', '/prices', null, $query );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$meta = $result['_meta'] ?? array();
			unset( $result['_meta'] );
			foreach ( $result as $price ) {
				if ( is_array( $price ) ) {
					$out[] = $price;
				}
			}
			if ( empty( $meta['pagination']['has_more'] ) || ! $out ) {
				break;
			}
			$after = (string) end( $out )['id'];
		}
		return $out;
	}

	/**
	 * Create a product.
	 *
	 * @param string $name        Name.
	 * @param string $description Description.
	 * @return array|WP_Error
	 */
	public static function create_product( string $name, string $description = '' ) {
		return self::request(
			'POST',
			'/products',
			array_filter(
				array(
					'name'         => $name,
					'tax_category' => 'standard',
					'description'  => $description,
					'custom_data'  => array( 'source' => 'subtleforms-license-server' ),
				)
			)
		);
	}

	/**
	 * Create a yearly price, optionally with a free trial that requires a
	 * payment method.
	 *
	 * @param string $product_id  Product ID.
	 * @param string $name        Name shown at checkout.
	 * @param string $minor       Amount in the lowest denomination.
	 * @param string $currency    ISO 4217 code.
	 * @param int    $trial_days  Trial length (0 = no trial).
	 * @return array|WP_Error
	 */
	public static function create_yearly_price( string $product_id, string $name, string $minor, string $currency, int $trial_days = 0 ) {
		$body = array(
			'product_id'    => $product_id,
			'description'   => $trial_days > 0
				/* translators: 1: plan product name, 2: trial days */
				? sprintf( __( '%1$s, yearly with a %2$d day free trial', 'subtleforms-license-server' ), $name, $trial_days )
				/* translators: %s: plan product name */
				: sprintf( __( '%s, yearly', 'subtleforms-license-server' ), $name ),
			'name'          => $name,
			'unit_price'    => array(
				'amount'        => $minor,
				'currency_code' => strtoupper( $currency ),
			),
			'billing_cycle' => array(
				'interval'  => 'year',
				'frequency' => 1,
			),
			'quantity'      => array(
				'minimum' => 1,
				'maximum' => 1,
			),
			'custom_data'   => array( 'source' => 'subtleforms-license-server' ),
		);
		if ( $trial_days > 0 ) {
			$body['trial_period'] = array(
				'interval'                => 'day',
				'frequency'               => $trial_days,
				'requires_payment_method' => true,
			);
		}
		return self::request( 'POST', '/prices', $body );
	}

	/**
	 * Trial length in days of a Paddle price (0 without a trial).
	 *
	 * @param array $price Price entity.
	 */
	public static function trial_days_of( array $price ): int {
		$trial = $price['trial_period'] ?? null;
		if ( ! is_array( $trial ) || empty( $trial['frequency'] ) ) {
			return 0;
		}
		$per = array(
			'day'   => 1,
			'week'  => 7,
			'month' => 30,
			'year'  => 365,
		);
		return (int) $trial['frequency'] * ( $per[ $trial['interval'] ?? 'day' ] ?? 1 );
	}
}
