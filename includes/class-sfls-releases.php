<?php
/**
 * Pro releases: every uploaded version in the wp_sfls_releases table, the
 * "latest" logic for the update API, entitlements per license, packages in
 * the private folder and short-lived, single-use signed download links.
 *
 * Until 1.5.1 releases lived in two options (sfls_release and
 * sfls_release_history). 1.6.0 copies them into the table once
 * (migrate_from_options) and stops writing them. While the copy has not run,
 * the options are still read as a fallback.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sfls-signing.php';

/**
 * Release store.
 */
final class SFLS_Releases {

	/**
	 * Legacy options (read only, as a fallback, until the migration ran).
	 */
	const OPTION         = 'sfls_release';
	const HISTORY_OPTION = 'sfls_release_history';

	/**
	 * Set once the options were copied into the table.
	 */
	const MIGRATED_OPTION = 'sfls_releases_migrated';

	/**
	 * Version an admin made "the latest" (stable channel), '' when none.
	 */
	const PIN_OPTION = 'sfls_release_pin';

	/**
	 * Option set once the release files were renamed (1.7.0).
	 */
	const PRIVATE_NAMES_OPTION = 'sfls_release_files_private';

	/**
	 * Release settings (auto publish CI releases).
	 */
	const SETTINGS_OPTION = 'sfls_release_settings';

	const CHANNELS = array( 'stable', 'beta' );
	const STATUSES = array( 'draft', 'published', 'yanked' );
	const SOURCES  = array( 'upload', 'api', 'github', 'cli' );

	/**
	 * How long a signed download link works.
	 */
	const LINK_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * The plugin file every release zip must contain.
	 */
	const MAIN_FILE = 'subtleforms-pro/subtleforms-pro.php';

	/**
	 * Rows of this request, newest version first (null = not loaded).
	 *
	 * @var array[]|null
	 */
	private static ?array $cache = null;

	// ── Storage ─────────────────────────────────────────────────────────

	/**
	 * Private folder outside public listing (protected by .htaccess; on Nginx
	 * add a deny rule, see README). Files in it have random names since 1.7.0.
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'sfls-private';
	}

	/**
	 * Release settings.
	 *
	 * @return array{auto_publish:int}
	 */
	public static function settings(): array {
		return wp_parse_args( (array) get_option( self::SETTINGS_OPTION, array() ), array( 'auto_publish' => 0 ) );
	}

	/**
	 * Save release settings.
	 *
	 * @param array $values Values.
	 */
	public static function save_settings( array $values ): void {
		update_option( self::SETTINGS_OPTION, array_merge( self::settings(), array( 'auto_publish' => empty( $values['auto_publish'] ) ? 0 : 1 ) ), false );
	}

	/**
	 * Defaults for one release record.
	 */
	private static function defaults(): array {
		return array(
			'id'             => 0,
			'version'        => '',
			'channel'        => 'stable',
			'status'         => 'published',
			'file'           => '',
			'size'           => 0,
			'sha256'         => '',
			'requires'       => '6.4',
			'tested'         => '',
			'requires_php'   => '8.1',
			'changelog'      => '',
			'source'         => 'upload',
			'git_ref'        => '',
			'git_commit'     => '',
			'notes'          => '',
			'yank_reason'    => '',
			'replaced_files' => '',
			'downloads'      => 0,
			'released_at'    => null,
			'created_at'     => null,
			'updated_at'     => null,
			'updated'        => '',
		);
	}

	/**
	 * Fill in defaults and the legacy "updated" date (Y-m-d of the release).
	 *
	 * @param array $row Row.
	 */
	private static function normalize( array $row ): array {
		$row              = wp_parse_args( $row, self::defaults() );
		$row['id']        = (int) $row['id'];
		$row['size']      = (int) $row['size'];
		$row['downloads'] = (int) $row['downloads'];
		$row['changelog'] = (string) $row['changelog'];
		$row['notes']     = (string) $row['notes'];
		if ( ! empty( $row['released_at'] ) ) {
			$row['updated'] = substr( (string) $row['released_at'], 0, 10 );
		}
		return $row;
	}

	/**
	 * Whether the table holds the releases (the 1.6.0 migration ran).
	 */
	public static function uses_table(): bool {
		return (bool) get_option( self::MIGRATED_OPTION );
	}

