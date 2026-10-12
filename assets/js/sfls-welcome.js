/**
 * Welcome page after a Paddle checkout: polls GET /sfls/v1/paddle/checkout
 * until the webhook has issued the license, then shows the key, the trial end
 * date, Download Pro and the install steps.
 *
 * The checkout token comes from the URL fragment (#t=...), which never reaches
 * the server logs, or from sessionStorage in the browser that paid.
 *
 * Settings come from window.sflsWelcome (SFLS_Checkout_Paddle::localize_welcome()).
 */
( function () {
	'use strict';

	var cfg = window.sflsWelcome;
	var root = document.querySelector( '[data-sfls-welcome]' );
	if ( ! cfg || ! root ) {
		return;
	}
	var i18n = cfg.i18n || {};

	function token() {
		var match = /[#&]t=([a-f0-9]+)/.exec( window.location.hash || '' );
		if ( match ) {
			return match[ 1 ];
		}
		try {
			return window.sessionStorage.getItem( 'sfls_checkout_token' ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function show( state ) {
		root.querySelectorAll( '.sfls-welcome-state' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-state' ) !== state;
		} );
		root.setAttribute( 'data-current', state );
	}

	function field( name ) {
		return root.querySelector( '[data-state="' + ( 'message' === name ? 'signin' : 'ready' ) + '"] [data-field="' + name + '"]' );
	}

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( text || '' ).replace( /%(\d\$)?s/g, function ( m, pos ) {
			return pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
		} );
	}

	function render( data ) {
		if ( ! data.authorized ) {
			field( 'message' ).textContent = data.message || '';
			show( 'signin' );
			return;
		}
		field( 'key' ).textContent = data.license_key;
		var copy = root.querySelector( '[data-state="ready"] [data-sfls-copy]' );
		if ( copy ) {
			copy.setAttribute( 'data-sfls-copy', data.license_key );
		}
		field( 'summary' ).textContent = [ data.plan, data.sites ].filter( Boolean ).join( ' · ' );
		var trial = field( 'trial' );
		if ( data.trial && data.trial_ends ) {
			trial.textContent = data.amount ? format( i18n.trialThen, data.trial_ends, data.amount ) : format( i18n.trialEnds, data.trial_ends );
			trial.hidden = false;
		} else if ( data.renews ) {
			trial.textContent = format( i18n.renews, data.renews );
			trial.hidden = false;
		}
		if ( data.email ) {
			field( 'emailed' ).textContent = format( i18n.emailed, data.email );
		}
		var download = field( 'download' );
		if ( download && data.download ) {
			download.setAttribute( 'href', data.download );
		}
		var account = field( 'account' );
		if ( account && data.account ) {
			account.setAttribute( 'href', data.account );
		}
		show( 'ready' );
		try {
			window.sessionStorage.removeItem( 'sfls_checkout_token' );
		} catch ( e ) {}
	}

	if ( ! cfg.txn ) {
		show( 'slow' );
		return;
	}

	var attempts = 0;
	var secret = token();

	function poll() {
		attempts++;
		var url = cfg.endpoint + ( cfg.endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) + 'txn=' + encodeURIComponent( cfg.txn ) + '&token=' + encodeURIComponent( secret );
		var headers = {};
		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		window
			.fetch( url, { credentials: 'same-origin', headers: headers } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data && data.ready ) {
					render( data );
					return;
				}
				next();
			} )
			.catch( next );
	}

	function next() {
		if ( attempts >= ( cfg.attempts || 45 ) ) {
			show( 'slow' );
			return;
		}
		window.setTimeout( poll, cfg.interval || 2000 );
	}

	show( 'waiting' );
	poll();
} )();
