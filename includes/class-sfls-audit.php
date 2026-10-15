<?php
/**
 * Audit log (1.7.0): who changed what on a license, and when.
 *
 * Every write that goes through SFLS_Licenses::update(), create() and
 * delete() lands here with the columns before and after, the source
 * (admin, cli, webhook, billing, account, system), the acting user and the
 * request IP. Callers name the change with SFLS_Audit::label() right before
 * the write ("renew", "refund", "transfer"); unnamed writes are "update".
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audit log writer and reader.
 */
final class SFLS_Audit {

	/**
	 * Columns worth recording. Everything else (reminder markers, sync
	 * timestamps, checkout tokens) is noise.
	 */
	const TRACKED = array( 'license_key', 'plan', 'status', 'sites_allowed', 'customer_email', 'order_email', 'user_id', 'order_id', 'product_id', 'expires_at', 'notes', 'billing', 'billing_status', 'trial', 'trial_ends_at', 'renews_at', 'cancels_at', 'paddle_subscription_id', 'paddle_customer_id', 'claimed_at', 'flagged_at', 'flag_reason' );

	/**
	 * Label for the next recorded write: [ action, note, source ].
	 *
	 * @var array|null
	 */
	private static ?array $next = null;

	/**
	 * Source forced for the current request (webhook handlers set it).
	 *
	 * @var string
	 */
	private static string $forced_source = '';

	/**
	 * Name the next license write.
	 *
	 * @param string $action Action slug (renew, upgrade, refund, suspend, ...).
	 * @param string $note   Free text shown in the log.
	 * @param string $source Override the detected source.
	 */
	public static function label( string $action, string $note = '', string $source = '' ): void {
		self::$next = array( $action, $note, $source );
	}

	/**
	 * Forget a label that was not used (the write did not happen).
	 */
	public static function clear_label(): void {
		self::$next = null;
	}

	/**
	 * Force the source for everything recorded in this request.
	 *
	 * @param string $source webhook | billing | cli | system | ''.
	 */
	public static function set_source( string $source ): void {
		self::$forced_source = sanitize_key( $source );
	}

	/**
	 * Where the current change comes from.
	 */
	public static function source(): string {
		if ( '' !== self::$forced_source ) {
			return self::$forced_source;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'system';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'api';
		}
		if ( is_admin() && get_current_user_id() ) {
			return 'admin';
		}
		return get_current_user_id() ? 'account' : 'system';
	}

	/**
	 * Who is acting: "login (email)" for users, the source otherwise.
	 */
	private static function actor(): array {
		$id = get_current_user_id();
		if ( $id ) {
			$user = get_userdata( $id );
			return array( $id, $user ? $user->user_login . ' (' . $user->user_email . ')' : (string) $id );
		}
		return array( 0, self::source() );
	}

