/**
 * "Pay with Paddle" in the WooCommerce Checkout block.
 *
 * Registers the payment method with its label, description and the tax note.
 * Placing the order runs the gateway's process_payment() through the Store
 * API, which creates the order and its Paddle transaction. onCheckoutSuccess
 * then opens the Paddle payment form inline, under the payment method, and
 * holds the checkout until Paddle confirms the payment
 * (assets/js/sfls-paddle-inline.js). "Change details" closes the form and
 * returns the checkout to editing, so the customer can place the order again.
 *
 * If the inline script is not available, the checkout falls back to the
 * order's payment page.
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
	var useEffect = element.useEffect;
	var useRef = element.useRef;
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

	/**
	 * Payment details from the Store API, as a plain object.
	 *
	 * @param {Object|Array} details Object, or a list of { key, value }.
	 */
	function details( raw ) {
		if ( Array.isArray( raw ) ) {
			var out = {};
			raw.forEach( function ( row ) {
				if ( row && row.key ) {
					out[ row.key ] = row.value;
				}
			} );
			return out;
		}
		return raw || {};
	}

	function Content( props ) {
		var frame = useRef( null );
		var registration = ( props && props.eventRegistration ) || {};
		var emit = ( props && props.emitResponse ) || {};
		var onSuccess = registration.onCheckoutSuccess;

		useEffect(
			function () {
				if ( typeof onSuccess !== 'function' ) {
					return undefined;
				}
				return onSuccess( function ( args ) {
					var response = ( args && args.processingResponse ) || {};
					var d = details( response.paymentDetails );
					var api = window.sflsPaddleInlineApi;
					if ( ! d.sfls_paddle_txn || ! api || ! frame.current ) {
						return true; // Upgrades, or no inline script: go to the redirect URL.
					}
					return api
						.mount( frame.current, {
							transactionId: d.sfls_paddle_txn,
							successUrl: d.sfls_paddle_success,
							payUrl: d.sfls_paddle_pay_url,
							trial: d.sfls_paddle_trial,
							cancelable: true,
						} )
						.then( function ( outcome ) {
							if ( 'completed' === outcome ) {
								// Keep the checkout busy; the page is moving to the order received page.
								return new Promise( function () {} );
							}
							var types = emit.responseTypes || {};
							var contexts = emit.noticeContexts || {};
							return {
								type: types.ERROR || 'error',
								message: decode( ( api.i18n && api.i18n.cancelled ) || data.cancelled || '' ),
								messageContext: contexts.PAYMENTS,
								retry: true,
							};
						} );
				} );
			},
			[ onSuccess ]
		);

		return el(
			'div',
			{ className: 'sfls-paddle-method' },
			data.description ? el( 'p', null, decode( data.description ) ) : null,
			data.taxNote ? el( 'p', { className: 'sfls-paddle-tax-note' }, decode( data.taxNote ) ) : null,
			el( 'div', { ref: frame, className: 'sfls-paddle-inline', hidden: true } )
		);
	}

	function Edit() {
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
		edit: el( Edit ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: { features: data.supports || [ 'products' ] },
	} );
} )();
