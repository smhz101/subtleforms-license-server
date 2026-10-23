<?php
/**
 * Ed25519 signatures for release packages and license API answers (1.9.0).
 *
 * The key pair is made once with `wp sfls signing generate` and lives in
 * wp-config.php as two constants, never in the database:
 *
 *   define( 'SFLS_SIGNING_SECRET_KEY', '<base64, 64 bytes>' );
 *   define( 'SFLS_SIGNING_PUBLIC_KEY', '<base64, 32 bytes>' );
 *
 * Pro ships the public key. With it Pro checks two things:
 *
 * - Packages: every release gets a detached signature over
 *   "sfls-package-v1\n<slug>\n<version>\n<sha256>" when it is stored. The
 *   signature is kept next to the release (option sfls_release_signatures,
 *   public data) so a database write alone cannot sign another zip.
 *   /update/check returns package_sha256 and package_sig.
 * - Answers: every /license/*, /update/check, /update/versions,
 *   /connect/exchange and /branding answer carries signed_at, the echoed
 *   sig_nonce of the request and signature, a detached signature over
 *   "sfls-response-v1\n<route>\n<canonical JSON of the body without signature>".
 *
 * Pro versions before 2.7.0 ignore the new fields.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signing key, package signatures and response signatures.
 */
final class SFLS_Signing {

	const PACKAGE_CONTEXT  = 'sfls-package-v1';
	const RESPONSE_CONTEXT = 'sfls-response-v1';
	const SLUG             = 'subtleforms-pro';

	/**
	 * Package signatures by release ID: { id: { version, sha256, sig, signed_at } }.
	 */
	const SIGNATURES_OPTION = 'sfls_release_signatures';

	/**
	 * Log of package replacements of published releases (newest first, capped).
	 */
	const REPLACEMENTS_OPTION = 'sfls_release_replacements';
	const REPLACEMENTS_KEEP   = 100;

	/**
	 * Routes whose answers are signed.
	 */
	const SIGNED_ROUTES = '#^/sfls/v1/(license/(activate|validate|deactivate|status)|update/(check|versions)|connect/exchange|branding)$#';

