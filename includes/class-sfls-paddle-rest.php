<?php
/**
 * Paddle REST endpoints.
 *
 *   POST /wp-json/sfls/v1/paddle/webhook       Paddle notifications (signed).
 *   POST /wp-json/sfls/v1/paddle/eligibility   { email, plan, site? } before a trial checkout.
 *   GET  /wp-json/sfls/v1/paddle/checkout      ?txn&token: the license a checkout created.
 *
 * Webhooks: the Paddle-Signature header is `ts=<unix>;h1=<hex>` (more than one
 * h1 while secrets rotate). The signed payload is `<ts>:<raw body>` and h1 is
 * its HMAC-SHA256 with the destination's secret key. Requests older or newer
 * than five minutes (filter `sfls_paddle_webhook_tolerance`) are refused, and
 * each event_id is handled once (table wp_sfls_paddle_events). Events can
 * arrive out of order; subscription events older than the last one applied to
 * a license are skipped (SFLS_Billing::sync_subscription()).
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paddle routes.
 */
final class SFLS_Paddle_REST {

	const DEFAULT_TOLERANCE = 300;

	/**
	 * Subscription events that carry a full subscription entity.
	 */
	const SUBSCRIPTION_EVENTS = array(
		'subscription.created',
		'subscription.trialing',
		'subscription.activated',
		'subscription.updated',
		'subscription.past_due',
		'subscription.paused',
		'subscription.resumed',
		'subscription.canceled',
		'subscription.imported',
	);

	/**
	 * Hook routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function routes(): void {
		register_rest_route(
			SFLS_REST::NS,
			'/paddle/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook' ),
				'permission_callback' => '__return_true', // Authenticated by the Paddle-Signature header.
			)
		);
		register_rest_route(
			SFLS_REST::NS,
			'/paddle/eligibility',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'eligibility' ),
				'permission_callback' => '__return_true', // Public, but only a signed-in caller (valid REST nonce) gets the real answer.
			)
		);
		register_rest_route(
			SFLS_REST::NS,
			'/paddle/checkout',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'checkout' ),
				'permission_callback' => '__return_true', // Authenticated by the checkout token or the signed-in owner.
			)
		);
	}

	// ── Signature ───────────────────────────────────────────────────────

	/**
	 * Verify a Paddle-Signature header.
	 *
	 * @param string   $header    Header value, e.g. "ts=1671552777;h1=eb4d...".
	 * @param string   $raw       Raw request body, unchanged.
	 * @param string   $secret    Endpoint secret key.
	 * @param int|null $now       Current time (tests).
	 * @param int|null $tolerance Allowed clock difference in seconds.
	 * @return true|WP_Error
	 */
	public static function verify( string $header, string $raw, string $secret, ?int $now = null, ?int $tolerance = null ) {
		if ( '' === $secret ) {
			return new WP_Error( 'not_configured', 'Webhook secret is not set.' );
		}
		$ts     = 0;
		$hashes = array();
		foreach ( explode( ';', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 'ts' === $pair[0] && ctype_digit( $pair[1] ) ) {
				$ts = (int) $pair[1];
			} elseif ( 'h1' === $pair[0] && '' !== $pair[1] ) {
				$hashes[] = strtolower( $pair[1] );
			}
		}
		if ( ! $ts || ! $hashes ) {
			return new WP_Error( 'malformed_signature', 'Paddle-Signature header is missing or malformed.' );
		}
		$now       = $now ?? time();
		$tolerance = $tolerance ?? (int) apply_filters( 'sfls_paddle_webhook_tolerance', self::DEFAULT_TOLERANCE );
		if ( abs( $now - $ts ) > $tolerance ) {
			return new WP_Error( 'stale_signature', 'Paddle-Signature timestamp is outside the allowed window.' );
		}
		$expected = hash_hmac( 'sha256', $ts . ':' . $raw, $secret );
		foreach ( $hashes as $h1 ) {
			if ( hash_equals( $expected, $h1 ) ) {
				return true;
			}
		}
		return new WP_Error( 'bad_signature', 'Paddle-Signature does not match.' );
	}

