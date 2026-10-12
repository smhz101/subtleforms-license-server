<?php
/**
 * Base class: fixtures that clean up after themselves.
 *
 * Every user, license and order a test creates uses an @sfls-test.invalid
 * address and is removed in tear_down, so the tests can run against a site
 * that holds real data.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals

/**
 * Shared helpers.
 */
abstract class SFLS_Test_Case extends \PHPUnit\Framework\TestCase {

	const DOMAIN = 'sfls-test.invalid';

	/**
	 * Created post IDs (products).
	 *
	 * @var int[]
	 */
	protected array $posts = array();

	/**
	 * Created order IDs.
	 *
	 * @var int[]
	 */
	protected array $orders = array();

	/**
	 * Created user IDs.
	 *
	 * @var int[]
	 */
	protected array $users = array();

	/**
	 * Option values to restore: name => value (null = delete).
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = array();

	/**
	 * Filters added by the test: [ hook, callback, priority ].
	 *
	 * @var array[]
	 */
	protected array $filters = array();

	/**
	 * Captured emails.
	 *
	 * @var array[]
	 */
	protected array $mails = array();

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		$this->filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
			WC()->cart->empty_cart();
			wc_clear_notices();
		}
	}

	/**
	 * Remove everything the test created.
	 */
	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->filters as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->filters = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
			wc_clear_notices();
		}
		foreach ( $this->orders as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
			delete_option( 'sfls_issue_lock_' . $id );
		}
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		$like = '%' . $wpdb->esc_like( self::DOMAIN );
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sfls_licenses WHERE customer_email LIKE %s", $like ) );
		foreach ( $ids as $id ) {
			$wpdb->delete( $wpdb->prefix . 'sfls_trial_claims', array( 'license_id' => (int) $id ) );
			SFLS_Licenses::delete( (int) $id );
		}
		// Trial claims, webhook events and accounts the Paddle tests created.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sfls_trial_claims WHERE value LIKE %s OR value LIKE %s OR value LIKE %s", $like, '%sflstest%', '%sfls-site-test%' ) );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sfls_paddle_events WHERE event_id LIKE 'evt\_test\_%'" );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$auto = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE %s OR user_email LIKE %s", $like, '%sflstest%' ) );
		foreach ( array_unique( array_merge( $this->users, array_map( 'intval', $auto ) ) ) as $id ) {
			wp_delete_user( $id );
		}
		foreach ( $this->options as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sfls\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sfls\\_rl\\_%'" );
		SFLS_Products::flush();
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		parent::tearDown();
	}

	/**
	 * Add a filter that is removed after the test.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 */
	protected function filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Change an option for the duration of the test.
	 *
	 * @param string $name  Option.
	 * @param mixed  $value Value (null deletes it).
	 */
	protected function set_option( string $name, $value ): void {
		if ( ! array_key_exists( $name, $this->options ) ) {
			$current                = get_option( $name, null );
			$this->options[ $name ] = $current;
		}
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}

	/**
	 * Change plugin settings for the test.
	 *
	 * @param array $values Settings.
	 */
	protected function settings( array $values ): void {
		$this->set_option( SFLS_Settings::OPTION, array_merge( SFLS_Settings::all(), $values ) );
	}

	/**
	 * A customer account.
	 *
	 * @param string $name Local part of the email.
	 */
	protected function make_user( string $name = '' ): WP_User {
		$name = '' !== $name ? $name : 'cust' . wp_generate_password( 6, false );
		$id   = wp_insert_user(
			array(
				'user_login' => $name . wp_generate_password( 4, false ),
				'user_email' => $name . '@' . self::DOMAIN,
				'user_pass'  => wp_generate_password(),
				'role'       => 'customer',
				'first_name' => 'Test',
			)
		);
		$this->assertIsInt( $id );
		$this->users[] = $id;
		return get_userdata( $id );
	}

	/**
	 * A license product made for the test (virtual, not downloadable).
	 *
	 * @param string $plan   Plan.
	 * @param int    $months 12 or 0.
	 * @param string $price  Price.
	 */
	protected function make_license_product( string $plan = 'pro', int $months = 12, string $price = '99' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test license ' . $plan . ' ' . wp_generate_password( 4, false ) );
		$product->set_regular_price( $price );
		$product->set_virtual( true );
		$product->set_downloadable( false );
		$product->set_sold_individually( true );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_status( 'publish' );
		$product->update_meta_data( '_sfls_enabled', 'yes' );
		$product->update_meta_data( '_sfls_plan', $plan );
		$product->update_meta_data( '_sfls_sites', '' );
		$product->update_meta_data( '_sfls_months', (string) $months );
		$product->update_meta_data( '_sfls_renewal', 'no' );
		$product->save();
		$this->posts[] = $product->get_id();
		SFLS_Products::flush();
		return $product;
	}

	/**
	 * A physical product.
	 */
	protected function make_physical_product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test T-shirt ' . wp_generate_password( 4, false ) );
		$product->set_regular_price( '20' );
		$product->set_virtual( false );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->save();
		$this->posts[] = $product->get_id();
		return $product;
	}

	/**
	 * A pending order "paid with Stripe".
	 *
	 * @param WC_Product[] $products Products.
	 * @param WP_User|null $user     Customer.
	 * @param array        $metas    Line item meta per product index.
	 */
	protected function make_order( array $products, ?WP_User $user = null, array $metas = array() ): WC_Order {
		$order = wc_create_order( array( 'customer_id' => $user ? $user->ID : 0 ) );
		foreach ( $products as $i => $product ) {
			$item_id = $order->add_product( $product, 1 );
			foreach ( (array) ( $metas[ $i ] ?? array() ) as $key => $value ) {
				wc_add_order_item_meta( $item_id, $key, $value );
			}
		}
		$order->set_billing_email( $user ? $user->user_email : 'guest' . wp_generate_password( 4, false ) . '@' . self::DOMAIN );
		$order->set_billing_first_name( 'Test' );
		$order->set_billing_country( 'US' );
		$order->set_payment_method( 'stripe' );
		$order->set_payment_method_title( 'Credit card (Stripe)' );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order->save();
		$this->orders[] = $order->get_id();
		return $order;
	}

	/**
	 * Licenses issued for an order (fresh from the database).
	 *
	 * @param int $order_id Order.
	 * @return array[]
	 */
	protected function licenses_of_order( int $order_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE order_id = %d", $order_id ), ARRAY_A );
	}

	/**
	 * A license for a test customer.
	 *
	 * @param array $args SFLS_Licenses::create() args.
	 */
	protected function make_license( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'plan'           => 'pro',
				'customer_email' => 'lic' . wp_generate_password( 5, false ) . '@' . self::DOMAIN,
				'months'         => 12,
			)
		);
		return SFLS_Licenses::create( $args );
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $params Params.
	 */
	protected function rest( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_ensure_response( rest_do_request( $request ) );
	}

	/**
	 * Text of the WooCommerce notices queued so far.
	 *
	 * @param string $type Notice type.
	 * @return string[]
	 */
	protected function notices( string $type = 'notice' ): array {
		return array_map(
			static fn( $n ) => wp_strip_all_tags( is_array( $n ) ? $n['notice'] : (string) $n ),
			wc_get_notices( $type )
		);
	}
}
