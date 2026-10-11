<?php
/**
 * Pro releases: the current package, older packages and short-lived,
 * single-use signed download links.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Release store.
 */
final class SFLS_Releases {

	const OPTION         = 'sfls_release';
	const HISTORY_OPTION = 'sfls_release_history';

	/**
	 * Private folder outside public listing (protected by .htaccess; on Nginx
	 * add a deny rule, see README).
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'sfls-private';
	}

	/**
	 * Defaults for one release record.
	 */
	private static function defaults(): array {
		return array(
			'version'      => '',
			'file'         => '',
			'requires'     => '6.4',
			'tested'       => '',
			'requires_php' => '8.1',
			'changelog'    => '',
			'updated'      => '',
		);
	}

	/**
	 * The current release.
	 *
	 * @return array{version:string,file:string,requires:string,tested:string,requires_php:string,changelog:string,updated:string}
	 */
	public static function current(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * Save the current release. When the version changes, the previous release
	 * moves into the history so it stays downloadable and listed.
	 *
	 * @param array $data Release fields.
	 */
	public static function save( array $data ): void {
		$previous = self::current();
		$next     = array_merge( $previous, $data );

		if ( '' !== $previous['version'] && $previous['version'] !== $next['version'] ) {
			if ( ! isset( $data['file'] ) ) {
				$next['file'] = ''; // A new version without a new zip has no package yet.
			}
			$history = self::history();
			$history = array_filter( $history, static fn( $r ) => $r['version'] !== $previous['version'] && $r['version'] !== $next['version'] );
			array_unshift( $history, $previous );
			usort( $history, static fn( $a, $b ) => version_compare( $b['version'], $a['version'] ) );
			update_option( self::HISTORY_OPTION, array_slice( array_values( $history ), 0, 50 ), false );
		}

		update_option( self::OPTION, $next, false );
	}

	/**
	 * Older releases, newest first.
	 *
	 * @return array[]
	 */
	public static function history(): array {
		$rows = (array) get_option( self::HISTORY_OPTION, array() );
		return array_values( array_map( static fn( $r ) => wp_parse_args( (array) $r, self::defaults() ), $rows ) );
	}

	/**
	 * Every release, current first then older ones newest first.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		$current = self::current();
		$all     = self::history();
		if ( '' !== $current['version'] ) {
			array_unshift( $all, $current );
		}
		return $all;
	}

	/**
	 * Find a release by version.
	 *
	 * @param string $version Version.
	 */
	public static function find( string $version ): ?array {
		foreach ( self::all() as $release ) {
			if ( $release['version'] === $version ) {
				return $release;
			}
		}
		return null;
	}

	/**
	 * Absolute path of a release's package, or '' when it has none.
	 *
	 * @param array|null $release Release, defaults to current.
	 */
	public static function package_path( ?array $release = null ): string {
		$file = ( $release ?? self::current() )['file'];
		return $file ? trailingslashit( self::dir() ) . basename( $file ) : '';
	}

	/**
	 * Whether a release has a readable package.
	 *
	 * @param array|null $release Release, defaults to current.
	 */
	public static function has_package( ?array $release = null ): bool {
		$path = self::package_path( $release );
		return '' !== $path && is_readable( $path );
	}

	/**
	 * Release date formatted for people.
	 *
	 * @param array $release Release.
	 */
	public static function date_label( array $release ): string {
		if ( empty( $release['updated'] ) ) {
			return '';
		}
		$ts = strtotime( $release['updated'] . ' 12:00:00 UTC' );
		return $ts ? wp_date( get_option( 'date_format' ), $ts ) : '';
	}

	/**
	 * Changelog as HTML (stored as plain text, one item per line).
	 *
	 * @param array $release Release.
	 */
	public static function changelog_html( array $release ): string {
		$text = trim( (string) $release['changelog'] );
		if ( '' === $text ) {
			return '';
		}
		$lines = array_filter( array_map( 'trim', explode( "\n", $text ) ) );
		$items = array();
		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^[\*\-•]\s*/u', $line ) ) {
				return wpautop( esc_html( $text ) );
			}
			$items[] = '<li>' . esc_html( preg_replace( '/^[\*\-•]\s*/u', '', $line ) ) . '</li>';
		}
		return '<ul>' . implode( '', $items ) . '</ul>';
	}

	/**
	 * Stream a release package to the browser and stop.
	 *
	 * @param array $release Release.
	 */
	public static function stream( array $release ): void {
		$path = self::package_path( $release );
		if ( '' === $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'This package is not available.', 'subtleforms-license-server' ), '', array( 'response' => 404 ) );
		}
		$name = 'subtleforms-pro-' . sanitize_file_name( $release['version'] ) . '.zip';
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * How long a signed download link works.
	 */
	const LINK_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * The plugin file every release zip must contain.
	 */
	const MAIN_FILE = 'subtleforms-pro/subtleforms-pro.php';

	/**
	 * Signed download URL: valid for 15 minutes, works once, bound to a
	 * license key and domain.
	 *
	 * @param string $key    License key.
	 * @param string $domain Domain.
	 */
	public static function signed_url( string $key, string $domain ): string {
		$expires = time() + self::LINK_TTL;
		$token   = bin2hex( random_bytes( 16 ) );
		$sig     = self::sign( $key, $domain, $expires, $token );
		return add_query_arg(
			array(
				'license_key' => rawurlencode( $key ),
				'site'        => rawurlencode( $domain ),
				'expires'     => $expires,
				'token'       => $token,
				'sig'         => $sig,
			),
			rest_url( 'sfls/v1/update/download' )
		);
	}

	/**
	 * HMAC for a download link.
	 *
	 * @param string $key     License key.
	 * @param string $domain  Domain.
	 * @param int    $expires Expiry timestamp.
	 * @param string $token   One-time token.
	 */
	public static function sign( string $key, string $domain, int $expires, string $token = '' ): string {
		return hash_hmac( 'sha256', strtoupper( $key ) . '|' . $domain . '|' . $expires . '|' . $token, wp_salt( 'auth' ) . 'subtleforms-license-server' );
	}

	/**
	 * Check a download link signature and expiry (does not use it up).
	 *
	 * @param string $key     License key.
	 * @param string $domain  Domain.
	 * @param int    $expires Expiry timestamp.
	 * @param string $sig     Signature.
	 * @param string $token   One-time token.
	 */
	public static function verify( string $key, string $domain, int $expires, string $sig, string $token = '' ): bool {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false; // Links without a one-time token (1.2.0 and older) are no longer accepted.
		}
		return $expires >= time() && $expires <= time() + self::LINK_TTL && hash_equals( self::sign( $key, $domain, $expires, $token ), $sig );
	}

	/**
	 * Mark a link token as used. Returns false when it was used before. The
	 * primary key makes this atomic, so two parallel requests cannot both win.
	 *
	 * @param string $token One-time token.
	 */
	public static function consume_token( string $token ): bool {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false;
		}
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			SFLS_DB::download_tokens(),
			array(
				'token'   => $token,
				'used_at' => current_time( 'mysql', true ),
			)
		);
		$wpdb->suppress_errors( $suppress );
		return 1 === $ok;
	}

	/**
	 * Forget used tokens whose links have expired anyway (daily).
	 */
	public static function purge_tokens(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sfls_download_tokens WHERE used_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	}

	// ── Release zips ────────────────────────────────────────────────────

	/**
	 * Read the plugin headers from a release zip and check its layout.
	 *
	 * @param string $zip_path Path to the zip.
	 * @return array{version:string,requires:string,tested:string,requires_php:string}|WP_Error
	 */
	public static function inspect_zip( string $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'The PHP zip extension is missing on this server, so the release cannot be checked.', 'subtleforms-license-server' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'bad_zip', __( 'That file is not a valid zip archive.', 'subtleforms-license-server' ) );
		}
		$main = $zip->getFromName( self::MAIN_FILE );
		if ( false === $main ) {
			$top = array();
			for ( $i = 0; $i < min( $zip->numFiles, 500 ); $i++ ) {
				$parts                  = explode( '/', (string) $zip->getNameIndex( $i ) );
				$top[ (string) $parts[0] ] = true;
			}
			$zip->close();
			return new WP_Error(
				'bad_layout',
				sprintf(
					/* translators: 1: expected path, 2: folders found in the zip */
					__( 'This zip does not contain %1$s. Upload the SubtleForms Pro plugin zip (it must have a subtleforms-pro folder at the top). Found: %2$s.', 'subtleforms-license-server' ),
					self::MAIN_FILE,
					implode( ', ', array_slice( array_keys( $top ), 0, 5 ) )
				)
			);
		}
		$readme = $zip->getFromName( 'subtleforms-pro/readme.txt' );
		$zip->close();

		$headers = self::parse_headers(
			(string) $main,
			array(
				'version'      => 'Version',
				'requires'     => 'Requires at least',
				'tested'       => 'Tested up to',
				'requires_php' => 'Requires PHP',
			)
		);
		if ( false !== $readme ) {
			$from_readme = self::parse_headers(
				(string) $readme,
				array(
					'requires'     => 'Requires at least',
					'tested'       => 'Tested up to',
					'requires_php' => 'Requires PHP',
				)
			);
			foreach ( $from_readme as $field => $value ) {
				if ( '' === $headers[ $field ] ) {
					$headers[ $field ] = $value;
				}
			}
		}
		if ( '' === $headers['version'] || ! preg_match( '/^\d+(\.\d+){0,3}([-.+][0-9A-Za-z.]+)?$/', $headers['version'] ) ) {
			return new WP_Error( 'no_version', __( 'The plugin file in this zip has no valid Version header.', 'subtleforms-license-server' ) );
		}
		return $headers;
	}

	/**
	 * Read "Name: value" headers from the first 8 KB of a file, the way
	 * get_file_data() does.
	 *
	 * @param string $contents File contents.
	 * @param array  $fields   key => header name.
	 * @return array<string,string>
	 */
	public static function parse_headers( string $contents, array $fields ): array {
		$contents = str_replace( "\r", "\n", substr( $contents, 0, 8 * KB_IN_BYTES ) );
		$out      = array();
		foreach ( $fields as $key => $header ) {
			$out[ $key ] = '';
			if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $contents, $m ) ) {
				$out[ $key ] = sanitize_text_field( trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) ) );
			}
		}
		return $out;
	}

	/**
	 * Why a version may not be published, or '' when it may. A release may
	 * replace the current version (same number) but never go below it.
	 *
	 * @param string $version Version to publish.
	 */
	public static function version_problem( string $version ): string {
		$current = self::current()['version'];
		if ( '' === $version ) {
			return __( 'Enter a version number, or upload a zip so it can be read from the plugin.', 'subtleforms-license-server' );
		}
		if ( '' !== $current && version_compare( $version, $current, '<' ) ) {
			return sprintf(
				/* translators: 1: version being published, 2: current version */
				__( 'Version %1$s is lower than the current release %2$s. Publish a higher version, or re-upload %2$s to replace its package.', 'subtleforms-license-server' ),
				$version,
				$current
			);
		}
		return '';
	}
}
