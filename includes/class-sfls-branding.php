<?php
/**
 * White-label branding per license (1.8.0).
 *
 * An agency with the white_label feature sets its branding once in My
 * Account › Branding. The profile is stored per license, because the key is
 * what client sites hold. Client sites fetch it through POST
 * /sfls/v1/branding and SubtleForms Pro applies it. Every save bumps an
 * integer version; the last ten states are kept so they can be restored.
 *
 * Profile keys (all optional, unknown keys are dropped):
 *   name, menu_name, logo_url, support_url, hide_credits, restrict_screens,
 *   author, author_url, description.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Branding storage, validation and the client answer.
 */
final class SFLS_Branding {

	/**
	 * Plan feature that unlocks branding.
	 */
	const FEATURE = 'white_label';

	/**
	 * Largest logo upload in bytes (1 MB).
	 */
	const MAX_LOGO_BYTES = 1048576;

	/**
	 * Revisions kept per license.
	 */
	const MAX_REVISIONS = 10;

	/**
	 * Folder in the uploads directory that holds uploaded logos.
	 */
	const LOGO_DIR = 'sfls-branding';

	/**
	 * Text fields and their longest value in characters.
	 */
	const TEXT_FIELDS = array(
		'name'        => 60,
		'menu_name'   => 40,
		'author'      => 60,
		'description' => 200,
	);

	/**
	 * Address fields and their longest value in characters.
	 */
	const URL_FIELDS = array(
		'logo_url'    => 500,
		'support_url' => 500,
		'author_url'  => 500,
	);

	/**
	 * On/off fields.
	 */
	const BOOL_FIELDS = array( 'hide_credits', 'restrict_screens' );

	/**
	 * Image types a logo address may point to.
	 */
	const IMAGE_EXTENSIONS = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' );

	// ── Plan and ownership ──────────────────────────────────────────────

	/**
	 * Whether the plan of a license includes branding.
	 *
	 * @param array $lic License.
	 */
	public static function plan_allows( array $lic ): bool {
		return in_array( self::FEATURE, SFLS_Plans::features( (string) $lic['plan'] ), true );
	}

	/**
	 * Whether a license may have branding right now: its plan includes it and
	 * the license is active (a grace period still counts).
	 *
	 * @param array $lic License.
	 */
	public static function is_eligible( array $lic ): bool {
		return self::plan_allows( $lic ) && SFLS_Licenses::state( $lic )['valid'];
	}

	/**
	 * The licenses of a user that may have branding.
	 *
	 * @param WP_User $user User.
	 * @return array[]
	 */
	public static function eligible_licenses( WP_User $user ): array {
		return array_values( array_filter( SFLS_Licenses::for_user( $user ), array( self::class, 'is_eligible' ) ) );
	}

	/**
	 * Whether a user may manage the branding of a license: they own it and it
	 * is eligible.
	 *
	 * @param array   $lic  License.
	 * @param WP_User $user User.
	 */
	public static function can_manage( array $lic, WP_User $user ): bool {
		return SFLS_Licenses::user_owns( $lic, $user ) && self::is_eligible( $lic );
	}

	// ── Storage ─────────────────────────────────────────────────────────

	/**
	 * A profile with every key empty (the shape stored and sent).
	 *
	 * @return array<string,mixed>
	 */
	public static function empty_profile(): array {
		$profile = array();
		foreach ( array_keys( self::TEXT_FIELDS ) as $key ) {
			$profile[ $key ] = '';
		}
		foreach ( array_keys( self::URL_FIELDS ) as $key ) {
			$profile[ $key ] = '';
		}
		foreach ( self::BOOL_FIELDS as $key ) {
			$profile[ $key ] = false;
		}
		return $profile;
	}

