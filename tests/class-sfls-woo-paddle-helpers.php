<?php
/**
 * Helpers for the WooCommerce + Paddle gateway tests: a stateful mock of the
 * Paddle API parts the gateway uses (customers, addresses, transactions,
 * subscriptions, adjustments), WooCommerce checkouts through the real cart,
 * and transactions as Paddle would send them back.
 *
 * Use together with SFLS_Paddle_Helpers in an SFLS_Test_Case.
 *
 * @package SFLS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Mix into a test case that also uses SFLS_Paddle_Helpers.
 */
trait SFLS_Woo_Paddle_Helpers {

	/**
	 * Transactions created through the mock: id => entity.
	 *
	 * @var array<string,array>
	 */
	protected array $txns = array();

	/**
	 * Subscriptions the mock knows: id => entity.
	 *
	 * @var array<string,array>
	 */
	protected array $subs = array();

	/**
	 * Tax Paddle adds, as a fraction of the amount after discount.
	 *
	 * @var float
	 */
	protected float $paddle_tax = 0.0;

	/**
	 * License products made for the test: plan => product.
	 *
	 * @var array<string,WC_Product>
	 */
	protected array $plan_products = array();

	/**
	 * Configure Paddle, sell through the WooCommerce checkout with Paddle
	 * payments, create yearly license products at the Paddle prices and mock
	 * the API.
	 */
	protected function woo_paddle(): void {
		$this->paddle();
		$this->settings(
			array(
				'sell_with'       => 'woo_paddle',
				'simple_checkout' => 1,
			)
		);
		$this->set_option( 'woocommerce_sfls_paddle_settings', array( 'enabled' => 'yes' ) );
		$this->set_option( SFLS_Products::OPTION, array() );
		foreach ( array(
			'starter' => '39',
			'pro'     => '79',
			'agency'  => '199',
		) as $plan => $price ) {
			$this->plan_products[ $plan ] = $this->make_license_product( $plan, 12, $price );
		}
		$this->set_option(
			SFLS_Products::OPTION,
			array_map(
				static fn( $p ) => array( 'yearly' => $p->get_id() ),
				$this->plan_products
			)
		);
		SFLS_Products::flush();
		$this->reset_gateways();

		$this->paddle_routes['GET /customers']                       = array();
		$this->paddle_routes['POST /customers']                      = fn( $call ) => array(
			201,
			array(
				'id'    => 'ctm_' . substr( md5( (string) $call['body']['email'] ), 0, 12 ),
				'email' => $call['body']['email'],
			),
		);
		$this->paddle_routes['#^POST /customers/ctm_\w+/addresses$#'] = array( 'id' => 'add_test01' );
		$this->paddle_routes['POST /transactions']                   = fn( $call ) => array( 201, $this->mock_create_txn( (array) $call['body'] ) );
		$this->paddle_routes['#^GET /transactions/txn_\w+$#']         = function ( $call ) {
			$id = basename( $call['path'] );
			return isset( $this->txns[ $id ] ) ? $this->txns[ $id ] : array( 404, array( 'error' => array( 'code' => 'not_found' ) ) );
		};
		$this->paddle_routes['#^PATCH /transactions/txn_\w+$#']       = function ( $call ) {
			$id = basename( $call['path'] );
			if ( isset( $this->txns[ $id ] ) ) {
				$this->txns[ $id ] = array_merge( $this->txns[ $id ], (array) $call['body'] );
			}
			return $this->txns[ $id ] ?? array();
		};
		$this->paddle_routes['#^GET /subscriptions/sub_\w+$#']        = function ( $call ) {
			$id = basename( $call['path'] );
			return isset( $this->subs[ $id ] ) ? $this->subs[ $id ] : array( 404, array( 'error' => array( 'code' => 'not_found' ) ) );
		};
		$this->paddle_routes['GET /transactions']                    = fn( $call ) => array_values( array_filter( $this->txns, static fn( $t ) => ( $t['subscription_id'] ?? '' ) === ( $call['query']['subscription_id'] ?? '-' ) ) );
	}

	/**
	 * Gateways are cached per request: rebuild them after changing settings.
	 */
	protected function reset_gateways(): void {
		WC()->payment_gateways()->init();
	}

	/**
	 * Paddle's answer to POST /transactions.
	 *
	 * @param array $body Request.
	 */
	protected function mock_create_txn( array $body ): array {
		$amounts  = array(
			'pri_starter'       => 3900,
			'pri_starter_trial' => 3900,
			'pri_pro'           => 7900,
			'pri_pro_trial'     => 7900,
			'pri_agency'        => 19900,
		);
		$item     = (array) $body['items'][0];
		$trial    = str_ends_with( (string) ( $item['price_id'] ?? '' ), '_trial' );
		$subtotal = isset( $item['price_id'] ) ? ( $amounts[ $item['price_id'] ] ?? 0 ) : (int) $item['price']['unit_price']['amount'];
		if ( $trial ) {
			$subtotal = 0; // Nothing is charged on a trial checkout.
		}
		$discount = isset( $body['discount'] ) ? min( $subtotal, (int) $body['discount']['amount'] ) : 0;
		$net      = $subtotal - $discount;
		$tax      = (int) round( $net * $this->paddle_tax );
		$id       = 'txn_' . strtolower( wp_generate_password( 14, false, false ) );
		$txn      = array(
			'id'              => $id,
			'status'          => 'ready',
			'origin'          => 'api',
			'customer_id'     => $body['customer_id'] ?? null,
			'address_id'      => $body['address_id'] ?? null,
			'subscription_id' => null,
			'currency_code'   => $body['currency_code'] ?? 'USD',
			'collection_mode' => $body['collection_mode'] ?? 'automatic',
			'custom_data'     => $body['custom_data'] ?? null,
			'items'           => $body['items'],
			'discount_id'     => $body['discount_id'] ?? null,
			'details'         => array(
				'totals'     => array(
					'subtotal'      => (string) $subtotal,
					'discount'      => (string) $discount,
					'tax'           => (string) $tax,
					'total'         => (string) ( $net + $tax ),
					'grand_total'   => (string) ( $net + $tax ),
					'currency_code' => $body['currency_code'] ?? 'USD',
				),
				'line_items' => array(
					array(
						'id'       => 'txnitm_' . substr( $id, 4, 8 ),
						'price_id' => $item['price_id'] ?? null,
						'quantity' => 1,
						'totals'   => array(
							'subtotal' => (string) $subtotal,
							'discount' => (string) $discount,
							'tax'      => (string) $tax,
							'total'    => (string) ( $net + $tax ),
						),
					),
				),
			),
			'checkout'        => array( 'url' => home_url( '/?_ptxn=' . $id ) ),
		);
		$this->txns[ $id ] = $txn;
		return $txn;
	}

