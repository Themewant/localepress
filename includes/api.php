<?php
/**
 * Public developer API for themes and plugins.
 *
 * Every function here is a thin, guarded wrapper over the LocalePress services.
 * None of them fail when LocalePress is deactivated, has not booted yet, or has
 * no languages configured: they return an empty value instead. Integrators
 * should still wrap calls in function_exists() so their theme or plugin keeps
 * working when LocalePress is not installed at all.
 *
 * A "language reference" accepted by these functions may be a URL slug (`bn`),
 * a language code (`bn`), a WordPress locale (`bn_BD`), a stable language ID,
 * or a full language record. An empty reference always means the language of
 * the current request.
 *
 * Call these on or after `init`. The `localepress_loaded` action fires once the
 * services are available.
 *
 * @package LocalePress
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'localepress_is_active' ) ) {
	/**
	 * Reports whether LocalePress has booted and holds at least one language.
	 *
	 * Use this as the single guard around multilingual code paths.
	 *
	 * @return bool
	 */
	function localepress_is_active() {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return false;
		}

		$manager = LocalePress\Plugin::instance()->languages();

		return $manager instanceof LocalePress\Language\LanguageManager && $manager->has_languages();
	}
}

if ( ! function_exists( 'localepress_get_language' ) ) {
	/**
	 * Resolves any language reference to its full language record.
	 *
	 * A record contains `id`, `name`, `native_name`, `locale`, `language_code`,
	 * `url_slug`, `is_rtl`, and `enabled`.
	 *
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return array<string, mixed>|null Language record, or null when unknown.
	 */
	function localepress_get_language( $language = '' ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return null;
		}

		$plugin  = LocalePress\Plugin::instance();
		$manager = $plugin->languages();

		if ( ! $manager instanceof LocalePress\Language\LanguageManager ) {
			return null;
		}

		if ( is_array( $language ) && isset( $language['id'] ) ) {
			$language = $language['id'];
		}

		$reference = is_scalar( $language ) ? trim( (string) $language ) : '';

		if ( '' === $reference ) {
			return $plugin->current_language();
		}

		$urls = $plugin->urls();

		if ( $urls instanceof LocalePress\Routing\LanguageUrlManager ) {
			$record = $urls->resolve_language( $reference );

			if ( is_array( $record ) ) {
				return $record;
			}
		}

		// Locale references, and any request made before routing has booted.
		$normalized = strtolower( str_replace( '-', '_', $reference ) );

		foreach ( $manager->get_languages() as $record ) {
			foreach ( array( 'id', 'url_slug', 'language_code', 'locale' ) as $field ) {
				if (
					isset( $record[ $field ] )
					&& is_scalar( $record[ $field ] )
					&& strtolower( str_replace( '-', '_', (string) $record[ $field ] ) ) === $normalized
				) {
					return $record;
				}
			}
		}

		return null;
	}
}

if ( ! function_exists( 'localepress_get_language_field' ) ) {
	/**
	 * Reads one field from a language reference or record.
	 *
	 * Accepted fields: `slug` (default), `id`, `code`, `locale`, `name`,
	 * `native_name`, `is_rtl`, `enabled`, and `all` for the whole record.
	 *
	 * @param mixed  $language Language reference or record.
	 * @param string $field    Requested field.
	 * @return mixed Field value, false for booleans, or an empty string.
	 */
	function localepress_get_language_field( $language, $field = 'slug' ) {
		$record = is_array( $language ) && isset( $language['url_slug'] )
			? $language
			: localepress_get_language( $language );

		$field = is_scalar( $field ) ? strtolower( trim( (string) $field ) ) : 'slug';

		if ( in_array( $field, array( 'is_rtl', 'rtl', 'enabled' ), true ) ) {
			$key = 'rtl' === $field ? 'is_rtl' : $field;

			return null !== $record && ! empty( $record[ $key ] );
		}

		if ( null === $record ) {
			return '';
		}

		if ( in_array( $field, array( '', 'all', 'record' ), true ) ) {
			return $record;
		}

		$map = array(
			'id'            => 'id',
			'slug'          => 'url_slug',
			'url_slug'      => 'url_slug',
			'code'          => 'language_code',
			'language_code' => 'language_code',
			'locale'        => 'locale',
			'name'          => 'name',
			'native_name'   => 'native_name',
		);

		$key = isset( $map[ $field ] ) ? $map[ $field ] : $field;

		return isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ? (string) $record[ $key ] : '';
	}
}

