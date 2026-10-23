<?php
/**
 * WooCommerce orders paid with Paddle: what the Paddle webhook does to them.
 *
 *   transaction.completed (origin api, custom_data.wc_order_id)
 *       The checkout of a WooCommerce order. The order key, currency and
 *       amount (before tax) are checked, Paddle's tax is added as a fee line
 *       so the order total matches what Paddle charged, and
 *       $order->payment_complete() runs. The existing order completion issues
 *       the license once (the `_sfls_issued` guard), then the license is
 *       linked to the Paddle subscription (billing = paddle).
 *   subscription.created / trialing / activated for a subscription with no
 *       license yet: the order is found through custom_data, the creating
 *       transaction or the subscription's transactions, then completed (when
 *       the transaction already is) and linked.
 *   transaction.completed (origin subscription_recurring)
 *       A renewal (or the first payment after a trial): the license is
 *       extended as before, and a Completed renewal order is recorded so the
 *       payment shows in WooCommerce reports and the customer's Orders.
 *   transaction.completed (origin subscription_update)
 *       The prorated charge of an upgrade paid at the WooCommerce checkout:
 *       the transaction ID is added to the upgrade order.
 *   transaction.payment_failed
 *       Checkout attempts get an order note; renewals keep the existing
 *       past due emails and add a note to the license's order.
 *   adjustment.created / adjustment.updated
 *       Refunds and chargebacks made in Paddle are mirrored as WooCommerce
 *       refunds once approved. Refunds made from WooCommerce (process_refund)
 *       are remembered by adjustment ID, so they are never refunded twice.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order side of the Paddle gateway.
 */
final class SFLS_Woo_Paddle_Orders {

	/**
	 * Paddle context for the order being completed right now, so the license
	 * is linked before the order emails are written: { sub_id, sub, txn }.
	 *
	 * @var array<int,array>
	 */
	private static array $pending_link = array();

	/**
	 * Adjustment created by the last refund() call.
	 *
	 * @var string
	 */
	private static string $last_adjustment = '';

