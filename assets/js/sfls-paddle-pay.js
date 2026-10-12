/**
 * Payment page of an order paid with Paddle (WooCommerce order-pay).
 *
 * Opens the Paddle overlay for the transaction created from the order. When
 * the payment completes Paddle sends the customer to the order received page,
 * which waits for the webhook and then shows the license key. Closing the
 * overlay leaves the order pending; the button opens it again.
 *
 * Settings come from window.sflsPaddlePay (SFLS_Woo_Paddle::receipt_page()).
 */
( function () {
	'use strict';

	var cfg = window.sflsPaddlePay;
	var root = document.querySelector( '[data-sfls-paddle-pay]' );
	if ( ! cfg || ! root ) {
		return;
	}
	var i18n = cfg.i18n || {};
	var button = root.querySelector( '[data-sfls-paddle-open]' );
	var status = root.querySelector( '.sfls-paddle-pay-status' );
	var initialized = false;
	var done = false;

	function say( text, tone ) {
		if ( status ) {
			status.textContent = text || '';
			status.setAttribute( 'data-tone', tone || '' );
		}
	}

	function ready() {
		if ( initialized ) {
			return true;
		}
		var P = window.Paddle;
		if ( ! P || ! P.Checkout || typeof P.Initialize !== 'function' ) {
			return false;
		}
		if ( 'sandbox' === cfg.environment && P.Environment && typeof P.Environment.set === 'function' ) {
			P.Environment.set( 'sandbox' );
		}
		P.Initialize( { token: cfg.token, eventCallback: onEvent } );
		initialized = true;
		return true;
	}

	function onEvent( event ) {
		var name = event && event.name;
		if ( 'checkout.completed' === name ) {
			done = true;
			say( i18n.done, 'ok' );
			// Paddle redirects to successUrl itself; this is the fallback.
			window.setTimeout( function () {
				window.location.assign( cfg.successUrl );
			}, 1500 );
		} else if ( 'checkout.closed' === name && ! done ) {
			say( i18n.closed, 'warn' );
			if ( button ) {
				button.disabled = false;
			}
		}
	}

	function open() {
		if ( ! ready() ) {
			say( i18n.noPaddle, 'bad' );
			return;
		}
		say( i18n.opening, '' );
		if ( button ) {
			button.disabled = true;
		}
		window.Paddle.Checkout.open( {
			transactionId: cfg.transactionId,
			settings: {
				displayMode: 'overlay',
				successUrl: cfg.successUrl,
				locale: cfg.locale || undefined,
				allowLogout: false,
				showAddDiscounts: false,
				allowDiscountRemoval: false,
			},
		} );
		window.setTimeout( function () {
			if ( button && ! done ) {
				button.disabled = false;
			}
		}, 4000 );
	}

	if ( button ) {
		button.addEventListener( 'click', open );
	}

	// Paddle.js loads with the page; open as soon as it is there.
	var tries = 0;
	( function wait() {
		if ( ready() ) {
			open();
			return;
		}
		if ( ++tries > 50 ) {
			say( i18n.noPaddle, 'bad' );
			return;
		}
		window.setTimeout( wait, 100 );
	} )();
} )();
