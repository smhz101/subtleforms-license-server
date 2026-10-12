<?php
/**
 * "Pay with Paddle": a WooCommerce payment gateway for SubtleForms license
 * products. Paddle Billing is the merchant of record.
 *
 * The API key, client-side token, webhook secret and plan prices are the
 * ones in Licenses › Settings › Paddle; this screen only holds the title and
 * description customers see. See SFLS_Woo_Paddle for the checkout flow and
 * SFLS_Woo_Paddle_Orders for webhooks, renewals and refunds.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paddle payment gateway.
 */
class SFLS_Gateway_Paddle extends WC_Payment_Gateway {

	/**
	 * Adjustment created by the last process_refund() call, so the WooCommerce
	 * refund can remember it.
	 *
	 * @var string
	 */
	public static string $last_adjustment = '';

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id                 = SFLS_Woo_Paddle::GATEWAY;
		$this->method_title       = __( 'Paddle', 'subtleforms-license-server' );
		$this->method_description = __( 'Customers check out in WooCommerce and pay in a secure Paddle window by card, PayPal, Apple Pay or Google Pay. Paddle is the merchant of record and adds sales tax or VAT. Used for SubtleForms license products only.', 'subtleforms-license-server' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = (string) $this->get_option( 'title' );
		$this->description = (string) $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Licenses › Settings › Paddle.
	 */
	public static function paddle_settings_url(): string {
		return admin_url( 'admin.php?page=sfls-settings&tab=paddle' );
	}

	/**
	 * This screen.
	 */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . SFLS_Woo_Paddle::GATEWAY );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'subtleforms-license-server' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Pay with Paddle for SubtleForms licenses', 'subtleforms-license-server' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Title', 'subtleforms-license-server' ),
				'type'        => 'text',
				'description' => __( 'The payment method name customers see at checkout.', 'subtleforms-license-server' ),
				'default'     => __( 'Pay with Paddle', 'subtleforms-license-server' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'subtleforms-license-server' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment method at checkout.', 'subtleforms-license-server' ),
				'default'     => __( 'You pay in a secure Paddle window, and Paddle emails your receipt.', 'subtleforms-license-server' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Settings screen: the Paddle connection lives on the license server's
	 * Paddle tab.
	 */
	public function admin_options(): void {
		echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
		echo wp_kses_post( wpautop( $this->get_method_description() ) );
		$mode = SFLS_Settings::sell_with();
		if ( ! SFLS_Paddle::configured() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Paddle is not connected yet. Add the API key, client-side token, webhook secret and plan prices first.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( self::paddle_settings_url() ) . '">' . esc_html__( 'Open Licenses › Settings › Paddle', 'subtleforms-license-server' ) . '</a></p></div>';
		} elseif ( 'woo_paddle' !== $mode ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Licenses › Settings › Paddle does not sell through the WooCommerce checkout with Paddle payments, so this method is hidden at checkout.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( self::paddle_settings_url() ) . '">' . esc_html__( 'Change how licenses are sold', 'subtleforms-license-server' ) . '</a></p></div>';
		} else {
			echo '<div class="notice notice-success inline"><p>' . esc_html(
				'live' === SFLS_Paddle::config()['env']
					? __( 'Connected to Paddle (live). License-only carts are paid with Paddle.', 'subtleforms-license-server' )
					: __( 'Connected to the Paddle sandbox. No real money is taken.', 'subtleforms-license-server' )
			) . ' <a href="' . esc_url( self::paddle_settings_url() ) . '">' . esc_html__( 'Paddle connection, prices and webhook', 'subtleforms-license-server' ) . '</a></p></div>';
		}
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 * Available for license-only carts while Licenses › Settings › Paddle
	 * sells with "WooCommerce checkout with Paddle payments".
	 */
	public function is_available() {
		if ( ! parent::is_available() || ! SFLS_Paddle::woo_selling() ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true; // Settings screens list it.
		}
		return SFLS_Woo_Paddle::context_payable();
	}

	/**
	 * Classic checkout: description and the tax note.
	 */
	public function payment_fields(): void {
		echo '<p class="sfls-paddle-label-methods">' . esc_html__( 'Card · PayPal · Apple Pay · Google Pay', 'subtleforms-license-server' ) . '</p>';
		$description = $this->get_description();
		if ( '' !== $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}
		echo '<p class="sfls-paddle-tax-note">' . esc_html( SFLS_Woo_Paddle::tax_note() ) . '</p>';
	}

	/**
	 * Place order: create the Paddle transaction and go to the payment page.
	 *
	 * @param int $order_id Order.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'result' => 'failure' );
		}
		$result = SFLS_Woo_Paddle::start_payment( $order );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => '',
			);
		}
		return $result;
	}

	/**
	 * Refund through Paddle: a full or partial refund adjustment of the
	 * order's transaction. Paddle approves refunds (sandbox: within minutes);
	 * a rejected refund is reported back by the webhook.
	 *
	 * @param int        $order_id Order.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		self::$last_adjustment = '';
		$order                 = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'sfls_refund', __( 'Order not found.', 'subtleforms-license-server' ) );
		}
		$result = SFLS_Woo_Paddle_Orders::refund( $order, null === $amount ? (float) $order->get_remaining_refund_amount() : (float) $amount, (string) $reason );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::$last_adjustment = (string) $result['id'];
		return true;
	}
}
