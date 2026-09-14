<?php
/**
 * LocalePress configuration integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Content\PostTypeSupport;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Settings\PluginSettings;
use LocalePress\Settings\SettingsTransfer;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\Taxonomy\TaxonomySupport;

/**
 * Verifies Phase 11 settings persistence and transfer behavior.
 */
class Test_LocalePress_Settings extends WP_UnitTestCase {

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Central settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Workflow settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow;

	/**
	 * Language IDs keyed by code.
	 *
	 * @var array<string, string>
	 */
	private $language_ids = array();

	/** Sets up isolated options and two languages. */
	public function set_up() {
		parent::set_up();

		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		OptionsLanguageRepository::install();
		PluginSettings::install();
		WorkflowSettings::install();

		$this->languages = new LanguageManager( new OptionsLanguageRepository(), new LanguageValidator() );
		$this->settings  = new PluginSettings();
		$this->workflow  = new WorkflowSettings();

		foreach ( array( array( 'English', 'en_US', 'en' ), array( 'German', 'de_DE', 'de' ) ) as $data ) {
			$language                       = $this->languages->create(
				array(
					'name'          => $data[0],
					'native_name'   => $data[0],
					'locale'        => $data[1],
					'language_code' => $data[2],
					'url_slug'      => $data[2],
					'is_rtl'        => false,
					'enabled'       => true,
				)
			);
			$this->language_ids[ $data[2] ] = $language['id'];
		}
	}

	/** Removes isolated options. */
	public function tear_down() {
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		parent::tear_down();
	}

	/** Default settings preserve behavior from releases before Phase 11. */
	public function test_defaults_preserve_existing_runtime_behavior() {
		$settings = $this->settings->get();

		$this->assertSame( PluginSettings::SCHEMA_VERSION, $settings['schema_version'] );
		$this->assertTrue( $settings['url']['prefix_default'] );
		$this->assertSame( 'all', $settings['content']['post_types_mode'] );
		$this->assertSame( 'all', $settings['content']['taxonomies_mode'] );
		$this->assertSame( 'native_name', $settings['switcher']['display'] );
		$this->assertTrue( $settings['seo']['hreflang_enabled'] );
		$this->assertFalse( $settings['advanced']['delete_data_on_uninstall'] );
		$this->assertFalse( $settings['setup']['complete'] );
	}

	/** A fresh install offers a floating switcher without anyone placing one. */
	public function test_floating_switcher_is_enabled_by_default() {
		$floater = $this->settings->get_floater_settings();

		$this->assertTrue( $floater['enabled'] );
		$this->assertSame( 'middle-right', $floater['position'] );
		$this->assertSame( 'vertical', $floater['layout'] );

		// Its own answer, and the opposite of the site-wide one: in a strip at
		// the edge of the screen the flag is what identifies the language.
		$this->assertTrue( $floater['show_flags'] );
		$this->assertFalse( $this->settings->get_switcher_defaults()['show_flags'] );
	}

	/** A site stored before the setting existed reads the shipped default. */
	public function test_floating_switcher_defaults_fill_a_stored_switcher_without_one() {
		$stored = get_option( PluginSettings::OPTION_NAME );
		unset( $stored['switcher']['floater'] );
		update_option( PluginSettings::OPTION_NAME, $stored );

		$settings = new PluginSettings();

		$this->assertTrue( $settings->get_floater_settings()['enabled'] );
	}

	/** The floater is a placement, so it never reaches a switcher's arguments. */
	public function test_switcher_defaults_exclude_the_floating_switcher() {
		$this->assertArrayNotHasKey( 'floater', $this->settings->get_switcher_defaults() );
	}

	/** Turning the floater off and moving it survives a round trip. */
	public function test_floating_switcher_settings_persist() {
		$this->settings->update_sections(
			array(
				'switcher' => array(
					'floater' => array(
						'enabled'    => false,
						'position'   => 'middle-left',
						'layout'     => 'horizontal',
						'show_flags' => false,
					),
				),
			)
		);

		$floater = ( new PluginSettings() )->get_floater_settings();

		$this->assertFalse( $floater['enabled'] );
		$this->assertSame( 'middle-left', $floater['position'] );
		$this->assertSame( 'horizontal', $floater['layout'] );
		$this->assertFalse( $floater['show_flags'] );
	}

	/** An unsupported corner falls back rather than reaching the stylesheet. */
	public function test_unknown_floating_switcher_position_falls_back() {
		$normalized = $this->settings->normalize(
			array(
				'switcher' => array(
					'floater' => array(
						'enabled'  => true,
						'position' => 'middle',
						'layout'   => 'carousel',
					),
				),
			)
		);

		$this->assertSame( 'middle-right', $normalized['switcher']['floater']['position'] );
		$this->assertSame( 'vertical', $normalized['switcher']['floater']['layout'] );
	}

	/** Upgrading a configured site does not reopen first-run setup. */
	public function test_install_marks_an_existing_language_registry_as_configured() {
		delete_option( PluginSettings::OPTION_NAME );
		PluginSettings::install();

		$settings = new PluginSettings();

		$this->assertTrue( $settings->is_setup_complete() );
		$this->assertSame( 5, $settings->get_setup_step() );
	}

	/** Selected object policies restrict only the shared support layer. */
	public function test_selected_content_policies_filter_supported_objects() {
		$this->settings->update_sections(
			array(
				'content' => array(
					'post_types_mode' => 'selected',
					'post_types'      => array( 'page' ),
					'taxonomies_mode' => 'selected',
					'taxonomies'      => array( 'category' ),
				),
			)
		);

		$post_types = new PostTypeSupport( $this->settings );
		$taxonomies = new TaxonomySupport( $this->settings );

		$this->assertSame( array( 'page' ), $post_types->get_post_types() );
		$this->assertSame( array( 'category' ), $taxonomies->get_taxonomies() );
		$this->assertContains( 'post', $post_types->get_available_post_types() );
		$this->assertContains( 'post_tag', $taxonomies->get_available_taxonomies() );
	}

