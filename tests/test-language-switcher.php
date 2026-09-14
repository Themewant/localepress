<?php
/**
 * Language switcher integration tests.
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
use LocalePress\Settings\PluginSettings;
use LocalePress\Switcher\FloatingSwitcher;
use LocalePress\Switcher\LanguageSwitcher;
use LocalePress\Switcher\NavigationMenuIntegration;
use LocalePress\Switcher\SwitcherModule;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies Phase 5 switcher behavior and integration boundaries.
 */
class Test_LocalePress_Language_Switcher extends WP_UnitTestCase {
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
	 * URL manager.
	 *
	 * @var LanguageUrlManager
	 */
	private $urls;

	/**
	 * Switcher under test.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

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
	 * Created category IDs.
	 *
	 * @var array<int, int>
	 */
	private $term_ids = array();

	/**
	 * Created navigation menu IDs.
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
	 * Prepares isolated language and relationship storage.
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
		update_option( 'permalink_structure', '/%postname%/' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		get_taxonomy( 'category' )->add_rewrite_rules();
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );

		add_filter( 'localepress_enable_frontend_routing', '__return_true', 20 );
		add_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		add_filter( 'localepress_auto_assign_default_term_language', '__return_false' );

		DatabaseTranslationRepository::install();
		DatabaseTermTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		OptionsLanguageRepository::install();

		$this->languages = new LanguageManager(
			new OptionsLanguageRepository(),
			new LanguageValidator()
		);

		foreach (
			array(
				array( 'English', 'English', 'en_US', 'en', true ),
				array( 'German', 'Deutsch', 'de_DE', 'de', true ),
				array( 'French', 'Francais', 'fr_FR', 'fr', true ),
				array( 'Arabic', 'Arabic', 'ar', 'ar', false ),
			) as $language_data
		) {
			$language = $this->languages->create(
				array(
					'name'          => $language_data[0],
					'native_name'   => $language_data[1],
					'locale'        => $language_data[2],
					'language_code' => $language_data[3],
					'url_slug'      => $language_data[3],
					'is_rtl'        => 'ar' === $language_data[3],
					'enabled'       => $language_data[4],
				)
			);

			$this->language_ids[ $language_data[3] ] = $language['id'];
		}

		$this->post_translations = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$this->languages,
			new PostTypeSupport()
		);
		$this->term_translations = new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$this->languages,
			new TaxonomySupport()
		);

		$this->rebuild_switcher();
		$this->set_request_language( 'en', '/en/' );
	}

	/**
	 * Removes test data and request filters.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rewrite;

		$_POST = array();

		foreach ( array_unique( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		foreach ( array_unique( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		foreach ( array_unique( $this->menu_ids ) as $menu_id ) {
			wp_delete_nav_menu( $menu_id );
		}

		if ( post_type_exists( 'localepress_book' ) ) {
			unregister_post_type( 'localepress_book' );
		}

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		update_option( 'permalink_structure', $this->permalink_structure );
		$wp_rewrite->set_permalink_structure( $this->permalink_structure );
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );

		remove_filter( 'localepress_enable_frontend_routing', '__return_true', 20 );
		remove_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		remove_filter( 'localepress_auto_assign_default_term_language', '__return_false' );

		$_SERVER['REQUEST_URI'] = $this->request_uri;
		parent::tear_down();
	}

	/**
	 * Posts and pages link to translated objects through their shared source route.
	 *
	 * @return void
	 */
	public function test_post_and_page_links_use_translation_relationships() {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			list( $source, $target ) = $this->create_translated_posts(
				$post_type,
				'lp-switcher-' . $post_type,
				'lp-switcher-' . $post_type . '-de'
			);

			$this->set_singular_request( $source, $post_type, '/en/lp-switcher-' . $post_type . '/' );
			$items  = $this->items_by_code();
			$de_url = home_url( '/de/lp-switcher-' . $post_type . '/' );

			$this->assertSame( $de_url, $items['de']['url'] );
			$this->assertTrue( $items['de']['available'] );
			$this->assertSame( $target, $this->post_translations->get_translation( $source, $this->language_ids['de'] ) );
		}
	}

	/**
	 * CPT singular and archive URLs retain their core rewrite route.
	 *
	 * @return void
	 */
	public function test_custom_post_type_singular_and_archive_urls() {
		$this->register_book_post_type();
		list( $source ) = $this->create_translated_posts( 'localepress_book', 'lp-book', 'lp-buch' );

		$this->set_singular_request( $source, 'localepress_book', '/en/lp-books/lp-book/' );
		$items = $this->items_by_code();
		$this->assertSame( home_url( '/de/lp-books/lp-book/' ), $items['de']['url'] );

		$this->go_to( home_url( '/?post_type=localepress_book' ) );
		$this->set_request_language( 'en', '/en/lp-books/' );
		$items = $this->items_by_code();
		$this->assertSame( home_url( '/de/lp-books/' ), $items['de']['url'] );
	}

	/**
	 * Taxonomy switches use the translated term's own WordPress slug.
	 *
	 * @return void
	 */
	public function test_taxonomy_switcher_uses_translated_term_url() {
		$source = $this->create_category( 'Travel', 'lp-travel' );
		$target = $this->create_category( 'Reisen', 'lp-reisen' );
		$result = $this->term_translations->link_translations(
			array(
				$this->language_ids['en'] => $source,
				$this->language_ids['de'] => $target,
			),
			'category',
			$source
		);
		$this->assertNotWPError( $result );

		$this->go_to( home_url( '/?cat=' . $source ) );
		$this->set_request_language( 'en', '/en/category/lp-travel/' );
		$items = $this->items_by_code();

		$this->assertSame( home_url( '/de/category/lp-reisen/' ), $items['de']['url'] );
	}

	/**
	 * Static home switching resolves the translated front page to language roots.
	 *
	 * @return void
	 */
	public function test_static_home_switcher_uses_language_home_urls() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-home', 'lp-startseite' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $source );
		$this->rebuild_switcher();
		$this->go_to( home_url( '/?page_id=' . $source ) );
		$this->set_request_language( 'en', '/en/' );
		$items = $this->items_by_code();

		$this->assertTrue( is_front_page() );
		$this->assertSame( home_url( '/de/' ), $items['de']['url'] );
	}

	/**
	 * Missing and disabled languages obey every visibility and fallback policy.
	 *
	 * @return void
	 */
	public function test_missing_and_disabled_language_behaviors() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-about', 'lp-about-de' );
		$this->set_singular_request( $source, 'page', '/en/lp-about/' );

		// By default a language without a translation leaves the switcher.
		$items = $this->items_by_code();
		$this->assertArrayNotHasKey( 'fr', $items );
		$this->assertArrayNotHasKey( 'ar', $items );
		ksort( $items );
		$this->assertSame( array( 'de', 'en' ), array_keys( $items ) );

		// The homepage fallback stays available to sites that ask for it.
		$to_home = $this->items_by_code( array( 'unavailable_behavior' => 'home' ) );
		$this->assertFalse( $to_home['fr']['available'] );
		$this->assertTrue( $to_home['fr']['fallback'] );
		$this->assertSame( home_url( '/fr/' ), $to_home['fr']['url'] );

		$hidden = $this->items_by_code(
			array(
				'hide_current' => true,
				'hide_missing' => true,
			)
		);
		$this->assertSame( array( 'de' ), array_keys( $hidden ) );

		$disabled_behavior = $this->items_by_code( array( 'unavailable_behavior' => 'disabled' ) );
		$this->assertFalse( $disabled_behavior['fr']['fallback'] );
		$this->assertSame( '', $disabled_behavior['fr']['url'] );

		$with_disabled = $this->items_by_code( array( 'show_disabled' => true ) );
		$this->assertTrue( $with_disabled['ar']['disabled'] );
		$this->assertSame( '', $with_disabled['ar']['url'] );
	}

	/**
	 * Markup is semantic, keyboard-native, and exposes unavailable state.
	 *
	 * @return void
	 */
	public function test_accessible_list_and_dropdown_markup() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-accessible', 'lp-accessible-de' );
		$this->set_singular_request( $source, 'page', '/en/lp-accessible/' );

		$html = $this->switcher->render(
			array(
				'display'       => 'language_code',
				'aria_label'    => 'Choose language',
				'show_disabled' => true,
			)
		);
		$this->assertStringContainsString( '<nav ', $html );
		$this->assertStringContainsString( 'aria-label="Choose language"', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertStringContainsString( 'hreflang="de-DE"', $html );

		// Only a language with nowhere to go is inert; a missing translation links home.
		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/fr/' ) ) . '"', $html );
		$this->assertStringContainsString( '(translation unavailable)', $html );

		// The missing-translation wording is announced, never printed beside a label.
		$this->assertStringNotContainsString( '>Unavailable<', $html );

		$dropdown = $this->switcher->render( array( 'layout' => 'dropdown' ) );
		$this->assertStringContainsString( '<details ', $dropdown );
		$this->assertStringContainsString( '<summary>', $dropdown );
	}

	/**
	 * The floating switcher prints itself, pinned to the edge it was given.
	 *
	 * @return void
	 */
	public function test_floating_switcher_renders_pinned_to_the_screen_edge() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-floater', 'lp-floater-de' );
		$this->set_singular_request( $source, 'page', '/en/lp-floater/' );

		$settings = new PluginSettings();

		ob_start();
		( new FloatingSwitcher( $this->switcher, $settings ) )->render();
		$html = (string) ob_get_clean();

		// Out of the box: a vertical strip on the middle right, every language
		// on screen at once rather than behind a control that has to be opened.
		$this->assertStringContainsString( 'localepress-switcher--floating', $html );
		$this->assertStringContainsString( 'localepress-switcher--at-middle-right', $html );
		$this->assertStringContainsString( 'localepress-switcher--vertical', $html );
		$this->assertStringNotContainsString( '<details ', $html );

		// The language being read is the one the stylesheet fills in.
		$this->assertStringContainsString( 'localepress-switcher__item is-current', $html );

		// Flags are the floater's own setting, on even though the site-wide one
		// is off, because the strip is read by its flags before its labels.
		$this->assertStringContainsString( 'localepress-switcher__flag', $html );
		$this->assertStringNotContainsString(
			'localepress-switcher__flag',
			$this->switcher->render( array( 'layout' => 'vertical' ) )
		);

		$settings->update_sections(
			array(
				'switcher' => array(
					'floater' => array(
						'enabled'    => true,
						'position'   => 'middle-left',
						'layout'     => 'dropdown',
						'show_flags' => true,
					),
				),
			)
		);

		ob_start();
		( new FloatingSwitcher( $this->switcher, new PluginSettings() ) )->render();
		$collapsed = (string) ob_get_clean();

		$this->assertStringContainsString( 'localepress-switcher--at-middle-left', $collapsed );
		$this->assertStringContainsString( '<details ', $collapsed );

		// Turning it off is the whole point of the checkbox: nothing is printed.
		$settings = new PluginSettings();
		$settings->update_sections(
			array(
				'switcher' => array(
					'floater' => array(
						'enabled'    => false,
						'position'   => 'middle-right',
						'layout'     => 'vertical',
						'show_flags' => true,
					),
				),
			)
		);

		ob_start();
		( new FloatingSwitcher( $this->switcher, new PluginSettings() ) )->render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * A page builder's canvas is the page being edited, not a page to float over.
	 *
	 * @return void
	 */
	public function test_floating_switcher_stands_down_in_a_builder_preview() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-builder', 'lp-builder-de' );
		$this->set_singular_request( $source, 'page', '/en/lp-builder/' );

		$floater = new FloatingSwitcher( $this->switcher, new PluginSettings(), $this->urls );

		ob_start();
		$floater->render();
		$this->assertNotSame( '', (string) ob_get_clean(), 'The floater renders on an ordinary request.' );

		$_GET['elementor-preview'] = (string) $source;

		ob_start();
		$floater->render();
		$rendered = (string) ob_get_clean();

		unset( $_GET['elementor-preview'] );

		$this->assertSame( '', $rendered );
	}

	/**
	 * Flag filters and shortcode/block adapters share the same renderer.
	 *
	 * @return void
	 */
	public function test_flags_shortcode_and_block_use_shared_output() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-adapters', 'lp-adapters-de' );
		$this->set_singular_request( $source, 'page', '/en/lp-adapters/' );
		$flag_filter = static function ( $url, $language ) {
			return 'https://example.org/flags/' . $language['language_code'] . '.svg';
		};
		add_filter( 'localepress_switcher_flag_url', $flag_filter, 10, 2 );

		$navigation = new NavigationMenuIntegration( $this->switcher );
		$module     = new SwitcherModule( $this->switcher, $navigation, $this->languages );
		$shortcode  = $module->render_shortcode(
			array(
				'display'    => 'language_code',
				'show_flags' => 'true',
			)
		);
		$block      = $module->render_block(
			array(
				'display'   => 'name',
				'layout'    => 'vertical',
				'showFlags' => true,
			)
		);

		remove_filter( 'localepress_switcher_flag_url', $flag_filter, 10 );
		$this->assertStringContainsString( 'flags/de.svg', $shortcode );
		$this->assertStringContainsString( '>DE<', $shortcode );
		$this->assertStringContainsString( 'localepress-switcher--vertical', $block );
		$this->assertStringContainsString( '>German<', $block );
	}

	/**
	 * Every block attribute reaches the renderer and changes the output.
	 *
	 * The inspector, block.json, and the render callback each name these
	 * separately, so an attribute can be added to one and missed in another and
	 * still look right in the editor. This walks all of them.
	 *
	 * @return void
	 */
	public function test_block_attributes_reach_the_renderer() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-block-attrs', 'lp-block-attrs-de' );
		$this->set_singular_request( $source, 'page', '/en/lp-block-attrs/' );

		$module = new SwitcherModule(
			$this->switcher,
			new NavigationMenuIntegration( $this->switcher ),
			$this->languages
		);

		// Defaults: the current language is marked, the translated one links.
		$default = $module->render_block( array() );
		$this->assertStringContainsString( 'localepress-switcher--horizontal', $default );
		$this->assertStringContainsString( 'aria-current="page"', $default );
		$this->assertStringContainsString( 'hreflang="de-DE"', $default );
		$this->assertStringNotContainsString( 'localepress-switcher__flag', $default );

		// display + layout.
		$labels = $module->render_block( array( 'display' => 'language_code', 'layout' => 'dropdown' ) );
		$this->assertStringContainsString( 'localepress-switcher--dropdown', $labels );
		$this->assertStringContainsString( '<details ', $labels );
		$this->assertStringContainsString( '>EN<', $labels );

		// hideCurrent.
		$this->assertStringNotContainsString(
			'aria-current="page"',
			$module->render_block( array( 'hideCurrent' => true ) )
		);

		// showFlags.
		$this->assertStringContainsString(
			'localepress-switcher__flag',
			$module->render_block( array( 'showFlags' => true ) )
		);

		// showDisabled surfaces the registered-but-disabled language.
		$disabled = $module->render_block( array( 'showDisabled' => true ) );
		$this->assertStringContainsString( 'aria-disabled="true"', $disabled );

		// ariaLabel and className both land on the wrapper.
		$labelled = $module->render_block(
			array(
				'ariaLabel' => 'Choose a language',
				'className' => 'my-switcher',
			)
		);
		$this->assertStringContainsString( 'aria-label="Choose a language"', $labelled );
		$this->assertStringContainsString( 'my-switcher', $labelled );

		/*
		 * French has nothing published anywhere, so "hide" drops it while
		 * "home" keeps it and sends it to its own front page. That difference
		 * is the whole of unavailableBehavior, and it is what hideMissing
		 * overrules.
		 */
		$home = $module->render_block( array( 'unavailableBehavior' => 'home' ) );
		$this->assertStringContainsString( esc_url( home_url( '/fr/' ) ), $home );

		$hidden = $module->render_block( array( 'unavailableBehavior' => 'hide' ) );
		$this->assertStringNotContainsString( 'hreflang="fr-FR"', $hidden );

		$overruled = $module->render_block(
			array(
				'unavailableBehavior' => 'home',
				'hideMissing'         => true,
			)
		);
		$this->assertStringNotContainsString( 'hreflang="fr-FR"', $overruled );
	}

	/**
	 * Menu settings require the core nonce and theme-edit capability.
	 *
	 * @return void
	 */
	public function test_navigation_menu_item_settings_and_rendering_are_secure() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-menu', 'lp-menu-de' );
		$this->set_singular_request( $source, 'page', '/en/lp-menu/' );
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		$menu_id          = wp_create_nav_menu( 'LocalePress Test Menu' );
		$this->menu_ids[] = $menu_id;
		$item_id          = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Language switcher',
				'menu-item-url'    => NavigationMenuIntegration::MENU_ITEM_URL,
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			)
		);
		$this->assertNotWPError( $item_id );

		$navigation = new NavigationMenuIntegration( $this->switcher );
		$_POST      = array(
			'update-nav-menu-nonce' => 'invalid',
			'localepress-switcher'  => array(
				$item_id => array( 'layout' => 'vertical' ),
			),
		);
		$navigation->save_item_fields(
			$menu_id,
			$item_id,
			array( 'menu-item-url' => NavigationMenuIntegration::MENU_ITEM_URL )
		);
		$this->assertSame( '', get_post_meta( $item_id, NavigationMenuIntegration::SETTINGS_META_KEY, true ) );

		$_POST['update-nav-menu-nonce'] = wp_create_nonce( 'update-nav_menu' );
		$navigation->save_item_fields(
			$menu_id,
			$item_id,
			array( 'menu-item-url' => NavigationMenuIntegration::MENU_ITEM_URL )
		);
		$settings = get_post_meta( $item_id, NavigationMenuIntegration::SETTINGS_META_KEY, true );
		$this->assertSame( 'vertical', $settings['layout'] );

		$menu_item = wp_setup_nav_menu_item( get_post( $item_id ) );
		$expanded  = $navigation->filter_menu_items( array( $menu_item ), (object) array() );

		// The virtual item becomes one real menu item per available language.
		// French has no translation of this page, so it is not offered.
		$this->assertCount( 2, $expanded );

		$by_code = array();

		foreach ( $expanded as $entry ) {
			$this->assertNotSame( NavigationMenuIntegration::MENU_ITEM_URL, $entry->url );
			$this->assertContains( 'menu-item-localepress-switcher', $entry->classes );
			$this->assertSame( 0, $entry->db_id );
			$by_code[ $entry->localepress_language['language_code'] ] = $entry;
		}

		ksort( $by_code );
		$this->assertSame( array( 'de', 'en' ), array_keys( $by_code ) );
		$this->assertSame( home_url( '/de/lp-menu-de/' ), $by_code['de']->url );
		$this->assertContains( 'current-lang', $by_code['en']->classes );

		// Language metadata reaches the anchor through the core attribute filter.
		$atts = $navigation->menu_link_attributes(
			array( 'href' => $by_code['de']->url ),
			$by_code['de']
		);
		$this->assertSame( 'de-DE', $atts['hreflang'] );
		$this->assertSame( 'de-DE', $atts['lang'] );
		$this->assertSame( 'alternate', $atts['rel'] );
		$this->assertSame( 'ltr', $atts['dir'] );

		// A regular menu item is left untouched.
		$this->assertSame( array(), $navigation->menu_link_attributes( array(), $menu_item ) );

		/*
		 * A dropdown adds a parent item. WordPress works menu-item-has-children
		 * out before wp_nav_menu_objects runs, so a parent built during that
		 * filter has to carry the class itself or themes draw no submenu arrow.
		 */
		update_post_meta(
			$item_id,
			NavigationMenuIntegration::SETTINGS_META_KEY,
			array( 'layout' => 'dropdown' ) + $settings
		);
		$dropdown = $navigation->filter_menu_items(
			array( wp_setup_nav_menu_item( get_post( $item_id ) ) ),
			(object) array()
		);

		$this->assertCount( 3, $dropdown );
		$parent = array_shift( $dropdown );
		$this->assertContains( 'menu-item-has-children', $parent->classes );
		$this->assertNotSame( 0, $parent->db_id );

		foreach ( $dropdown as $child ) {
			$this->assertSame( (int) $parent->db_id, $child->menu_item_parent );
			$this->assertNotSame( $parent->db_id, $child->db_id );
		}
	}

	/**
	 * Returns switcher items keyed by language code.
	 *
	 * @param array<string, mixed> $args Switcher arguments.
	 * @return array<string, array<string, mixed>>
	 */
	private function items_by_code( $args = array() ) {
		$items = array();

		foreach ( $this->switcher->get_items( $args ) as $item ) {
			$items[ $item['language']['language_code'] ] = $item;
		}

		return $items;
	}

	/**
	 * Rebuilds request-sensitive URL and switcher services.
	 *
	 * @return void
	 */
	private function rebuild_switcher() {
		$this->urls     = new LanguageUrlManager(
			$this->languages,
			$this->post_translations,
			$this->term_translations
		);
		$this->switcher = new LanguageSwitcher( $this->languages, $this->urls );
	}

	/**
	 * Creates and links an English/German post pair.
	 *
	 * @param string $post_type   Post type.
	 * @param string $source_slug Source slug.
	 * @param string $target_slug Target slug.
	 * @return array<int, int>
	 */
	private function create_translated_posts( $post_type, $source_slug, $target_slug ) {
		$source = $this->create_post( $post_type, $source_slug );
		$target = $this->create_post( $post_type, $target_slug );
		$result = $this->post_translations->link_translations(
			array(
				$this->language_ids['en'] => $source,
				$this->language_ids['de'] => $target,
			),
			$source
		);
		$this->assertNotWPError( $result );

		return array( $source, $target );
	}

	/**
	 * Creates and tracks a published post object.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug      Post slug.
	 * @return int
	 */
	private function create_post( $post_type, $slug ) {
		$post_id          = self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => ucwords( str_replace( '-', ' ', $slug ) ),
				'post_name'   => $slug,
			)
		);
		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Creates and tracks a category.
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
	 * Sets WordPress singular query state and LocalePress request language.
	 *
	 * @param int    $post_id   Post identifier.
	 * @param string $post_type Post type.
	 * @param string $path      Language-prefixed request path.
	 * @return void
	 */
	private function set_singular_request( $post_id, $post_type, $path ) {
		$query_args = 'page' === $post_type
			? array( 'page_id' => $post_id )
			: array(
				'p'         => $post_id,
				'post_type' => $post_type,
			);
		$this->go_to( add_query_arg( $query_args, home_url( '/' ) ) );
		$this->set_request_language( 'en', $path );
	}

	/**
	 * Sets parsed language query state and request URI.
	 *
	 * @param string $slug Language URL slug.
	 * @param string $path Request URI.
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
	 * Registers a generic public CPT with singular and archive rewrites.
	 *
	 * @return void
	 */
	private function register_book_post_type() {
		register_post_type(
			'localepress_book',
			array(
				'public'      => true,
				'show_ui'     => true,
				'has_archive' => 'lp-books',
				'rewrite'     => array( 'slug' => 'lp-books' ),
			)
		);
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
