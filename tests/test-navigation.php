<?php
/**
 * Multilingual navigation integration tests.
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
use LocalePress\Navigation\MenuLanguageManager;
use LocalePress\Navigation\NavigationModule;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies Phase 6 navigation behavior through WordPress menu APIs.
 */
class Test_LocalePress_Navigation extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Post translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Menu language manager.
	 *
	 * @var MenuLanguageManager
	 */
	private $menu_manager;

	/**
	 * Navigation module.
	 *
	 * @var NavigationModule
	 */
	private $navigation;

	/**
	 * Language IDs keyed by language code.
	 *
	 * @var array<string, string>
	 */
	private $language_ids = array();

	/**
	 * Created post IDs.
	 *
	 * @var array<int, int>
	 */
	private $post_ids = array();

	/**
	 * Created term IDs.
	 *
	 * @var array<int, int>
	 */
	private $term_ids = array();

	/**
	 * Created menu IDs.
	 *
	 * @var array<int, int>
	 */
	private $menu_ids = array();

	/**
	 * Original permalink structure.
	 *
	 * @var string
	 */
	private $permalink_structure;

	/**
	 * Request URI present before a test.
	 *
	 * @var string
	 */
	private $request_uri;

	/**
	 * Original LocalePress menu-location theme mod.
	 *
	 * @var mixed
	 */
	private $location_theme_mod;

	/**
	 * Prepares isolated language, relationship, menu, and routing state.
	 *
	 * @return void
	 */
	public function set_up() {
		global $wp_rewrite;

		parent::set_up();

		$this->permalink_structure = (string) get_option( 'permalink_structure' );
		$this->request_uri         = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';
		$this->location_theme_mod  = get_theme_mod( MenuLanguageManager::LOCATION_THEME_MOD, null );
		$this->post_ids            = array();
		$this->term_ids            = array();
		$this->menu_ids            = array();
		$this->language_ids        = array();

		update_option( 'permalink_structure', '/%postname%/' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		get_taxonomy( 'category' )->add_rewrite_rules();
		remove_theme_mod( MenuLanguageManager::LOCATION_THEME_MOD );
		register_nav_menu( 'localepress_primary', 'LocalePress Primary' );
		add_filter( 'localepress_enable_frontend_routing', '__return_true', 20 );
		add_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		add_filter( 'localepress_auto_assign_default_term_language', '__return_false' );

		DatabaseTranslationRepository::install();
		DatabaseTermTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		OptionsLanguageRepository::install();
		WorkflowSettings::install();

		$this->languages = new LanguageManager(
			new OptionsLanguageRepository(),
			new LanguageValidator()
		);

		foreach (
			array(
				array( 'English', 'en_US', 'en' ),
				array( 'German', 'de_DE', 'de' ),
				array( 'French', 'fr_FR', 'fr' ),
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

		$this->post_translations = new PostTranslationManager(
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
		$this->menu_manager      = new MenuLanguageManager( $this->languages );
		$urls                    = new LanguageUrlManager(
			$this->languages,
			$this->post_translations,
			$this->term_translations
		);
		$this->navigation        = new NavigationModule(
			$this->menu_manager,
			$this->post_translations,
			$this->term_translations,
			$urls
		);

		$this->set_request_language( 'de', '/de/' );
	}

	/**
	 * Removes isolated test state.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rewrite;

		foreach ( array_unique( $this->menu_ids ) as $menu_id ) {
			wp_delete_nav_menu( $menu_id );
		}

		foreach ( array_unique( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		foreach ( array_unique( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		update_option( 'permalink_structure', $this->permalink_structure );
		$wp_rewrite->set_permalink_structure( $this->permalink_structure );
		unregister_nav_menu( 'localepress_primary' );

		if ( null === $this->location_theme_mod ) {
			remove_theme_mod( MenuLanguageManager::LOCATION_THEME_MOD );
		} else {
			set_theme_mod( MenuLanguageManager::LOCATION_THEME_MOD, $this->location_theme_mod );
		}

		remove_filter( 'localepress_enable_frontend_routing', '__return_true', 20 );
		remove_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		remove_filter( 'localepress_auto_assign_default_term_language', '__return_false' );
		$_SERVER['REQUEST_URI'] = $this->request_uri;

		parent::tear_down();
	}

	/**
	 * Theme locations select a different native menu for each language.
	 *
	 * @return void
	 */
	public function test_theme_location_selects_language_menu() {
		$english_menu = $this->create_menu( 'English Menu' );
		$german_menu  = $this->create_menu( 'German Menu' );
		$result       = $this->menu_manager->update_configuration(
			array(
				$english_menu => $this->language_ids['en'],
				$german_menu  => $this->language_ids['de'],
			),
			array(
				'localepress_primary' => array(
					$this->language_ids['en'] => $english_menu,
					$this->language_ids['de'] => $german_menu,
				),
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( $this->language_ids['de'], $this->menu_manager->get_menu_language_id( $german_menu ) );
		$this->assertSame( $german_menu, $this->menu_manager->get_menu_for_location( 'localepress_primary', $this->language_ids['de'] ) );

		$args = $this->navigation->select_language_menu(
			array(
				'theme_location' => 'localepress_primary',
				'menu'           => '',
			)
		);
		$this->assertSame( $german_menu, $args['menu'] );

		$explicit = $this->navigation->select_language_menu(
			array(
				'theme_location' => 'localepress_primary',
				'menu'           => $english_menu,
			)
		);
		$this->assertSame( $english_menu, $explicit['menu'] );
	}

	/**
	 * One menu cannot be assigned to conflicting language slots.
	 *
	 * @return void
	 */
	public function test_menu_language_conflicts_are_rejected_before_storage() {
		$menu   = $this->create_menu( 'Shared Menu' );
		$result = $this->menu_manager->update_configuration(
			array( $menu => '' ),
			array(
				'localepress_primary' => array(
					$this->language_ids['en'] => $menu,
					$this->language_ids['de'] => $menu,
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'menu_language_conflict', $result->get_error_code() );
		$this->assertSame( '', $this->menu_manager->get_menu_language_id( $menu ) );
		$this->assertSame( array(), $this->menu_manager->get_location_assignments() );
	}

	/**
	 * Menu post, term, custom, missing, and switcher items remain compatible.
	 *
	 * @return void
	 */
	public function test_menu_items_resolve_translations_and_preserve_compatible_links() {
		$source_page = $this->create_post( 'Menu Source', 'menu-source' );
		$target_page = $this->create_post( 'Menu Target', 'menu-target' );
		$result      = $this->post_translations->link_translations(
			array(
				$this->language_ids['en'] => $source_page,
				$this->language_ids['de'] => $target_page,
			),
			$source_page
		);
		$this->assertNotWPError( $result );

		$source_term = $this->create_category( 'Menu Travel', 'menu-travel' );
		$target_term = $this->create_category( 'Menu Reisen', 'menu-reisen' );
		$result      = $this->term_translations->link_translations(
			array(
				$this->language_ids['en'] => $source_term,
				$this->language_ids['de'] => $target_term,
			),
			'category',
			$source_term
		);
		$this->assertNotWPError( $result );

		$missing_page = $this->create_post( 'Missing Page', 'menu-missing' );
		$this->post_translations->set_post_language( $missing_page, $this->language_ids['en'] );
		$menu_id       = $this->create_menu( 'Translated Links' );
		$page_item     = $this->create_object_menu_item( $menu_id, $source_page, 'page', 'post_type', 'Page Link' );
		$term_item     = $this->create_object_menu_item( $menu_id, $source_term, 'category', 'taxonomy', 'Term Link' );
		$missing_item  = $this->create_object_menu_item( $menu_id, $missing_page, 'page', 'post_type', 'Missing Link' );
		$child_item    = $this->create_custom_menu_item( $menu_id, 'Missing Child', home_url( '/child/' ), $missing_item );
		$external      = $this->create_custom_menu_item( $menu_id, 'External', 'https://external.example/' );
		$internal      = $this->create_custom_menu_item( $menu_id, 'Internal', home_url( '/en/help/' ) );
		$root_relative = $this->create_custom_menu_item( $menu_id, 'Root Relative', '/en/contact/' );
		$switcher      = $this->create_custom_menu_item( $menu_id, 'Switcher', '#localepress-language-switcher' );

		$this->set_singular_request( $target_page, '/de/menu-source/' );
		$items      = wp_get_nav_menu_items( $menu_id );
		$translated = $this->navigation->translate_menu_items( $items, (object) array() );
		$by_id      = array();

		foreach ( $translated as $item ) {
			$by_id[ $item->ID ] = $item;
		}

		$this->assertSame( home_url( '/de/menu-source/' ), $by_id[ $page_item ]->url );
		$this->assertSame( (string) $target_page, $by_id[ $page_item ]->object_id );
		$this->assertContains( 'current-menu-item', $by_id[ $page_item ]->classes );
		$this->assertSame( home_url( '/de/category/menu-reisen/' ), $by_id[ $term_item ]->url );

		// An entry whose page has not been translated yet stays in the menu and
		// keeps pointing at the page it names, so a reader who switches language
		// is shown the same menu rather than a shorter one. It is marked so a
		// theme can say as much, and its children come with it.
		$this->assertArrayHasKey( $missing_item, $by_id );
		$this->assertContains( 'localepress-menu-item-unavailable', $by_id[ $missing_item ]->classes );
		$this->assertSame( (string) $missing_page, $by_id[ $missing_item ]->object_id );
		$this->assertArrayHasKey( $child_item, $by_id );
		$this->assertSame( 'https://external.example/', $by_id[ $external ]->url );
		$this->assertSame( home_url( '/de/help/' ), $by_id[ $internal ]->url );
		$this->assertSame( home_url( '/de/contact/' ), $by_id[ $root_relative ]->url );
		$this->assertSame( '#localepress-language-switcher', $by_id[ $switcher ]->url );

		// A site that would rather drop those entries says so, and the entry and
		// everything under it goes.
		add_filter(
			'localepress_menu_missing_translation_behavior',
			static function () {
				return 'hide';
			}
		);

		$hidden = array();

		foreach ( $this->navigation->translate_menu_items( wp_get_nav_menu_items( $menu_id ), (object) array() ) as $item ) {
			$hidden[ $item->ID ] = $item;
		}

		remove_all_filters( 'localepress_menu_missing_translation_behavior' );

		$this->assertArrayHasKey( $page_item, $hidden );
		$this->assertArrayNotHasKey( $missing_item, $hidden );
		$this->assertArrayNotHasKey( $child_item, $hidden );
	}

	/** Relationship lookups remain bounded as translated menu items increase. */
	public function test_menu_translation_bulk_primes_relationship_queries() {
		global $wpdb;

		$menu_id = $this->create_menu( 'Query Bounded Menu' );

		for ( $index = 1; $index <= 8; $index++ ) {
			$source = $this->create_post( 'Query Source ' . $index, 'query-source-' . $index );
			$target = $this->create_post( 'Query Target ' . $index, 'query-target-' . $index );
			$result = $this->post_translations->link_translations(
				array(
					$this->language_ids['en'] => $source,
					$this->language_ids['de'] => $target,
				),
				$source
			);

			$this->assertNotWPError( $result );
			$this->create_object_menu_item( $menu_id, $source, 'page', 'post_type', 'Query Link ' . $index );
			clean_post_cache( $target );
		}

		$post_translations = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$this->languages,
			new PostTypeSupport(),
			new WorkflowSettings()
		);
		$term_translations = new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$this->languages,
			new TaxonomySupport()
		);
		$navigation        = new NavigationModule(
			$this->menu_manager,
			$post_translations,
			$term_translations,
			new LanguageUrlManager( $this->languages, $post_translations, $term_translations )
		);
		$items             = wp_get_nav_menu_items( $menu_id );
		$query_count       = $wpdb->num_queries;
		$translated        = $navigation->translate_menu_items( $items, (object) array() );
		$query_count       = $wpdb->num_queries - $query_count;

		$this->assertCount( 8, $translated );
		$this->assertLessThanOrEqual( 12, $query_count );
	}

	/**
	 * Deleted menus are removed from all language-specific locations.
	 *
	 * @return void
	 */
	public function test_deleted_menu_cleanup_and_language_usage() {
		$menu   = $this->create_menu( 'German Deletion Menu' );
		$result = $this->menu_manager->update_configuration(
			array( $menu => $this->language_ids['de'] ),
			array(
				'localepress_primary' => array( $this->language_ids['de'] => $menu ),
			)
		);
		$this->assertTrue( $result );
		$this->assertTrue( $this->menu_manager->is_language_in_use( $this->language_ids['de'] ) );

		$decision = $this->navigation->prevent_language_deletion( true, $this->language_ids['de'], array() );
		$this->assertWPError( $decision );
		$this->assertSame( 'menu_language_in_use', $decision->get_error_code() );

		$this->menu_manager->remove_menu( $menu );
		$this->assertSame( 0, $this->menu_manager->get_menu_for_location( 'localepress_primary', $this->language_ids['de'] ) );
	}

	/**
	 * Creates and tracks one published page.
	 *
	 * @param string $title Post title.
	 * @param string $slug  Post slug.
	 * @return int
	 */
	private function create_post( $title, $slug ) {
		$post_id          = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => $slug,
			)
		);
		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Creates and tracks one category.
	 *
	 * @param string $name Term name.
	 * @param string $slug Term slug.
	 * @return int
	 */
	private function create_category( $name, $slug ) {
		$result = wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
		$this->assertNotWPError( $result );
		$term_id          = absint( $result['term_id'] );
		$this->term_ids[] = $term_id;

		return $term_id;
	}

	/**
	 * Creates and tracks one navigation menu.
	 *
	 * @param string $name Menu name.
	 * @return int
	 */
	private function create_menu( $name ) {
		$menu_id          = wp_create_nav_menu( $name );
		$this->menu_ids[] = $menu_id;
		$this->assertNotWPError( $menu_id );

		return $menu_id;
	}

	/**
	 * Creates a post or taxonomy menu item.
	 *
	 * @param int    $menu_id   Menu identifier.
	 * @param int    $object_id Linked object identifier.
	 * @param string $object_name Post type or taxonomy.
	 * @param string $type      Menu item type.
	 * @param string $title     Menu item title.
	 * @return int
	 */
	private function create_object_menu_item( $menu_id, $object_id, $object_name, $type, $title ) {
		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-object-id' => $object_id,
				'menu-item-object'    => $object_name,
				'menu-item-type'      => $type,
				'menu-item-title'     => $title,
				'menu-item-status'    => 'publish',
			)
		);
		$this->assertNotWPError( $item_id );

		return $item_id;
	}

	/**
	 * Creates one custom navigation menu item.
	 *
	 * @param int    $menu_id Menu identifier.
	 * @param string $title   Item title.
	 * @param string $url     Item URL.
	 * @param int    $parent_id Optional parent menu item.
	 * @return int
	 */
	private function create_custom_menu_item( $menu_id, $title, $url, $parent_id = 0 ) {
		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => $title,
				'menu-item-url'       => $url,
				'menu-item-parent-id' => $parent_id,
				'menu-item-status'    => 'publish',
				'menu-item-type'      => 'custom',
			)
		);
		$this->assertNotWPError( $item_id );

		return $item_id;
	}

	/**
	 * Sets a translated singular request.
	 *
	 * @param int    $post_id Post identifier.
	 * @param string $path    Request path.
	 * @return void
	 */
	private function set_singular_request( $post_id, $path ) {
		global $post, $wp_query;

		$post                        = get_post( $post_id );
		$wp_query                    = new WP_Query();
		$wp_query->queried_object    = $post;
		$wp_query->queried_object_id = $post_id;
		$wp_query->is_singular       = true;
		$wp_query->is_page           = true;

		$this->set_request_language( 'de', $path );
	}

	/**
	 * Sets LocalePress request language state.
	 *
	 * @param string $slug Language URL slug.
	 * @param string $path Request path.
	 * @return void
	 */
	private function set_request_language( $slug, $path ) {
		global $wp;

		if ( ! $wp instanceof WP ) {
			$wp = new WP();
		}

		$wp->query_vars = array( LanguageUrlManager::QUERY_VAR => $slug );

		$_SERVER['REQUEST_URI'] = $path;
	}

	/**
	 * Clears LocalePress relationship tables.
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
