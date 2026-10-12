<?php
/**
 * WooCommerce products that sell SubtleForms licenses.
 *
 * A license product is a simple virtual product with this meta (whether it is
 * also downloadable makes no difference: license-only orders complete on
 * payment either way, see SFLS_Woo::payment_complete_status()):
 *   _sfls_enabled  yes
 *   _sfls_plan     starter | pro | agency
 *   _sfls_sites    site limit ('' = plan default, 0 = unlimited)
 *   _sfls_months   12 = yearly, 0 = lifetime
 *   _sfls_renewal  yes = extends an existing key instead of issuing one
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product lookup and the setup wizard's product writer.
 */
final class SFLS_Products {

	const OPTION = 'sfls_products';
	const TYPES  = array( 'yearly', 'lifetime', 'renewal' );

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static ?array $cache = null;

	/**
	 * Whether WooCommerce is available.
	 */
	public static function woo_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Product IDs the wizard created: plan => type => ID.
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function map(): array {
		return (array) get_option( self::OPTION, array() );
	}

	/**
	 * License product type from its meta.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function type_of( int $product_id ): string {
		if ( 'yes' === get_post_meta( $product_id, '_sfls_renewal', true ) ) {
			return 'renewal';
		}
		return '0' === (string) get_post_meta( $product_id, '_sfls_months', true ) ? 'lifetime' : 'yearly';
	}

	/**
	 * Every license product (any status), described.
	 *
	 * @return array<int,array{id:int,plan:string,type:string,months:int,sites:int,status:string}>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => '_sfls_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$plan   = (string) get_post_meta( $id, '_sfls_plan', true );
			$plan   = '' === $plan ? 'pro' : $plan;
			$sites  = (string) get_post_meta( $id, '_sfls_sites', true );
			$months = (string) get_post_meta( $id, '_sfls_months', true );
			$out[]  = array(
				'id'     => $id,
				'plan'   => $plan,
				'type'   => self::type_of( $id ),
				'months' => '' === $months ? 12 : (int) $months,
				'sites'  => '' === $sites ? (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ) : (int) $sites,
				'status' => (string) get_post_status( $id ),
			);
		}
		self::$cache = $out;
		return $out;
	}

	/**
	 * Forget the request cache.
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Published product for a plan and type. Prefers the wizard's product.
	 *
	 * @param string $plan Plan slug.
	 * @param string $type yearly | lifetime | renewal.
	 */
	public static function find( string $plan, string $type ): ?array {
		$map       = self::map();
		$preferred = (int) ( $map[ $plan ][ $type ] ?? 0 );
		$match     = null;
		foreach ( self::all() as $row ) {
			if ( $row['plan'] !== $plan || $row['type'] !== $type || 'publish' !== $row['status'] ) {
				continue;
			}
			if ( $row['id'] === $preferred ) {
				return $row;
			}
			$match = $match ?? $row;
		}
		return $match;
	}

	/**
	 * Sellable (non-renewal, published) products for the pricing table.
	 *
	 * @param string $type yearly | lifetime.
	 * @return array<string,array> Keyed by plan, in plan order.
	 */
	public static function sellable( string $type ): array {
		$out = array();
		foreach ( array_keys( SFLS_Plans::all() ) as $plan ) {
			$row = self::find( $plan, $type );
			if ( $row ) {
				$out[ $plan ] = $row;
			}
		}
		return $out;
	}

	/**
	 * URL that puts a product in the cart and opens the checkout.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function buy_url( int $product_id ): string {
		$base = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );
		return add_query_arg( 'sfls_buy', $product_id, $base );
	}

	/**
	 * Renew link for a license (nonce protected), or '' when no renewal product exists.
	 *
	 * @param array $lic License.
	 */
	public static function renew_url( array $lic ): string {
		if ( empty( $lic['expires_at'] ) || ! self::woo_active() || SFLS_Licenses::is_paddle( $lic ) ) {
			return '';
		}
		$product = self::find( $lic['plan'], 'renewal' ) ?? self::find( $lic['plan'], 'yearly' );
		if ( ! $product ) {
			return '';
		}
		return wp_nonce_url(
			add_query_arg( 'sfls_renew', (int) $lic['id'], wc_get_account_endpoint_url( 'licenses' ) ),
			'sfls_renew_' . $lic['id']
		);
	}

