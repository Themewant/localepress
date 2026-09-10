<?php
/**
 * Core and widget option translation integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Infrastructure\DatabaseStringRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\CurrentLanguageResolver;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\StringTranslation\CoreStringCatalog;
use LocalePress\StringTranslation\CoreStringModule;
use LocalePress\StringTranslation\OptionStringTranslator;
use LocalePress\StringTranslation\RegisteredStringValidator;
use LocalePress\StringTranslation\StringManager;

/**
 * Verifies that a new site has translatable strings without any integration code.
 */
class Test_LocalePress_Core_Strings extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Language manager under test.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * String application service under test.
	 *
	 * @var StringManager
	 */
	private $strings;

	/**
	 * Stable language IDs keyed by language code.
	 *
	 * @var array<string, string>
	 */
	private $language_ids = array();

	/**
	 * Original site title, restored after each test.
	 *
	 * @var string
	 */
	private $blogname = '';

	/**
	 * Prepares isolated language and registered-string storage.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		DatabaseStringRepository::install();
		$this->clear_string_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		OptionsLanguageRepository::install();

		$this->blogname  = (string) get_option( 'blogname' );
		$this->languages = new LanguageManager(
			new OptionsLanguageRepository(),
			new LanguageValidator()
		);

		foreach (
			array(
				array( 'English', 'en_US', 'en' ),
				array( 'German', 'de_DE', 'de' ),
			) as $language_data
		) {
			$language = $this->languages->create(
				array(
					'name'          => $language_data[0],
					'native_name'   => $language_data[0],
					'locale'        => $language_data[1],
					'language_code' => $language_data[2],
					'url_slug'      => $language_data[2],
					'is_rtl'        => false,
					'enabled'       => true,
				)
			);

			$this->language_ids[ $language_data[2] ] = $language['id'];
		}

		$this->strings = new StringManager(
			new DatabaseStringRepository(),
			$this->languages,
			new CurrentLanguageResolver( $this->languages ),
			new RegisteredStringValidator()
		);
	}

	/**
	 * Removes test data and request filters.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'localepress_current_language_id', array( $this, 'use_german' ) );
		remove_filter( 'localepress_register_core_strings', '__return_false' );
		remove_filter( 'localepress_core_string_catalog', array( $this, 'add_acme_option' ) );
		remove_all_filters( 'option_blogname' );
		remove_all_filters( 'option_blogdescription' );
		remove_all_filters( 'option_date_format' );
		remove_all_filters( 'option_time_format' );
		remove_all_filters( 'option_widget_text' );
		remove_all_filters( 'option_acme_settings' );

		update_option( 'blogname', $this->blogname );
		delete_option( 'widget_text' );
		delete_option( 'acme_settings' );
		$this->clear_string_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * Selects German as the current language.
	 *
	 * @return string
	 */
	public function use_german() {
		return $this->language_ids['de'];
	}

	/**
	 * Adds one option to the catalog through the public filter.
	 *
	 * @param array<string, array<string, mixed>> $catalog Declarations keyed by context.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_acme_option( $catalog ) {
		$catalog['Acme'] = array(
			'acme_settings' => array( 'header_text' => true ),
		);

		return $catalog;
	}

	/**
	 * The site title is registered and translated without any integration code.
	 *
	 * @return void
	 */
	public function test_site_identity_is_translatable_out_of_the_box() {
		update_option( 'blogname', 'Acme Hotels' );
		$this->boot();

		// Reading the option is what registers it, so the string translation
		// screen fills itself the first time anything renders the site title.
		$this->assertSame( 'Acme Hotels', get_option( 'blogname' ) );
		$this->strings->flush_registered_strings();

		$string_id  = StringManager::generate_string_id( CoreStringCatalog::SITE_CONTEXT, 'blogname' );
		$repository = new DatabaseStringRepository();

		$this->assertNotNull( $repository->find_definition( $string_id ) );

		$this->strings->save_translations( $this->language_ids['de'], array( $string_id => 'Acme Hotels GmbH' ) );
		add_filter( 'localepress_current_language_id', array( $this, 'use_german' ) );

		$this->assertSame( 'Acme Hotels GmbH', get_option( 'blogname' ) );
		$this->assertSame( 'Acme Hotels GmbH', get_bloginfo( 'name' ) );
	}

	/**
	 * Widget titles are picked up from every widget type without configuration.
	 *
	 * @return void
	 */
	public function test_widget_values_are_registered_by_wildcard() {
		update_option(
			'widget_text',
			array(
				2              => array(
					'title' => 'Opening hours',
					'text'  => 'Every day from 9 to 6',
				),
				'_multiwidget' => 1,
			),
			// The wildcard is expanded against autoloaded options, which is where
			// WordPress keeps widget instances.
			true
		);

		$this->boot();
		get_option( 'widget_text' );
		$this->strings->flush_registered_strings();

		$repository = new DatabaseStringRepository();
		$title_id   = StringManager::generate_string_id( CoreStringCatalog::WIDGET_CONTEXT, 'widget_text.2.title' );
		$text_id    = StringManager::generate_string_id( CoreStringCatalog::WIDGET_CONTEXT, 'widget_text.2.text' );

		$this->assertNotNull( $repository->find_definition( $title_id ) );
		$this->assertNotNull( $repository->find_definition( $text_id ) );

		$this->strings->save_translations( $this->language_ids['de'], array( $title_id => 'Öffnungszeiten' ) );
		add_filter( 'localepress_current_language_id', array( $this, 'use_german' ) );

		$widgets = get_option( 'widget_text' );

		$this->assertSame( 'Öffnungszeiten', $widgets[2]['title'] );
		$this->assertSame( 'Every day from 9 to 6', $widgets[2]['text'] );

		// The bookkeeping key every widget option carries is not a string.
		$this->assertSame( 1, $widgets['_multiwidget'] );
	}

	/**
	 * A site can add its own options to the catalog without writing a module.
	 *
	 * @return void
	 */
	public function test_catalog_is_extensible_through_its_filter() {
		update_option( 'acme_settings', array( 'header_text' => 'Book your stay' ) );
		add_filter( 'localepress_core_string_catalog', array( $this, 'add_acme_option' ) );

		$translator = $this->boot();

		$this->assertContains( 'acme_settings', $translator->get_option_names() );

		get_option( 'acme_settings' );
		$this->strings->flush_registered_strings();

		$string_id = StringManager::generate_string_id( 'Acme', 'acme_settings.header_text' );

		$this->assertNotNull( ( new DatabaseStringRepository() )->find_definition( $string_id ) );
	}

	/**
	 * Nothing is claimed when the catalog is turned off.
	 *
	 * @return void
	 */
	public function test_catalog_can_be_disabled() {
		add_filter( 'localepress_register_core_strings', '__return_false' );

		$this->assertSame( array(), $this->boot()->get_option_names() );
	}

	/**
	 * Registers the catalog and returns the translator it was registered on.
	 *
	 * @return OptionStringTranslator
	 */
	private function boot() {
		$translator = new OptionStringTranslator( $this->strings );

		( new CoreStringModule( $translator, $this->languages ) )->register();

		return $translator;
	}

	/**
	 * Empties the registered string tables between tests.
	 *
	 * @return void
	 */
	private function clear_string_tables() {
		global $wpdb;

		$strings_table      = DatabaseStringRepository::strings_table();
		$translations_table = DatabaseStringRepository::translations_table();

		// Table names are generated by the LocalePress repository.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$translations_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$strings_table}" );

		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( DatabaseStringRepository::CACHE_GROUP );
		}
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