	/**
	 * Keep only the tracked columns.
	 *
	 * @param array $row License columns.
	 */
	private static function tracked( array $row ): array {
		$out = array();
		foreach ( self::TRACKED as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$out[ $column ] = is_null( $row[ $column ] ) ? null : (string) $row[ $column ];
			}
		}
		return $out;
	}

	/**
	 * Record a change.
	 *
	 * @param int    $license_id License ID (0 for bulk or system notes).
	 * @param string $action     Action slug.
	 * @param array  $before     Columns before (tracked ones are kept).
	 * @param array  $after      Columns after.
	 * @param string $note       Note.
	 * @param string $source     Source override.
	 */
	public static function record( int $license_id, string $action, array $before = array(), array $after = array(), string $note = '', string $source = '' ): void {
		global $wpdb;
		$before = self::tracked( $before );
		$after  = self::tracked( $after );
		// Only the columns that changed.
		foreach ( array_keys( $after ) as $column ) {
			if ( array_key_exists( $column, $before ) && $before[ $column ] === $after[ $column ] ) {
				unset( $before[ $column ], $after[ $column ] );
			}
		}
		foreach ( array_keys( $before ) as $column ) {
			if ( ! array_key_exists( $column, $after ) && 'delete' !== $action ) {
				unset( $before[ $column ] );
			}
		}
		if ( ! $before && ! $after && '' === $note && in_array( $action, array( 'update', '' ), true ) ) {
			return; // Nothing visible changed.
		}
		[ $actor_id, $actor ] = self::actor();
		$source               = '' !== $source ? sanitize_key( $source ) : self::source();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			SFLS_DB::audit(),
			array(
				'license_id'  => $license_id,
				'action'      => substr( sanitize_key( $action ), 0, 40 ),
				'source'      => substr( $source, 0, 20 ),
				'actor_id'    => $actor_id,
				'actor'       => substr( $actor, 0, 190 ),
				'before_data' => $before ? wp_json_encode( $before ) : null,
				'after_data'  => $after ? wp_json_encode( $after ) : null,
				'note'        => '' !== $note ? sanitize_textarea_field( $note ) : null,
				'ip'          => SFLS_Licenses::client_ip(),
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		/**
		 * Fires after an audit entry is written.
		 *
		 * @param int    $license_id License.
		 * @param string $action     Action.
		 * @param array  $before     Changed columns before.
		 * @param array  $after      Changed columns after.
		 */
		do_action( 'sfls_audit_recorded', $license_id, $action, $before, $after );
	}

	/**
	 * Record a write made by SFLS_Licenses::update(), using the pending label.
	 *
	 * @param array $before License before.
	 * @param array $after  License after.
	 */
	public static function record_update( array $before, array $after ): void {
		$label      = self::$next ?? array( 'update', '', '' );
		self::$next = null;
		self::record( (int) ( $before['id'] ?? $after['id'] ?? 0 ), $label[0], $before, $after, $label[1], $label[2] );
	}

	/**
	 * Entries, newest first.
	 *
	 * @param array $args license_id, action, source, actor (search), from, to (GMT datetimes), per, offset.
	 * @return array{0:array[],1:int}
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['license_id'] ) ) {
			$where[] = 'license_id = %d';
			$vals[]  = (int) $args['license_id'];
		}
		if ( ! empty( $args['action'] ) ) {
			$where[] = 'action = %s';
			$vals[]  = sanitize_key( $args['action'] );
		}
		if ( ! empty( $args['source'] ) ) {
			$where[] = 'source = %s';
			$vals[]  = sanitize_key( $args['source'] );
		}
		if ( ! empty( $args['actor'] ) ) {
			$where[] = 'actor LIKE %s';
			$vals[]  = '%' . $wpdb->esc_like( (string) $args['actor'] ) . '%';
		}
		if ( ! empty( $args['from'] ) ) {
			$where[] = 'created_at >= %s';
			$vals[]  = (string) $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[] = 'created_at <= %s';
			$vals[]  = (string) $args['to'];
		}
		$per    = max( 1, (int) ( $args['per'] ?? 50 ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$sql    = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_audit WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( $per, $offset ) ) ), ARRAY_A );
		$total = $vals ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_audit WHERE {$sql}", $vals ) ) : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_audit WHERE {$sql}" );
		// phpcs:enable
		return array( (array) $rows, $total );
	}

	/**
	 * Entries of one license, newest first.
	 *
	 * @param int $license_id License.
	 * @param int $limit      Rows.
	 * @return array[]
	 */
	public static function for_license( int $license_id, int $limit = 50 ): array {
		return self::query(
			array(
				'license_id' => $license_id,
				'per'        => $limit,
			)
		)[0];
	}

	/**
	 * Distinct action slugs in the log (for the filter).
	 *
	 * @return string[]
	 */
	public static function actions(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT action FROM {$wpdb->prefix}sfls_audit ORDER BY action ASC" ) );
	}

	/**
	 * Decode a before/after column.
	 *
	 * @param string|null $json JSON.
	 */
	public static function decode( $json ): array {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Human label for an action slug.
	 *
	 * @param string $action Action.
	 */
	public static function action_label( string $action ): string {
		$labels = array(
			'create'          => __( 'Created', 'subtleforms-license-server' ),
			'update'          => __( 'Updated', 'subtleforms-license-server' ),
			'delete'          => __( 'Deleted', 'subtleforms-license-server' ),
			'renew'           => __( 'Renewed', 'subtleforms-license-server' ),
			'extend'          => __( 'Extended', 'subtleforms-license-server' ),
			'upgrade'         => __( 'Upgraded', 'subtleforms-license-server' ),
			'downgrade'       => __( 'Downgraded', 'subtleforms-license-server' ),
			'plan_change'     => __( 'Plan changed', 'subtleforms-license-server' ),
			'suspend'         => __( 'Suspended', 'subtleforms-license-server' ),
			'reactivate'      => __( 'Reactivated', 'subtleforms-license-server' ),
			'refund'          => __( 'Refunded', 'subtleforms-license-server' ),
			'refund_reversed' => __( 'Refund reversed', 'subtleforms-license-server' ),
			'regenerate'      => __( 'Key regenerated', 'subtleforms-license-server' ),
			'transfer'        => __( 'Transferred', 'subtleforms-license-server' ),
			'claim'           => __( 'Claimed', 'subtleforms-license-server' ),
			'flag'            => __( 'Flagged', 'subtleforms-license-server' ),
			'unflag'          => __( 'Flag cleared', 'subtleforms-license-server' ),
			'billing'         => __( 'Billing synced', 'subtleforms-license-server' ),
			'import'          => __( 'Imported', 'subtleforms-license-server' ),
			'email'           => __( 'Email sent', 'subtleforms-license-server' ),
			'erase'           => __( 'Personal data erased', 'subtleforms-license-server' ),
		);
		return $labels[ $action ] ?? ucfirst( str_replace( '_', ' ', $action ) );
	}

	/**
	 * Human label for a source slug.
	 *
	 * @param string $source Source.
	 */
	public static function source_label( string $source ): string {
		$labels = array(
			'admin'   => __( 'Admin', 'subtleforms-license-server' ),
			'cli'     => __( 'WP-CLI', 'subtleforms-license-server' ),
			'webhook' => __( 'Webhook', 'subtleforms-license-server' ),
			'billing' => __( 'Billing', 'subtleforms-license-server' ),
			'account' => __( 'Customer', 'subtleforms-license-server' ),
			'api'     => __( 'API', 'subtleforms-license-server' ),
			'system'  => __( 'System', 'subtleforms-license-server' ),
			'order'   => __( 'Order', 'subtleforms-license-server' ),
			'import'  => __( 'Import', 'subtleforms-license-server' ),
		);
		return $labels[ $source ] ?? ucfirst( $source );
	}

	/**
	 * "plan: pro → agency, sites_allowed: 5 → 0" for a row.
	 *
	 * @param array $row Audit row.
	 */
	public static function diff_text( array $row ): string {
		$before = self::decode( $row['before_data'] ?? null );
		$after  = self::decode( $row['after_data'] ?? null );
		$parts  = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $column ) {
			$from = array_key_exists( $column, $before ) ? $before[ $column ] : null;
			$to   = array_key_exists( $column, $after ) ? $after[ $column ] : null;
			if ( 'license_key' === $column ) {
				$from = null === $from ? null : SFLS_Licenses::mask_key( (string) $from );
				$to   = null === $to ? null : SFLS_Licenses::mask_key( (string) $to );
			}
			$parts[] = $column . ': ' . ( null === $from || '' === $from ? '(none)' : $from ) . ' > ' . ( null === $to || '' === $to ? '(none)' : $to );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Delete the log rows of a license (used when a license is erased).
	 *
	 * @param int $license_id License.
	 */
	public static function anonymize( int $license_id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SFLS_DB::audit(),
			array(
				'before_data' => null,
				'after_data'  => null,
				'ip'          => '',
				'note'        => null,
			),
			array( 'license_id' => $license_id )
		);
		// phpcs:enable
	}
}
