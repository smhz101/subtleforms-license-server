<?php
/**
 * Paddle test helpers: configuration, a mock of the Paddle API (through the
 * pre_http_request filter, so every request is recorded and nothing leaves
 * the machine), entity fixtures and signed webhook deliveries.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals

/**
 * Use in an SFLS_Test_Case.
 */
trait SFLS_Paddle_Helpers {

	/**
	 * Recorded Paddle API calls: { method, path, query, body }.
	 *
	 * @var array[]
	 */
	protected array $paddle_calls = array();

	/**
	 * Scripted answers: "METHOD /path" (or a regex starting with #) => data array or callable( call ) => [ code, data ].
	 *
	 * @var array<string,mixed>
	 */
	protected array $paddle_routes = array();

	/**
	 * Webhook secret used by the tests.
	 */
	protected string $secret = 'pdl_ntfset_test_secret_0123456789';

	/**
	 * Configure Paddle (sandbox) and sell with it, with the API mocked.
	 *
	 * @param array $plans Plan mapping overrides.
	 */
	protected function paddle( array $plans = array() ): void {
		$this->set_option(
			SFLS_Paddle::OPTION,
			array(
				'env'            => 'sandbox',
				'api_key'        => 'pdl_sdbx_apikey_test',
				'client_token'   => 'test_clienttoken',
				'webhook_secret' => $this->secret,
				'plans'          => array_replace_recursive(
					array(
						'starter' => array(
							'product_id'     => 'pro_starter',
							'price_id'       => 'pri_starter',
							'trial_price_id' => 'pri_starter_trial',
							'trial_days'     => 7,
							'amount'         => '3900',
							'currency'       => 'USD',
						),
						'pro'     => array(
							'product_id'     => 'pro_pro',
							'price_id'       => 'pri_pro',
							'trial_price_id' => 'pri_pro_trial',
							'trial_days'     => 14,
							'amount'         => '7900',
							'currency'       => 'USD',
						),
						'agency'  => array(
							'product_id'     => 'pro_agency',
							'price_id'       => 'pri_agency',
							'trial_price_id' => '',
							'trial_days'     => 0,
							'amount'         => '19900',
							'currency'       => 'USD',
						),
					),
					$plans
				),
			)
		);
		$this->set_option( SFLS_Billing::REJECTED_OPTION, array() );
		$this->settings( array( 'sell_with' => 'paddle' ) );
		$this->filter( 'pre_http_request', array( $this, 'mock_paddle' ), 10, 3 );
	}

	/**
	 * The pre_http_request mock.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function mock_paddle( $pre, $args, $url ) {
		if ( ! str_starts_with( $url, SFLS_Paddle::API_SANDBOX ) && ! str_starts_with( $url, SFLS_Paddle::API_LIVE ) ) {
			return $pre;
		}
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$call                 = array(
			'method'  => strtoupper( $args['method'] ?? 'GET' ),
			'path'    => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'query'   => $query,
			'raw'     => (string) ( $args['body'] ?? '' ),
			'body'    => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
			'headers' => (array) ( $args['headers'] ?? array() ),
		);
		$this->paddle_calls[] = $call;

		$answer = null;
		foreach ( $this->paddle_routes as $pattern => $response ) {
			$key = $call['method'] . ' ' . $call['path'];
			if ( $pattern === $key || ( str_starts_with( $pattern, '#' ) && preg_match( $pattern, $key ) ) ) {
				$answer = $response;
				break;
			}
		}
		if ( null === $answer ) {
			if ( preg_match( '#^GET /customers/(ctm_\w+)$#', $call['method'] . ' ' . $call['path'], $m ) ) {
				$answer = array(
					'id'    => $m[1],
					'email' => $this->customer_emails[ $m[1] ] ?? 'nobody@' . self::DOMAIN,
					'name'  => 'Pat Tester',
				);
			} else {
				$answer = array( 404, array( 'error' => array( 'code' => 'not_found', 'detail' => 'No mock for ' . $call['method'] . ' ' . $call['path'] ) ) );
			}
		}
		if ( is_callable( $answer ) ) {
			$answer = $answer( $call );
		}
		[ $code, $data ] = isset( $answer[0] ) && is_int( $answer[0] ) ? $answer : array( 200, $answer );
		$body            = $code >= 300 ? $data : array( 'data' => $data, 'meta' => array( 'request_id' => 'req_test' ) );
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Customer emails the mock returns for GET /customers/{id}.
	 *
	 * @var array<string,string>
	 */
	protected array $customer_emails = array();

