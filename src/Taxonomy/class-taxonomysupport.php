<?php
/**
 * Translatable taxonomy policy.
 *
 * @package LocalePress
 */

namespace LocalePress\Taxonomy;

use LocalePress\Settings\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers public taxonomies supported by the free translation engine.
 */
final class TaxonomySupport {

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param PluginSettings|null $settings Optional central settings service.
	 */
	public function __construct( ?PluginSettings $settings = null ) {
		$this->settings = null === $settings ? new PluginSettings() : $settings;
	}

	/**
	 * Returns supported taxonomy names.
	 *
	 * Public taxonomies with an administrative UI use one generic term workflow.
	 * No commerce, attribute, or other taxonomy-specific data is handled here.
	 *
	 * @return array<int, string>
	 */
	public function get_taxonomies() {
		$taxonomies = $this->get_available_taxonomies();
		$content    = $this->settings->get_section( 'content' );

		if ( 'selected' === $content['taxonomies_mode'] ) {
			$taxonomies = array_values( array_intersect( $taxonomies, $content['taxonomies'] ) );
		}

		return $taxonomies;
	}

	/**
	 * Returns all taxonomies eligible for configuration.
	 *
	 * @return array<int, string>
	 */
	public function get_available_taxonomies() {
		$objects    = get_taxonomies(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		$taxonomies = array_keys( $objects );

		/**
		 * Filters taxonomies supported by the core term translation engine.
		 *
		 * Returned taxonomies must remain public and expose an administrative UI.
		 *
		 * @param array<int, string>       $taxonomies Supported taxonomy names.
		 * @param array<string, \WP_Taxonomy> $objects Taxonomy objects.
		 */
		$filtered  = apply_filters( 'localepress_supported_taxonomies', $taxonomies, $objects );
		$filtered  = is_array( $filtered ) ? $filtered : $taxonomies;
		$supported = array();

		foreach ( $filtered as $taxonomy ) {
			if ( ! is_scalar( $taxonomy ) ) {
				continue;
			}

			$taxonomy = sanitize_key( $taxonomy );
			$object   = get_taxonomy( $taxonomy );

			if ( ! $object || ! $object->public || ! $object->show_ui ) {
				continue;
			}

			$supported[] = $taxonomy;
		}

		return array_values( array_unique( $supported ) );
	}

	/**
	 * Reports whether a taxonomy is supported.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function supports( $taxonomy ) {
		return in_array( sanitize_key( $taxonomy ), $this->get_taxonomies(), true );
	}

	/**
	 * Returns supported taxonomies that apply a default term.
	 *
	 * Categories always do, through the option WordPress reserves for them.
	 * Any other taxonomy applies one only when it was registered with a
	 * default_term, which is what gives it its own stored option.
	 *
	 * @return array<int, string>
	 */
	public function get_default_term_taxonomies() {
		$taxonomies = array();

		foreach ( $this->get_taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( 'category' === $taxonomy || ( $object && ! empty( $object->default_term ) ) ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}
}
