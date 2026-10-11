<?php
/**
 * Pro releases: the current package, older packages and short-lived signed
 * download links.
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
	 * Signed URL valid for one hour, bound to a license key and domain.
	 *
	 * @param string $key    License key.
	 * @param string $domain Domain.
	 */
	public static function signed_url( string $key, string $domain ): string {
		$expires = time() + HOUR_IN_SECONDS;
		$sig     = self::sign( $key, $domain, $expires );
		return add_query_arg(
			array(
				'license_key' => rawurlencode( $key ),
				'site'        => rawurlencode( $domain ),
				'expires'     => $expires,
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
	 */
	public static function sign( string $key, string $domain, int $expires ): string {
		return hash_hmac( 'sha256', strtoupper( $key ) . '|' . $domain . '|' . $expires, wp_salt( 'auth' ) . 'subtleforms-license-server' );
	}

	/**
	 * Check a download link signature.
	 *
	 * @param string $key     License key.
	 * @param string $domain  Domain.
	 * @param int    $expires Expiry timestamp.
	 * @param string $sig     Signature.
	 */
	public static function verify( string $key, string $domain, int $expires, string $sig ): bool {
		return $expires >= time() && hash_equals( self::sign( $key, $domain, $expires ), $sig );
	}
}