	/**
	 * A valid header for a body (tests and the owner's sandbox replays).
	 *
	 * @param string   $raw    Body.
	 * @param string   $secret Secret.
	 * @param int|null $ts     Timestamp.
	 */
	public static function sign( string $raw, string $secret, ?int $ts = null ): string {
		$ts = $ts ?? time();
		return 'ts=' . $ts . ';h1=' . hash_hmac( 'sha256', $ts . ':' . $raw, $secret );
	}

	// ── Webhook ─────────────────────────────────────────────────────────

	/**
	 * Claim an event for processing. False when it was handled already.
	 *
	 * @param array $event Notification.
	 */
	private static function claim( array $event ): bool {
		global $wpdb;
		$table = SFLS_DB::paddle_events();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}sfls_paddle_events ( event_id, event_type, occurred_at, received_at, status ) VALUES ( %s, %s, %s, %s, 'processing' )",
				(string) $event['event_id'],
				substr( (string) $event['event_type'], 0, 64 ),
				(string) ( SFLS_Billing::gmt( $event['occurred_at'] ?? null ) ?? current_time( 'mysql', true ) ),
				current_time( 'mysql', true )
			)
		);
		if ( $inserted ) {
			return true;
		}
		// A delivery that crashed half way (still "processing" after five minutes) may run again.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, received_at FROM {$wpdb->prefix}sfls_paddle_events WHERE event_id = %s", (string) $event['event_id'] ), ARRAY_A );
		if ( $row && 'processing' === $row['status'] && strtotime( $row['received_at'] . ' UTC' ) < time() - 5 * MINUTE_IN_SECONDS ) {
			$wpdb->update( $table, array( 'received_at' => current_time( 'mysql', true ) ), array( 'event_id' => (string) $event['event_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return true;
		}
		return false;
	}

	/**
	 * Webhook endpoint.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function webhook( WP_REST_Request $req ) {
		$raw   = (string) $req->get_body();
		$check = self::verify( (string) $req->get_header( 'paddle_signature' ), $raw, SFLS_Paddle::config()['webhook_secret'] );
		if ( is_wp_error( $check ) ) {
			return new WP_REST_Response(
				array(
					'received' => false,
					'error'    => $check->get_error_code(),
				),
				'not_configured' === $check->get_error_code() ? 503 : 401
			);
		}
		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || empty( $event['event_id'] ) || empty( $event['event_type'] ) || ! is_array( $event['data'] ?? null ) ) {
			return new WP_REST_Response(
				array(
					'received' => false,
					'error'    => 'invalid_payload',
				),
				400
			);
		}
		if ( ! self::claim( $event ) ) {
			return rest_ensure_response(
				array(
					'received'  => true,
					'duplicate' => true,
				)
			);
		}

		try {
			$result = self::dispatch( $event );
		} catch ( Throwable $e ) {
			global $wpdb;
			$wpdb->delete( SFLS_DB::paddle_events(), array( 'event_id' => (string) $event['event_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			do_action( 'sfls_paddle_webhook_error', $event, $e );
			// Paddle retries on anything but 200.
			return new WP_REST_Response(
				array(
					'received' => false,
					'error'    => 'processing_failed',
				),
				500
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SFLS_DB::paddle_events(),
			array(
				'status'     => substr( $result['action'], 0, 20 ),
				'license_id' => (int) ( $result['license']['id'] ?? 0 ),
				'note'       => $result['note'],
			),
			array( 'event_id' => (string) $event['event_id'] )
		);
		do_action( 'sfls_paddle_event_handled', $event, $result );

		return rest_ensure_response(
			array(
				'received' => true,
				'action'   => $result['action'],
			)
		);
	}

	/**
	 * Route an event to its handler.
	 *
	 * @param array $event Notification.
	 * @return array{action:string,note:string,license:?array}
	 */
	public static function dispatch( array $event ): array {
		$type = (string) $event['event_type'];
		$data = (array) $event['data'];
		if ( in_array( $type, self::SUBSCRIPTION_EVENTS, true ) ) {
			return SFLS_Billing::sync_subscription(
				$data,
				array(
					'event_type'     => $type,
					'occurred_at'    => (string) SFLS_Billing::gmt( $event['occurred_at'] ?? null ),
					'transaction_id' => (string) ( $data['transaction_id'] ?? '' ),
					'from_webhook'   => true,
				)
			);
		}
		$woo = function_exists( 'wc_get_order' );
		if ( 'transaction.completed' === $type ) {
			return $woo ? SFLS_Woo_Paddle_Orders::transaction_completed( $data ) : SFLS_Billing::transaction_completed( $data );
		}
		if ( 'transaction.payment_failed' === $type ) {
			return $woo ? SFLS_Woo_Paddle_Orders::payment_failed( $data ) : SFLS_Billing::payment_failed( $data );
		}
		if ( $woo && in_array( $type, array( 'adjustment.created', 'adjustment.updated' ), true ) ) {
			return SFLS_Woo_Paddle_Orders::adjustment( $data );
		}
		return array(
			'action'  => 'ignored',
			'note'    => 'Event type not used.',
			'license' => null,
		);
	}

	/**
	 * Most recent events, for Licenses › Settings › Paddle.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function recent_events( int $limit = 20 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_paddle_events ORDER BY received_at DESC LIMIT %d", $limit ), ARRAY_A );
	}

	// ── Eligibility and checkout status ─────────────────────────────────

	/**
	 * Per-IP limit shared with the license API.
	 */
	private static function limited(): ?WP_REST_Response {
		return ( new SFLS_REST() )->rate_limit( 'ip', SFLS_Licenses::client_ip() );
	}

	/**
	 * Can this email start a free trial of this plan?
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function eligibility( WP_REST_Request $req ) {
		$limited = self::limited();
		if ( $limited ) {
			return $limited;
		}
		$plan  = sanitize_key( (string) $req->get_param( 'plan' ) );
		$user  = wp_get_current_user();
		$email = $user->ID ? $user->user_email : sanitize_email( (string) $req->get_param( 'email' ) );
		$site  = sanitize_text_field( (string) $req->get_param( 'site' ) );

		if ( ! SFLS_Paddle::any_selling() || ! SFLS_Plans::get( $plan ) ) {
			return new WP_REST_Response(
				array(
					'eligible' => false,
					'reason'   => 'unavailable',
					'message'  => __( 'Free trials are not available right now.', 'subtleforms-license-server' ),
				),
				400
			);
		}
		if ( ! SFLS_Paddle::plan_has_trial( $plan ) ) {
			return rest_ensure_response(
				array(
					'eligible'   => false,
					'reason'     => 'no_trial',
					'message'    => __( 'This plan has no free trial.', 'subtleforms-license-server' ),
					'email'      => $email,
					'trial_days' => 0,
				)
			);
		}
		if ( ! $user->ID ) {
			// Anonymous callers get a uniform answer: only the shape of the address is checked, never
			// whether it or its site already had a trial. Checkout enforces the real rules, so this
			// endpoint cannot be used to probe other people's addresses or sites.
			$normal = SFLS_Trials::normalize_email( $email );
			if ( '' === $normal ) {
				$check = array(
					'eligible' => false,
					'reason'   => 'invalid_email',
					'message'  => __( 'Enter a valid email address.', 'subtleforms-license-server' ),
				);
			} else {
				$check = array(
					'eligible' => true,
					'reason'   => '',
					'message'  => '',
				);
			}
			return rest_ensure_response(
				$check + array(
					'email'      => $email,
					'trial_days' => SFLS_Paddle::plan( $plan )['trial_days'],
				)
			);
		}
		$check = SFLS_Trials::check( $email, $site );
		return rest_ensure_response(
			$check + array(
				'email'      => $email,
				'trial_days' => SFLS_Paddle::plan( $plan )['trial_days'],
			)
		);
	}

	/**
	 * The license a checkout created, once the webhook has issued it.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function checkout( WP_REST_Request $req ) {
		// Polling after a payment has a bucket of its own (per address and order or transaction), so it
		// never uses up the allowance of the license API and a customer is not locked out by it.
		$ref     = (string) $req->get_param( 'order' ) . '|' . (string) $req->get_param( 'txn' );
		$limited = ( new SFLS_REST() )->rate_limit( 'poll', SFLS_Licenses::client_ip() . '|' . $ref );
		if ( $limited ) {
			return $limited;
		}
		if ( $req->get_param( 'order' ) && function_exists( 'wc_get_order' ) ) {
			return self::order_status( absint( $req->get_param( 'order' ) ), sanitize_text_field( (string) $req->get_param( 'key' ) ) );
		}
		$txn   = sanitize_text_field( (string) $req->get_param( 'txn' ) );
		$token = sanitize_text_field( (string) $req->get_param( 'token' ) );
		$lic   = preg_match( '/^txn_[a-z0-9]+$/', $txn ) ? SFLS_Licenses::find_by_transaction( $txn ) : null;
		if ( ! $lic ) {
			return rest_ensure_response( array( 'ready' => false ) );
		}
		$user  = wp_get_current_user();
		$owner = $user->ID && SFLS_Licenses::user_owns( $lic, $user );
		$valid = '' !== $token && '' !== (string) $lic['checkout_token_hash'] && hash_equals( (string) $lic['checkout_token_hash'], hash( 'sha256', $token ) );
		if ( ! $owner && ! $valid ) {
			return rest_ensure_response(
				array(
					'ready'      => true,
					'authorized' => false,
					'message'    => __( 'Your license is ready. We emailed the key to you, and you can see it after signing in.', 'subtleforms-license-server' ),
					'account'    => SFLS_Licenses::manage_url(),
				)
			);
		}
		return rest_ensure_response( array( 'ready' => true ) + self::license_summary( $lic ) );
	}

	/**
	 * The WooCommerce order received page asks whether the Paddle payment of
	 * an order is confirmed (?order=ID&key=wc_order_...). If the webhook has
	 * not arrived yet, Paddle is asked directly every few seconds.
	 *
	 * @param int    $order_id Order.
	 * @param string $key      Order key.
	 * @return WP_REST_Response
	 */
	private static function order_status( int $order_id, string $key ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return new WP_REST_Response( array( 'ready' => false ), 404 );
		}
		SFLS_Woo_Paddle_Orders::poll( $order );
		$order    = wc_get_order( $order_id );
		$licenses = SFLS_Woo::order_licenses( $order_id );
		if ( ! $licenses ) {
			return rest_ensure_response(
				array(
					'ready'  => false,
					'status' => $order->get_status(),
				)
			);
		}
		return rest_ensure_response(
			array(
				'ready' => true,
				'html'  => SFLS_Delivery::panel_body( $order ),
				'total' => (string) $order->get_total(),
			) + self::license_summary( $licenses[0] )
		);
	}

	/**
	 * What the welcome page shows for a license.
	 *
	 * @param array $lic License.
	 */
	public static function license_summary( array $lic ): array {
		$fmt  = get_option( 'date_format' );
		$date = static fn( $gmt ) => $gmt ? wp_date( $fmt, strtotime( $gmt . ' UTC' ) ) : '';
		return array(
			'authorized'  => true,
			'license_key' => (string) $lic['license_key'],
			'plan'        => SFLS_Plans::label( (string) $lic['plan'] ),
			'sites'       => SFLS_Plans::sites_label( (int) $lic['sites_allowed'] ),
			'trial'       => ! empty( $lic['trial'] ),
			'trial_ends'  => ! empty( $lic['trial'] ) ? $date( $lic['trial_ends_at'] ) : '',
			'renews'      => $date( $lic['renews_at'] ?? null ),
			'amount'      => (string) SFLS_Licenses::renewal_amount( $lic ),
			'email'       => (string) $lic['customer_email'],
			'download'    => function_exists( 'wc_get_account_endpoint_url' ) ? SFLS_Delivery::download_link() : '',
			'account'     => SFLS_Licenses::manage_url(),
		);
	}
}
