<?php
/**
 * Pro releases: the current package and short-lived signed download links.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

final class SFLS_Releases {

	const OPTION = 'sfls_release';

	/**
	 * Private folder outside public listing (protected by .htaccess; on Nginx
	 * add a deny rule, see README).
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'sfls-private';
	}

	/**
	 * @return array{version:string,file:string,requires:string,tested:string,requires_php:string,changelog:string,updated:string}
	 */
	public static function current(): array {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'version'      => '',
				'file'         => '',
				'requires'     => '6.4',
				'tested'       => '',
				'requires_php' => '8.1',
				'changelog'    => '',
				'updated'      => '',
			)
		);
	}

	public static function save( array $data ): void {
		update_option( self::OPTION, array_merge( self::current(), $data ), false );
	}

	public static function package_path(): string {
		$file = self::current()['file'];
		return $file ? trailingslashit( self::dir() ) . basename( $file ) : '';
	}

	/**
	 * Signed URL valid for one hour, bound to a license key and domain.
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

	public static function sign( string $key, string $domain, int $expires ): string {
		return hash_hmac( 'sha256', strtoupper( $key ) . '|' . $domain . '|' . $expires, wp_salt( 'auth' ) . 'subtleforms-license-server' );
	}

	public static function verify( string $key, string $domain, int $expires, string $sig ): bool {
		return $expires >= time() && hash_equals( self::sign( $key, $domain, $expires ), $sig );
	}
}