	/**
	 * Put a license product in the cart the way the Buy links do.
	 *
	 * @param string $plan  Plan.
	 * @param bool   $trial With the free trial.
	 * @param array  $data  Other cart item data.
	 */
	protected function cart_plan( string $plan = 'pro', bool $trial = false, array $data = array() ): void {
		$woo = new SFLS_Woo();
		if ( $data ) {
			$woo->put_in_cart( $this->plan_products[ $plan ]->get_id(), $data );
		} else {
			$woo->buy( $this->plan_products[ $plan ]->get_id(), $trial );
		}
		WC()->cart->calculate_totals();
	}

	/**
	 * Run the WooCommerce checkout for the cart (Place order) with Pay with
	 * Paddle, creating the pending order.
	 *
	 * @param string $email Billing email.
	 * @param array  $extra Other posted fields.
	 */
	protected function checkout_order( string $email, array $extra = array() ): WC_Order {
		WC()->cart->calculate_totals();
		$id = WC()->checkout()->create_order(
			array_merge(
				array(
					'billing_first_name' => 'Pat',
					'billing_last_name'  => 'Tester',
					'billing_email'      => $email,
					'billing_country'    => 'US',
					'billing_postcode'   => '10001',
					'payment_method'     => 'sfls_paddle',
				),
				$extra
			)
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		$this->orders[] = $id;
		$order          = wc_get_order( $id );
		$order->set_payment_method( 'sfls_paddle' );
		$order->save();
		return $order;
	}

	/**
	 * Place order: process_payment() through the gateway.
	 *
	 * @param WC_Order $order Order.
	 * @return array Result.
	 */
	protected function pay( WC_Order $order ): array {
		$gateway = SFLS_Woo_Paddle::gateway();
		$this->assertNotNull( $gateway );
		return $gateway->process_payment( $order->get_id() );
	}

	/**
	 * The transaction Paddle would send in transaction.completed for an order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $over  Overrides.
	 * @param bool     $sub   Create a subscription for it.
	 */
	protected function completed_txn( WC_Order $order, array $over = array(), bool $sub = true ): array {
		$order = wc_get_order( $order->get_id() );
		$id    = (string) $order->get_meta( '_sfls_paddle_txn_id' );
		$this->assertArrayHasKey( $id, $this->txns );
		$txn = $this->txns[ $id ];
		if ( $sub ) {
			$kind     = (string) ( $txn['custom_data']['sfls_kind'] ?? 'subscription' );
			$entity   = $this->subscription(
				array(
					'status'      => 'trial' === $kind ? 'trialing' : 'active',
					'customer_id' => (string) $txn['customer_id'],
					'_price'      => (string) ( $txn['items'][0]['price_id'] ?? 'pri_pro' ),
				)
			);
			$this->subs[ $entity['id'] ] = $entity;
			$this->customer_emails[ (string) $txn['customer_id'] ] = $order->get_billing_email();
			$txn['subscription_id'] = $entity['id'];
		}
		$txn['status']                = 'completed';
		$txn['billing_period']        = array(
			'starts_at' => self::when( 0 ),
			'ends_at'   => self::when( 365 * DAY_IN_SECONDS ),
		);
		$txn['payments']              = array(
			array(
				'status'         => 'captured',
				'amount'         => $txn['details']['totals']['total'],
				'method_details' => array(
					'type' => 'card',
					'card' => array(
						'type'  => 'visa',
						'last4' => '4242',
					),
				),
			),
		);
		$txn                          = array_replace_recursive( $txn, $over );
		$this->txns[ $txn['id'] ]     = $txn;
		return $txn;
	}

	/**
	 * Buy a plan end to end (cart, checkout, Place order, webhook) and return
	 * the completed order.
	 *
	 * @param string       $email Email.
	 * @param string       $plan  Plan.
	 * @param bool         $trial Trial.
	 * @param WP_User|null $user  Signed-in customer.
	 */
	protected function buy_with_paddle( string $email, string $plan = 'pro', bool $trial = false, ?WP_User $user = null ): WC_Order {
		if ( $user ) {
			wp_set_current_user( $user->ID );
		}
		$this->cart_plan( $plan, $trial );
		$order  = $this->checkout_order( $email, $user ? array( 'customer_id' => $user->ID ) : array() );
		if ( $user ) {
			$order->set_customer_id( $user->ID );
			$order->save();
		}
		$result = $this->pay( $order );
		$this->assertSame( 'success', $result['result'], wp_json_encode( $this->notices( 'error' ) ) );
		$response = $this->webhook( 'transaction.completed', $this->completed_txn( $order ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		WC()->cart->empty_cart();
		return wc_get_order( $order->get_id() );
	}
}
