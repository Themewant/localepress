<?php
/**
 * SEO plugin field integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Contracts\SeoProviderInterface;
use LocalePress\Infrastructure\DatabaseStringRepository;
use LocalePress\Infrastructure\DatabaseTermTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Integrations\Seo\RankMathProvider;
use LocalePress\Integrations\Seo\SeoMetaModule;
use LocalePress\Integrations\Seo\SeoPressProvider;
use LocalePress\Integrations\Seo\YoastSeoProvider;
use LocalePress\Language\CurrentLanguageResolver;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\StringTranslation\OptionStringTranslator;
use LocalePress\StringTranslation\RegisteredStringValidator;
use LocalePress\StringTranslation\StringManager;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * A provider whose plugin is always reported as loaded.
 *
 * The shipped providers detect their plugin through a constant, and a constant
 * cannot be taken back once defined. Defining one here would follow the whole
 * suite into every later test, so the module is exercised through a provider
 * that reports itself instead.
 */
class LocalePress_Test_Seo_Provider implements SeoProviderInterface {

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'test-seo';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_active() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_translatable_meta_keys() {
		return array( '_test_seo_title', '_test_seo_description', 'test_seo_public_title' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_copied_meta_keys() {
		return array( '_test_seo_image', '_test_seo_noindex' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_primary_term_meta_keys() {
		return array(
			'_test_seo_primary_category'  => 'category',
			'_test_seo_primary_post_tag'  => 'post_tag',
			'_test_seo_primary_untracked' => 'localepress_untracked_tax',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_option_declarations() {
		return array( 'Test SEO' => array( 'test_seo_titles' => array( 'title-*' => true ) ) );
	}
}

/**
 * An inactive provider, to prove nothing of it reaches a translation.
 */
class LocalePress_Test_Inactive_Seo_Provider extends LocalePress_Test_Seo_Provider {

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'inactive-seo';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_active() {
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_translatable_meta_keys() {
		return array( '_inactive_seo_title' );
	}
}

/**
 * Verifies which SEO fields travel with a translation, and which stay behind.
 */
class Test_LocalePress_Seo_Meta extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Module under test.
	 *
	 * @var SeoMetaModule
	 */
	private $module;

	/**
	 * Term translation manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Copy and synchronization settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Registered language IDs keyed by language code.
	 *
	 * @var array<string, string>
	 */
	private $language_ids = array();

	/**
	 * Term IDs created by a test.
	 *
	 * @var array<int, int>
	 */
	private $term_ids = array();

	/**
	 * Prepares isolated language and relationship storage.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->language_ids = array();
		$this->term_ids     = array();

		DatabaseTermTranslationRepository::install();
		DatabaseStringRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		OptionsLanguageRepository::install();
		WorkflowSettings::install();

		$this->languages = new LanguageManager( new OptionsLanguageRepository(), new LanguageValidator() );

		foreach (
			array(
				array( 'English', 'en_US', 'en' ),
				array( 'Bengali', 'bn_BD', 'bn' ),
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

		$this->workflow          = new WorkflowSettings();
		$this->term_translations = new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$this->languages,
			new TaxonomySupport()
		);

		$this->module = new SeoMetaModule(
			array( new LocalePress_Test_Seo_Provider(), new LocalePress_Test_Inactive_Seo_Provider() ),
			$this->term_translations,
			$this->workflow,
			new OptionStringTranslator(
				new StringManager(
					new DatabaseStringRepository(),
					$this->languages,
					new CurrentLanguageResolver( $this->languages ),
					new RegisteredStringValidator()
				)
			),
			$this->languages
		);
	}

	/**
	 * Removes test content and storage.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( array_unique( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * A plugin that is not loaded contributes nothing.
	 *
	 * @return void
	 */
	public function test_only_loaded_plugins_take_part() {
		$active = $this->module->get_active_providers();

		$this->assertCount( 1, $active );
		$this->assertSame( 'test-seo', $active[0]->get_name() );

		$this->assertNotContains(
			'_inactive_seo_title',
			$this->module->filter_meta_keys( array(), false, 1, 2, $this->language_ids['bn'] )
		);
	}

	/**
	 * A new translation opens with the source's SEO fields to translate from.
	 *
	 * @return void
	 */
	public function test_a_new_translation_receives_the_seo_fields() {
		$keys = $this->module->filter_meta_keys(
			array( 'some_theme_field' ),
			false,
			1,
			2,
			$this->language_ids['bn']
		);

		$this->assertContains( '_test_seo_title', $keys );
		$this->assertContains( '_test_seo_description', $keys );
		$this->assertContains( '_test_seo_image', $keys );
		$this->assertContains( '_test_seo_noindex', $keys );

		// What the engine resolved on its own is never taken away.
		$this->assertContains( 'some_theme_field', $keys );

		// A plugin storing a field under an unprefixed key is listed once, not
		// once as a custom field and again as an SEO field.
		$listed = $this->module->filter_meta_keys(
			array( 'test_seo_public_title' ),
			false,
			1,
			2,
			$this->language_ids['bn']
		);

		$this->assertSame( array_values( array_unique( $listed ) ), $listed );
	}

	/**
	 * Only taxonomies the site translates get a primary-term key.
	 *
	 * @return void
	 */
	public function test_primary_term_keys_follow_translated_taxonomies() {
		$keys = $this->module->filter_meta_keys( array(), false, 1, 2, $this->language_ids['bn'] );

		$this->assertContains( '_test_seo_primary_category', $keys );
		$this->assertContains( '_test_seo_primary_post_tag', $keys );

		// A taxonomy that is not registered at all cannot be translated.
		$this->assertNotContains( '_test_seo_primary_untracked', $keys );
	}

	/**
	 * Translated text is withdrawn from synchronization so it survives an edit.
	 *
	 * @return void
	 */
	public function test_translated_text_is_not_overwritten_later() {
		$this->workflow->update( array( 'sync_post_meta' => true, 'sync_taxonomies' => true ) );

		$keys = $this->module->filter_meta_keys(
			// The unprefixed key arrives from the generic custom-field sync, which
			// is exactly the path that would overwrite a translated title.
			array( 'test_seo_public_title', 'some_theme_field' ),
			true,
			1,
			2,
			$this->language_ids['bn']
		);

		$this->assertNotContains( 'test_seo_public_title', $keys );
		$this->assertNotContains( '_test_seo_title', $keys );
		$this->assertNotContains( '_test_seo_description', $keys );

		// Nothing else the engine listed is disturbed.
		$this->assertContains( 'some_theme_field', $keys );
	}

	/**
	 * A primary term keeps following the source only while terms are synced.
	 *
	 * @return void
	 */
	public function test_primary_term_follows_the_taxonomy_setting() {
		$this->workflow->update( array( 'sync_taxonomies' => true ) );
		$this->assertContains(
			'_test_seo_primary_category',
			$this->module->filter_meta_keys( array(), true, 1, 2, $this->language_ids['bn'] )
		);

		$this->workflow->update( array( 'sync_taxonomies' => false ) );
		$this->assertNotContains(
			'_test_seo_primary_category',
			$this->module->filter_meta_keys( array(), true, 1, 2, $this->language_ids['bn'] )
		);
	}

	/**
	 * A copied primary term resolves to the translation's own term.
	 *
	 * @return void
	 */
	public function test_primary_term_is_resolved_to_the_target_language() {
		$english = self::factory()->category->create( array( 'name' => 'News' ) );
		$bengali = self::factory()->category->create( array( 'name' => 'Khobor' ) );

		$this->term_ids[] = $english;
		$this->term_ids[] = $bengali;

		$this->assertNotWPError(
			$this->term_translations->link_translations(
				array(
					$this->language_ids['en'] => $english,
					$this->language_ids['bn'] => $bengali,
				),
				'category',
				$english
			)
		);

		$this->assertSame(
			$bengali,
			$this->module->translate_meta_value( $english, '_test_seo_primary_category', $this->language_ids['bn'] )
		);
	}

	/**
	 * An untranslated term keeps the identifier it had.
	 *
	 * @return void
	 */
	public function test_an_untranslated_primary_term_is_left_alone() {
		$english          = self::factory()->category->create( array( 'name' => 'Sport' ) );
		$this->term_ids[] = $english;

		$this->assertSame(
			$english,
			$this->module->translate_meta_value( $english, '_test_seo_primary_category', $this->language_ids['bn'] )
		);
	}

	/**
	 * Values this module does not own are passed through untouched.
	 *
	 * @return void
	 */
	public function test_unrelated_values_are_untouched() {
		$language = $this->language_ids['bn'];

		$this->assertSame( 42, $this->module->translate_meta_value( 42, 'some_theme_field', $language ) );
		$this->assertSame( 'abc', $this->module->translate_meta_value( 'abc', '_test_seo_primary_category', $language ) );
		$this->assertSame( 0, $this->module->translate_meta_value( 0, '_test_seo_primary_category', $language ) );

		$array = array( 'a' => 1 );
		$this->assertSame( $array, $this->module->translate_meta_value( $array, '_test_seo_primary_category', $language ) );
	}

	/**
	 * No shipped provider carries a canonical into a translation.
	 *
	 * A canonical names one address and a translation has its own, so copying
	 * one would point every language at the source and remove them all from the
	 * index. This is the single mistake worth a test of its own.
	 *
	 * @return void
	 */
	public function test_no_provider_copies_a_canonical() {
		foreach ( array( new YoastSeoProvider(), new RankMathProvider(), new SeoPressProvider() ) as $provider ) {
			$keys = array_merge(
				$provider->get_translatable_meta_keys(),
				$provider->get_copied_meta_keys(),
				array_keys( $provider->get_primary_term_meta_keys() )
			);

			foreach ( $keys as $key ) {
				$this->assertStringNotContainsStringIgnoringCase(
					'canonical',
					$key,
					$provider->get_name() . ' must not carry a canonical into a translation.'
				);
			}
		}
	}

	/**
	 * Every shipped provider names fields and detects its plugin by constant.
	 *
	 * @return void
	 */
	public function test_shipped_providers_are_complete_and_inert() {
		foreach ( array( new YoastSeoProvider(), new RankMathProvider(), new SeoPressProvider() ) as $provider ) {
			$name = $provider->get_name();

			$this->assertNotEmpty( $provider->get_translatable_meta_keys(), "$name names no translatable field." );
			$this->assertNotEmpty( $provider->get_copied_meta_keys(), "$name names no carried field." );
			$this->assertNotEmpty( $provider->get_option_declarations(), "$name names no option." );

			// None of these plugins is installed in the test suite, so every
			// provider has to report itself absent rather than assume.
			$this->assertFalse( $provider->is_active(), "$name reported itself loaded." );

			foreach ( $provider->get_primary_term_meta_keys() as $meta_key => $taxonomy ) {
				$this->assertIsString( $meta_key );
				$this->assertTrue( taxonomy_exists( $taxonomy ), "$name names an unregistered taxonomy." );
			}
		}
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
