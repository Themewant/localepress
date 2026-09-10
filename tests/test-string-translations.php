<?php
/**
 * Registered string translation integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Admin\StringTranslationListTable;
use LocalePress\Admin\StringTranslationQuery;
use LocalePress\Infrastructure\DatabaseStringRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\CurrentLanguageResolver;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\StringTranslation\RegisteredStringValidator;
use LocalePress\StringTranslation\StringManager;
use LocalePress\StringTranslation\StringModule;

/**
 * Verifies the registered-string API, persistence, caching, and reporting UI.
 */
class Test_LocalePress_String_Translations extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * String repository under test.
	 *
	 * @var DatabaseStringRepository
	 */
	private $repository;

	/**
	 * String application service under test.
	 *
	 * @var StringManager
	 */
	private $strings;

	/**
	 * Language manager under test.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Stable language IDs keyed by language code.
	 *
	 * @var array<string, string>
	 */
	private $language_ids = array();

	/**
	 * Prepares isolated language and registered-string storage.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		DatabaseStringRepository::install();
		$this->clear_string_tables();
		$this->clear_string_cache();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		OptionsLanguageRepository::install();

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

		$this->repository = new DatabaseStringRepository();
		$this->strings    = new StringManager(
			$this->repository,
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
		$this->clear_string_tables();
		$this->clear_string_cache();
		delete_option( OptionsLanguageRepository::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * Definitions use deterministic IDs and unchanged registrations do not write.
	 *
	 * @return void
	 */
	public function test_registration_is_deterministic_and_deduplicated() {
		$string_id = $this->strings->register_string( 'theme', 'hero_title', 'Welcome <strong>home</strong>' );

		$this->assertNotWPError( $string_id );
		$this->assertSame( StringManager::generate_string_id( 'theme', 'hero_title' ), $string_id );
		$this->assertSame( 1, $this->strings->flush_registered_strings() );

		$definition = $this->repository->find_definition( $string_id );

		$this->assertSame( 'Welcome home', $definition['original_string'] );
		$this->strings->register_string( 'theme', 'hero_title', 'Welcome home' );
		$this->assertSame( 0, $this->strings->flush_registered_strings() );
	}

	/**
	 * Updating an original preserves its independently stored translations.
	 *
	 * @return void
	 */
	public function test_original_updates_preserve_translations() {
		$string_id = $this->register( 'theme', 'cta', 'Read more' );
		$result    = $this->strings->save_translations(
			$this->language_ids['de'],
			array( $string_id => 'Mehr lesen' )
		);

		$this->assertSame( 1, $result );
		$this->strings->register_string( 'theme', 'cta', 'Continue reading' );
		$this->assertSame( 1, $this->strings->flush_registered_strings() );
		$this->assertSame( 'Continue reading', $this->repository->find_definition( $string_id )['original_string'] );
		$this->assertSame( 'Mehr lesen', $this->repository->find_translation( $string_id, $this->language_ids['de'] ) );
	}

	/**
	 * Retrieval uses the current enabled language and falls back to the original.
	 *
	 * @return void
	 */
	public function test_retrieval_uses_current_language_and_safe_fallbacks() {
		$string_id = $this->register( 'theme', 'greeting', 'Hello' );
		$this->strings->save_translations(
			$this->language_ids['de'],
			array( $string_id => 'Hallo' )
		);
		add_filter( 'localepress_current_language_id', array( $this, 'use_german' ) );

		$this->assertSame( 'Hallo', $this->strings->get_string( 'theme', 'greeting', 'Hello' ) );
		$this->assertSame( 'Hello', $this->strings->get_string( 'theme', 'greeting', 'Hello', $this->language_ids['en'] ) );
		$this->assertSame( 'Fallback', $this->strings->get_string( 'unknown', 'key', '<b>Fallback</b>', 'unknown' ) );
	}

	/**
	 * Empty values remove translations and restore original-string fallback.
	 *
	 * @return void
	 */
	public function test_empty_value_deletes_translation() {
		$string_id = $this->register( 'theme', 'empty_test', 'Original' );
		$this->strings->save_translations(
			$this->language_ids['de'],
			array( $string_id => 'Translated' )
		);

		$this->assertSame(
			1,
			$this->strings->save_translations(
				$this->language_ids['de'],
				array( $string_id => '' )
			)
		);
		$this->assertNull( $this->repository->find_translation( $string_id, $this->language_ids['de'] ) );
		$this->assertSame( 'Original', $this->strings->get_string( 'theme', 'empty_test', '', $this->language_ids['de'] ) );
	}

	/**
	 * Every submitted value is validated before any translation is changed.
	 *
	 * @return void
	 */
	public function test_invalid_page_save_has_no_partial_mutation() {
		$first_id  = $this->register( 'theme', 'first', 'First' );
		$second_id = $this->register( 'theme', 'second', 'Second' );
		$result    = $this->strings->save_translations(
			$this->language_ids['de'],
			array(
				$first_id  => 'Valid value',
				$second_id => str_repeat( 'x', RegisteredStringValidator::MAX_STRING_LENGTH + 1 ),
			)
		);

		$this->assertWPError( $result );
		$this->assertNull( $this->repository->find_translation( $first_id, $this->language_ids['de'] ) );
		$this->assertNull( $this->repository->find_translation( $second_id, $this->language_ids['de'] ) );
	}

	/**
	 * Admin reporting applies group, search, language, and pagination in bounded queries.
	 *
	 * @return void
	 */
	public function test_admin_query_filters_and_bulk_loads_translations() {
		$first_id = $this->register( 'theme', 'hero', 'Welcome aboard' );
		$this->register( 'theme', 'footer', 'Copyright notice' );
		$this->register( 'integration', 'label', 'Account label' );
		$this->strings->save_translations(
			$this->language_ids['de'],
			array( $first_id => 'Willkommen an Bord' )
		);

		$query  = new StringTranslationQuery( $this->repository, $this->languages );
		$before = $GLOBALS['wpdb']->num_queries;
		$result = $query->query(
			array(
				'language_id' => $this->language_ids['de'],
				'group'       => 'theme',
				'search'      => 'Welcome',
				'per_page'    => 1,
			)
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Willkommen an Bord', $result['items'][0]['translation'] );
		$this->assertSame( array( 'integration', 'theme' ), $query->get_groups() );
		$this->assertLessThanOrEqual( 4, $GLOBALS['wpdb']->num_queries - $before );
	}

	/**
	 * A prepared list table renders original and editable translated values.
	 *
	 * @return void
	 */
	public function test_list_table_renders_translation_fields() {
		$string_id = $this->register( 'theme', 'admin_field', 'Editable original' );
		$this->strings->save_translations(
			$this->language_ids['de'],
			array( $string_id => 'Bearbeitbar' )
		);
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Test-only read filter state.
		$previous_get           = $_GET;
		$previous_server        = $_SERVER;
		$_GET                   = array(
			'page'                 => 'localepress-string-translations',
			'localepress_language' => $this->language_ids['de'],
		);
		$_SERVER['HTTP_HOST']   = 'example.org';
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=localepress-string-translations';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$table = new StringTranslationListTable(
			new StringTranslationQuery( $this->repository, $this->languages )
		);
		$table->prepare_items();

		ob_start();
		$table->display();
		$html    = ob_get_clean();
		$_GET    = $previous_get;
		$_SERVER = $previous_server;

		$this->assertStringContainsString( 'Editable original', $html );
		$this->assertStringContainsString(
			'name="translations[' . $this->language_ids['de'] . '][' . $string_id . ']"',
			$html
		);
		$this->assertStringContainsString( 'Bearbeitbar', $html );

		// One language needs no heading above its field; the column names it.
		$this->assertStringNotContainsString( 'localepress-string-language', $html );
	}

	/**
	 * The all-languages view offers one field per language in a single form.
	 *
	 * @return void
	 */
	public function test_list_table_renders_every_language_when_selected() {
		$string_id = $this->register( 'theme', 'admin_field', 'Editable original' );
		$this->strings->save_translations(
			$this->language_ids['de'],
			array( $string_id => 'Bearbeitbar' )
		);
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Test-only read filter state.
		$previous_get           = $_GET;
		$previous_server        = $_SERVER;
		$_GET                   = array(
			'page'                 => 'localepress-string-translations',
			'localepress_language' => StringTranslationQuery::ALL_LANGUAGES,
		);
		$_SERVER['HTTP_HOST']   = 'example.org';
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=localepress-string-translations';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$table = new StringTranslationListTable(
			new StringTranslationQuery( $this->repository, $this->languages )
		);
		$table->prepare_items();

		ob_start();
		$table->display();
		$html    = ob_get_clean();
		$_GET    = $previous_get;
		$_SERVER = $previous_server;

		$this->assertSame( StringTranslationQuery::ALL_LANGUAGES, $table->get_query_args()['language_id'] );

		foreach ( $this->language_ids as $language_id ) {
			$this->assertStringContainsString(
				'name="translations[' . $language_id . '][' . $string_id . ']"',
				$html
			);
		}

		// Only the language that has one shows a stored value.
		$this->assertStringContainsString( 'Bearbeitbar', $html );
		$this->assertStringContainsString( 'localepress-string-language', $html );

		// The modifier class is what pairs each name with its own box, so a
		// single-field cell keeps filling the column instead of being indented.
		$this->assertStringContainsString( 'localepress-string-translations--labelled', $html );
	}

	/**
	 * Selecting every language loads their values without a query per language.
	 *
	 * @return void
	 */
	public function test_all_languages_view_bulk_loads_every_value() {
		$string_id = $this->register( 'theme', 'bulk', 'Bulk original' );
		$this->strings->save_translations( $this->language_ids['de'], array( $string_id => 'Sammel' ) );

		$query  = new StringTranslationQuery( $this->repository, $this->languages );
		$before = $GLOBALS['wpdb']->num_queries;
		$result = $query->query( array( 'language_id' => StringTranslationQuery::ALL_LANGUAGES ) );
		$item   = $result['items'][0];

		$this->assertSame( 'Sammel', $item['translations'][ $this->language_ids['de'] ] );
		$this->assertSame( '', $item['translations'][ $this->language_ids['en'] ] );
		$this->assertLessThanOrEqual( 4, $GLOBALS['wpdb']->num_queries - $before );
	}

	/**
	 * Removing a language cleans its string translations through the lifecycle hook.
	 *
	 * @return void
	 */
	public function test_deleting_language_cleans_string_translations() {
		$string_id = $this->register( 'theme', 'cleanup', 'Cleanup' );
		$this->strings->save_translations(
			$this->language_ids['de'],
			array( $string_id => 'Bereinigung' )
		);
		$module = new StringModule( $this->strings );
		$module->register();

		$this->assertTrue( $this->languages->delete( $this->language_ids['de'] ) );
		$this->assertNull( $this->repository->find_translation( $string_id, $this->language_ids['de'] ) );
		remove_action( 'shutdown', array( $this->strings, 'flush_registered_strings' ) );
		remove_action( 'localepress_language_deleted', array( $module, 'delete_language_translations' ) );
	}

	/**
	 * Supplies German as the detected current language.
	 *
	 * @return string
	 */
	public function use_german() {
		return $this->language_ids['de'];
	}

	/**
	 * Registers and persists one test definition.
	 *
	 * @param string $group           String group.
	 * @param string $key             Stable string key.
	 * @param string $original_string Original value.
	 * @return string
	 */
	private function register( $group, $key, $original_string ) {
		$string_id = $this->strings->register_string( $group, $key, $original_string );
		$this->strings->flush_registered_strings();

		return $string_id;
	}

	/**
	 * Removes rows from both custom string tables.
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
	}

	/**
	 * Clears only LocalePress registered-string object-cache entries.
	 *
	 * @return void
	 */
	private function clear_string_cache() {
		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( DatabaseStringRepository::CACHE_GROUP );
		}
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