	/**
	 * The stored branding of a license, or null before the first save.
	 *
	 * @param int $license_id License ID.
	 * @return array{license_id:int,version:int,enabled:bool,profile:array,logo_file:string,updated_at:string,updated_by:int}|null
	 */
	public static function get( int $license_id ): ?array {
		global $wpdb;
		$table = SFLS_DB::branding();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_id = %d", $license_id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Turn a table row into the array used everywhere else. The logo address
	 * of an uploaded logo is built from the file name, so it follows the
	 * site address.
	 *
	 * @param array $row Table row.
	 */
	private static function hydrate( array $row ): array {
		$profile = self::normalize( (array) json_decode( (string) $row['profile'], true ) );
		$file    = (string) ( $row['logo_file'] ?? '' );
		if ( '' !== $file ) {
			$profile['logo_url'] = self::logo_url( $file );
		}
		$out = array(
			'version'    => (int) $row['version'],
			'enabled'    => (bool) (int) $row['enabled'],
			'profile'    => $profile,
			'logo_file'  => $file,
			'updated_at' => (string) $row['updated_at'],
			'updated_by' => (int) ( $row['updated_by'] ?? 0 ),
		);
		if ( isset( $row['license_id'] ) ) {
			$out['license_id'] = (int) $row['license_id'];
		}
		if ( isset( $row['id'] ) ) {
			$out['id'] = (int) $row['id'];
		}
		if ( isset( $row['action'] ) ) {
			$out['action'] = (string) $row['action'];
		}
		return $out;
	}

	/**
	 * Keep only the known keys, in the stored shape, without validating.
	 * Used for data read back from storage.
	 *
	 * @param array $data Stored profile.
	 */
	private static function normalize( array $data ): array {
		$profile = self::empty_profile();
		foreach ( $profile as $key => $default ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$profile[ $key ] = is_bool( $default ) ? self::to_bool( $data[ $key ] ) : ( is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '' );
		}
		return $profile;
	}

	/**
	 * The branding version clients see: the stored version, or 0 when there is
	 * none or the plan has no branding.
	 *
	 * @param array $lic License.
	 */
	public static function version_for( array $lic ): int {
		if ( ! self::plan_allows( $lic ) ) {
			return 0;
		}
		$row = self::get( (int) $lic['id'] );
		return $row ? $row['version'] : 0;
	}

	/**
	 * Save a new state of a license: bump the version, keep a revision and
	 * drop revisions beyond the newest ten.
	 *
	 * @param int    $license_id License ID.
	 * @param bool   $enabled    Whether clients should apply the profile.
	 * @param array  $profile    Validated profile.
	 * @param string $logo_file  Uploaded logo file name, '' for none.
	 * @param int    $user_id    Who changed it (0 for the system).
	 * @param string $action     save | restore | reset.
	 * @return array The stored state.
	 */
	private static function commit( int $license_id, bool $enabled, array $profile, string $logo_file, int $user_id, string $action ): array {
		global $wpdb;
		$current = self::get( $license_id );
		$version = ( $current ? $current['version'] : 0 ) + 1;
		$now     = current_time( 'mysql', true );
		$json    = wp_json_encode( $profile );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			SFLS_DB::branding(),
			array(
				'license_id' => $license_id,
				'version'    => $version,
				'enabled'    => $enabled ? 1 : 0,
				'profile'    => $json,
				'logo_file'  => $logo_file,
				'updated_at' => $now,
				'updated_by' => $user_id,
			)
		);
		$wpdb->insert(
			SFLS_DB::branding_revisions(),
			array(
				'license_id' => $license_id,
				'version'    => $version,
				'enabled'    => $enabled ? 1 : 0,
				'profile'    => $json,
				'logo_file'  => $logo_file,
				'action'     => $action,
				'user_id'    => $user_id,
				'created_at' => $now,
			)
		);
		$revisions = SFLS_DB::branding_revisions();
		$keep      = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$revisions} WHERE license_id = %d ORDER BY id DESC LIMIT %d", $license_id, self::MAX_REVISIONS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $keep ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$revisions} WHERE license_id = %d AND id NOT IN (" . implode( ',', $keep ) . ')', $license_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}
		// phpcs:enable

		if ( $current && '' !== $current['logo_file'] && $current['logo_file'] !== $logo_file ) {
			self::delete_logo_file( $current['logo_file'] );
		}
		/**
		 * Fires after a license's branding changed.
		 *
		 * @param int    $license_id License ID.
		 * @param int    $version    New version.
		 * @param string $action     save | restore | reset.
		 */
		do_action( 'sfls_branding_changed', $license_id, $version, $action );
		return (array) self::get( $license_id );
	}

	/**
	 * Save the branding of a license from form input.
	 *
	 * $logo carries the logo choice: `file` is an upload array (tmp_name,
	 * size, error) and `remove` clears the logo. An uploaded file wins over a
	 * logo address; a logo address wins over the uploaded logo kept so far;
	 * with neither, the logo stays as it is.
	 *
	 * @param array $lic     License.
	 * @param array $input   Unslashed fields.
	 * @param int   $user_id Who saves.
	 * @param array $logo    { file?: array, remove?: bool }.
	 * @return array|WP_Error The stored state, or an error whose data holds `fields` (field => message).
	 */
	public static function save( array $lic, array $input, int $user_id, array $logo = array() ) {
		if ( ! self::plan_allows( $lic ) ) {
			return new WP_Error( 'sfls_branding_plan', __( 'Branding is part of the Agency plan.', 'subtleforms-license-server' ), array( 'status' => 403 ) );
		}
		$license_id = (int) $lic['id'];
		$checked    = self::validate( $input );
		$errors     = $checked['errors'];
		$profile    = $checked['profile'];
		$current    = self::get( $license_id );
		$file       = $current ? $current['logo_file'] : '';
		$new_file   = null;

		$upload = isset( $logo['file'] ) && is_array( $logo['file'] ) && ( UPLOAD_ERR_NO_FILE !== (int) ( $logo['file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) ? $logo['file'] : null;
		if ( $upload ) {
			$stored = self::store_logo( $license_id, (string) ( $upload['tmp_name'] ?? '' ), (int) ( $upload['error'] ?? 0 ) );
			if ( is_wp_error( $stored ) ) {
				$errors['logo'] = $stored->get_error_message();
			} else {
				$new_file = $stored;
			}
		}
		if ( $errors ) {
			if ( null !== $new_file ) {
				self::delete_logo_file( $new_file );
			}
			return new WP_Error( 'sfls_branding_invalid', reset( $errors ), array( 'status' => 400, 'fields' => $errors ) );
		}

		if ( null !== $new_file ) {
			$file                = $new_file;
			$profile['logo_url'] = self::logo_url( $file );
		} elseif ( ! empty( $logo['remove'] ) ) {
			$file                = '';
			$profile['logo_url'] = '';
		} elseif ( '' !== $profile['logo_url'] ) {
			$file = ''; // An address replaces the uploaded logo.
		} elseif ( '' !== $file ) {
			$profile['logo_url'] = self::logo_url( $file );
		}

		return self::commit( $license_id, true, $profile, $file, $user_id, 'save' );
	}

	/**
	 * Remove the branding of a license: clients get "enabled: false" and show
	 * the default branding. The version still goes up.
	 *
	 * @param array $lic     License.
	 * @param int   $user_id Who removes it.
	 * @return array The stored state.
	 */
	public static function reset( array $lic, int $user_id ): array {
		return self::commit( (int) $lic['id'], false, self::empty_profile(), '', $user_id, 'reset' );
	}

	/**
	 * Put an earlier state back. It becomes a new version, so clients that
	 * already applied the later one fetch it.
	 *
	 * @param array $lic         License.
	 * @param int   $revision_id Revision ID.
	 * @param int   $user_id     Who restores.
	 * @return array{state:array,logo_lost:bool}|WP_Error
	 */
	public static function restore( array $lic, int $revision_id, int $user_id ) {
		if ( ! self::plan_allows( $lic ) ) {
			return new WP_Error( 'sfls_branding_plan', __( 'Branding is part of the Agency plan.', 'subtleforms-license-server' ), array( 'status' => 403 ) );
		}
		$revision = self::revision( (int) $lic['id'], $revision_id );
		if ( ! $revision ) {
			return new WP_Error( 'sfls_branding_revision', __( 'That version was not found.', 'subtleforms-license-server' ), array( 'status' => 404 ) );
		}
		$profile   = $revision['profile'];
		$file      = $revision['logo_file'];
		$logo_lost = false;
		if ( '' !== $file && ! is_file( self::logo_dir() . '/' . $file ) ) {
			// Replaced logos are deleted, so an old state may have lost its file.
			$file      = '';
			$logo_lost = true;
			if ( self::logo_url( $revision['logo_file'] ) === $profile['logo_url'] ) {
				$profile['logo_url'] = '';
			}
		}
		// Whatever it holds went through validation when it was saved; check again.
		$checked = self::validate( $profile );
		$profile = $checked['profile'];
		if ( '' !== $file ) {
			$profile['logo_url'] = self::logo_url( $file );
		}
		return array(
			'state'     => self::commit( (int) $lic['id'], $revision['enabled'], $profile, $file, $user_id, 'restore' ),
			'logo_lost' => $logo_lost,
		);
	}

	/**
	 * Revisions of a license, newest first.
	 *
	 * @param int $license_id License ID.
	 * @return array[]
	 */
	public static function revisions( int $license_id ): array {
		global $wpdb;
		$table = SFLS_DB::branding_revisions();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_id = %d ORDER BY id DESC LIMIT %d", $license_id, self::MAX_REVISIONS ), ARRAY_A );
		return array_map( array( self::class, 'hydrate_revision' ), $rows );
	}

	/**
	 * One revision of a license.
	 *
	 * @param int $license_id License ID.
	 * @param int $id         Revision ID.
	 */
	public static function revision( int $license_id, int $id ): ?array {
		global $wpdb;
		$table = SFLS_DB::branding_revisions();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_id = %d AND id = %d", $license_id, $id ), ARRAY_A );
		return $row ? self::hydrate_revision( $row ) : null;
	}

	/**
	 * Revision row to array. Unlike the current state, the logo address is
	 * the one stored then.
	 *
	 * @param array $row Table row.
	 */
	private static function hydrate_revision( array $row ): array {
		$row['updated_at'] = (string) $row['created_at'];
		$row['updated_by'] = (int) $row['user_id'];
		return self::hydrate( $row );
	}

	// ── Validation ──────────────────────────────────────────────────────

	/**
	 * Validate and sanitise a profile. Unknown keys are dropped. Over-long
	 * text and bad addresses are reported in `errors` (field => message)
	 * rather than cut silently; the returned profile then holds the
	 * trimmed or emptied value.
	 *
	 * @param array $input Raw fields (unslashed).
	 * @return array{profile:array,errors:array<string,string>}
	 */
	public static function validate( array $input ): array {
		$profile = self::empty_profile();
		$errors  = array();

		foreach ( self::TEXT_FIELDS as $key => $max ) {
			if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $input[ $key ] );
			if ( mb_strlen( $value ) > $max ) {
				$errors[ $key ] = sprintf(
					/* translators: 1: field name, 2: maximum characters */
					__( '%1$s can have up to %2$d characters.', 'subtleforms-license-server' ),
					self::field_label( $key ),
					$max
				);
				$value = mb_substr( $value, 0, $max );
			}
			$profile[ $key ] = $value;
		}

		foreach ( self::URL_FIELDS as $key => $max ) {
			if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
				continue;
			}
			$clean = self::clean_url( (string) $input[ $key ], 'logo_url' === $key, $max );
			if ( is_wp_error( $clean ) ) {
				$errors[ $key ] = sprintf( '%s %s', self::field_label( $key ), $clean->get_error_message() );
				continue;
			}
			$profile[ $key ] = $clean;
		}

		foreach ( self::BOOL_FIELDS as $key ) {
			$profile[ $key ] = isset( $input[ $key ] ) && self::to_bool( $input[ $key ] );
		}
		return array(
			'profile' => $profile,
			'errors'  => $errors,
		);
	}

	/**
	 * Clean an address. The logo must be https and an image; the others may
	 * be http or https. No user name or password in the address.
	 *
	 * @param string $raw        Address.
	 * @param bool   $logo       Whether it is the logo (https and an image).
	 * @param int    $max_length Longest address.
	 * @return string|WP_Error '' when empty.
	 */
	private static function clean_url( string $raw, bool $logo, int $max_length ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( mb_strlen( $raw ) > $max_length ) {
			/* translators: %d: maximum characters */
			return new WP_Error( 'long', sprintf( __( 'can have up to %d characters.', 'subtleforms-license-server' ), $max_length ) );
		}
		$schemes = $logo ? array( 'https' ) : array( 'http', 'https' );
		$parts   = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), $schemes, true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error(
				'scheme',
				$logo ? __( 'must be an https address.', 'subtleforms-license-server' ) : __( 'must be an http or https address.', 'subtleforms-license-server' )
			);
		}
		$url = esc_url_raw( $raw, $schemes );
		if ( '' === $url || mb_strlen( $url ) > $max_length ) {
			return new WP_Error( 'invalid', __( 'is not a valid address.', 'subtleforms-license-server' ) );
		}
		if ( $logo ) {
			$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::IMAGE_EXTENSIONS, true ) ) {
				return new WP_Error( 'image', __( 'must point to a png, jpg, gif, webp or svg image.', 'subtleforms-license-server' ) );
			}
		}
		return $url;
	}

	/**
	 * Boolean from a form or JSON value.
	 *
	 * @param mixed $value Value.
	 */
	private static function to_bool( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'on', 'yes' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Field names for messages.
	 *
	 * @param string $key Profile key.
	 */
	public static function field_label( string $key ): string {
		$labels = array(
			'name'             => __( 'Plugin name', 'subtleforms-license-server' ),
			'menu_name'        => __( 'Menu name', 'subtleforms-license-server' ),
			'logo_url'         => __( 'Logo address', 'subtleforms-license-server' ),
			'support_url'      => __( 'Support address', 'subtleforms-license-server' ),
			'hide_credits'     => __( 'Hide SubtleForms credits', 'subtleforms-license-server' ),
			'restrict_screens' => __( 'Restrict the License screen', 'subtleforms-license-server' ),
			'author'           => __( 'Author', 'subtleforms-license-server' ),
			'author_url'       => __( 'Author address', 'subtleforms-license-server' ),
			'description'      => __( 'Description', 'subtleforms-license-server' ),
		);
		return $labels[ $key ] ?? $key;
	}

	// ── Logo files ──────────────────────────────────────────────────────

	/**
	 * Folder of uploaded logos.
	 */
	public static function logo_dir(): string {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['basedir'] ) . '/' . self::LOGO_DIR;
	}

	/**
	 * Public https address of an uploaded logo.
	 *
	 * @param string $file File name.
	 */
	public static function logo_url( string $file ): string {
		$uploads = wp_upload_dir( null, false );
		return set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::LOGO_DIR . '/' . rawurlencode( $file ), 'https' );
	}

	/**
	 * Address to show a logo at inside the portal: an uploaded logo is served
	 * with the scheme this site is on (clients always get the https address).
	 *
	 * @param string $url Logo address.
	 */
	public static function display_url( string $url ): string {
		$uploads = wp_upload_dir( null, false );
		$base    = set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::LOGO_DIR . '/', 'https' );
		return str_starts_with( $url, $base ) ? set_url_scheme( $url ) : $url;
	}

	/**
	 * Check an uploaded logo by its content and store it under a random name.
	 * The type comes from the image itself, never from the file name or the
	 * type the browser sent. PNG, JPEG, GIF and WebP are accepted, up to 1 MB.
	 *
	 * @param int    $license_id License ID.
	 * @param string $tmp        Path of the uploaded file.
	 * @param int    $error      PHP upload error code.
	 * @return string|WP_Error File name inside the logo folder.
	 */
	public static function store_logo( int $license_id, string $tmp, int $error = UPLOAD_ERR_OK ) {
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return new WP_Error( 'sfls_logo_size', __( 'The logo is larger than 1 MB.', 'subtleforms-license-server' ) );
		}
		if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_file( $tmp ) ) {
			return new WP_Error( 'sfls_logo_upload', __( 'The logo could not be uploaded. Try again.', 'subtleforms-license-server' ) );
		}
		$size = (int) filesize( $tmp );
		if ( $size <= 0 ) {
			return new WP_Error( 'sfls_logo_empty', __( 'The logo file is empty.', 'subtleforms-license-server' ) );
		}
		if ( $size > self::MAX_LOGO_BYTES ) {
			return new WP_Error( 'sfls_logo_size', __( 'The logo is larger than 1 MB.', 'subtleforms-license-server' ) );
		}

		$types = array(
			IMAGETYPE_PNG  => array( 'png', 'image/png' ),
			IMAGETYPE_JPEG => array( 'jpg', 'image/jpeg' ),
			IMAGETYPE_GIF  => array( 'gif', 'image/gif' ),
			IMAGETYPE_WEBP => array( 'webp', 'image/webp' ),
		);
		$info  = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file that is not an image returns false.
		$type  = is_array($info) ? (int) $info[2] : 0;
		$mime  = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = $finfo ? (string) finfo_file( $finfo, $tmp ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
		}
		if ( ! isset( $types[ $type ] ) || ( '' !== $mime && $mime !== $types[ $type ][1] ) ) {
			return new WP_Error( 'sfls_logo_type', __( 'The logo must be a PNG, JPEG, GIF or WebP image.', 'subtleforms-license-server' ) );
		}
		// An image that also carries code is not a logo.
		$head = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== stripos( $head, '<?php' ) || false !== stripos( $head, '<?=' ) || false !== stripos( $head, '<script' ) ) {
			return new WP_Error( 'sfls_logo_type', __( 'The logo must be a PNG, JPEG, GIF or WebP image.', 'subtleforms-license-server' ) );
		}

		$dir = self::logo_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sfls_logo_dir', __( 'The logo could not be stored. Try again later.', 'subtleforms-license-server' ) );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Options -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi)$\">\nRequire all denied\n</FilesMatch>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$name = 'license-' . $license_id . '-' . strtolower( wp_generate_password( 12, false, false ) ) . '.' . $types[ $type ][0];
		$path = $dir . '/' . $name;
		$ok   = is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $path ) : copy( $tmp, $path );
		if ( ! $ok ) {
			return new WP_Error( 'sfls_logo_dir', __( 'The logo could not be stored. Try again later.', 'subtleforms-license-server' ) );
		}
		chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
		return $name;
	}

	/**
	 * Delete an uploaded logo. Only plain file names inside the logo folder.
	 *
	 * @param string $file File name.
	 */
	public static function delete_logo_file( string $file ): void {
		if ( '' === $file || $file !== basename( $file ) ) {
			return;
		}
		$path = self::logo_dir() . '/' . $file;
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	// ── Client sites ────────────────────────────────────────────────────

	/**
	 * Record that a site fetched the branding.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain (the key).
	 * @param string $site_url   Address the site sent.
	 * @param int    $version    Version the site now has.
	 */
	public static function record_fetch( int $license_id, string $domain, string $site_url, int $version ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			SFLS_DB::branding_sites(),
			array(
				'license_id' => $license_id,
				'domain'     => substr( $domain, 0, 190 ),
				'site_url'   => substr( esc_url_raw( $site_url ), 0, 255 ),
				'version'    => $version,
				'fetched_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Sites that fetched the branding and are still activated, newest fetch
	 * first. Activated sites that never fetched are listed last with a null
	 * fetch time.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array{domain:string,site_url:string,version:?int,fetched_at:?string}>
	 */
	public static function client_sites( int $license_id ): array {
		global $wpdb;
		$sites = SFLS_DB::branding_sites();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$sites} WHERE license_id = %d", $license_id ), ARRAY_A );
		$by   = array();
		foreach ( $rows as $row ) {
			$by[ $row['domain'] ] = $row;
		}
		$out = array();
		foreach ( SFLS_Licenses::activations( $license_id ) as $act ) {
			$row   = $by[ $act['domain'] ] ?? null;
			$out[] = array(
				'domain'     => (string) $act['domain'],
				'site_url'   => $row ? (string) $row['site_url'] : '',
				'version'    => $row ? (int) $row['version'] : null,
				'fetched_at' => $row ? (string) $row['fetched_at'] : null,
			);
		}
		usort(
			$out,
			static fn( $a, $b ) => strcmp( (string) $b['fetched_at'], (string) $a['fetched_at'] )
		);
		return $out;
	}

	// ── REST answer ─────────────────────────────────────────────────────

	/**
	 * ISO 8601 time with a Z, as the contract shows.
	 *
	 * @param string $gmt GMT datetime.
	 */
	public static function iso( string $gmt ): ?string {
		return '' !== $gmt ? gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $gmt . ' UTC' ) ) : null;
	}

	/**
	 * What a client site gets from POST /branding, and the fetch log entry.
	 *
	 * @param array    $lic      License (valid, site activated: checked by the caller).
	 * @param string   $domain   Normalised domain.
	 * @param string   $site_url Address the site sent.
	 * @param int|null $sent     Version the site applied, null when it sent none.
	 * @return array
	 */
	public static function answer( array $lic, string $domain, string $site_url, ?int $sent ): array {
		$row = self::plan_allows( $lic ) ? self::get( (int) $lic['id'] ) : null;
		if ( ! $row ) {
			self::record_fetch( (int) $lic['id'], $domain, $site_url, 0 );
			return array(
				'enabled'    => false,
				'version'    => 0,
				'unchanged'  => 0 === $sent,
				'updated_at' => null,
			);
		}
		self::record_fetch( (int) $lic['id'], $domain, $site_url, $row['version'] );
		$unchanged = null !== $sent && $sent === $row['version'];
		$data      = array(
			'enabled'    => $row['enabled'],
			'version'    => $row['version'],
			'unchanged'  => $unchanged,
			'updated_at' => self::iso( $row['updated_at'] ),
		);
		if ( $row['enabled'] && ! $unchanged ) {
			$data['profile'] = $row['profile'];
		}
		return $data;
	}

	// ── Cleanup ─────────────────────────────────────────────────────────

	/**
	 * Remove everything stored for a license (when it is deleted).
	 *
	 * @param int $license_id License ID.
	 */
	public static function purge( int $license_id ): void {
		global $wpdb;
		$row = self::get( $license_id );
		if ( $row && '' !== $row['logo_file'] ) {
			self::delete_logo_file( $row['logo_file'] );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( SFLS_DB::branding(), array( 'license_id' => $license_id ) );
		$wpdb->delete( SFLS_DB::branding_revisions(), array( 'license_id' => $license_id ) );
		$wpdb->delete( SFLS_DB::branding_sites(), array( 'license_id' => $license_id ) );
		// phpcs:enable
	}
}
