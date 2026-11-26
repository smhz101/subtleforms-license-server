<?php
/**
 * Public license API used by SubtleForms Pro.
 *
 *   POST /wp-json/sfls/v1/license/activate    { license_key, site_url }
 *   POST /wp-json/sfls/v1/license/validate    { license_key, site_url }
 *   POST /wp-json/sfls/v1/license/deactivate  { license_key, site_url }
 *   GET  /wp-json/sfls/v1/license/status      ?license_key&site_url
 *   GET  /wp-json/sfls/v1/update/check        ?license_key&site_url&version&slug[&channel=beta], or with products={"<slug>":"<version>"} for every installed product (answer: { products: { <slug>: {...} } })
 *   GET  /wp-json/sfls/v1/update/package      ?lid&ptoken&site_url[&channel][&version][&product], or ?license_key&site_url (redirects to a fresh signed link)
 *   GET  /wp-json/sfls/v1/update/download     ?lid&site&expires&token&sig[&version][&product] (15 minutes, single use)
 *   GET  /wp-json/sfls/v1/extensions          published extension catalog (open, signed)
 *   GET  /wp-json/sfls/v1/update/versions     ?license_key&site_url[&channel=beta] (versions this license may download)
 *   POST /wp-json/sfls/v1/branding            { license_key, site_url[, version] } (white-label profile, Agency plan)
 *   POST /wp-json/sfls/v1/connect/exchange    { code, site_url }
 *
 * Every response is JSON except download. Errors use a 4xx status with
 * { error, reason }, which is what the Pro client maps to a license state.
 * activate, validate, status, package, versions and branding are limited per IP (30
 * requests per 10 minutes). update/check has its own bucket per IP (60 per 10
 * minutes) and counts unknown keys as failures. Requests from a site the valid key is activated
 * on are also limited per key and site; every other request is checked
 * against a budget of failed requests per key, address and site, and a
 * high ceiling per key (see guarded()). Over a limit they answer 429 with
 * Retry-After.
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
	 * Requests per window of each bucket ('ip' is the shared bucket of the
	 * license routes and the fallback for a bucket not listed): releasing a
	 * seat on a replaced key (its own bucket, so a key switch is never
	 * refused by the shared one), update checks (every site checks on its own
	 * schedule, and many sit behind one address) and the order status polling of the checkout page
	 * (a request every 2 seconds for up to 2 minutes), plus an
	 * address-wide ceiling for that polling that varying the order or
	 * transaction cannot get around.
	 */
	const DEFAULT_LIMITS = array(
		'ip'      => 30,
		'release' => 60,
		'update'  => 60,
		'poll'    => 240,
		'pollip'  => 600,
	);

	/**
	 * Hook routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'rest_api_init', array( $this, 'extension_routes' ) );
	}

	/**
	 * Register the public extension catalog route.
	 */
	public function extension_routes(): void {
		register_rest_route(
			self::NS,
			'/extensions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'extensions' ),
				'permission_callback' => '__return_true', // Public and signed; the catalog is not secret.
			)
		);
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
		register_rest_route( self::NS, '/branding', array( 'methods' => 'POST', 'callback' => array( $this, 'branding' ), 'permission_callback' => $open ) );
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
		[ $lic, $domain, $error ] = $this->resolve( $req, true );
		if ( $error ) {
			return $error;
		}

		$payload = SFLS_Licenses::payload( $lic, $domain );

		if ( ! SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			// Same shape as every other error (valid, reason, error). It stays
			// HTTP 200, which Pro reads as "inactive" rather than "invalid", and
			// keeps "message" for Pro versions that read that field (LS-10).
			$removed = SFLS_Licenses::was_removed( (int) $lic['id'], $domain );
			$text    = $removed
				? __( 'This site was removed from the license in your account.', 'subtleforms-license-server' )
				: __( 'This site is not activated for this license.', 'subtleforms-license-server' );
			return rest_ensure_response(
				array(
					'valid'   => false,
					'reason'  => $removed ? 'site_removed' : 'domain_not_activated',
					'error'   => $text,
					'message' => $text,
				) + array_diff_key( self::with_data( $payload ), array( 'valid' => 1, 'status' => 1 ) )
			);
		}

		SFLS_Licenses::touch_activation( (int) $lic['id'], $domain );

		if ( ! $payload['valid'] ) {
			$payload['reason'] = $payload['status'];
		}
		$extensions = SFLS_Extensions::entitlement( $lic );
		if ( $extensions ) {
			$payload['extensions'] = $extensions;
		}

		return rest_ensure_response( self::with_data( $payload ) );
	}

	/**
	 * Add the `data` object that carries the branding version (1.8.0). The
	 * flat field stays for Pro versions that read the license fields from the
	 * top level of the answer.
	 *
	 * @param array $payload License payload.
	 */
	private static function with_data( array $payload ): array {
		$payload['data'] = array( 'branding_version' => (int) ( $payload['branding_version'] ?? 0 ) );
		return $payload;
	}

	/**
	 * Branding endpoint: the white-label profile of the license (Agency
	 * plan) for a site the key is activated on.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function branding( WP_REST_Request $req ) {
		return $this->guarded( $req, array( $this, 'branding_now' ) );
	}

	/**
	 * Branding endpoint after the rate limit. Answers like validate does for
	 * a missing or unknown key, and refuses a license that is not active or a
	 * site that is not activated with the same error shape. The site's
	 * `version` is the one it applied last; when it is the current one the
	 * answer has `unchanged` and no profile.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function branding_now( WP_REST_Request $req ) {
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error ) {
			return $error;
		}
		$sent = $req->get_param( 'version' );
		if ( null !== $sent && '' !== $sent && ( is_bool( $sent ) || ! is_numeric( $sent ) || (float) $sent < 0 || (float) $sent !== (float) (int) $sent ) ) {
			return $this->error( 400, 'invalid_version', __( 'version must be a whole number.', 'subtleforms-license-server' ) );
		}
		$sent  = null === $sent || '' === $sent ? null : (int) $sent;
		$state = SFLS_Licenses::state( $lic );
		if ( ! $state['valid'] ) {
			return $this->error(
				403,
				$state['status'],
				'expired' === $state['status']
					? __( 'This license has expired. Renew it from your SubtleForms account.', 'subtleforms-license-server' )
					: __( 'This license is not active. Contact SubtleForms support.', 'subtleforms-license-server' )
			);
		}
		if ( ! SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			return $this->error( 403, 'domain_not_activated', __( 'This site is not activated for this license.', 'subtleforms-license-server' ) );
		}
		SFLS_Licenses::touch_activation( (int) $lic['id'], $domain );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => SFLS_Branding::answer( $lic, $domain, sanitize_text_field( (string) $req->get_param( 'site_url' ) ), $sent ),
			)
		);
	}

	/**
	 * Deactivate endpoint.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function deactivate( WP_REST_Request $req ) {
		// Its own bucket, and not the shared 'ip' one: freeing a seat is cheap
		// and repeating it changes nothing, and a key switch fires several
		// other requests from the same address first. Sites that share an
		// address (one host) must never be refused when they free a seat.
		$limited = $this->rate_limit( 'release', SFLS_Licenses::client_ip() );
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
	 * Public catalog of the published extensions. Open (no key), signed like
	 * a license answer (sign_rest_response), cached for five minutes.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function extensions( WP_REST_Request $req ) {
		unset( $req );
		$limited = $this->rate_limit( 'update', SFLS_Licenses::client_ip() );
		if ( $limited ) {
			return $limited;
		}
		return rest_ensure_response( array( 'extensions' => SFLS_Extensions::public_catalog() ) );
	}

	/**
	 * The product a package or download request is for: Pro unless named.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	private static function product( WP_REST_Request $req ): string {
		$product = substr( sanitize_key( (string) $req->get_param( 'product' ) ), 0, 64 );
		return '' === $product ? SFLS_Releases::PRO : $product;
	}

	/**
	 * Why a license may not download an extension, or null when it may. Pro
	 * is not checked here: it keeps its own rules.
	 *
	 * @param array  $lic     License.
	 * @param string $domain  Normalised domain.
	 * @param string $product Extension slug.
	 */
	private function extension_refusal( array $lic, string $domain, string $product ): ?WP_REST_Response {
		if ( ! SFLS_Licenses::state( $lic )['valid'] ) {
			return $this->error( 403, 'licence_inactive', __( 'An active license is required to download extensions.', 'subtleforms-license-server' ) );
		}
		if ( ! SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			return $this->error( 403, 'site_not_activated', __( 'This site is not activated for this license.', 'subtleforms-license-server' ) );
		}
		if ( ! SFLS_Extensions::may_download( $lic, $product ) ) {
			return $this->error( 403, 'not_entitled', __( 'This extension is not included in your plan.', 'subtleforms-license-server' ) );
		}
		return null;
	}

	/**
	 * The products of a batched update check: slug => installed version, or
	 * null when the request has none (the old single-product check), or false
	 * when `products` is not a JSON object of strings.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array<string,string>|false|null
	 */
	private static function requested_products( WP_REST_Request $req ) {
		$raw = $req->get_param( 'products' );
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) || ( $raw && array_is_list( $raw ) ) ) {
			return false;
		}
		$out = array();
		foreach ( $raw as $slug => $version ) {
			if ( ! is_scalar( $version ) || is_bool( $version ) ) {
				return false;
			}
			$slug = substr( sanitize_key( (string) $slug ), 0, 64 );
			if ( '' !== $slug ) {
				$out[ $slug ] = sanitize_text_field( (string) $version );
			}
		}
		return $out;
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
		// Its own bucket: update checks never use up the license API's allowance of an address.
		$ip      = SFLS_Licenses::client_ip();
		$limited = $this->rate_limit( 'update', $ip );
		if ( $limited ) {
			return $limited;
		}
		$pair    = strtoupper( sanitize_text_field( (string) $req->get_param( 'license_key' ) ) ) . '|' . $ip . '|' . SFLS_Licenses::normalize_domain( (string) $req->get_param( 'site_url' ) );
		$limited = $this->rate_limit( 'pair', $pair, false );
		if ( $limited ) {
			return $limited;
		}

		$products = self::requested_products( $req );
		if ( false === $products ) {
			return $this->error( 400, 'invalid_products', __( 'products must be a JSON object of product slug and installed version.', 'subtleforms-license-server' ) );
		}
		if ( null !== $products ) {
			return $this->update_check_products( $req, $products, $pair );
		}

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
		if ( $error && 404 === $error->get_status() ) {
			// A key that does not exist counts as a failure, so the answer cannot be used to test keys in bulk.
			$this->rate_limit( 'pair', $pair, true );
		}
		if ( ! $error && SFLS_Licenses::state( $lic )['valid'] && SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			// Pro caches this answer for hours, longer than a signed link lives,
			// so the package is a stable address that hands out a fresh signed
			// link at download time (see package()).
			// The address names the version this answer offers (1.9.2), so the
			// download is the package whose sha256 and signature are in this
			// answer even when a newer release is published before the site
			// installs it. Addresses cached before 1.9.2 have no version and
			// still get the current release.
			$extra = array( 'version' => $release['version'] );
			if ( 'beta' === $channel ) {
				$extra['channel'] = 'beta';
			}
			$response['package'] = self::package_address( $lic, $domain, $extra );
			SFLS_Licenses::touch_activation( (int) $lic['id'], $domain, (string) $req->get_param( 'version' ) );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Batched update check: the newer published release of each product the
	 * license may download, for a site it is activated on. A product the
	 * license may not download, or that has nothing newer, is left out.
	 *
	 * @param WP_REST_Request      $req      Request.
	 * @param array<string,string> $products Product slug => installed version.
	 * @param string               $pair     Rate limit key of key, address and site.
	 * @return WP_REST_Response
	 */
	private function update_check_products( WP_REST_Request $req, array $products, string $pair ) {
		[ $lic, $domain, $error ] = $this->resolve( $req );
		if ( $error && 404 === $error->get_status() ) {
			$this->rate_limit( 'pair', $pair, true );
		}
		$answer = array();
		if ( ! $error && SFLS_Licenses::state( $lic )['valid'] && SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			$channel = self::channel( $req );
			foreach ( $products as $slug => $installed ) {
				if ( SFLS_Releases::PRO !== $slug && ! SFLS_Extensions::may_download( $lic, $slug ) ) {
					continue;
				}
				$release = SFLS_Releases::latest( $channel, true, $slug );
				if ( ! $release || ! SFLS_Releases::has_package( $release ) || ! version_compare( (string) $release['version'], $installed, '>' ) ) {
					continue;
				}
				$extra = array( 'version' => $release['version'] );
				if ( 'beta' === $channel ) {
					$extra['channel'] = 'beta';
				}
				if ( SFLS_Releases::PRO !== $slug ) {
					$extra['product'] = $slug;
				}
				$answer[ $slug ] = array(
					'version'       => $release['version'],
					'package'       => self::package_address( $lic, $domain, $extra, $slug ),
					'requires'      => $release['requires'],
					'requires_php'  => $release['requires_php'],
					'requires_free' => null === $release['requires_free'] ? null : (string) $release['requires_free'],
					'requires_pro'  => null === $release['requires_pro'] ? null : (string) $release['requires_pro'],
					'tested'        => $release['tested'],
					'changelog'     => SFLS_Releases::changelog_html( $release ),
					'sha256'        => strtolower( (string) $release['sha256'] ),
					'package_sig'   => SFLS_Signing::release_signature( $release ),
				);
			}
			SFLS_Licenses::touch_activation( (int) $lic['id'], $domain, $products[ SFLS_Releases::PRO ] ?? '' );
		}
		return rest_ensure_response( array( 'products' => (object) $answer ) );
	}

	/**
	 * Package endpoint: checks the license and the site, then redirects to a
	 * newly signed download link that works once within 15 minutes. Without
	 * a version (addresses cached before 1.9.2) it serves the latest release
	 * of the channel and needs a valid license. With a version, which the
	 * update check puts in the address since 1.9.2, it serves exactly that
	 * release when the license may download it (an expired license keeps the
	 * versions released while it was valid). A version that was yanked since
	 * answers 410.
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
	 * @param array  $extra  channel, version, product.
	 * @param string $product Product slug the address is for (Pro unless an extension).
	 */
	public static function package_address( array $lic, string $domain, array $extra = array(), string $product = SFLS_Releases::PRO ): string {
		$args = array(
			'lid'      => (int) $lic['id'],
			'site_url' => rawurlencode( $domain ),
			'ptoken'   => SFLS_Releases::package_token( $lic, $domain, $product ),
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
		if ( $lic && '' !== $domain && preg_match( '/^[a-f0-9]{64}$/', $token ) && SFLS_Releases::package_token_valid( $lic, $domain, $token, self::product( $req ) ) ) {
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
		$product = self::product( $req );
		if ( SFLS_Releases::PRO !== $product ) {
			return $this->package_extension( $req, $lic, $domain, $product );
		}
		$version = sanitize_text_field( (string) $req->get_param( 'version' ) );
		if ( '' === $version && ! SFLS_Licenses::state( $lic )['valid'] ) {
			return $this->error( 403, 'expired', __( 'An active license is required to download updates.', 'subtleforms-license-server' ) );
		}
		if ( ! SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			return $this->error( 403, 'domain_not_activated', __( 'This site is not activated for this license.', 'subtleforms-license-server' ) );
		}
		$release = '' === $version ? SFLS_Releases::latest( self::channel( $req ) ) : SFLS_Releases::find( $version );
		if ( '' !== $version && self::is_withdrawn( $version ) ) {
			return $this->withdrawn();
		}
		if ( ! $release || ! SFLS_Releases::has_package( $release ) ) {
			return $this->error( 404, 'no_package', __( 'No package is available.', 'subtleforms-license-server' ) );
		}
		if ( '' !== $version ) {
			// A stable site cannot fetch a beta by editing version=. The answer is 410, so a site that
			// left the beta channel after it cached the address clears it and checks again.
			if ( 'beta' === $release['channel'] && 'beta' !== self::channel( $req ) ) {
				return $this->error( 410, 'wrong_channel', __( 'This version is not on your update channel. Check for updates again to get the current version.', 'subtleforms-license-server' ) );
			}
			// "Make this the latest" rolled back below this version after the site checked: do not send it.
			if ( SFLS_Releases::above_pin( $release ) ) {
				return $this->withdrawn();
			}
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
	 * Package endpoint for an extension: the license must be valid or in
	 * grace, the site activated, and the extension in the license's plan.
	 * The redirect goes to a signed link that names the product.
	 *
	 * @param WP_REST_Request $req     Request.
	 * @param array           $lic     License.
	 * @param string          $domain  Normalised domain.
	 * @param string          $product Extension slug.
	 * @return WP_REST_Response
	 */
	private function package_extension( WP_REST_Request $req, array $lic, string $domain, string $product ) {
		$refused = $this->extension_refusal( $lic, $domain, $product );
		if ( $refused ) {
			return $refused;
		}
		$version = sanitize_text_field( (string) $req->get_param( 'version' ) );
		if ( '' !== $version && self::is_withdrawn( $version, $product ) ) {
			return $this->withdrawn();
		}
		$release = '' === $version ? SFLS_Releases::latest( self::channel( $req ), true, $product ) : SFLS_Releases::find( $version, $product );
		if ( ! $release || ! SFLS_Releases::has_package( $release ) ) {
			return $this->error( 404, 'no_package', __( 'No package is available.', 'subtleforms-license-server' ) );
		}
		if ( '' !== $version && 'beta' === $release['channel'] && 'beta' !== self::channel( $req ) ) {
			return $this->error( 410, 'wrong_channel', __( 'This version is not on your update channel. Check for updates again to get the current version.', 'subtleforms-license-server' ) );
		}
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', SFLS_Releases::signed_url( $lic['license_key'], $domain, $release['version'], $product ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Whether a release exists and is no longer published: yanked, or taken
	 * back to a draft ("Unpublish to draft"). A version that never existed is
	 * not withdrawn.
	 *
	 * @param string $version Version.
	 * @param string $product Product slug.
	 */
	private static function is_withdrawn( string $version, string $product = SFLS_Releases::PRO ): bool {
		$release = SFLS_Releases::get( $version, false, $product );
		return $release && ! SFLS_Releases::is_live( $release );
	}

	/**
	 * The answer for a version that was offered and has been withdrawn since.
	 * 410 tells the site this will not come back, and what to do.
	 */
	private function withdrawn(): WP_REST_Response {
		return $this->error( 410, 'version_withdrawn', __( 'This version was withdrawn after your site last checked for updates. Check for updates again to get the current version.', 'subtleforms-license-server' ) );
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
		$product = self::product( $req );

		if ( ! SFLS_Releases::verify( $key, $domain, $expires, $sig, $token, $version, $product ) ) {
			return $this->error( 403, 'invalid_signature', __( 'This download link has expired. Check for updates again.', 'subtleforms-license-server' ) );
		}
		$lic = SFLS_Licenses::find_by_key( $key );
		if ( $lic && SFLS_Releases::PRO !== $product ) {
			// Checked again at download time, before the link is used up: the license may have lapsed since the link was made.
			$refused = $this->extension_refusal( $lic, $domain, $product );
			if ( $refused ) {
				return $refused;
			}
		}
		$release = '' === $version ? SFLS_Releases::latest( 'stable', true, $product ) : SFLS_Releases::find( $version, $product );
		if ( $lic && '' !== $version && self::is_withdrawn( $version, $product ) ) {
			return $this->withdrawn();
		}
		if ( ! $lic || ( $release && ! SFLS_Releases::allowed( $lic, $release ) ) || ( ! $release && ! SFLS_Licenses::state( $lic )['valid'] ) ) {
			return $this->error( 403, 'expired', __( 'An active license is required to download updates.', 'subtleforms-license-server' ) );
		}
		if ( ! $release || ! SFLS_Releases::has_package( $release ) ) {
			return $this->error( 404, 'no_package', __( 'No package is available.', 'subtleforms-license-server' ) );
		}
		if ( '' !== $version && SFLS_Releases::above_pin( $release ) ) {
			// A link signed before "Make this the latest" rolled back below this version: do not serve it.
			// Checked before the token is used up, so the refused request does not burn the link.
			return $this->withdrawn();
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
		// Pro 2.8.2 and later keep the path of the site; older versions are known by their host.
		$domain = SFLS_Connect::site_identity( esc_url_raw( (string) $req->get_param( 'site_url' ), array( 'http', 'https' ) ), (string) $req->get_param( 'identity' ) );
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
	 * @param WP_REST_Request $req     Request.
	 * @param bool            $upgrade Whether a quiet host-only row may be handed to this host/path
	 *                                 (validate only; activate does it itself, and deactivate never does).
	 * @return array{0:?array,1:string,2:?WP_REST_Response}
	 */
	private function resolve( WP_REST_Request $req, bool $upgrade = false ): array {
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

		// A site in a subdirectory that was activated by host only (before 1.9.2)
		// takes over its quiet host-only row the first time it validates with its
		// path. Deactivate never does this: a copy must not be able to rename the
		// seat of the site it was copied from and then delete it.
		if ( $upgrade ) {
			SFLS_Licenses::upgrade_legacy_activation( (int) $lic['id'], $domain );
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
	 * Rate limits for activate, validate, status, package, versions and
	 * branding.
	 *
	 * Every request counts against its IP. Then, by what the request is:
	 *
	 * - A valid license asked by a site it is activated on counts only
	 *   against that key and site.
	 * - A valid license asked by a site that is not activated yet but could
	 *   be (a free seat, or a local or staging site) is checked against the
	 *   failure budget of its own address and site pair. Failures of other
	 *   addresses and sites never block it, however many there are (LS-06).
	 * - Any other request (unknown key, license not active, no free seat)
	 *   is checked against the failure budget of its address and site pair
	 *   and against a high ceiling for the key as a whole; failures count
	 *   towards both. The ceiling is ten times the limit, times the number
	 *   of sites the license is activated on (at least one, at most its site
	 *   limit), so a big license is not cut off sooner than a small one.
	 *
	 * @param WP_REST_Request $req     Request.
	 * @param callable        $handler Endpoint.
	 * @return WP_REST_Response
	 */
	private function guarded( WP_REST_Request $req, callable $handler ) {
		$ip      = SFLS_Licenses::client_ip();
		$limited = $this->rate_limit( 'ip', $ip );
		if ( $limited ) {
			return $limited;
		}
		$key = strtoupper( sanitize_text_field( (string) $req->get_param( 'license_key' ) ) );
		if ( '' === $key ) {
			return $handler( $req );
		}
		$domain = SFLS_Licenses::normalize_domain( (string) $req->get_param( 'site_url' ) );
		$lic    = SFLS_Licenses::find_by_key( $key );
		$valid  = $lic && '' !== $domain && SFLS_Licenses::state( $lic )['valid'];
		if ( $valid && SFLS_Licenses::activation( (int) $lic['id'], $domain ) ) {
			$limited = $this->rate_limit( 'site', $key . '|' . $domain );
			return $limited ? $limited : $handler( $req );
		}

		$pair    = $key . '|' . $ip . '|' . $domain;
		$limited = $this->rate_limit( 'pair', $pair, false );
		if ( $limited ) {
			return $limited;
		}
		// A site that can still be activated is not counted against the key.
		$newcomer = $valid && ( SFLS_Licenses::has_seat_for( $lic, $domain ) || SFLS_Licenses::is_local_domain( $domain ) );
		$scale    = $lic ? self::failure_scale( $lic ) * self::CEILING_FACTOR : self::CEILING_FACTOR;
		if ( ! $newcomer ) {
			$limited = $this->rate_limit( 'ceiling', $key, false, $scale );
			if ( $limited ) {
				return $limited;
			}
		}
		$response = rest_ensure_response( $handler( $req ) );
		if ( self::failed( $response ) ) {
			$this->rate_limit( 'pair', $pair, true );
			if ( ! $newcomer ) {
				$this->rate_limit( 'ceiling', $key, true, $scale );
			}
		}
		return $response;
	}

	/**
	 * How many times the per-pair limit the ceiling of failures for one key
	 * is, before it is scaled by the sites of the license.
	 */
	const CEILING_FACTOR = 10;

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
	 * The counter is one row per bucket in wp_sfls_rate_limits and is raised
	 * with a single UPDATE, so requests that arrive together are all counted
	 * (transients lose counts under load).
	 *
	 * Local sites (WP_ENVIRONMENT_TYPE local) skip it unless the
	 * `sfls_rate_limit_enabled` filter says otherwise, so development and
	 * automated tests are not throttled.
	 *
	 * @param string $type  ip | site (key and site) | pair (failures of key, address and site) | ceiling (failures of a key) | update (update checks per address) | poll (checkout status polling per address and order).
	 * @param string $value IP address, "key|domain", "key|ip|domain" or license key.
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
				'limit'  => self::DEFAULT_LIMITS[ $type ] ?? 30,
				'window' => 10 * MINUTE_IN_SECONDS,
			)
		);
		$limit  = max( 1, (int) $conf['limit'] ) * max( 1, $scale );
		$window = max( 1, (int) $conf['window'] );
		$now    = time();
		$bucket = self::bucket_name( $type, $value );

		$state = $count ? self::hit( $bucket, $window, $now ) : self::peek( $bucket, $window, $now );
		// Counting raises the counter first, so the request that makes it exceed the limit is the first one refused.
		if ( $state['hits'] > $limit || ( ! $count && $state['hits'] >= $limit ) ) {
			$retry    = max( 1, $state['start'] + $window - $now );
			$response = $this->error( 429, 'rate_limited', __( 'Too many requests. Try again in a few minutes.', 'subtleforms-license-server' ) );
			$response->header( 'Retry-After', (string) $retry );
			return $response;
		}
		return null;
	}

	/**
	 * Key of a counter row.
	 *
	 * @param string $type  Bucket type.
	 * @param string $value Bucket value.
	 */
	public static function bucket_name( string $type, string $value ): string {
		return md5( $type . '|' . ( '' === $value ? 'unknown' : $value ) );
	}

	/**
	 * Current count of a bucket without counting.
	 *
	 * @param string $bucket Row key.
	 * @param int    $window Window length in seconds.
	 * @param int    $now    Current time.
	 * @return array{start:int,hits:int}
	 */
	private static function peek( string $bucket, int $window, int $now ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT window_start, hits FROM {$wpdb->prefix}sfls_rate_limits WHERE bucket = %s", $bucket ), ARRAY_A );
		if ( ! $row || (int) $row['window_start'] + $window <= $now ) {
			return array(
				'start' => $now,
				'hits'  => 0,
			);
		}
		return array(
			'start' => (int) $row['window_start'],
			'hits'  => (int) $row['hits'],
		);
	}

	/**
	 * Count one request in a bucket and return the count of its window. Each
	 * step is one atomic statement: raise the counter of a running window;
	 * else start a new window on an ended one; else create the row (another
	 * request may create it first, then raise it).
	 *
	 * @param string $bucket Row key.
	 * @param int    $window Window length in seconds.
	 * @param int    $now    Current time.
	 * @return array{start:int,hits:int}
	 */
	private static function hit( string $bucket, int $window, int $now ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfls_rate_limits';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		for ( $try = 0; $try < 3; $try++ ) {
			$raised = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1 WHERE bucket = %s AND window_start > %d", $bucket, $now - $window ) );
			if ( ! $raised ) {
				$raised = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = 1, window_start = %d WHERE bucket = %s AND window_start <= %d", $now, $bucket, $now - $window ) );
			}
			if ( ! $raised ) {
				$raised = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} ( bucket, window_start, hits ) VALUES ( %s, %d, 1 )", $bucket, $now ) );
			}
			if ( $raised ) {
				break;
			}
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT window_start, hits FROM {$table} WHERE bucket = %s", $bucket ), ARRAY_A );
		// phpcs:enable
		if ( ! $row ) {
			return array(
				'start' => $now,
				'hits'  => 1,
			);
		}
		return array(
			'start' => (int) $row['window_start'],
			'hits'  => (int) $row['hits'],
		);
	}
}
