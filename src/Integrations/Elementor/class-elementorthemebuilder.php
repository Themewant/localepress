<?php
/**
 * Elementor Theme Builder language resolution.
 *
 * @package LocalePress
 */

namespace LocalePress\Integrations\Elementor;

use LocalePress\Content\PostTranslationManager;
use LocalePress\Taxonomy\TermTranslationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the Theme Builder state that names another object.
 *
 * A Theme Builder template is chosen by its display conditions, and a condition
 * is a path: `include/singular/page/42`. Two things in that path belong to one
 * language. The template the condition was saved on is written in a language,
 * and `42` is a post or a term that has a translation of its own. Copying such a
 * template without rewriting either leaves a Bengali header pointing at an
 * English page, and two headers claiming the whole site at once.
 *
 * This class answers both questions and nothing else; deciding when to ask them
 * is ElementorThemeBuilderModule's job.
 */
final class ElementorThemeBuilder {

	/**
	 * Display conditions meta key.
	 */
	const CONDITIONS_META_KEY = '_elementor_conditions';

	/**
	 * Popup trigger and display rules meta key.
	 */
	const POPUP_SETTINGS_META_KEY = '_elementor_popup_display_settings';

	/**
	 * Theme location meta key used by the generic section document.
	 */
	const LOCATION_META_KEY = '_elementor_location';

	/**
	 * Sub-conditions whose identifier names a user rather than content.
	 *
	 * @var array<int, string>
	 */
	const AUTHOR_SUB_CONDITIONS = array( 'author', 'by_author' );

	/**
	 * Sub-conditions whose identifier names a post without naming its type.
	 *
	 * @var array<int, string>
	 */
	const POST_SUB_CONDITIONS = array( 'child_of', 'any_child_of' );

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation relationships.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 */
	public function __construct(
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations
	) {
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
	}

	/**
	 * Rewrites a stored condition list for one language.
	 *
	 * @param array<int, mixed> $conditions  Stored display conditions.
	 * @param string            $language_id Language the conditions are moving to.
	 * @return array<int, mixed> Conditions in the same order.
	 */
	public function translate_conditions( array $conditions, $language_id ) {
		$language_id = (string) $language_id;

		if ( '' === $language_id ) {
			return $conditions;
		}

		foreach ( $conditions as $index => $condition ) {
			if ( is_string( $condition ) ) {
				$conditions[ $index ] = $this->translate_condition( $condition, $language_id );
			}
		}

		return $conditions;
	}

	/**
	 * Rewrites one condition path for a language.
	 *
	 * A condition that names no object, or names one with no translation, comes
	 * back unchanged: the original identifier is a better answer than an empty
	 * condition, which would silently widen the template to every page.
	 *
	 * @param string $condition   Stored condition path.
	 * @param string $language_id Target language identifier.
	 * @return string
	 */
	public function translate_condition( $condition, $language_id ) {
		if ( ! is_string( $condition ) || '' === $condition ) {
			return $condition;
		}

		$parts = explode( '/', $condition );

		// A condition with fewer than four segments names no object at all:
		// `include/general`, `include/archive`, `include/singular/post`.
		if ( 4 > count( $parts ) ) {
			return $condition;
		}

		$translated = $this->translate_object_id( $parts[3], $parts[1], $parts[2], $language_id );

		if ( 0 === $translated || (string) $translated === (string) $parts[3] ) {
			return $condition;
		}

		$parts[3] = (string) $translated;

		return implode( '/', $parts );
	}

	/**
	 * Returns the translation of the object one condition segment names.
	 *
	 * @param string|int $sub_id      Identifier stored in the condition.
	 * @param string     $name        Condition name, `singular` or `archive`.
	 * @param string     $sub_name    Sub-condition name.
	 * @param string     $language_id Target language identifier.
	 * @return int Zero when the identifier names nothing translatable.
	 */
	public function translate_object_id( $sub_id, $name, $sub_name, $language_id ) {
		$sub_id      = absint( $sub_id );
		$language_id = (string) $language_id;

		if ( 0 === $sub_id || '' === $language_id ) {
			return 0;
		}

		$target = $this->condition_target( (string) $name, (string) $sub_name );

		if ( 'post' === $target['kind'] ) {
			$post_type = get_post_type( $sub_id );

			if ( ! is_string( $post_type ) || ! $this->post_translations->supports_post_type( $post_type ) ) {
				return 0;
			}

			return absint( $this->post_translations->get_translation( $sub_id, $language_id ) );
		}

		if ( 'term' === $target['kind'] ) {
			if ( ! $this->term_translations->supports_taxonomy( $target['taxonomy'] ) ) {
				return 0;
			}

			return absint(
				$this->term_translations->get_translation( $sub_id, $target['taxonomy'], $language_id )
			);
		}

		return 0;
	}

