<?php
/**
 * Extension catalog: the extensions the license server distributes, which
 * plan includes each one, and what a license may download.
 *
 * An extension is a product of the releases table (see SFLS_Releases). It is
 * entitled by plan: a license may use every published extension whose
 * min_plan is at or below the plan of the license.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalog table access, entitlement and the public catalog.
 */
final class SFLS_Extensions {

	const CATEGORIES = array( 'payments', 'integrations', 'fields', 'entries', 'site' );
	const STATUSES   = array( 'draft', 'published' );

	/**
	 * Transient that holds the public catalog (without its signature).
	 */
	const CACHE_KEY = 'sfls_extension_catalog';
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Columns an admin may set.
	 */
	const FIELDS = array( 'title', 'description', 'category', 'min_plan', 'icon', 'docs_url', 'status', 'sort' );

	/**
	 * Whether a string is a usable extension slug (not Pro itself).
	 *
	 * @param string $slug Slug.
	 */
	public static function valid_slug( string $slug ): bool {
		return SFLS_Releases::PRO !== $slug && strlen( $slug ) <= 64 && (bool) preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug );
	}

	/**
	 * One catalog entry by slug (any status), or null.
	 *
	 * @param string $slug Slug.
	 */
	public static function get( string $slug ): ?array {
		global $wpdb;
		if ( ! self::valid_slug( $slug ) ) {
			return null;
		}
		$table = SFLS_DB::extensions();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A );
		return $row ? self::normalize( $row ) : null;
	}

	/**
	 * Every catalog entry, or only the published ones, in catalog order.
	 *
	 * @param bool $published_only Leave out drafts.
	 * @return array[]
	 */
	public static function all( bool $published_only = false ): array {
		global $wpdb;
		$table = SFLS_DB::extensions();
		$where = $published_only ? "WHERE status = 'published'" : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sort ASC, slug ASC", ARRAY_A );
		return array_map( array( self::class, 'normalize' ), $rows );
	}

	/**
	 * Cast the numeric columns.
	 *
	 * @param array $row Row.
	 */
	private static function normalize( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['sort']        = (int) $row['sort'];
		$row['description'] = (string) $row['description'];
		return $row;
	}

	/**
	 * Clean and check a set of fields. Only the fields that are present are
	 * returned, so an update changes only what it names.
	 *
	 * @param array $values Raw values, keyed by column (dashes allowed).
	 * @return array|WP_Error
	 */
	private static function clean( array $values ) {
		$values = array_combine( array_map( static fn( $k ) => str_replace( '-', '_', (string) $k ), array_keys( $values ) ), array_values( $values ) );
		$out    = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $values ) ) {
				continue;
			}
			$value = $values[ $field ];
			switch ( $field ) {
				case 'title':
					$value = substr( sanitize_text_field( (string) $value ), 0, 190 );
					break;
				case 'description':
					$value = sanitize_textarea_field( (string) $value );
					break;
				case 'category':
					$value = sanitize_key( (string) $value );
					if ( ! in_array( $value, self::CATEGORIES, true ) ) {
						return new WP_Error( 'invalid_category', 'category must be one of: ' . implode( ', ', self::CATEGORIES ) . '.' );
					}
					break;
				case 'min_plan':
					$value = sanitize_key( (string) $value );
					if ( null === SFLS_Plans::get( $value ) ) {
						return new WP_Error( 'invalid_min_plan', 'min-plan must be one of: ' . implode( ', ', array_keys( SFLS_Plans::all() ) ) . '.' );
					}
					break;
				case 'icon':
					$value = substr( (string) preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $value ) ), 0, 40 );
					break;
				case 'docs_url':
					$value = '' === (string) $value ? '' : substr( esc_url_raw( (string) $value, array( 'https', 'http' ) ), 0, 255 );
					break;
				case 'status':
					$value = sanitize_key( (string) $value );
					if ( ! in_array( $value, self::STATUSES, true ) ) {
						return new WP_Error( 'invalid_status', 'status must be draft or published.' );
					}
					break;
				case 'sort':
					$value = (int) $value;
					break;
			}
			$out[ $field ] = $value;
		}
		return $out;
	}

	/**
	 * Add an extension (a draft unless the values say otherwise).
	 *
	 * @param string $slug   Extension slug, such as google-sheets.
	 * @param array  $values title, description, category, min_plan, icon, docs_url, sort.
	 * @return array|WP_Error The new entry.
	 */
	public static function create( string $slug, array $values ) {
		global $wpdb;
		if ( ! self::valid_slug( $slug ) ) {
			return new WP_Error( 'invalid_slug', 'The slug must be lowercase letters, numbers and dashes (and not subtleforms-pro).' );
		}
		if ( self::get( $slug ) ) {
			return new WP_Error( 'exists', sprintf( 'Extension %s already exists.', $slug ) );
		}
		$values = array_merge(
			array(
				'category' => 'integrations',
				'min_plan' => 'pro',
				'status'   => 'draft',
			),
			$values
		);
		$clean  = self::clean( $values );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( '' === ( $clean['title'] ?? '' ) ) {
			return new WP_Error( 'missing_title', 'A title is required.' );
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			SFLS_DB::extensions(),
			array_merge(
				$clean,
				array(
					'slug'       => $slug,
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);
		self::forget_catalog_cache();
		return self::get( $slug ) ?? new WP_Error( 'insert_failed', 'The extension could not be saved.' );
	}

	/**
	 * Change fields of an extension.
	 *
	 * @param string $slug   Slug.
	 * @param array  $values Fields to change.
	 * @return array|WP_Error The entry after the change.
	 */
	public static function update( string $slug, array $values ) {
		global $wpdb;
		if ( ! self::get( $slug ) ) {
			return new WP_Error( 'not_found', sprintf( 'Extension %s not found.', $slug ) );
		}
		$clean = self::clean( $values );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( ! $clean ) {
			return new WP_Error( 'nothing_to_update', 'Name at least one field: ' . implode( ', ', self::FIELDS ) . '.' );
		}
		$clean['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( SFLS_DB::extensions(), $clean, array( 'slug' => $slug ) );
		self::forget_catalog_cache();
		return (array) self::get( $slug );
	}

	// ── Entitlement ─────────────────────────────────────────────────────

	/**
	 * The extension slugs a plan includes: published entries whose min_plan
	 * ranks at or below the plan, sorted alphabetically so the signed body is
	 * stable.
	 *
	 * @param string $plan Plan slug.
	 * @return string[]
	 */
	public static function allowed_for_plan( string $plan ): array {
		$rank = SFLS_Plans::rank( $plan );
		$out  = array();
		foreach ( self::all( true ) as $entry ) {
			$needs = SFLS_Plans::rank( (string) $entry['min_plan'] );
			if ( $rank >= 0 && $needs >= 0 && $needs <= $rank ) {
				$out[] = (string) $entry['slug'];
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The `extensions` object of a licence answer, or null when the licence
	 * is not one a site may use (suspended or refunded). Valid, grace and
	 * expired licences all get their list: an expired licence keeps it so
	 * installed extensions keep running; downloads are refused separately.
	 *
	 * @param array $lic License.
	 * @return array{plan:string,allowed:string[]}|null
	 */
	public static function entitlement( array $lic ): ?array {
		if ( ! in_array( SFLS_Licenses::state( $lic )['status'], array( 'valid', 'grace_period', 'expired' ), true ) ) {
			return null;
		}
		return array(
			'plan'    => (string) $lic['plan'],
			'allowed' => self::allowed_for_plan( (string) $lic['plan'] ),
		);
	}

	/**
	 * Whether a license may download an extension now. Pro is not a catalog
	 * extension and keeps its own rules. An extension needs a license in
	 * valid or grace status and a place in the allowed list.
	 *
	 * @param array  $lic     License.
	 * @param string $product Extension slug.
	 */
	public static function may_download( array $lic, string $product ): bool {
		return SFLS_Licenses::state( $lic )['valid'] && in_array( $product, self::allowed_for_plan( (string) $lic['plan'] ), true );
	}

	// ── Public catalog ──────────────────────────────────────────────────

	/**
	 * Published entries with the version and requirements of the latest
	 * published release of each product (null while there is none). Kept for
	 * five minutes. The fields requires_free and requires_pro are null:
	 * releases do not record them yet.
	 *
	 * @return array[]
	 */
	public static function public_catalog(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$list = array();
		foreach ( self::all( true ) as $entry ) {
			$release = SFLS_Releases::latest( 'stable', false, (string) $entry['slug'] );
			$list[]  = array(
				'slug'          => (string) $entry['slug'],
				'title'         => (string) $entry['title'],
				'description'   => (string) $entry['description'],
				'category'      => (string) $entry['category'],
				'min_plan'      => (string) $entry['min_plan'],
				'icon'          => (string) $entry['icon'],
				'docs_url'      => (string) $entry['docs_url'],
				'sort'          => (int) $entry['sort'],
				'version'       => $release ? (string) $release['version'] : null,
				'requires_free' => null,
				'requires_pro'  => null,
				'requires_wp'   => $release && '' !== (string) $release['requires'] ? (string) $release['requires'] : null,
				'requires_php'  => $release && '' !== (string) $release['requires_php'] ? (string) $release['requires_php'] : null,
				'tested'        => $release && '' !== (string) $release['tested'] ? (string) $release['tested'] : null,
			);
		}
		set_transient( self::CACHE_KEY, $list, self::CACHE_TTL );
		return $list;
	}

	/**
	 * Drop the cached public catalog (a catalog or release change).
	 */
	public static function forget_catalog_cache(): void {
		delete_transient( self::CACHE_KEY );
	}
}
