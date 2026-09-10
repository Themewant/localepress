( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		const target = event.target.closest( '[data-localepress-confirm]' );

		if ( target && ! window.confirm( target.dataset.localepressConfirm ) ) {
			event.preventDefault();
		}
	} );

	/**
	 * Builds the flag element for one option.
	 *
	 * The wrapper is rendered even when a language has no bundled flag, so every
	 * label in the list starts at the same horizontal position.
	 *
	 * @param {string} url Flag URL, or an empty value.
	 * @return {HTMLElement} Flag wrapper.
	 */
	const buildFlag = function ( url ) {
		const wrapper = document.createElement( 'span' );

		wrapper.className = 'localepress-flag-select__flag';

		if ( url ) {
			const image = document.createElement( 'img' );

			image.className = 'localepress-flag';
			image.src = url;
			image.alt = '';
			image.width = 18;
			image.height = 12;
			image.loading = 'lazy';
			image.decoding = 'async';
			wrapper.appendChild( image );
		}

		return wrapper;
	};

	/**
	 * Number of options from which the list opens with a search field.
	 *
	 * The bundled language catalog runs to a few hundred entries, which no one
	 * can scroll comfortably. A short list — the handful of languages a site has
	 * already added — is faster to read than to type into, so it stays plain.
	 *
	 * @type {number}
	 */
	const SEARCH_THRESHOLD = 8;

	/**
	 * Folds text to the form both the index and a query are compared in.
	 *
	 * Accents are stripped so a query typed on a keyboard that has none still
	 * reaches the language it names: `espanol` finds `Español`.
	 *
	 * @param {string} value Raw text.
	 * @return {string} Comparable text.
	 */
	const fold = function ( value ) {
		const text = String( value ).toLowerCase();

		return text.normalize ? text.normalize( 'NFD' ).replace( /[\u0300-\u036F]/g, '' ) : text;
	};

	/**
	 * Replaces a select with a listbox that can show a flag beside each option.
	 *
	 * A native option cannot contain an image, so the select stays in the DOM as
	 * the value that is read and submitted, and this listbox drives it. Choosing
	 * an option dispatches `change` on the select, so anything already listening
	 * to it keeps working. When scripting is unavailable the plain select is what
	 * renders, which is why it is hidden here rather than in the stylesheet.
	 *
	 * A list long enough to need one opens with a search field. Filtering hides
	 * the items it excludes rather than rebuilding the list, so an item's place
	 * in `items` keeps matching its option's index in the select.
	 *
	 * @param {HTMLElement} wrapper Container holding one select.
	 * @return {void}
	 */
	const enhanceFlagSelect = function ( wrapper ) {
		const select = wrapper.querySelector( 'select' );

		if ( ! select || ! select.options.length || wrapper.classList.contains( 'is-enhanced' ) ) {
			return;
		}

		const label = select.id ? document.querySelector( 'label[for="' + select.id + '"]' ) : null;
		const toggle = document.createElement( 'button' );
		const panel = document.createElement( 'div' );
		const list = document.createElement( 'ul' );
		const items = [];
		const haystacks = [];
		const searchable = select.options.length >= SEARCH_THRESHOLD;
		const search = searchable ? document.createElement( 'input' ) : null;
		const empty = searchable ? document.createElement( 'li' ) : null;
		let matches = [];
		let activeIndex = -1;
		let typed = '';
		let typedTimer = 0;

		toggle.type = 'button';
		toggle.className = 'localepress-flag-select__toggle';
		toggle.id = ( select.id || 'localepress-flag-select' ) + '-toggle';
		toggle.setAttribute( 'aria-haspopup', 'listbox' );
		toggle.setAttribute( 'aria-expanded', 'false' );

		panel.className = 'localepress-flag-select__panel';
		panel.hidden = true;

		list.className = 'localepress-flag-select__list';
		list.id = toggle.id + '-list';
		list.tabIndex = -1;
		list.setAttribute( 'role', 'listbox' );
		toggle.setAttribute( 'aria-controls', list.id );

		Array.prototype.forEach.call( select.options, function ( option, index ) {
			const item = document.createElement( 'li' );
			const text = document.createElement( 'span' );
			const name = option.textContent.trim();

			item.className = 'localepress-flag-select__option';
			item.id = list.id + '-' + index;
			item.setAttribute( 'role', 'option' );
			item.setAttribute( 'aria-selected', 'false' );

			text.className = 'localepress-flag-select__label';
			text.textContent = name;

			item.appendChild( buildFlag( option.dataset.flag ) );
			item.appendChild( text );
			list.appendChild( item );
			items.push( item );

			// A locale and a language code are what a reader knows a language by as
			// often as its name, so `bn`, `bn_BD` and `Bengali` all have to find it.
			haystacks.push(
				fold(
					[
						name,
						option.value,
						option.dataset.languageCode || '',
						option.dataset.urlSlug || '',
					].join( ' ' )
				)
			);
		} );

		if ( searchable ) {
			// Labels come from the wrapper because this script is shared by every
			// admin screen and is enqueued without a translation handle.
			const placeholder = wrapper.dataset.searchLabel || 'Search';

			search.type = 'search';
			search.className = 'localepress-flag-select__search';
			search.id = toggle.id + '-search';
			search.autocomplete = 'off';
			search.placeholder = placeholder;
			search.setAttribute( 'aria-label', placeholder );
			search.setAttribute( 'role', 'combobox' );
			search.setAttribute( 'aria-expanded', 'true' );
			search.setAttribute( 'aria-autocomplete', 'list' );
			search.setAttribute( 'aria-controls', list.id );

			empty.className = 'localepress-flag-select__empty';
			empty.setAttribute( 'role', 'presentation' );
			empty.textContent = wrapper.dataset.emptyText || 'No results found.';
			empty.hidden = true;

			panel.appendChild( search );
			list.appendChild( empty );
		}

		panel.appendChild( list );

		const renderToggle = function () {
			const option = select.options[ select.selectedIndex ];
			const text = document.createElement( 'span' );

			text.className = 'localepress-flag-select__label';
			text.textContent = option ? option.textContent.trim() : '';

			toggle.textContent = '';
			toggle.appendChild( buildFlag( option ? option.dataset.flag : '' ) );
			toggle.appendChild( text );
		};

		const syncSelected = function () {
			items.forEach( function ( item, index ) {
				const selected = index === select.selectedIndex;

				item.classList.toggle( 'is-selected', selected );
				item.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			} );
		};

		// Whichever element holds focus while the list is open is the one that
		// owns `aria-activedescendant`.
		const focusTarget = search || list;

		const setActive = function ( index ) {
			if ( activeIndex >= 0 && items[ activeIndex ] ) {
				items[ activeIndex ].classList.remove( 'is-active' );
			}

			activeIndex = -1;
			focusTarget.removeAttribute( 'aria-activedescendant' );

			if ( index < 0 || index >= items.length || items[ index ].hidden ) {
				return;
			}

			activeIndex = index;
			items[ index ].classList.add( 'is-active' );
			focusTarget.setAttribute( 'aria-activedescendant', items[ index ].id );

			if ( ! panel.hidden ) {
				items[ index ].scrollIntoView( { block: 'nearest' } );
			}
		};

		/**
		 * Moves the active option through what the filter left behind.
		 *
		 * @param {number} delta Steps to move; a negative value moves up.
		 * @return {void}
		 */
		const moveActive = function ( delta ) {
			if ( ! matches.length ) {
				return;
			}

			const current = matches.indexOf( activeIndex );
			const next = current < 0 ? ( 0 < delta ? 0 : matches.length - 1 ) : current + delta;

			setActive( matches[ Math.min( Math.max( next, 0 ), matches.length - 1 ) ] );
		};

		/**
		 * Shows the options a query matches and hides the rest.
		 *
		 * An empty query restores the whole list and keeps the current value in
		 * view. Anything else moves to the first match, so Enter takes what the
		 * reader is already looking at.
		 *
		 * @param {string} query Raw query text.
		 * @return {void}
		 */
		const filter = function ( query ) {
			const needle = fold( query.trim() );

			matches = [];

			items.forEach( function ( item, index ) {
				const hit = '' === needle || -1 !== haystacks[ index ].indexOf( needle );

				item.hidden = ! hit;

				if ( hit ) {
					matches.push( index );
				}
			} );

			if ( empty ) {
				empty.hidden = 0 !== matches.length;
			}

			if ( '' === needle && -1 !== matches.indexOf( select.selectedIndex ) ) {
				setActive( select.selectedIndex );

				return;
			}

			// Matching anywhere in the text is what makes a half-remembered name
			// findable, but a name that begins with the query is almost always the
			// one meant: `fr` leads on French rather than on Afrikaans.
			const leading = matches.find( function ( index ) {
				return 0 === haystacks[ index ].indexOf( needle );
			} );

			setActive( undefined === leading ? ( matches.length ? matches[ 0 ] : -1 ) : leading );
		};

		const close = function ( refocus ) {
			if ( panel.hidden ) {
				return;
			}

			panel.hidden = true;
			toggle.setAttribute( 'aria-expanded', 'false' );
			setActive( -1 );

			if ( refocus ) {
				toggle.focus();
			}
		};

		const open = function () {
			if ( ! panel.hidden ) {
				return;
			}

			panel.hidden = false;
			toggle.setAttribute( 'aria-expanded', 'true' );

			if ( search ) {
				// A fresh query every time: the narrowed list someone left behind is
				// rarely the one they open it for next.
				search.value = '';
			}

			filter( '' );
			focusTarget.focus();
		};

		const choose = function ( index ) {
			if ( index < 0 || index >= items.length ) {
				return;
			}

			select.selectedIndex = index;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			close( true );
		};

		// Mirrors the type-to-jump behavior of a native select. A list that has a
		// search field has no use for it, because those keys go to the field.
		const typeahead = function ( key ) {
			if ( 1 !== key.length ) {
				return;
			}

			window.clearTimeout( typedTimer );
			typed += key.toLowerCase();
			typedTimer = window.setTimeout( function () {
				typed = '';
			}, 600 );

			const current = Math.max( activeIndex, 0 );
			const start = typed.length > 1 ? current : current + 1;

			for ( let offset = 0; offset < items.length; offset++ ) {
				const index = ( start + offset ) % items.length;

				if ( 0 === items[ index ].textContent.trim().toLowerCase().indexOf( typed ) ) {
					setActive( index );
					return;
				}
			}
		};

		/**
		 * Handles the keys that drive the list, from the field or from the list.
		 *
		 * Home, End and Space are left alone when there is a search field: inside
		 * a text field those keys belong to the caret and to typing.
		 *
		 * @param {KeyboardEvent} event Key event.
		 * @return {void}
		 */
		const onKeydown = function ( event ) {
			if ( event.ctrlKey || event.metaKey || event.altKey ) {
				return;
			}

			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					moveActive( 1 );
					break;
				case 'ArrowUp':
					event.preventDefault();
					moveActive( -1 );
					break;
				case 'Home':
					if ( search || ! matches.length ) {
						break;
					}

					event.preventDefault();
					setActive( matches[ 0 ] );
					break;
				case 'End':
					if ( search || ! matches.length ) {
						break;
					}

					event.preventDefault();
					setActive( matches[ matches.length - 1 ] );
					break;
				case 'Enter':
					// Also keeps the surrounding form from being submitted by a query
					// that matched nothing.
					event.preventDefault();
					choose( activeIndex );
					break;
				case ' ':
					if ( search ) {
						break;
					}

					event.preventDefault();
					choose( activeIndex );
					break;
				case 'Escape':
					event.preventDefault();
					close( true );
					break;
				case 'Tab':
					close( false );
					break;
				default:
					if ( ! search ) {
						typeahead( event.key );
					}

					break;
			}
		};

		toggle.addEventListener( 'click', function () {
			if ( panel.hidden ) {
				open();
			} else {
				close( true );
			}
		} );

		toggle.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
				event.preventDefault();
				open();
			}
		} );

		if ( search ) {
			search.addEventListener( 'input', function () {
				filter( search.value );
			} );

			search.addEventListener( 'keydown', onKeydown );
		}

		list.addEventListener( 'keydown', onKeydown );

		list.addEventListener( 'click', function ( event ) {
			const item = event.target.closest( '.localepress-flag-select__option' );

			if ( item ) {
				choose( items.indexOf( item ) );
			}
		} );

		list.addEventListener( 'mousemove', function ( event ) {
			const item = event.target.closest( '.localepress-flag-select__option' );

			if ( item ) {
				setActive( items.indexOf( item ) );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! wrapper.contains( event.target ) ) {
				close( false );
			}
		} );

		select.addEventListener( 'change', function () {
			syncSelected();
			renderToggle();
		} );

		select.hidden = true;
		wrapper.classList.add( 'is-enhanced' );
		wrapper.appendChild( toggle );
		wrapper.appendChild( panel );

		if ( label ) {
			label.htmlFor = toggle.id;
		}

		filter( '' );
		syncSelected();
		renderToggle();
	};

	Array.prototype.forEach.call(
		document.querySelectorAll( '[data-localepress-flag-select]' ),
		enhanceFlagSelect
	);

	// The wizard shows a domain field per language, but only the separate-domain
	// format uses one. Without scripting every field stays visible and still saves.
	var urlModes = document.querySelector( '[data-localepress-url-mode]' );

	if ( urlModes ) {
		var panels = document.querySelectorAll( '[data-localepress-mode-panel]' );

		var syncModePanels = function () {
			var checked = urlModes.querySelector( 'input[type="radio"]:checked' );
			var mode = checked ? checked.value : '';

			Array.prototype.forEach.call( panels, function ( panel ) {
				panel.hidden = panel.dataset.localepressModePanel !== mode;
			} );
		};

		urlModes.addEventListener( 'change', syncModePanels );
		syncModePanels();
	}
	// Settings > Switcher prints the shortcode that reproduces whatever the
	// controls are set to. Rebuilding it as the controls move is what shows a
	// reader that the screen and the shortcode are the same set of options under
	// two spellings.
	const usage = document.querySelector( '[data-localepress-switcher-usage]' );

	if ( usage ) {
		// The order the options read in, fixed here rather than left to whichever
		// order the fields happen to be found in.
		const options = [
			[ 'display', document.getElementById( 'localepress-switcher-display' ) ],
			[ 'layout', document.getElementById( 'localepress-switcher-layout' ) ],
			[ 'unavailable_behavior', document.getElementById( 'localepress-unavailable-behavior' ) ],
			[ 'show_flags', document.querySelector( 'input[name="switcher[show_flags]"]' ) ],
			[ 'hide_current', document.querySelector( 'input[name="switcher[hide_current]"]' ) ],
			[ 'hide_missing', document.querySelector( 'input[name="switcher[hide_missing]"]' ) ],
			[ 'show_disabled', document.querySelector( 'input[name="switcher[show_disabled]"]' ) ],
		].filter( function ( option ) {
			return !! option[ 1 ];
		} );
		const shortcodeOutput = usage.querySelector( '[data-localepress-usage="shortcode"]' );

		/**
		 * Reads one control as the value the shortcode would carry.
		 *
		 * @param {HTMLElement} field Select or checkbox.
		 * @return {string} Option value.
		 */
		const readValue = function ( field ) {
			if ( 'checkbox' === field.type ) {
				return field.checked ? 'true' : 'false';
			}

			return field.value;
		};

		const render = function () {
			if ( ! shortcodeOutput ) {
				return;
			}

			let shortcode = '[localepress_switcher';

			options.forEach( function ( option ) {
				shortcode += ' ' + option[ 0 ] + '="' + readValue( option[ 1 ] ) + '"';
			} );

			shortcodeOutput.textContent = shortcode + ']';
		};

		options.forEach( function ( option ) {
			option[ 1 ].addEventListener( 'change', render );
		} );

		// Clipboard access needs a secure context. Where there is none the snippet
		// is still there to select by hand, so the button goes rather than sitting
		// on the screen doing nothing.
		if ( navigator.clipboard ) {
			usage.addEventListener( 'click', function ( event ) {
				const button = event.target.closest( '[data-localepress-copy]' );

				if ( ! button ) {
					return;
				}

				const target = usage.querySelector(
					'[data-localepress-usage="' + button.dataset.localepressCopy + '"]'
				);

				if ( ! target ) {
					return;
				}

				navigator.clipboard.writeText( target.textContent ).then( function () {
					const original = button.dataset.label || button.textContent;

					button.dataset.label = original;
					button.textContent = button.dataset.copied || original;
					window.clearTimeout( button.dataset.timer );
					button.dataset.timer = window.setTimeout( function () {
						button.textContent = original;
					}, 1500 );
				} );
			} );
		} else {
			Array.prototype.forEach.call(
				usage.querySelectorAll( '[data-localepress-copy]' ),
				function ( button ) {
					button.hidden = true;
				}
			);
		}

		render();
	}

	const preset = document.getElementById( 'localepress-language-preset' );

	if ( ! preset ) {
		return;
	}

	const fields = {
		name: document.getElementById( 'localepress-name' ),
		nativeName: document.getElementById( 'localepress-native_name' ),
		locale: document.getElementById( 'localepress-locale' ),
		languageCode: document.getElementById( 'localepress-language_code' ),
		urlSlug: document.getElementById( 'localepress-url_slug' ),
		isRtl: document.getElementById( 'localepress-is-rtl' ),
	};
	const missingField = Object.keys( fields ).some( function ( key ) {
		return ! fields[ key ];
	} );

	if ( missingField ) {
		return;
	}

	const applyPreset = function () {
		const option = preset.options[ preset.selectedIndex ];

		if ( ! option || ! option.value ) {
			return;
		}

		fields.name.value = option.dataset.name || '';
		fields.nativeName.value = option.dataset.nativeName || '';
		fields.locale.value = option.value;
		fields.languageCode.value = option.dataset.languageCode || '';
		fields.urlSlug.value = option.dataset.urlSlug || '';
		fields.isRtl.checked = option.dataset.isRtl === '1';
	};

	preset.addEventListener( 'change', applyPreset );

	if ( preset.value && ! fields.name.value ) {
		applyPreset();
	}
}() );