if ( ! function_exists( 'localepress_current_language' ) ) {
	/**
	 * Returns the language of the current request.
	 *
	 * @param string $field Field to return. See localepress_get_language_field().
	 * @return mixed Field value, or an empty string when unavailable.
	 */
	function localepress_current_language( $field = 'slug' ) {
		return localepress_get_language_field( localepress_get_language(), $field );
	}
}

if ( ! function_exists( 'localepress_default_language' ) ) {
	/**
	 * Returns the configured default language.
	 *
	 * @param string $field Field to return. See localepress_get_language_field().
	 * @return mixed Field value, or an empty string when unavailable.
	 */
	function localepress_default_language( $field = 'slug' ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return localepress_get_language_field( null, $field );
		}

		$manager = LocalePress\Plugin::instance()->languages();
		$record  = $manager instanceof LocalePress\Language\LanguageManager
			? $manager->get_default_language()
			: null;

		return localepress_get_language_field( $record, $field );
	}
}

if ( ! function_exists( 'localepress_languages_list' ) ) {
	/**
	 * Returns the registered languages in their configured order.
	 *
	 * Arguments:
	 * - `fields`        Field to return per language, or `all` for full records.
	 *                   Defaults to `slug`.
	 * - `hide_disabled` Whether to skip disabled languages. Defaults to true.
	 *
	 * @param array<string, mixed> $args Optional arguments.
	 * @return array<int, mixed>
	 */
	function localepress_languages_list( $args = array() ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return array();
		}

		$manager = LocalePress\Plugin::instance()->languages();

		if ( ! $manager instanceof LocalePress\Language\LanguageManager ) {
			return array();
		}

		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'fields'        => 'slug',
				'hide_disabled' => true,
			)
		);

		$languages = $manager->get_languages( ! empty( $args['hide_disabled'] ) );
		$field     = is_scalar( $args['fields'] ) ? strtolower( trim( (string) $args['fields'] ) ) : 'slug';

		if ( in_array( $field, array( '', 'all', 'record' ), true ) ) {
			return $languages;
		}

		$values = array();

		foreach ( $languages as $language ) {
			$values[] = localepress_get_language_field( $language, $field );
		}

		return $values;
	}
}

if ( ! function_exists( 'localepress_is_rtl' ) ) {
	/**
	 * Reports whether a language is written right to left.
	 *
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return bool
	 */
	function localepress_is_rtl( $language = '' ) {
		return (bool) localepress_get_language_field( $language, 'is_rtl' );
	}
}

if ( ! function_exists( 'localepress_home_url' ) ) {
	/**
	 * Returns the site home URL for one language.
	 *
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return string Home URL, or an empty string when unavailable.
	 */
	function localepress_home_url( $language = '' ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return '';
		}

		$urls   = LocalePress\Plugin::instance()->urls();
		$record = localepress_get_language( $language );

		if ( ! $urls instanceof LocalePress\Routing\LanguageUrlManager || null === $record ) {
			return '';
		}

		return $urls->get_language_home_url( $record );
	}
}

if ( ! function_exists( 'localepress_get_post' ) ) {
	/**
	 * Returns the translated post for one language.
	 *
	 * @param int   $post_id  Post identifier.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return int Translated post ID, or zero when there is none.
	 */
	function localepress_get_post( $post_id, $language = '' ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return 0;
		}

		$manager = LocalePress\Plugin::instance()->translations();
		$record  = localepress_get_language( $language );

		if ( ! $manager instanceof LocalePress\Content\PostTranslationManager || null === $record ) {
			return 0;
		}

		return absint( $manager->get_translation( absint( $post_id ), (string) $record['id'] ) );
	}
}

