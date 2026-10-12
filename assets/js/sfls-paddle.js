/**
 * Paddle checkout for the SubtleForms Pro pricing cards.
 *
 * Trial buttons check the trial rules first (POST /sfls/v1/paddle/eligibility)
 * with the signed-in account's email or an email the visitor types, then open
 * the Paddle overlay checkout with the trial price. Customers who already had
 * a trial get "Buy now" with the reason. When the checkout completes, the
 * browser goes to the welcome page, which waits for the license.
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

	function setBusy( btn, label ) {
		if ( label ) {
			btn.dataset.label = btn.dataset.label || btn.textContent;
			btn.textContent = label;
			btn.disabled = true;
			btn.setAttribute( 'aria-busy', 'true' );
		} else {
			if ( btn.dataset.label ) {
				btn.textContent = btn.dataset.label;
			}
			delete btn.dataset.label;
			btn.disabled = false;
			btn.removeAttribute( 'aria-busy' );
		}
	}

	function openCheckout( btn, email, trial ) {
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
			items: [ { priceId: trial ? btn.dataset.trialPrice : btn.dataset.price, quantity: 1 } ],
			customData: {
				sfls_plan: plan,
				sfls_trial: trial ? '1' : '0',
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

	function switchToBuy( btn, message ) {
		var c = card( btn );
		btn.dataset.mode = 'buy';
		btn.textContent = btn.dataset.buyLabel || i18n.buyNow || 'Buy now';
		var note = c.querySelector( '.sfls-plan-trial-note' );
		if ( note ) {
			note.hidden = true;
		}
		var form = c.querySelector( '.sfls-trial-email' );
		if ( form ) {
			form.hidden = true;
		}
		btn.hidden = false;
		showReason( btn, [ message, i18n.buyInstead ].filter( Boolean ).join( ' ' ) );
	}

	function checkEligibility( btn, email ) {
		setBusy( btn, i18n.checking );
		var headers = { 'Content-Type': 'application/json' };
		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		return window
			.fetch( cfg.eligibilityUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
				body: JSON.stringify( { email: email, plan: btn.dataset.plan } ),
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				setBusy( btn, null );
				return data || {};
			} )
			.catch( function () {
				setBusy( btn, null );
				return { error: true };
			} );
	}

	function startTrial( btn, email, form ) {
		var errorEl = form ? form.querySelector( '.sfls-trial-email-error' ) : null;
		checkEligibility( btn, email ).then( function ( data ) {
			if ( data.error && ! data.reason ) {
				if ( errorEl ) {
					errorEl.textContent = i18n.error || '';
					errorEl.hidden = false;
				} else {
					showReason( btn, i18n.error || '' );
				}
				return;
			}
			if ( 'invalid_email' === data.reason && errorEl ) {
				errorEl.textContent = data.message || '';
				errorEl.hidden = false;
				return;
			}
			btn.dataset.email = email;
			if ( data.eligible ) {
				if ( form ) {
					form.hidden = true;
					btn.hidden = false;
				}
				openCheckout( btn, email, true );
				return;
			}
			switchToBuy( btn, data.message || '' );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.sfls-paddle-buy' );
		if ( ! btn ) {
			return;
		}
		event.preventDefault();
		var email = cfg.email || btn.dataset.email || '';
		if ( 'buy' === btn.dataset.mode ) {
			openCheckout( btn, email, false );
			return;
		}
		if ( email ) {
			startTrial( btn, email, null );
			return;
		}
		var form = card( btn ).querySelector( '.sfls-trial-email' );
		if ( ! form ) {
			return;
		}
		form.hidden = false;
		btn.hidden = true;
		var input = form.querySelector( 'input[type=email]' );
		if ( input ) {
			input.focus();
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '.sfls-trial-email' );
		if ( ! form ) {
			return;
		}
		event.preventDefault();
		var btn = card( form ).querySelector( '.sfls-paddle-buy' );
		var input = form.querySelector( 'input[type=email]' );
		var errorEl = form.querySelector( '.sfls-trial-email-error' );
		var email = input ? input.value.trim() : '';
		if ( errorEl ) {
			errorEl.hidden = true;
		}
		if ( ! email || ( input && input.checkValidity && ! input.checkValidity() ) ) {
			if ( errorEl ) {
				errorEl.textContent = input && input.validationMessage ? input.validationMessage : '';
				errorEl.hidden = false;
			}
			return;
		}
		var submit = form.querySelector( 'button[type=submit]' );
		if ( submit ) {
			setBusy( submit, i18n.checking );
		}
		startTrial( btn, email, form );
		if ( submit ) {
			window.setTimeout( function () {
				setBusy( submit, null );
			}, 600 );
		}
	} );

	// Paddle.js loads in the footer; initialise as soon as it is there.
	if ( ! paddleReady() ) {
		window.addEventListener( 'load', paddleReady );
	}
} )();
