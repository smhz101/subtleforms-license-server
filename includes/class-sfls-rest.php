<?php
/**
 * Public license API used by SubtleForms Pro.
 *
 *   POST /wp-json/sfls/v1/license/activate    { license_key, site_url }
 *   POST /wp-json/sfls/v1/license/validate    { license_key, site_url }
 *   POST /wp-json/sfls/v1/license/deactivate  { license_key, site_url }
 *   GET  /wp-json/sfls/v1/license/status      ?license_key&site_url
 *   GET  /wp-json/sfls/v1/update/check        ?license_key&site_url&version&slug[&channel=beta]
 *   GET  /wp-json/sfls/v1/update/package      ?lid&ptoken&site_url[&channel][&version], or ?license_key&site_url (redirects to a fresh signed link)
 *   GET  /wp-json/sfls/v1/update/download     ?lid&site&expires&token&sig[&version] (15 minutes, single use)
 *   GET  /wp-json/sfls/v1/update/versions     ?license_key&site_url[&channel=beta] (versions this license may download)
 *   POST /wp-json/sfls/v1/connect/exchange    { code, site_url }
 *
 * Every response is JSON except download. Errors use a 4xx status with
 * { error, reason }, which is what the Pro client maps to a license state.
 * activate, validate, status, package and versions are limited per IP (30
 * requests per 10 minutes). Requests from a site the valid key is activated
 * on are also limited per key and site; every other request is checked
 * against a per-key budget of failed requests. Over a limit they answer 429
 * with Retry-After.
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
		register_rest_route( self::NS, '/license/status', array( 'methods' => 'GET, POST', 'callback' => array( $this, 'validate' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/update/check', array( 'methods' => 'GET, POST', 'callback' => array( $this, 'update_check' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/update/package', array( 'methods' => 'GET', 'callback' => array( $this, 'package' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/update/download', array( 'methods' => 'GET', 'callback' => array( $this, 'download' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/update/versions', array( 'methods' => 'GET', 'callback' => array( $this, 'versions' ), 'permission_callback' => $open ) );
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
		return $this->guarded( $req, array( $this, 'activate_now' ) );
	}

	/**
	 * Activate endpoint after the rate limit.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function activate_now( WP_REST_Request $req ) {
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
		return $this->guarded( $req, array( $this, 'validate_now' ) );
	}

	/**
	 * Validate endpoint after the rate limit.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function validate_now( WP_REST_Request $req ) {
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}

		$payload = SFLS_Licenses::payload( $lic, $domain );

		if ( ! SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			// Same shape as every other error (valid, reason, error). It stays
			// HTTP 200, which Pro reads as "inactive" rather than "invalid", and
			// keeps "message" for Pro versions that read that field (LS-10).
			$text = __( 'This site is not activated for this license.', 'subtleforms-license-server' );
			return rest_ensure_response(
				array(
					'valid'   => false,
					'reason'  => 'domain_not_activated',
					'error'   => $text,
					'message' => $text,
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
	 * The channel a request asks for: beta when the site opted in, else stable.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	private static function channel( WP_REST_Request $req ): string {
		return 'beta' === sanitize_key( (string) $req->get_param( 'channel' ) ) ? 'beta' : 'stable';
	}

	/**
	 * Update check endpoint. Offers the latest published release of the
	 * site's channel (stable unless channel=beta); drafts and yanked releases
	 * are never offered.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function update_check( WP_REST_Request $req ) {
		$channel = self::channel( $req );
		$release = SFLS_Releases::latest( $channel );
		if ( ! $release ) {
			return rest_ensure_response( new stdClass() );
		}

		$response = array(
			'new_version'  => $release['version'],
			'requires'     => $release['requires'],
			'tested'       => $release['tested'],
			'requires_php' => $release['requires_php'],
			'last_updated' => $release['updated'],
			'changelog'    => SFLS_Releases::changelog_html( $release ),
			'channel'      => $release['channel'],
			'package'      => '',
		);

		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( ! $error && SFLS_Licenses::state( $lic )['valid'] && SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			// Pro caches this answer for hours, longer than a signed link lives,
			// so the package is a stable address that hands out a fresh signed
			// link at download time (see package()).
			$response['package'] = self::package_address( $lic, $domain, 'beta' === $channel ? array( 'channel' => 'beta' ) : array() );
			SFLS_Licenses::touch_activation( (int) $lic['id'], $domain, (string) $req->get_param( 'version' ) );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Package endpoint: checks the license and the site, then redirects to a
	 * newly signed download link that works once within 15 minutes. Without
	 * a version it serves the latest release of the channel and needs a valid
	 * license. With a version it serves that release when the license may
	 * download it (an expired license keeps the versions released while it
	 * was valid).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function package( WP_REST_Request $req ) {
		self::key_from_package_token( $req );
		return $this->guarded( $req, array( $this, 'package_now' ) );
	}

	/**
	 * Stable package address of a license and site: the license ID and a
	 * token, never the key (1.7.0).
	 *
	 * @param array  $lic    License.
	 * @param string $domain Normalised domain.
	 * @param array  $extra  channel, version.
	 */
	public static function package_address( array $lic, string $domain, array $extra = array() ): string {
		$args = array(
			'lid'      => (int) $lic['id'],
			'site_url' => rawurlencode( $domain ),
			'ptoken'   => SFLS_Releases::package_token( $lic, $domain ),
		);
		foreach ( $extra as $name => $value ) {
			$args[ $name ] = rawurlencode( (string) $value );
		}
		return add_query_arg( $args, rest_url( self::NS . '/update/package' ) );
	}

	/**
	 * A package address with lid and ptoken stands for the license key: put
	 * the key on the request when the token matches. Addresses with the key
	 * itself (handed out before 1.7.0) keep working.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	private static function key_from_package_token( WP_REST_Request $req ): void {
		if ( '' !== (string) $req->get_param( 'license_key' ) ) {
			return;
		}
		$id     = absint( $req->get_param( 'lid' ) );
		$token  = strtolower( sanitize_text_field( (string) $req->get_param( 'ptoken' ) ) );
		$domain = SFLS_Licenses::normalize_domain( (string) $req->get_param( 'site_url' ) );
		$lic    = $id ? SFLS_Licenses::find( $id ) : null;
		if ( $lic && '' !== $domain && preg_match( '/^[a-f0-9]{64}$/', $token ) && hash_equals( SFLS_Releases::package_token( $lic, $domain ), $token ) ) {
			$req->set_param( 'license_key', (string) $lic['license_key'] );
		}
	}

	/**
	 * Package endpoint after the rate limit.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function package_now( WP_REST_Request $req ) {
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}
		$version = sanitize_text_field( (string) $req->get_param( 'version' ) );
		if ( '' === $version && ! SFLS_Licenses::state( $lic )['valid'] ) {
			return $this->error( 403, 'expired', __( 'An active license is required to download updates.', 'subtleforms-license-server' ) );
		}
		if ( ! SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			return $this->error( 403, 'domain_not_activated', __( 'This site is not activated for this license.', 'subtleforms-license-server' ) );
		}
		$release = '' === $version ? SFLS_Releases::latest( self::channel( $req ) ) : SFLS_Releases::find( $version );
		if ( ! $release || ! SFLS_Releases::has_package( $release ) ) {
			return $this->error( 404, 'no_package', __( 'No package is available.', 'subtleforms-license-server' ) );
		}
		if ( ! SFLS_Releases::allowed( $lic, $release ) ) {
			return $this->error( 403, 'expired', __( 'This version was released after your license expired. Renew to download it.', 'subtleforms-license-server' ) );
		}
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', SFLS_Releases::signed_url( $lic['license_key'], $domain, $release['version'] ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Versions endpoint: every published, non-yanked version this license may
	 * download, highest first, each with a package address that redirects to
	 * a fresh signed link for that version.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function versions( WP_REST_Request $req ) {
		return $this->guarded( $req, array( $this, 'versions_now' ) );
	}

	/**
	 * Versions endpoint after the rate limit.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function versions_now( WP_REST_Request $req ) {
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}
		$activated = (bool) SFLS_Licenses::activation( (int) $lic['id'], $domain );
		$channel   = self::channel( $req );
		$latest    = SFLS_Releases::latest( $channel );
		$list      = array();
		foreach ( SFLS_Releases::allowed_for( $lic, $channel ) as $release ) {
			if ( ! SFLS_Releases::has_package( $release ) ) {
				continue;
			}
			$list[] = array(
				'version'      => $release['version'],
				'channel'      => $release['channel'],
				'latest'       => $latest && $latest['version'] === $release['version'],
				'released_at'  => $release['released_at'] ? gmdate( 'c', (int) strtotime( $release['released_at'] . ' UTC' ) ) : null,
				'requires'     => $release['requires'],
				'tested'       => $release['tested'],
				'requires_php' => $release['requires_php'],
				'size'         => (int) $release['size'],
				'sha256'       => $release['sha256'],
				'changelog'    => $release['changelog'],
				'package'      => $activated ? self::package_address( $lic, $domain, array( 'version' => $release['version'] ) ) : '',
			);
		}
		$state = SFLS_Licenses::state( $lic );
		return rest_ensure_response(
			array(
				'status'    => $state['status'],
				'valid'     => $state['valid'],
				'activated' => $activated,
				'channel'   => $channel,
				'versions'  => $list,
			)
		);
	}

	/**
	 * Download endpoint. The signed link must be unexpired and unused, and
	 * the license must be allowed to download that version. Links without a
	 * version (handed out by 1.5) serve the current stable release.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function download( WP_REST_Request $req ) {
		$id      = absint( $req->get_param( 'lid' ) );
		$by_id   = $id ? SFLS_Licenses::find( $id ) : null;
		// Links name the license by ID since 1.7.0; older links carry the key.
		$key     = $id ? ( $by_id ? strtoupper( (string) $by_id['license_key'] ) : '' ) : strtoupper( sanitize_text_field( (string) $req->get_param( 'license_key' ) ) );
		$domain  = SFLS_Licenses::normalize_domain( (string) $req->get_param( 'site' ) );
		$expires = (int) $req->get_param( 'expires' );
		$token   = strtolower( sanitize_key( (string) $req->get_param( 'token' ) ) );
		$sig     = sanitize_text_field( (string) $req->get_param( 'sig' ) );
		$version = sanitize_text_field( (string) $req->get_param( 'version' ) );

		if ( ! SFLS_Releases::verify( $key, $domain, $expires, $sig, $token, $version ) ) {
			return $this->error( 403, 'invalid_signature', __( 'This download link has expired. Check for updates again.', 'subtleforms-license-server' ) );
		}
		$lic     = SFLS_Licenses::find_by_key( $key );
		$release = '' === $version ? SFLS_Releases::latest( 'stable' ) : SFLS_Releases::find( $version );
		if ( ! $lic || ( $release && ! SFLS_Releases::allowed( $lic, $release ) ) || ( ! $release && ! SFLS_Licenses::state( $lic )['valid'] ) ) {
			return $this->error( 403, 'expired', __( 'An active license is required to download updates.', 'subtleforms-license-server' ) );
		}
		if ( ! $release || ! SFLS_Releases::has_package( $release ) ) {
			return $this->error( 404, 'no_package', __( 'No package is available.', 'subtleforms-license-server' ) );
		}
		if ( ! SFLS_Releases::consume_token( $token ) ) {
			return $this->error( 403, 'link_used', __( 'This download link was already used. Check for updates again to get a new one.', 'subtleforms-license-server' ) );
		}

		SFLS_Releases::count_download( (int) $release['id'] );
		/**
		 * Fires before a licensed download is streamed.
		 *
		 * @param array  $release Release.
		 * @param array  $lic     License.
		 * @param string $domain  Site domain, or "account" for the members area.
		 */
		do_action( 'sfls_release_downloaded', $release, $lic, $domain );
		SFLS_Releases::stream( $release );
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

		$raw_site = trim( (string) $req->get_param( 'site_url' ) );
		if ( '' === $key && '' === $raw_site ) {
			return array( null, $domain, $this->error( 400, 'missing_parameters', __( 'license_key and site_url are required.', 'subtleforms-license-server' ) ) );
		}
		if ( '' === $key ) {
			return array( null, $domain, $this->error( 400, 'missing_parameters', __( 'license_key is required.', 'subtleforms-license-server' ) ) );
		}
		if ( '' === $raw_site ) {
			return array( null, $domain, $this->error( 400, 'missing_parameters', __( 'site_url is required.', 'subtleforms-license-server' ) ) );
		}
		if ( '' === $domain ) {
			return array( null, $domain, $this->error( 400, 'invalid_site_url', __( 'site_url must be the address of a website, such as https://example.com.', 'subtleforms-license-server' ) ) );
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
	 * Rate limits for activate, validate, status, package and versions.
	 *
	 * Every request counts against its IP. A request for a valid license
	 * from a site it is activated on then only counts against that key and
	 * site, so others who know the key can never lock that site out. Any
	 * other request (unknown key, a site the key is not activated on, a
	 * license that is not active) is checked against the key's failure
	 * budget and, when it fails, counts towards it. The failure budget is the
	 * limit times the number of sites the license is activated on (at least
	 * one, at most its site limit), so a big license is not cut off sooner
	 * than a small one.
	 *
	 * @param WP_REST_Request $req     Request.
	 * @param callable        $handler Endpoint.
	 * @return WP_REST_Response
	 */
	private function guarded( WP_REST_Request $req, callable $handler ) {
		$limited = $this->rate_limit( 'ip', SFLS_Licenses::client_ip() );
		if ( $limited ) {
			return $limited;
		}
		$key = strtoupper( sanitize_text_field( (string) $req->get_param( 'license_key' ) ) );
		if ( '' === $key ) {
			return $handler( $req );
		}
		$domain = SFLS_Licenses::normalize_domain( (string) $req->get_param( 'site_url' ) );
		$lic    = SFLS_Licenses::find_by_key( $key );
		if ( $lic && '' !== $domain && SFLS_Licenses::state( $lic )['valid'] && SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			$limited = $this->rate_limit( 'site', $key . '|' . $domain );
			return $limited ? $limited : $handler( $req );
		}
		$scale   = $lic ? self::failure_scale( $lic ) : 1;
		$limited = $this->rate_limit( 'key', $key, false, $scale );
		if ( $limited ) {
			return $limited;
		}
		$response = rest_ensure_response( $handler( $req ) );
		if ( self::failed( $response ) ) {
			$this->rate_limit( 'key', $key, true, $scale );
		}
		return $response;
	}

	/**
	 * How many times the limit a license's failure budget is: the sites it
	 * is activated on, at least one and at most its site limit.
	 *
	 * @param array $lic License.
	 */
	private static function failure_scale( array $lic ): int {
		$sites = count( SFLS_Licenses::activations( (int) $lic['id'] ) );
		$limit = (int) $lic['sites_allowed'];
		if ( $limit > 0 ) {
			$sites = min( $sites, $limit );
		}
		return max( 1, $sites );
	}

	/**
	 * Whether a license request failed (error status, or valid / activated
	 * false in the answer). A 429 is not counted again.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private static function failed( WP_REST_Response $response ): bool {
		$status = $response->get_status();
		if ( 429 === $status ) {
			return false;
		}
		if ( $status >= 400 ) {
			return true;
		}
		$data = $response->get_data();
		return is_array( $data ) && ( ( array_key_exists( 'valid', $data ) && false === $data['valid'] ) || ( array_key_exists( 'activated', $data ) && false === $data['activated'] ) );
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
	 * @param string $type  ip | key (failures of a key) | site (key and site).
	 * @param string $value IP address, license key, or "key|domain".
	 * @param bool   $count Count this request (false: only check).
	 * @param int    $scale Multiplies the limit.
	 */
	public function rate_limit( string $type, string $value, bool $count = true, int $scale = 1 ): ?WP_REST_Response {
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
		$limit  = max( 1, (int) $conf['limit'] ) * max( 1, $scale );
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

		if ( $count ) {
			++$state['hits'];
			set_transient( $bucket, $state, $window );
		}
		return null;
	}
}