	/**
	 * Returns the published translation of one Theme Builder template.
	 *
	 * Only a published translation is offered. Elementor drops a template that is
	 * not published rather than falling back, so answering with the translated
	 * draft LocalePress creates would leave the location empty — a site would
	 * lose its header the moment someone started translating it.
	 *
	 * @param int    $template_id Template post identifier.
	 * @param string $language_id Language being rendered.
	 * @return int Zero when no published translation applies.
	 */
	public function translate_template_id( $template_id, $language_id ) {
		$template_id = absint( $template_id );
		$language_id = (string) $language_id;

		if ( 0 === $template_id || '' === $language_id ) {
			return 0;
		}

		$post_type = get_post_type( $template_id );

		if ( ! is_string( $post_type ) || ! $this->post_translations->supports_post_type( $post_type ) ) {
			return 0;
		}

		if ( $language_id === $this->post_translations->get_post_language_id( $template_id ) ) {
			return 0;
		}

		$translated = absint( $this->post_translations->get_translation( $template_id, $language_id ) );

		if ( 0 === $translated || $translated === $template_id ) {
			return 0;
		}

		return 'publish' === get_post_status( $translated ) ? $translated : 0;
	}

	/**
	 * Reports what kind of object a sub-condition identifier names.
	 *
	 * Elementor builds the sub-condition name from the object it filters by, so
	 * the name is the only place the kind is recorded. A post type and a taxonomy
	 * may share a name, which is what the condition name disambiguates: `singular`
	 * narrows a single post, `archive` narrows a term listing.
	 *
	 * @param string $name     Condition name.
	 * @param string $sub_name Sub-condition name.
	 * @return array{kind: string, taxonomy: string}
	 */
	private function condition_target( $name, $sub_name ) {
		$none = array(
			'kind'     => '',
			'taxonomy' => '',
		);

		if ( '' === $sub_name ) {
			return $none;
		}

		// An author identifier is a user. Users have no translations, and
		// rewriting one would silently point the condition at unrelated content.
		if ( in_array( $sub_name, self::AUTHOR_SUB_CONDITIONS, true ) ) {
			return $none;
		}

		if ( in_array( $sub_name, self::POST_SUB_CONDITIONS, true ) ) {
			return array(
				'kind'     => 'post',
				'taxonomy' => '',
			);
		}

		// Longest prefix first: `any_child_of_category` also ends in a taxonomy
		// name, and `child_of_` would claim the wrong half of it.
		foreach ( array( 'any_child_of_', 'child_of_', 'in_' ) as $prefix ) {
			if ( 0 !== strpos( $sub_name, $prefix ) ) {
				continue;
			}

			$taxonomy = substr( $sub_name, strlen( $prefix ) );

			if ( taxonomy_exists( $taxonomy ) ) {
				return array(
					'kind'     => 'term',
					'taxonomy' => $taxonomy,
				);
			}

			// `in_category_children` names the same taxonomy as `in_category`,
			// matching a post through the children of the term instead of the
			// term itself. The identifier is still that taxonomy's term.
			$trimmed = preg_replace( '/_children$/', '', $taxonomy );

			if ( is_string( $trimmed ) && $trimmed !== $taxonomy && taxonomy_exists( $trimmed ) ) {
				return array(
					'kind'     => 'term',
					'taxonomy' => $trimmed,
				);
			}

			return $none;
		}

		if ( 'singular' === $name && post_type_exists( $sub_name ) ) {
			return array(
				'kind'     => 'post',
				'taxonomy' => '',
			);
		}

		if ( 'archive' === $name && taxonomy_exists( $sub_name ) ) {
			return array(
				'kind'     => 'term',
				'taxonomy' => $sub_name,
			);
		}

		return $none;
	}
}
