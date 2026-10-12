<?php
/**
 * Paddle subscriptions and licenses.
 *
 * One Paddle subscription is one license. The license follows the
 * subscription:
 *
 *   trialing  license active, 1 site, trial_ends_at set,
 *             expires_at = trial end + SFLS_Paddle::BILLING_GRACE_DAYS
 *   active    the plan's site limit, expires_at = current billing period end + grace
 *   past_due  unchanged expiry: stays valid until the paid period (plus grace) ends
 *   paused    same as past_due
 *   canceled  a paid license keeps its expiry; a trial that never paid ends now
 *
 * `renews_at` is the next charge (none while set to cancel), `cancels_at` the
 * date a scheduled cancellation takes effect. Changing plans keeps the key.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription sync and the customer's billing actions.
 */
final class SFLS_Billing {

	/**
	 * Subscriptions refused as a second trial (never turned into a license).
	 */
	const REJECTED_OPTION = 'sfls_paddle_rejected';

	/**
	 * GMT "Y-m-d H:i:s" from an RFC 3339 date, or null.
	 *
	 * @param mixed $value Date.
	 */
	public static function gmt( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * GMT datetime plus the billing grace days.
	 *
	 * @param string|null $gmt GMT datetime.
	 */
	private static function plus_grace( ?string $gmt ): ?string {
		if ( null === $gmt ) {
			return null;
		}
		$days = (int) apply_filters( 'sfls_paddle_grace_days', SFLS_Paddle::BILLING_GRACE_DAYS );
		return gmdate( 'Y-m-d H:i:s', strtotime( $gmt . ' UTC' ) + max( 0, $days ) * DAY_IN_SECONDS );
	}

	/**
	 * The recurring item of a subscription or transaction.
	 *
	 * @param array $entity Subscription or transaction.
	 */
	private static function main_item( array $entity ): array {
		foreach ( (array) ( $entity['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) && ( ! isset( $item['recurring'] ) || $item['recurring'] ) ) {
				return $item;
			}
		}
		return array();
	}

	/**
	 * Plan of a subscription: the mapped price, else custom data, else the
	 * license's current plan, else pro.
	 *
	 * @param array      $sub Subscription.
	 * @param array|null $lic Existing license.
	 */
	public static function plan_of( array $sub, ?array $lic = null ): string {
		$item = self::main_item( $sub );
		$plan = SFLS_Paddle::plan_for_price( (string) ( $item['price']['id'] ?? $item['price_id'] ?? '' ) );
		if ( '' === $plan ) {
			$custom = sanitize_key( (string) ( $sub['custom_data']['sfls_plan'] ?? '' ) );
			$plan   = SFLS_Plans::get( $custom ) ? $custom : '';
		}
		if ( '' === $plan && $lic ) {
			$plan = (string) $lic['plan'];
		}
		return '' !== $plan ? $plan : 'pro';
	}

	/**
	 * Customer email and name for a subscription.
	 *
	 * @param array $sub Subscription.
	 * @return array{0:string,1:string}
	 */
	private static function customer( array $sub ): array {
		$email = '';
		$name  = '';
		if ( ! empty( $sub['customer_id'] ) && SFLS_Paddle::has_api_key() ) {
			$customer = SFLS_Paddle::get_customer( (string) $sub['customer_id'] );
			if ( ! is_wp_error( $customer ) ) {
				$email = sanitize_email( (string) ( $customer['email'] ?? '' ) );
				$name  = sanitize_text_field( (string) ( $customer['name'] ?? '' ) );
			}
		}
		if ( '' === $email ) {
			$email = sanitize_email( (string) ( $sub['custom_data']['sfls_email'] ?? '' ) );
		}
		return array( $email, $name );
	}

	/**
	 * Find or create the WordPress account of a customer. New accounts get
	 * the "set your password" email.
	 *
	 * @param string $email Email.
	 * @param string $name  Name from Paddle.
	 * @return array{0:int,1:bool} User ID and whether it was created.
	 */
	public static function customer_user( string $email, string $name = '' ): array {
		if ( ! is_email( $email ) ) {
			return array( 0, false );
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return array( (int) $user->ID, false );
		}
		$base  = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base  = '' !== $base ? $base : 'customer';
		$login = $base;
		for ( $i = 2; username_exists( $login ); $i++ ) {
			$login = $base . $i;
		}
		$parts = preg_split( '/\s+/', trim( $name ), 2 );
		$id    = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24 ),
				'first_name'   => (string) ( $parts[0] ?? '' ),
				'last_name'    => (string) ( $parts[1] ?? '' ),
				'display_name' => '' !== trim( $name ) ? trim( $name ) : $login,
				'role'         => get_role( 'customer' ) ? 'customer' : get_option( 'default_role', 'subscriber' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return array( 0, false );
		}
		wp_new_user_notification( (int) $id, null, 'user' );
		return array( (int) $id, true );
	}

	/**
	 * License columns that describe a subscription's current state.
	 *
	 * @param array      $sub Subscription.
	 * @param array|null $lic Existing license.
	 */
	public static function fields_for( array $sub, ?array $lic = null ): array {
		$status     = (string) ( $sub['status'] ?? '' );
		$item       = self::main_item( $sub );
		$plan       = self::plan_of( $sub, $lic );
		$period_end = self::gmt( $sub['current_billing_period']['ends_at'] ?? null );
		$next       = self::gmt( $sub['next_billed_at'] ?? null );
		$scheduled  = is_array( $sub['scheduled_change'] ?? null ) ? $sub['scheduled_change'] : array();
		$cancels_at = 'cancel' === ( $scheduled['action'] ?? '' ) ? self::gmt( $scheduled['effective_at'] ?? null ) : null;
		$trial_end  = self::gmt( $item['trial_dates']['ends_at'] ?? null );

		if ( 'trialing' === $status ) {
			$trial = 1;
		} elseif ( 'active' === $status ) {
			$trial = 0;
		} else {
			$trial = $lic ? (int) $lic['trial'] : 0;
		}
		if ( $trial && null === $trial_end ) {
			$trial_end = $lic && ! empty( $lic['trial_ends_at'] ) ? (string) $lic['trial_ends_at'] : ( $next ?? $period_end );
		}

		$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		$unit     = (string) ( $item['price']['unit_price']['amount'] ?? '' );
		$fields   = array(
			'billing'                => 'paddle',
			'billing_status'         => sanitize_key( $status ),
			'plan'                   => $plan,
			'sites_allowed'          => $trial ? 1 : (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ),
			'trial'                  => $trial,
			'trial_ends_at'          => $trial ? $trial_end : ( $lic['trial_ends_at'] ?? null ),
			'renews_at'              => in_array( $status, array( 'active', 'trialing', 'past_due' ), true ) && null === $cancels_at ? $next : null,
			'cancels_at'             => 'canceled' === $status ? null : $cancels_at,
			'billing_amount'         => '' !== $unit ? (string) ( (int) $unit * $quantity ) : (string) ( $lic['billing_amount'] ?? '' ),
			'billing_currency'       => strtoupper( (string) ( $item['price']['unit_price']['currency_code'] ?? $sub['currency_code'] ?? ( $lic['billing_currency'] ?? '' ) ) ),
			'paddle_customer_id'     => (string) ( $sub['customer_id'] ?? ( $lic['paddle_customer_id'] ?? '' ) ),
			'paddle_subscription_id' => (string) ( $sub['id'] ?? '' ),
			'paddle_price_id'        => (string) ( $item['price']['id'] ?? ( $lic['paddle_price_id'] ?? '' ) ),
		);

		switch ( $status ) {
			case 'trialing':
				$fields['expires_at'] = self::plus_grace( $trial_end );
				break;
			case 'active':
				$fields['expires_at'] = self::plus_grace( $period_end ?? $next );
				break;
			case 'canceled':
				if ( $trial ) {
					// Canceled during the trial: nothing was paid, so it ends now.
					$fields['expires_at'] = self::gmt( $sub['canceled_at'] ?? null ) ?? current_time( 'mysql', true );
				} elseif ( $lic && ! empty( $lic['expires_at'] ) ) {
					$fields['expires_at'] = $lic['expires_at']; // Paid until the end of the period.
				} else {
					$fields['expires_at'] = self::plus_grace( $period_end ) ?? current_time( 'mysql', true );
				}
				break;
			default: // past_due, paused: keep the paid period.
				$fields['expires_at'] = $lic && ! empty( $lic['expires_at'] ) ? $lic['expires_at'] : self::plus_grace( $period_end ?? current_time( 'mysql', true ) );
		}
		return $fields;
	}

	/**
	 * Apply a subscription to its license, creating the license for a new
	 * subscription.
	 *
	 * @param array $sub Subscription entity.
	 * @param array $ctx event_type, occurred_at (GMT), transaction_id, from_webhook (bool).
	 * @return array{action:string,note:string,license:?array}
	 */
	public static function sync_subscription( array $sub, array $ctx = array() ): array {
		$sub_id      = (string) ( $sub['id'] ?? '' );
		$status      = (string) ( $sub['status'] ?? '' );
		$occurred    = (string) ( $ctx['occurred_at'] ?? '' );
		$from_hook   = ! empty( $ctx['from_webhook'] );
		$transaction = (string) ( $ctx['transaction_id'] ?? ( $sub['transaction_id'] ?? '' ) );
		$result      = array(
			'action'  => 'ignored',
			'note'    => '',
			'license' => null,
		);
		if ( '' === $sub_id ) {
			$result['note'] = 'No subscription ID.';
			return $result;
		}

		$lic = SFLS_Licenses::find_by_subscription( $sub_id );

		if ( ! $lic ) {
			return self::create_from_subscription( $sub, $transaction, $occurred );
		}

		if ( $from_hook && '' !== $occurred && ! empty( $lic['billing_synced_at'] ) && strtotime( $occurred . ' UTC' ) < strtotime( $lic['billing_synced_at'] . ' UTC' ) ) {
			$result['action']  = 'stale';
			$result['note']    = 'Older than the last applied event.';
			$result['license'] = $lic;
			return $result;
		}

		$fields = self::fields_for( $sub, $lic ); // Never touches `status`, so an admin suspension stays.
		if ( $from_hook ) {
			$fields['billing_synced_at'] = '' !== $occurred ? $occurred : current_time( 'mysql', true );
		}
		if ( '' !== $transaction && '' === (string) $lic['paddle_transaction_id'] ) {
			$fields['paddle_transaction_id'] = $transaction;
		}
		$notes = array();
		if ( $fields['plan'] !== $lic['plan'] ) {
			$notes[] = sprintf(
				/* translators: 1: old plan, 2: new plan, 3: date */
				__( 'Plan changed from %1$s to %2$s through Paddle on %3$s, same key.', 'subtleforms-license-server' ),
				SFLS_Plans::label( (string) $lic['plan'] ),
				SFLS_Plans::label( $fields['plan'] ),
				wp_date( get_option( 'date_format' ) )
			);
			$fields['notes'] = trim( (string) $lic['notes'] . "\n" . $notes[0] );
		}
		SFLS_Licenses::update( (int) $lic['id'], $fields );
		$after = SFLS_Licenses::find( (int) $lic['id'] );

		if ( $fields['plan'] !== $lic['plan'] ) {
			do_action( 'sfls_license_upgraded', (int) $lic['id'], $lic['plan'], $fields['plan'] );
		}

		// Customer emails on transitions.
		if ( $after ) {
			if ( empty( $lic['cancels_at'] ) && ! empty( $after['cancels_at'] ) ) {
				SFLS_Billing_Emails::send_once( $after, 'canceled', 'canceled|' . $after['cancels_at'] );
			} elseif ( 'canceled' === $status && 'canceled' !== $lic['billing_status'] && empty( $lic['cancels_at'] ) ) {
				SFLS_Billing_Emails::send_once( $after, 'canceled', 'canceled|' . $sub_id );
			}
			if ( 'past_due' === $status && 'past_due' !== $lic['billing_status'] ) {
				SFLS_Billing_Emails::send_once( $after, 'payment_failed', 'failed|' . (string) self::gmt( $sub['current_billing_period']['starts_at'] ?? null ) );
			}
		}

		$result['action']  = 'updated';
		$result['note']    = implode( ' ', $notes );
		$result['license'] = $after;
		return $result;
	}

	/**
	 * First event of a subscription: check the trial rules and create the
	 * license (or cancel a second trial before any charge).
	 *
	 * @param array  $sub         Subscription.
	 * @param string $transaction Checkout transaction ID.
	 * @param string $occurred    Event time (GMT).
	 * @return array{action:string,note:string,license:?array}
	 */
	private static function create_from_subscription( array $sub, string $transaction, string $occurred ): array {
		$sub_id = (string) $sub['id'];
		$status = (string) ( $sub['status'] ?? '' );
		$result = array(
			'action'  => 'ignored',
			'note'    => '',
			'license' => null,
		);

		if ( in_array( $sub_id, (array) get_option( self::REJECTED_OPTION, array() ), true ) ) {
			$result['note'] = 'Subscription was refused as a second trial.';
			return $result;
		}
		if ( ! in_array( $status, array( 'trialing', 'active', 'past_due' ), true ) ) {
			$result['note'] = sprintf( 'No license for a %s subscription.', $status );
			return $result;
		}

		[ $email, $name ] = self::customer( $sub );

		if ( 'trialing' === $status ) {
			$check = SFLS_Trials::check( $email );
			if ( ! $check['eligible'] ) {
				return self::reject_trial( $sub, $email, $name, $check );
			}
		}

		[ $user_id, $created ] = self::customer_user( $email, $name );
		$fields                = self::fields_for( $sub, null );
		$token                 = (string) ( $sub['custom_data']['sfls_token'] ?? '' );
		$lic                   = SFLS_Licenses::create(
			array_merge(
				$fields,
				array(
					'customer_email'        => $email,
					'user_id'               => $user_id,
					'months'                => 12,
					'paddle_transaction_id' => $transaction,
					'checkout_token_hash'   => '' !== $token ? hash( 'sha256', $token ) : '',
					'trial_started_at'      => 'trialing' === $status ? current_time( 'mysql', true ) : null,
					'billing_synced_at'     => '' !== $occurred ? $occurred : current_time( 'mysql', true ),
					'notes'                 => sprintf(
						/* translators: %s: Paddle subscription ID */
						__( 'Paddle subscription %s.', 'subtleforms-license-server' ),
						$sub_id
					),
				)
			)
		);
		$lic = SFLS_Licenses::find( (int) $lic['id'] ) ?? $lic;

		if ( 'trialing' === $status ) {
			SFLS_Trials::record_start( (int) $lic['id'], $email );
		}
		SFLS_Billing_Emails::send_once( $lic, 'welcome', 'welcome', array( 'new_account' => $created ) );
		do_action( 'sfls_paddle_license_created', $lic, $sub );

		$result['action']  = 'created';
		$result['license'] = $lic;
		$result['note']    = 'trialing' === $status ? 'Trial license created.' : 'License created.';
		return $result;
	}

	/**
	 * Backstop for a second trial that got past the checkout check: cancel
	 * the subscription at once (nothing has been charged during a trial) and
	 * tell the customer.
	 *
	 * @param array  $sub   Subscription.
	 * @param string $email Customer email.
	 * @param string $name  Customer name.
	 * @param array  $check Eligibility result.
	 * @return array{action:string,note:string,license:?array}
	 */
	private static function reject_trial( array $sub, string $email, string $name, array $check ): array {
		$sub_id   = (string) $sub['id'];
		$rejected = (array) get_option( self::REJECTED_OPTION, array() );
		$rejected = array_slice( array_values( array_unique( array_merge( $rejected, array( $sub_id ) ) ) ), -500 );
		update_option( self::REJECTED_OPTION, $rejected, false );

		$cancel = SFLS_Paddle::cancel_subscription( $sub_id, true );
		$note   = is_wp_error( $cancel )
			? sprintf( 'Second trial (%s). Cancelling it failed: %s', $check['reason'], $cancel->get_error_message() )
			: sprintf( 'Second trial (%s). Subscription canceled before any charge.', $check['reason'] );

		if ( is_email( $email ) ) {
			SFLS_Billing_Emails::send_to( $email, $name, 'trial_rejected', array( 'reason' => $check['message'] ) );
		}
		do_action( 'sfls_paddle_trial_rejected', $sub, $email, $check, $cancel );

		return array(
			'action'  => is_wp_error( $cancel ) ? 'failed' : 'rejected',
			'note'    => $note,
			'license' => null,
		);
	}

	/**
	 * A completed transaction: link the checkout to its license, and for a
	 * real payment extend the license to the end of the paid period and end
	 * the trial.
	 *
	 * @param array $txn Transaction entity.
	 * @return array{action:string,note:string,license:?array}
	 */
	public static function transaction_completed( array $txn ): array {
		$sub_id = (string) ( $txn['subscription_id'] ?? '' );
		$result = array(
			'action'  => 'ignored',
			'note'    => '',
			'license' => null,
		);
		if ( '' === $sub_id ) {
			$result['note'] = 'Not a subscription transaction.';
			return $result;
		}
		$lic = SFLS_Licenses::find_by_subscription( $sub_id );
		if ( ! $lic ) {
			// The subscription event has not arrived yet: fetch it.
			$sub = SFLS_Paddle::get_subscription( $sub_id );
			if ( is_wp_error( $sub ) ) {
				throw new RuntimeException( esc_html( 'Subscription ' . $sub_id . ' could not be fetched: ' . $sub->get_error_message() ) );
			}
			$created = self::sync_subscription( $sub, array( 'transaction_id' => (string) ( $txn['id'] ?? '' ) ) );
			$lic     = $created['license'];
			if ( ! $lic ) {
				return $created;
			}
		}

		$fields = array();
		if ( 'web' === ( $txn['origin'] ?? '' ) && '' === (string) $lic['paddle_transaction_id'] ) {
			$fields['paddle_transaction_id'] = (string) $txn['id'];
		}

		$total = (int) ( $txn['details']['totals']['grand_total'] ?? $txn['details']['totals']['total'] ?? 0 );
		if ( $total > 0 ) {
			$period_end = self::gmt( $txn['billing_period']['ends_at'] ?? null );
			$expires    = self::plus_grace( $period_end );
			if ( null !== $expires && ( empty( $lic['expires_at'] ) || strtotime( $expires . ' UTC' ) > strtotime( $lic['expires_at'] . ' UTC' ) ) ) {
				$fields['expires_at'] = $expires;
			}
			$plan                    = self::plan_of( $txn, $lic );
			$fields['trial']         = 0;
			$fields['sites_allowed'] = (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 );
			if ( in_array( $lic['billing_status'], array( 'trialing', 'past_due', '' ), true ) ) {
				$fields['billing_status'] = 'active';
			}
			if ( empty( $lic['cancels_at'] ) && null !== $period_end && 'subscription_update' !== ( $txn['origin'] ?? '' ) ) {
				$fields['renews_at'] = $period_end;
			}
		}
		if ( $fields ) {
			SFLS_Licenses::update( (int) $lic['id'], $fields );
		}
		$after = SFLS_Licenses::find( (int) $lic['id'] );
		if ( $total > 0 && $after ) {
			SFLS_Billing_Emails::send_once(
				$after,
				'payment_succeeded',
				'paid|' . (string) $txn['id'],
				array(
					'amount'      => SFLS_Paddle::format_amount( (string) $total, (string) ( $txn['details']['totals']['currency_code'] ?? $txn['currency_code'] ?? $after['billing_currency'] ) ),
					'was_trial'   => ! empty( $lic['trial'] ),
					'is_upgrade'  => 'subscription_update' === ( $txn['origin'] ?? '' ),
				)
			);
		}
		$result['action']  = $fields ? 'updated' : 'ignored';
		$result['note']    = $total > 0 ? 'Payment applied.' : 'Linked checkout.';
		$result['license'] = $after;
		return $result;
	}

	/**
	 * A failed renewal charge: tell the customer how to update the card.
	 * Failed attempts on the checkout itself are ignored (the customer is
	 * still there).
	 *
	 * @param array $txn Transaction entity.
	 * @return array{action:string,note:string,license:?array}
	 */
	public static function payment_failed( array $txn ): array {
		$lic = SFLS_Licenses::find_by_subscription( (string) ( $txn['subscription_id'] ?? '' ) );
		if ( ! $lic || 'web' === ( $txn['origin'] ?? '' ) ) {
			return array(
				'action'  => 'ignored',
				'note'    => 'No license, or a failed attempt during checkout.',
				'license' => $lic,
			);
		}
		$sent = SFLS_Billing_Emails::send_once( $lic, 'payment_failed', 'failed|' . (string) self::gmt( $txn['billing_period']['starts_at'] ?? null ) );
		return array(
			'action'  => $sent ? 'emailed' : 'ignored',
			'note'    => $sent ? 'Payment failed email sent.' : 'Payment failed email already sent for this period.',
			'license' => $lic,
		);
	}

	// ── Customer actions (My Account) ───────────────────────────────────

	/**
	 * Change the plan of a Paddle license, charging the prorated difference
	 * now. The key stays; limits rise at once.
	 *
	 * @param array  $lic  License.
	 * @param string $plan Target plan.
	 * @return array|WP_Error Updated license.
	 */
	public static function change_plan( array $lic, string $plan ) {
		if ( ! SFLS_Licenses::is_paddle( $lic ) || ! SFLS_Plans::get( $plan ) ) {
			return new WP_Error( 'sfls_not_paddle', __( 'This license is not billed through Paddle.', 'subtleforms-license-server' ) );
		}
		$map      = SFLS_Paddle::plan( $plan );
		$trialing = 'trialing' === $lic['billing_status'];
		$price    = $trialing && '' !== $map['trial_price_id'] ? $map['trial_price_id'] : $map['price_id'];
		if ( '' === $price ) {
			return new WP_Error( 'sfls_no_price', __( 'This plan is not on sale right now. Contact support and we will sort it out.', 'subtleforms-license-server' ) );
		}
		$body = array(
			'items'                  => array(
				array(
					'price_id' => $price,
					'quantity' => 1,
				),
			),
			// During a trial nothing has been paid: the new plan is charged when the trial ends.
			'proration_billing_mode' => $trialing ? 'do_not_bill' : 'prorated_immediately',
		);
		if ( ! $trialing ) {
			$body['on_payment_failure'] = 'prevent_change';
		}
		$sub = SFLS_Paddle::update_subscription( (string) $lic['paddle_subscription_id'], $body );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		if ( SFLS_Paddle::plan_for_price( $price ) !== $plan ) {
			$sub['custom_data'] = array( 'sfls_plan' => $plan );
		}
		$synced = self::sync_subscription( $sub );
		return $synced['license'] ?? SFLS_Licenses::find( (int) $lic['id'] );
	}

	/**
	 * Turn auto-renew off: cancel at the end of the period (or of the trial).
	 *
	 * @param array $lic License.
	 * @return array|WP_Error Updated license.
	 */
	public static function cancel_auto_renew( array $lic ) {
		if ( ! SFLS_Licenses::is_paddle( $lic ) ) {
			return new WP_Error( 'sfls_not_paddle', __( 'This license is not billed through Paddle.', 'subtleforms-license-server' ) );
		}
		$sub = SFLS_Paddle::cancel_subscription( (string) $lic['paddle_subscription_id'], false );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		return self::sync_subscription( $sub )['license'] ?? SFLS_Licenses::find( (int) $lic['id'] );
	}

	/**
	 * Turn auto-renew back on: remove the scheduled cancellation.
	 *
	 * @param array $lic License.
	 * @return array|WP_Error Updated license.
	 */
	public static function resume( array $lic ) {
		if ( ! SFLS_Licenses::is_paddle( $lic ) ) {
			return new WP_Error( 'sfls_not_paddle', __( 'This license is not billed through Paddle.', 'subtleforms-license-server' ) );
		}
		$sub = SFLS_Paddle::update_subscription( (string) $lic['paddle_subscription_id'], array( 'scheduled_change' => null ) );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		return self::sync_subscription( $sub )['license'] ?? SFLS_Licenses::find( (int) $lic['id'] );
	}

	/**
	 * A fresh customer portal link (they expire, so never stored).
	 *
	 * @param array  $lic  License.
	 * @param string $page payment | overview | cancel.
	 * @return string|WP_Error
	 */
	public static function portal_url( array $lic, string $page = 'payment' ) {
		if ( ! SFLS_Licenses::is_paddle( $lic ) || '' === (string) $lic['paddle_customer_id'] ) {
			return new WP_Error( 'sfls_not_paddle', __( 'This license is not billed through Paddle.', 'subtleforms-license-server' ) );
		}
		$urls = SFLS_Paddle::portal_urls( (string) $lic['paddle_customer_id'], (string) $lic['paddle_subscription_id'] );
		if ( is_wp_error( $urls ) ) {
			return $urls;
		}
		$keys = array(
			'payment'  => 'update_subscription_payment_method',
			'cancel'   => 'cancel_subscription',
			'overview' => 'overview',
		);
		$url = (string) ( $urls[ $keys[ $page ] ?? 'overview' ] ?? '' );
		if ( '' === $url ) {
			$url = (string) ( $urls['overview'] ?? '' );
		}
		return '' !== $url ? $url : new WP_Error( 'sfls_no_portal', __( 'Paddle did not return a billing link. Try again in a minute.', 'subtleforms-license-server' ) );
	}

	/**
	 * Billing state for people: [ tone, text ] or null for non-Paddle licenses.
	 *
	 * @param array $lic License.
	 * @return array{0:string,1:string}|null
	 */
	public static function state_line( array $lic ): ?array {
		if ( ! SFLS_Licenses::is_paddle( $lic ) ) {
			return null;
		}
		$fmt  = get_option( 'date_format' );
		$date = static fn( $gmt ) => $gmt ? wp_date( $fmt, strtotime( $gmt . ' UTC' ) ) : '';
		switch ( $lic['billing_status'] ) {
			case 'trialing':
				if ( ! empty( $lic['cancels_at'] ) ) {
					/* translators: %s: date */
					return array( 'warn', sprintf( __( 'Free trial, cancels on %s. You will not be charged.', 'subtleforms-license-server' ), $date( $lic['cancels_at'] ) ) );
				}
				$amount = SFLS_Licenses::renewal_amount( $lic );
				return array(
					'info',
					null !== $amount
						/* translators: 1: date, 2: amount */
						? sprintf( __( 'Trial ends on %1$s. Then %2$s a year.', 'subtleforms-license-server' ), $date( $lic['trial_ends_at'] ), $amount )
						/* translators: %s: date */
						: sprintf( __( 'Trial ends on %s.', 'subtleforms-license-server' ), $date( $lic['trial_ends_at'] ) ),
				);
			case 'past_due':
				return array( 'bad', __( 'Past due. The last payment failed. Update your payment method to keep Pro running.', 'subtleforms-license-server' ) );
			case 'paused':
				return array( 'warn', __( 'Billing is paused.', 'subtleforms-license-server' ) );
			case 'canceled':
				return array(
					'neutral',
					SFLS_Licenses::state( $lic )['valid']
						/* translators: %s: date */
						? sprintf( __( 'Canceled. Pro keeps working until %s.', 'subtleforms-license-server' ), SFLS_Licenses::expiry_label( $lic ) )
						: __( 'Canceled.', 'subtleforms-license-server' ),
				);
			default:
				if ( ! empty( $lic['cancels_at'] ) ) {
					/* translators: %s: date */
					return array( 'warn', sprintf( __( 'Cancels on %s. Auto-renew is off.', 'subtleforms-license-server' ), $date( $lic['cancels_at'] ) ) );
				}
				$amount = SFLS_Licenses::renewal_amount( $lic );
				return array(
					'ok',
					null !== $amount && ! empty( $lic['renews_at'] )
						/* translators: 1: date, 2: amount */
						? sprintf( __( 'Renews on %1$s for %2$s.', 'subtleforms-license-server' ), $date( $lic['renews_at'] ), $amount )
						/* translators: %s: date */
						: sprintf( __( 'Renews on %s.', 'subtleforms-license-server' ), $date( $lic['renews_at'] ?? $lic['expires_at'] ) ),
				);
		}
	}
}
