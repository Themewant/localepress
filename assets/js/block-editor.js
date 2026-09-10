/**
 * Appends the edited post's language to filterable editor REST requests.
 *
 * The block editor loads parent pages, terms, and link search results over the
 * REST API. Without a language those lists mix every language, so the request is
 * tagged here and LocalePress constrains the collection on the server.
 *
 * @package LocalePress
 */
( function ( wp ) {
	'use strict';

	var config = window.localePressEditor;

	if ( ! wp || ! wp.apiFetch || ! config || ! config.routes || ! config.routes.length ) {
		return;
	}

	/**
	 * Reads the language currently selected in the editor.
	 *
	 * Falls back to the default language so a brand new post, which has no
	 * assignment yet, still receives a consistent list.
	 *
	 * @return {string} Language identifier, or an empty string when unknown.
	 */
	function currentLanguage() {
		var field = document.querySelector( '[name="' + config.field + '"]' );

		if ( field && field.value ) {
			return field.value;
		}

		return config.defaultLanguage || '';
	}

	/**
	 * Reports whether a request path is one LocalePress can constrain.
	 *
	 * Single-item routes end with an identifier and are left alone: fetching one
	 * known post must never depend on the language being edited.
	 *
	 * @param {string} path Request path.
	 * @return {boolean} True when the collection accepts a language.
	 */
	function isFilterable( path ) {
		var route = path.split( '?' )[ 0 ].replace( /^\/+|\/+$/g, '' );

		return config.routes.indexOf( route ) !== -1;
	}

	wp.apiFetch.use( function ( options, next ) {
		// A defined url means a direct call rather than a REST route.
		if ( typeof options.url !== 'undefined' || typeof options.path !== 'string' ) {
			return next( options );
		}

		if ( ! isFilterable( options.path ) ) {
			return next( options );
		}

		var language = currentLanguage();

		if ( ! language ) {
			return next( options );
		}

		if ( typeof options.data === 'undefined' || null === options.data ) {
			options.path +=
				( options.path.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'lang=' + encodeURIComponent( language );
		} else {
			options.data.lang = language;
		}

		return next( options );
	} );
} )( window.wp );
