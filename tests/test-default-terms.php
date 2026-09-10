<?php
/**
 * Per-language default term integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Content\PostTranslationManager;
use LocalePress\Content\PostTypeSupport;
use LocalePress\Infrastructure\DatabaseTermTranslationRepository;
use LocalePress\Infrastructure\DatabaseTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\CurrentLanguageResolver;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Settings\PluginSettings;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\Taxonomy\DefaultTermModule;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies that each language keeps its own default category.
 */
class Test_LocalePress_Default_Terms extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Module under test.
	 *
	 * @var DefaultTermModule
	 */
	private $module;

	/**
	 * Post translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $translations;

	/**
	 * Term translation manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $plugin_settings;

	/**
	 * Registered language IDs keyed by language code.
	 *
	 * @var array<string, string>
	 */
	private $language_ids = array();

	/**
	 * Post IDs created by a test.
	 *
	 * @var array<int, int>
	 */
	private $post_ids = array();

	/**
	 * Term IDs created by a test.
	 *
	 * @var array<int, int>
	 */
	private $term_ids = array();

	/**
	 * Default category stored before a test replaced it.
	 *
	 * @var int
	 */
	private $stored_default = 0;

	/**
	 * Prepares isolated language and relationship storage.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->language_ids   = array();
		$this->post_ids       = array();
		$this->term_ids       = array();
		$this->stored_default = (int) get_option( 'default_category', 0 );

		DatabaseTranslationRepository::install();
		DatabaseTermTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		OptionsLanguageRepository::install();
		WorkflowSettings::install();
		PluginSettings::install();

		$this->languages = new LanguageManager( new OptionsLanguageRepository(), new LanguageValidator() );

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

		$this->translations      = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$this->languages,
			new PostTypeSupport(),
			new WorkflowSettings()
		);
		$this->term_translations = new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$this->languages,
			new TaxonomySupport()
		);
		$this->plugin_settings   = new PluginSettings();
		$this->module            = new DefaultTermModule(
			$this->term_translations,
			$this->translations,
			$this->languages,
			new TaxonomySupport(),
			new CurrentLanguageResolver( $this->languages ),
			$this->plugin_settings
		);

		$this->module->register();
	}

	/**
	 * Removes test content, hooks, and storage.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'pre_option_default_category', array( $this->module, 'filter_default_term' ), 10 );
		remove_action( 'wp_loaded', array( $this->module, 'register_taxonomy_filters' ) );
		remove_filter( 'rest_pre_dispatch', array( $this->module, 'capture_request_language' ), 10 );
		remove_action( 'wp_after_insert_post', array( $this->module, 'realign_default_terms' ), 25 );
		remove_action( 'localepress_language_registered', array( $this->module, 'seed_registered_language' ) );
		remove_action( 'localepress_language_updated', array( $this->module, 'seed_enabled_language' ), 10 );
		remove_action( 'admin_init', array( $this->module, 'seed_existing_languages' ) );
		delete_option( DefaultTermModule::SEEDED_OPTION );

		foreach ( array_unique( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		foreach ( array_unique( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		update_option( 'default_category', $this->stored_default );
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * A translated draft starts in the default category of its own language.
	 *
	 * @return void
	 */
	public function test_translated_draft_uses_the_default_category_of_its_language() {
		list( $english, $german ) = $this->create_linked_defaults();

		$source = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->post_ids[] = $source;

		$translation = $this->translations->create_translation( $source, $this->language_ids['de'] );

		$this->assertNotWPError( $translation );

		$this->post_ids[] = $translation;

		$this->assertSame( array( $german ), $this->category_ids( $translation ) );
		$this->assertSame( array( $english ), $this->category_ids( $source ) );
	}

	/**
	 * A post left in the site-wide default moves to its own language.
	 *
	 * @return void
	 */
	public function test_saved_post_moves_from_the_site_default_to_its_language() {
		list( , $german ) = $this->create_linked_defaults();

		$post_id = $this->create_post_in( 'de' );

		wp_update_post( array( 'ID' => $post_id ) );

		$this->assertSame( array( $german ), $this->category_ids( $post_id ) );
	}

	/**
	 * A chosen category is never rewritten by the language alignment.
	 *
	 * @return void
	 */
	public function test_a_chosen_category_is_left_alone() {
		list( $english ) = $this->create_linked_defaults();

		$chosen = self::factory()->category->create( array( 'name' => 'Reports' ) );

		$this->term_ids[] = $chosen;

		$post_id = $this->create_post_in( 'de' );

		wp_set_object_terms( $post_id, array( $english, $chosen ), 'category' );
		wp_update_post( array( 'ID' => $post_id ) );

		$this->assertSame( array( $english, $chosen ), $this->category_ids( $post_id ) );
	}

	/**
	 * An untranslated default category stays as it is stored.
	 *
	 * @return void
	 */
	public function test_untranslated_default_category_is_kept() {
		$english          = self::factory()->category->create( array( 'name' => 'Uncategorized EN' ) );
		$this->term_ids[] = $english;

		update_option( 'default_category', $english );

		$post_id = $this->create_post_in( 'de' );

		wp_update_post( array( 'ID' => $post_id ) );

		$this->assertSame( array( $english ), $this->category_ids( $post_id ) );
	}

	/**
	 * Reading the option outside a save returns the stored default.
	 *
	 * @return void
	 */
	public function test_option_read_without_a_saving_language_is_unchanged() {
		list( $english ) = $this->create_linked_defaults();

		$this->assertSame( $english, (int) get_option( 'default_category' ) );
	}

	/**
	 * A term chosen for a language is used instead of the translated default.
	 *
	 * @return void
	 */
	public function test_chosen_default_wins_over_the_translated_default() {
		$this->create_linked_defaults();

		$chosen = self::factory()->category->create( array( 'name' => 'Nachrichten' ) );

		$this->term_ids[] = $chosen;

		$this->assertNotWPError(
			$this->term_translations->set_term_language( $chosen, 'category', $this->language_ids['de'] )
		);

		$this->plugin_settings->update_sections(
			array(
				'content' => array(
					'default_terms' => array( 'category' => array( $this->language_ids['de'] => $chosen ) ),
				),
			)
		);

		$post_id = $this->create_post_in( 'de' );

		wp_update_post( array( 'ID' => $post_id ) );

		$this->assertSame( array( $chosen ), $this->category_ids( $post_id ) );
	}

	/**
	 * A chosen default that was deleted falls back to the stored default.
	 *
	 * @return void
	 */
	public function test_deleted_choice_falls_back_to_the_stored_default() {
		list( $english ) = $this->create_linked_defaults();

		$removed = self::factory()->category->create( array( 'name' => 'Temporary' ) );

		$this->plugin_settings->update_sections(
			array(
				'content' => array(
					'default_terms' => array( 'category' => array( $this->language_ids['de'] => $removed ) ),
				),
			)
		);
		wp_delete_term( $removed, 'category' );

		$post_id = $this->create_post_in( 'de' );

		wp_set_object_terms( $post_id, array( $english ), 'category' );
		wp_update_post( array( 'ID' => $post_id ) );

		$this->assertSame( array( $english ), $this->category_ids( $post_id ) );
	}

	/**
	 * A newly registered language is given a translation of the default term.
	 *
	 * @return void
	 */
	public function test_registering_a_language_translates_the_default_term() {
		list( $english ) = $this->create_linked_defaults();

		$french = $this->register_language( 'French', 'fr_FR', 'fr', true );

		$translation = $this->term_translations->get_translation( $english, 'category', $french );

		$this->assertGreaterThan( 0, $translation );

		$this->term_ids[] = $translation;

		$this->assertSame(
			$french,
			$this->term_translations->get_term_language_id( $translation, 'category' )
		);
	}

	/**
	 * A post written in a language added later lands in its own default term.
	 *
	 * @return void
	 */
	public function test_a_seeded_default_term_is_used_by_that_language() {
		list( $english ) = $this->create_linked_defaults();

		$french      = $this->register_language( 'French', 'fr_FR', 'fr', true );
		$translation = $this->term_translations->get_translation( $english, 'category', $french );

		$this->assertGreaterThan( 0, $translation );

		$this->term_ids[] = $translation;

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->post_ids[] = $post_id;

		$this->assertNotWPError( $this->translations->set_post_language( $post_id, $french ) );
		wp_update_post( array( 'ID' => $post_id ) );

		$this->assertSame( array( $translation ), $this->category_ids( $post_id ) );
	}

	/**
	 * A language registered as disabled is seeded when it is enabled instead.
	 *
	 * @return void
	 */
	public function test_a_disabled_language_is_seeded_once_enabled() {
		list( $english ) = $this->create_linked_defaults();

		$french = $this->register_language( 'French', 'fr_FR', 'fr', false );

		$this->assertSame( 0, $this->term_translations->get_translation( $english, 'category', $french ) );

		$this->enable_language( 'French', 'fr_FR', 'fr' );

		$translation = $this->term_translations->get_translation( $english, 'category', $french );

		$this->assertGreaterThan( 0, $translation );

		$this->term_ids[] = $translation;
	}

	/**
	 * A language registered before seeding existed is backfilled once.
	 *
	 * @return void
	 */
	public function test_a_language_that_predates_seeding_is_backfilled() {
		$english = self::factory()->category->create( array( 'name' => 'Uncategorized EN' ) );

		$this->term_ids[] = $english;

		update_option( 'default_category', $english );
		$this->assertNotWPError(
			$this->term_translations->set_term_language( $english, 'category', $this->language_ids['en'] )
		);

		// German was registered in set_up, before the module was registered.
		$this->assertSame( 0, $this->term_translations->get_translation( $english, 'category', $this->language_ids['de'] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->module->seed_existing_languages();

		$translation = $this->term_translations->get_translation( $english, 'category', $this->language_ids['de'] );

		$this->assertGreaterThan( 0, $translation );

		$this->term_ids[] = $translation;

		// The languages handled are recorded, so a second pass creates nothing.
		$before = $this->category_count();

		$this->module->seed_existing_languages();

		$this->assertSame( $before, $this->category_count() );
	}

	/**
	 * A language that already has a translation of the default gets no second one.
	 *
	 * @return void
	 */
	public function test_an_existing_translation_is_not_duplicated() {
		list( $english ) = $this->create_linked_defaults();

		$french = $this->register_language( 'French', 'fr_FR', 'fr', false );
		$chosen = self::factory()->category->create( array( 'name' => 'Non classe' ) );

		$this->term_ids[] = $chosen;

		$this->assertNotWPError(
			$this->term_translations->link_translations(
				array(
					$this->language_ids['en'] => $english,
					$french                   => $chosen,
				),
				'category',
				$english
			)
		);

		$before = $this->category_count();

		$this->enable_language( 'French', 'fr_FR', 'fr' );

		$this->assertSame( $before, $this->category_count() );
		$this->assertSame(
			$chosen,
			$this->term_translations->get_translation( $english, 'category', $french )
		);
	}

	/**
	 * Returns how many categories the site holds.
	 *
	 * @return int
	 */
	private function category_count() {
		return count(
			get_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			)
		);
	}

	/**
	 * Enables a previously disabled language.
	 *
	 * @param string $name   Language name.
	 * @param string $locale Locale.
	 * @param string $code   Language code and URL slug.
	 * @return void
	 */
	private function enable_language( $name, $locale, $code ) {
		$this->assertNotWPError(
			$this->languages->update(
				$this->language_ids[ $code ],
				array(
					'name'          => $name,
					'native_name'   => $name,
					'locale'        => $locale,
					'language_code' => $code,
					'url_slug'      => $code,
					'is_rtl'        => false,
					'enabled'       => true,
				)
			)
		);
	}

	/**
	 * Registers one more language and returns its identifier.
	 *
	 * @param string $name    Language name.
	 * @param string $locale  Locale.
	 * @param string $code    Language code and URL slug.
	 * @param bool   $enabled Whether the language is enabled.
	 * @return string
	 */
	private function register_language( $name, $locale, $code, $enabled ) {
		$language = $this->languages->create(
			array(
				'name'          => $name,
				'native_name'   => $name,
				'locale'        => $locale,
				'language_code' => $code,
				'url_slug'      => $code,
				'is_rtl'        => false,
				'enabled'       => $enabled,
			)
		);

		$this->assertNotWPError( $language );

		$this->language_ids[ $code ] = $language['id'];

		return $language['id'];
	}

	/**
	 * Creates a default category with a linked German counterpart.
	 *
	 * @return array<int, int> English and German default term identifiers.
	 */
	private function create_linked_defaults() {
		$english = self::factory()->category->create( array( 'name' => 'Uncategorized EN' ) );
		$german  = self::factory()->category->create( array( 'name' => 'Uncategorized DE' ) );

		$this->term_ids[] = $english;
		$this->term_ids[] = $german;

		update_option( 'default_category', $english );

		$this->assertNotWPError(
			$this->term_translations->link_translations(
				array(
					$this->language_ids['en'] => $english,
					$this->language_ids['de'] => $german,
				),
				'category',
				$english
			)
		);

		return array( $english, $german );
	}

	/**
	 * Creates a post assigned to one language.
	 *
	 * @param string $code Language code.
	 * @return int
	 */
	private function create_post_in( $code ) {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->post_ids[] = $post_id;

		$this->assertNotWPError( $this->translations->set_post_language( $post_id, $this->language_ids[ $code ] ) );

		return $post_id;
	}

	/**
	 * Returns the category identifiers assigned to a post.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<int, int>
	 */
	private function category_ids( $post_id ) {
		$terms = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
		$terms = is_wp_error( $terms ) ? array() : array_map( 'absint', (array) $terms );

		sort( $terms );

		return $terms;
	}

	/**
	 * Empties the relationship tables between tests.
	 *
	 * @return void
	 */
	private function clear_relationship_tables() {
		global $wpdb;

		foreach (
			array(
				DatabaseTranslationRepository::assignments_table(),
				DatabaseTranslationRepository::groups_table(),
				DatabaseTermTranslationRepository::assignments_table(),
				DatabaseTermTranslationRepository::groups_table(),
			) as $table
		) {
			// Table names are generated exclusively by LocalePress repositories.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table}" );
		}
	}
}
