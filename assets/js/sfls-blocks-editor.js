/**
 * Editor side of the SubtleForms License Server blocks. The blocks are
 * registered on the server from block.json and rendered by PHP; this file only
 * adds the inspector controls and a live server-side preview.
 *
 * Plain JavaScript on the wp.* globals, no build step.
 */
( function ( wp, settings ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var RangeControl = wp.components.RangeControl;
	var Disabled = wp.components.Disabled;
	var ServerSideRender = wp.serverSideRender;
	var plans = ( settings && settings.plans ) || [];

	/**
	 * Server-side preview, links disabled so clicks select the block.
	 *
	 * @param {string} name       Block name.
	 * @param {Object} attributes Attributes.
	 */
	function preview( name, attributes ) {
		return el( Disabled, null, el( ServerSideRender, { block: name, attributes: attributes } ) );
	}

	function editPricing( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Plans', 'subtleforms-license-server' ) },
					el( SelectControl, {
						label: __( 'Highlighted plan', 'subtleforms-license-server' ),
						help: __( 'Shown with a Most popular badge and a filled button.', 'subtleforms-license-server' ),
						value: a.popular,
						options: [ { value: '', label: __( 'None', 'subtleforms-license-server' ) } ].concat( plans ),
						onChange: function ( v ) {
							set( { popular: v } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( SelectControl, {
						label: __( 'Show first', 'subtleforms-license-server' ),
						help: __( 'When lifetime products exist, visitors can switch between yearly and lifetime prices.', 'subtleforms-license-server' ),
						value: a.period,
						options: [
							{ value: 'yearly', label: __( 'Yearly prices', 'subtleforms-license-server' ) },
							{ value: 'lifetime', label: __( 'Lifetime prices', 'subtleforms-license-server' ) },
						],
						onChange: function ( v ) {
							set( { period: v } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label: __( 'Show renewal note', 'subtleforms-license-server' ),
						help: __( 'A line under the price with the renewal price, or "Pay once" for lifetime plans.', 'subtleforms-license-server' ),
						checked: !! a.showRenewalNote,
						onChange: function ( v ) {
							set( { showRenewalNote: v } );
						},
						__nextHasNoMarginBottom: true,
					} )
				),
				el(
					PanelBody,
					{ title: __( 'Buttons', 'subtleforms-license-server' ), initialOpen: false },
					el( TextControl, {
						label: __( 'Yearly button label', 'subtleforms-license-server' ),
						help: __( '{plan} is replaced with the plan name. Empty uses "Buy {plan}".', 'subtleforms-license-server' ),
						value: a.buttonLabel,
						placeholder: __( 'Buy {plan}', 'subtleforms-license-server' ),
						onChange: function ( v ) {
							set( { buttonLabel: v } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'Lifetime button label', 'subtleforms-license-server' ),
						value: a.lifetimeButtonLabel,
						placeholder: __( 'Buy {plan}', 'subtleforms-license-server' ),
						onChange: function ( v ) {
							set( { lifetimeButtonLabel: v } );
						},
						__nextHasNoMarginBottom: true,
					} )
				)
			),
			el( 'div', useBlockProps(), preview( 'sfls/pricing', a ) )
		);
	}

	function editChangelog( props ) {
		var a = props.attributes;
		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Releases', 'subtleforms-license-server' ) },
					el( RangeControl, {
						label: __( 'Releases to show', 'subtleforms-license-server' ),
						help: __( '0 shows every release.', 'subtleforms-license-server' ),
						value: a.limit,
						min: 0,
						max: 20,
						onChange: function ( v ) {
							props.setAttributes( { limit: v || 0 } );
						},
						__nextHasNoMarginBottom: true,
					} )
				)
			),
			el( 'div', useBlockProps(), preview( 'sfls/changelog', a ) )
		);
	}

	function editLatestVersion( props ) {
		var a = props.attributes;
		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Text', 'subtleforms-license-server' ) },
					el( TextControl, {
						label: __( 'Text before the version', 'subtleforms-license-server' ),
						help: __( 'For example "Version" or "Latest:".', 'subtleforms-license-server' ),
						value: a.prefix,
						onChange: function ( v ) {
							props.setAttributes( { prefix: v } );
						},
						__nextHasNoMarginBottom: true,
					} )
				)
			),
			el( 'div', useBlockProps(), preview( 'sfls/latest-version', a ) )
		);
	}

	function editAccountLink( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Labels', 'subtleforms-license-server' ) },
					el( 'p', { className: 'components-base-control__help' }, __( 'The editor previews the signed-in link. Visitors who are not signed in see the signed-out label.', 'subtleforms-license-server' ) ),
					el( TextControl, {
						label: __( 'Signed out', 'subtleforms-license-server' ),
						value: a.signInLabel,
						placeholder: __( 'Sign in', 'subtleforms-license-server' ),
						onChange: function ( v ) {
							set( { signInLabel: v } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label: __( 'Signed in', 'subtleforms-license-server' ),
						value: a.accountLabel,
						placeholder: __( 'My licenses', 'subtleforms-license-server' ),
						onChange: function ( v ) {
							set( { accountLabel: v } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label: __( 'Show avatar when signed in', 'subtleforms-license-server' ),
						checked: !! a.showAvatar,
						onChange: function ( v ) {
							set( { showAvatar: v } );
						},
						__nextHasNoMarginBottom: true,
					} )
				)
			),
			el( 'div', useBlockProps(), preview( 'sfls/account-link', a ) )
		);
	}

	var edits = {
		'sfls/pricing': editPricing,
		'sfls/changelog': editChangelog,
		'sfls/latest-version': editLatestVersion,
		'sfls/account-link': editAccountLink,
	};

	Object.keys( edits ).forEach( function ( name ) {
		// Title, attributes and supports come from block.json on the server.
		wp.blocks.registerBlockType( name, {
			edit: edits[ name ],
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp, window.sflsBlocks );
