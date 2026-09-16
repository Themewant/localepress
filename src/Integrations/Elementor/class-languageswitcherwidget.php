<?php
/**
 * Elementor language switcher widget.
 *
 * @package LocalePress
 */

namespace LocalePress\Integrations\Elementor;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use LocalePress\Plugin;
use LocalePress\Switcher\LanguageSwitcher;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the shared language switcher as an Elementor widget.
 *
 * The widget owns its controls and nothing else. Every language it lists, every
 * URL it links to, and every element of the markup comes from the same
 * `LanguageSwitcher` service the shortcode, the block, the navigation menu item,
 * and the floating switcher all render through, so a fix to how a switcher
 * resolves a translation reaches the header a site built in Elementor without
 * anyone porting it there.
 *
 * Elementor constructs widgets itself, with a signature this class must not
 * change, so the service is taken from the plugin container at render time
 * rather than injected.
 */
final class LanguageSwitcherWidget extends Widget_Base {

	/**
	 * Elementor category this widget is filed under.
	 */
	const CATEGORY = 'localepress';

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function get_name() {
		return 'localepress-language-switcher';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Language Switcher', 'localepress' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-globe';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<int, string>
	 */
	public function get_categories() {
		return array( self::CATEGORY );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<int, string>
	 */
	public function get_keywords() {
		return array( 'language', 'switcher', 'multilingual', 'translation', 'localepress', 'flag' );
	}

	/**
	 * Stylesheet the rendered markup depends on.
	 *
	 * Named rather than enqueued at render time, so Elementor can put it in the
	 * head of a page that uses this widget and keep it off every page that
	 * does not.
	 *
	 * @return array<int, string>
	 */
	public function get_style_depends() {
		return array( 'localepress-switcher' );
	}

	/**
	 * Script the dropdown layout depends on.
	 *
	 * Named unconditionally rather than only for the dropdown layout. Elementor
	 * reads this list to decide what a page needs, not what one saved widget
	 * needs, and the script is inert where no dropdown exists: it acts on
	 * `.localepress-switcher__dropdown` and finds none.
	 *
	 * @return array<int, string>
	 */
	public function get_script_depends() {
		return array( 'localepress-switcher' );
	}

	/**
	 * Registers the widget's content and style controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->register_content_controls();
		$this->register_language_style_controls();
		$this->register_flag_style_controls();
		$this->register_dropdown_style_controls();
		$this->register_spacing_style_controls();
	}

	/**
	 * Registers the Content tab.
	 *
	 * @return void
	 */
	private function register_content_controls() {
		$this->start_controls_section(
			'localepress_content',
			array(
				'label' => __( 'Languages', 'localepress' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'localepress' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'horizontal',
				'options' => array(
					'horizontal' => __( 'Horizontal', 'localepress' ),
					'vertical'   => __( 'Vertical', 'localepress' ),
					'dropdown'   => __( 'Dropdown', 'localepress' ),
				),
			)
		);

		/*
		 * One label per language rather than a set of checkboxes. A switcher
		 * showing a name and a code together has to say which is the link and
		 * which is the annotation, in every language at once, and there is no
		 * answer that reads well in all of them. The renderer therefore takes a
		 * single label mode, and the widget offers exactly that.
		 */
		$this->add_control(
			'display',
			array(
				'label'   => __( 'Label', 'localepress' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'native_name',
				'options' => array(
					'native_name'   => __( 'Native name', 'localepress' ),
					'name'          => __( 'Language name', 'localepress' ),
					'language_code' => __( 'Language code', 'localepress' ),
				),
			)
		);

		$this->add_control(
			'show_flags',
			array(
				'label'        => __( 'Show flags', 'localepress' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'languages',
			array(
				'label'       => __( 'Languages', 'localepress' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => $this->get_language_options(),
				'default'     => array(),
				'label_block' => true,
				'description' => __( 'Leave empty to show every enabled language. A language added to the site later appears on its own while this is empty, and has to be added here once a selection is made.', 'localepress' ),
			)
		);

		$this->add_control(
			'hide_current',
			array(
				'label'        => __( 'Hide current language', 'localepress' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'unavailable_behavior',
			array(
				'label'       => __( 'Missing translation', 'localepress' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'hide',
				'options'     => array(
					'hide'     => __( 'Hide languages the site has no content in', 'localepress' ),
					'disabled' => __( 'Show as unavailable', 'localepress' ),
					'home'     => __( 'Link to language homepage', 'localepress' ),
					'current'  => __( 'Keep current URL', 'localepress' ),
				),
				'description' => __( 'A language stays in the switcher as long as something on the site is published in it, even on a page carrying no translation of its own.', 'localepress' ),
			)
		);

		$this->add_control(
			'show_disabled',
			array(
				'label'        => __( 'Show disabled languages', 'localepress' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'aria_label',
			array(
				'label'       => __( 'Accessible label', 'localepress' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Language selector', 'localepress' ),
				'description' => __( 'Announced to screen readers in place of the default.', 'localepress' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers Style > Language.
	 *
	 * @return void
	 */
	private function register_language_style_controls() {
		$this->start_controls_section(
			'localepress_style_language',
			array(
				'label' => __( 'Language', 'localepress' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'language_typography',
				'selector' => '{{WRAPPER}} .localepress-switcher__link, {{WRAPPER}} .localepress-switcher__unavailable, {{WRAPPER}} .localepress-switcher__dropdown > summary',
			)
		);

		$this->start_controls_tabs( 'localepress_language_colors' );

		$this->start_controls_tab(
			'localepress_language_normal',
			array( 'label' => __( 'Normal', 'localepress' ) )
		);

		$this->add_control(
			'language_color',
			array(
				'label'     => __( 'Text Color', 'localepress' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .localepress-switcher__link'            => 'color: {{VALUE}};',
					'{{WRAPPER}} .localepress-switcher__dropdown > summary' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'localepress_language_hover',
			array( 'label' => __( 'Hover', 'localepress' ) )
		);

		$this->add_control(
			'language_hover_color',
			array(
				'label'     => __( 'Text Color', 'localepress' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .localepress-switcher__link:hover'      => 'color: {{VALUE}};',
					'{{WRAPPER}} .localepress-switcher__link:focus-visible' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'localepress_language_current',
			array( 'label' => __( 'Current', 'localepress' ) )
		);

		$this->add_control(
			'language_current_color',
			array(
				'label'     => __( 'Text Color', 'localepress' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .localepress-switcher__item.is-current .localepress-switcher__link' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();
		$this->end_controls_tabs();

		/*
		 * A language the switcher is keeping but cannot link anywhere. The core
		 * stylesheet dims it; a site that would rather say so in its own colors
		 * says it here.
		 */
		$this->add_control(
			'language_unavailable_color',
			array(
				'label'     => __( 'Unavailable Color', 'localepress' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array(
					'{{WRAPPER}} .localepress-switcher__unavailable' => 'color: {{VALUE}}; opacity: 1;',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers Style > Flag.
	 *
	 * @return void
	 */
	private function register_flag_style_controls() {
		$this->start_controls_section(
			'localepress_style_flag',
			array(
				'label'     => __( 'Flag', 'localepress' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_flags' => 'yes' ),
			)
		);

		$this->add_responsive_control(
			'flag_size',
			array(
				'label'      => __( 'Size', 'localepress' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 64,
					),
					'em' => array(
						'min'  => 0.5,
						'max'  => 4,
						'step' => 0.05,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .localepress-switcher__flag' => 'width: {{SIZE}}{{UNIT}}; height: auto;',
				),
			)
		);

		$this->add_responsive_control(
			'flag_spacing',
			array(
				'label'      => __( 'Spacing', 'localepress' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
					'em' => array(
						'min'  => 0,
						'max'  => 3,
						'step' => 0.05,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .localepress-switcher__link'               => 'gap: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .localepress-switcher__unavailable'        => 'gap: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .localepress-switcher__dropdown > summary' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'flag_radius',
			array(
				'label'      => __( 'Border Radius', 'localepress' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'%' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .localepress-switcher__flag' => 'border-radius: {{SIZE}}{{UNIT}}; object-fit: cover;',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers Style > Dropdown.
	 *
	 * @return void
	 */
	private function register_dropdown_style_controls() {
		$this->start_controls_section(
			'localepress_style_dropdown',
			array(
				'label'     => __( 'Dropdown', 'localepress' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'layout' => 'dropdown' ),
			)
		);

		/*
		 * The open panel only. The summary is styled as a language above,
		 * because that is what it is: the current language, standing in for the
		 * list until the list is opened.
		 */
		$panel = '{{WRAPPER}} .localepress-switcher__dropdown[open] > .localepress-switcher__list';

		$this->add_control(
			'dropdown_background',
			array(
				'label'     => __( 'Background', 'localepress' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $panel => 'background: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'dropdown_border',
				'selector' => $panel,
			)
		);

		$this->add_control(
			'dropdown_radius',
			array(
				'label'      => __( 'Border Radius', 'localepress' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					$panel => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'dropdown_shadow',
				'selector' => $panel,
			)
		);

		$this->add_responsive_control(
			'dropdown_padding',
			array(
				'label'      => __( 'Padding', 'localepress' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					$panel => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers Style > Spacing.
	 *
	 * @return void
	 */
	private function register_spacing_style_controls() {
		$this->start_controls_section(
			'localepress_style_spacing',
			array(
				'label' => __( 'Spacing', 'localepress' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		/*
		 * The core stylesheet spaces the list with a custom property for this
		 * reason: a gap set here applies to a row and a column alike, and does
		 * not have to know which one it is looking at.
		 */
		$this->add_responsive_control(
			'item_gap',
			array(
				'label'      => __( 'Gap Between Languages', 'localepress' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
					'em' => array(
						'min'  => 0,
						'max'  => 5,
						'step' => 0.05,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .localepress-switcher' => '--localepress-switcher-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'item_padding',
			array(
				'label'      => __( 'Language Padding', 'localepress' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .localepress-switcher__link'               => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					'{{WRAPPER}} .localepress-switcher__unavailable'        => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					'{{WRAPPER}} .localepress-switcher__dropdown > summary' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'switcher_alignment',
			array(
				'label'     => __( 'Alignment', 'localepress' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Left', 'localepress' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => __( 'Center', 'localepress' ),
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'Right', 'localepress' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .localepress-switcher__list' => 'justify-content: {{VALUE}};',
					'{{WRAPPER}} .localepress-switcher' => 'display: flex; justify-content: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the widget on the front end and in the editor preview.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$switcher = Plugin::instance()->switcher();

		if ( ! $switcher instanceof LanguageSwitcher ) {
			return;
		}

		$selected = isset( $settings['languages'] ) && is_array( $settings['languages'] )
			? array_filter( array_map( 'strval', $settings['languages'] ) )
			: array();

		$restrict = $this->language_restriction( $selected );

		if ( null !== $restrict ) {
			add_filter( 'localepress_switcher_languages', $restrict, 10, 1 );
		}

		$html = $switcher->render(
			array(
				'display'              => isset( $settings['display'] ) ? $settings['display'] : 'native_name',
				'layout'               => isset( $settings['layout'] ) ? $settings['layout'] : 'horizontal',
				'hide_current'         => 'yes' === ( isset( $settings['hide_current'] ) ? $settings['hide_current'] : '' ),
				'unavailable_behavior' => isset( $settings['unavailable_behavior'] ) ? $settings['unavailable_behavior'] : 'hide',

				/*
				 * Spelled out, and always false. `hide_missing` is the older
				 * spelling of `unavailable_behavior="hide"` and the renderer
				 * lets it win, so inheriting it from the site settings would
				 * silently overrule the Missing translation control on this
				 * panel: an editor would pick "Keep current URL", see nothing
				 * change, and have no way to find out why. The widget names
				 * every language behavior it wants, so it answers this one too.
				 */
				'hide_missing'         => false,
				'show_flags'           => 'yes' === ( isset( $settings['show_flags'] ) ? $settings['show_flags'] : '' ),
				'show_disabled'        => 'yes' === ( isset( $settings['show_disabled'] ) ? $settings['show_disabled'] : '' ),
				'aria_label'           => isset( $settings['aria_label'] ) ? $settings['aria_label'] : '',
				'context'              => 'elementor',
			)
		);

		if ( null !== $restrict ) {
			remove_filter( 'localepress_switcher_languages', $restrict, 10 );
		}

		if ( '' !== $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by LanguageSwitcher::render().

			return;
		}

		/*
		 * An empty switcher is correct on the front end and useless in the
		 * editor: a widget that draws nothing cannot be selected again to be
		 * configured or removed. The placeholder appears only while editing.
		 */
		$this->render_editor_placeholder();
	}

	/**
	 * Returns a filter that narrows the switcher to the chosen languages.
	 *
	 * @param array<int, string> $selected Selected language identifiers.
	 * @return callable|null Null when every language is shown.
	 */
	private function language_restriction( array $selected ) {
		if ( empty( $selected ) ) {
			return null;
		}

		$wanted = array_flip( $selected );

		return static function ( $languages ) use ( $wanted ) {
			if ( ! is_array( $languages ) ) {
				return $languages;
			}

			$filtered = array();

			foreach ( $languages as $language ) {
				if ( is_array( $language ) && isset( $language['id'] ) && isset( $wanted[ (string) $language['id'] ] ) ) {
					$filtered[] = $language;
				}
			}

			/*
			 * A selection naming only languages that have since been deleted
			 * would empty the switcher. The registered set is a better answer
			 * than nothing at all, so the stale selection is ignored.
			 */
			return empty( $filtered ) ? $languages : $filtered;
		};
	}

	/**
	 * Prints a placeholder so an empty widget stays selectable while editing.
	 *
	 * @return void
	 */
	private function render_editor_placeholder() {
		$elementor = \Elementor\Plugin::$instance;

		if (
			! is_object( $elementor )
			|| ! isset( $elementor->editor )
			|| ! is_object( $elementor->editor )
			|| ! $elementor->editor->is_edit_mode()
		) {
			return;
		}

		/*
		 * Styled inline rather than from a stylesheet. This markup exists only
		 * inside the editor, and a rule shipped in the frontend stylesheet for
		 * it would be downloaded by every visitor to describe something none of
		 * them can ever be shown.
		 */
		printf(
			'<div class="localepress-elementor-placeholder" style="%1$s">%2$s</div>',
			esc_attr( 'padding:1em;border:1px dashed currentColor;border-radius:4px;opacity:.7;font-size:13px;text-align:center;' ),
			esc_html__( 'No language can be shown here yet. Register a second language, or widen the Missing translation setting.', 'localepress' )
		);
	}

	/**
	 * Returns the enabled languages as Elementor control options.
	 *
	 * @return array<string, string>
	 */
	private function get_language_options() {
		$manager = Plugin::instance()->languages();
		$options = array();

		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_languages' ) ) {
			return $options;
		}

		foreach ( $manager->get_languages( true ) as $language ) {
			if ( ! is_array( $language ) || ! isset( $language['id'] ) ) {
				continue;
			}

			$name = isset( $language['native_name'] ) && '' !== $language['native_name']
				? $language['native_name']
				: ( isset( $language['name'] ) ? $language['name'] : $language['id'] );

			$options[ (string) $language['id'] ] = (string) $name;
		}

		return $options;
	}
}
