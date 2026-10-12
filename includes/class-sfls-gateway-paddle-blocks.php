<?php
/**
 * Pay with Paddle in the Checkout block.
 *
 * The block script (assets/js/sfls-gateway-blocks.js) registers the payment
 * method with its label, description and the tax note. Placing the order
 * runs SFLS_Gateway_Paddle::process_payment() through the Store API, which
 * answers with the payment page that opens the Paddle overlay.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Checkout block integration.
 */
final class SFLS_Gateway_Paddle_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name (the gateway ID).
	 *
	 * @var string
	 */
	protected $name = 'sfls_paddle';

	/**
	 * Load the gateway settings.
	 */
	public function initialize() {
		$this->settings = (array) get_option( 'woocommerce_sfls_paddle_settings', array() );
	}

	/**
	 * Whether the method is offered (WooCommerce still checks each cart
	 * against the gateway's availability).
	 */
	public function is_active() {
		return 'no' !== ( $this->settings['enabled'] ?? 'yes' ) && SFLS_Paddle::woo_selling();
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		if ( ! wp_script_is( 'sfls-gateway-blocks', 'registered' ) ) {
			wp_register_script(
				'sfls-gateway-blocks',
				SFLS_URL . 'assets/js/sfls-gateway-blocks.js',
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
				SFLS_VERSION,
				true
			);
		}
		return array( 'sfls-gateway-blocks' );
	}

	/**
	 * Data for the script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = SFLS_Woo_Paddle::gateway();
		return array(
			'title'       => $gateway ? $gateway->get_title() : __( 'Pay with Paddle', 'subtleforms-license-server' ),
			'description' => $gateway ? $gateway->get_description() : '',
			'taxNote'     => SFLS_Woo_Paddle::tax_note(),
			'methods'     => __( 'Card · PayPal · Apple Pay · Google Pay', 'subtleforms-license-server' ),
			'supports'    => $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array( 'products' ),
		);
	}
}
