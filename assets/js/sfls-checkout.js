/**
 * Checkout block: "Change plan" under the order summary when the cart holds a
 * SubtleForms license. The Store API marks license items with
 * extensions.sfls.is_license (see SFLS_Checkout::cart_item_extension()).
 */
( function ( wp, wc, settings ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wc || ! wc.blocksCheckout || ! wc.blocksCheckout.ExperimentalOrderMeta ) {
		return;
	}
	var el = wp.element.createElement;
	var OrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	settings = settings || {};

	function licenseItem() {
		var store = wp.data.select( 'wc/store/cart' );
		var cart = store && store.getCartData ? store.getCartData() : null;
		var items = ( cart && cart.items ) || [];
		for ( var i = 0; i < items.length; i++ ) {
			var ext = items[ i ].extensions && items[ i ].extensions.sfls;
			if ( ext && ext.is_license && ext.swappable ) {
				return ext;
			}
		}
		return null;
	}

	function ChangePlan() {
		var item = wp.data.useSelect( licenseItem, [] );
		if ( ! item || ! settings.url ) {
			return null;
		}
		return el(
			'div',
			{ className: 'sfls-change-plan' },
			el( 'span', { className: 'sfls-change-plan__plan' }, ( settings.text || '%s' ).replace( '%s', item.plan ) ),
			el( 'a', { className: 'sfls-change-plan__link', href: settings.url }, settings.link || 'Change plan' )
		);
	}

	wp.plugins.registerPlugin( 'sfls-change-plan', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el( OrderMeta, null, el( ChangePlan ) );
		},
	} );
} )( window.wp, window.wc, window.sflsCheckout );
