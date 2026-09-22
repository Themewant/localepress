/**
 * Editor controls and preview for the navigation language switcher.
 *
 * The block renders through core navigation blocks on the server, so what the
 * canvas shows is a stand-in rather than the real thing. It is still built from
 * the same rules the renderer applies, because a preview that ignores the panel
 * beside it teaches the wrong thing: every control here changes what the menu
 * will hold, so every control has to change what is on screen.
 *
 * Two of them cannot be answered exactly. `hideMissing` and the untranslated
 * behavior both turn on the page being read, and a template is not a page. The
 * preview answers them the way the renderer answers a page that exists in every
 * language — the home page, every archive, every search — because that is the
 * page a header is seen on most, and a preview that guessed the other way would
 * show a shorter menu than the header it is previewing.
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

	/**
	 * Returns the language the preview treats as the one being read.
	 *
	 * The Site Editor works in a language and says so, and that is the honest
	 * answer wherever it is available. Failing that the default language stands
	 * in, because the switcher has to mark one entry as current and no entry is
	 * a worse answer than an approximate one.
	 *
	 * @return {string} Language identifier, or an empty string.
	 */
	function currentLanguageId() {
		var editor = window.localePressEditor;
		var index;

		if ( editor && editor.language ) {
			return editor.language;
		}

		for ( index = 0; index < languages.length; index++ ) {
			if ( languages[ index ].is_default ) {
				return languages[ index ].id;
			}
		}

		return languages.length ? languages[ 0 ].id : '';
	}

	/**
	 * Builds the entries the preview renders, following the renderer's rules.
	 *
	 * @param {Object} attributes Block attributes.
	 * @return {Array} Preview entries.
	 */
	function previewEntries( attributes ) {
		var currentId = currentLanguageId();
		var behavior = attributes.hideMissing ? 'hide' : attributes.unavailableBehavior || 'hide';
		var entries = [];

		languages.forEach( function ( language ) {
			var isDisabled = ! language.enabled;
			var isCurrent = language.id === currentId;
			var unavailable;

			if ( isDisabled && ! attributes.showDisabled ) {
				return;
			}

			/*
			 * Outside submenu mode the current language leaves the menu outright.
			 * Inside one it is also the item everything hangs from, so it stays in
			 * the list here and the submenu decides what to do with it.
			 */
			if ( isCurrent && attributes.hideCurrent && ! attributes.dropdown ) {
				return;
			}

			unavailable = ! isDisabled && ! language.has_content;

			/*
			 * Hiding is narrower than it sounds, and the preview matches the
			 * renderer rather than the label. A language leaves the menu only
			 * where the page being read has no address in it — and a template is
			 * read on the home page and every archive, which answer in every
			 * language. Dropping it here would show a shorter menu than the one
			 * the same header renders a moment later.
			 */
			entries.push( {
				language: language,
				isCurrent: isCurrent,
				isUnlinked: isDisabled || ( unavailable && 'disabled' === behavior )
			} );
		} );

		return entries;
	}

	/**
	 * Renders one language as a menu item's content.
	 *
	 * @param {Object} entry      Preview entry.
	 * @param {Object} attributes Block attributes.
	 * @return {Object} Label element.
	 */
	function itemContent( entry, attributes ) {
		var classNames = [ 'wp-block-navigation-item__content', 'localepress-switcher__item' ];
		var flag = null;

		if ( entry.isCurrent ) {
			classNames.push( 'is-current-language' );
		}

		if ( entry.isUnlinked ) {
			classNames.push( 'localepress-switcher__item--unavailable' );
		}

		if ( attributes.showFlags && entry.language.flag_url ) {
			flag = createElement( 'img', {
				className: 'localepress-switcher__flag',
				src: entry.language.flag_url,
				alt: '',
				width: 16,
				height: 11
			} );
		}

		return createElement(
			'span',
			{ className: classNames.join( ' ' ) },
			flag,
			labelFor( entry.language, attributes.display )
		);
	}

	/**
	 * Renders the arrow core puts beside a submenu.
	 *
	 * @return {Object} Icon element.
	 */
	function submenuIcon() {
		return createElement(
			'span',
			{ className: 'localepress-switcher-preview__icon', 'aria-hidden': true },
			createElement(
				'svg',
				{
					xmlns: 'http://www.w3.org/2000/svg',
					width: 12,
					height: 12,
					viewBox: '0 0 12 12',
					focusable: false
				},
				createElement( 'path', {
					d: 'M1.5 4L6 8L10.5 4',
					stroke: 'currentColor',
					strokeWidth: 1.5,
					fill: 'none'
				} )
			)
		);
	}

	/**
	 * Renders the languages as a flat run of menu items.
	 *
	 * @param {Array}  entries    Preview entries.
	 * @param {Object} attributes Block attributes.
	 * @return {Array} Item elements.
	 */
	function renderFlat( entries, attributes ) {
		return entries.map( function ( entry ) {
			return createElement(
				Fragment,
				{ key: entry.language.id },
				itemContent( entry, attributes )
			);
		} );
	}

	/**
	 * Renders the current language holding the full list beneath it.
	 *
	 * The language on the toggle is listed inside the panel too, marked as the
	 * current one, which is how a language menu reads everywhere else: the closed
	 * control names where you are, opening it shows the whole set with your place
	 * in it. "Hide the current language" takes it back out.
	 *
	 * The label and its arrow are wrapped together, because the menu item they
	 * sit in also holds the panel and all three would otherwise be laid out as
	 * siblings — the arrow dropping onto its own line under the label.
	 *
	 * The panel is drawn out of flow, the way the rendered menu draws it, so a
	 * header keeps its height whether the block is selected or not. It opens
	 * while the block is selected: an editor changing these settings is holding
	 * the block, and one who is not is looking at the menu as a reader will.
	 *
	 * @param {Array}   entries    Preview entries.
	 * @param {Object}  attributes Block attributes.
	 * @return {Array} Item elements.
	 */
	function renderSubmenu( entries, attributes ) {
		var parent = null;
		var children = [];

		entries.forEach( function ( entry ) {
			if ( null === parent && entry.isCurrent ) {
				parent = entry;
			}
		} );

		if ( null === parent ) {
			parent = entries[ 0 ];
		}

		entries.forEach( function ( entry ) {
			if ( entry === parent && attributes.hideCurrent ) {
				return;
			}

			children.push(
				createElement(
					'li',
					{
						key: entry.language.id,
						className: 'wp-block-navigation-item localepress-switcher-preview__child'
					},
					itemContent( entry, attributes )
				)
			);
		} );

		if ( ! children.length ) {
			return [ itemContent( parent, attributes ) ];
		}

		return [
			createElement(
				'span',
				{ key: 'toggle', className: 'localepress-switcher-preview__toggle' },
				itemContent( parent, attributes ),
				submenuIcon()
			),
			createElement(
				'ul',
				{ key: 'submenu', className: 'localepress-switcher-preview__submenu' },
				children
			)
		];
	}

	blocks.registerBlockType( 'localepress/navigation-language-switcher', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var entries = languages.length ? previewEntries( attributes ) : [];
			var isSubmenu = !! attributes.dropdown && entries.length > 0;
			var className = 'wp-block-navigation-item localepress-switcher-preview';
			var blockProps;
			var preview;

			if ( isSubmenu ) {
				className += ' has-child localepress-switcher-preview--submenu';

				if ( props.isSelected ) {
					className += ' localepress-switcher-preview--open';
				}
			}

			blockProps = useBlockProps( { className: className } );

			if ( ! languages.length ) {
				preview = createElement(
					'span',
					{ className: 'wp-block-navigation-item__content localepress-switcher-preview__notice' },
					__( 'Add languages in LocalePress to preview the switcher.', 'localepress' )
				);
			} else if ( ! entries.length ) {
				preview = createElement(
					'span',
					{ className: 'wp-block-navigation-item__content localepress-switcher-preview__notice' },
					__( 'These settings leave no language to show.', 'localepress' )
				);
			} else if ( isSubmenu ) {
				preview = renderSubmenu( entries, attributes );
			} else {
				preview = renderFlat( entries, attributes );
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
								'Puts the current language in the menu, with every language listed below it.',
								'localepress'
							),
							checked: !! attributes.dropdown,
							onChange: function ( value ) {
								setAttributes( { dropdown: value } );
							}
						} ),
						createElement( ToggleControl, {
							/*
							 * In a submenu the current language is the toggle as well
							 * as an entry in the list, and only the entry can go. The
							 * help text says which one this removes, because the
							 * label on its own reads as though the whole thing would
							 * disappear.
							 */
							label: __( 'Hide the current language', 'localepress' ),
							help: attributes.dropdown
								? __(
									'Leaves the current language on the submenu itself and out of the list below it.',
									'localepress'
								)
								: undefined,
							checked: !! attributes.hideCurrent,
							onChange: function ( value ) {
								setAttributes( { hideCurrent: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Hide languages the site has no content in', 'localepress' ),
							/*
							 * The preview cannot show this working, because the rule
							 * only bites on a page that has no translation and a
							 * template is not one. Saying so is better than a canvas
							 * that looks broken.
							 */
							help: __(
								'Applies on pages that have no translation. The home page and archives answer in every language, so the preview keeps showing them.',
								'localepress'
							),
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
