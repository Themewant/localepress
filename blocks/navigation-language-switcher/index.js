/**
 * Editor controls and preview for the navigation language switcher.
 *
 * The block renders through core navigation blocks on the server. Previewing that
 * inside the navigation editor would nest one list inside another, so the editor
 * shows the real language labels laid out as menu items instead.
 *
 * @package LocalePress
 */
( function ( blocks, blockEditor, components, element, i18n ) {
	'use strict';

	var createElement = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var __ = i18n.__;
	var languages = window.localePressSwitcherLanguages || [];

	/**
	 * Returns the label a language shows for the selected display mode.
	 *
	 * @param {Object} language Language record.
	 * @param {string} display  Display mode.
	 * @return {string} Label text.
	 */
	function labelFor( language, display ) {
		if ( 'name' === display ) {
			return language.name;
		}

		if ( 'language_code' === display ) {
			return ( language.language_code || '' ).toUpperCase();
		}

		return language.native_name || language.name;
	}

	blocks.registerBlockType( 'localepress/navigation-language-switcher', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'wp-block-navigation-item' } );
			var preview;

			if ( ! languages.length ) {
				preview = createElement(
					'span',
					{ className: 'wp-block-navigation-item__content' },
					__( 'Add languages in LocalePress to preview the switcher.', 'localepress' )
				);
			} else {
				preview = languages.map( function ( language ) {
					return createElement(
						'span',
						{
							key: language.id,
							className: 'wp-block-navigation-item__content localepress-switcher__item'
						},
						labelFor( language, attributes.display )
					);
				} );
			}

			return createElement(
				Fragment,
				null,
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: __( 'Switcher settings', 'localepress' ), initialOpen: true },
						createElement( SelectControl, {
							label: __( 'Display', 'localepress' ),
							value: attributes.display,
							options: [
								{ label: __( 'Native name', 'localepress' ), value: 'native_name' },
								{ label: __( 'Language name', 'localepress' ), value: 'name' },
								{ label: __( 'Language code', 'localepress' ), value: 'language_code' }
							],
							onChange: function ( value ) {
								setAttributes( { display: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show as a submenu', 'localepress' ),
							help: __(
								'Places the current language in the menu and the other languages below it.',
								'localepress'
							),
							checked: !! attributes.dropdown,
							onChange: function ( value ) {
								setAttributes( { dropdown: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Hide the current language', 'localepress' ),
							checked: !! attributes.hideCurrent,
							onChange: function ( value ) {
								setAttributes( { hideCurrent: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Hide languages the site has no content in', 'localepress' ),
							checked: !! attributes.hideMissing,
							onChange: function ( value ) {
								setAttributes( { hideMissing: value } );
							}
						} ),
						createElement( SelectControl, {
							label: __( 'Without a translation', 'localepress' ),
							value: attributes.unavailableBehavior,
							disabled: !! attributes.hideMissing,
							options: [
								{ label: __( 'Link to the home page', 'localepress' ), value: 'home' },
								{ label: __( 'Keep the current route', 'localepress' ), value: 'current' },
								{ label: __( 'Show without a link', 'localepress' ), value: 'disabled' },
								{ label: __( 'Hide languages the site has no content in', 'localepress' ), value: 'hide' }
							],
							onChange: function ( value ) {
								setAttributes( { unavailableBehavior: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show flags', 'localepress' ),
							checked: !! attributes.showFlags,
							onChange: function ( value ) {
								setAttributes( { showFlags: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Include disabled languages', 'localepress' ),
							checked: !! attributes.showDisabled,
							onChange: function ( value ) {
								setAttributes( { showDisabled: value } );
							}
						} )
					)
				),
				createElement( 'li', blockProps, preview )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n );
