<?php
/**
 * Elementor widget registration.
 *
 * @package LocalePress
 */

namespace LocalePress\Integrations\Elementor;

use Elementor\Widgets_Manager;
use LocalePress\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the LocalePress panel category and the widgets filed under it.
 *
 * Kept apart from ElementorModule, which copies a document when a translated
 * draft is created. That runs wherever translations are made, with or without
 * the editor open; this runs only where Elementor is drawing its panel.
 */
final class ElementorWidgetModule implements ModuleInterface {

	/**
	 * Panel category slug.
	 *
	 * Spelled out rather than read from the widget class. A constant borrowed
	 * from that class would be resolved as this file loads, which would pull in
	 * a class extending `Elementor\Widget_Base` on every request — including the
	 * ones where Elementor is not active and that parent does not exist.
	 */
	const CATEGORY = 'localepress';

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
	}

	/**
	 * Adds the LocalePress category to the Elementor panel.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 * @return void
	 */
	public function register_category( $elements_manager ) {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'LocalePress', 'localepress' ),
				'icon'  => 'eicon-globe',
			)
		);
	}

	/**
	 * Registers the widgets LocalePress contributes to the panel.
	 *
	 * @param Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ) {
		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		$widgets_manager->register( new LanguageSwitcherWidget() );
	}
}
