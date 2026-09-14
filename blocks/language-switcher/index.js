( function ( blocks, blockEditor, components, element, i18n, ServerSideRender ) {
	'use strict';

	var createElement = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var __ = i18n.__;

	blocks.registerBlockType( 'localepress/language-switcher', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

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
						createElement( SelectControl, {
							label: __( 'Layout', 'localepress' ),
							value: attributes.layout,
							options: [
								{ label: __( 'Horizontal list', 'localepress' ), value: 'horizontal' },
								{ label: __( 'Vertical list', 'localepress' ), value: 'vertical' },
								{ label: __( 'Dropdown', 'localepress' ), value: 'dropdown' }
							],
							onChange: function ( value ) {
								setAttributes( { layout: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Hide current language', 'localepress' ),
							checked: attributes.hideCurrent,
							onChange: function ( value ) {
								setAttributes( { hideCurrent: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Hide languages the site has no content in', 'localepress' ),
							help: __( 'The same choice as the Missing translation option of that name, and it wins. Leave it off to use any of the other three.', 'localepress' ),
							checked: attributes.hideMissing,
							onChange: function ( value ) {
								setAttributes( { hideMissing: value } );
							}
						} ),
						createElement( SelectControl, {
							label: __( 'Missing translation', 'localepress' ),
							value: attributes.unavailableBehavior,
							disabled: attributes.hideMissing,
							help: attributes.hideMissing
								? __( 'Turn off the checkbox above to choose one of these.', 'localepress' )
								: __( 'What a language does on a page it has no translation of.', 'localepress' ),
							options: [
								{ label: __( 'Hide languages the site has no content in', 'localepress' ), value: 'hide' },
								{ label: __( 'Show as unavailable', 'localepress' ), value: 'disabled' },
								{ label: __( 'Link to language homepage', 'localepress' ), value: 'home' },
								{ label: __( 'Keep current URL', 'localepress' ), value: 'current' }
							],
							onChange: function ( value ) {
								setAttributes( { unavailableBehavior: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show flags', 'localepress' ),
							checked: attributes.showFlags,
							onChange: function ( value ) {
								setAttributes( { showFlags: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show disabled languages as unavailable', 'localepress' ),
							checked: attributes.showDisabled,
							onChange: function ( value ) {
								setAttributes( { showDisabled: value } );
							}
						} ),
						createElement( TextControl, {
							label: __( 'Accessible label', 'localepress' ),
							value: attributes.ariaLabel,
							onChange: function ( value ) {
								setAttributes( { ariaLabel: value } );
							}
						} )
					)
				),
				createElement( ServerSideRender, {
					block: 'localepress/language-switcher',
					attributes: attributes
				} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n,
	window.wp.serverSideRender
);