	/**
	 * Secret key used by the test suite instead of the constant (base64).
	 *
	 * @var string|null
	 */
	private static ?string $test_secret = null;

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'sign_rest_response' ), 100, 3 );
	}

	// ── Keys ────────────────────────────────────────────────────────────

	/**
	 * Whether ed25519 is available (PHP sodium or WordPress sodium_compat).
	 */
	public static function sodium(): bool {
		return function_exists( 'sodium_crypto_sign_detached' ) && function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	/**
	 * A new key pair, base64 encoded.
	 *
	 * @return array{secret:string,public:string}
	 */
	public static function generate(): array {
		$pair = sodium_crypto_sign_keypair();
		return array(
			'secret' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Key encoding.
			'public' => base64_encode( sodium_crypto_sign_publickey( $pair ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Key encoding.
		);
	}

	/**
	 * Raw secret key, '' when none is configured or it is malformed.
	 */
	private static function secret_key(): string {
		$encoded = null !== self::$test_secret ? self::$test_secret : ( defined( 'SFLS_SIGNING_SECRET_KEY' ) ? (string) SFLS_SIGNING_SECRET_KEY : '' );
		$raw     = '' !== $encoded ? base64_decode( $encoded, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Key encoding.
		return ( is_string( $raw ) && 64 === strlen( $raw ) ) ? $raw : '';
	}

	/**
	 * Whether this server can sign.
	 */
	public static function enabled(): bool {
		return self::sodium() && '' !== self::secret_key();
	}

	/**
	 * Public key (base64) of the configured secret key, '' when none.
	 */
	public static function public_key(): string {
		$secret = self::secret_key();
		if ( '' === $secret || ! self::sodium() ) {
			return '';
		}
		return base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Key encoding.
	}

	/**
	 * Whether SFLS_SIGNING_PUBLIC_KEY, when defined, belongs to the secret key.
	 */
	public static function public_key_matches(): bool {
		if ( ! defined( 'SFLS_SIGNING_PUBLIC_KEY' ) || null !== self::$test_secret ) {
			return true;
		}
		return hash_equals( self::public_key(), (string) SFLS_SIGNING_PUBLIC_KEY );
	}

	/**
	 * Use this secret key (base64) instead of the constant. Test suite only.
	 *
	 * @param string|null $secret Base64 secret key, null to go back to the constant.
	 */
	public static function use_test_key( ?string $secret ): void {
		if ( defined( 'SFLS_TESTING' ) && SFLS_TESTING ) {
			self::$test_secret = $secret;
		}
	}

	// ── Signing ─────────────────────────────────────────────────────────

	/**
	 * Detached signature (base64) of a message, '' when signing is off.
	 *
	 * @param string $message Message.
	 */
	public static function sign( string $message ): string {
		if ( ! self::enabled() ) {
			return '';
		}
		return base64_encode( sodium_crypto_sign_detached( $message, self::secret_key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Signature encoding.
	}

	/**
	 * Check a detached signature.
	 *
	 * @param string $message    Message.
	 * @param string $signature  Base64 signature.
	 * @param string $public_key Base64 public key.
	 */
	public static function verify( string $message, string $signature, string $public_key ): bool {
		$sig = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Signature encoding.
		$pk  = base64_decode( $public_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Key encoding.
		if ( ! self::sodium() || ! is_string( $sig ) || 64 !== strlen( $sig ) || ! is_string( $pk ) || 32 !== strlen( $pk ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, $message, $pk );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * What a package signature covers.
	 *
	 * @param string $version Version.
	 * @param string $sha256  Lowercase hex sha256 of the zip.
	 */
	public static function package_message( string $version, string $sha256 ): string {
		return self::PACKAGE_CONTEXT . "\n" . self::SLUG . "\n" . $version . "\n" . strtolower( $sha256 );
	}

	/**
	 * What a response signature covers.
	 *
	 * @param string $route Route, like /sfls/v1/license/validate.
	 * @param array  $body  Body without the signature field.
	 */
	public static function response_message( string $route, array $body ): string {
		unset( $body['signature'] );
		return self::RESPONSE_CONTEXT . "\n" . $route . "\n" . self::canonical( $body );
	}

	/**
	 * Canonical JSON: objects with sorted keys, no escaped slashes or unicode.
	 * Pro builds the same string from the decoded body.
	 *
	 * @param mixed $value Value decoded from JSON (arrays, scalars).
	 */
	public static function canonical( $value ): string {
		return (string) wp_json_encode( self::sorted( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * Sort object keys recursively.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::sorted( $v );
		}
		return $value;
	}

	// ── Packages ────────────────────────────────────────────────────────

	/**
	 * Stored package signatures.
	 *
	 * @return array<int,array{version:string,sha256:string,sig:string,signed_at:int}>
	 */
	private static function signatures(): array {
		return (array) get_option( self::SIGNATURES_OPTION, array() );
	}

	/**
	 * Sign the package of a release and keep the signature. Returns false
	 * when signing is off or the release has no sha256.
	 *
	 * @param array $release Release.
	 */
	public static function sign_release( array $release ): bool {
		$sha = strtolower( (string) ( $release['sha256'] ?? '' ) );
		$id  = (int) ( $release['id'] ?? 0 );
		if ( ! self::enabled() || $id <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
			return false;
		}
		$all        = self::signatures();
		$all[ $id ] = array(
			'version'   => (string) $release['version'],
			'sha256'    => $sha,
			'sig'       => self::sign( self::package_message( (string) $release['version'], $sha ) ),
			'signed_at' => time(),
		);
		update_option( self::SIGNATURES_OPTION, $all, false );
		return true;
	}

	/**
	 * Package signature of a release, '' when it was never signed or its
	 * package changed since.
	 *
	 * @param array $release Release.
	 */
	public static function release_signature( array $release ): string {
		$entry = self::signatures()[ (int) ( $release['id'] ?? 0 ) ] ?? null;
		if ( ! is_array( $entry ) ) {
			return '';
		}
		$sha = strtolower( (string) ( $release['sha256'] ?? '' ) );
		if ( '' === $sha || ! hash_equals( (string) $entry['sha256'], $sha ) || (string) $entry['version'] !== (string) $release['version'] ) {
			return '';
		}
		return (string) $entry['sig'];
	}

	/**
	 * Forget the signature of a deleted release.
	 *
	 * @param int $id Release ID.
	 */
	public static function forget( int $id ): void {
		$all = self::signatures();
		if ( isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( self::SIGNATURES_OPTION, $all, false );
		}
	}

	/**
	 * Sign every release whose zip on disk still matches its sha256 (for
	 * releases stored before signing was set up).
	 *
	 * @param bool $resign Sign again even when a valid signature exists.
	 * @return array{signed:string[],skipped:string[],mismatch:string[]}
	 */
	public static function sign_all( bool $resign = false ): array {
		$out = array(
			'signed'   => array(),
			'skipped'  => array(),
			'mismatch' => array(),
		);
		foreach ( SFLS_Releases::rows() as $release ) {
			if ( ! $resign && '' !== self::release_signature( $release ) ) {
				$out['skipped'][] = $release['version'];
				continue;
			}
			$path = SFLS_Releases::has_package( $release ) ? SFLS_Releases::package_path( $release ) : '';
			if ( '' === $path || ! hash_equals( strtolower( (string) $release['sha256'] ), (string) hash_file( 'sha256', $path ) ) ) {
				$out['mismatch'][] = $release['version'];
				continue;
			}
			if ( self::sign_release( $release ) ) {
				$out['signed'][] = $release['version'];
			}
		}
		return $out;
	}

	/**
	 * Record that a published package was replaced.
	 *
	 * @param array  $old    Release before.
	 * @param string $sha256 New sha256.
	 * @param string $source upload | api | github | cli.
	 */
	public static function log_replacement( array $old, string $sha256, string $source ): void {
		$entry = array(
			'at'      => current_time( 'mysql', true ),
			'version' => (string) $old['version'],
			'status'  => (string) $old['status'],
			'old'     => (string) $old['sha256'],
			'new'     => $sha256,
			'source'  => $source,
			'user'    => get_current_user_id(),
			'ip'      => class_exists( 'SFLS_Licenses' ) ? SFLS_Licenses::client_ip() : '',
		);
		$log   = (array) get_option( self::REPLACEMENTS_OPTION, array() );
		array_unshift( $log, $entry );
		update_option( self::REPLACEMENTS_OPTION, array_slice( $log, 0, self::REPLACEMENTS_KEEP ), false );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Audit line for the server log.
		error_log( sprintf( 'SFLS: package of published release %s replaced (%s to %s) via %s by user %d from %s.', $entry['version'], $entry['old'], $entry['new'], $source, $entry['user'], $entry['ip'] ) );
	}

	/**
	 * Logged replacements, newest first.
	 *
	 * @return array[]
	 */
	public static function replacements(): array {
		return (array) get_option( self::REPLACEMENTS_OPTION, array() );
	}

	// ── Responses ───────────────────────────────────────────────────────

	/**
	 * Add package fields and the signature to license API answers.
	 *
	 * @param mixed           $response WP_REST_Response, WP_Error or data.
	 * @param mixed           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function sign_rest_response( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request || ! preg_match( self::SIGNED_ROUTES, (string) $request->get_route() ) ) {
			return $response;
		}
		if ( is_wp_error( $response ) || ( ! $response instanceof WP_REST_Response && ! is_array( $response ) ) ) {
			return $response;
		}
		$response = rest_ensure_response( $response );
		$data     = $response->get_data();
		if ( is_object( $data ) ) {
			$data = json_decode( (string) wp_json_encode( $data ), true );
		}
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$before = $data;
		if ( '/sfls/v1/update/check' === $request->get_route() ) {
			$data = self::with_package_fields( $data );
		}
		if ( ! self::enabled() ) {
			// Keep the original data (an empty answer stays the object {}).
			if ( $data !== $before ) {
				$response->set_data( $data );
			}
			return $response;
		}

		$data['signed_at'] = time();
		$nonce             = (string) $request->get_param( 'sig_nonce' );
		if ( preg_match( '/^[A-Za-z0-9_\-]{16,64}$/', $nonce ) ) {
			$data['sig_nonce'] = $nonce;
		}
		unset( $data['signature'] );
		// Sign what Pro will decode: the JSON round trip turns objects into
		// arrays exactly as json_decode( ..., true ) does on the other side.
		$decoded           = json_decode( (string) wp_json_encode( $data ), true );
		$data['signature'] = self::sign( self::response_message( (string) $request->get_route(), is_array( $decoded ) ? $decoded : array() ) );
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Add package_sha256 and package_sig of the offered version.
	 *
	 * @param array $data update/check answer.
	 */
	private static function with_package_fields( array $data ): array {
		$version = (string) ( $data['new_version'] ?? '' );
		if ( '' === $version ) {
			return $data;
		}
		$release = SFLS_Releases::get( $version );
		if ( ! $release || '' === (string) $release['sha256'] ) {
			return $data;
		}
		$data['package_sha256'] = strtolower( (string) $release['sha256'] );
		$sig                    = self::release_signature( $release );
		if ( '' !== $sig ) {
			$data['package_sig'] = $sig;
		}
		return $data;
	}
}

SFLS_Signing::register();
