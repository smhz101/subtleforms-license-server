<?php
/**
 * GitHub connection: a release published on GitHub becomes a Pro release.
 *
 *   POST /wp-json/sfls/v1/github/webhook
 *
 * The webhook checks X-Hub-Signature-256 (HMAC-SHA256 of the raw body with
 * the webhook secret), answers "ping", and handles the "release" event
 * (actions published and released; a pre-release goes to the beta channel).
 * It finds the zip asset that matches the asset name pattern, downloads it
 * through the GitHub API (Accept: application/octet-stream, with the token
 * for private repositories), checks that the tag matches the plugin Version
 * header and stores it with source github.
 *
 * Every delivery is recorded (with its payload, so it can be retried from
 * Licenses › Settings › Releases).
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * GitHub releases.
 */
final class SFLS_GitHub {

	const OPTION = 'sfls_github';
	const API    = 'https://api.github.com';

	/**
	 * Deliveries kept in the log.
	 */
	const KEEP = 200;

	/**
	 * Hook the webhook route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register the webhook route.
	 */
	public function routes(): void {
		register_rest_route(
			'sfls/v1',
			'/github/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook' ),
				'permission_callback' => '__return_true', // Authenticated by X-Hub-Signature-256.
			)
		);
	}

	// ── Settings ────────────────────────────────────────────────────────

	/**
	 * Connection settings.
	 *
	 * @return array{repo:string,token:string,secret:string,asset_pattern:string,auto_publish:int}
	 */
	public static function config(): array {
		$config = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'repo'          => '',
				'token'         => '',
				'secret'        => '',
				'asset_pattern' => 'subtleforms-pro-*.zip',
				'auto_publish'  => 0,
			)
		);
		if ( '' === trim( (string) $config['asset_pattern'] ) ) {
			$config['asset_pattern'] = 'subtleforms-pro-*.zip';
		}
		return $config;
	}

	/**
	 * Save settings (only the keys given).
	 *
	 * @param array $values Values.
	 */
	public static function save_config( array $values ): void {
		$config = self::config();
		if ( array_key_exists( 'repo', $values ) ) {
			$config['repo'] = self::sanitize_repo( (string) $values['repo'] );
		}
		foreach ( array( 'token', 'secret' ) as $secret ) {
			if ( array_key_exists( $secret, $values ) ) {
				$config[ $secret ] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $values[ $secret ] );
			}
		}
		if ( array_key_exists( 'asset_pattern', $values ) ) {
			$pattern                 = basename( sanitize_file_name( str_replace( '*', 'STAR', (string) $values['asset_pattern'] ) ) );
			$config['asset_pattern'] = '' !== $pattern ? str_replace( 'STAR', '*', $pattern ) : 'subtleforms-pro-*.zip';
		}
		if ( array_key_exists( 'auto_publish', $values ) ) {
			$config['auto_publish'] = empty( $values['auto_publish'] ) ? 0 : 1;
		}
		update_option( self::OPTION, $config, false );
	}

	/**
	 * "owner/repo" from a value or a github.com URL, '' when invalid.
	 *
	 * @param string $value Value.
	 */
	public static function sanitize_repo( string $value ): string {
		$value = trim( $value );
		$value = (string) preg_replace( '#^(https?://)?(www\.)?github\.com/#i', '', $value );
		$value = (string) preg_replace( '#(\.git)?/?$#', '', $value );
		return preg_match( '#^[A-Za-z0-9_.\-]{1,100}/[A-Za-z0-9_.\-]{1,100}$#', $value ) ? $value : '';
	}

	/**
	 * Generate and save a new webhook secret.
	 */
	public static function generate_secret(): string {
		$secret = bin2hex( random_bytes( 24 ) );
		self::save_config( array( 'secret' => $secret ) );
		return $secret;
	}

	/**
	 * The webhook URL to paste into GitHub.
	 */
	public static function webhook_url(): string {
		return rest_url( 'sfls/v1/github/webhook' );
	}

	/**
	 * Link to a tag or commit of the configured repository, '' without one.
	 *
	 * @param string $ref    Tag.
	 * @param string $commit Commit SHA or branch.
	 */
	public static function ref_url( string $ref, string $commit = '' ): string {
		$repo = self::config()['repo'];
		if ( '' === $repo ) {
			return '';
		}
		if ( preg_match( '/^[a-f0-9]{7,40}$/i', $commit ) ) {
			return 'https://github.com/' . $repo . '/commit/' . $commit;
		}
		if ( '' !== $ref ) {
			return 'https://github.com/' . $repo . '/releases/tag/' . rawurlencode( $ref );
		}
		return '';
	}

	// ── GitHub API ──────────────────────────────────────────────────────

	/**
	 * Request headers for the GitHub API.
	 *
	 * @param string $accept Accept header.
	 */
	private static function headers( string $accept = 'application/vnd.github+json' ): array {
		$headers = array(
			'Accept'               => $accept,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		$token   = self::config()['token'];
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	/**
	 * GET a GitHub API path.
	 *
	 * @param string $path Path, e.g. /repos/o/r.
	 * @return array|WP_Error Decoded JSON.
	 */
	public static function api_get( string $path ) {
		$res = wp_safe_remote_get(
			self::API . $path,
			array(
				'timeout'    => 20,
				'headers'    => self::headers(),
				'user-agent' => 'SubtleForms-License-Server/' . SFLS_VERSION,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$message = is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : '';
			if ( 404 === $code ) {
				$message = __( 'Not found. Check the repository name, and for a private repository add a token that can read it.', 'subtleforms-license-server' );
			} elseif ( 401 === $code ) {
				$message = __( 'GitHub refused the token. Create a new one and save it.', 'subtleforms-license-server' );
			}
			return new WP_Error(
				'github_' . $code,
				sprintf(
					/* translators: 1: HTTP status, 2: message from GitHub */
					__( 'GitHub answered HTTP %1$d. %2$s', 'subtleforms-license-server' ),
					$code,
					$message
				)
			);
		}
		return is_array( $body ) ? $body : array();
	}

	/**
	 * Test connection: GET /repos/{owner}/{repo}.
	 *
	 * @return array{full_name:string,private:bool,default_branch:string}|WP_Error
	 */
	public static function test_connection() {
		$repo = self::config()['repo'];
		if ( '' === $repo ) {
			return new WP_Error( 'no_repo', __( 'Enter the repository as owner/repo first.', 'subtleforms-license-server' ) );
		}
		$data = self::api_get( '/repos/' . $repo );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'full_name'      => (string) ( $data['full_name'] ?? $repo ),
			'private'        => ! empty( $data['private'] ),
			'default_branch' => (string) ( $data['default_branch'] ?? '' ),
		);
	}

	/**
	 * Import a GitHub release by tag (same processing as the webhook).
	 *
	 * @param string $tag Tag.
	 * @return array{result:string,message:string,version:string}
	 */
	public static function import_tag( string $tag ): array {
		$repo = self::config()['repo'];
		$tag  = trim( sanitize_text_field( $tag ) );
		if ( '' === $repo || '' === $tag ) {
			return self::outcome( 'failed', __( 'Enter the repository and a tag.', 'subtleforms-license-server' ) );
		}
		$release = self::api_get( '/repos/' . $repo . '/releases/tags/' . rawurlencode( $tag ) );
		if ( is_wp_error( $release ) ) {
			return self::outcome( 'failed', $release->get_error_message() );
		}
		$payload = array(
			'action'     => 'published',
			'release'    => $release,
			'repository' => array( 'full_name' => $repo ),
		);
		$outcome = self::process( 'release', $payload );
		self::record( 'import-' . gmdate( 'YmdHis' ), 'release', $payload, $outcome );
		return $outcome;
	}

	/**
	 * Download a release asset through the API URL. GitHub answers with a
	 * redirect to its storage host, which is followed without the token.
	 *
	 * @param array $asset Asset from the release payload.
	 * @return string|WP_Error Temp file.
	 */
	public static function download_asset( array $asset ) {
		$url = (string) ( $asset['url'] ?? '' );
		if ( '' === $url ) {
			$url = (string) ( $asset['browser_download_url'] ?? '' );
		}
		if ( (int) ( $asset['size'] ?? 0 ) > SFLS_Releases::max_size() ) {
			return new WP_Error( 'too_large', __( 'The release asset is larger than the size limit.', 'subtleforms-license-server' ) );
		}
		return SFLS_Releases::download_package( $url, self::headers( 'application/octet-stream' ) );
	}

	// ── Webhook ─────────────────────────────────────────────────────────

	/**
	 * Whether the signature header matches the raw body.
	 *
	 * @param string $body      Raw body.
	 * @param string $signature X-Hub-Signature-256 value.
	 * @param string $secret    Webhook secret.
	 */
	public static function verify_signature( string $body, string $signature, string $secret ): bool {
		if ( '' === $secret || ! str_starts_with( $signature, 'sha256=' ) ) {
			return false;
		}
		return hash_equals( 'sha256=' . hash_hmac( 'sha256', $body, $secret ), $signature );
	}

	/**
	 * POST /github/webhook.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function webhook( WP_REST_Request $req ): WP_REST_Response {
		$config   = self::config();
		$body     = (string) $req->get_body();
		$event    = sanitize_key( (string) $req->get_header( 'x_github_event' ) );
		$delivery = substr( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $req->get_header( 'x_github_delivery' ) ), 0, 64 );

		if ( '' === $config['secret'] ) {
			return self::reply( 503, 'not_configured', __( 'The GitHub webhook secret is not set on the license server.', 'subtleforms-license-server' ) );
		}
		if ( ! self::verify_signature( $body, (string) $req->get_header( 'x_hub_signature_256' ), $config['secret'] ) ) {
			self::record( $delivery, $event, null, self::outcome( 'rejected', __( 'Invalid signature.', 'subtleforms-license-server' ) ) );
			return self::reply( 401, 'invalid_signature', __( 'The X-Hub-Signature-256 header does not match.', 'subtleforms-license-server' ) );
		}
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			self::record( $delivery, $event, null, self::outcome( 'failed', __( 'The body is not JSON.', 'subtleforms-license-server' ) ) );
			return self::reply( 400, 'bad_payload', __( 'The body is not JSON. Set the webhook content type to application/json.', 'subtleforms-license-server' ) );
		}

		$outcome = self::process( $event, $payload );
		self::record( $delivery, $event, $payload, $outcome );

		$status = 'failed' === $outcome['result'] ? 422 : ( 'stored' === $outcome['result'] ? 201 : 200 );
		return new WP_REST_Response( $outcome, $status );
	}

	/**
	 * JSON reply.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private static function reply( int $status, string $code, string $message ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}

	/**
	 * Outcome record.
	 *
	 * @param string $result  stored | existing | ignored | failed | rejected | ok.
	 * @param string $message Message.
	 * @param string $version Release version.
	 * @return array{result:string,message:string,version:string}
	 */
	private static function outcome( string $result, string $message, string $version = '' ): array {
		return array(
			'result'  => $result,
			'message' => $message,
			'version' => $version,
		);
	}

	/**
	 * Handle one event payload.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Payload.
	 * @return array{result:string,message:string,version:string}
	 */
	public static function process( string $event, array $payload ): array {
		if ( 'ping' === $event ) {
			return self::outcome( 'ok', __( 'Ping received. The webhook works.', 'subtleforms-license-server' ) );
		}
		if ( 'release' !== $event ) {
			/* translators: %s: event name */
			return self::outcome( 'ignored', sprintf( __( 'The %s event is not used. Send only Releases.', 'subtleforms-license-server' ), $event ) );
		}
		$action  = (string) ( $payload['action'] ?? '' );
		$release = (array) ( $payload['release'] ?? array() );
		$tag     = (string) ( $release['tag_name'] ?? '' );
		if ( ! in_array( $action, array( 'published', 'released' ), true ) ) {
			/* translators: %s: release action */
			return self::outcome( 'ignored', sprintf( __( 'Release action "%s" is not used.', 'subtleforms-license-server' ), $action ) );
		}
		if ( ! empty( $release['draft'] ) || '' === $tag ) {
			return self::outcome( 'ignored', __( 'Draft releases are not imported.', 'subtleforms-license-server' ) );
		}
		$repo = self::config()['repo'];
		$from = (string) ( $payload['repository']['full_name'] ?? '' );
		if ( '' !== $repo && 0 !== strcasecmp( $repo, $from ) ) {
			/* translators: 1: repository of the event, 2: configured repository */
			return self::outcome( 'ignored', sprintf( __( 'The event is from %1$s, not %2$s.', 'subtleforms-license-server' ), $from, $repo ) );
		}

		$channel = ! empty( $release['prerelease'] ) ? 'beta' : 'stable';
		$version = SFLS_Releases::version_from_tag( $tag );
		$known   = SFLS_Releases::get( $version, true );
		if ( $known && $known['git_ref'] === $tag && 'github' === $known['source'] && SFLS_Releases::has_package( $known ) ) {
			// "published" and "released" both arrive for one release.
			if ( 'stable' === $channel && 'beta' === $known['channel'] ) {
				SFLS_Releases::update( $known['id'], array( 'channel' => 'stable' ) );
				/* translators: %s: version */
				return self::outcome( 'existing', sprintf( __( '%s is no longer a pre-release and moved to the stable channel.', 'subtleforms-license-server' ), $version ), $version );
			}
			/* translators: %s: version */
			return self::outcome( 'existing', sprintf( __( '%s was already imported from this tag.', 'subtleforms-license-server' ), $version ), $version );
		}

		$config  = self::config();
		$pattern = $config['asset_pattern'];
		$asset   = null;
		foreach ( (array) ( $release['assets'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) && self::matches( (string) ( $candidate['name'] ?? '' ), $pattern ) ) {
				$asset = $candidate;
				break;
			}
		}
		if ( ! $asset ) {
			/* translators: 1: pattern, 2: tag */
			return self::outcome( 'failed', sprintf( __( 'No asset matching %1$s was attached to %2$s. Attach the zip to the GitHub release and retry.', 'subtleforms-license-server' ), $pattern, $tag ), $version );
		}

		$tmp = self::download_asset( $asset );
		if ( is_wp_error( $tmp ) ) {
			return self::outcome( 'failed', $tmp->get_error_message(), $version );
		}
		$commit = (string) ( $release['target_commitish'] ?? '' );
		$result = SFLS_Releases::ingest(
			$tmp,
			array(
				'source'         => 'github',
				'channel'        => $channel,
				'publish'        => (bool) $config['auto_publish'],
				'changelog'      => SFLS_Releases::markdown_to_changelog( (string) ( $release['body'] ?? '' ) ),
				'git_ref'        => $tag,
				'git_commit'     => $commit,
				'expect_version' => $tag,
			)
		);
		wp_delete_file( $tmp );
		if ( is_wp_error( $result ) ) {
			return self::outcome( 'failed', $result->get_error_message(), $version );
		}
		$stored = $result['release'];
		if ( 'existing' === $result['result'] ) {
			/* translators: %s: version */
			return self::outcome( 'existing', sprintf( __( '%s is already stored with the same package.', 'subtleforms-license-server' ), $stored['version'] ), $stored['version'] );
		}
		return self::outcome(
			'stored',
			sprintf(
				/* translators: 1: version, 2: channel, 3: status */
				__( 'Stored %1$s (%2$s, %3$s).', 'subtleforms-license-server' ),
				$stored['version'],
				$stored['channel'],
				$stored['status']
			),
			$stored['version']
		);
	}

	/**
	 * Whether an asset name matches a pattern with * wildcards.
	 *
	 * @param string $name    Asset name.
	 * @param string $pattern Pattern.
	 */
	public static function matches( string $name, string $pattern ): bool {
		$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
		return '' !== $name && (bool) preg_match( $regex, $name );
	}

	// ── Delivery log ────────────────────────────────────────────────────

	/**
	 * Record a delivery and its outcome.
	 *
	 * @param string     $delivery Delivery ID.
	 * @param string     $event    Event.
	 * @param array|null $payload  Payload (null for rejected deliveries: not stored).
	 * @param array      $outcome  Outcome.
	 */
	public static function record( string $delivery, string $event, ?array $payload, array $outcome ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			SFLS_DB::github_deliveries(),
			array(
				'delivery_id'     => substr( $delivery, 0, 64 ),
				'event'           => substr( $event, 0, 40 ),
				'action'          => substr( (string) ( $payload['action'] ?? '' ), 0, 40 ),
				'tag'             => substr( (string) ( $payload['release']['tag_name'] ?? '' ), 0, 100 ),
				'result'          => $outcome['result'],
				'message'         => $outcome['message'],
				'release_version' => substr( $outcome['version'], 0, 40 ),
				'payload'         => null === $payload ? null : wp_json_encode( $payload ),
				'received_at'     => $now,
				'processed_at'    => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		// Keep the log short.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sfls_github_deliveries WHERE id <= %d", $id - self::KEEP ) );
		return $id;
	}

	/**
	 * The latest deliveries, newest first.
	 *
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function recent_deliveries( int $limit = 20 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, delivery_id, event, action, tag, result, message, release_version, received_at, processed_at, payload IS NOT NULL AS has_payload FROM {$wpdb->prefix}sfls_github_deliveries ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * Run a stored delivery again (for example after attaching the zip).
	 *
	 * @param int $id Delivery row ID.
	 * @return array{result:string,message:string,version:string}
	 */
	public static function retry( int $id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_github_deliveries WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row || null === $row['payload'] ) {
			return self::outcome( 'failed', __( 'That delivery cannot be retried.', 'subtleforms-license-server' ) );
		}
		$payload = json_decode( (string) $row['payload'], true );
		$outcome = is_array( $payload ) ? self::process( (string) $row['event'], $payload ) : self::outcome( 'failed', __( 'The stored payload is damaged.', 'subtleforms-license-server' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SFLS_DB::github_deliveries(),
			array(
				'result'          => $outcome['result'],
				'message'         => $outcome['message'] . ' ' . __( '(retried)', 'subtleforms-license-server' ),
				'release_version' => substr( $outcome['version'], 0, 40 ),
				'processed_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
		return $outcome;
	}
}
