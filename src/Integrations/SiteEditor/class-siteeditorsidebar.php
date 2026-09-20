<?php
/**
 * Language panel inside the WordPress Site Editor.
 *
 * @package LocalePress
 */

namespace LocalePress\Integrations\SiteEditor;

use LocalePress\Admin\AdminModule;
use LocalePress\Assets;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\Language\FlagRegistry;
use LocalePress\Language\LanguageManager;

defined( 'ABSPATH' ) || exit;

/**
 * Says which language a template part is, and leads to the others.
 *
 * The Site Editor shows a template part's name and nothing else about it, so a
 * site holding two headers shows two rows that read almost the same and gives an
 * editor no way to tell which one a reader in Bengali sees.
 *
 * This panel is the missing sentence, and it is the only place these are
 * managed. It names the language of the part on screen, lists the languages
 * beside it, and in each row offers the one thing that language needs: the part
 * where it exists, and the offer to start it where it does not. Nothing here
 * leaves the Site Editor, because the work being done is editing.
 */
final class SiteEditorSidebar implements ModuleInterface {

	/**
	 * Editor script handle.
	 */
	const SCRIPT_HANDLE = 'localepress-site-editor';

	/**
	 * Template part translation service.
	 *
	 * @var TemplateParts
	 */
	private $parts;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Flag lookup.
	 *
	 * @var FlagRegistry
	 */
	private $flags;

	/**
	 * Constructor.
	 *
	 * @param TemplateParts   $parts            Template part translation service.
	 * @param LanguageManager $language_manager Language manager.
	 */
	public function __construct( TemplateParts $parts, LanguageManager $language_manager ) {
		$this->parts            = $parts;
		$this->language_manager = $language_manager;
		$this->flags            = new FlagRegistry();
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->parts->is_available() ) {
			return;
		}

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Loads the panel on the Site Editor only.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->parts->is_site_editor() || ! current_user_can( AdminModule::capability() ) ) {
			return;
		}

		if ( ! $this->language_manager->has_languages() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			LOCALEPRESS_URL . 'assets/js/site-editor.js',
			array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-i18n', 'wp-editor', 'wp-api-fetch' ),
			Assets::version( 'assets/js/site-editor.js' ),
			true
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'localepress', LOCALEPRESS_PATH . 'languages' );

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.localePressSiteEditor = ' . wp_json_encode( $this->panel_data() ) . ';',
			'before'
		);

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			LOCALEPRESS_URL . 'assets/css/site-editor.css',
			array(),
			Assets::version( 'assets/css/site-editor.css' )
		);
	}

	/**
	 * Returns what the panel needs to describe the open part.
	 *
	 * @return array<string, mixed>
	 */
	private function panel_data() {
		$edited       = $this->parts->get_edited_slug();
		$current      = '' === $edited ? '' : $this->parts->get_language_id( $edited );
		$translations = '' === $edited ? array() : $this->parts->get_translations( $edited );
		$default_id   = $this->parts->default_language_id();
		$languages    = array();

		foreach ( $this->language_manager->get_languages() as $language ) {
			$language_id = isset( $language['id'] ) ? (string) $language['id'] : '';

			if ( '' === $language_id ) {
				continue;
			}

			$target = isset( $translations[ $language_id ] ) ? (string) $translations[ $language_id ] : '';

			$languages[] = array(
				'id'          => $language_id,
				'name'        => isset( $language['name'] ) ? (string) $language['name'] : $language_id,
				'nativeName'  => isset( $language['native_name'] ) ? (string) $language['native_name'] : $language_id,
				'flagUrl'     => $this->flags->get_flag_url( $language ),
				'code'        => isset( $language['language_code'] ) ? (string) $language['language_code'] : $language_id,
				'slug'        => $target,
				'isCurrent'   => $language_id === $current,
				'isDefault'   => $language_id === $default_id,
				/*
				 * A part the theme ships as a file has no post behind it, so there
				 * is nothing to delete and the button says so by being disabled
				 * rather than by failing when pressed.
				 */
				'isDeletable' => '' !== $target && null !== $this->parts->find_post( $target ),
				'editUrl'     => '' === $target ? '' : $this->parts->editor_url( $target ),
			);
		}

		return array(
			'slug'      => $edited,
			'language'  => $current,
			'languages' => $languages,
			'isPart'    => '' !== $edited,
			'canManage' => current_user_can( 'edit_theme_options' ),
			'route'     => TemplatePartRoutes::NAMESPACE . TemplatePartRoutes::ROUTE,
		);
	}
}
