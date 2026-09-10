<?php
/**
 * Block editor REST scoping and navigation switcher integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Content\PostTranslationManager;
use LocalePress\Content\PostTypeSupport;
use LocalePress\Infrastructure\DatabaseTermTranslationRepository;
use LocalePress\Infrastructure\DatabaseTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageTag;
use LocalePress\Language\LanguageValidator;
use LocalePress\Rest\RestLanguageModule;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Settings\PluginSettings;
use LocalePress\Switcher\LanguageSwitcher;
use LocalePress\Switcher\NavigationSwitcherBlock;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies language-scoped editor requests and the navigation switcher block.
 */
class Test_LocalePress_Editor_Integration extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * REST language module under test.
	 *
	 * @var RestLanguageModule
	 */
	private $rest;

	/**
	 * Navigation switcher renderer under test.
	 *
	 * @var NavigationSwitcherBlock
	 */
	private $navigation_block;

	/**
	 * Post translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $translations;

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
	 * Post IDs created by a test.
	 *
	 * @var array<int, int>
	 */
	private $post_ids = array();

	/**
	 * Permalink structure present before a test.
	 *
	 * @var string
	 */
	private $permalink_structure = '';

	/**
	 * Prepares isolated language and relationship storage.
	 *
	 * @return void
	 */
	public function set_up() {
		global $wp_rewrite;

		parent::set_up();
		$this->language_ids = array();
		$this->post_ids     = array();

		// The switcher only builds URLs when prefixed routing is possible.
		$this->permalink_structure = (string) get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/%postname%/' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		add_filter( 'localepress_enable_frontend_routing', '__return_true', 20 );

		DatabaseTranslationRepository::install();
		DatabaseTermTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		OptionsLanguageRepository::install();
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

		$settings           = new PluginSettings();
		$this->translations = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$this->languages,
			new PostTypeSupport( $settings )
		);
		$term_translations  = new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$this->languages,
			new TaxonomySupport( $settings )
		);
		$this->rest         = new RestLanguageModule( $this->translations, $term_translations, $this->languages );

		$url_manager            = new LanguageUrlManager(
			$this->languages,
			$this->translations,
			$term_translations,
			$settings
		);
		$this->navigation_block = new NavigationSwitcherBlock(
			new LanguageSwitcher( $this->languages, $url_manager, new LanguageTag(), $settings )
		);
	}

	/**
	 * Removes test content and storage.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rewrite;

		foreach ( array_unique( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		update_option( 'permalink_structure', $this->permalink_structure );
		$wp_rewrite->set_permalink_structure( $this->permalink_structure );
		remove_filter( 'localepress_enable_frontend_routing', '__return_true', 20 );

		parent::tear_down();
	}

	/**
	 * Translatable collections and the editor's link search accept a language.
	 *
	 * @return void
	 */
	public function test_filterable_routes_cover_editor_collections() {
		$routes = $this->rest->get_filterable_routes();

		$this->assertContains( 'wp/v2/posts', $routes );
		$this->assertContains( 'wp/v2/pages', $routes );
		$this->assertContains( 'wp/v2/categories', $routes );
		$this->assertContains( 'wp/v2/search', $routes, 'The link dialog uses the search controller.' );
		$this->assertNotContains( 'wp/v2/users', $routes, 'Untranslatable collections stay untouched.' );
	}

	/**
	 * A marked post collection receives the assignment join.
	 *
	 * @return void
	 */
	public function test_marked_post_collection_is_constrained() {
		$original = array(
			'join'  => '',
			'where' => ' WHERE 1=1',
		);

		$this->assertSame(
			$original,
			$this->rest->filter_posts_by_language( $original, new WP_Query() ),
			'An unmarked query is left alone.'
		);

		$query = new WP_Query();
		$query->set( RestLanguageModule::QUERY_VAR, $this->language_ids['de'] );
		$clauses = $this->rest->filter_posts_by_language( $original, $query );

		$this->assertStringContainsString( DatabaseTranslationRepository::assignments_table(), $clauses['join'] );
		$this->assertStringContainsString( $this->language_ids['de'], $clauses['where'] );
		$this->assertStringNotContainsString( 'post_id IS NULL', $clauses['where'] );
	}

	/**
	 * A marked term collection joins the term assignments on the tt alias.
	 *
	 * @return void
	 */
	public function test_marked_term_collection_is_constrained() {
		$original = array(
			'join'  => '',
			'where' => ' WHERE 1=1',
		);

		$this->assertSame(
			$original,
			$this->rest->filter_terms_by_language( $original, array( 'category' ), array() )
		);

		$clauses = $this->rest->filter_terms_by_language(
			$original,
			array( 'category' ),
			array( RestLanguageModule::QUERY_VAR => $this->language_ids['de'] )
		);

		$this->assertStringContainsString( DatabaseTermTranslationRepository::assignments_table(), $clauses['join'] );
		$this->assertStringContainsString( 'tt.term_taxonomy_id', $clauses['join'] );
		$this->assertStringContainsString( $this->language_ids['de'], $clauses['where'] );
	}

	/**
	 * A term collection reading one object's terms is never constrained.
	 *
	 * @return void
	 */
	public function test_object_term_collection_is_left_unconstrained() {
		$original = array(
			'join'  => '',
			'where' => ' WHERE 1=1',
		);

		$this->assertSame(
			$original,
			$this->rest->filter_terms_by_language(
				$original,
				array( 'category' ),
				array(
					RestLanguageModule::QUERY_VAR => $this->language_ids['de'],
					'object_ids'                  => array( 1 ),
				)
			)
		);
	}

	/**
	 * A term created during a language-tagged REST request starts in that language.
	 *
	 * @return void
	 */
	public function test_new_term_language_follows_the_rest_request() {
		$this->assertSame( '', $this->rest->filter_new_term_language( '' ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/categories' );

		$request->set_param( 'lang', $this->language_ids['de'] );
		$this->rest->capture_request_language( null, null, $request );

		$this->assertSame( $this->language_ids['de'], $this->rest->filter_new_term_language( '' ) );

		// The request language outranks a parent term's language.
		$this->assertSame(
			$this->language_ids['de'],
			$this->rest->filter_new_term_language( $this->language_ids['en'] )
		);
	}

	/**
	 * Default-language collections still include unassigned legacy content.
	 *
	 * @return void
	 */
	public function test_default_language_collection_includes_unassigned_content() {
		$query = new WP_Query();
		$query->set( RestLanguageModule::QUERY_VAR, $this->languages->get_default_id() );

		$clauses = $this->rest->filter_posts_by_language(
			array(
				'join'  => '',
				'where' => ' WHERE 1=1',
			),
			$query
		);

		$this->assertStringContainsString( 'post_id IS NULL', $clauses['where'] );
	}

	/**
	 * Preloaded editor paths carry the edited post's language.
	 *
	 * @return void
	 */
	public function test_preload_paths_receive_the_post_language() {
		$post             = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->post_ids[] = $post;
		$this->translations->set_post_language( $post, $this->language_ids['de'] );

		$context       = new stdClass();
		$context->post = get_post( $post );
		$paths         = $this->rest->add_language_to_preload_paths(
			array(
				'/wp/v2/categories?per_page=10',
				array( '/wp/v2/pages?per_page=5', 'OPTIONS' ),
				'/wp/v2/users/me',
			),
			$context
		);

		$this->assertStringContainsString( 'lang=' . $this->language_ids['de'], $paths[0] );
		$this->assertStringContainsString( 'lang=' . $this->language_ids['de'], $paths[1][0] );
		$this->assertSame( '/wp/v2/users/me', $paths[2], 'Untranslatable paths are untouched.' );
	}

	/**
	 * The navigation switcher renders native navigation items.
	 *
	 * @return void
	 */
	public function test_navigation_switcher_renders_core_navigation_items() {
		$output = $this->navigation_block->render( array( 'display' => 'name' ) );

		$this->assertStringContainsString( 'wp-block-navigation-item', $output );
		$this->assertStringContainsString( 'German', $output );
		$this->assertStringContainsString( 'localepress-switcher__item', $output );
		$this->assertStringNotContainsString( 'localepress-switcher-label-token', $output );
	}

	/**
	 * Dropdown mode renders one submenu instead of separate items.
	 *
	 * @return void
	 */
	public function test_navigation_switcher_renders_a_submenu_in_dropdown_mode() {
		$output = $this->navigation_block->render(
			array(
				'display'  => 'name',
				'dropdown' => true,
			)
		);

		$this->assertStringContainsString( 'wp-block-navigation-submenu', $output );
		$this->assertStringNotContainsString( 'localepress-switcher-label-token', $output );
	}

	/**
	 * Flags survive the label escaping WordPress applies to navigation links.
	 *
	 * @return void
	 */
	public function test_navigation_switcher_renders_flags() {
		$flag_filter = static function ( $url, $language ) {
			return 'https://example.org/flags/' . $language['language_code'] . '.svg';
		};

		add_filter( 'localepress_switcher_flag_url', $flag_filter, 10, 2 );
		$output = $this->navigation_block->render(
			array(
				'display'   => 'name',
				'showFlags' => true,
			)
		);
		remove_filter( 'localepress_switcher_flag_url', $flag_filter, 10 );

		$this->assertStringContainsString( 'flags/de.svg', $output );
		$this->assertStringContainsString( 'localepress-switcher__flag', $output );
		$this->assertStringContainsString( 'German', $output );
		$this->assertStringNotContainsString( 'localepress-switcher-label-token', $output );
	}

	/**
	 * Clears LocalePress custom relationship tables.
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
