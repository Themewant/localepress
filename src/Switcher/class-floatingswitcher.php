<?php
/**
 * Floating language switcher pinned to a corner of the viewport.
 *
 * @package LocalePress
 */

namespace LocalePress\Switcher;

use LocalePress\Contracts\ModuleInterface;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Settings\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints one switcher in the footer, fixed to a corner of the screen.
 *
 * This is the only switcher the plugin places by itself. Every other one is
 * put somewhere deliberately — a shortcode in a post, a block in a template, an
 * item in a menu — and this one exists for the site that has done none of that
 * yet, so a second language is reachable the moment it is registered rather
 * than after someone finds where to put a switcher.
 *
 * It renders in `wp_footer` because that is the one place every theme has, but
 * it is fixed rather than in flow: the corner it sits in is a setting, and
 * choosing a top corner puts it over the header without the markup moving.
 */
final class FloatingSwitcher implements ModuleInterface {

	/**
	 * Switcher service.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Language URL service, used to recognize a page builder's preview frame.
	 *
	 * @var LanguageUrlManager|null
	 */
	private $url_manager;

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher        $switcher    Switcher service.
	 * @param PluginSettings|null     $settings    Optional central settings service.
	 * @param LanguageUrlManager|null $url_manager Optional language URL service.
	 */
	public function __construct(
		LanguageSwitcher $switcher,
		?PluginSettings $settings = null,
		?LanguageUrlManager $url_manager = null
	) {
		$this->switcher    = $switcher;
		$this->settings    = null === $settings ? new PluginSettings() : $settings;
		$this->url_manager = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		/*
		 * The markup is printed in the footer but the assets are asked for in
		 * the head, where a stylesheet still blocks the first paint. Enqueuing
		 * them beside the markup would leave the switcher briefly unstyled —
		 * a list of languages in the flow of the page before it jumps into its
		 * corner — which is the one moment a fixed element must not be visible.
		 */
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Loads the switcher assets when a floater will be rendered.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$config = $this->settings->get_floater_settings();

		wp_enqueue_style( 'localepress-switcher' );
		wp_enqueue_style( 'localepress-floating-switcher' );

		if ( 'dropdown' === $config['layout'] ) {
			// A panel opening downward out of a switcher pinned to the bottom of
			// the screen has nowhere to go, and only the browser knows how much
			// room is actually there.
			wp_enqueue_script( 'localepress-switcher' );
		}
	}

	/**
	 * Prints the floating switcher.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$config   = $this->settings->get_floater_settings();
		$position = in_array( $config['position'], PluginSettings::floater_positions(), true )
			? $config['position']
			: 'bottom-right';

		/*
		 * Only the three settings the floater owns are passed. Everything else —
		 * the labels, what a missing translation does, whether disabled
		 * languages appear — is left to the switcher defaults, because those
		 * describe what a switcher says rather than where it goes.
		 */
		$html = $this->switcher->render(
			array(
				'layout'     => $config['layout'],
				'show_flags' => ! empty( $config['show_flags'] ),
				'class_name' => 'localepress-switcher--floating localepress-switcher--at-' . $position,
				'context'    => 'floater',
			)
		);

		/*
		 * A site with one language, or one whose URL mode carries no language
		 * prefixes, renders nothing rather than an empty box in the corner.
		 */
		if ( '' === $html ) {
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by LanguageSwitcher::render().
	}

	/**
	 * Reports whether a floating switcher should be printed on this request.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		$config  = $this->settings->get_floater_settings();
		$enabled = ! empty( $config['enabled'] ) && ! $this->in_builder_preview();

		/**
		 * Filters whether the floating language switcher is printed.
		 *
		 * A theme that already carries a switcher turns this off in the
		 * settings. This is for the narrower case: keeping the floater on the
		 * site but off one template, such as a landing page or a checkout.
		 *
		 * @param bool                 $enabled Whether the floater renders.
		 * @param array<string, mixed> $config  Floating switcher settings.
		 */
		return (bool) apply_filters( 'localepress_render_floating_switcher', $enabled, $config );
	}

	/**
	 * Reports whether this request is a page builder editing a page.
	 *
	 * A builder renders the real front end inside its canvas, so everything
	 * `wp_footer` prints turns up in the editor too. For most of what a plugin
	 * adds that is correct — the point of the canvas is to show the page as it
	 * will be. A switcher fixed over that canvas is the exception: it is not
	 * part of the page being edited, it cannot be moved or selected, and it
	 * sits on top of the handles and toolbars the builder needs that corner
	 * for. So it stands down while a builder is open and comes back on the
	 * published page.
	 *
	 * @return bool
	 */
	private function in_builder_preview() {
		if ( null !== $this->url_manager && $this->url_manager->is_builder_preview_request() ) {
			return true;
		}

		return $this->in_elementor_editor();
	}

	/**
	 * Reports whether Elementor is editing or previewing this request.
	 *
	 * Elementor is asked directly rather than having its preview recognized by
	 * the query argument alone, because the argument names only the canvas
	 * frame: the editor also renders the page for its own template and popup
	 * previews, which announce themselves nowhere in the URL.
	 *
	 * @return bool
	 */
	private function in_elementor_editor() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( ! is_object( $elementor ) ) {
			return false;
		}

		foreach ( array( 'preview' => 'is_preview_mode', 'editor' => 'is_edit_mode' ) as $component => $method ) {
			if (
				isset( $elementor->{$component} )
				&& is_object( $elementor->{$component} )
				&& method_exists( $elementor->{$component}, $method )
				&& $elementor->{$component}->{$method}()
			) {
				return true;
			}
		}

		return false;
	}
}
