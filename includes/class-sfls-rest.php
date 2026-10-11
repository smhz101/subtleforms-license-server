<?php
/**
 * Public license API used by SubtleForms Pro.
 *
 *   POST /wp-json/sfls/v1/license/activate    { license_key, site_url }
 *   POST /wp-json/sfls/v1/license/validate    { license_key, site_url }
 *   POST /wp-json/sfls/v1/license/deactivate  { license_key, site_url }
 *   GET  /wp-json/sfls/v1/license/status      ?license_key&site_url
 *   GET  /wp-json/sfls/v1/update/check        ?license_key&site_url&version&slug
 *   GET  /wp-json/sfls/v1/update/package      ?license_key&site_url (redirects to a fresh signed link)
 *   GET  /wp-json/sfls/v1/update/download     ?license_key&site&expires&token&sig (15 minutes, single use)
 *   POST /wp-json/sfls/v1/connect/exchange    { code, site_url }
 *
 * Every response is JSON except download. Errors use a 4xx status with
 * { error, reason }, which is what the Pro client maps to a license state.
 * activate, validate and status are limited per IP and per key (30 requests
 * per 10 minutes each); over the limit they answer 429 with Retry-After.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
final class SFLS_REST {

	const NS = 'sfls/v1';

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
		$open = '__return_true'; // Authenticated by license key, not by WordPress user.

		register_rest_route( self::NS, '/license/activate', array( 'methods' => 'POST', 'callback' => array( $this, 'activate' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/license/validate', array( 'methods' => 'POST', 'callback' => array( $this, 'validate' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/license/deactivate', array( 'methods' => 'POST', 'callback' => array( $this, 'deactivate' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/license/status', array( 'methods' => 'GET', 'callback' => array( $this, 'validate' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/update/check', array( 'methods' => 'GET', 'callback' => array( $this, 'update_check' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/update/package', array( 'methods' => 'GET', 'callback' => array( $this, 'package' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/update/download', array( 'methods' => 'GET', 'callback' => array( $this, 'download' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/connect/exchange', array( 'methods' => 'POST', 'callback' => array( $this, 'exchange' ), 'permission_callback' => $open ) );
	}

	// ── Endpoints ───────────────────────────────────────────────────────

	/**
	 * Activate endpoint.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function activate( WP_REST_Request $req ) {
		$limited = $this->limit_license_request( $req );
		if ( $limited ) {
			return $limited;
		}
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}

		$result = SFLS_Licenses::activate_domain( $lic, $domain, sanitize_text_field( (string) $req->get_param( 'version' ) ), 'api' );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error( $result );
		}

		return rest_ensure_response( array( 'activated' => true ) + SFLS_Licenses::payload( $lic, $domain ) );
	}

	/**
	 * Validate endpoint.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function validate( WP_REST_Request $req ) {
		$limited = $this->limit_license_request( $req );
		if ( $limited ) {
			return $limited;
		}
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}

		$payload = SFLS_Licenses::payload( $lic, $domain );

		if ( ! SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			return rest_ensure_response(
				array(
					'valid'   => false,
					'reason'  => 'domain_not_activated',
					'message' => __( 'This site is not activated for this license.', 'subtleforms-license-server' ),
				) + array_diff_key( $payload, array( 'valid' => 1, 'status' => 1 ) )
			);
		}

		SFLS_Licenses::touch_activation( (int) $lic['id'], $domain );

		if ( ! $payload['valid'] ) {
			$payload['reason'] = $payload['status'];
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Deactivate endpoint.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function deactivate( WP_REST_Request $req ) {
		$limited = $this->rate_limit( 'ip', SFLS_Licenses::client_ip() );
		if ( $limited ) {
			return $limited;
		}
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}
		SFLS_Licenses::remove_activation( (int) $lic['id'], $domain, 'api' );
		return rest_ensure_response( array( 'deactivated' => true ) );
	}

	/**
	 * Update check endpoint.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function update_check( WP_REST_Request $req ) {
		$release = SFLS_Releases::current();
		if ( '' === $release['version'] ) {
			return rest_ensure_response( new stdClass() );
		}

		$response = array(
			'new_version'  => $release['version'],
			'requires'     => $release['requires'],
			'tested'       => $release['tested'],
			'requires_php' => $release['requires_php'],
			'last_updated' => $release['updated'],
			'changelog'    => wpautop( esc_html( $release['changelog'] ) ),
			'package'      => '',
		);

		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( ! $error && SFLS_Licenses::state( $lic )['valid'] && SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			// Pro caches this answer for hours, longer than a signed link lives,
			// so the package is a stable address that hands out a fresh signed
			// link at download time (see package()).
			$response['package'] = add_query_arg(
				array(
					'license_key' => rawurlencode( $lic['license_key'] ),
					'site_url'    => rawurlencode( $domain ),
				),
				rest_url( self::NS . '/update/package' )
			);
			SFLS_Licenses::touch_activation( (int) $lic['id'], $domain, (string) $req->get_param( 'version' ) );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Package endpoint: checks the license and the site, then redirects to a
	 * newly signed download link that works once within 15 minutes.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function package( WP_REST_Request $req ) {
		$limited = $this->limit_license_request( $req );
		if ( $limited ) {
			return $limited;
		}
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}
		if ( ! SFLS_Licenses::state( $lic )['valid'] ) {
			return $this->error( 403, 'expired', __( 'An active license is required to download updates.', 'subtleforms-license-server' ) );
		}
		if ( ! SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			return $this->error( 403, 'domain_not_activated', __( 'This site is not activated for this license.', 'subtleforms-license-server' ) );
		}
		if ( ! SFLS_Releases::has_package() ) {
			return $this->error( 404, 'no_package', __( 'No package is available.', 'subtleforms-license-server' ) );
		}
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', SFLS_Releases::signed_url( $lic['license_key'], $domain ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Download endpoint. The signed link must be unexpired and unused.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function download( WP_REST_Request $req ) {
		$key     = strtoupper( sanitize_text_field( (string) $req->get_param( 'license_key' ) ) );
		$domain  = SFLS_Licenses::normalize_domain( (string) $req->get_param( 'site' ) );
		$expires = (int) $req->get_param( 'expires' );
		$token   = strtolower( sanitize_key( (string) $req->get_param( 'token' ) ) );
		$sig     = sanitize_text_field( (string) $req->get_param( 'sig' ) );

		if ( ! SFLS_Releases::verify( $key, $domain, $expires, $sig, $token ) ) {
			return $this->error( 403, 'invalid_signature', __( 'This download link has expired. Check for updates again.', 'subtleforms-license-server' ) );
		}
		$lic = SFLS_Licenses::find_by_key( $key );
		if ( ! $lic || ! SFLS_Licenses::state( $lic )['valid'] ) {
			return $this->error( 403, 'expired', __( 'An active license is required to download updates.', 'subtleforms-license-server' ) );
		}
		if ( ! SFLS_Releases::consume_token( $token ) ) {
			return $this->error( 403, 'link_used', __( 'This download link was already used. Check for updates again to get a new one.', 'subtleforms-license-server' ) );
		}

		$path = SFLS_Releases::package_path();
		if ( ! $path || ! is_readable( $path ) ) {
			return $this->error( 404, 'no_package', __( 'No package is available.', 'subtleforms-license-server' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="subtleforms-pro.zip"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Swap a one-time code from the /connect-site/ flow for the license key.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function exchange( WP_REST_Request $req ) {
		$limited = $this->rate_limit( 'ip', SFLS_Licenses::client_ip() );
		if ( $limited ) {
			return $limited;
		}
		$code   = preg_replace( '/[^A-Za-z0-9]/', '', (string) $req->get_param( 'code' ) );
		$domain = SFLS_Licenses::normalize_domain( sanitize_text_field( (string) $req->get_param( 'site_url' ) ) );
		$fail   = $this->error( 400, 'invalid_code', __( 'This connection code is invalid or has expired. Connect the site again.', 'subtleforms-license-server' ) );

		if ( '' === $code || '' === $domain ) {
			return $fail;
		}

		$data = SFLS_Connect::consume_code( $code );
		if ( ! $data || ! hash_equals( (string) $data['domain'], $domain ) ) {
			return $fail;
		}

		$lic = SFLS_Licenses::find( (int) $data['license_id'] );
		if ( ! $lic ) {
			return $fail;
		}

		$user = get_userdata( (int) $data['user_id'] );
		SFLS_Licenses::touch_activation( (int) $lic['id'], $domain );

		do_action( 'sfls_site_connected', $lic, $domain, (int) $data['user_id'] );

		return rest_ensure_response(
			array(
				'license_key' => $lic['license_key'],
				'account'     => array(
					'email' => $user ? $user->user_email : $lic['customer_email'],
					'name'  => $user ? $user->display_name : '',
				),
			) + SFLS_Licenses::payload( $lic, $domain )
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Look up the license and domain of a request.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array{0:?array,1:string,2:?WP_REST_Response}
	 */
	private function resolve( WP_REST_Request $req ): array {
		$key    = strtoupper( sanitize_text_field( (string) $req->get_param( 'license_key' ) ) );
		$domain = SFLS_Licenses::normalize_domain( (string) $req->get_param( 'site_url' ) );

		if ( '' === $key || '' === $domain ) {
			return array( null, $domain, $this->error( 400, 'missing_parameters', __( 'license_key and site_url are required.', 'subtleforms-license-server' ) ) );
		}

		$lic = SFLS_Licenses::find_by_key( $key );
		if ( ! $lic ) {
			return array( null, $domain, $this->error( 404, 'license_not_found', __( 'That license key was not found. Check it in your SubtleForms account.', 'subtleforms-license-server' ) ) );
		}

		return array( $lic, $domain, null );
	}

	/**
	 * Error response in the shape the Pro client expects.
	 *
	 * @param WP_Error $error Error with a status in its data.
	 */
	private function wp_error( WP_Error $error ): WP_REST_Response {
		$data = (array) $error->get_error_data();
		return $this->error( (int) ( $data['status'] ?? 400 ), (string) $error->get_error_code(), $error->get_error_message() );
	}

	/**
	 * Error response.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $reason  Machine reason.
	 * @param string $message Human message.
	 */
	private function error( int $code, string $reason, string $message ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'valid'  => false,
				'reason' => $reason,
				'error'  => $message,
			),
			$code
		);
	}

	/**
	 * Rate limits for activate, validate, status and package: one bucket per
	 * IP and one per license key.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	private function limit_license_request( WP_REST_Request $req ): ?WP_REST_Response {
		$limited = $this->rate_limit( 'ip', SFLS_Licenses::client_ip() );
		if ( $limited ) {
			return $limited;
		}
		$key = strtoupper( sanitize_text_field( (string) $req->get_param( 'license_key' ) ) );
		return '' === $key ? null : $this->rate_limit( 'key', $key );
	}

	/**
	 * Fixed-window rate limit: `limit` requests per `window` seconds per bucket
	 * (default 30 per 10 minutes). Over the limit the answer is 429 with a
	 * Retry-After header.
	 *
	 * Local sites (WP_ENVIRONMENT_TYPE local) skip it unless the
	 * `sfls_rate_limit_enabled` filter says otherwise, so development and
	 * automated tests are not throttled.
	 *
	 * @param string $type  ip | key.
	 * @param string $value IP address or license key.
	 */
	public function rate_limit( string $type, string $value ): ?WP_REST_Response {
		if ( ! apply_filters( 'sfls_rate_limit_enabled', 'local' !== wp_get_environment_type(), $type ) ) {
			return null;
		}
		$conf   = wp_parse_args(
			(array) apply_filters( 'sfls_rate_limit', array(), $type ),
			array(
				'limit'  => 30,
				'window' => 10 * MINUTE_IN_SECONDS,
			)
		);
		$limit  = max( 1, (int) $conf['limit'] );
		$window = max( 1, (int) $conf['window'] );
		$now    = time();
		$bucket = 'sfls_rl_' . $type . '_' . md5( '' === $value ? 'unknown' : $value );
		$state  = get_transient( $bucket );
		$state  = ( is_array( $state ) && isset( $state['start'], $state['hits'] ) && $state['start'] + $window > $now ) ? $state : array(
			'start' => $now,
			'hits'  => 0,
		);

		if ( $state['hits'] >= $limit ) {
			$retry    = max( 1, (int) $state['start'] + $window - $now );
			$response = $this->error( 429, 'rate_limited', __( 'Too many requests. Try again in a few minutes.', 'subtleforms-license-server' ) );
			$response->header( 'Retry-After', (string) $retry );
			return $response;
		}

		++$state['hits'];
		set_transient( $bucket, $state, $window );
		return null;
	}
}
