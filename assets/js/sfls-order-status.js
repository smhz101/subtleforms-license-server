/**
 * Order received page of an order paid with Paddle: the customer is often
 * back before Paddle's webhook. Shows "Confirming your payment" and polls
 * GET /sfls/v1/paddle/checkout?order=ID&key=KEY (which also asks Paddle
 * directly) until the license is issued, then swaps in the key panel.
 *
 * Settings come from window.sflsOrderStatus (SFLS_Delivery::waiting_html()).
 */
( function () {
	'use strict';

	var cfg = window.sflsOrderStatus;
	var box = document.querySelector( '[data-sfls-order-status]' );
	if ( ! cfg || ! box ) {
		return;
	}
	var panel = box.closest( '.sfls-delivery' ) || box.parentNode;
	var attempts = 0;
	var url = cfg.endpoint + ( cfg.endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) + 'order=' + encodeURIComponent( box.getAttribute( 'data-order' ) ) + '&key=' + encodeURIComponent( box.getAttribute( 'data-key' ) );

	function poll() {
		attempts++;
		window
			.fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data && data.ready && data.html ) {
					panel.innerHTML = data.html;
					panel.setAttribute( 'data-ready', '1' );
					// Paddle added sales tax or VAT: reload so the order details show it.
					if ( data.total && data.total !== box.getAttribute( 'data-total' ) ) {
						window.setTimeout( function () {
							window.location.reload();
						}, 1200 );
					}
					return;
				}
				next();
			} )
			.catch( next );
	}

	function next() {
		if ( attempts >= ( cfg.attempts || 60 ) ) {
			var status = box.querySelector( '[role="status"]' );
			if ( status ) {
				status.textContent = cfg.slow || '';
			}
			var spinner = box.querySelector( '.sfls-welcome-spinner' );
			if ( spinner ) {
				spinner.hidden = true;
			}
			return;
		}
		window.setTimeout( poll, cfg.interval || 2000 );
	}

	poll();
} )();