if ( ! function_exists( 'localepress_get_post_language' ) ) {
	/**
	 * Returns the language assigned to a post.
	 *
	 * @param int    $post_id Post identifier.
	 * @param string $field   Field to return. See localepress_get_language_field().
	 * @return mixed Field value, or an empty string when unassigned.
	 */
	function localepress_get_post_language( $post_id, $field = 'slug' ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return localepress_get_language_field( null, $field );
		}

		$manager = LocalePress\Plugin::instance()->translations();
		$record  = $manager instanceof LocalePress\Content\PostTranslationManager
			? $manager->get_post_language( absint( $post_id ) )
			: null;

		return localepress_get_language_field( $record, $field );
	}
}

if ( ! function_exists( 'localepress_get_post_translations' ) ) {
	/**
	 * Returns every post in a translation group, keyed by language URL slug.
	 *
	 * The requested post is included in the returned map.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string, int>
	 */
	function localepress_get_post_translations( $post_id ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return array();
		}

		$manager = LocalePress\Plugin::instance()->translations();

		if ( ! $manager instanceof LocalePress\Content\PostTranslationManager ) {
			return array();
		}

		return localepress_key_translations_by_slug( $manager->get_translations( absint( $post_id ) ) );
	}
}

if ( ! function_exists( 'localepress_set_post_language' ) ) {
	/**
	 * Assigns a language to a post.
	 *
	 * @param int   $post_id  Post identifier.
	 * @param mixed $language Language reference.
	 * @return array<string, mixed>|WP_Error Stored assignment, or an error.
	 */
	function localepress_set_post_language( $post_id, $language ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return localepress_api_unavailable_error();
		}

		$manager = LocalePress\Plugin::instance()->translations();

		if ( ! $manager instanceof LocalePress\Content\PostTranslationManager ) {
			return localepress_api_unavailable_error();
		}

		$record = localepress_get_language( $language );

		if ( null === $record ) {
			return localepress_api_unknown_language_error();
		}

		return $manager->set_post_language( absint( $post_id ), (string) $record['id'] );
	}
}

if ( ! function_exists( 'localepress_save_post_translations' ) ) {
	/**
	 * Links posts as translations of one another.
	 *
	 * Keys may be URL slugs, language codes, locales, or language IDs.
	 *
	 *     localepress_save_post_translations( array( 'en' => 12, 'bn' => 34 ) );
	 *
	 * @param array<string, int> $translations   Post IDs keyed by language reference.
	 * @param int                $source_post_id Optional source post for a new group.
	 * @return array<string, mixed>|WP_Error Stored group, or an error.
	 */
	function localepress_save_post_translations( $translations, $source_post_id = 0 ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return localepress_api_unavailable_error();
		}

		$manager = LocalePress\Plugin::instance()->translations();

		if ( ! $manager instanceof LocalePress\Content\PostTranslationManager ) {
			return localepress_api_unavailable_error();
		}

		$resolved = localepress_key_translations_by_id( $translations );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		return $manager->link_translations( $resolved, absint( $source_post_id ) );
	}
}

if ( ! function_exists( 'localepress_get_term' ) ) {
	/**
	 * Returns the translated term for one language.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param mixed  $language Optional language reference. Empty means current.
	 * @param string $taxonomy Optional taxonomy. Detected from the term when omitted.
	 * @return int Translated term ID, or zero when there is none.
	 */
	function localepress_get_term( $term_id, $language = '', $taxonomy = '' ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return 0;
		}

		$manager  = LocalePress\Plugin::instance()->term_translations();
		$record   = localepress_get_language( $language );
		$taxonomy = localepress_resolve_term_taxonomy( $term_id, $taxonomy );

		if (
			! $manager instanceof LocalePress\Taxonomy\TermTranslationManager
			|| null === $record
			|| '' === $taxonomy
		) {
			return 0;
		}

		return absint( $manager->get_translation( absint( $term_id ), $taxonomy, (string) $record['id'] ) );
	}
}