	/**
	 * API calls matching a method and path.
	 *
	 * @param string $method Method.
	 * @param string $path   Path (exact) or regex starting with #.
	 * @return array[]
	 */
	protected function calls( string $method, string $path ): array {
		return array_values(
			array_filter(
				$this->paddle_calls,
				static fn( $c ) => $c['method'] === $method && ( $c['path'] === $path || ( str_starts_with( $path, '#' ) && preg_match( $path, $c['path'] ) ) )
			)
		);
	}

	/**
	 * RFC 3339 date relative to now.
	 *
	 * @param int $seconds Offset.
	 */
	protected static function when( int $seconds ): string {
		return gmdate( 'Y-m-d\TH:i:s.000000\Z', time() + $seconds );
	}

	/**
	 * A unique test email at a unique company domain under the test domain.
	 *
	 * @param string $local Local part.
	 */
	protected function email( string $local = 'buyer' ): string {
		return $local . wp_generate_password( 5, false, false ) . '@co' . strtolower( wp_generate_password( 6, false, false ) ) . '.' . self::DOMAIN;
	}

	/**
	 * A subscription entity.
	 *
	 * @param array $over Overrides (merged recursively; `items` replaced).
	 */
	protected function subscription( array $over = array() ): array {
		$id       = 'sub_' . strtolower( wp_generate_password( 12, false, false ) );
		$customer = 'ctm_' . strtolower( wp_generate_password( 10, false, false ) );
		$status   = $over['status'] ?? 'trialing';
		$price    = $over['_price'] ?? ( 'trialing' === $status ? 'pri_pro_trial' : 'pri_pro' );
		$amount   = $over['_amount'] ?? '7900';
		$trial    = 'trialing' === $status;
		unset( $over['_price'], $over['_amount'] );
		$sub = array(
			'id'                     => $id,
			'status'                 => $status,
			'customer_id'            => $customer,
			'address_id'             => 'add_test',
			'currency_code'          => 'USD',
			'created_at'             => self::when( -60 ),
			'started_at'             => self::when( -60 ),
			'first_billed_at'        => $trial ? null : self::when( -60 ),
			'next_billed_at'         => $trial ? self::when( 14 * DAY_IN_SECONDS ) : self::when( 365 * DAY_IN_SECONDS ),
			'canceled_at'            => null,
			'current_billing_period' => array(
				'starts_at' => self::when( -60 ),
				'ends_at'   => $trial ? self::when( 14 * DAY_IN_SECONDS ) : self::when( 365 * DAY_IN_SECONDS ),
			),
			'billing_cycle'          => array(
				'interval'  => 'year',
				'frequency' => 1,
			),
			'scheduled_change'       => null,
			'items'                  => array(
				array(
					'status'      => $trial ? 'trialing' : 'active',
					'quantity'    => 1,
					'recurring'   => true,
					'trial_dates' => $trial ? array(
						'starts_at' => self::when( -60 ),
						'ends_at'   => self::when( 14 * DAY_IN_SECONDS ),
					) : null,
					'price'       => array(
						'id'           => $price,
						'product_id'   => 'pro_pro',
						'unit_price'   => array(
							'amount'        => $amount,
							'currency_code' => 'USD',
						),
						'trial_period' => $trial ? array(
							'interval'                => 'day',
							'frequency'               => 14,
							'requires_payment_method' => true,
						) : null,
					),
				),
			),
			'custom_data'            => array(
				'sfls_plan'  => 'pro',
				'sfls_token' => 'tok' . wp_generate_password( 20, false, false ),
			),
		);
		if ( isset( $over['items'] ) ) {
			$sub['items'] = $over['items'];
			unset( $over['items'] );
		}
		return array_replace_recursive( $sub, $over );
	}

