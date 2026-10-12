/**
 * Pay with Paddle, embedded in the page (Paddle inline checkout).
 *
 * Place order creates the WooCommerce order and its Paddle transaction
 * (SFLS_Woo_Paddle::start_payment()). Instead of leaving the checkout, the
 * Paddle payment form opens right under the payment method, already filled
 * with the customer's email and country, so the customer only enters the card
 * (or picks PayPal, Apple Pay or Google Pay) and pays. When Paddle confirms,
 * the customer goes to the order received page, which waits for the webhook
 * and shows the license key.
 *
 * Used by:
 *   - the Checkout block (assets/js/sfls-gateway-blocks.js calls
 *     window.sflsPaddleInlineApi.mount() from onCheckoutSuccess);
 *   - the classic checkout (checkout_place_order_success, below);
 *   - the order payment page (order-pay), which mounts automatically.
 *
 * Loading Paddle.js on the checkout page also lets Paddle open payment links
 * (?_ptxn=txn_...) that it emails, for example to update a card.
 *
 * Settings come from window.sflsPaddleInline (SFLS_Woo_Paddle::inline_config()).
 */
( function () {
	'use strict';

	var cfg = window.sflsPaddleInline || {};
	var i18n = cfg.i18n || {};
	var initialized = false;
	var current = null; // { root, status, resolve, successUrl, done }

	function paddle() {
		var P = window.Paddle;
		return P && P.Checkout && typeof P.Initialize === 'function' ? P : null;
	}

	function init() {
		if ( initialized ) {
			return true;
		}
		var P = paddle();
		if ( ! P || ! cfg.token ) {
			return false;
		}
		if ( 'sandbox' === cfg.environment && P.Environment && typeof P.Environment.set === 'function' ) {
			P.Environment.set( 'sandbox' );
		}
		if ( P.Initialized && typeof P.Update === 'function' ) {
			P.Update( { eventCallback: onEvent } );
		} else {
			P.Initialize( { token: cfg.token, eventCallback: onEvent } );
		}
		initialized = true;
		return true;
	}

	function whenReady( done, fail ) {
		var tries = 0;
		( function wait() {
			if ( init() ) {
				done();
				return;
			}
			if ( ++tries > 100 ) {
				fail();
				return;
			}
			window.setTimeout( wait, 100 );
		} )();
	}

	function say( text, tone ) {
		if ( current && current.status ) {
			current.status.textContent = text || '';
			current.status.setAttribute( 'data-tone', tone || '' );
		}
	}

	function setPaying( on ) {
		document.body.classList.toggle( 'sfls-paddle-paying', !! on );
	}

	function finish( outcome ) {
		var c = current;
		if ( ! c ) {
			return;
		}
		if ( 'completed' !== outcome ) {
			try {
				if ( paddle() ) {
					window.Paddle.Checkout.close();
				}
			} catch ( e ) {}
			c.root.innerHTML = '';
			c.root.hidden = true;
			setPaying( false );
			current = null;
		}
		c.resolve( outcome );
	}

	function onEvent( event ) {
		var name = event && event.name;
		if ( ! current ) {
			return;
		}
		if ( 'checkout.loaded' === name ) {
			say( '', '' );
			current.root.classList.add( 'is-loaded' );
		} else if ( 'checkout.completed' === name ) {
			if ( current.done ) {
				return;
			}
			current.done = true;
			say( i18n.done, 'ok' );
			var url = current.successUrl;
			finish( 'completed' );
			// Paddle redirects to successUrl itself; this is the fallback.
			window.setTimeout( function () {
				window.location.assign( url );
			}, 1500 );
		} else if ( 'checkout.error' === name ) {
			say( i18n.error, 'bad' );
		}
	}

	/**
	 * Open the Paddle payment form inside `root`.
	 *
	 * @param {HTMLElement} root Container.
	 * @param {Object}      opts { transactionId, successUrl, payUrl, trial, cancelable }
	 * @return {Promise<string>} 'completed', 'cancelled' or 'failed'.
	 */
	function mount( root, opts ) {
		if ( current ) {
			finish( 'cancelled' );
		}
		return new Promise( function ( resolve ) {
			var frameClass = 'sfls-paddle-frame-' + Math.random().toString( 36 ).slice( 2 );
			root.innerHTML = '';
			root.hidden = false;
			root.classList.remove( 'is-loaded' );
			root.classList.add( 'sfls-paddle-inline' );

			var head = document.createElement( 'p' );
			head.className = 'sfls-paddle-inline__lead';
			head.textContent = 'yes' === opts.trial ? i18n.leadTrial : i18n.lead;

			var status = document.createElement( 'p' );
			status.className = 'sfls-paddle-inline__status';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			status.textContent = i18n.loading || '';

			var frame = document.createElement( 'div' );
			frame.className = 'sfls-paddle-inline__frame ' + frameClass;

			root.appendChild( head );
			root.appendChild( status );
			root.appendChild( frame );

			if ( opts.cancelable ) {
				var cancel = document.createElement( 'button' );
				cancel.type = 'button';
				cancel.className = 'sfls-paddle-inline__cancel';
				cancel.textContent = i18n.change;
				cancel.addEventListener( 'click', function () {
					finish( 'cancelled' );
				} );
				root.appendChild( cancel );
			}

			current = {
				root: root,
				status: status,
				resolve: resolve,
				successUrl: opts.successUrl,
				done: false,
			};
			setPaying( true );
			if ( root.scrollIntoView ) {
				root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}

			whenReady(
				function () {
					window.Paddle.Checkout.open( {
						transactionId: opts.transactionId,
						settings: {
							displayMode: 'inline',
							variant: 'one-page',
							frameTarget: frameClass,
							frameInitialHeight: 450,
							frameStyle: 'width:100%; min-width:312px; background-color:transparent; border:none;',
							successUrl: opts.successUrl,
							locale: cfg.locale || undefined,
							allowLogout: false,
							showAddDiscounts: false,
							allowDiscountRemoval: false,
						},
					} );
				},
				function () {
					status.setAttribute( 'data-tone', 'bad' );
					status.textContent = i18n.noPaddle || '';
					if ( opts.payUrl ) {
						var link = document.createElement( 'a' );
						link.href = opts.payUrl;
						link.textContent = ' ' + ( i18n.payPage || '' );
						status.appendChild( link );
					}
				}
			);
		} );
	}

	window.sflsPaddleInlineApi = {
		mount: mount,
		cancel: function () {
			finish( 'cancelled' );
		},
		i18n: i18n,
	};

	// Load Paddle early: faster form, and Paddle's emailed payment links work.
	whenReady( function () {}, function () {} );

	// ── Classic checkout ────────────────────────────────────────────────
	var $ = window.jQuery;
	if ( $ ) {
		$( function () {
			var $form = $( 'form.checkout' );
			if ( ! $form.length ) {
				return;
			}
			$form.on( 'checkout_place_order_success', function ( e, result ) {
				if ( ! result || ! result.sfls_paddle_txn ) {
					return true;
				}
				// WooCommerce then "redirects" to #sfls-paddle, which stays on the page.
				$form.removeClass( 'processing' );
				if ( typeof $form.unblock === 'function' ) {
					$form.unblock();
				}
				var root = document.querySelector( '.sfls-paddle-inline--classic' );
				if ( ! root ) {
					root = document.createElement( 'div' );
					root.className = 'sfls-paddle-inline--classic';
					var payment = document.querySelector( '#payment' ) || $form.get( 0 );
					payment.parentNode.insertBefore( root, payment.nextSibling );
				}
				mount( root, {
					transactionId: result.sfls_paddle_txn,
					successUrl: result.sfls_paddle_success,
					payUrl: result.sfls_paddle_pay_url,
					trial: result.sfls_paddle_trial,
					cancelable: true,
				} ).then( function ( outcome ) {
					if ( 'cancelled' === outcome ) {
						$( document.body ).trigger( 'update_checkout' );
					}
				} );
				return true;
			} );
		} );
	}

	// ── Order payment page ──────────────────────────────────────────────
	var payRoot = document.querySelector( '[data-sfls-paddle-pay-inline]' );
	if ( payRoot && cfg.pay && cfg.pay.transactionId ) {
		mount( payRoot, {
			transactionId: cfg.pay.transactionId,
			successUrl: cfg.pay.successUrl,
			trial: cfg.pay.trial,
			cancelable: false,
		} );
	}
} )();