if ( ! function_exists( 'localepress_get_term_language' ) ) {
	/**
	 * Returns the language assigned to a term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $field    Field to return. See localepress_get_language_field().
	 * @param string $taxonomy Optional taxonomy. Detected from the term when omitted.
	 * @return mixed Field value, or an empty string when unassigned.
	 */
	function localepress_get_term_language( $term_id, $field = 'slug', $taxonomy = '' ) {
		$record = null;

		if ( class_exists( 'LocalePress\\Plugin' ) ) {
			$manager  = LocalePress\Plugin::instance()->term_translations();
			$taxonomy = localepress_resolve_term_taxonomy( $term_id, $taxonomy );

			if ( $manager instanceof LocalePress\Taxonomy\TermTranslationManager && '' !== $taxonomy ) {
				$record = $manager->get_term_language( absint( $term_id ), $taxonomy );
			}
		}

		return localepress_get_language_field( $record, $field );
	}
}

if ( ! function_exists( 'localepress_get_term_translations' ) ) {
	/**
	 * Returns every term in a translation group, keyed by language URL slug.
	 *
	 * The requested term is included in the returned map.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Optional taxonomy. Detected from the term when omitted.
	 * @return array<string, int>
	 */
	function localepress_get_term_translations( $term_id, $taxonomy = '' ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return array();
		}

		$manager  = LocalePress\Plugin::instance()->term_translations();
		$taxonomy = localepress_resolve_term_taxonomy( $term_id, $taxonomy );

		if ( ! $manager instanceof LocalePress\Taxonomy\TermTranslationManager || '' === $taxonomy ) {
			return array();
		}

		return localepress_key_translations_by_slug( $manager->get_translations( absint( $term_id ), $taxonomy ) );
	}
}

if ( ! function_exists( 'localepress_set_term_language' ) ) {
	/**
	 * Assigns a language to a term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param mixed  $language Language reference.
	 * @param string $taxonomy Optional taxonomy. Detected from the term when omitted.
	 * @return array<string, mixed>|WP_Error Stored assignment, or an error.
	 */
	function localepress_set_term_language( $term_id, $language, $taxonomy = '' ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return localepress_api_unavailable_error();
		}

		$manager  = LocalePress\Plugin::instance()->term_translations();
		$taxonomy = localepress_resolve_term_taxonomy( $term_id, $taxonomy );

		if ( ! $manager instanceof LocalePress\Taxonomy\TermTranslationManager || '' === $taxonomy ) {
			return localepress_api_unavailable_error();
		}

		$record = localepress_get_language( $language );

		if ( null === $record ) {
			return localepress_api_unknown_language_error();
		}

		return $manager->set_term_language( absint( $term_id ), $taxonomy, (string) $record['id'] );
	}
}

if ( ! function_exists( 'localepress_save_term_translations' ) ) {
	/**
	 * Links terms as translations of one another.
	 *
	 * Keys may be URL slugs, language codes, locales, or language IDs.
	 *
	 *     localepress_save_term_translations( array( 'en' => 5, 'bn' => 9 ) );
	 *
	 * @param array<string, int> $translations   Term IDs keyed by language reference.
	 * @param string             $taxonomy       Optional taxonomy. Detected when omitted.
	 * @param int                $source_term_id Optional source term for a new group.
	 * @return array<string, mixed>|WP_Error Stored group, or an error.
	 */
	function localepress_save_term_translations( $translations, $taxonomy = '', $source_term_id = 0 ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return localepress_api_unavailable_error();
		}

		$manager = LocalePress\Plugin::instance()->term_translations();

		if ( ! $manager instanceof LocalePress\Taxonomy\TermTranslationManager ) {
			return localepress_api_unavailable_error();
		}

		$resolved = localepress_key_translations_by_id( $translations );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$first    = (int) reset( $resolved );
		$taxonomy = localepress_resolve_term_taxonomy( $first, $taxonomy );

		if ( '' === $taxonomy ) {
			return new WP_Error(
				'localepress_unknown_taxonomy',
				__( 'The taxonomy for these terms could not be determined.', 'localepress' )
			);
		}

		return $manager->link_translations( $resolved, $taxonomy, absint( $source_term_id ) );
	}
}

