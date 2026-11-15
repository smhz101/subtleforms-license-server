/**
 * Paddle checkout for the SubtleForms Pro pricing cards.
 *
 * Buy buttons open the Paddle overlay checkout. When the checkout completes,
 * the browser goes to the welcome page, which waits for the license.
 *
 * Settings come from window.sflsPaddle (SFLS_Checkout_Paddle::enqueue_checkout()).
 */
( function () {
	'use strict';

	var cfg = window.sflsPaddle;
	if ( ! cfg ) {
		return;
	}
	var i18n = cfg.i18n || {};
	var initialized = false;
	var pending = null;

	function paddleReady() {
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
		P.Initialize( { token: cfg.token, eventCallback: onPaddleEvent } );
		initialized = true;
		return true;
	}

	function randomToken() {
		var bytes = new Uint8Array( 24 );
		( window.crypto || window.msCrypto ).getRandomValues( bytes );
		return Array.prototype.map.call( bytes, function ( b ) {
			return ( '0' + b.toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	function onPaddleEvent( event ) {
		if ( ! event || 'checkout.completed' !== event.name || ! pending ) {
			return;
		}
		var txn = event.data && event.data.transaction_id;
		if ( ! txn ) {
			return;
		}
		var url = cfg.welcomeUrl + ( cfg.welcomeUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'txn=' + encodeURIComponent( txn ) + '#t=' + pending.token;
		if ( window.Paddle && window.Paddle.Checkout && typeof window.Paddle.Checkout.close === 'function' ) {
			window.Paddle.Checkout.close();
		}
		window.location.assign( url );
	}

	function card( el ) {
		return el.closest( '.sfls-plan' );
	}

	function showReason( btn, text ) {
		var reason = card( btn ).querySelector( '.sfls-plan-reason' );
		if ( reason ) {
			reason.textContent = text;
			reason.hidden = ! text;
		}
	}

	function openCheckout( btn, email ) {
		if ( ! paddleReady() ) {
			showReason( btn, i18n.noPaddle || '' );
			return;
		}
		var token = randomToken();
		pending = { token: token };
		try {
			window.sessionStorage.setItem( 'sfls_checkout_token', token );
		} catch ( e ) {}
		var plan = btn.dataset.plan;
		var options = {
			items: [ { priceId: btn.dataset.price, quantity: 1 } ],
			customData: {
				sfls_plan: plan,
				sfls_token: token,
				sfls_source: 'pricing',
			},
			settings: {
				displayMode: 'overlay',
				allowLogout: ! email,
				locale: cfg.locale || undefined,
			},
		};
		if ( email ) {
			options.customer = { email: email };
			options.customData.sfls_email = email;
		}
		window.Paddle.Checkout.open( options );
	}

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.sfls-paddle-buy' );
		if ( ! btn ) {
			return;
		}
		event.preventDefault();
		openCheckout( btn, cfg.email || '' );
	} );

	// Paddle.js loads in the footer; initialise as soon as it is there.
	if ( ! paddleReady() ) {
		window.addEventListener( 'load', paddleReady );
	}
} )();
