<?php
/**
 * Elementor Theme Builder integration module.
 *
 * @package LocalePress
 */

namespace LocalePress\Integrations\Elementor;

use LocalePress\Content\PostTranslationManager;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\Routing\LanguageUrlManager;

defined( 'ABSPATH' ) || exit;

/**
 * Carries Theme Builder templates and popups across languages.
 *
 * Two halves that only work together. Copying a template's display conditions
 * gives the translation rules of its own, and on its own that is worse than
 * copying nothing: a header location renders one document, so an English and a
 * Bengali header both claiming the whole site leave Elementor picking whichever
 * sorts first, for every reader. Resolving the chosen template to the language
 * being read is what turns those two rules into one answer per language.
 *
 * Nothing here reaches into Elementor Pro. Both halves run through filters
 * Elementor documents and calls itself, so a template LocalePress never touched
 * behaves exactly as it did before.
 */
final class ElementorThemeBuilderModule implements ModuleInterface {

	/**
	 * Theme Builder language resolution service.
	 *
	 * @var ElementorThemeBuilder
	 */
	private $theme_builder;

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Constructor.
	 *
	 * @param ElementorThemeBuilder  $theme_builder     Theme Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		ElementorThemeBuilder $theme_builder,
		PostTranslationManager $post_translations,
		LanguageUrlManager $url_manager
	) {
		$this->theme_builder     = $theme_builder;
		$this->post_translations = $post_translations;
		$this->url_manager       = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'localepress_elementor_copy_meta_value', array( $this, 'translate_copied_conditions' ), 10, 4 );
		add_action( 'localepress_elementor_document_copied', array( $this, 'refresh_conditions_cache' ), 10, 2 );

		add_filter(
			'elementor/theme/get_location_templates/template_id',
			array( $this, 'filter_location_template_id' ),
			10,
			2
		);
		add_filter(
			'elementor/theme/get_location_templates/condition_sub_id',
			array( $this, 'filter_condition_sub_id' ),
			10,
			2
		);
	}

	/**
	 * Points a copied condition list at the target language's own content.
	 *
	 * A condition that names a page names it by ID, so the copy would hold the
	 * source language's page. The translation of that page is the same condition
	 * expressed in the language the template now belongs to.
	 *
	 * @param mixed  $meta_value     Metadata value about to be copied.
	 * @param string $meta_key       Metadata key.
	 * @param int    $source_post_id Source post identifier.
	 * @param int    $target_post_id Target post identifier.
	 * @return mixed
	 */
	public function translate_copied_conditions( $meta_value, $meta_key, $source_post_id, $target_post_id ) {
		unset( $source_post_id );

		if ( ElementorThemeBuilder::CONDITIONS_META_KEY !== $meta_key || ! is_array( $meta_value ) ) {
			return $meta_value;
		}

		$language_id = $this->post_translations->get_post_language_id( absint( $target_post_id ) );

		if ( '' === $language_id ) {
			return $meta_value;
		}

		/**
		 * Filters whether copied display conditions are rewritten for the target.
		 *
		 * Returning false copies the conditions verbatim, which is what a site
		 * wants when a translated template should keep targeting exactly the
		 * objects the source named.
		 *
		 * @param bool   $translate      Whether condition identifiers are rewritten.
		 * @param int    $target_post_id Target post identifier.
		 * @param string $language_id    Target language identifier.
		 */
		$translate = apply_filters(
			'localepress_elementor_translate_conditions',
			true,
			absint( $target_post_id ),
			$language_id
		);

		if ( ! $translate ) {
			return $meta_value;
		}

		return $this->theme_builder->translate_conditions( $meta_value, $language_id );
	}

	/**
	 * Rebuilds Elementor's condition index after a document is copied.
	 *
	 * Elementor answers a location from an option it regenerates when conditions
	 * are saved through its own editor. A translation created outside the editor
	 * never triggers that, so the copied conditions would sit in metadata that
	 * nothing reads until the next time someone opened and saved the template.
	 *
	 * @param int $target_post_id Target translated post identifier.
	 * @param int $source_post_id Source post identifier.
	 * @return void
	 */
	public function refresh_conditions_cache( $target_post_id, $source_post_id ) {
		unset( $source_post_id );

		if ( ! metadata_exists( 'post', absint( $target_post_id ), ElementorThemeBuilder::CONDITIONS_META_KEY ) ) {
			return;
		}

		$cache = $this->get_conditions_cache();

		if ( null !== $cache ) {
			$cache->regenerate();
		}
	}

	/**
	 * Answers a theme location with the template written in the read language.
	 *
	 * @param int    $template_id Template identifier Elementor matched.
	 * @param string $location    Theme location name.
	 * @return int
	 */
	public function filter_location_template_id( $template_id, $location ) {
		$template_id = absint( $template_id );

		if ( ! $this->resolving_for_visitor() ) {
			return $template_id;
		}

		$language_id = $this->current_language_id();
		$translated  = '' === $language_id
			? 0
			: $this->theme_builder->translate_template_id( $template_id, $language_id );

		/**
		 * Filters the Theme Builder template one location renders.
		 *
		 * @param int    $resolved_id Template identifier after language resolution.
		 * @param int    $template_id Template identifier Elementor matched.
		 * @param string $location    Theme location name.
		 * @param string $language_id Language being rendered.
		 */
		$resolved_id = apply_filters(
			'localepress_elementor_location_template_id',
			0 < $translated ? $translated : $template_id,
			$template_id,
			$location,
			$language_id
		);

		return 0 < absint( $resolved_id ) ? absint( $resolved_id ) : $template_id;
	}

	/**
	 * Reads a condition's object identifier in the language being rendered.
	 *
	 * This is what lets one set of conditions serve every language. A template
	 * limited to the English "About" page also applies on that page's Bengali
	 * translation, so a site can leave the rules on the source template and let
	 * the location resolution above pick the translated template to render.
	 *
	 * @param string|int           $sub_id           Identifier stored in the condition.
	 * @param array<string, mixed> $parsed_condition Condition split into its segments.
	 * @return string|int
	 */
	public function filter_condition_sub_id( $sub_id, $parsed_condition ) {
		if ( ! is_array( $parsed_condition ) || ! $this->resolving_for_visitor() ) {
			return $sub_id;
		}

		$language_id = $this->current_language_id();

		if ( '' === $language_id ) {
			return $sub_id;
		}

		/**
		 * Filters whether a condition identifier follows the language being read.
		 *
		 * Returning false keeps a condition matching only the exact object it
		 * names, so a rule written against one language's page stops applying to
		 * that page's translations.
		 *
		 * @param bool                 $translate        Whether the identifier is resolved.
		 * @param array<string, mixed> $parsed_condition Condition segments.
		 * @param string               $language_id      Language being rendered.
		 */
		$translate = apply_filters(
			'localepress_elementor_translate_condition_sub_id',
			true,
			$parsed_condition,
			$language_id
		);

		if ( ! $translate ) {
			return $sub_id;
		}

		$name       = isset( $parsed_condition['name'] ) ? (string) $parsed_condition['name'] : '';
		$sub_name   = isset( $parsed_condition['sub_name'] ) ? (string) $parsed_condition['sub_name'] : '';
		$translated = $this->theme_builder->translate_object_id( $sub_id, $name, $sub_name, $language_id );

		return 0 < $translated ? $translated : $sub_id;
	}

	/**
	 * Reports whether a location is being resolved for someone reading the site.
	 *
	 * The administration resolves locations to describe them — the Theme Builder
	 * listing, the conditions conflict notice — and an editor asking which
	 * template holds a rule must be answered with the template that holds it.
	 *
	 * @return bool
	 */
	private function resolving_for_visitor() {
		return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
	}

	/**
	 * Returns the language identifier of the current request.
	 *
	 * @return string
	 */
	private function current_language_id() {
		$language = $this->url_manager->get_current_language();

		return null === $language ? '' : (string) $language['id'];
	}

	/**
	 * Returns Elementor Pro's condition cache when the Theme Builder is loaded.
	 *
	 * Reached through Elementor's own module registry rather than the module's
	 * static accessor, which would construct a second Theme Builder on a site
	 * where Elementor Pro is inactive.
	 *
	 * @return object|null
	 */
	private function get_conditions_cache() {
		if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
			return null;
		}

		$plugin = \ElementorPro\Plugin::instance();

		if ( ! is_object( $plugin ) || ! isset( $plugin->modules_manager ) || ! is_object( $plugin->modules_manager ) ) {
			return null;
		}

		$theme_builder = $plugin->modules_manager->get_modules( 'theme-builder' );

		if ( ! is_object( $theme_builder ) || ! method_exists( $theme_builder, 'get_conditions_manager' ) ) {
			return null;
		}

		$conditions = $theme_builder->get_conditions_manager();

		if ( ! is_object( $conditions ) || ! method_exists( $conditions, 'get_cache' ) ) {
			return null;
		}

		$cache = $conditions->get_cache();

		return is_object( $cache ) && method_exists( $cache, 'regenerate' ) ? $cache : null;
	}
}