if ( ! function_exists( 'localepress_is_translated_post_type' ) ) {
	/**
	 * Reports whether a post type participates in translation.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	function localepress_is_translated_post_type( $post_type ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return false;
		}

		$manager = LocalePress\Plugin::instance()->translations();

		return $manager instanceof LocalePress\Content\PostTranslationManager
			&& $manager->supports_post_type( $post_type );
	}
}

if ( ! function_exists( 'localepress_is_translated_taxonomy' ) ) {
	/**
	 * Reports whether a taxonomy participates in translation.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	function localepress_is_translated_taxonomy( $taxonomy ) {
		if ( ! class_exists( 'LocalePress\\Plugin' ) ) {
			return false;
		}

		$manager = LocalePress\Plugin::instance()->term_translations();

		return $manager instanceof LocalePress\Taxonomy\TermTranslationManager
			&& $manager->supports_taxonomy( $taxonomy );
	}
}

if ( ! function_exists( 'localepress_object_id' ) ) {
	/**
	 * Returns the translated counterpart of a post or a term.
	 *
	 * This is the single call a theme, a page builder, or a header and footer
	 * builder needs so that a stored object ID follows the language being
	 * rendered. It deliberately mirrors the shape those integrations already use
	 * for other multilingual plugins, so an existing branch can gain LocalePress
	 * support without being restructured:
	 *
	 *     if ( function_exists( 'localepress_object_id' ) ) {
	 *         $header_id = localepress_object_id( $header_id, 'elementor_library' );
	 *     }
	 *
	 * `$type` may name a post type or a taxonomy; taxonomies are recognised by
	 * name. When the object is already in the requested language its own ID
	 * comes back, so the call is safe to make on every request without first
	 * checking which language is current.
	 *
	 * Unlike localepress_get_post() and localepress_get_term(), which report a
	 * missing translation as zero, this function returns the original ID by
	 * default. A builder that swapped in zero would render nothing at all, which
	 * is a worse outcome than showing the untranslated template.
	 *
	 * @param int    $object_id                  Post or term identifier.
	 * @param string $type                       Post type or taxonomy name. Defaults to `post`.
	 * @param bool   $return_original_if_missing Whether to fall back to $object_id when
	 *                                           there is no translation. Defaults to true.
	 * @param mixed  $language                   Optional language reference. Empty means current.
	 * @return int|null Translated object ID, the original ID, or null.
	 */
	function localepress_object_id( $object_id, $type = 'post', $return_original_if_missing = true, $language = '' ) {
		$object_id = absint( $object_id );
		$type      = is_scalar( $type ) ? (string) $type : 'post';
		$fallback  = $return_original_if_missing ? $object_id : null;

		if ( 0 === $object_id ) {
			return $fallback;
		}

		$translated = taxonomy_exists( $type )
			? localepress_get_term( $object_id, $language, $type )
			: localepress_get_post( $object_id, $language );

		return $translated > 0 ? $translated : $fallback;
	}
}