	/**
	 * A transaction entity for a subscription.
	 *
	 * @param array $sub  Subscription.
	 * @param array $over Overrides.
	 */
	protected function transaction( array $sub, array $over = array() ): array {
		return array_replace_recursive(
			array(
				'id'              => 'txn_' . strtolower( wp_generate_password( 14, false, false ) ),
				'status'          => 'completed',
				'customer_id'     => $sub['customer_id'],
				'subscription_id' => $sub['id'],
				'origin'          => 'subscription_recurring',
				'currency_code'   => 'USD',
				'billing_period'  => array(
					'starts_at' => self::when( 0 ),
					'ends_at'   => self::when( 365 * DAY_IN_SECONDS ),
				),
				'details'         => array(
					'totals' => array(
						'subtotal'      => '7900',
						'tax'           => '0',
						'total'         => '7900',
						'grand_total'   => '7900',
						'currency_code' => 'USD',
					),
				),
				'items'           => array(
					array(
						'price'    => array( 'id' => 'pri_pro' ),
						'quantity' => 1,
					),
				),
				'custom_data'     => $sub['custom_data'] ?? null,
			),
			$over
		);
	}

	/**
	 * Deliver a signed webhook.
	 *
	 * @param string   $type       Event type.
	 * @param array    $data       Entity.
	 * @param array    $event      Event overrides (event_id, occurred_at).
	 * @param int|null $ts         Signature timestamp.
	 * @param string   $secret     Secret to sign with ('' = the configured one).
	 * @return WP_REST_Response
	 */
	protected function webhook( string $type, array $data, array $event = array(), ?int $ts = null, string $secret = '' ): WP_REST_Response {
		$payload = array_merge(
			array(
				'event_id'        => 'evt_test_' . strtolower( wp_generate_password( 16, false, false ) ),
				'event_type'      => $type,
				'occurred_at'     => self::when( 0 ),
				'notification_id' => 'ntf_test',
				'data'            => $data,
			),
			$event
		);
		$raw     = (string) wp_json_encode( $payload );
		$request = new WP_REST_Request( 'POST', '/sfls/v1/paddle/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Paddle-Signature', SFLS_Paddle_REST::sign( $raw, '' !== $secret ? $secret : $this->secret, $ts ) );
		$request->set_body( $raw );
		$this->last_event = $payload;
		return rest_ensure_response( rest_do_request( $request ) );
	}

	/**
	 * Last delivered event payload.
	 *
	 * @var array
	 */
	protected array $last_event = array();

	/**
	 * Start a trial for a new customer through the webhook and return the license.
	 *
	 * @param string $email Customer email.
	 * @param array  $over  Subscription overrides.
	 * @return array{0:array,1:array} License and subscription.
	 */
	protected function start_trial( string $email = '', array $over = array() ): array {
		$email = '' !== $email ? $email : $this->email();
		$sub   = $this->subscription( $over );
		$this->customer_emails[ $sub['customer_id'] ] = $email;
		$response = $this->webhook( 'subscription.created', $sub + array( 'transaction_id' => 'txn_' . strtolower( wp_generate_password( 14, false, false ) ) ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$lic = SFLS_Licenses::find_by_subscription( $sub['id'] );
		$this->assertNotNull( $lic, 'License created: ' . wp_json_encode( $response->get_data() ) );
		return array( $lic, $sub );
	}

	/**
	 * Emails captured whose subject contains a text.
	 *
	 * @param string $needle Text.
	 * @return array[]
	 */
	protected function mails_with( string $needle ): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => str_contains( (string) $m['subject'], $needle ) ) );
	}
}