	/**
	 * Hook in when WooCommerce is loaded.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) && ! did_action( 'woocommerce_loaded' ) ) {
			add_action( 'woocommerce_loaded', array( $this, 'hooks' ) );
			return;
		}
		$this->hooks();
	}

	/**
	 * WooCommerce hooks.
	 */
	public function hooks(): void {
		add_action( 'sfls_order_issued', array( self::class, 'link_after_issue' ) );
		add_action( 'woocommerce_order_refunded', array( self::class, 'remember_adjustment' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'cancel_on_full_refund' ), 20 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( self::class, 'quiet_renewal_email' ), 20, 2 );
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( self::class, 'quiet_renewal_email' ), 20, 2 );
	}

	// ── Lookups ─────────────────────────────────────────────────────────

	/**
	 * Orders with a meta value, newest first.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Value.
	 * @param int    $limit Max.
	 * @return WC_Order[]
	 */
	public static function orders_by_meta( string $key, string $value, int $limit = 1 ): array {
		if ( '' === $value ) {
			return array();
		}
		return array_values(
			array_filter(
				(array) wc_get_orders(
					array(
						'limit'      => $limit,
						'type'       => 'shop_order',
						'status'     => array_keys( wc_get_order_statuses() ),
						'orderby'    => 'date',
						'order'      => 'DESC',
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'   => $key,
								'value' => $value,
							),
						),
					)
				),
				static fn( $o ) => $o instanceof WC_Order
			)
		);
	}

	/**
	 * The order a Paddle payment belongs to (by transaction ID).
	 *
	 * @param string $txn_id Transaction ID.
	 */
	public static function order_by_transaction( string $txn_id ): ?WC_Order {
		if ( '' === $txn_id ) {
			return null;
		}
		$found = wc_get_orders(
			array(
				'limit'          => 1,
				'type'           => 'shop_order',
				'status'         => array_keys( wc_get_order_statuses() ),
				'transaction_id' => $txn_id,
			)
		);
		if ( $found && $found[0] instanceof WC_Order ) {
			return $found[0];
		}
		foreach ( array( '_sfls_paddle_paid_txn', '_sfls_paddle_txn_id' ) as $key ) {
			$orders = self::orders_by_meta( $key, $txn_id );
			if ( $orders ) {
				return $orders[0];
			}
		}
		return null;
	}

	/**
	 * The order named in a transaction's custom data, when its key matches.
	 *
	 * @param array $custom custom_data.
	 */
	private static function order_from_custom( $custom ): ?WC_Order {
		if ( ! is_array( $custom ) || empty( $custom['wc_order_id'] ) ) {
			return null;
		}
		$order = wc_get_order( absint( $custom['wc_order_id'] ) );
		if ( ! $order instanceof WC_Order || ! hash_equals( $order->get_order_key(), (string) ( $custom['order_key'] ?? '' ) ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * Short lock per order (webhook and the customer's return can race).
	 *
	 * @param int $order_id Order.
	 */
	private static function lock( int $order_id ): bool {
		global $wpdb;
		$name = 'sfls_paddle_lock_' . $order_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $name, (string) time() ) );
		if ( ! $got ) {
			$since = (int) get_option( $name );
			if ( $since && $since > time() - MINUTE_IN_SECONDS ) {
				return false;
			}
			update_option( $name, (string) time(), false );
		}
		return true;
	}

	/**
	 * Release the lock.
	 *
	 * @param int $order_id Order.
	 */
	private static function unlock( int $order_id ): void {
		delete_option( 'sfls_paddle_lock_' . $order_id );
	}

	/**
	 * Result array.
	 *
	 * @param string     $action Action.
	 * @param string     $note   Note.
	 * @param array|null $lic    License.
	 * @return array{action:string,note:string,license:?array}
	 */
	private static function result( string $action, string $note, ?array $lic = null ): array {
		return array(
			'action'  => $action,
			'note'    => $note,
			'license' => $lic,
		);
	}

	/**
	 * "$94.80" for minor units.
	 *
	 * @param int|string $minor    Amount.
	 * @param string     $currency Currency.
	 */
	private static function money( $minor, string $currency ): string {
		return SFLS_Paddle::format_amount( (string) $minor, $currency );
	}

	// ── Webhook entry points ────────────────────────────────────────────

	/**
	 * transaction.completed.
	 *
	 * @param array $txn Transaction.
	 * @return array{action:string,note:string,license:?array}
	 */
	public static function transaction_completed( array $txn ): array {
		$origin = (string) ( $txn['origin'] ?? '' );
		if ( 'subscription_recurring' === $origin ) {
			$result = SFLS_Billing::transaction_completed( $txn );
			if ( $result['license'] ) {
				$order = self::renewal_order( $result['license'], $txn );
				if ( $order ) {
					/* translators: %d: order ID */
					$result['note'] = trim( $result['note'] . ' ' . sprintf( 'Renewal order #%d.', $order->get_id() ) );
				}
			}
			return $result;
		}
		if ( 'subscription_update' === $origin ) {
			return self::upgrade_charged( $txn );
		}
		$order = self::order_from_custom( $txn['custom_data'] ?? null ) ?? ( 'api' === $origin ? self::order_by_transaction( (string) ( $txn['id'] ?? '' ) ) : null );
		if ( ! $order ) {
			return SFLS_Billing::transaction_completed( $txn ); // Legacy overlay checkout or a transaction we did not create.
		}
		return self::complete_checkout( $order, $txn );
	}

	/**
	 * A subscription with no license yet: if a WooCommerce order paid for it,
	 * complete (when paid) and link that order instead of creating a license
	 * from the subscription. Null when no order belongs to it.
	 *
	 * @param array $sub Subscription.
	 * @param array $ctx event_type, transaction_id, ...
	 * @return array{action:string,note:string,license:?array}|null
	 * @throws RuntimeException When Paddle cannot be reached (Paddle retries the webhook).
	 */
	public static function subscription_event( array $sub, array $ctx ): ?array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$sub_id = (string) ( $sub['id'] ?? '' );
		$txn_id = (string) ( $ctx['transaction_id'] ?? '' );
		$order  = self::order_from_custom( $sub['custom_data'] ?? null );
		if ( ! $order && '' !== $txn_id ) {
			$order = self::orders_by_meta( '_sfls_paddle_txn_id', $txn_id )[0] ?? null;
		}
		if ( ! $order ) {
			$order = self::orders_by_meta( '_sfls_paddle_subscription_id', $sub_id )[0] ?? null;
		}
		if ( ! $order && 'woo_paddle' === SFLS_Settings::sell_with() && SFLS_Paddle::has_api_key() ) {
			$list = SFLS_Paddle::subscription_transactions( $sub_id );
			if ( is_wp_error( $list ) ) {
				throw new RuntimeException( esc_html( 'Transactions of ' . $sub_id . ' could not be fetched: ' . $list->get_error_message() ) );
			}
			foreach ( $list as $row ) {
				$order = self::order_from_custom( $row['custom_data'] ?? null );
				if ( $order ) {
					$txn_id = '' !== $txn_id ? $txn_id : (string) ( $row['id'] ?? '' );
					break;
				}
			}
		}
		if ( ! $order ) {
			return null;
		}
		$occurred = (string) ( $ctx['occurred_at'] ?? '' );
		if ( '' !== (string) $order->get_meta( '_sfls_paddle_paid_txn' ) ) {
			$lic = self::link_licenses( $order, $sub_id, $sub, array(), $occurred );
			return self::result( 'linked', sprintf( 'Linked to order #%d.', $order->get_id() ), $lic );
		}
		if ( ! in_array( (string) ( $sub['status'] ?? '' ), array( 'trialing', 'active', 'past_due' ), true ) ) {
			return self::result( 'ignored', sprintf( 'Order #%d is not paid and the subscription is %s.', $order->get_id(), (string) ( $sub['status'] ?? '' ) ) );
		}
		$txn_id = '' !== $txn_id ? $txn_id : (string) $order->get_meta( '_sfls_paddle_txn_id' );
		$txn    = '' !== $txn_id ? SFLS_Paddle::get_transaction( $txn_id ) : new WP_Error( 'sfls_no_txn', 'No transaction.' );
		if ( is_wp_error( $txn ) ) {
			throw new RuntimeException( esc_html( 'Transaction for order #' . $order->get_id() . ' could not be fetched: ' . $txn->get_error_message() ) );
		}
		if ( ! in_array( (string) ( $txn['status'] ?? '' ), array( 'completed', 'paid' ), true ) ) {
			$order->update_meta_data( '_sfls_paddle_subscription_id', $sub_id );
			$order->save_meta_data();
			return self::result( 'waiting', sprintf( 'Order #%d waits for transaction %s.', $order->get_id(), $txn_id ) );
		}
		return self::complete_checkout( $order, $txn, $sub, $occurred );
	}

	// ── Checkout completion ─────────────────────────────────────────────

	/**
	 * Complete a WooCommerce order from its paid Paddle transaction.
	 *
	 * @param WC_Order   $order    Order.
	 * @param array      $txn      Transaction (completed or paid).
	 * @param array|null $sub      Subscription, when known.
	 * @param string     $occurred When the subscription event that brought $sub happened (GMT), '' when none.
	 * @return array{action:string,note:string,license:?array}
	 */
	public static function complete_checkout( WC_Order $order, array $txn, ?array $sub = null, string $occurred = '' ): array {
		$order_id = $order->get_id();
		$txn_id   = (string) ( $txn['id'] ?? '' );
		$sub_id   = (string) ( $txn['subscription_id'] ?? ( $sub['id'] ?? '' ) );
		if ( ! self::lock( $order_id ) ) {
			throw new RuntimeException( esc_html( 'Order #' . $order_id . ' is being completed by another request.' ) );
		}
		try {
			$order = wc_get_order( $order_id ); // Fresh, inside the lock.
			$paid  = (string) $order->get_meta( '_sfls_paddle_paid_txn' );
			if ( '' !== $paid ) {
				if ( $paid !== $txn_id ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: transaction ID, 2: transaction ID */
							__( 'Paddle transaction %1$s also completed for this order, which was already paid by %2$s. Refund the duplicate payment in Paddle.', 'subtleforms-license-server' ),
							$txn_id,
							$paid
						)
					);
					return self::result( 'duplicate', 'Order already paid by ' . $paid . '.' );
				}
				$lic = '' !== $sub_id ? self::link_licenses( $order, $sub_id, $sub, $txn, $occurred ) : null;
				return self::result( 'ignored', 'Order already completed.', $lic );
			}

			$custom = (array) ( $txn['custom_data'] ?? array() );
			if ( isset( $custom['order_key'] ) && ! hash_equals( $order->get_order_key(), (string) $custom['order_key'] ) ) {
				/* translators: %s: transaction ID */
				$order->add_order_note( sprintf( __( 'Paddle transaction %s names this order with a wrong order key. Nothing was changed.', 'subtleforms-license-server' ), $txn_id ) );
				return self::result( 'rejected', 'Order key mismatch.' );
			}
			$totals   = (array) ( $txn['details']['totals'] ?? array() );
			$currency = strtoupper( (string) ( $totals['currency_code'] ?? $txn['currency_code'] ?? '' ) );
			$expected = (int) SFLS_Paddle::to_minor( (string) $order->get_total(), $order->get_currency() );
			$net      = SFLS_Paddle::net_of( $totals );
			$total    = (int) ( $totals['total'] ?? 0 );
			$tax      = (int) ( $totals['tax'] ?? 0 );
			$net_ok   = abs( $net - $expected ) <= 1;
			if ( strtoupper( $order->get_currency() ) !== $currency || ( ! $net_ok && abs( $total - $expected ) > 1 ) ) {
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: transaction ID, 2: amount paid, 3: order total */
						__( 'Paddle transaction %1$s paid %2$s before tax, which does not match the order total of %3$s. The order is on hold and no license was issued. Check the payment in Paddle.', 'subtleforms-license-server' ),
						$txn_id,
						self::money( $net, '' !== $currency ? $currency : $order->get_currency() ),
						self::money( $expected, $order->get_currency() )
					)
				);
				return self::result( 'rejected', 'Amount or currency mismatch.' );
			}

			// The WooCommerce total becomes what Paddle charged, tax included.
			if ( $tax > 0 && $net_ok ) {
				$fee = new WC_Order_Item_Fee();
				$fee->set_name( __( 'Sales tax / VAT (collected by Paddle)', 'subtleforms-license-server' ) );
				$fee->set_tax_status( 'none' );
				$fee->set_total( SFLS_Paddle::to_decimal( (string) $tax, $currency ) );
				$order->add_item( $fee );
				$order->set_total( SFLS_Paddle::to_decimal( (string) $total, $currency ) );
			} elseif ( $tax > 0 ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: tax amount */
						__( 'Paddle included %s of sales tax or VAT in the price.', 'subtleforms-license-server' ),
						self::money( $tax, $currency )
					)
				);
			}
			$title = self::method_title( $txn );
			if ( '' !== $title ) {
				$order->set_payment_method_title( $title );
			}
			$order->update_meta_data( '_sfls_paddle_paid_txn', $txn_id );
			$order->update_meta_data( '_sfls_paddle_customer_id', (string) ( $txn['customer_id'] ?? $order->get_meta( '_sfls_paddle_customer_id' ) ) );
			if ( '' !== $sub_id ) {
				$order->update_meta_data( '_sfls_paddle_subscription_id', $sub_id );
			}
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: transaction ID */
					__( 'Paid %1$s with Paddle (transaction %2$s).', 'subtleforms-license-server' ),
					self::money( $total, $currency ),
					$txn_id
				)
			);

			// payment_complete() moves the order to Completed, which issues the
			// license once; link_after_issue() then ties it to the subscription.
			self::$pending_link[ $order_id ] = array(
				'sub_id'   => $sub_id,
				'sub'      => $sub,
				'txn'      => $txn,
				'occurred' => $occurred,
			);
			$order->payment_complete( $txn_id );
			$lic = self::link_licenses( wc_get_order( $order_id ), $sub_id, $sub, $txn, $occurred );
			unset( self::$pending_link[ $order_id ] );
			return self::result( 'completed', sprintf( 'Order #%d completed.', $order_id ), $lic );
		} finally {
			self::unlock( $order_id );
		}
	}

	/**
	 * "Paddle (Visa ending 4242)" from a transaction's payment.
	 *
	 * @param array $txn Transaction.
	 */
	private static function method_title( array $txn ): string {
		$payment = (array) ( $txn['payments'][0] ?? array() );
		$details = (array) ( $payment['method_details'] ?? array() );
		$type    = (string) ( $details['type'] ?? '' );
		if ( 'card' === $type && ! empty( $details['card']['last4'] ) ) {
			/* translators: 1: card brand, 2: last four digits */
			return sprintf( __( 'Paddle (%1$s ending %2$s)', 'subtleforms-license-server' ), ucfirst( (string) ( $details['card']['type'] ?? 'card' ) ), (string) $details['card']['last4'] );
		}
		$names = array(
			'paypal'     => 'PayPal',
			'apple_pay'  => 'Apple Pay',
			'google_pay' => 'Google Pay',
		);
		/* translators: %s: payment method */
		return isset( $names[ $type ] ) ? sprintf( __( 'Paddle (%s)', 'subtleforms-license-server' ), $names[ $type ] ) : '';
	}

	/**
	 * After the order issued its license (inside payment_complete()), link it
	 * to Paddle before any email is written.
	 *
	 * @param int $order_id Order.
	 */
	public static function link_after_issue( $order_id ): void {
		$ctx = self::$pending_link[ (int) $order_id ] ?? null;
		if ( ! $ctx ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order ) {
			self::link_licenses( $order, (string) $ctx['sub_id'], $ctx['sub'], (array) $ctx['txn'], (string) ( $ctx['occurred'] ?? '' ) );
		}
	}

	/**
	 * Tie the licenses of a paid order to its Paddle subscription, so they
	 * follow it (trial, renewals, past due, cancel). Safe to run again.
	 *
	 * The license is stamped as synced at the time the subscription state is
	 * from: the event's occurred_at, else the subscription's updated_at, else
	 * now. Later events are then applied, older ones skipped as stale.
	 *
	 * @param WC_Order   $order    Order.
	 * @param string     $sub_id   Subscription ID ('' for one-time payments).
	 * @param array|null $sub      Subscription entity (fetched when null).
	 * @param array      $txn      Checkout transaction.
	 * @param string     $occurred occurred_at of the event that brought $sub (GMT), '' when none.
	 * @return array|null First license.
	 */
	public static function link_licenses( WC_Order $order, string $sub_id, ?array $sub = null, array $txn = array(), string $occurred = '' ): ?array {
		$licenses = SFLS_Woo::order_licenses( $order->get_id() );
		if ( ! $licenses ) {
			return null;
		}
		if ( '' === $sub_id ) {
			return $licenses[0]; // One-time payment: a plain WooCommerce license.
		}
		if ( null === $sub && SFLS_Paddle::has_api_key() ) {
			$fetched = SFLS_Paddle::get_subscription( $sub_id );
			$sub     = is_wp_error( $fetched ) ? null : $fetched;
		}
		$trial_order = '' !== self::trial_days( $order );
		$out         = null;
		foreach ( $licenses as $lic ) {
			$owner = SFLS_Licenses::find_by_subscription( $sub_id );
			if ( $owner && (int) $owner['id'] !== (int) $lic['id'] ) {
				continue; // Another license already follows this subscription.
			}
			$first  = '' === (string) $lic['paddle_subscription_id'];
			$fields = array(
				'billing'                => 'paddle',
				'paddle_subscription_id' => $sub_id,
				'paddle_customer_id'     => (string) ( $txn['customer_id'] ?? ( $sub['customer_id'] ?? $order->get_meta( '_sfls_paddle_customer_id' ) ) ),
			);
			if ( '' === (string) $lic['paddle_transaction_id'] && ! empty( $txn['id'] ) ) {
				$fields['paddle_transaction_id'] = (string) $txn['id'];
			}
			if ( $sub ) {
				$from_sub = SFLS_Billing::fields_for( $sub, $lic );
				// A renewal bought early keeps the later expiry.
				if ( ! empty( $lic['expires_at'] ) && ! empty( $from_sub['expires_at'] ) && strtotime( $lic['expires_at'] . ' UTC' ) > strtotime( $from_sub['expires_at'] . ' UTC' ) && 'trialing' !== ( $sub['status'] ?? '' ) && ! $first ) {
					unset( $from_sub['expires_at'] );
				}
				if ( ! $first ) {
					unset( $from_sub['plan'] ); // Plan changes come through the subscription events.
				}
				$fields                      = array_merge( $from_sub, $fields );
				$fields['billing_synced_at'] = self::synced_at( $occurred, $sub, $lic );
			} elseif ( $first ) {
				if ( $trial_order ) {
					$days                    = (int) self::trial_days( $order );
					$fields['trial']         = 1;
					$fields['sites_allowed'] = 1;
					$fields['billing_status'] = 'trialing';
					$fields['trial_ends_at'] = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
					$fields['expires_at']    = gmdate( 'Y-m-d H:i:s', time() + ( $days + SFLS_Paddle::BILLING_GRACE_DAYS ) * DAY_IN_SECONDS );
				} else {
					$fields['billing_status'] = 'active';
				}
			}
			if ( $first && $trial_order ) {
				$fields['trial_started_at'] = current_time( 'mysql', true );
			}
			SFLS_Licenses::update( (int) $lic['id'], $fields );
			if ( $first ) {
				if ( $trial_order ) {
					SFLS_Trials::record_start( (int) $lic['id'], (string) $lic['customer_email'] );
				}
				$order->add_order_note(
					sprintf(
						/* translators: 1: masked key, 2: subscription ID */
						__( 'License %1$s follows Paddle subscription %2$s: renewals, trial, cancel and resume run through Paddle.', 'subtleforms-license-server' ),
						SFLS_Licenses::mask_key( (string) $lic['license_key'] ),
						$sub_id
					)
				);
			}
			$out = $out ?? SFLS_Licenses::find( (int) $lic['id'] );
		}
		if ( $sub_id !== (string) $order->get_meta( '_sfls_paddle_subscription_id' ) ) {
			$order->update_meta_data( '_sfls_paddle_subscription_id', $sub_id );
			$order->save_meta_data();
		}
		return $out ?? $licenses[0];
	}

	/**
	 * When the subscription state applied by link_licenses() is from: the
	 * event time, else the subscription's updated_at, else now. Never earlier
	 * than what the license already applied.
	 *
	 * @param string $occurred Event time (GMT) or ''.
	 * @param array  $sub      Subscription.
	 * @param array  $lic      License.
	 */
	private static function synced_at( string $occurred, array $sub, array $lic ): string {
		$at = '' !== $occurred ? $occurred : (string) ( SFLS_Billing::gmt( $sub['updated_at'] ?? null ) ?? current_time( 'mysql', true ) );
		if ( ! empty( $lic['billing_synced_at'] ) && strtotime( $lic['billing_synced_at'] . ' UTC' ) > strtotime( $at . ' UTC' ) ) {
			return (string) $lic['billing_synced_at'];
		}
		return $at;
	}

	/**
	 * Trial days of an order's license line ('' when it is not a trial).
	 *
	 * @param WC_Order $order Order.
	 */
	private static function trial_days( WC_Order $order ): string {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && '' !== (string) $item->get_meta( '_sfls_trial' ) ) {
				return (string) $item->get_meta( '_sfls_trial' );
			}
		}
		return '';
	}

	/**
	 * The customer is back on the order received page before the webhook:
	 * ask Paddle once every few seconds whether the transaction is paid and
	 * complete the order if it is.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function poll( WC_Order $order ): void {
		if ( SFLS_Woo_Paddle::GATEWAY !== $order->get_payment_method() || '' !== (string) $order->get_meta( '_sfls_paddle_paid_txn' ) || ! SFLS_Paddle::has_api_key() ) {
			return;
		}
		$txn_id = (string) $order->get_meta( '_sfls_paddle_txn_id' );
		$key    = 'sfls_paddle_poll_' . $order->get_id();
		if ( '' === $txn_id || get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 5 );
		$txn = SFLS_Paddle::get_transaction( $txn_id );
		if ( is_wp_error( $txn ) || ! in_array( (string) ( $txn['status'] ?? '' ), array( 'completed', 'paid' ), true ) ) {
			return;
		}
		try {
			self::complete_checkout( $order, $txn );
		} catch ( RuntimeException $e ) {
			unset( $e ); // Another request is completing it.
		}
	}

	// ── Renewals ────────────────────────────────────────────────────────

	/**
	 * Record a Paddle renewal charge as a Completed WooCommerce order linked
	 * to the license's first order. It never issues a license (the existing
	 * one was extended by SFLS_Billing::transaction_completed()).
	 *
	 * @param array $lic License.
	 * @param array $txn Transaction.
	 */
	public static function renewal_order( array $lic, array $txn ): ?WC_Order {
		$totals = (array) ( $txn['details']['totals'] ?? array() );
		$total  = (int) ( $totals['total'] ?? 0 );
		$txn_id = (string) ( $txn['id'] ?? '' );
		$parent = (int) $lic['order_id'] ? wc_get_order( (int) $lic['order_id'] ) : null;
		if ( $total <= 0 || '' === $txn_id || ! $parent instanceof WC_Order ) {
			return null;
		}
		$existing = self::order_by_transaction( $txn_id );
		if ( $existing ) {
			return $existing;
		}
		$currency = strtoupper( (string) ( $totals['currency_code'] ?? $txn['currency_code'] ?? $parent->get_currency() ) );
		$tax      = (int) ( $totals['tax'] ?? 0 );
		$net      = $total - $tax;
		$plan     = (string) $lic['plan'];
		$was      = 'trialing' === (string) $lic['billing_status'] || (int) $lic['trial'];

		$order = wc_create_order(
			array(
				'customer_id' => (int) $lic['user_id'] ? (int) $lic['user_id'] : (int) $parent->get_customer_id(),
				'created_via' => 'sfls_paddle_renewal',
			)
		);
		if ( is_wp_error( $order ) ) {
			return null;
		}
		$order->set_currency( $currency );
		$order->set_address( $parent->get_address( 'billing' ), 'billing' );
		if ( '' === $order->get_billing_email() ) {
			$order->set_billing_email( (string) $lic['customer_email'] );
		}
		$row     = SFLS_Products::find( $plan, 'renewal' ) ?? SFLS_Products::find( $plan, 'yearly' );
		$product = $row ? wc_get_product( $row['id'] ) : null;
		$amount  = SFLS_Paddle::to_decimal( (string) $net, $currency );
		if ( $product ) {
			$item_id = $order->add_product(
				$product,
				1,
				array(
					'subtotal' => $amount,
					'total'    => $amount,
				)
			);
			wc_update_order_item_meta( $item_id, '_sfls_license_ids', (string) $lic['id'] );
			wc_update_order_item_meta( $item_id, '_sfls_renew_license', (int) $lic['id'] );
		} else {
			$fee = new WC_Order_Item_Fee();
			/* translators: %s: plan */
			$fee->set_name( sprintf( __( 'SubtleForms Pro %s renewal', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) ) );
			$fee->set_tax_status( 'none' );
			$fee->set_total( $amount );
			$order->add_item( $fee );
		}
		if ( $tax > 0 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( __( 'Sales tax / VAT (collected by Paddle)', 'subtleforms-license-server' ) );
			$fee->set_tax_status( 'none' );
			$fee->set_total( SFLS_Paddle::to_decimal( (string) $tax, $currency ) );
			$order->add_item( $fee );
		}
		$order->set_payment_method( SFLS_Woo_Paddle::GATEWAY );
		$title = self::method_title( $txn );
		$order->set_payment_method_title( '' !== $title ? $title : __( 'Pay with Paddle', 'subtleforms-license-server' ) );
		$order->set_transaction_id( $txn_id );
		$order->set_date_paid( time() );
		$order->set_total( SFLS_Paddle::to_decimal( (string) $total, $currency ) );
		$order->update_meta_data( '_sfls_paddle_renewal', 'yes' );
		$order->update_meta_data( '_sfls_parent_order', $parent->get_id() );
		$order->update_meta_data( '_sfls_paddle_paid_txn', $txn_id );
		$order->update_meta_data( '_sfls_paddle_subscription_id', (string) ( $txn['subscription_id'] ?? '' ) );
		$order->update_meta_data( '_sfls_issued', 'yes' ); // Never issues a new key.
		$order->save();
		$note = $was
			/* translators: 1: masked key, 2: parent order number, 3: transaction ID */
			? __( 'First yearly payment after the free trial of license %1$s (order #%2$s), charged by Paddle (transaction %3$s). The license was extended, no new key was issued.', 'subtleforms-license-server' )
			/* translators: 1: masked key, 2: parent order number, 3: transaction ID */
			: __( 'Automatic renewal of license %1$s (order #%2$s), charged by Paddle (transaction %3$s). The license was extended, no new key was issued.', 'subtleforms-license-server' );
		$order->update_status( 'completed', sprintf( $note, SFLS_Licenses::mask_key( (string) $lic['license_key'] ), $parent->get_order_number(), $txn_id ) );
		$parent->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: order number */
				__( 'Paddle renewal payment of %1$s recorded as order #%2$s.', 'subtleforms-license-server' ),
				self::money( $total, $currency ),
				$order->get_order_number()
			)
		);
		return $order;
	}

	/**
	 * No "order completed" email for renewal orders: the Paddle receipt and
	 * the license's payment email already told the customer.
	 *
	 * @param bool  $enabled Enabled.
	 * @param mixed $order   Order.
	 * @return bool
	 */
	public static function quiet_renewal_email( $enabled, $order = null ) {
		return $order instanceof WC_Order && 'yes' === $order->get_meta( '_sfls_paddle_renewal' ) ? false : $enabled;
	}

	/**
	 * transaction.payment_failed.
	 *
	 * @param array $txn Transaction.
	 * @return array{action:string,note:string,license:?array}
	 */
	public static function payment_failed( array $txn ): array {
		$origin = (string) ( $txn['origin'] ?? '' );
		$txn_id = (string) ( $txn['id'] ?? '' );
		$order  = 'subscription_recurring' === $origin ? null : ( self::order_from_custom( $txn['custom_data'] ?? null ) ?? self::order_by_transaction( $txn_id ) );
		if ( $order ) {
			if ( '' === (string) $order->get_meta( '_sfls_paddle_paid_txn' ) && self::note_once( $order, 'failed|' . $txn_id ) ) {
				/* translators: %s: transaction ID */
				$order->add_order_note( sprintf( __( 'A payment attempt failed in the Paddle checkout (transaction %s). The order stays pending, and the customer can try again.', 'subtleforms-license-server' ), $txn_id ) );
			}
			return self::result( 'noted', 'Checkout attempt failed.' );
		}
		$result = SFLS_Billing::payment_failed( $txn );
		$lic    = $result['license'];
		$parent = $lic && (int) $lic['order_id'] ? wc_get_order( (int) $lic['order_id'] ) : null;
		if ( $parent instanceof WC_Order && 'web' !== $origin && self::note_once( $parent, 'failed|' . $txn_id ) ) {
			$parent->add_order_note(
				sprintf(
					/* translators: 1: masked key, 2: transaction ID */
					__( 'Paddle could not charge the renewal of license %1$s (transaction %2$s). The customer was emailed to update their payment method; Paddle retries automatically.', 'subtleforms-license-server' ),
					SFLS_Licenses::mask_key( (string) $lic['license_key'] ),
					$txn_id
				)
			);
		}
		return $result;
	}

	/**
	 * Adjustments an order knows: id => { source, status, refund_id }.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function adjustments_of( WC_Order $order ): array {
		$map = $order->get_meta( '_sfls_paddle_adjustments' );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Remember a note marker on an order; false when it was there already.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $marker Marker.
	 */
	private static function note_once( WC_Order $order, string $marker ): bool {
		$seen = $order->get_meta( '_sfls_paddle_notes' );
		$seen = is_array( $seen ) ? $seen : array();
		if ( in_array( $marker, $seen, true ) ) {
			return false;
		}
		$seen[] = $marker;
		$order->update_meta_data( '_sfls_paddle_notes', array_slice( $seen, -50 ) );
		$order->save_meta_data();
		return true;
	}

	// ── Upgrades ────────────────────────────────────────────────────────

	/**
	 * Pay an upgrade of a Paddle license at the WooCommerce checkout: the
	 * subscription moves to the new plan's price with prorated_immediately,
	 * Paddle charges the saved payment method at once, the key stays, and the
	 * order records the prorated charge. During a trial nothing is charged
	 * (the new plan is billed when the trial ends).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $line  SFLS_Woo_Paddle::order_line().
	 * @return array{result:string,redirect:string}|WP_Error
	 */
	public static function pay_upgrade( WC_Order $order, array $line ) {
		$lic      = (array) $line['license'];
		$plan     = (string) $line['plan'];
		$map      = SFLS_Paddle::plan( $plan );
		$trialing = 'trialing' === (string) $lic['billing_status'];
		$price    = $trialing && '' !== $map['trial_price_id'] ? $map['trial_price_id'] : $map['price_id'];
		$currency = $order->get_currency();
		$body     = array(
			'items'                  => array(
				array(
					'price_id' => $price,
					'quantity' => 1,
				),
			),
			'proration_billing_mode' => $trialing ? 'do_not_bill' : 'prorated_immediately',
		);
		if ( ! $trialing ) {
			$body['on_payment_failure'] = 'prevent_change';
		}
		$net = 0;
		$tax = 0;
		if ( ! $trialing ) {
			$preview = SFLS_Paddle::preview_subscription_update( (string) $lic['paddle_subscription_id'], $body );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
			$totals   = (array) ( $preview['immediate_transaction']['details']['totals'] ?? array() );
			$net      = SFLS_Paddle::net_of( $totals );
			$tax      = (int) ( $totals['tax'] ?? 0 );
			$currency = strtoupper( (string) ( $totals['currency_code'] ?? $currency ) );
			$expected = (int) SFLS_Paddle::to_minor( (string) $order->get_total(), $order->get_currency() );
			// Paddle prorates to the second; allow 1% (at least one cent) above the estimate.
			if ( $net > $expected + max( 1, (int) ceil( $expected / 100 ) ) ) {
				return new WP_Error(
					'sfls_upgrade_price',
					sprintf(
						/* translators: %s: amount */
						__( 'The upgrade costs %s today. Reload the checkout to see the current price.', 'subtleforms-license-server' ),
						self::money( $net, $currency )
					)
				);
			}
		}
		$sub = SFLS_Paddle::update_subscription( (string) $lic['paddle_subscription_id'], $body );
		if ( is_wp_error( $sub ) ) {
			return new WP_Error(
				'sfls_upgrade_failed',
				sprintf(
					/* translators: %s: Paddle's message */
					__( 'Paddle could not charge your saved payment method, so your plan did not change (%s). Update your payment method in My Account › Licenses and try again.', 'subtleforms-license-server' ),
					$sub->get_error_message()
				)
			);
		}
		if ( SFLS_Paddle::plan_for_price( $price ) !== $plan ) {
			$sub['custom_data'] = array( 'sfls_plan' => $plan );
		}
		SFLS_Billing::sync_subscription( $sub );

		// Record what Paddle charged.
		$item   = $line['item'];
		$amount = SFLS_Paddle::to_decimal( (string) $net, $currency );
		if ( abs( (float) $item->get_total() - (float) $amount ) > 0.001 ) {
			$item->set_subtotal( $amount );
			$item->set_total( $amount );
			$item->save();
		}
		if ( $tax > 0 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( __( 'Sales tax / VAT (collected by Paddle)', 'subtleforms-license-server' ) );
			$fee->set_tax_status( 'none' );
			$fee->set_total( SFLS_Paddle::to_decimal( (string) $tax, $currency ) );
			$order->add_item( $fee );
		}
		$order->set_total( SFLS_Paddle::to_decimal( (string) ( $net + $tax ), $currency ) );
		$order->update_meta_data( '_sfls_paddle_kind', 'upgrade' );
		$order->update_meta_data( '_sfls_paddle_subscription_id', (string) $lic['paddle_subscription_id'] );
		$order->update_meta_data( '_sfls_paddle_paid_txn', 'subscription_update' );
		if ( ! $trialing && $net > 0 ) {
			$order->update_meta_data( '_sfls_paddle_upgrade_pending', 'yes' );
		}
		$order->save();
		$order->add_order_note(
			$trialing
				/* translators: %s: subscription ID */
				? sprintf( __( 'Paddle subscription %s moved to the new plan during its trial. Nothing is charged until the trial ends.', 'subtleforms-license-server' ), (string) $lic['paddle_subscription_id'] )
				: sprintf(
					/* translators: 1: subscription ID, 2: amount */
					__( 'Paddle subscription %1$s updated with a prorated charge of %2$s on the saved payment method. The transaction ID is added when Paddle confirms it.', 'subtleforms-license-server' ),
					(string) $lic['paddle_subscription_id'],
					self::money( $net + $tax, $currency )
				)
		);
		$order->payment_complete();
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}

	/**
	 * transaction.completed for a subscription change: extend nothing new,
	 * and put the transaction on the upgrade order.
	 *
	 * @param array $txn Transaction.
	 * @return array{action:string,note:string,license:?array}
	 */
	private static function upgrade_charged( array $txn ): array {
		$sub_id = (string) ( $txn['subscription_id'] ?? '' );
		$order  = null;
		foreach ( self::orders_by_meta( '_sfls_paddle_subscription_id', $sub_id, 5 ) as $candidate ) {
			if ( 'yes' === $candidate->get_meta( '_sfls_paddle_upgrade_pending' ) ) {
				$order = $candidate;
				break;
			}
		}
		$lic = SFLS_Licenses::find_by_subscription( $sub_id );
		if ( $order && $lic ) {
			// The upgrade order already told the customer; no second payment email.
			SFLS_Reminders::add_marker( $lic, 'paid|' . (string) ( $txn['id'] ?? '' ) );
		}
		$result = SFLS_Billing::transaction_completed( $txn );
		if ( $order ) {
			$order->set_transaction_id( (string) ( $txn['id'] ?? '' ) );
			$order->update_meta_data( '_sfls_paddle_paid_txn', (string) ( $txn['id'] ?? '' ) );
			$order->delete_meta_data( '_sfls_paddle_upgrade_pending' );
			$order->save();
			$totals = (array) ( $txn['details']['totals'] ?? array() );
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: transaction ID */
					__( 'Paddle confirmed the prorated upgrade charge of %1$s (transaction %2$s).', 'subtleforms-license-server' ),
					self::money( (int) ( $totals['total'] ?? 0 ), strtoupper( (string) ( $totals['currency_code'] ?? $order->get_currency() ) ) ),
					(string) ( $txn['id'] ?? '' )
				)
			);
			/* translators: %d: order ID */
			$result['note'] = trim( $result['note'] . ' ' . sprintf( 'Upgrade order #%d.', $order->get_id() ) );
		}
		return $result;
	}

	// ── Refunds ─────────────────────────────────────────────────────────

	/**
	 * Refund (part of) an order's Paddle payment.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount (order currency).
	 * @param string   $reason Reason.
	 * @return array|WP_Error Adjustment.
	 */
	public static function refund( WC_Order $order, float $amount, string $reason ) {
		self::$last_adjustment = '';
		$txn_id                = (string) $order->get_transaction_id();
		if ( '' === $txn_id || ! str_starts_with( $txn_id, 'txn_' ) ) {
			return new WP_Error( 'sfls_refund', __( 'This order has no Paddle payment to refund yet. Wait for Paddle to confirm the payment, then try again.', 'subtleforms-license-server' ) );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'sfls_refund', __( 'Enter an amount to refund.', 'subtleforms-license-server' ) );
		}
		$txn = SFLS_Paddle::get_transaction( $txn_id );
		if ( is_wp_error( $txn ) ) {
			return $txn;
		}
		if ( 'completed' !== ( $txn['status'] ?? '' ) ) {
			return new WP_Error( 'sfls_refund', __( 'Paddle can refund this payment once it is completed.', 'subtleforms-license-server' ) );
		}
		$currency = strtoupper( (string) ( $txn['details']['totals']['currency_code'] ?? $order->get_currency() ) );
		$grand    = (int) ( $txn['details']['totals']['grand_total'] ?? $txn['details']['totals']['total'] ?? 0 );
		$minor    = (int) SFLS_Paddle::to_minor( (string) $amount, $currency );
		$body     = array(
			'action'         => 'refund',
			'transaction_id' => $txn_id,
			/* translators: %s: order number */
			'reason'         => '' !== trim( $reason ) ? trim( $reason ) : sprintf( __( 'Refunded from WooCommerce order #%s', 'subtleforms-license-server' ), $order->get_order_number() ),
		);
		if ( $minor >= $grand - 1 ) {
			$body['type'] = 'full';
		} else {
			$body['type']  = 'partial';
			$body['items'] = array();
			$left          = $minor;
			foreach ( (array) ( $txn['details']['line_items'] ?? array() ) as $line ) {
				$available = (int) ( $line['totals']['total'] ?? 0 );
				if ( $left <= 0 || $available <= 0 || empty( $line['id'] ) ) {
					continue;
				}
				$take = min( $left, $available );
				$row  = array(
					'item_id' => (string) $line['id'],
					'type'    => $take >= $available ? 'full' : 'partial',
				);
				if ( 'partial' === $row['type'] ) {
					$row['amount'] = (string) $take;
				}
				$body['items'][] = $row;
				$left           -= $take;
			}
			if ( ! $body['items'] || $left > 0 ) {
				return new WP_Error( 'sfls_refund', __( 'That amount is more than Paddle can refund for this payment.', 'subtleforms-license-server' ) );
			}
		}
		$adj = SFLS_Paddle::create_adjustment( $body );
		if ( is_wp_error( $adj ) ) {
			return $adj;
		}
		$id                    = (string) ( $adj['id'] ?? '' );
		self::$last_adjustment = $id;
		$map                   = self::adjustments_of( $order );
		$map[ $id ]            = array(
			'source' => 'woo',
			'status' => (string) ( $adj['status'] ?? '' ),
			'amount' => $minor,
		);
		$order->update_meta_data( '_sfls_paddle_adjustments', $map );
		$order->save_meta_data();
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: adjustment ID, 3: status */
				__( 'Refund of %1$s sent to Paddle (%2$s, %3$s).', 'subtleforms-license-server' ),
				self::money( $minor, $currency ),
				$id,
				'pending_approval' === ( $adj['status'] ?? '' ) ? __( 'waiting for Paddle to approve it', 'subtleforms-license-server' ) : (string) ( $adj['status'] ?? '' )
			)
		);
		return $adj;
	}

	/**
	 * Remember which Paddle adjustment a WooCommerce refund created.
	 *
	 * @param int $order_id  Order.
	 * @param int $refund_id Refund.
	 */
	public static function remember_adjustment( $order_id, $refund_id ): void {
		if ( '' === self::$last_adjustment ) {
			return;
		}
		$id                    = self::$last_adjustment;
		self::$last_adjustment = '';
		$refund                = wc_get_order( (int) $refund_id );
		$order                 = wc_get_order( (int) $order_id );
		if ( $refund instanceof WC_Order_Refund ) {
			$refund->update_meta_data( '_sfls_paddle_adjustment_id', $id );
			$refund->save_meta_data();
		}
		if ( $order instanceof WC_Order ) {
			$map = self::adjustments_of( $order );
			if ( isset( $map[ $id ] ) ) {
				$map[ $id ]['refund_id'] = (int) $refund_id;
				$order->update_meta_data( '_sfls_paddle_adjustments', $map );
				$order->save_meta_data();
			}
		}
	}

	/**
	 * adjustment.created / adjustment.updated: mirror refunds and chargebacks
	 * made in Paddle, and follow the ones made from WooCommerce.
	 *
	 * @param array $adj Adjustment.
	 * @return array{action:string,note:string,license:?array}
	 */
	public static function adjustment( array $adj ): array {
		$id     = (string) ( $adj['id'] ?? '' );
		$action = (string) ( $adj['action'] ?? '' );
		$status = (string) ( $adj['status'] ?? '' );
		$order  = self::order_by_transaction( (string) ( $adj['transaction_id'] ?? '' ) );
		if ( '' === $id || ! $order ) {
			return self::result( 'ignored', 'No order for this adjustment.' );
		}
		if ( ! in_array( $action, array( 'refund', 'chargeback', 'chargeback_warning', 'chargeback_reverse' ), true ) ) {
			return self::result( 'ignored', 'Adjustment type not used.' );
		}
		if ( ! self::lock( $order->get_id() ) ) {
			throw new RuntimeException( esc_html( 'Order #' . $order->get_id() . ' is busy.' ) );
		}
		try {
			$order    = wc_get_order( $order->get_id() );
			$map      = self::adjustments_of( $order );
			$entry    = (array) ( $map[ $id ] ?? array() );
			$currency = strtoupper( (string) ( $adj['totals']['currency_code'] ?? $adj['currency_code'] ?? $order->get_currency() ) );
			$minor    = (int) ( $adj['totals']['total'] ?? 0 );

			if ( in_array( $action, array( 'chargeback_warning', 'chargeback_reverse' ), true ) ) {
				if ( 'chargeback_reverse' === $action ) {
					// The money came back: remove the refunds the chargebacks created, and restore the licenses.
					$restored = self::restore_after_chargeback( $order );
					if ( self::note_once( $order, $action . '|' . $id ) ) {
						$order->add_order_note(
							$restored
								/* translators: 1: adjustment ID, 2: number of licenses */
								? sprintf( _n( 'Paddle reversed a chargeback (%1$s). %2$d license is active again.', 'Paddle reversed a chargeback (%1$s). %2$d licenses are active again.', $restored, 'subtleforms-license-server' ), $id, $restored )
								/* translators: %s: adjustment ID */
								: sprintf( __( 'Paddle reversed a chargeback (%s). No suspended license was found to restore.', 'subtleforms-license-server' ), $id )
						);
					}
					return self::result( $restored ? 'restored' : 'noted', $action );
				}
				if ( self::note_once( $order, $action . '|' . $id ) ) {
					/* translators: %s: adjustment ID */
					$order->add_order_note( sprintf( __( 'Paddle reported a chargeback warning (%s). Nothing was refunded yet.', 'subtleforms-license-server' ), $id ) );
				}
				return self::result( 'noted', $action );
			}

			// Refunds made from WooCommerce: already a WooCommerce refund.
			if ( 'woo' === ( $entry['source'] ?? '' ) ) {
				if ( ( $entry['status'] ?? '' ) === $status ) {
					return self::result( 'ignored', 'Known refund.' );
				}
				$map[ $id ]['status'] = $status;
				$order->update_meta_data( '_sfls_paddle_adjustments', $map );
				$order->save_meta_data();
				if ( 'rejected' === $status ) {
					self::undo_refund( $order, (int) ( $entry['refund_id'] ?? 0 ), $id );
					return self::result( 'rejected', 'Refund rejected by Paddle.' );
				}
				if ( 'approved' === $status ) {
					/* translators: %s: adjustment ID */
					$order->add_order_note( sprintf( __( 'Paddle approved refund %s.', 'subtleforms-license-server' ), $id ) );
				}
				return self::result( 'updated', 'Refund ' . $status . '.' );
			}

			if ( ! empty( $entry['refund_id'] ) ) {
				return self::result( 'ignored', 'Already mirrored.' );
			}
			if ( 'approved' !== $status ) {
				$map[ $id ] = array(
					'source' => 'paddle',
					'status' => $status,
				);
				$order->update_meta_data( '_sfls_paddle_adjustments', $map );
				$order->save_meta_data();
				if ( 'pending_approval' === $status && self::note_once( $order, 'pending|' . $id ) ) {
					/* translators: 1: amount, 2: adjustment ID */
					$order->add_order_note( sprintf( __( 'A refund of %1$s was requested in Paddle (%2$s) and is waiting for approval.', 'subtleforms-license-server' ), self::money( $minor, $currency ), $id ) );
				}
				return self::result( 'waiting', 'Adjustment ' . $status . '.' );
			}

			$amount = min( (float) SFLS_Paddle::to_decimal( (string) $minor, $currency ), (float) $order->get_remaining_refund_amount() );
			if ( $amount <= 0 ) {
				return self::result( 'ignored', 'Nothing left to refund.' );
			}
			$reason = 'chargeback' === $action
				/* translators: %s: adjustment ID */
				? sprintf( __( 'Chargeback in Paddle (%s)', 'subtleforms-license-server' ), $id )
				/* translators: 1: adjustment ID, 2: reason */
				: trim( sprintf( __( 'Refunded in Paddle (%1$s) %2$s', 'subtleforms-license-server' ), $id, (string) ( $adj['reason'] ?? '' ) ) );
			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => wc_format_decimal( $amount ),
					'reason'         => $reason,
					'refund_payment' => false,
					'restock_items'  => false,
				)
			);
			if ( is_wp_error( $refund ) ) {
				throw new RuntimeException( esc_html( 'Refund for ' . $id . ' could not be recorded: ' . $refund->get_error_message() ) );
			}
			$refund->update_meta_data( '_sfls_paddle_adjustment_id', $id );
			$refund->save_meta_data();
			$order      = wc_get_order( $order->get_id() );
			$map        = self::adjustments_of( $order );
			$map[ $id ] = array(
				'source'    => 'paddle',
				'action'    => $action,
				'status'    => $status,
				'refund_id' => $refund->get_id(),
			);
			$order->update_meta_data( '_sfls_paddle_adjustments', $map );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: reason */
					__( 'Recorded a refund of %1$s: %2$s.', 'subtleforms-license-server' ),
					self::money( $minor, $currency ),
					$reason
				)
			);
			return self::result( 'refunded', 'Mirrored as refund #' . $refund->get_id() . '.' );
		} finally {
			self::unlock( $order->get_id() );
		}
	}

	/**
	 * Whether a stored adjustment entry mirrors a chargeback. Entries written
	 * before the action was stored have none, so a Paddle entry without one is
	 * recognised by the reason of the refund it created.
	 *
	 * @param string $adj_id Adjustment ID.
	 * @param array  $entry  Stored entry.
	 */
	private static function is_chargeback_entry( string $adj_id, array $entry ): bool {
		if ( isset( $entry['action'] ) && '' !== $entry['action'] ) {
			return 'chargeback' === $entry['action'];
		}
		if ( 'paddle' !== ( $entry['source'] ?? '' ) || empty( $entry['refund_id'] ) ) {
			return false;
		}
		$refund = wc_get_order( (int) $entry['refund_id'] );
		if ( ! $refund instanceof WC_Order_Refund ) {
			return false;
		}
		/* translators: %s: adjustment ID */
		return sprintf( __( 'Chargeback in Paddle (%s)', 'subtleforms-license-server' ), $adj_id ) === (string) $refund->get_reason();
	}

	/**
	 * A chargeback was reversed: remove the refunds that mirrored chargebacks
	 * and make the suspended or refunded licenses of the order active again.
	 *
	 * @param WC_Order $order Order.
	 * @return int Licenses restored.
	 */
	private static function restore_after_chargeback( WC_Order $order ): int {
		$map = self::adjustments_of( $order );
		foreach ( $map as $adj_id => $entry ) {
			if ( self::is_chargeback_entry( (string) $adj_id, (array) $entry ) && ! empty( $entry['refund_id'] ) ) {
				$refund = wc_get_order( (int) $entry['refund_id'] );
				if ( $refund instanceof WC_Order_Refund ) {
					$refund->delete( true );
				}
				unset( $map[ $adj_id ] );
			}
		}
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_sfls_paddle_adjustments', $map );
		$order->save_meta_data();
		if ( $order->has_status( 'refunded' ) ) {
			$order->update_status( 'completed' );
		}
		$restored = 0;
		foreach ( SFLS_Woo::order_licenses( $order->get_id() ) as $lic ) {
			if ( (int) $lic['order_id'] === $order->get_id() && in_array( $lic['status'], array( 'suspended', 'refunded' ), true ) ) {
				SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'active' ) );
				++$restored;
			}
		}
		return $restored;
	}

	/**
	 * Paddle rejected a refund made from WooCommerce: remove the WooCommerce
	 * refund again.
	 *
	 * @param WC_Order $order     Order.
	 * @param int      $refund_id Refund.
	 * @param string   $adj_id    Adjustment.
	 */
	private static function undo_refund( WC_Order $order, int $refund_id, string $adj_id ): void {
		$refund = $refund_id ? wc_get_order( $refund_id ) : null;
		if ( $refund instanceof WC_Order_Refund ) {
			$refund->delete( true );
		}
		$order = wc_get_order( $order->get_id() );
		if ( $order->has_status( 'refunded' ) ) {
			$order->update_status( 'completed' );
			foreach ( SFLS_Woo::order_licenses( $order->get_id() ) as $lic ) {
				if ( (int) $lic['order_id'] === $order->get_id() && 'suspended' === $lic['status'] ) {
					SFLS_Licenses::update( (int) $lic['id'], array( 'status' => 'active' ) );
				}
			}
		}
		/* translators: %s: adjustment ID */
		$order->add_order_note( sprintf( __( 'Paddle rejected refund %s, so the WooCommerce refund was removed. No money was returned.', 'subtleforms-license-server' ), $adj_id ) );
	}

	/**
	 * A fully refunded first order ends its Paddle subscription at once, so
	 * the customer is not charged again next year.
	 *
	 * @param int $order_id Order.
	 */
	public static function cancel_on_full_refund( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || SFLS_Woo_Paddle::GATEWAY !== $order->get_payment_method() || 'yes' === $order->get_meta( '_sfls_paddle_renewal' ) || ! SFLS_Paddle::has_api_key() ) {
			return;
		}
		foreach ( SFLS_Woo::order_licenses( $order->get_id() ) as $lic ) {
			if ( (int) $lic['order_id'] !== $order->get_id() || ! SFLS_Licenses::is_paddle( $lic ) || 'canceled' === $lic['billing_status'] ) {
				continue;
			}
			$sub = SFLS_Paddle::cancel_subscription( (string) $lic['paddle_subscription_id'], true );
			if ( is_wp_error( $sub ) ) {
				/* translators: 1: subscription ID, 2: error */
				$order->add_order_note( sprintf( __( 'Could not cancel Paddle subscription %1$s after the refund: %2$s. Cancel it in Paddle.', 'subtleforms-license-server' ), (string) $lic['paddle_subscription_id'], $sub->get_error_message() ) );
				continue;
			}
			SFLS_Billing::sync_subscription( $sub );
			/* translators: %s: subscription ID */
			$order->add_order_note( sprintf( __( 'Paddle subscription %s canceled because the order was fully refunded.', 'subtleforms-license-server' ), (string) $lic['paddle_subscription_id'] ) );
		}
	}
}