	/**
	 * Every release, any status, highest version first.
	 *
	 * @return array[]
	 */
	public static function rows(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		if ( ! self::uses_table() ) {
			return self::legacy_rows(); // Fallback until the migration ran.
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sfls_releases", ARRAY_A );
		$rows = array_map( array( self::class, 'normalize' ), $rows );
		usort( $rows, static fn( $a, $b ) => version_compare( $b['version'], $a['version'] ) );
		self::$cache = $rows;
		return $rows;
	}

	/**
	 * The 1.5.1 options as rows (current release first).
	 *
	 * @return array[]
	 */
	private static function legacy_rows(): array {
		$rows    = array();
		$current = (array) get_option( self::OPTION, array() );
		if ( ! empty( $current['version'] ) ) {
			$rows[] = $current;
		}
		foreach ( (array) get_option( self::HISTORY_OPTION, array() ) as $old ) {
			if ( ! empty( $old['version'] ) ) {
				$rows[] = (array) $old;
			}
		}
		$out = array();
		foreach ( $rows as $row ) {
			$row['released_at'] = ! empty( $row['updated'] ) ? $row['updated'] . ' 12:00:00' : null;
			$out[]              = self::normalize( $row );
		}
		usort( $out, static fn( $a, $b ) => version_compare( $b['version'], $a['version'] ) );
		return $out;
	}

	/**
	 * Forget the rows read in this request.
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Copy the 1.5.1 options into the table, once. The options are left in
	 * place (read as a fallback by 1.6.0 only until this ran) and no longer
	 * written. Returns how many releases were copied.
	 */
	public static function migrate_from_options(): int {
		global $wpdb;
		if ( self::uses_table() ) {
			return 0;
		}
		$table = SFLS_DB::releases();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0; // Table missing: keep reading the options.
		}
		$legacy  = self::legacy_rows();
		$current = (string) ( $legacy[0]['version'] ?? '' );
		$copied  = 0;
		foreach ( $legacy as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sfls_releases WHERE version = %s", (string) $row['version'] ) ) ) {
				continue;
			}
			$path = '' !== $row['file'] ? trailingslashit( self::dir() ) . basename( (string) $row['file'] ) : '';
			$has  = '' !== $path && is_readable( $path );
			$now  = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'version'      => substr( sanitize_text_field( (string) $row['version'] ), 0, 40 ),
					'channel'      => 'stable',
					'status'       => 'published',
					'file'         => $has ? basename( $path ) : '',
					'size'         => $has ? (int) filesize( $path ) : 0,
					'sha256'       => $has ? (string) hash_file( 'sha256', $path ) : '',
					'requires'     => (string) $row['requires'],
					'tested'       => (string) $row['tested'],
					'requires_php' => (string) $row['requires_php'],
					'changelog'    => (string) $row['changelog'],
					'source'       => 'upload',
					'released_at'  => $row['released_at'] ? $row['released_at'] : $now,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
			++$copied;
		}
		update_option( self::MIGRATED_OPTION, gmdate( 'c' ), false );
		self::flush();

