/**
 * Cancel auto-renew through Paddle's Retain cancellation flow (survey and offer).
 *
 * Paddle cancels, discounts or keeps the subscription itself and the webhooks
 * sync the result. If the flow cannot load or fails, the form posts as before
 * and the server cancels directly.
 *
 * Settings come from window.sflsRetain (SFLS_Account::retain_attr()).
 */
( function () {
	'use strict';

	var cfg = window.sflsRetain;
	if ( ! cfg ) {
		return;
	}
	var i18n = cfg.i18n || {};
	var initialized = false;

	function init() {
		var P = window.Paddle;
		if ( initialized || ! P || typeof P.Initialize !== 'function' ) {
			return;
		}
		initialized = true;
		if ( ! P.Initialized ) {
			P.Initialize( { token: cfg.token } );
		}
	}

	function ready() {
		init();
		var P = window.Paddle;
		return !! ( P && P.Retain && typeof P.Retain.initCancellationFlow === 'function' );
	}

	// Direct cancel on the server, after the same confirm the button shows without Retain.
	function fallback( form, button ) {
		var text = button && button.getAttribute( 'data-sfls-retain-confirm' );
		if ( text && ! window.confirm( text ) ) {
			if ( button ) {
				button.disabled = false;
			}
			return;
		}
		HTMLFormElement.prototype.submit.call( form );
	}

	function notice( form, text ) {
		var el = document.createElement( 'p' );
		el.setAttribute( 'role', 'status' );
		el.className = 'sfls-retain-notice';
		el.textContent = text;
		form.parentNode.insertBefore( el, form.nextSibling );
		window.setTimeout( function () {
			window.location.reload();
		}, 4000 );
	}

	function onSubmit( event ) {
		var form = event.target;
		var id = form && form.getAttribute && form.getAttribute( 'data-sfls-retain-subscription' );
		if ( ! id ) {
			return;
		}
		event.preventDefault();
		var button = form.querySelector( 'button[type="submit"]' );
		if ( button ) {
			if ( button.disabled ) {
				return;
			}
			button.disabled = true;
		}
		function enable() {
			if ( button ) {
				button.disabled = false;
			}
		}
		var flow;
		try {
			if ( ! ready() ) {
				fallback( form, button );
				return;
			}
			flow = window.Paddle.Retain.initCancellationFlow( { subscriptionId: id } );
		} catch ( e ) {
			fallback( form, button );
			return;
		}
		Promise.resolve( flow ).then( function ( result ) {
			var status = result && result.status;
			if ( 'chose_to_cancel' === status ) {
				notice( form, i18n.scheduled || '' );
			} else if ( 'retained' === status ) {
				notice( form, i18n.kept || '' );
			} else if ( 'aborted' === status ) {
				enable();
			} else {
				fallback( form, button );
			}
		}, function () {
			fallback( form, button );
		} );
	}

	// Start Paddle early so Retain has loaded by the time the button is clicked.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	document.addEventListener( 'submit', onSubmit );
}() );
