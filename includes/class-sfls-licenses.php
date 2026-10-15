<?php
/**
 * License records and the rules that decide whether one is usable.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * License storage and business rules.
 */
final class SFLS_Licenses {

	/**
	 * Default grace period after expiry. Licenses › Settings can change it
	 * (see grace_days()).
	 */
	const GRACE_DAYS = 14;

	/**
	 * Billing columns (schema 4) that create() accepts as they are.
	 */
	const BILLING_COLUMNS = array( 'billing_status', 'trial', 'trial_started_at', 'trial_ends_at', 'renews_at', 'cancels_at', 'billing_amount', 'billing_currency', 'paddle_customer_id', 'paddle_subscription_id', 'paddle_price_id', 'paddle_transaction_id', 'checkout_token_hash', 'billing_synced_at', 'order_email', 'claimed_at' );

	/**
	 * Days an expired license keeps working (Licenses › Settings › Grace period).
	 */
	public static function grace_days(): int {
		$days = SFLS_Settings::get( 'grace_days' );
		$days = null === $days || '' === $days ? self::GRACE_DAYS : (int) $days;
		return max( 0, min( 365, (int) apply_filters( 'sfls_grace_days', $days ) ) );
	}

	/**
	 * New key: SFP-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX (128 random bits).
	 */
	public static function generate_key(): string {
		$hex = strtoupper( bin2hex( random_bytes( 16 ) ) );
		return 'SFP-' . implode( '-', str_split( $hex, 8 ) );
	}

	/**
	 * Create a license.
	 *
	 * @param array $args plan, sites_allowed, customer_email, user_id, order_id, product_id, months, notes,
	 *                    and optionally billing (paddle | woocommerce | manual), expires_at (GMT datetime,
	 *                    overrides months) and any billing column (see SFLS_DB).
	 * @return array The stored license.
	 */
	public static function create( array $args ): array {
		global $wpdb;

		$plan   = sanitize_key( $args['plan'] ?? 'pro' );
		$def    = SFLS_Plans::get( $plan ) ?? SFLS_Plans::get( 'pro' );
		$now    = current_time( 'mysql', true );
		$months = isset( $args['months'] ) ? (int) $args['months'] : 12;
		$email  = sanitize_email( $args['customer_email'] ?? '' );
		$user   = (int) ( $args['user_id'] ?? 0 );

		if ( ! $user && $email ) {
			$found = get_user_by( 'email', $email );
			$user  = $found ? (int) $found->ID : 0;
		}

		$row = array(
			'license_key'    => self::generate_key(),
			'plan'           => $plan,
			'status'         => 'active',
			'sites_allowed'  => isset( $args['sites_allowed'] ) ? (int) $args['sites_allowed'] : (int) $def['sites'],
			'customer_email' => $email,
			'user_id'        => $user,
			'order_id'       => (int) ( $args['order_id'] ?? 0 ),
			'product_id'     => (int) ( $args['product_id'] ?? 0 ),
			'notes'          => sanitize_textarea_field( $args['notes'] ?? '' ),
			'expires_at'     => $months > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( "+{$months} months" ) ) : null,
			'created_at'     => $now,
			'updated_at'     => $now,
			'billing'        => in_array( $args['billing'] ?? '', array( 'paddle', 'woocommerce', 'manual' ), true ) ? $args['billing'] : ( ! empty( $args['order_id'] ) ? 'woocommerce' : 'manual' ),
		);
		if ( array_key_exists( 'expires_at', $args ) ) {
			$row['expires_at'] = $args['expires_at'];
		}
		foreach ( self::BILLING_COLUMNS as $column ) {
			if ( array_key_exists( $column, $args ) ) {
				$row[ $column ] = $args[ $column ];
			}
		}
		// 1.7.0: the email the order was placed with, and when an account took the key.
		if ( ! isset( $row['order_email'] ) ) {
			$row['order_email'] = $row['order_id'] > 0 ? $email : '';
		}
		if ( ! isset( $row['claimed_at'] ) && $user > 0 ) {
			$row['claimed_at'] = $now;
		}

