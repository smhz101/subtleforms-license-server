<?php
/**
 * Public license API used by SubtleForms Pro.
 *
 *   POST /wp-json/sfls/v1/license/activate    { license_key, site_url }
 *   POST /wp-json/sfls/v1/license/validate    { license_key, site_url }
 *   POST /wp-json/sfls/v1/license/deactivate  { license_key, site_url }
 *   GET  /wp-json/sfls/v1/license/status      ?license_key&site_url
 *   GET  /wp-json/sfls/v1/update/check        ?license_key&site_url&version&slug
 *   GET  /wp-json/sfls/v1/update/download     ?license_key&site&expires&sig
 *
 * Every response is JSON except download. Errors use a 4xx status with
 * { error, reason }, which is what the Pro client maps to a license state.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

final class SFLS_REST {

	const NS = 'sfls/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$open = '__return_true'; // Authenticated by license key, not by WordPress user.

		register_rest_route( self::NS, '/license/activate', array( 'methods' => 'POST', 'callback' => array( $this, 'activate' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/license/validate', array( 'methods' => 'POST', 'callback' => array( $this, 'validate' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/license/deactivate', array( 'methods' => 'POST', 'callback' => array( $this, 'deactivate' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/license/status', array( 'methods' => 'GET', 'callback' => array( $this, 'validate' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/update/check', array( 'methods' => 'GET', 'callback' => array( $this, 'update_check' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/update/download', array( 'methods' => 'GET', 'callback' => array( $this, 'download' ), 'permission_callback' => $open ) );
	}

	// ── Endpoints ───────────────────────────────────────────────────────

	public function activate( WP_REST_Request $req ) {
		$limited = $this->rate_limit();
		if ( $limited ) {
			return $limited;
		}
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}

		$state = SFLS_Licenses::state( $lic );
		if ( ! $state['valid'] ) {
			return $this->error( 'expired' === $state['status'] ? 403 : 403, $state['status'], 'expired' === $state['status']
				? __( 'This license has expired. Renew it from your SubtleForms account.', 'subtleforms-license-server' )
				: __( 'This license is not active. Contact SubtleForms support.', 'subtleforms-license-server' ) );
		}

		$existing = SFLS_Licenses::activation( (int) $lic['id'], $domain );
		if ( ! $existing && ! SFLS_Licenses::is_local_domain( $domain ) ) {
			$limit = (int) $lic['sites_allowed'];
			if ( $limit > 0 && SFLS_Licenses::used_slots( (int) $lic['id'] ) >= $limit ) {
				return $this->error(
					400,
					'activation_limit',
					sprintf(
						/* translators: %d: number of sites */
						_n( 'This license is already active on %d site. Deactivate it there, or upgrade your plan.', 'This license is already active on %d sites. Deactivate one, or upgrade your plan.', $limit, 'subtleforms-license-server' ),
						$limit
					)
				);
			}
		}

		if ( $existing ) {
			SFLS_Licenses::touch_activation( (int) $lic['id'], $domain, (string) $req->get_param( 'version' ) );
		} else {
			SFLS_Licenses::add_activation( (int) $lic['id'], $domain, (string) $req->get_param( 'version' ) );
		}

		return rest_ensure_response( array( 'activated' => true ) + SFLS_Licenses::payload( $lic ) );
	}

	public function validate( WP_REST_Request $req ) {
		$limited = $this->rate_limit();
		if ( $limited ) {
			return $limited;
		}
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}

		$payload = SFLS_Licenses::payload( $lic );

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

	public function deactivate( WP_REST_Request $req ) {
		$limited = $this->rate_limit();
		if ( $limited ) {
			return $limited;
		}
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}
		SFLS_Licenses::remove_activation( (int) $lic['id'], $domain );
		return rest_ensure_response( array( 'deactivated' => true ) );
	}

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
			$response['package'] = SFLS_Releases::signed_url( $lic['license_key'], $domain );
			SFLS_Licenses::touch_activation( (int) $lic['id'], $domain, (string) $req->get_param( 'version' ) );
		}

		return rest_ensure_response( $response );
	}

	public function download( WP_REST_Request $req ) {
		$key     = strtoupper( sanitize_text_field( (string) $req->get_param( 'license_key' ) ) );
		$domain  = SFLS_Licenses::normalize_domain( (string) $req->get_param( 'site' ) );
		$expires = (int) $req->get_param( 'expires' );
		$sig     = sanitize_text_field( (string) $req->get_param( 'sig' ) );

		if ( ! SFLS_Releases::verify( $key, $domain, $expires, $sig ) ) {
			return $this->error( 403, 'invalid_signature', __( 'This download link has expired. Check for updates again.', 'subtleforms-license-server' ) );
		}
		$lic = SFLS_Licenses::find_by_key( $key );
		if ( ! $lic || ! SFLS_Licenses::state( $lic )['valid'] ) {
			return $this->error( 403, 'expired', __( 'An active license is required to download updates.', 'subtleforms-license-server' ) );
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

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
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
	 * 30 requests per minute per IP.
	 */
	private function rate_limit(): ?WP_REST_Response {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'sfls_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 30 ) {
			return $this->error( 429, 'rate_limited', __( 'Too many requests. Try again in a minute.', 'subtleforms-license-server' ) );
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
		return null;
	}
}
