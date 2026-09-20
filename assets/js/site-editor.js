/**
 * Language panel for the WordPress Site Editor.
 *
 * Names the language of the template part on screen, and lists the languages
 * beside it: the part where it exists, and the offer to start it where it does
 * not. This is the only place a template part's languages are managed, so the
 * work happens here rather than sending an editor to a settings screen.
 *
 * Plain ES5, like the rest of LocalePress: no build step, and `createElement`
 * rather than JSX for the same reason.
 *
 * @package LocalePress
 */
( function ( wp ) {
	'use strict';

	var config = window.localePressSiteEditor;

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.components || ! config ) {
		return;
	}

	/*
	 * WordPress 6.6 moved the editor slots to `wp.editor`, where both editors
	 * share them. Older releases keep the Site Editor's own copies, and a site
	 * on one of those is still supported.
	 */
	var editor = wp.editor || {};
	var editSite = wp.editSite || {};
	var PluginSidebar = editor.PluginSidebar || editSite.PluginSidebar;
	var PluginSidebarMoreMenuItem = editor.PluginSidebarMoreMenuItem || editSite.PluginSidebarMoreMenuItem;

	if ( ! PluginSidebar ) {
		return;
	}

	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var Notice = wp.components.Notice;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	/**
	 * Returns one of the editor's own icons, by name where it has them.
	 *
	 * Dashicon names are accepted by every version this plugin supports, and
	 * they are the icons the editor already draws everywhere else.
	 *
	 * @param {string} name Dashicon name.
	 * @return {string} Icon reference.
	 */
	function icon( name ) {
		return name;
	}

	/**
	 * Renders a language's flag, or its code when it has no flag.
	 *
	 * @param {Object} language Language entry.
	 * @return {Object} Element.
	 */
	function renderFlag( language ) {
		if ( language.flagUrl ) {
			return createElement( 'img', {
				className: 'localepress-site-editor__flag',
				src: language.flagUrl,
				alt: '',
				width: 18,
				height: 12,
				loading: 'lazy',
				decoding: 'async',
			} );
		}

		return createElement(
			'abbr',
			{ className: 'localepress-site-editor__code', title: language.name },
			language.code
		);
	}

	/**
	 * Renders the language the open part belongs to.
	 *
	 * @return {Object|null} Element.
	 */
	function renderCurrent() {
		var current = null;
		var index;

		for ( index = 0; index < config.languages.length; index++ ) {
			if ( config.languages[ index ].isCurrent ) {
				current = config.languages[ index ];
				break;
			}
		}

		if ( ! current ) {
			return null;
		}

		return createElement(
			'div',
			{ className: 'localepress-site-editor__section' },
			createElement( 'h2', { className: 'localepress-site-editor__heading' }, __( 'Language', 'localepress' ) ),
			createElement(
				'div',
				{ className: 'localepress-site-editor__current' },
				renderFlag( current ),
				createElement( 'span', null, current.nativeName )
			)
		);
	}

	/**
	 * The panel.
	 *
	 * @return {Object} Element.
	 */
	function Panel() {
		var busyState = useState( '' );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		/**
		 * Starts a language's version of the open part, then opens it.
		 *
		 * @param {Object} language Language to create.
		 * @return {void}
		 */
		function addTranslation( language ) {
			setError( '' );
			setBusy( language.id );

			wp.apiFetch( {
				path: '/' + config.route,
				method: 'POST',
				data: { slug: config.slug, language: language.id },
			} ).then(
				function ( response ) {
					if ( response && response.editUrl ) {
						window.location.href = response.editUrl;
						return;
					}

					window.location.reload();
				},
				function ( response ) {
					setBusy( '' );
					setError(
						response && response.message
							? response.message
							: __( 'LocalePress could not create that template part.', 'localepress' )
					);
				}
			);
		}

		/**
		 * Removes a language's version of the open part.
		 *
		 * @param {Object} language Language to remove.
		 * @return {void}
		 */
		function deleteTranslation( language ) {
			var question = sprintf(
				/* translators: %s: language name. */
				__( 'Delete the %s version of this template part? This cannot be undone.', 'localepress' ),
				language.nativeName
			);

			if ( language.isDefault ) {
				question += '\n\n' + __(
					'This is the version every other language falls back to, so its translations are deleted with it.',
					'localepress'
				);
			}

			// eslint-disable-next-line no-alert
			if ( ! window.confirm( question ) ) {
				return;
			}

			setError( '' );
			setBusy( language.id );

			wp.apiFetch( {
				path: '/' + config.route,
				method: 'DELETE',
				data: { slug: config.slug, language: language.id },
			} ).then(
				function ( response ) {
					// The part on screen may be the one just deleted.
					if ( language.isCurrent && response && response.editUrl ) {
						window.location.href = response.editUrl;
						return;
					}

					window.location.reload();
				},
				function ( response ) {
					setBusy( '' );
					setError(
						response && response.message
							? response.message
							: __( 'LocalePress could not delete that template part.', 'localepress' )
					);
				}
			);
		}

		/**
		 * Renders one language row.
		 *
		 * @param {Object} language Language entry.
		 * @return {Object} Element.
		 */
		function renderRow( language ) {
			var working = busy === language.id;
			var cells = [
				createElement(
					'th',
					{ key: 'flag', className: 'localepress-site-editor__flag-cell', scope: 'row' },
					renderFlag( language )
				),
				createElement(
					'td',
					{ key: 'name', className: 'localepress-site-editor__name-cell' },
					language.nativeName
				),
			];

			var action;

			if ( working ) {
				action = createElement( Spinner, null );
			} else if ( language.slug ) {
				action = createElement( Button, {
					icon: icon( 'edit' ),
					href: language.editUrl,
					disabled: ! language.editUrl,
					className: 'localepress-site-editor__button is-edit',
					label: sprintf(
						/* translators: %s: language name. */
						__( 'Edit the %s version', 'localepress' ),
						language.nativeName
					),
					showTooltip: true,
				} );
			} else {
				action = createElement( Button, {
					icon: icon( 'plus-alt2' ),
					disabled: ! config.canManage,
					className: 'localepress-site-editor__button is-add',
					label: sprintf(
						/* translators: %s: language name. */
						__( 'Add a translation in %s', 'localepress' ),
						language.nativeName
					),
					showTooltip: true,
					onClick: function () {
						addTranslation( language );
					},
				} );
			}

			cells.push(
				createElement( 'td', { key: 'action', className: 'localepress-site-editor__icon-cell' }, action )
			);

			cells.push(
				createElement(
					'td',
					{ key: 'delete', className: 'localepress-site-editor__icon-cell' },
					createElement( Button, {
						icon: icon( 'trash' ),
						disabled: working || ! language.isDeletable || ! config.canManage,
						className: 'localepress-site-editor__button is-delete',
						label: sprintf(
							/* translators: %s: language name. */
							__( 'Delete the %s version', 'localepress' ),
							language.nativeName
						),
						showTooltip: true,
						onClick: function () {
							deleteTranslation( language );
						},
					} )
				)
			);

			cells.push(
				createElement(
					'td',
					{ key: 'default', className: 'localepress-site-editor__icon-cell' },
					language.isDefault
						? createElement(
								'span',
								{
									className: 'localepress-site-editor__default',
									title: __( 'Default language', 'localepress' ),
								},
								createElement(
									'span',
									{ className: 'screen-reader-text' },
									__( 'Default language', 'localepress' )
								)
						  )
						: null
				)
			);

			return createElement(
				'tr',
				{ key: language.id, className: language.isCurrent ? 'is-current' : '' },
				cells
			);
		}

		var others = config.languages.filter( function ( language ) {
			return ! language.isCurrent;
		} );

		var body;

		if ( ! config.isPart ) {
			body = createElement(
				'p',
				{ className: 'localepress-site-editor__empty' },
				__( 'Open a template part to see which language it belongs to.', 'localepress' )
			);
		} else {
			body = createElement(
				Fragment,
				null,
				renderCurrent(),
				createElement(
					'div',
					{ className: 'localepress-site-editor__section' },
					createElement(
						'h2',
						{ className: 'localepress-site-editor__heading' },
						__( 'Translations', 'localepress' )
					),
					others.length
						? createElement(
								'table',
								{ className: 'localepress-site-editor__table' },
								createElement( 'tbody', null, others.map( renderRow ) )
						  )
						: createElement(
								'p',
								{ className: 'localepress-site-editor__empty' },
								__( 'This site publishes one language.', 'localepress' )
						  )
				)
			);
		}

		return createElement(
			Fragment,
			null,
			PluginSidebarMoreMenuItem
				? createElement(
						PluginSidebarMoreMenuItem,
						{ target: 'localepress-languages', icon: icon( 'translation' ) },
						__( 'Languages', 'localepress' )
				  )
				: null,
			createElement(
				PluginSidebar,
				{
					name: 'localepress-languages',
					title: __( 'Languages', 'localepress' ),
					// Without an icon the sidebar gets no toolbar button of its own.
					icon: icon( 'translation' ),
					className: 'localepress-site-editor',
				},
				error
					? createElement(
							Notice,
							{
								status: 'error',
								isDismissible: true,
								onRemove: function () {
									setError( '' );
								},
							},
							error
					  )
					: null,
				body
			)
		);
	}

	wp.plugins.registerPlugin( 'localepress-site-editor', {
		render: Panel,
		icon: icon( 'translation' ),
	} );
} )( window.wp );
