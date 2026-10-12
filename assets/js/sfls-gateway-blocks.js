/**
 * "Pay with Paddle" in the WooCommerce Checkout block.
 *
 * Registers the payment method with its label, description and the tax note.
 * Placing the order runs the gateway's process_payment() through the Store
 * API; it answers with the order's payment page, which opens the Paddle
 * overlay for the transaction (assets/js/sfls-paddle-pay.js).
 *
 * Data comes from SFLS_Gateway_Paddle_Blocks::get_payment_method_data().
 */
( function () {
	'use strict';

	var wc = window.wc || {};
	var registry = wc.wcBlocksRegistry;
	var settingsApi = wc.wcSettings;
	var element = window.wp && window.wp.element;
	if ( ! registry || ! settingsApi || ! element ) {
		return;
	}
	var el = element.createElement;
	var decode = ( window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) {
		return s;
	};
	var data = settingsApi.getSetting( 'sfls_paddle_data', {} );
	var title = decode( data.title || 'Pay with Paddle' );

	function Label( props ) {
		var components = ( props && props.components ) || {};
		var Text = components.PaymentMethodLabel;
		return el(
			'span',
			{ className: 'sfls-paddle-label' },
			Text ? el( Text, { text: title } ) : title,
			data.methods ? el( 'span', { className: 'sfls-paddle-label-methods' }, decode( data.methods ) ) : null
		);
	}

	function Content() {
		return el(
			'div',
			{ className: 'sfls-paddle-method' },
			data.description ? el( 'p', null, decode( data.description ) ) : null,
			data.taxNote ? el( 'p', { className: 'sfls-paddle-tax-note' }, decode( data.taxNote ) ) : null
		);
	}

	registry.registerPaymentMethod( {
		name: 'sfls_paddle',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: { features: data.supports || [ 'products' ] },
	} );
} )();
