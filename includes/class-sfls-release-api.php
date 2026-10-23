<?php
/**
 * Release API for CI/CD, authenticated with a deploy token
 * (Authorization: Bearer sflsdt_…, scope releases:write).
 *
 *   POST   /wp-json/sfls/v1/releases                     multipart "package" or JSON package_url
 *   GET    /wp-json/sfls/v1/releases
 *   GET    /wp-json/sfls/v1/releases/<version>
 *   POST   /wp-json/sfls/v1/releases/<version>/publish
 *   POST   /wp-json/sfls/v1/releases/<version>/yank      { reason }
 *   DELETE /wp-json/sfls/v1/releases/<version>
 *
 * Plain http is refused unless the site is local (WP_ENVIRONMENT_TYPE
 * local). Failed token checks are limited per IP (10 per 15 minutes by
 * default, sfls_token_auth_limit filter), then the API answers 429.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * CI release routes.
 */
final class SFLS_Release_API {

	const NS = 'sfls/v1';

	/**
	 * Token record of the current request.
	 *
	 * @var array|null
	 */
	private static ?array $token = null;

	/**
	 * Hook routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes. Every callback checks the deploy token itself first
	 * (see guard()) so it can answer 429 with Retry-After.
	 */
	public function routes(): void {
		$version = '/releases/(?P<version>[0-9A-Za-z.+\-]{1,40})';
		$open    = '__return_true'; // Authenticated by deploy token in guard().
		register_rest_route(
			self::NS,
			'/releases',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_releases' ),
					'permission_callback' => $open,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $open,
				),
			)
		);
		register_rest_route(
			self::NS,
			$version,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $open,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $open,
				),
			)
		);
		register_rest_route(
			self::NS,
			$version . '/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish' ),
				'permission_callback' => $open,
			)
		);
		register_rest_route(
			self::NS,
			$version . '/yank',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'yank' ),
				'permission_callback' => $open,
			)
		);
	}

	// ── Authentication ──────────────────────────────────────────────────

	/**
	 * Whether the request may use the API over this transport.
	 */
	public static function transport_ok(): bool {
		return is_ssl() || (bool) apply_filters( 'sfls_release_api_allow_http', 'local' === wp_get_environment_type() );
	}

	/**
	 * The token from Authorization: Bearer, or X-SFLS-Token for hosts that
	 * strip the Authorization header.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function bearer( WP_REST_Request $req ): string {
		$auth = trim( (string) $req->get_header( 'authorization' ) );
		if ( preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) {
			return $m[1];
		}
		return trim( (string) $req->get_header( 'x_sfls_token' ) );
	}

	/**
	 * Failed-auth limit per IP.
	 *
	 * @return array{limit:int,window:int}
	 */
	private static function limit(): array {
		$conf = wp_parse_args(
			(array) apply_filters( 'sfls_token_auth_limit', array() ),
			array(
				'limit'  => 10,
				'window' => 15 * MINUTE_IN_SECONDS,
			)
		);
		return array(
			'limit'  => max( 1, (int) $conf['limit'] ),
			'window' => max( 1, (int) $conf['window'] ),
		);
	}

	/**
	 * Transient key of the failed-auth counter for an IP.
	 *
	 * @param string $ip IP.
	 */
	private static function bucket( string $ip ): string {
		return 'sfls_rl_token_' . md5( '' === $ip ? 'unknown' : $ip );
	}

	/**
	 * Check the deploy token. Returns null when the request may go on, or
	 * the error response.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function guard( WP_REST_Request $req ): ?WP_REST_Response {
		self::$token = null;
		if ( ! self::transport_ok() ) {
			return self::error( 403, 'https_required', __( 'The release API only works over https.', 'subtleforms-license-server' ) );
		}
		$ip    = SFLS_Licenses::client_ip();
		$conf  = self::limit();
		$now   = time();
		$state = get_transient( self::bucket( $ip ) );
		$state = ( is_array( $state ) && isset( $state['start'], $state['hits'] ) && $state['start'] + $conf['window'] > $now ) ? $state : array(
			'start' => $now,
			'hits'  => 0,
		);
		if ( $state['hits'] >= $conf['limit'] ) {
			$response = self::error( 429, 'rate_limited', __( 'Too many failed token attempts. Try again later.', 'subtleforms-license-server' ) );
			$response->header( 'Retry-After', (string) max( 1, (int) $state['start'] + $conf['window'] - $now ) );
			return $response;
		}
		$token  = self::bearer( $req );
		$record = '' !== $token ? SFLS_Deploy_Tokens::verify( $token ) : null;
		if ( ! $record ) {
			++$state['hits'];
			set_transient( self::bucket( $ip ), $state, $conf['window'] );
			return '' === $token
				? self::error( 401, 'missing_token', __( 'Send a deploy token in the Authorization header: Bearer <token>.', 'subtleforms-license-server' ) )
				: self::error( 401, 'invalid_token', __( 'That deploy token is not valid or was revoked.', 'subtleforms-license-server' ) );
		}
		self::$token = $record;
		return null;
	}

	/**
	 * Error response (WP REST error shape).
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private static function error( int $status, string $code, string $message ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	/**
	 * Response for a WP_Error.
	 *
	 * @param WP_Error $error Error.
	 */
	private static function from_error( WP_Error $error ): WP_REST_Response {
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 400 );
		return self::error( $status >= 400 ? $status : 400, (string) $error->get_error_code(), $error->get_error_message() );
	}

	// ── Payloads ────────────────────────────────────────────────────────

	/**
	 * Public fields of a release (never the file path or private notes).
	 *
	 * @param array $release Release.
	 */
	public static function payload( array $release ): array {
		$latest = SFLS_Releases::latest( 'stable' );
		$beta   = SFLS_Releases::latest( 'beta' );
		$iso    = static fn( $v ) => $v ? gmdate( 'c', (int) strtotime( $v . ' UTC' ) ) : null;
		return array(
			'id'           => (int) $release['id'],
			'version'      => $release['version'],
			'channel'      => $release['channel'],
			'status'       => $release['status'],
			'latest'       => $latest && $latest['version'] === $release['version'],
			'latest_beta'  => $beta && $beta['version'] === $release['version'],
			'pinned'       => SFLS_Releases::pinned() === $release['version'],
			'size'         => (int) $release['size'],
			'sha256'       => $release['sha256'],
			'requires'     => $release['requires'],
			'tested'       => $release['tested'],
			'requires_php' => $release['requires_php'],
			'changelog'    => $release['changelog'],
			'source'       => $release['source'],
			'git_ref'      => $release['git_ref'],
			'git_commit'   => $release['git_commit'],
			'yank_reason'  => $release['yank_reason'],
			'downloads'    => (int) $release['downloads'],
			'has_package'  => SFLS_Releases::has_package( $release ),
			'package_sig'  => SFLS_Signing::release_signature( $release ),
			'released_at'  => $iso( $release['released_at'] ),
			'created_at'   => $iso( $release['created_at'] ),
			'updated_at'   => $iso( $release['updated_at'] ),
		);
	}

	/**
	 * Release from the route, or an error response.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_REST_Response
	 */
	private function release_of( WP_REST_Request $req ) {
		$release = SFLS_Releases::get( (string) $req->get_param( 'version' ), true );
		return $release ? $release : self::error( 404, 'not_found', __( 'That release was not found.', 'subtleforms-license-server' ) );
	}

	// ── Endpoints ───────────────────────────────────────────────────────

	/**
	 * GET /releases.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function list_releases( WP_REST_Request $req ): WP_REST_Response {
		$denied = self::guard( $req );
		if ( $denied ) {
			return $denied;
		}
		SFLS_Releases::flush();
		$latest = SFLS_Releases::latest( 'stable' );
		$beta   = SFLS_Releases::latest( 'beta' );
		return new WP_REST_Response(
			array(
				'latest'      => $latest ? $latest['version'] : null,
				'latest_beta' => $beta ? $beta['version'] : null,
				'pinned'      => '' !== SFLS_Releases::pinned() ? SFLS_Releases::pinned() : null,
				'releases'    => array_map( array( self::class, 'payload' ), SFLS_Releases::rows() ),
			)
		);
	}

	/**
	 * GET /releases/<version>.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function show( WP_REST_Request $req ): WP_REST_Response {
		$denied = self::guard( $req );
		if ( $denied ) {
			return $denied;
		}
		$release = $this->release_of( $req );
		return $release instanceof WP_REST_Response ? $release : new WP_REST_Response( self::payload( $release ) );
	}

	/**
	 * POST /releases: store a package from a multipart upload ("package") or
	 * from package_url (https, GitHub hosts by default).
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function create( WP_REST_Request $req ): WP_REST_Response {
		$denied = self::guard( $req );
		if ( $denied ) {
			return $denied;
		}
		$files   = (array) $req->get_file_params();
		$url     = trim( (string) $req->get_param( 'package_url' ) );
		$tmp     = '';
		$cleanup = false;

		if ( isset( $files['package'] ) && is_array( $files['package'] ) ) {
			$file = (array) $files['package'];
			if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
				$too_big = in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
				return self::error(
					$too_big ? 413 : 400,
					'upload_failed',
					$too_big
						? sprintf(
							/* translators: %s: PHP upload limit */
							__( 'The package is larger than this server accepts (upload_max_filesize is %s). Raise upload_max_filesize and post_max_size, or send a package_url instead.', 'subtleforms-license-server' ),
							(string) ini_get( 'upload_max_filesize' )
						)
						: __( 'The package upload failed.', 'subtleforms-license-server' )
				);
			}
			$tmp = (string) $file['tmp_name'];
			// Real multipart uploads always pass is_uploaded_file(); the filter exists for tests.
			if ( apply_filters( 'sfls_release_api_check_uploaded', true ) && ! is_uploaded_file( $tmp ) ) {
				return self::error( 400, 'upload_failed', __( 'The package upload failed.', 'subtleforms-license-server' ) );
			}
		} elseif ( '' !== $url ) {
			$tmp = SFLS_Releases::download_package( esc_url_raw( $url, array( 'https' ) ) );
			if ( is_wp_error( $tmp ) ) {
				return self::from_error( $tmp );
			}
			$cleanup = true;
		} else {
			return self::error( 400, 'missing_package', __( 'Send the zip as a multipart field named "package", or a package_url. If you did send a file, it may be larger than post_max_size on this server.', 'subtleforms-license-server' ) );
		}

		$publish = null === $req->get_param( 'publish' ) || '' === $req->get_param( 'publish' )
			? (bool) SFLS_Releases::settings()['auto_publish']
			: rest_sanitize_boolean( $req->get_param( 'publish' ) );
		$channel = sanitize_key( (string) $req->get_param( 'channel' ) );

		$result = SFLS_Releases::ingest(
			$tmp,
			array(
				'source'          => 'api',
				'channel'         => in_array( $channel, SFLS_Releases::CHANNELS, true ) ? $channel : '',
				'publish'         => $publish,
				'changelog'       => (string) $req->get_param( 'changelog' ),
				'git_ref'         => sanitize_text_field( (string) $req->get_param( 'git_ref' ) ),
				'git_commit'      => sanitize_text_field( (string) $req->get_param( 'git_commit' ) ),
				'sha256'          => sanitize_text_field( (string) $req->get_param( 'sha256' ) ),
				'replace'         => rest_sanitize_boolean( $req->get_param( 'replace' ) ),
				// A second, explicit flag for replacing a package customers already get.
				'confirm_replace' => rest_sanitize_boolean( $req->get_param( 'confirm_replace' ) ),
			)
		);
		if ( $cleanup ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $result ) ) {
			return self::from_error( $result );
		}
		return new WP_REST_Response(
			array(
				'result'  => $result['result'],
				'release' => self::payload( $result['release'] ),
			),
			'existing' === $result['result'] ? 200 : 201
		);
	}

	/**
	 * POST /releases/<version>/publish.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function publish( WP_REST_Request $req ): WP_REST_Response {
		$denied = self::guard( $req );
		if ( $denied ) {
			return $denied;
		}
		$release = $this->release_of( $req );
		if ( $release instanceof WP_REST_Response ) {
			return $release;
		}
		$result = SFLS_Releases::publish( $release['id'] );
		return is_wp_error( $result ) ? self::from_error( $result ) : new WP_REST_Response( self::payload( $result ) );
	}

	/**
	 * POST /releases/<version>/yank.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function yank( WP_REST_Request $req ): WP_REST_Response {
		$denied = self::guard( $req );
		if ( $denied ) {
			return $denied;
		}
		$release = $this->release_of( $req );
		if ( $release instanceof WP_REST_Response ) {
			return $release;
		}
		SFLS_Releases::yank( $release['id'], (string) $req->get_param( 'reason' ) );
		return new WP_REST_Response( self::payload( (array) SFLS_Releases::get_by_id( $release['id'] ) ) );
	}

	/**
	 * DELETE /releases/<version>: removes the release and its package.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function destroy( WP_REST_Request $req ): WP_REST_Response {
		$denied = self::guard( $req );
		if ( $denied ) {
			return $denied;
		}
		$release = $this->release_of( $req );
		if ( $release instanceof WP_REST_Response ) {
			return $release;
		}
		SFLS_Releases::delete( $release['id'] );
		return new WP_REST_Response(
			array(
				'deleted' => true,
				'version' => $release['version'],
			)
		);
	}
}