		$wpdb->insert( SFLS_DB::licenses(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row['id'] = (int) $wpdb->insert_id;

		SFLS_Audit::record( $row['id'], 'create', array(), $row, (string) ( $args['audit_note'] ?? '' ), (string) ( $args['audit_source'] ?? '' ) );
		do_action( 'sfls_license_created', $row );

		return $row;
	}

	/**
	 * Find a license by key.
	 *
	 * @param string $key License key.
	 */
	public static function find_by_key( string $key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE license_key = %s", strtoupper( trim( $key ) ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Find the license of a Paddle subscription.
	 *
	 * @param string $subscription_id Paddle subscription ID (sub_...).
	 */
	public static function find_by_subscription( string $subscription_id ): ?array {
		global $wpdb;
		if ( '' === $subscription_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE paddle_subscription_id = %s ORDER BY id ASC LIMIT 1", $subscription_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Find the license created by a Paddle checkout transaction.
	 *
	 * @param string $transaction_id Paddle transaction ID (txn_...).
	 */
	public static function find_by_transaction( string $transaction_id ): ?array {
		global $wpdb;
		if ( '' === $transaction_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE paddle_transaction_id = %s ORDER BY id ASC LIMIT 1", $transaction_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Find a license by ID.
	 *
	 * @param int $id License ID.
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE id = %d", $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Update columns of a license.
	 *
	 * @param int   $id   License ID.
	 * @param array $data Columns.
	 */
	public static function update( int $id, array $data ): void {
		global $wpdb;
		$before             = self::find( $id );
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( SFLS_DB::licenses(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $before ) {
			$after = array_merge( $before, $data );
			SFLS_Audit::record_update( $before, $after );
			self::notify_changes( $before, $after );
		} else {
			SFLS_Audit::clear_label();
		}
	}

	/**
	 * Fire the lifecycle hooks the outgoing webhooks listen to, from a diff.
	 *
	 * @param array $before License before.
	 * @param array $after  License after.
	 */
	private static function notify_changes( array $before, array $after ): void {
		if ( (string) $before['status'] !== (string) $after['status'] ) {
			if ( in_array( $after['status'], array( 'suspended', 'refunded' ), true ) ) {
				do_action( 'sfls_license_suspended', $after, $before );
			} elseif ( 'active' === $after['status'] ) {
				do_action( 'sfls_license_reactivated', $after, $before );
			}
		}
		if ( (string) $before['expires_at'] !== (string) $after['expires_at'] && ! empty( $after['expires_at'] ) && strtotime( $after['expires_at'] . ' UTC' ) > (int) strtotime( (string) $before['expires_at'] . ' UTC' ) ) {
			do_action( 'sfls_license_renewed', $after, $before );
		}
		if ( (int) $before['user_id'] !== (int) $after['user_id'] && (int) $before['user_id'] > 0 ) {
			do_action( 'sfls_license_transferred', $after, $before );
		}
	}

	/**
	 * Delete a license and its activations.
	 *
	 * @param int $id License ID.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$lic = self::find( $id );
		$wpdb->delete( SFLS_DB::activations(), array( 'license_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( SFLS_DB::activation_log(), array( 'license_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( SFLS_DB::transfers(), array( 'license_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( SFLS_DB::licenses(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $lic ) {
			SFLS_Audit::record( $id, 'delete', $lic, array(), SFLS_Licenses::mask_key( (string) $lic['license_key'] ) );
			do_action( 'sfls_license_deleted', $lic );
		}
	}

	/**
	 * Replace a license key with a fresh one. Activations stay attached.
	 *
	 * @param int $id License ID.
	 * @return string The new key.
	 */
	public static function regenerate_key( int $id ): string {
		$key = self::generate_key();
		SFLS_Audit::label( 'regenerate' );
		self::update( $id, array( 'license_key' => $key ) );
		return $key;
	}

	/**
	 * Extend a license by a number of months from its expiry (or from today if
	 * it has already lapsed).
	 *
	 * @param int $id     License ID.
	 * @param int $months Months to add.
	 */
	public static function extend( int $id, int $months = 12 ): void {
		$lic = self::find( $id );
		if ( ! $lic ) {
			return;
		}
		if ( empty( $lic['expires_at'] ) ) {
			self::update( $id, array( 'status' => 'active' ) );
			return; // Lifetime licenses have nothing to extend.
		}
		$current = strtotime( $lic['expires_at'] . ' UTC' );
		$base    = $current > time() ? $current : time();
		self::update(
			$id,
			array(
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( "+{$months} months", $base ) ),
				'status'     => 'active',
			)
		);
	}

	// ── Ownership ───────────────────────────────────────────────────────

	/**
	 * User meta: when the account's email address was last changed.
	 */
	const EMAIL_CHANGED_META = '_sfls_email_changed_at';

	/**
	 * Whether a user owns a license.
	 *
	 * Since 1.7.0 ownership is the user ID the license was bound to at
	 * purchase (or when it was claimed, transferred or issued to the account).
	 * A license that was never claimed (user_id 0, a guest order from before
	 * 1.7.0 or a key issued by hand to an email) is also owned by the account
	 * with that email, as long as the account did not change its email after
	 * the license was created: that closes the takeover where a customer
	 * changes their address to a guest buyer's one.
	 *
	 * @param array   $lic  License.
	 * @param WP_User $user User.
	 */
	public static function user_owns( array $lic, WP_User $user ): bool {
		if ( ! $user->ID ) {
			return false;
		}
		if ( (int) $lic['user_id'] > 0 ) {
			return (int) $lic['user_id'] === (int) $user->ID;
		}
		return self::email_claim_allowed( $lic, $user );
	}

	/**
	 * Whether an unclaimed license may be matched to an account by email:
	 * the email is the license's customer email and the account has not
	 * changed its address since the license was created.
	 *
	 * @param array   $lic  License (user_id 0).
	 * @param WP_User $user User.
	 */
	public static function email_claim_allowed( array $lic, WP_User $user ): bool {
		$email = (string) ( '' !== (string) ( $lic['order_email'] ?? '' ) ? $lic['order_email'] : $lic['customer_email'] );
		if ( '' === $email || 0 !== strcasecmp( $email, $user->user_email ) ) {
			return false;
		}
		$changed = (string) get_user_meta( $user->ID, self::EMAIL_CHANGED_META, true );
		if ( '' !== $changed && strtotime( $changed . ' UTC' ) >= strtotime( (string) $lic['created_at'] . ' UTC' ) ) {
			return false;
		}
		return (bool) apply_filters( 'sfls_email_claim_allowed', true, $lic, $user );
	}

	/**
	 * Licenses that belong to a user, newest first.
	 *
	 * @param WP_User $user User.
	 * @return array[]
	 */
	public static function for_user( WP_User $user ): array {
		global $wpdb;
		if ( ! $user->ID ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE user_id = %d OR ( user_id = 0 AND ( ( order_email <> '' AND LOWER(order_email) = LOWER(%s) ) OR ( order_email = '' AND customer_email <> '' AND LOWER(customer_email) = LOWER(%s) ) ) ) ORDER BY created_at DESC, id DESC", $user->ID, $user->user_email, $user->user_email ), ARRAY_A );
		return array_values( array_filter( $rows, static fn( $lic ) => self::user_owns( $lic, $user ) ) );
	}

	/**
	 * Bind an unclaimed license to an account. Returns false when another
	 * account already owns it.
	 *
	 * @param array   $lic    License.
	 * @param WP_User $user   User.
	 * @param string  $source Where the claim came from (connect, account, claim_link).
	 */
	public static function claim( array $lic, WP_User $user, string $source = 'account' ): bool {
		if ( ! $user->ID ) {
			return false;
		}
		if ( (int) $lic['user_id'] > 0 ) {
			return (int) $lic['user_id'] === (int) $user->ID;
		}
		SFLS_Audit::label( 'claim', $source, 'account' );
		self::update(
			(int) $lic['id'],
			array(
				'user_id'    => (int) $user->ID,
				'claimed_at' => current_time( 'mysql', true ),
			)
		);
		do_action( 'sfls_license_claimed', $lic, $user, $source );
		return true;
	}

	/**
	 * Signed claim token for the order email of a guest license. The link
	 * proves the reader has the mailbox the order was placed with.
	 *
	 * @param array $lic License.
	 */
	public static function claim_token( array $lic ): string {
		$email = '' !== (string) ( $lic['order_email'] ?? '' ) ? (string) $lic['order_email'] : (string) $lic['customer_email'];
		return hash_hmac( 'sha256', 'claim|' . (int) $lic['id'] . '|' . strtolower( $email ), wp_salt( 'auth' ) . 'subtleforms-license-server' );
	}

	/**
	 * Claim link for a license (My Account › Licenses with a signed token).
	 *
	 * @param array $lic License.
	 */
	public static function claim_url( array $lic ): string {
		return add_query_arg(
			array(
				'sfls_claim' => (int) $lic['id'],
				'token'      => self::claim_token( $lic ),
			),
			self::manage_url()
		);
	}

	/**
	 * Licenses (any owner) that use an email as customer or order email.
	 *
	 * @param string $email Email.
	 * @return array[]
	 */
	public static function by_email( string $email ): array {
		global $wpdb;
		if ( '' === $email ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE LOWER(customer_email) = LOWER(%s) OR LOWER(order_email) = LOWER(%s) ORDER BY id ASC", $email, $email ), ARRAY_A );
	}

	// ── Activations ─────────────────────────────────────────────────────

	/**
	 * Activations for a license.
	 *
	 * @param int $license_id License ID.
	 * @return array[]
	 */
	public static function activations( int $license_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_activations WHERE license_id = %d ORDER BY activated_at ASC", $license_id ), ARRAY_A );
	}

	/**
	 * One activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain.
	 */
	public static function activation( int $license_id, string $domain ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_activations WHERE license_id = %d AND domain = %s", $license_id, $domain ), ARRAY_A );
		if ( $row ) {
			return $row;
		}
		return self::network_activation_covering( $license_id, $domain );
	}

	/**
	 * Multisite (1.7.0): when a network counts as one site, an activation of
	 * the network's main domain with network=1 covers every subdomain site
	 * under it (subdirectory sites share the domain anyway).
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain of the requesting site.
	 */
	public static function network_activation_covering( int $license_id, string $domain ): ?array {
		global $wpdb;
		if ( ! SFLS_Settings::network_one_site() || '' === $domain ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_activations WHERE license_id = %d AND is_network = 1", $license_id ), ARRAY_A );
		foreach ( $rows as $row ) {
			if ( str_ends_with( $domain, '.' . $row['domain'] ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Mark an activation as a multisite network (its main domain).
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Main domain.
	 */
	public static function mark_network( int $license_id, string $domain ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SFLS_DB::activations(),
			array( 'is_network' => 1 ),
			array(
				'license_id' => $license_id,
				'domain'     => $domain,
			)
		);
	}

	/**
	 * Count activations that use up a site slot (local/dev sites are free).
	 *
	 * @param int $license_id License ID.
	 */
	public static function used_slots( int $license_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_activations WHERE license_id = %d AND is_local = 0", $license_id ) );
	}

	/**
	 * Whether a license has a free site slot.
	 *
	 * @param array $lic License.
	 */
	public static function has_free_slot( array $lic ): bool {
		$limit = (int) $lic['sites_allowed'];
		return $limit <= 0 || self::used_slots( (int) $lic['id'] ) < $limit;
	}

	/**
	 * Activate a domain on a license, applying every rule the REST activate
	 * endpoint applies. Re-activating a known domain only refreshes it.
	 *
	 * @param array  $lic     License.
	 * @param string $domain  Normalised domain.
	 * @param string $version Pro version reported by the site.
	 * @param string $source  Where the request came from (api, connect, admin) for the log.
	 * @return true|WP_Error WP_Error data carries the HTTP status.
	 */
	public static function activate_domain( array $lic, string $domain, string $version = '', string $source = 'api' ) {
		if ( '' === $domain ) {
			return new WP_Error( 'missing_parameters', __( 'license_key and site_url are required.', 'subtleforms-license-server' ), array( 'status' => 400 ) );
		}

		$state = self::state( $lic );
		if ( ! $state['valid'] ) {
			return new WP_Error(
				$state['status'],
				'expired' === $state['status']
					? __( 'This license has expired. Renew it from your SubtleForms account.', 'subtleforms-license-server' )
					: __( 'This license is not active. Contact SubtleForms support.', 'subtleforms-license-server' ),
				array( 'status' => 403 )
			);
		}

		$existing = self::activation( (int) $lic['id'], $domain );
		if ( ! $existing && ! empty( $lic['trial'] ) && ! self::is_local_domain( $domain ) && SFLS_Trials::site_used_by_other( $domain, (int) $lic['id'] ) ) {
			return new WP_Error(
				'trial_site_used',
				__( 'This site already had a SubtleForms Pro free trial. Buy a license to use Pro here, or start the trial on a site that has not had one.', 'subtleforms-license-server' ),
				array( 'status' => 403 )
			);
		}
		if ( ! $existing && ! self::is_local_domain( $domain ) && ! self::has_free_slot( $lic ) ) {
			$limit = (int) $lic['sites_allowed'];
			return new WP_Error(
				'activation_limit',
				sprintf(
					/* translators: %d: number of sites */
					_n( 'This license is already active on %d site. Deactivate it there, or upgrade your plan.', 'This license is already active on %d sites. Deactivate one, or upgrade your plan.', $limit, 'subtleforms-license-server' ),
					$limit
				),
				array( 'status' => 400 )
			);
		}

		if ( $existing && $existing['domain'] === $domain ) {
			self::touch_activation( (int) $lic['id'], $domain, $version );
		} elseif ( $existing ) {
			self::touch_activation( (int) $lic['id'], (string) $existing['domain'], $version ); // Covered by a network activation.
		} else {
			self::add_activation( (int) $lic['id'], $domain, $version );
			self::log( (int) $lic['id'], $domain, 'activate', $source );
			if ( ! empty( $lic['trial'] ) && ! self::is_local_domain( $domain ) ) {
				SFLS_Trials::record_site( (int) $lic['id'], $domain );
			}
			do_action( 'sfls_domain_activated', $lic, $domain, $version );
		}

		return true;
	}

	/**
	 * Store a new activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Domain.
	 * @param string $version    Pro version.
	 */
	public static function add_activation( int $license_id, string $domain, string $version = '' ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			SFLS_DB::activations(),
			array(
				'license_id'     => $license_id,
				'domain'         => $domain,
				'is_local'       => self::is_local_domain( $domain ) ? 1 : 0,
				'plugin_version' => substr( sanitize_text_field( $version ), 0, 20 ),
				'activated_at'   => $now,
				'last_seen_at'   => $now,
			)
		);
	}

	/**
	 * Refresh last-seen (and version) of an activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Domain.
	 * @param string $version    Pro version.
	 */
	public static function touch_activation( int $license_id, string $domain, string $version = '' ): void {
		global $wpdb;
		$data = array( 'last_seen_at' => current_time( 'mysql', true ) );
		if ( $version ) {
			$data['plugin_version'] = substr( sanitize_text_field( $version ), 0, 20 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SFLS_DB::activations(),
			$data,
			array(
				'license_id' => $license_id,
				'domain'     => $domain,
			)
		);
	}

	/**
	 * Remove an activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Domain.
	 * @param string $source     Who removed it (api, account, admin) for the log.
	 */
	public static function remove_activation( int $license_id, string $domain, string $source = 'api' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			SFLS_DB::activations(),
			array(
				'license_id' => $license_id,
				'domain'     => $domain,
			)
		);
		if ( $deleted ) {
			self::log( $license_id, $domain, 'deactivate', $source );
			$lic = self::find( $license_id );
			if ( $lic ) {
				do_action( 'sfls_domain_deactivated', $lic, $domain, $source );
			}
		}
	}

	/**
	 * Remove every activation of a license.
	 *
	 * @param int    $license_id License ID.
	 * @param string $source     Who removed them, for the log.
	 */
	public static function reset_activations( int $license_id, string $source = 'admin' ): void {
		foreach ( self::activations( $license_id ) as $act ) {
			self::remove_activation( $license_id, (string) $act['domain'], $source );
		}
	}

	// ── Activity log ────────────────────────────────────────────────────

	/**
	 * IP address of the current request ('' on the command line). Behind a
	 * proxy or CDN, filter `sfls_client_ip` to read the forwarded address.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'sfls_client_ip', $ip );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Record an activation or deactivation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Domain.
	 * @param string $event      activate | deactivate.
	 * @param string $source     api | connect | account | admin | cli.
	 */
	public static function log( int $license_id, string $domain, string $event, string $source = '' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			SFLS_DB::activation_log(),
			array(
				'license_id' => $license_id,
				'domain'     => substr( $domain, 0, 190 ),
				'event'      => sanitize_key( $event ),
				'source'     => sanitize_key( ( defined( 'WP_CLI' ) && WP_CLI && 'api' === $source ) ? 'cli' : $source ),
				'ip'         => self::client_ip(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Log entries of a license, newest first.
	 *
	 * @param int $license_id License ID.
	 * @param int $limit      Max rows.
	 * @return array[]
	 */
	public static function log_entries( int $license_id, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_activation_log WHERE license_id = %d ORDER BY id DESC LIMIT %d", $license_id, $limit ), ARRAY_A );
	}

	// ── Domains ─────────────────────────────────────────────────────────

	/**
	 * Normalise a site URL or host to a bare lowercase host without "www.".
	 *
	 * @param string $url URL or host.
	 */
	public static function normalize_domain( string $url ): string {
		$url  = trim( strtolower( $url ) );
		$host = wp_parse_url( str_contains( $url, '://' ) ? $url : 'https://' . $url, PHP_URL_HOST );
		$host = (string) $host;
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * The built-in list of local and staging host patterns (1.7.0). One
	 * pattern per line in Licenses › Settings; `*` matches any characters,
	 * everything else is matched exactly against the whole host, so
	 * `*.wpengine.com` no longer covers production sites and `.kinsta.cloud`
	 * only counts with the staging- prefix. IP addresses are always local.
	 *
	 * @return string[]
	 */
	public static function default_staging_patterns(): array {
		return array(
			'localhost',
			'*.localhost',
			'*.local',
			'*.test',
			'*.invalid',
			'*.example',
			'*.ddev.site',
			'*.lndo.site',
			'*.dev.cc',
			'staging.*',
			'stg.*',
			'*stg.wpengine.com',
			'*dev.wpengine.com',
			'*stg.wpenginepowered.com',
			'*dev.wpenginepowered.com',
			'staging-*.kinsta.cloud',
			'*.flywheelsites.com',
			'dev-*.pantheonsite.io',
			'test-*.pantheonsite.io',
			'*.instawp.xyz',
		);
	}

	/**
	 * The active staging patterns: the admin list, or the defaults.
	 *
	 * @return string[]
	 */
	public static function staging_patterns(): array {
		$raw = (string) SFLS_Settings::get( 'staging_hosts' );
		if ( '' === trim( $raw ) ) {
			$list = self::default_staging_patterns();
		} else {
			$list = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', strtolower( $raw ) ) ), 'strlen' ) );
		}
		return (array) apply_filters( 'sfls_staging_patterns', $list );
	}

	/**
	 * Whether a host matches a pattern (`*` = any characters, rest exact).
	 *
	 * @param string $pattern Pattern.
	 * @param string $domain  Normalised domain.
	 */
	public static function host_matches( string $pattern, string $domain ): bool {
		$pattern = strtolower( trim( $pattern ) );
		if ( '' === $pattern ) {
			return false;
		}
		if ( ! str_contains( $pattern, '*' ) ) {
			return $pattern === $domain;
		}
		$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
		return (bool) preg_match( $regex, $domain );
	}

	/**
	 * Local, staging and development hosts do not use a site slot.
	 *
	 * @param string $domain Normalised domain.
	 */
	public static function is_local_domain( string $domain ): bool {
		if ( '' === $domain ) {
			return false;
		}
		if ( false !== filter_var( trim( $domain, '[]' ), FILTER_VALIDATE_IP ) ) {
			return true;
		}
		foreach ( self::staging_patterns() as $pattern ) {
			if ( self::host_matches( $pattern, $domain ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'sfls_is_local_domain', false, $domain );
	}

	// ── State ───────────────────────────────────────────────────────────

	/**
	 * The status a client should see right now.
	 *
	 * @param array $lic License.
	 * @return array{status:string,valid:bool,grace_period_ends_at:?string}
	 */
	public static function state( array $lic ): array {
		if ( 'suspended' === $lic['status'] || 'refunded' === $lic['status'] ) {
			return array(
				'status'               => 'suspended',
				'valid'                => false,
				'grace_period_ends_at' => null,
			);
		}
		if ( empty( $lic['expires_at'] ) ) {
			return array(
				'status'               => 'valid',
				'valid'                => true,
				'grace_period_ends_at' => null,
			);
		}
		$expires = strtotime( $lic['expires_at'] . ' UTC' );
		if ( $expires > time() ) {
			return array(
				'status'               => 'valid',
				'valid'                => true,
				'grace_period_ends_at' => null,
			);
		}
		// A free trial that ended without a payment has no grace period.
		$grace_end = ! empty( $lic['trial'] ) ? $expires : $expires + self::grace_days() * DAY_IN_SECONDS;
		if ( $grace_end > time() ) {
			return array(
				'status'               => 'grace_period',
				'valid'                => true,
				'grace_period_ends_at' => gmdate( 'c', $grace_end ),
			);
		}
		return array(
			'status'               => 'expired',
			'valid'                => false,
			'grace_period_ends_at' => null,
		);
	}

	/**
	 * Human label for a state slug.
	 *
	 * @param string $status State slug from state().
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'valid'        => __( 'Active', 'subtleforms-license-server' ),
			'grace_period' => __( 'Grace period', 'subtleforms-license-server' ),
			'expired'      => __( 'Expired', 'subtleforms-license-server' ),
			'suspended'    => __( 'Suspended', 'subtleforms-license-server' ),
		);
		return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
	}

	/**
	 * Key with everything but the last 8 characters hidden.
	 *
	 * @param string $key License key.
	 */
	public static function mask_key( string $key ): string {
		return 'SFP-••••-' . substr( $key, -8 );
	}

	/**
	 * Expiry formatted for people ("Lifetime" when it never expires).
	 *
	 * @param array $lic License.
	 */
	public static function expiry_label( array $lic ): string {
		if ( empty( $lic['expires_at'] ) ) {
			return __( 'Lifetime', 'subtleforms-license-server' );
		}
		return wp_date( get_option( 'date_format' ), strtotime( $lic['expires_at'] . ' UTC' ) );
	}

	/**
	 * "2 of 5" / "1 of unlimited".
	 *
	 * @param array $lic License.
	 */
	public static function usage_label( array $lic ): string {
		$used  = self::used_slots( (int) $lic['id'] );
		$limit = (int) $lic['sites_allowed'];
		if ( $limit <= 0 ) {
			/* translators: %d: sites used */
			return sprintf( _n( '%d site used, unlimited', '%d sites used, unlimited', $used, 'subtleforms-license-server' ), $used );
		}
		/* translators: 1: sites used, 2: sites allowed */
		return sprintf( _n( '%1$d of %2$d site used', '%1$d of %2$d sites used', $limit, 'subtleforms-license-server' ), $used, $limit );
	}

	/**
	 * The payload SubtleForms Pro stores for a license.
	 *
	 * `is_local` tells the requesting site whether it is recognised as a local or
	 * staging site that does not use a seat.
	 *
	 * @param array  $lic    License.
	 * @param string $domain Normalised domain of the requesting site ('' when unknown).
	 */
	public static function payload( array $lic, string $domain = '' ): array {
		$state = self::state( $lic );
		$limit = (int) $lic['sites_allowed'];
		return array(
			'valid'                => $state['valid'],
			'status'               => $state['status'],
			'plan'                 => $lic['plan'],
			'features'             => SFLS_Plans::features( $lic['plan'] ),
			'expires_at'           => $lic['expires_at'] ? gmdate( 'c', strtotime( $lic['expires_at'] . ' UTC' ) ) : null,
			'grace_period_ends_at' => $state['grace_period_ends_at'],
			'sites_allowed'        => $limit,
			'sites_used'           => self::used_slots( (int) $lic['id'] ),
			'check_again_in'       => 12 * HOUR_IN_SECONDS,
			'is_local'             => '' !== $domain && self::is_local_domain( $domain ),
			// Billing (1.4.0). During a free trial the license stays valid with
			// status "valid", so older Pro versions keep working.
			'billing'              => self::billing_source( $lic ),
			'billing_status'       => 'paddle' === self::billing_source( $lic ) && '' !== (string) ( $lic['billing_status'] ?? '' ) ? (string) $lic['billing_status'] : null,
			'trial'                => ! empty( $lic['trial'] ),
			'trial_ends_at'        => self::iso( $lic['trial_ends_at'] ?? null ),
			'renews_at'            => self::iso( $lic['renews_at'] ?? null ),
			'cancels_at'           => self::iso( $lic['cancels_at'] ?? null ),
			'renewal_amount'       => self::renewal_amount( $lic ),
			'manage_url'           => self::manage_url(),
		);
	}

	/**
	 * Where the license is billed: paddle, woocommerce or manual.
	 *
	 * @param array $lic License.
	 */
	public static function billing_source( array $lic ): string {
		$billing = (string) ( $lic['billing'] ?? '' );
		if ( in_array( $billing, array( 'paddle', 'woocommerce', 'manual' ), true ) ) {
			return $billing;
		}
		return (int) ( $lic['order_id'] ?? 0 ) > 0 ? 'woocommerce' : 'manual';
	}

	/**
	 * Whether a license renews through a Paddle subscription.
	 *
	 * @param array $lic License.
	 */
	public static function is_paddle( array $lic ): bool {
		return 'paddle' === self::billing_source( $lic ) && '' !== (string) ( $lic['paddle_subscription_id'] ?? '' );
	}

	/**
	 * GMT datetime column as ISO 8601, or null.
	 *
	 * @param string|null $value GMT datetime.
	 */
	public static function iso( $value ): ?string {
		return empty( $value ) ? null : gmdate( 'c', strtotime( $value . ' UTC' ) );
	}

	/**
	 * Formatted next charge ("$79.00"), or null when unknown.
	 *
	 * @param array $lic License.
	 */
	public static function renewal_amount( array $lic ): ?string {
		if ( ! self::is_paddle( $lic ) || '' === (string) ( $lic['billing_amount'] ?? '' ) || '' === (string) ( $lic['billing_currency'] ?? '' ) || ! empty( $lic['cancels_at'] ) ) {
			return null;
		}
		return SFLS_Paddle::format_amount( (string) $lic['billing_amount'], (string) $lic['billing_currency'] );
	}

	/**
	 * My Account › Licenses on this site (where customers manage billing).
	 */
	public static function manage_url(): string {
		if ( function_exists( 'wc_get_account_endpoint_url' ) && function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'myaccount' ) > 0 ) {
			return wc_get_account_endpoint_url( 'licenses' );
		}
		return home_url( '/my-account/licenses/' );
	}

	// ── Admin queries ───────────────────────────────────────────────────

	/**
	 * Search licenses for the admin list and the CSV export.
	 *
	 * @param array $args {
	 *     @type string $search Key, email or activated domain fragment.
	 *     @type string $status '', active, expiring (in 30 days), expired, suspended.
	 *     @type string $plan   Plan slug or ''.
	 *     @type int    $per    Rows per page (0 = all).
	 *     @type int    $offset Offset.
	 * }
	 * @return array{0:array[],1:int} Rows and total.
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$search = trim( (string) ( $args['search'] ?? '' ) );
		$status = (string) ( $args['status'] ?? '' );
		$plan   = sanitize_key( (string) ( $args['plan'] ?? '' ) );
		$per    = max( 0, (int) ( $args['per'] ?? 20 ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$like = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';
		$now  = current_time( 'mysql', true );
		$soon = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$vals = array( $like, $like, $like, $like, $plan, $plan, $status, $status, $now, $status, $now, $soon, $status, $now, $status );
		$per  = $per > 0 ? $per : 1000000; // 0 = everything (CSV export).

		// The replacements are passed as one array, which the placeholder-count sniff cannot read.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.* FROM {$wpdb->prefix}sfls_licenses l
				WHERE ( %s = '' OR l.license_key LIKE %s OR l.customer_email LIKE %s
					OR EXISTS ( SELECT 1 FROM {$wpdb->prefix}sfls_activations a WHERE a.license_id = l.id AND a.domain LIKE %s ) )
				AND ( %s = '' OR l.plan = %s )
				AND ( %s = ''
					OR ( %s = 'active' AND l.status = 'active' AND ( l.expires_at IS NULL OR l.expires_at > %s ) )
					OR ( %s = 'expiring' AND l.status = 'active' AND l.expires_at > %s AND l.expires_at <= %s )
					OR ( %s = 'expired' AND l.status = 'active' AND l.expires_at <= %s )
					OR ( %s = 'suspended' AND l.status IN ( 'suspended', 'refunded' ) ) )
				ORDER BY l.id DESC LIMIT %d OFFSET %d",
				array_merge( $vals, array( $per, $offset ) )
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses l
				WHERE ( %s = '' OR l.license_key LIKE %s OR l.customer_email LIKE %s
					OR EXISTS ( SELECT 1 FROM {$wpdb->prefix}sfls_activations a WHERE a.license_id = l.id AND a.domain LIKE %s ) )
				AND ( %s = '' OR l.plan = %s )
				AND ( %s = ''
					OR ( %s = 'active' AND l.status = 'active' AND ( l.expires_at IS NULL OR l.expires_at > %s ) )
					OR ( %s = 'expiring' AND l.status = 'active' AND l.expires_at > %s AND l.expires_at <= %s )
					OR ( %s = 'expired' AND l.status = 'active' AND l.expires_at <= %s )
					OR ( %s = 'suspended' AND l.status IN ( 'suspended', 'refunded' ) ) )",
				$vals
			)
		);
		// phpcs:enable

		return array( (array) $rows, $total );
	}

	/**
	 * Rows for the CSV export, one per license.
	 *
	 * @param array[] $licenses Licenses.
	 * @return array[] First row is the header.
	 */
	public static function csv_rows( array $licenses ): array {
		$out = array( array( 'id', 'license_key', 'plan', 'status', 'sites_allowed', 'sites_used', 'domains', 'customer_email', 'user_id', 'order_id', 'expires_at', 'created_at' ) );
		$acts = self::activations_for( array_map( static fn( $lic ) => (int) $lic['id'], $licenses ) );
		foreach ( $licenses as $lic ) {
			$id      = (int) $lic['id'];
			$domains = wp_list_pluck( $acts[ $id ] ?? array(), 'domain' );
			$used    = count( array_filter( $acts[ $id ] ?? array(), static fn( $a ) => empty( $a['is_local'] ) ) );
			$out[]   = array(
				$id,
				(string) $lic['license_key'],
				(string) $lic['plan'],
				self::state( $lic )['status'],
				(int) $lic['sites_allowed'],
				$used,
				implode( ' ', $domains ),
				(string) $lic['customer_email'],
				(int) $lic['user_id'],
				(int) $lic['order_id'],
				(string) ( $lic['expires_at'] ?? '' ),
				(string) $lic['created_at'],
			);
		}
		return array_map( array( self::class, 'csv_safe_row' ), $out );
	}

	/**
	 * Activations of many licenses in a few queries (CSV export, lists).
	 *
	 * @param int[] $ids License IDs.
	 * @return array<int,array[]> license_id => activations.
	 */
	public static function activations_for( array $ids ): array {
		global $wpdb;
		$out = array();
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sfls_activations WHERE license_id IN ({$in}) ORDER BY activated_at ASC", ARRAY_A );
			foreach ( $rows as $row ) {
				$out[ (int) $row['license_id'] ][] = $row;
			}
		}
		return $out;
	}

	/**
	 * Neutralise spreadsheet formulas (=, +, -, @) in CSV cells.
	 *
	 * @param array $row Cells.
	 * @return array
	 */
	public static function csv_safe_row( array $row ): array {
		return array_map(
			static function ( $cell ) {
				$cell = (string) $cell;
				return ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $cell : $cell;
			},
			$row
		);
	}

	/**
	 * Numbers for the overview cards.
	 *
	 * @return array{active:int,activations:int,expiring:int}
	 */
	public static function stats(): array {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$soon = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND ( expires_at IS NULL OR expires_at > %s )", $now ) );
		$activations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_activations" );
		$expiring    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND expires_at > %s AND expires_at <= %s", $now, $soon ) );
		// phpcs:enable
		return array(
			'active'      => $active,
			'activations' => $activations,
			'expiring'    => $expiring,
		);
	}

	/**
	 * Active, non-lifetime licenses whose expiry falls in a window.
	 *
	 * @param string $from GMT datetime (exclusive).
	 * @param string $to   GMT datetime (inclusive).
	 * @return array[]
	 */
	public static function expiring_between( string $from, string $to, int $after_id = 0, int $limit = 500 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s AND id > %d ORDER BY id ASC LIMIT %d", $from, $to, $after_id, max( 1, $limit ) ), ARRAY_A );
	}

	/**
	 * Every license expiring in a window, in batches of 500 (1.7.0: no cap).
	 *
	 * @param string   $from     GMT datetime (exclusive).
	 * @param string   $to       GMT datetime (inclusive).
	 * @param callable $callback Called with each license.
	 */
	public static function each_expiring_between( string $from, string $to, callable $callback ): void {
		$after = 0;
		do {
			$rows = self::expiring_between( $from, $to, $after );
			foreach ( $rows as $lic ) {
				$after = max( $after, (int) $lic['id'] );
				$callback( $lic );
			}
		} while ( count( $rows ) === 500 );
	}

	/**
	 * Paddle licenses whose trial ends, or whose subscription renews, in a
	 * window, and that are not set to cancel.
	 *
	 * @param string $column trial_ends_at | renews_at.
	 * @param string $from   GMT datetime (exclusive).
	 * @param string $to     GMT datetime (inclusive).
	 * @return array[]
	 */
	public static function paddle_due( string $column, string $from, string $to ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 'trial_ends_at' === $column ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND billing = 'paddle' AND trial = 1 AND billing_status = 'trialing' AND cancels_at IS NULL AND trial_ends_at IS NOT NULL AND trial_ends_at > %s AND trial_ends_at <= %s ORDER BY trial_ends_at ASC LIMIT 500", $from, $to ), ARRAY_A );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE status = 'active' AND billing = 'paddle' AND trial = 0 AND billing_status IN ( 'active', 'past_due' ) AND cancels_at IS NULL AND renews_at IS NOT NULL AND renews_at > %s AND renews_at <= %s ORDER BY renews_at ASC LIMIT 500", $from, $to ), ARRAY_A );
		// phpcs:enable
	}
}
