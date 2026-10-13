<?php
/**
 * Deploy tokens for CI: named API tokens with the releases:write scope.
 *
 * A token is shown once when it is created. Only a keyed hash
 * (HMAC-SHA256 with a site salt) is stored, so a database dump does not
 * reveal working tokens. Tokens are never logged.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deploy token store.
 */
final class SFLS_Deploy_Tokens {

	const OPTION = 'sfls_deploy_tokens';
	const SCOPE  = 'releases:write';
	const PREFIX = 'sflsdt_';

	/**
	 * Every token record (no secrets), newest first.
	 *
	 * @return array<int,array{id:int,name:string,hint:string,hash:string,scopes:string[],created_at:string,created_by:int,last_used_at:string,last_used_ip:string,revoked_at:string}>
	 */
	public static function all(): array {
		$rows = array();
		foreach ( (array) get_option( self::OPTION, array() ) as $row ) {
			$row = wp_parse_args(
				(array) $row,
				array(
					'id'           => 0,
					'name'         => '',
					'hint'         => '',
					'hash'         => '',
					'scopes'       => array( self::SCOPE ),
					'created_at'   => '',
					'created_by'   => 0,
					'last_used_at' => '',
					'last_used_ip' => '',
					'revoked_at'   => '',
				)
			);
			if ( (int) $row['id'] > 0 ) {
				$rows[ (int) $row['id'] ] = $row;
			}
		}
		krsort( $rows );
		return $rows;
	}

	/**
	 * Save every record.
	 *
	 * @param array $rows Records.
	 */
	private static function save( array $rows ): void {
		update_option( self::OPTION, array_values( $rows ), false );
	}

	/**
	 * Keyed hash of a token.
	 *
	 * @param string $token Token.
	 */
	public static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . 'sfls-deploy-token' );
	}

	/**
	 * Create a token. The plain token is returned once and never stored.
	 *
	 * @param string $name    Name, e.g. "GitHub Actions".
	 * @param int    $user_id Who created it.
	 * @return array{token:string,record:array}
	 */
	public static function create( string $name, int $user_id = 0 ): array {
		$rows   = self::all();
		$id     = $rows ? max( array_keys( $rows ) ) + 1 : 1;
		$token  = self::PREFIX . bin2hex( random_bytes( 24 ) );
		$record = array(
			'id'           => $id,
			'name'         => '' !== trim( $name ) ? substr( sanitize_text_field( $name ), 0, 80 ) : __( 'Deploy token', 'subtleforms-license-server' ),
			'hint'         => substr( $token, 0, strlen( self::PREFIX ) + 4 ) . '…' . substr( $token, -4 ),
			'hash'         => self::hash( $token ),
			'scopes'       => array( self::SCOPE ),
			'created_at'   => current_time( 'mysql', true ),
			'created_by'   => $user_id,
			'last_used_at' => '',
			'last_used_ip' => '',
			'revoked_at'   => '',
		);
		$rows[ $id ] = $record;
		self::save( $rows );
		return array(
			'token'  => $token,
			'record' => $record,
		);
	}

	/**
	 * Revoke a token. Returns false when it does not exist.
	 *
	 * @param int $id Token ID.
	 */
	public static function revoke( int $id ): bool {
		$rows = self::all();
		if ( ! isset( $rows[ $id ] ) ) {
			return false;
		}
		if ( '' === $rows[ $id ]['revoked_at'] ) {
			$rows[ $id ]['revoked_at'] = current_time( 'mysql', true );
			self::save( $rows );
		}
		return true;
	}

	/**
	 * Find the active token record for a plain token (constant-time compare
	 * against every stored hash) and note when it was used.
	 *
	 * @param string $token Plain token.
	 * @param string $scope Required scope.
	 */
	public static function verify( string $token, string $scope = self::SCOPE ): ?array {
		$token = trim( $token );
		if ( ! str_starts_with( $token, self::PREFIX ) || strlen( $token ) > 200 ) {
			return null;
		}
		$hash  = self::hash( $token );
		$match = null;
		$rows  = self::all();
		foreach ( $rows as $id => $row ) {
			if ( hash_equals( (string) $row['hash'], $hash ) ) {
				$match = $id;
			}
		}
		if ( null === $match || '' !== $rows[ $match ]['revoked_at'] || ! in_array( $scope, (array) $rows[ $match ]['scopes'], true ) ) {
			return null;
		}
		$rows[ $match ]['last_used_at'] = current_time( 'mysql', true );
		$rows[ $match ]['last_used_ip'] = SFLS_Licenses::client_ip();
		self::save( $rows );
		return $rows[ $match ];
	}
}