if ( ! function_exists( 'localepress_the_languages' ) ) {
	/**
	 * Renders the language switcher.
	 *
	 * Accepts every argument of localepress_get_language_switcher(), plus `echo`
	 * to return the markup instead of printing it.
	 *
	 * @param array<string, mixed> $args Optional switcher arguments.
	 * @return string Markup when `echo` is false, otherwise an empty string.
	 */
	function localepress_the_languages( $args = array() ) {
		$args  = is_array( $args ) ? $args : array();
		$print = ! isset( $args['echo'] ) || (bool) $args['echo'];

		unset( $args['echo'] );

		$markup = function_exists( 'localepress_get_language_switcher' )
			? localepress_get_language_switcher( $args )
			: '';

		if ( ! $print ) {
			return $markup;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped in LanguageSwitcher::render().
		echo $markup;

		return '';
	}
}

if ( ! function_exists( 'localepress__' ) ) {
	/**
	 * Returns a registered string for one language.
	 *
	 * Registered strings are stored as plain text, so the caller decides how to
	 * escape the result.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return string
	 */
	function localepress__( $group, $key, $fallback = '', $language = '' ) {
		if ( ! function_exists( 'localepress_translate_string' ) ) {
			return is_scalar( $fallback ) ? (string) $fallback : '';
		}

		$record      = '' === $language ? null : localepress_get_language( $language );
		$language_id = null === $record ? '' : (string) $record['id'];

		return localepress_translate_string( $group, $key, $fallback, $language_id );
	}
}

if ( ! function_exists( 'localepress_e' ) ) {
	/**
	 * Echoes a registered string, escaped for HTML output.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return void
	 */
	function localepress_e( $group, $key, $fallback = '', $language = '' ) {
		echo esc_html( localepress__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'localepress_esc_html__' ) ) {
	/**
	 * Returns a registered string escaped for HTML output.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return string
	 */
	function localepress_esc_html__( $group, $key, $fallback = '', $language = '' ) {
		return esc_html( localepress__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'localepress_esc_html_e' ) ) {
	/**
	 * Echoes a registered string escaped for HTML output.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return void
	 */
	function localepress_esc_html_e( $group, $key, $fallback = '', $language = '' ) {
		echo esc_html( localepress__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'localepress_esc_attr__' ) ) {
	/**
	 * Returns a registered string escaped for an HTML attribute.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return string
	 */
	function localepress_esc_attr__( $group, $key, $fallback = '', $language = '' ) {
		return esc_attr( localepress__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'localepress_esc_attr_e' ) ) {
	/**
	 * Echoes a registered string escaped for an HTML attribute.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return void
	 */
	function localepress_esc_attr_e( $group, $key, $fallback = '', $language = '' ) {
		echo esc_attr( localepress__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'localepress_resolve_term_taxonomy' ) ) {
	/**
	 * Returns the taxonomy for a term, reading it from the term when omitted.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Provided taxonomy, if any.
	 * @return string Taxonomy name, or an empty string when unresolved.
	 */
	function localepress_resolve_term_taxonomy( $term_id, $taxonomy = '' ) {
		$taxonomy = is_scalar( $taxonomy ) ? sanitize_key( (string) $taxonomy ) : '';

		if ( '' !== $taxonomy ) {
			return $taxonomy;
		}

		$term = get_term( absint( $term_id ) );

		return $term instanceof WP_Term ? $term->taxonomy : '';
	}
}

if ( ! function_exists( 'localepress_key_translations_by_slug' ) ) {
	/**
	 * Rewrites a language-ID keyed translation map to URL slug keys.
	 *
	 * @param array<string, int> $translations Object IDs keyed by language ID.
	 * @return array<string, int>
	 */
	function localepress_key_translations_by_slug( $translations ) {
		$mapped = array();

		foreach ( (array) $translations as $language_id => $object_id ) {
			$slug = (string) localepress_get_language_field( (string) $language_id, 'slug' );

			if ( '' !== $slug ) {
				$mapped[ $slug ] = absint( $object_id );
			}
		}

		return $mapped;
	}
}

if ( ! function_exists( 'localepress_key_translations_by_id' ) ) {
	/**
	 * Rewrites a caller-supplied translation map to language-ID keys.
	 *
	 * @param array<string, int> $translations Object IDs keyed by language reference.
	 * @return array<string, int>|WP_Error
	 */
	function localepress_key_translations_by_id( $translations ) {
		if ( ! is_array( $translations ) || empty( $translations ) ) {
			return new WP_Error(
				'localepress_empty_translations',
				__( 'Provide at least one object keyed by language.', 'localepress' )
			);
		}

		$mapped = array();

		foreach ( $translations as $language => $object_id ) {
			$record = localepress_get_language( $language );

			if ( null === $record ) {
				return localepress_api_unknown_language_error();
			}

			$mapped[ (string) $record['id'] ] = absint( $object_id );
		}

		return $mapped;
	}
}

if ( ! function_exists( 'localepress_api_unavailable_error' ) ) {
	/**
	 * Returns the shared error used when LocalePress services are unavailable.
	 *
	 * @return WP_Error
	 */
	function localepress_api_unavailable_error() {
		return new WP_Error(
			'localepress_not_ready',
			__( 'LocalePress is not available yet. Call this on init or later.', 'localepress' )
		);
	}
}

if ( ! function_exists( 'localepress_api_unknown_language_error' ) ) {
	/**
	 * Returns the shared error used for an unresolved language reference.
	 *
	 * @return WP_Error
	 */
	function localepress_api_unknown_language_error() {
		return new WP_Error(
			'localepress_unknown_language',
			__( 'That language is not registered in LocalePress.', 'localepress' )
		);
	}
}