		// The 1.5.1 current release was the latest by definition. Should a
		// higher version sit in the history, keep the old behaviour by pinning.
		$highest = self::latest( 'stable', false );
		if ( '' !== $current && $highest && $highest['version'] !== $current ) {
			update_option( self::PIN_OPTION, $current, false );
		}
		return $copied;
	}

	// ── Reading ─────────────────────────────────────────────────────────

	/**
	 * One release by version (any status), or null.
	 *
	 * @param string $version Version.
	 * @param bool   $fresh   Skip the request cache.
	 */
	public static function get( string $version, bool $fresh = false ): ?array {
		if ( $fresh ) {
			self::flush();
		}
		foreach ( self::rows() as $row ) {
			if ( $row['version'] === $version ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * One release by ID, or null.
	 *
	 * @param int $id ID.
	 */
	public static function get_by_id( int $id ): ?array {
		foreach ( self::rows() as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Whether a release may be offered and downloaded at all.
	 *
	 * @param array $release Release.
	 */
	public static function is_live( array $release ): bool {
		return 'published' === $release['status'];
	}

	/**
	 * Published releases (never drafts or yanked ones), highest first.
	 *
	 * @param string $channel stable (stable only) or beta (stable and beta) or '' for both.
	 * @return array[]
	 */
	public static function published( string $channel = '' ): array {
		return array_values(
			array_filter(
				self::rows(),
				static fn( $r ) => self::is_live( $r ) && ( 'stable' !== $channel || 'stable' === $r['channel'] )
			)
		);
	}

	/**
	 * The version an admin made the latest, '' when none.
	 */
	public static function pinned(): string {
		return (string) get_option( self::PIN_OPTION, '' );
	}

	/**
	 * The release the update API offers. Stable sites get the highest
	 * published stable version, or the pinned one. Beta sites get the highest
	 * published version of either channel (stable versions above a pin are
	 * left out for them too).
	 *
	 * @param string $channel  stable | beta.
	 * @param bool   $use_pin  Respect the pin.
	 */
	public static function latest( string $channel = 'stable', bool $use_pin = true ): ?array {
		$channel = in_array( $channel, self::CHANNELS, true ) ? $channel : 'stable';
		$rows    = self::published();
		$pin     = $use_pin ? self::pinned() : '';
		if ( '' !== $pin ) {
			$pinned = self::get( $pin );
			if ( $pinned && self::is_live( $pinned ) && 'stable' === $pinned['channel'] ) {
				$rows = array_filter( $rows, static fn( $r ) => 'stable' !== $r['channel'] || version_compare( $r['version'], $pin, '<=' ) );
			}
		}
		foreach ( $rows as $row ) {
			if ( 'beta' === $channel || 'stable' === $row['channel'] ) {
				return $row; // Rows are sorted highest first.
			}
		}
		return null;
	}

	/**
	 * The current (latest stable) release, or an empty record.
	 *
	 * @return array
	 */
	public static function current(): array {
		$latest = self::latest( 'stable' );
		return $latest ? $latest : self::defaults();
	}

	/**
	 * Every published release except the current one, highest first.
	 *
	 * @return array[]
	 */
	public static function history(): array {
		$current = self::current()['version'];
		return array_values( array_filter( self::published(), static fn( $r ) => $r['version'] !== $current ) );
	}

	/**
	 * Every published release, the current one first, then the others
	 * highest first.
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
	 * Find a published release by version.
	 *
	 * @param string $version Version.
	 */
	public static function find( string $version ): ?array {
		$release = self::get( $version );
		return $release && self::is_live( $release ) ? $release : null;
	}

	// ── Writing ─────────────────────────────────────────────────────────

	/**
	 * Update a release.
	 *
	 * @param int   $id   ID.
	 * @param array $data Columns.
	 */
	public static function update( int $id, array $data ): void {
		global $wpdb;
		$allowed = array_flip( array( 'channel', 'status', 'file', 'size', 'sha256', 'requires', 'tested', 'requires_php', 'changelog', 'source', 'git_ref', 'git_commit', 'notes', 'yank_reason', 'package_sig', 'replaced_files', 'downloads', 'released_at' ) );
		$data    = array_intersect_key( $data, $allowed );
		if ( isset( $data['channel'] ) && ! in_array( $data['channel'], self::CHANNELS, true ) ) {
			unset( $data['channel'] );
		}
		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::STATUSES, true ) ) {
			unset( $data['status'] );
		}
		$data['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( SFLS_DB::releases(), $data, array( 'id' => $id ) );
		self::flush();
	}

	/**
	 * Insert a release row.
	 *
	 * @param array $data Columns.
	 * @return array The new release.
	 */
	private static function insert( array $data ): array {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			array(
				'channel'    => 'stable',
				'status'     => 'draft',
				'source'     => 'upload',
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( SFLS_DB::releases(), $data );
		self::flush();
		return (array) self::get( (string) $data['version'] );
	}

	/**
	 * Publish a release (the first publish sets its release date).
	 *
	 * @param int $id ID.
	 * @return array|WP_Error
	 */
	public static function publish( int $id ) {
		$release = self::get_by_id( $id );
		if ( ! $release ) {
			return new WP_Error( 'not_found', __( 'That release was not found.', 'subtleforms-license-server' ), array( 'status' => 404 ) );
		}
		if ( ! self::has_package( $release ) ) {
			return new WP_Error( 'no_package', __( 'This release has no package, so it cannot be published. Upload its zip first.', 'subtleforms-license-server' ), array( 'status' => 409 ) );
		}
		self::update(
			$id,
			array(
				'status'      => 'published',
				'yank_reason' => '',
				'released_at' => $release['released_at'] ? $release['released_at'] : current_time( 'mysql', true ),
			)
		);
		$release = (array) self::get_by_id( $id );
		do_action( 'sfls_release_published', $release );
		return $release;
	}

	/**
	 * Move a release back to draft.
	 *
	 * @param int $id ID.
	 */
	public static function unpublish( int $id ): void {
		self::update( $id, array( 'status' => 'draft' ) );
		self::drop_pin_if( $id );
	}

	/**
	 * Yank a release: it is never offered or downloadable again until it is
	 * published again, so the previous published version becomes the latest.
	 *
	 * @param int    $id     ID.
	 * @param string $reason Why (shown to admins only).
	 */
	public static function yank( int $id, string $reason = '' ): void {
		self::update(
			$id,
			array(
				'status'      => 'yanked',
				'yank_reason' => substr( sanitize_text_field( $reason ), 0, 255 ),
			)
		);
		self::drop_pin_if( $id );
		do_action( 'sfls_release_yanked', self::get_by_id( $id ) );
	}

	/**
	 * Unpin when the pinned release stops being published.
	 *
	 * @param int $id ID.
	 */
	private static function drop_pin_if( int $id ): void {
		$release = self::get_by_id( $id );
		if ( $release && self::pinned() === $release['version'] ) {
			self::unpin();
		}
	}

	/**
	 * Make an older stable version the latest.
	 *
	 * @param string $version Version.
	 * @return true|WP_Error
	 */
	public static function pin( string $version ) {
		$release = self::get( $version );
		if ( ! $release || ! self::is_live( $release ) || 'stable' !== $release['channel'] ) {
			return new WP_Error( 'cannot_pin', __( 'Only a published stable release can be made the latest.', 'subtleforms-license-server' ), array( 'status' => 409 ) );
		}
		update_option( self::PIN_OPTION, $version, false );
		return true;
	}

	/**
	 * Go back to "highest published stable version is the latest".
	 */
	public static function unpin(): void {
		delete_option( self::PIN_OPTION );
	}

	/**
	 * Delete a release and its package files. Only ever called when an admin
	 * (or a deploy token, or WP-CLI with --yes) explicitly deletes it.
	 *
	 * @param int $id ID.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$release = self::get_by_id( $id );
		if ( ! $release ) {
			return false;
		}
		$files = array_filter( array_merge( array( $release['file'] ), self::replaced_files( $release ) ) );
		foreach ( $files as $file ) {
			$path = trailingslashit( self::dir() ) . basename( (string) $file );
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( self::pinned() === $release['version'] ) {
			self::unpin();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( SFLS_DB::releases(), array( 'id' => $id ) );
		self::flush();
		SFLS_Signing::forget( $id );
		do_action( 'sfls_release_deleted', $release );
		return true;
	}

	/**
	 * Count one download of a release.
	 *
	 * @param int $id ID.
	 */
	public static function count_download( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}sfls_releases SET downloads = downloads + 1 WHERE id = %d", $id ) );
		self::flush();
	}

	/**
	 * Earlier package files of a release that was re-uploaded. They are kept
	 * until the release is deleted.
	 *
	 * @param array $release Release.
	 * @return string[]
	 */
	public static function replaced_files( array $release ): array {
		$list = json_decode( (string) $release['replaced_files'], true );
		return is_array( $list ) ? array_values( array_filter( array_map( 'basename', array_map( 'strval', $list ) ) ) ) : array();
	}

	// ── Adding packages ─────────────────────────────────────────────────

	/**
	 * Package size for display: whole bytes under 1 KB ("478 B"), one
	 * decimal above ("1.5 MB").
	 *
	 * @param int $bytes Size in bytes.
	 */
	public static function size_label( int $bytes ): string {
		if ( $bytes <= 0 ) {
			return '';
		}
		return (string) size_format( $bytes, $bytes < KB_IN_BYTES ? 0 : 1 );
	}

	/**
	 * Largest package accepted, in bytes (default 50 MB).
	 */
	public static function max_size(): int {
		return max( 1, (int) apply_filters( 'sfls_release_max_size', 50 * MB_IN_BYTES ) );
	}

	/**
	 * Store a release zip from a local file: checks size, sha256 and layout
	 * with inspect_zip(), then copies it into the private folder and creates
	 * or updates the release. The source file is never moved or deleted.
	 *
	 * The same version with the same sha256 returns the existing release
	 * ("existing"). The same version with another package is refused
	 * unless `replace` is true or the existing release is a draft; the old
	 * file is kept until the release is deleted. Replacing the package of a
	 * published or yanked release from the API, GitHub or WP-CLI also needs
	 * `confirm_replace` (the admin upload form is its own confirmation: a
	 * ticked box behind a nonce), and every such replacement is logged
	 * (SFLS_Signing::log_replacement). Stored packages are signed when a
	 * signing key is configured.
	 *
	 * @param string $path Local zip.
	 * @param array  $args {
	 *     @type string $source        upload | api | github | cli.
	 *     @type string $channel       stable | beta.
	 *     @type bool   $publish       Publish now (else a new release is a draft).
	 *     @type string $changelog     Changelog (one change per line).
	 *     @type string $git_ref       Tag or branch.
	 *     @type string $git_commit    Commit SHA.
	 *     @type string $sha256        Expected sha256 (optional).
	 *     @type bool   $replace       Replace another package of the same version.
	 *     @type bool   $confirm_replace Second flag to replace a published package, not needed for upload.
	 *     @type string $expect_version Version the zip must have (a tag like v2.3.4 is fine).
	 *     @type string $notes         Private notes.
	 * }
	 * @return array{release:array,result:string}|WP_Error result is created, replaced or existing.
	 */
	public static function ingest( string $path, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'source'          => 'upload',
				'channel'         => '',
				'publish'         => false,
				'changelog'       => '',
				'git_ref'         => '',
				'git_commit'      => '',
				'sha256'          => '',
				'replace'         => false,
				'confirm_replace' => false,
				'expect_version'  => '',
				'notes'           => '',
			)
		);

		if ( ! self::uses_table() ) {
			return new WP_Error( 'not_ready', __( 'The releases table is not ready yet. Reload the page and try again.', 'subtleforms-license-server' ), array( 'status' => 503 ) );
		}
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'no_file', __( 'The package file could not be read.', 'subtleforms-license-server' ), array( 'status' => 400 ) );
		}
		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			return new WP_Error( 'empty_file', __( 'The package file is empty.', 'subtleforms-license-server' ), array( 'status' => 400 ) );
		}
		if ( $size > self::max_size() ) {
			return new WP_Error(
				'too_large',
				sprintf(
					/* translators: 1: file size, 2: limit */
					__( 'The package is %1$s, more than the %2$s limit.', 'subtleforms-license-server' ),
					size_format( $size ),
					size_format( self::max_size() )
				),
				array( 'status' => 413 )
			);
		}
		$sha256   = (string) hash_file( 'sha256', $path );
		$expected = strtolower( trim( (string) $args['sha256'] ) );
		if ( '' !== $expected && ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, $sha256 ) ) ) {
			return new WP_Error( 'sha256_mismatch', __( 'The package does not match the sha256 that was sent with it.', 'subtleforms-license-server' ), array( 'status' => 422 ) );
		}
		$headers = self::inspect_zip( $path );
		if ( is_wp_error( $headers ) ) {
			$headers->add_data( array( 'status' => 422 ) );
			return $headers;
		}
		$version = $headers['version'];
		$expect  = self::version_from_tag( (string) $args['expect_version'] );
		if ( '' !== (string) $args['expect_version'] && $expect !== $version ) {
			return new WP_Error(
				'version_mismatch',
				sprintf(
					/* translators: 1: tag or expected version, 2: version in the zip */
					__( 'The tag %1$s does not match the plugin Version header %2$s in the zip.', 'subtleforms-license-server' ),
					(string) $args['expect_version'],
					$version
				),
				array( 'status' => 422 )
			);
		}

		$source  = in_array( $args['source'], self::SOURCES, true ) ? $args['source'] : 'upload';
		$channel = in_array( $args['channel'], self::CHANNELS, true ) ? $args['channel'] : '';
		$fields  = array(
			'size'         => $size,
			'sha256'       => $sha256,
			'requires'     => $headers['requires'],
			'tested'       => $headers['tested'],
			'requires_php' => $headers['requires_php'],
			'source'       => $source,
		);
		foreach ( array( 'git_ref', 'git_commit' ) as $git ) {
			if ( '' !== (string) $args[ $git ] ) {
				$fields[ $git ] = substr( sanitize_text_field( (string) $args[ $git ] ), 0, 'git_ref' === $git ? 190 : 64 );
			}
		}
		$changelog = trim( sanitize_textarea_field( (string) $args['changelog'] ) );

		$existing = self::get( $version, true );
		if ( $existing ) {
			if ( '' !== $existing['sha256'] && hash_equals( $existing['sha256'], $sha256 ) && self::has_package( $existing ) ) {
				if ( $args['publish'] && 'draft' === $existing['status'] ) {
					$published = self::publish( $existing['id'] );
					$existing  = is_wp_error( $published ) ? $existing : $published;
				}
				if ( '' === SFLS_Signing::release_signature( $existing ) ) {
					SFLS_Signing::sign_release( $existing );
				}
				return array(
					'release' => $existing,
					'result'  => 'existing',
				);
			}
			if ( ! $args['replace'] && 'draft' !== $existing['status'] && self::has_package( $existing ) ) {
				return new WP_Error(
					'version_exists',
					sprintf(
						/* translators: %s: version */
						__( 'Version %s already exists with a different package. Send replace=true to replace it.', 'subtleforms-license-server' ),
						$version
					),
					array( 'status' => 409 )
				);
			}
			$live = 'draft' !== $existing['status'] && self::has_package( $existing );
			if ( $live && 'upload' !== $source && ! $args['confirm_replace'] ) {
				return new WP_Error(
					'replace_unconfirmed',
					sprintf(
						/* translators: %s: version */
						__( 'Version %s is already released to customers. To replace its package, send confirm_replace=true together with replace=true.', 'subtleforms-license-server' ),
						$version
					),
					array( 'status' => 409 )
				);
			}
			$file = self::store_file( $path, $version, $sha256 );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			if ( $live ) {
				SFLS_Signing::log_replacement( $existing, $sha256, $source );
			}
			$replaced = self::replaced_files( $existing );
			if ( '' !== $existing['file'] && $existing['file'] !== $file ) {
				$replaced[] = $existing['file'];
			}
			$fields['file']           = $file;
			$fields['replaced_files'] = $replaced ? (string) wp_json_encode( array_values( array_unique( $replaced ) ) ) : '';
			if ( '' !== $changelog ) {
				$fields['changelog'] = $changelog;
			}
			if ( '' !== $channel ) {
				$fields['channel'] = $channel;
			}
			if ( '' !== (string) $args['notes'] ) {
				$fields['notes'] = sanitize_textarea_field( (string) $args['notes'] );
			}
			self::update( $existing['id'], $fields );
			$release = (array) self::get_by_id( $existing['id'] );
			SFLS_Signing::sign_release( $release );
			if ( $args['publish'] && 'published' !== $release['status'] ) {
				$published = self::publish( $release['id'] );
				$release   = is_wp_error( $published ) ? $release : $published;
			}
			do_action( 'sfls_release_stored', $release, 'replaced' );
			return array(
				'release' => $release,
				'result'  => 'replaced',
			);
		}

		$file = self::store_file( $path, $version, $sha256 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$release = self::insert(
			array_merge(
				$fields,
				array(
					'version'   => substr( $version, 0, 40 ),
					'channel'   => '' !== $channel ? $channel : ( str_contains( $version, '-' ) ? 'beta' : 'stable' ),
					'status'    => 'draft',
					'file'      => $file,
					'changelog' => $changelog,
					'notes'     => sanitize_textarea_field( (string) $args['notes'] ),
				)
			)
		);
		SFLS_Signing::sign_release( $release );
		if ( $args['publish'] ) {
			$published = self::publish( $release['id'] );
			$release   = is_wp_error( $published ) ? $release : $published;
		}
		do_action( 'sfls_release_stored', $release, 'created' );
		return array(
			'release' => $release,
			'result'  => 'created',
		);
	}

	/**
	 * "2.3.4" from a tag like "v2.3.4", "V2.3.4" or "2.3.4".
	 *
	 * @param string $tag Tag.
	 */
	public static function version_from_tag( string $tag ): string {
		$tag = trim( $tag );
		if ( str_starts_with( $tag, 'refs/tags/' ) ) {
			$tag = substr( $tag, 10 );
		}
		return (string) preg_replace( '/^[vV](?=\d)/', '', $tag );
	}

	/**
	 * Copy a zip into the private folder under a name nobody can guess:
	 * subtleforms-pro-<version>-<32 random hex>.zip. The folder is also
	 * closed by .htaccess, but on servers that ignore it (Nginx without a
	 * deny rule) a random name keeps the package from being fetched directly;
	 * customers only ever get it through the signed download endpoint. Only
	 * a basename is ever used, so a version can never point outside the
	 * folder.
	 *
	 * @param string $path    Source.
	 * @param string $version Version.
	 * @param string $sha256  Hash of the source (unused, kept for callers).
	 * @return string|WP_Error File name.
	 */
	private static function store_file( string $path, string $version, string $sha256 ) {
		unset( $sha256 );
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$name = self::private_name( $version );
		$dest = trailingslashit( $dir ) . $name;
		if ( ! copy( $path, $dest ) ) {
			return new WP_Error( 'store_failed', __( 'The package could not be saved in the private releases folder. Check that wp-content/uploads is writable.', 'subtleforms-license-server' ), array( 'status' => 500 ) );
		}
		return $name;
	}

	/**
	 * A new random package file name for a version.
	 *
	 * @param string $version Version.
	 */
	public static function private_name( string $version ): string {
		$base = sanitize_file_name( 'subtleforms-pro-' . preg_replace( '/[^0-9A-Za-z.\-+]/', '', $version ) );
		return basename( $base . '-' . bin2hex( random_bytes( 16 ) ) . '.zip' );
	}

	/**
	 * Whether a stored file name has the random part.
	 *
	 * @param string $file File name.
	 */
	public static function is_private_name( string $file ): bool {
		return (bool) preg_match( '/-[a-f0-9]{32}\.zip$/', $file );
	}

	/**
	 * 1.7.0: rename package files stored under a predictable name
	 * (subtleforms-pro-<version>.zip) to a random one, current and replaced
	 * files alike. Runs once, after the releases moved into the table.
	 * Returns how many files were renamed.
	 */
	public static function make_names_private(): int {
		global $wpdb;
		if ( get_option( self::PRIVATE_NAMES_OPTION ) || ! self::uses_table() ) {
			return 0;
		}
		$dir     = trailingslashit( self::dir() );
		$renamed = 0;
		$move    = static function ( string $file, string $version ) use ( $dir, &$renamed ): string {
			$file = basename( $file );
			if ( '' === $file || self::is_private_name( $file ) || ! is_file( $dir . $file ) ) {
				return $file;
			}
			$name = self::private_name( $version );
			if ( ! @rename( $dir . $file, $dir . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Same folder; a failure leaves the old name.
				return $file;
			}
			++$renamed;
			return $name;
		};
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( "SELECT id, version, file, replaced_files FROM {$wpdb->prefix}sfls_releases", ARRAY_A ) as $row ) {
			$data = array();
			$file = $move( (string) $row['file'], (string) $row['version'] );
			if ( $file !== (string) $row['file'] ) {
				$data['file'] = $file;
			}
			$old = json_decode( (string) $row['replaced_files'], true );
			if ( is_array( $old ) && $old ) {
				$new = array_map( static fn( $f ) => $move( (string) $f, (string) $row['version'] ), $old );
				if ( $new !== $old ) {
					$data['replaced_files'] = (string) wp_json_encode( array_values( $new ) );
				}
			}
			if ( $data ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( SFLS_DB::releases(), $data, array( 'id' => (int) $row['id'] ) );
			}
		}
		update_option( self::PRIVATE_NAMES_OPTION, gmdate( 'c' ), true );
		self::flush();
		return $renamed;
	}

	/**
	 * Hosts packages may be downloaded from (package_url and GitHub assets).
	 * An entry starting with a dot matches any subdomain.
	 *
	 * @return string[]
	 */
	public static function download_hosts(): array {
		return (array) apply_filters( 'sfls_release_download_hosts', array( 'github.com', 'api.github.com', 'codeload.github.com', '.githubusercontent.com' ) );
	}

	/**
	 * Whether a URL may be downloaded: https and an allowed host.
	 *
	 * @param string $url URL.
	 */
	public static function download_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		foreach ( self::download_hosts() as $allowed ) {
			$allowed = strtolower( (string) $allowed );
			if ( $host === $allowed || ( str_starts_with( $allowed, '.' ) && str_ends_with( $host, $allowed ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Download a package to a temporary file. Redirects are followed by hand
	 * (up to 5), every hop must be https on an allowed host, and the extra
	 * headers (for example a GitHub token) are only sent to the first host.
	 * The caller deletes the file.
	 *
	 * @param string $url     URL.
	 * @param array  $headers Extra headers for the first request.
	 * @return string|WP_Error Temp file path.
	 */
	public static function download_package( string $url, array $headers = array() ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$first = (string) wp_parse_url( $url, PHP_URL_HOST );
		for ( $hop = 0; $hop <= 5; $hop++ ) {
			if ( ! self::download_allowed( $url ) ) {
				return new WP_Error( 'url_not_allowed', __( 'Packages can only be downloaded over https from GitHub. Use the sfls_release_download_hosts filter to allow another host.', 'subtleforms-license-server' ), array( 'status' => 400 ) );
			}
			$tmp = wp_tempnam( 'sfls-release.zip' );
			$res = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 120,
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $tmp,
					'limit_response_size' => self::max_size() + 1,
					'user-agent'          => 'SubtleForms-License-Server/' . SFLS_VERSION,
					'headers'             => (string) wp_parse_url( $url, PHP_URL_HOST ) === $first ? $headers : array(),
				)
			);
			if ( is_wp_error( $res ) ) {
				wp_delete_file( $tmp );
				$res->add_data( array( 'status' => 502 ) );
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code >= 300 && $code < 400 ) {
				wp_delete_file( $tmp );
				$location = (string) wp_remote_retrieve_header( $res, 'location' );
				if ( '' === $location ) {
					break;
				}
				$url = $location;
				continue;
			}
			if ( 200 !== $code ) {
				wp_delete_file( $tmp );
				return new WP_Error(
					'download_failed',
					sprintf(
						/* translators: %d: HTTP status */
						__( 'The package download failed with HTTP %d.', 'subtleforms-license-server' ),
						$code
					),
					array( 'status' => 502 )
				);
			}
			// A filtered (pre_http_request) response has a body but no file.
			$body = (string) wp_remote_retrieve_body( $res );
			if ( '' !== $body && ( ! is_file( $tmp ) || 0 === (int) filesize( $tmp ) ) ) {
				file_put_contents( $tmp, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			clearstatcache( true, $tmp );
			if ( (int) filesize( $tmp ) > self::max_size() ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'too_large', __( 'The package is larger than the size limit.', 'subtleforms-license-server' ), array( 'status' => 413 ) );
			}
			return $tmp;
		}
		return new WP_Error( 'too_many_redirects', __( 'The package download was redirected too often.', 'subtleforms-license-server' ), array( 'status' => 502 ) );
	}

	// ── Entitlements ────────────────────────────────────────────────────

	/**
	 * The last moment a license was valid, for licenses that are no longer
	 * valid (expired, suspended or refunded). Null while it is valid: then
	 * every published release may be downloaded.
	 *
	 * @param array $lic License.
	 */
	public static function cutoff( array $lic ): ?int {
		if ( SFLS_Licenses::state( $lic )['valid'] ) {
			return null;
		}
		$ends = array();
		if ( ! empty( $lic['expires_at'] ) ) {
			$ends[] = (int) strtotime( $lic['expires_at'] . ' UTC' );
		}
		if ( in_array( $lic['status'], array( 'suspended', 'refunded' ), true ) && ! empty( $lic['updated_at'] ) ) {
			$ends[] = (int) strtotime( $lic['updated_at'] . ' UTC' ); // When it was revoked, as far as we know.
		}
		$cutoff = $ends ? min( $ends ) : 0;
		return (int) apply_filters( 'sfls_release_cutoff', $cutoff, $lic );
	}

	/**
	 * Whether a license may download a release: it must be published, and a
	 * license that is no longer valid keeps the versions released while it was.
	 *
	 * @param array $lic     License.
	 * @param array $release Release.
	 */
	public static function allowed( array $lic, array $release ): bool {
		if ( ! self::is_live( $release ) ) {
			return false;
		}
		$cutoff = self::cutoff( $lic );
		if ( null === $cutoff ) {
			return true;
		}
		$released = ! empty( $release['released_at'] ) ? (int) strtotime( $release['released_at'] . ' UTC' ) : 0;
		return $released > 0 && $released <= $cutoff;
	}

	/**
	 * Releases a license may download, highest first.
	 *
	 * @param array  $lic     License.
	 * @param string $channel stable (no betas) or beta.
	 * @return array[]
	 */
	public static function allowed_for( array $lic, string $channel = 'beta' ): array {
		return array_values( array_filter( self::published( 'beta' === $channel ? '' : 'stable' ), static fn( $r ) => self::allowed( $lic, $r ) ) );
	}

	/**
	 * How many sites last checked in with each Pro version (sites of valid
	 * licenses only), from the activations table.
	 *
	 * @return array<string,int> version => sites, highest first.
	 */
	public static function site_versions(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			"SELECT a.plugin_version AS version, l.status, l.expires_at, l.trial, COUNT(*) AS sites
			FROM {$wpdb->prefix}sfls_activations a
			INNER JOIN {$wpdb->prefix}sfls_licenses l ON l.id = a.license_id
			WHERE a.plugin_version <> ''
			GROUP BY a.plugin_version, l.status, l.expires_at, l.trial",
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! SFLS_Licenses::state( $row )['valid'] ) {
				continue;
			}
			$out[ $row['version'] ] = ( $out[ $row['version'] ] ?? 0 ) + (int) $row['sites'];
		}
		uksort( $out, static fn( $a, $b ) => version_compare( (string) $b, (string) $a ) );
		return $out;
	}

	// ── Packages ────────────────────────────────────────────────────────

	/**
	 * Absolute path of a release's package, or '' when it has none.
	 *
	 * @param array|null $release Release, defaults to current.
	 */
	public static function package_path( ?array $release = null ): string {
		$file = (string) ( $release ?? self::current() )['file'];
		return '' !== $file ? trailingslashit( self::dir() ) . basename( $file ) : '';
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
		if ( ! empty( $release['released_at'] ) ) {
			$ts = strtotime( $release['released_at'] . ' UTC' );
		} elseif ( ! empty( $release['updated'] ) ) {
			$ts = strtotime( $release['updated'] . ' 12:00:00 UTC' );
		} else {
			return '';
		}
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
	 * Markdown release notes (GitHub) as this plugin's plain changelog: one
	 * change per line, bullets as "* ", headings, emphasis, code marks and
	 * links reduced to their text.
	 *
	 * @param string $markdown Markdown.
	 */
	public static function markdown_to_changelog( string $markdown ): string {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $markdown ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || preg_match( '/^(<!--.*-->|---+|\*\*\*+|```.*)$/', $line ) ) {
				continue;
			}
			$bullet = (bool) preg_match( '/^([\*\-\+]|\d+[.)])\s+/', $line );
			$line   = (string) preg_replace( '/^([\*\-\+]|\d+[.)])\s+/', '', $line );
			$line   = (string) preg_replace( '/^#{1,6}\s*/', '', $line );
			$line   = (string) preg_replace( '/!\[([^\]]*)\]\([^)]*\)/', '$1', $line );
			$line   = (string) preg_replace( '/\[([^\]]+)\]\([^)]*\)/', '$1', $line );
			$line   = (string) preg_replace( '/(\*\*|__|`)/', '', $line );
			$line   = (string) preg_replace( '/(^|\s)[\*_]([^\*_]+)[\*_](?=\s|$|[.,;:!?])/', '$1$2', $line );
			$line   = trim( wp_strip_all_tags( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$out[] = $bullet ? '* ' . $line : $line;
		}
		return implode( "\n", $out );
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

	// ── Signed links ────────────────────────────────────────────────────

	/**
	 * Signed download URL: valid for 15 minutes, works once, bound to a
	 * license key, a domain and (since 1.6.0) a version. Since 1.7.0 the URL
	 * names the license by ID and never carries the key itself (it would end
	 * up in server access logs); the signature still covers the key, so the
	 * link cannot be made without knowing it.
	 *
	 * @param string $key     License key.
	 * @param string $domain  Domain ("account" for the members area).
	 * @param string $version Version ('' for the current release, 1.5 links).
	 */
	public static function signed_url( string $key, string $domain, string $version = '' ): string {
		$lic     = SFLS_Licenses::find_by_key( $key );
		$expires = time() + self::LINK_TTL;
		$token   = bin2hex( random_bytes( 16 ) );
		$sig     = self::sign( $key, $domain, $expires, $token, $version );
		$args    = array(
			'lid'     => $lic ? (int) $lic['id'] : 0,
			'site'    => rawurlencode( $domain ),
			'expires' => $expires,
			'token'   => $token,
			'sig'     => $sig,
		);
		if ( '' !== $version ) {
			$args['version'] = rawurlencode( $version );
		}
		return add_query_arg( $args, rest_url( 'sfls/v1/update/download' ) );
	}

	/**
	 * HMAC for a download link. Links without a version keep the 1.5 message,
	 * so links handed out just before an update still verify.
	 *
	 * @param string $key     License key.
	 * @param string $domain  Domain.
	 * @param int    $expires Expiry timestamp.
	 * @param string $token   One-time token.
	 * @param string $version Version, '' for the current release.
	 */
	public static function sign( string $key, string $domain, int $expires, string $token = '', string $version = '' ): string {
		$message = strtoupper( $key ) . '|' . $domain . '|' . $expires . '|' . $token;
		if ( '' !== $version ) {
			$message .= '|v=' . $version;
		}
		return hash_hmac( 'sha256', $message, wp_salt( 'auth' ) . 'subtleforms-license-server' );
	}

	/**
	 * Check a download link signature and expiry (does not use it up).
	 *
	 * @param string $key     License key.
	 * @param string $domain  Domain.
	 * @param int    $expires Expiry timestamp.
	 * @param string $sig     Signature.
	 * @param string $token   One-time token.
	 * @param string $version Version the link was signed for ('' = current).
	 */
	public static function verify( string $key, string $domain, int $expires, string $sig, string $token = '', string $version = '' ): bool {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false; // Links without a one-time token (1.2.0 and older) are no longer accepted.
		}
		return $expires >= time() && $expires <= time() + self::LINK_TTL && hash_equals( self::sign( $key, $domain, $expires, $token, $version ), $sig );
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
	 * Token for the stable package address a site gets from update/check and
	 * update/versions, so that address names the license by ID instead of
	 * carrying its key. Changes when the key is regenerated.
	 *
	 * @param array  $lic    License.
	 * @param string $domain Normalised domain.
	 */
	public static function package_token( array $lic, string $domain ): string {
		return hash_hmac( 'sha256', 'package|' . (int) $lic['id'] . '|' . strtoupper( (string) $lic['license_key'] ) . '|' . $domain, wp_salt( 'auth' ) . 'subtleforms-license-server' );
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
				$parts                     = explode( '/', (string) $zip->getNameIndex( $i ) );
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
		if ( '' === $headers['version'] || strlen( $headers['version'] ) > 40 || ! preg_match( '/^\d+(\.\d+){0,3}([-.+][0-9A-Za-z.]+)?$/', $headers['version'] ) ) {
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
	 * A note when a version is lower than the current release, or '' when it
	 * is not. Since 1.6.0 lower versions are accepted (they are listed as
	 * older versions and never become the latest by themselves), so this is
	 * only shown as information.
	 *
	 * @param string $version Version.
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
