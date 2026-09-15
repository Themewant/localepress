<?php
/**
 * Per-language core sitemap tests.
 *
 * @package LocalePress
 */

use LocalePress\Content\PostTranslationManager;
use LocalePress\Content\PostTypeSupport;
use LocalePress\Infrastructure\DatabaseTermTranslationRepository;
use LocalePress\Infrastructure\DatabaseTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\SEO\SitemapLanguageProvider;
use LocalePress\SEO\SitemapModule;
use LocalePress\Settings\PluginSettings;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies that the sitemap index is divided by language, and when it is not.
 */
class Test_LocalePress_Sitemap_Languages extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Module under test.
	 *
	 * @var SitemapModule
	 */
	private $module;

	/**
	 * Central settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

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
	 * Prepares isolated language storage and a module wired to it.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->language_ids = array();

		DatabaseTranslationRepository::install();
		DatabaseTermTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		OptionsLanguageRepository::install();

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

		$this->settings    = new PluginSettings();
		$post_translations = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$this->languages,
			new PostTypeSupport( $this->settings ),
			new WorkflowSettings()
		);
		$term_translations = new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$this->languages,
			new TaxonomySupport( $this->settings )
		);

		$this->module = new SitemapModule(
			new LanguageUrlManager( $this->languages, $post_translations, $term_translations, $this->settings ),
			$this->languages,
			$post_translations,
			$term_translations,
			$this->settings
		);
	}

	/**
	 * Removes test storage.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * Two languages sharing a host are worth dividing; one is not.
	 *
	 * @return void
	 */
	public function test_split_applies_only_where_it_says_something() {
		$this->assertTrue( $this->module->splits_by_language() );

		$this->settings->update_sections( array( 'seo' => array( 'split_sitemaps' => false ) ) );
		$this->assertFalse( $this->fresh_module()->splits_by_language() );
	}

	/**
	 * A site with a single language keeps a single sitemap.
	 *
	 * @return void
	 */
	public function test_one_language_is_never_split() {
		$this->assertNotWPError( $this->languages->delete( $this->language_ids['bn'] ) );

		$this->assertFalse( $this->fresh_module()->splits_by_language() );
	}

	/**
	 * Only the post types and taxonomies the site translates are divided.
	 *
	 * @return void
	 */
	public function test_untranslated_subtypes_are_not_divided() {
		$this->assertTrue( $this->module->is_translatable_subtype( 'posts', 'post' ) );
		$this->assertTrue( $this->module->is_translatable_subtype( 'posts', 'page' ) );
		$this->assertTrue( $this->module->is_translatable_subtype( 'taxonomies', 'category' ) );

		// Not enabled in the default content settings.
		$this->assertFalse( $this->module->is_translatable_subtype( 'posts', 'attachment' ) );

		// A provider this module cannot scope is never treated as divisible.
		$this->assertFalse( $this->module->is_translatable_subtype( 'users', 'post' ) );
	}

	/**
	 * Each divided sitemap is addressed in the language it lists.
	 *
	 * @return void
	 */
	public function test_sitemap_addresses_carry_their_language() {
		$url = home_url( '/wp-sitemap-posts-post-1.xml' );

		$this->assertSame(
			$url,
			$this->module->localize_sitemap_url( $url, $this->language_ids['en'] ),
			'The default language keeps the unprefixed address.'
		);
		$this->assertStringContainsString(
			'/bn/wp-sitemap-posts-post-1.xml',
			$this->module->localize_sitemap_url( $url, $this->language_ids['bn'] )
		);
	}

	/**
	 * A scoped language applies only for the duration of the callback.
	 *
	 * @return void
	 */
	public function test_language_scope_is_released_again() {
		$this->assertSame( '', $this->module->get_scoped_language_id() );

		$seen = $this->module->with_language(
			$this->language_ids['bn'],
			function () {
				return $this->module->get_scoped_language_id();
			}
		);

		$this->assertSame( $this->language_ids['bn'], $seen );
		$this->assertSame( '', $this->module->get_scoped_language_id() );
	}

	/**
	 * Asking for every language is a scope of its own, not the absence of one.
	 *
	 * An undivided sitemap is counted and served across every listed language.
	 * If that were expressed by naming no language, the count would be read as
	 * belonging to whichever language the index was fetched under.
	 *
	 * @return void
	 */
	public function test_an_empty_scope_still_counts_as_asked() {
		$nested = $this->module->with_language(
			$this->language_ids['bn'],
			function () {
				return $this->module->with_language(
					'',
					function () {
						return $this->module->get_scoped_language_id();
					}
				);
			}
		);

		$this->assertSame( '', $nested );

		// And the language around it is restored rather than lost.
		$restored = $this->module->with_language(
			$this->language_ids['bn'],
			function () {
				$this->module->with_language(
					'',
					static function () {
						return null;
					}
				);

				return $this->module->get_scoped_language_id();
			}
		);

		$this->assertSame( $this->language_ids['bn'], $restored );
	}

	/**
	 * A scope survives the callback throwing, so one failure cannot leak into
	 * every later sitemap query.
	 *
	 * @return void
	 */
	public function test_language_scope_is_released_after_a_failure() {
		try {
			$this->module->with_language(
				$this->language_ids['bn'],
				static function () {
					throw new RuntimeException( 'counting failed' );
				}
			);
		} catch ( RuntimeException $exception ) {
			unset( $exception );
		}

		$this->assertSame( '', $this->module->get_scoped_language_id() );
	}

	/**
	 * Only the two providers whose queries can be scoped are wrapped.
	 *
	 * @return void
	 */
	public function test_only_scopable_providers_are_wrapped() {
		$this->assertInstanceOf(
			SitemapLanguageProvider::class,
			$this->module->replace_provider( new WP_Sitemaps_Posts() )
		);
		$this->assertInstanceOf(
			SitemapLanguageProvider::class,
			$this->module->replace_provider( new WP_Sitemaps_Taxonomies() )
		);

		$users = new WP_Sitemaps_Users();
		$this->assertSame( $users, $this->module->replace_provider( $users ) );

		$this->assertSame( 'not a provider', $this->module->replace_provider( 'not a provider' ) );
	}

	/**
	 * A wrapped provider keeps the identity core registered it under.
	 *
	 * @return void
	 */
	public function test_wrapped_provider_keeps_its_identity() {
		$core    = new WP_Sitemaps_Posts();
		$wrapped = $this->module->replace_provider( $core );

		$this->assertSame( $core->name, $wrapped->name );
		$this->assertSame( $core->object_type, $wrapped->object_type );
		$this->assertSame( $core->get_object_subtypes(), $wrapped->get_object_subtypes() );
	}

	/**
	 * The separator that carries a language internally never reaches a URL.
	 *
	 * @return void
	 */
	public function test_separator_never_reaches_an_address() {
		$wrapped = $this->module->replace_provider( new WP_Sitemaps_Posts() );

		foreach ( $wrapped->get_sitemap_entries() as $entry ) {
			$this->assertIsArray( $entry );
			$this->assertArrayHasKey( 'loc', $entry );
			$this->assertStringNotContainsString( SitemapLanguageProvider::SEPARATOR, $entry['loc'] );
		}
	}

	/**
	 * Turning the split off leaves the providers exactly as core made them.
	 *
	 * @return void
	 */
	public function test_disabled_split_leaves_providers_alone() {
		$this->settings->update_sections( array( 'seo' => array( 'split_sitemaps' => false ) ) );

		$core = new WP_Sitemaps_Posts();
		$this->assertSame( $core, $this->fresh_module()->replace_provider( $core ) );
	}

	/**
	 * Returns a module reading the settings as they stand now.
	 *
	 * The module resolves its languages once, so a test that changes settings
	 * or languages asks a new one rather than a stale cache.
	 *
	 * @return SitemapModule
	 */
	private function fresh_module() {
		$settings          = new PluginSettings();
		$post_translations = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$this->languages,
			new PostTypeSupport( $settings ),
			new WorkflowSettings()
		);
		$term_translations = new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$this->languages,
			new TaxonomySupport( $settings )
		);

		return new SitemapModule(
			new LanguageUrlManager( $this->languages, $post_translations, $term_translations, $settings ),
			$this->languages,
			$post_translations,
			$term_translations,
			$settings
		);
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