	/**
	 * Upgrade targets for a license: plan => product row.
	 *
	 * @param array $lic License.
	 * @return array<string,array>
	 */
	public static function upgrade_options( array $lic ): array {
		if ( ! self::woo_active() ) {
			return array();
		}
		$paddle = SFLS_Licenses::is_paddle( $lic );
		// Paddle subscriptions upgrade at the WooCommerce checkout only when it
		// takes Paddle payments; otherwise My Account changes the plan directly.
		if ( $paddle && ( ! SFLS_Paddle::woo_selling() || ! in_array( (string) $lic['billing_status'], array( 'active', 'trialing' ), true ) ) ) {
			return array();
		}
		$prefer = empty( $lic['expires_at'] ) ? 'lifetime' : 'yearly';
		$out    = array();
		foreach ( SFLS_Plans::higher( $lic['plan'] ) as $plan ) {
			if ( $paddle && '' === SFLS_Paddle::plan( $plan )['price_id'] ) {
				continue;
			}
			$row = self::find( $plan, $prefer ) ?? self::find( $plan, 'yearly' );
			if ( $row ) {
				$out[ $plan ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Upgrade link for a license to a plan (nonce protected).
	 *
	 * @param array  $lic  License.
	 * @param string $plan Target plan.
	 */
	public static function upgrade_url( array $lic, string $plan ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'sfls_upgrade' => (int) $lic['id'],
					'plan'         => $plan,
				),
				wc_get_account_endpoint_url( 'licenses' )
			),
			'sfls_upgrade_' . $lic['id']
		);
	}

	/**
	 * Create or update the license products from the wizard.
	 *
	 * @param array $rows plan => { yearly, renewal, lifetime, sites } (raw strings).
	 * @return array{created:int,updated:int}
	 */
	public static function save_wizard( array $rows ): array {
		$result = array(
			'created' => 0,
			'updated' => 0,
		);
		// A double-clicked Save must not create the products twice.
		if ( get_transient( 'sfls_wizard_lock' ) ) {
			return $result;
		}
		set_transient( 'sfls_wizard_lock', 1, 30 );
		self::flush();
		$map = self::map();

		foreach ( SFLS_Plans::all() as $plan => $def ) {
			$row   = (array) ( $rows[ $plan ] ?? array() );
			$sites = isset( $row['sites'] ) && '' !== $row['sites'] ? absint( $row['sites'] ) : (int) $def['sites'];

			$base  = self::product_base_name( $plan );
			$specs = array(
				'yearly'   => array(
					'name'   => $base,
					'months' => 12,
				),
				'lifetime' => array(
					/* translators: %s: product name, e.g. "SubtleForms Pro Agency" */
					'name'   => sprintf( __( '%s Lifetime', 'subtleforms-license-server' ), $base ),
					'months' => 0,
				),
				'renewal'  => array(
					/* translators: %s: product name, e.g. "SubtleForms Pro Agency" */
					'name'   => sprintf( __( '%s Renewal', 'subtleforms-license-server' ), $base ),
					'months' => 12,
				),
			);

			foreach ( $specs as $type => $spec ) {
				$price = self::clean_price( (string) ( $row[ $type ] ?? '' ) );
				$id    = (int) ( $map[ $plan ][ $type ] ?? 0 );
				$prod  = $id ? wc_get_product( $id ) : null;
				if ( ! $prod || 'trash' === $prod->get_status() ) {
					// Mapped product gone: adopt a live one for this plan and type before creating.
					$id   = self::existing_id( $plan, $type );
					$prod = $id ? wc_get_product( $id ) : null;
				}

				if ( '' === $price && ! $prod ) {
					continue; // Nothing to create.
				}
				if ( ! $prod || 'trash' === $prod->get_status() ) {
					$prod = new WC_Product_Simple();
					$prod->set_name( $spec['name'] );
					$prod->set_downloadable( false );
					++$result['created'];
				} else {
					++$result['updated'];
				}

				// Existing products keep their downloadable flag: nothing depends
				// on it, and shop owners may have set it on purpose.
				$prod->set_virtual( true );
				$prod->set_sold_individually( true );
				$prod->set_tax_status( $prod->get_tax_status() ? $prod->get_tax_status() : 'taxable' );
				$prod->set_catalog_visibility( 'renewal' === $type ? 'hidden' : 'visible' );
				if ( '' !== $price ) {
					$prod->set_regular_price( $price );
					$prod->set_status( 'publish' );
				} else {
					$prod->set_status( 'draft' ); // Price cleared: stop selling it.
				}
				if ( ! $prod->get_short_description() ) {
					$prod->set_short_description(
						sprintf(
							/* translators: 1: plan name, 2: sites, 3: period */
							__( 'SubtleForms Pro %1$s license for %2$s, %3$s.', 'subtleforms-license-server' ),
							$def['label'],
							strtolower( SFLS_Plans::sites_label( $sites ) ),
							0 === $spec['months'] ? __( 'lifetime updates and support', 'subtleforms-license-server' ) : __( 'one year of updates and support', 'subtleforms-license-server' )
						)
					);
				}
				$prod->update_meta_data( '_sfls_enabled', 'yes' );
				$prod->update_meta_data( '_sfls_plan', $plan );
				$prod->update_meta_data( '_sfls_sites', (string) $sites );
				$prod->update_meta_data( '_sfls_months', (string) $spec['months'] );
				$prod->update_meta_data( '_sfls_renewal', 'renewal' === $type ? 'yes' : 'no' );
				$map[ $plan ][ $type ] = (int) $prod->save();
			}
		}

		update_option( self::OPTION, $map, false );
		self::flush();
		delete_transient( 'sfls_wizard_lock' );
		return $result;
	}

	/**
	 * Which product each wizard price maps to, for the status chips:
	 * plan => type => { id, name, status } where status is a post status,
	 * "missing" (mapped product deleted or trashed) or "none".
	 *
	 * @return array<string,array<string,array{id:int,name:string,status:string}>>
	 */
	public static function mapping(): array {
		$map = self::map();
		$out = array();
		foreach ( array_keys( SFLS_Plans::all() ) as $plan ) {
			foreach ( self::TYPES as $type ) {
				$id     = (int) ( $map[ $plan ][ $type ] ?? 0 );
				$mapped = $id > 0;
				$id     = $id ? $id : self::existing_id( $plan, $type );
				$prod   = $id ? wc_get_product( $id ) : null;
				if ( $prod && 'trash' !== $prod->get_status() ) {
					$status = $prod->get_status();
					$name   = $prod->get_name();
				} else {
					$status = $mapped ? 'missing' : 'none';
					$name   = '';
					$id     = $mapped ? $id : 0;
				}
				$out[ $plan ][ $type ] = array(
					'id'     => $id,
					'name'   => $name,
					'status' => $status,
				);
			}
		}
		return $out;
	}

	/**
	 * Base product name for a plan: "SubtleForms Pro" for the Pro plan,
	 * "SubtleForms Pro Agency" for the others.
	 *
	 * @param string $plan Plan slug.
	 */
	private static function product_base_name( string $plan ): string {
		$label = SFLS_Plans::label( $plan );
		if ( 'pro' === $plan || 0 === strcasecmp( $label, 'Pro' ) ) {
			return __( 'SubtleForms Pro', 'subtleforms-license-server' );
		}
		/* translators: %s: plan name */
		return sprintf( __( 'SubtleForms Pro %s', 'subtleforms-license-server' ), $label );
	}

	/**
	 * A license product set up by hand that the wizard can take over, so
	 * running the wizard on an existing shop does not duplicate products.
	 *
	 * @param string $plan Plan slug.
	 * @param string $type yearly | lifetime | renewal.
	 */
	private static function existing_id( string $plan, string $type ): int {
		$found = 0;
		foreach ( self::all() as $row ) {
			if ( $row['plan'] === $plan && $row['type'] === $type ) {
				if ( 'publish' === $row['status'] ) {
					return $row['id'];
				}
				$found = $found ? $found : $row['id'];
			}
		}
		return $found;
	}

	/**
	 * Normalise a typed price ("49", "49.00", "1,299.50") to a decimal string.
	 *
	 * @param string $raw Raw input.
	 */
	public static function clean_price( string $raw ): string {
		$raw = preg_replace( '/\s+/', '', $raw );
		if ( ',' === wc_get_price_decimal_separator() ) {
			$raw = str_replace( array( '.', ',' ), array( '', '.' ), $raw ); // 1.299,50 → 1299.50.
		} else {
			$raw = str_replace( ',', '', $raw ); // 1,299.50 → 1299.50.
		}
		if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw < 0 ) {
			return '';
		}
		return wc_format_decimal( $raw );
	}

	/**
	 * Current wizard values: plan => { yearly, renewal, lifetime, sites }.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function wizard_values(): array {
		$map = self::map();
		$out = array();
		foreach ( SFLS_Plans::all() as $plan => $def ) {
			$out[ $plan ] = array(
				'sites' => (string) $def['sites'],
			);
			foreach ( self::TYPES as $type ) {
				$out[ $plan ][ $type ] = '';
				$prod                  = ! empty( $map[ $plan ][ $type ] ) ? wc_get_product( (int) $map[ $plan ][ $type ] ) : null;
				if ( ! $prod ) {
					$row  = self::find( $plan, $type );
					$prod = $row ? wc_get_product( $row['id'] ) : null;
				}
				if ( $prod && 'trash' !== $prod->get_status() ) {
					if ( 'publish' === $prod->get_status() ) {
						$out[ $plan ][ $type ] = (string) $prod->get_regular_price();
					}
					$sites = (string) $prod->get_meta( '_sfls_sites' );
					if ( 'yearly' === $type && '' !== $sites ) {
						$out[ $plan ]['sites'] = $sites;
					}
				}
			}
		}
		return $out;
	}
}