	/** Export and import map stable locales back to site-specific UUIDs. */
	public function test_settings_round_trip_restores_language_and_portable_configuration() {
		$this->settings->update_sections(
			array(
				'url'      => array( 'prefix_default' => false ),
				'content'  => array(
					'post_types_mode' => 'selected',
					'post_types'      => array( 'page' ),
					'taxonomies_mode' => 'selected',
					'taxonomies'      => array( 'category' ),
				),
				'switcher' => array(
					'display' => 'language_code',
					'layout'  => 'dropdown',
				),
				'seo'      => array( 'x_default_enabled' => false ),
			)
		);
		$this->workflow->update(
			array(
				'copy_content'        => false,
				'copy_featured_image' => true,
			)
		);
		$transfer = $this->create_transfer();
		$document = $transfer->export();

		$this->settings->update_sections( array( 'url' => array( 'prefix_default' => true ) ) );
		$this->assertTrue( $this->languages->set_default( $this->language_ids['de'] ) );
		$this->assertNotWPError( $this->languages->toggle( $this->language_ids['en'] ) );

		$result = $transfer->import_json( wp_json_encode( $document ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['url_changed'] );
		$this->assertSame( $this->language_ids['en'], $this->languages->get_default_id() );
		$this->assertCount( 2, $this->languages->get_languages( true ) );
		$this->assertFalse( $this->settings->should_prefix_default_language() );
		$this->assertSame( 'language_code', $this->settings->get_section( 'switcher' )['display'] );
		$this->assertFalse( $this->settings->is_x_default_enabled() );
		$this->assertFalse( $this->workflow->get()['copy_content'] );
	}

	/** Invalid object selections fail before any option is changed. */
	public function test_import_validation_rejects_unavailable_content_type_without_mutation() {
		$transfer = $this->create_transfer();
		$document = $transfer->export();
		$before   = $this->settings->get();
		$document['settings']['content']['post_types_mode'] = 'selected';
		$document['settings']['content']['post_types']      = array( 'missing_type' );

		$result = $transfer->import_json( wp_json_encode( $document ) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_import_content_type', $result->get_error_code() );
		$this->assertSame( $before, $this->settings->get() );
		$this->assertSame( $this->language_ids['en'], $this->languages->get_default_id() );
	}

	/** Malformed and oversized input is rejected without decoding. */
	public function test_import_rejects_malformed_and_oversized_json() {
		$transfer = $this->create_transfer();

		$this->assertSame( 'invalid_json', $transfer->import_json( '{bad' )->get_error_code() );
		$this->assertSame(
			'import_too_large',
			$transfer->import_json( str_repeat( 'x', SettingsTransfer::MAX_JSON_BYTES + 1 ) )->get_error_code()
		);
	}

	/** Runtime settings filters cannot become persisted during an unrelated save. */
	public function test_runtime_settings_filter_is_not_persisted_by_section_updates() {
		$filter = static function ( $settings ) {
			$settings['advanced']['delete_data_on_uninstall'] = true;

			return $settings;
		};

		add_filter( 'localepress_settings', $filter );
		$this->assertTrue( $this->settings->should_delete_data_on_uninstall() );
		$this->settings->update_sections( array( 'url' => array( 'prefix_default' => false ) ) );
		remove_filter( 'localepress_settings', $filter );

		$stored = get_option( PluginSettings::OPTION_NAME );

		$this->assertFalse( $stored['advanced']['delete_data_on_uninstall'] );
		$this->assertFalse( $stored['url']['prefix_default'] );
	}

	/** Malformed extension defaults retain a complete, allowlisted schema. */
	public function test_malformed_filtered_defaults_normalize_without_invalid_values() {
		$filter = static function ( $defaults ) {
			$defaults['content']             = 'invalid';
			$defaults['switcher']['display'] = 'unsupported';

			return $defaults;
		};

		add_filter( 'localepress_settings_defaults', $filter );
		$normalized = $this->settings->normalize( array() );
		remove_filter( 'localepress_settings_defaults', $filter );

		$this->assertSame( 'all', $normalized['content']['post_types_mode'] );
		$this->assertSame( 'name', $normalized['switcher']['display'] );
		$this->assertSame( 'horizontal', $normalized['switcher']['layout'] );
	}

	/** Per-language default terms are site data and never travel in a transfer. */
	public function test_default_terms_are_normalized_and_kept_out_of_a_transfer() {
		$this->settings->update_sections(
			array(
				'content' => array(
					'default_terms' => array(
						'category' => array(
							'lang-de' => '12',
							'lang-en' => 0,
						),
						''         => array( 'lang-de' => 3 ),
					),
				),
			)
		);

		$expected = array( 'category' => array( 'lang-de' => 12 ) );

		$this->assertSame( $expected, $this->settings->get_section( 'content' )['default_terms'] );

		$transfer = $this->create_transfer();
		$document = $transfer->export();

		$this->assertArrayNotHasKey( 'default_terms', $document['settings']['content'] );
		$this->assertNotWPError( $transfer->import_json( wp_json_encode( $document ) ) );
		$this->assertSame( $expected, $this->settings->get_section( 'content' )['default_terms'] );
	}

	/** Returns a transfer service using the active support policies. */
	private function create_transfer() {
		return new SettingsTransfer(
			$this->languages,
			$this->settings,
			$this->workflow,
			new PostTypeSupport( $this->settings ),
			new TaxonomySupport( $this->settings )
		);
	}
}
