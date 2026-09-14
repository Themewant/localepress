<?php
/**
 * Frontend language routing integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Content\CommentLanguageModule;
use LocalePress\Content\PostTranslationManager;
use LocalePress\Content\PostTypeSupport;
use LocalePress\Content\QueryIdTranslationModule;
use LocalePress\Infrastructure\DatabaseTermTranslationRepository;
use LocalePress\Infrastructure\DatabaseTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Routing\RoutingModule;
use LocalePress\Routing\SearchFormModule;
use LocalePress\SEO\SitemapModule;
use LocalePress\Settings\PluginSettings;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies Phase 4 routing behavior against WordPress posts and terms.
 */
class Test_LocalePress_Routing extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $urls;

	/**
	 * Router under test.
	 *
	 * @var RoutingModule
	 */
	private $router;

	/**
	 * Language IDs keyed by code.
	 *
	 * @var array<string, string>
	 */
	private $language_ids = array();

	/**
	 * Post IDs created during a test.
	 *
	 * @var array<int, int>
	 */
	private $post_ids = array();

	/**
	 * Term IDs created during a test.
	 *
	 * @var array<int, int>
	 */
	private $term_ids = array();

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
	 * Request host present before a test.
	 *
	 * @var string|null
	 */
	private $request_host;

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
		$this->request_host        = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: null;
		update_option( 'permalink_structure', '/%postname%/' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		get_taxonomy( 'category' )->add_rewrite_rules();
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );

		add_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		add_filter( 'localepress_auto_assign_default_term_language', '__return_false' );

		DatabaseTranslationRepository::install();
		DatabaseTermTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		OptionsLanguageRepository::install();
		PluginSettings::install();

		$languages = new LanguageManager(
			new OptionsLanguageRepository(),
			new LanguageValidator()
		);

		foreach (
			array(
				array( 'English', 'en_US', 'en' ),
				array( 'German', 'de_DE', 'de' ),
			) as $language_data
		) {
			$language = $languages->create(
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
			$languages,
			new PostTypeSupport()
		);
		$this->term_translations = new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$languages,
			new TaxonomySupport()
		);

		$this->rebuild_router( $languages );
		$this->set_request_language( 'en', '/' );
	}

	/**
	 * Removes test content and restores URL settings.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rewrite;

		foreach ( array_unique( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		foreach ( array_unique( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		if ( post_type_exists( 'localepress_book' ) ) {
			unregister_post_type( 'localepress_book' );
		}

		if ( post_type_exists( 'localepress_route' ) ) {
			unregister_post_type( 'localepress_route' );
		}

		if ( taxonomy_exists( 'localepress_shop_cat' ) ) {
			unregister_taxonomy( 'localepress_shop_cat' );
		}

		if ( post_type_exists( 'localepress_shop' ) ) {
			unregister_post_type( 'localepress_shop' );
		}

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		update_option( 'permalink_structure', $this->permalink_structure );
		$wp_rewrite->set_permalink_structure( $this->permalink_structure );
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );

		remove_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		remove_filter( 'localepress_auto_assign_default_term_language', '__return_false' );

		$_SERVER['REQUEST_URI'] = $this->request_uri;

		// A leaked host would silently put every later test on a language domain.
		if ( null === $this->request_host ) {
			unset( $_SERVER['HTTP_HOST'] );
		} else {
			$_SERVER['HTTP_HOST'] = $this->request_host;
		}
		parent::tear_down();
	}

	/**
	 * Prefixes same-site URLs while preserving query strings and fragments.
	 *
	 * @return void
	 */
	public function test_prefix_url_replaces_language_and_preserves_url_components() {
		$url = $this->urls->prefix_url( home_url( '/en/about/?view=full#team' ), 'de' );

		$this->assertSame( home_url( '/de/about/?view=full#team' ), $url );
		$this->assertSame( home_url( '/de/' ), $this->urls->get_language_home_url( 'de' ) );
	}

	/**
	 * Hiding the default prefix generates root URLs and canonicalizes old paths.
	 *
	 * @return void
	 */
	public function test_hidden_default_prefix_generates_and_redirects_to_unprefixed_urls() {
		$settings = new PluginSettings();
		$settings->update_sections( array( 'url' => array( 'prefix_default' => false ) ) );
		$this->rebuild_router();

		$this->assertSame( home_url( '/' ), $this->urls->get_language_home_url( 'en' ) );
		$this->assertSame( home_url( '/about/?view=full#team' ), $this->urls->prefix_url( home_url( '/de/about/?view=full#team' ), 'en' ) );
		$this->assertSame( home_url( '/de/about/' ), $this->urls->prefix_url( home_url( '/about/' ), 'de' ) );

		$this->go_to( home_url( '/' ) );
		$this->set_request_language( 'en', '/en/' );
		$this->assertSame( home_url( '/' ), $this->router->get_unprefixed_redirect_url() );

		$this->set_request_language( 'de', '/de/' );
		$this->assertSame( '', $this->router->get_unprefixed_redirect_url() );
	}

	/**
	 * Plain permalinks are not changed into unsupported path-prefix URLs.
	 *
	 * @return void
	 */
	public function test_plain_permalinks_are_left_unchanged() {
		update_option( 'permalink_structure', '' );
		$url = home_url( '/?p=123' );

		$this->assertSame( $url, $this->urls->prefix_url( $url, 'de' ) );
	}

	/**
	 * Request path detection supplies the current language.
	 *
	 * @return void
	 */
	public function test_current_language_is_detected_from_path_prefix() {
		$this->set_request_language( '', '/de/about/' );

		$this->assertSame( $this->language_ids['de'], $this->urls->get_current_language()['id'] );
		$this->assertSame( $this->language_ids['en'], $this->urls->get_default_language()['id'] );
	}

	/**
	 * Archive-style switching replaces the prefix and preserves query arguments.
	 *
	 * @return void
	 */
	public function test_switch_language_url_preserves_generic_request() {
		global $wp_query;

		$previous_query = $wp_query;
		$wp_query       = new WP_Query();
		$this->set_request_language( 'en', '/en/search/route/?page=2' );

		$this->assertSame(
			home_url( '/de/search/route/?page=2' ),
			$this->urls->switch_language_url( 'de' )
		);

		$wp_query = $previous_query;
	}

	/**
	 * Existing rewrite captures are shifted behind the language capture.
	 *
	 * @return void
	 */
	public function test_rewrite_rules_shift_matches_and_keep_core_rules() {
		$rules     = array(
			'category/(.+?)/page/?([0-9]{1,})/?$' => 'index.php?category_name=$matches[1]&paged=$matches[2]',
			'wp-json/?$'                          => 'index.php?rest_route=/',
		);
		$localized = $this->router->add_language_rewrite_rules( $rules );
		$rule      = '^(' . implode( '|', array( 'en', 'de' ) ) . ')/category/(.+?)/page/?([0-9]{1,})/?$';

		$this->assertArrayHasKey( $rule, $localized );
		$this->assertSame(
			'index.php?category_name=$matches[2]&paged=$matches[3]&localepress_lang=$matches[1]',
			$localized[ $rule ]
		);
		$this->assertArrayHasKey( 'category/(.+?)/page/?([0-9]{1,})/?$', $localized );
		$this->assertArrayNotHasKey( '^(en|de)/wp-json/?$', $localized );
	}

	/**
	 * Subdomain routing moves the language from the path into the host.
	 *
	 * @return void
	 */
	public function test_subdomain_mode_routes_by_host() {
		$this->use_url_mode( 'subdomain' );

		$site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$this->assertSame( 'en.' . $site, $this->urls->get_language_host( 'en' ) );
		$this->assertSame( 'de.' . $site, $this->urls->get_language_host( 'de' ) );

		// The path keeps core's own shape; only the host carries the language.
		$this->assertSame(
			'http://de.' . $site . '/sample-page/',
			$this->urls->prefix_url( 'http://' . $site . '/sample-page/', 'de' )
		);

		// A URL left over from directory routing loses its now-meaningless prefix.
		$this->assertSame(
			'http://de.' . $site . '/sample-page/',
			$this->urls->prefix_url( 'http://' . $site . '/en/sample-page/', 'de' )
		);

		$this->set_request_host( 'de.' . $site );
		$current = $this->urls->get_current_language();

		$this->assertNotNull( $current );
		$this->assertSame( $this->language_ids['de'], $current['id'] );
		$this->assertTrue( $this->urls->request_names_language() );

		// No path prefix means no localized rewrite rules at all.
		$rules = array( 'category/(.+?)/?$' => 'index.php?category_name=$matches[1]' );
		$this->assertSame( $rules, $this->router->add_language_rewrite_rules( $rules ) );

		// The site root is nobody's explicit choice, so detection may still run.
		$this->set_request_host( $site );
		$this->assertFalse( $this->urls->request_names_language() );
	}

	/**
	 * Domain routing serves each language from its own registered host.
	 *
	 * @return void
	 */
	public function test_domain_mode_routes_by_registered_domain() {
		$languages = new LanguageManager( new OptionsLanguageRepository(), new LanguageValidator() );
		$german    = $languages->find( $this->language_ids['de'] );

		// update() validates the whole record rather than merging a partial one.
		$german['domain'] = 'example.de';
		$this->assertNotWPError( $languages->update( $this->language_ids['de'], $german ) );

		$this->use_url_mode( 'domain' );

		$site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$this->assertSame( 'example.de', $this->urls->get_language_host( 'de' ) );

		// A language with no domain of its own stays reachable on the site host.
		$this->assertSame( $site, $this->urls->get_language_host( 'en' ) );

		$this->assertSame(
			'http://example.de/sample-page/',
			$this->urls->prefix_url( 'http://' . $site . '/sample-page/', 'de' )
		);

		$this->set_request_host( 'example.de' );
		$current = $this->urls->get_current_language();

		$this->assertNotNull( $current );
		$this->assertSame( $this->language_ids['de'], $current['id'] );

		// wp_safe_redirect() would otherwise discard every cross-host redirect.
		$this->assertContains( 'example.de', $this->router->allow_language_hosts( array() ) );
	}

	/**
	 * Query routing names the language beside an otherwise untouched URL.
	 *
	 * @return void
	 */
	public function test_query_mode_names_the_language_in_a_query_argument() {
		$this->use_url_mode( 'query' );

		// The path stays exactly as WordPress built it.
		$this->assertSame(
			home_url( '/sample-page/?lang=de' ),
			$this->urls->prefix_url( home_url( '/sample-page/' ), 'de' )
		);
		$this->assertSame( home_url( '/?lang=de' ), $this->urls->get_language_home_url( 'de' ) );

		// A URL left over from directory routing loses its now-meaningless prefix.
		$this->assertSame(
			home_url( '/sample-page/?lang=de' ),
			$this->urls->prefix_url( home_url( '/en/sample-page/' ), 'de' )
		);

		// Switching languages replaces the argument instead of stacking a second.
		$this->assertSame(
			home_url( '/sample-page/?lang=en' ),
			$this->urls->prefix_url( home_url( '/sample-page/?lang=de' ), 'en' )
		);

		// Existing arguments and fragments survive the addition.
		$this->assertSame(
			home_url( '/about/?view=full&lang=de#team' ),
			$this->urls->prefix_url( home_url( '/about/?view=full#team' ), 'de' )
		);

		// No path prefix means no localized rewrite rules at all.
		$rules = array( 'category/(.+?)/?$' => 'index.php?category_name=$matches[1]' );
		$this->assertSame( $rules, $this->router->add_language_rewrite_rules( $rules ) );
	}

	/**
	 * The language argument is read back from the request that carries it.
	 *
	 * @return void
	 */
	public function test_query_mode_detects_the_language_from_the_request_argument() {
		$this->use_url_mode( 'query' );

		$this->set_request_language( '', '/sample-page/?lang=de' );
		$this->assertSame( $this->language_ids['de'], $this->urls->get_current_language()['id'] );
		$this->assertTrue( $this->urls->request_names_language() );

		// The internal variable names the same language, which is what lets a
		// search form submit one.
		$this->set_request_language( '', '/?localepress_lang=de' );
		$this->assertSame( $this->language_ids['de'], $this->urls->get_current_language()['id'] );

		// A first path segment that happens to match a slug is an ordinary page.
		$this->set_request_language( '', '/de/sample-page/' );
		$this->assertSame( $this->language_ids['en'], $this->urls->get_current_language()['id'] );
		$this->assertFalse( $this->urls->request_names_language() );
	}

	/**
	 * Query routing is the one mode a plain-permalink site can use.
	 *
	 * @return void
	 */
	public function test_query_mode_works_without_pretty_permalinks() {
		global $wp_rewrite;

		update_option( 'permalink_structure', '' );
		$wp_rewrite->set_permalink_structure( '' );
		$this->use_url_mode( 'query' );

		$this->assertTrue( $this->urls->supports_language_prefixes() );
		$this->assertSame(
			home_url( '/?p=123&lang=de' ),
			$this->urls->prefix_url( home_url( '/?p=123' ), 'de' )
		);

		// The canonical redirect still has an unprefixed form to correct, which
		// it does not on a plain-permalink site in any path-based mode.
		$this->go_to( home_url( '/' ) );
		$this->set_request_language( '', '/' );
		$this->assertSame( home_url( '/?lang=en' ), $this->router->get_unprefixed_redirect_url() );

		$this->set_request_language( '', '/?lang=en' );
		$this->assertSame( '', $this->router->get_unprefixed_redirect_url() );
	}

	/**
	 * A hidden default language leaves the site's own addresses untouched.
	 *
	 * @return void
	 */
	public function test_query_mode_hidden_default_language_adds_no_argument() {
		$settings = new PluginSettings();
		$settings->update_sections( array( 'url' => array( 'prefix_default' => false ) ) );
		$this->use_url_mode( 'query' );

		$this->assertSame( home_url( '/' ), $this->urls->get_language_home_url( 'en' ) );
		$this->assertSame(
			home_url( '/sample-page/' ),
			$this->urls->prefix_url( home_url( '/sample-page/?lang=de' ), 'en' )
		);
		$this->assertSame(
			home_url( '/sample-page/?lang=de' ),
			$this->urls->prefix_url( home_url( '/sample-page/' ), 'de' )
		);
	}

	/**
	 * A search form carries the language in a field, not in its action.
	 *
	 * @return void
	 */
	public function test_query_mode_search_form_submits_the_language_as_a_field() {
		$this->use_url_mode( 'query' );

		$search = new SearchFormModule( $this->urls );
		$search->register();

		$this->set_request_language( '', '/?lang=de' );
		$form = get_search_form( array( 'echo' => false ) );

		/*
		 * A GET form discards the query string on its action, so an action of
		 * "/?lang=de" would submit a search with no language at all.
		 */
		$this->assertStringContainsString( 'name="lang" value="de"', $form );
		$this->assertStringNotContainsString( 'action="' . esc_url( home_url( '/?lang=de' ) ) . '"', $form );
	}

	/**
	 * A stored post identifier is answered with the current language's post.
	 *
	 * @return void
	 */
	public function test_stored_post_ids_are_translated_into_the_request_language() {
		list( $source, $target ) = $this->create_translated_pages( 'lp-featured', 'lp-hervorgehoben' );
		$untranslated            = $this->create_page( 'lp-alone' );
		$module                  = $this->build_id_translator();

		$this->set_request_language( 'de', '/de/' );

		// The single-identifier vars a theme stores when an editor picks a page.
		foreach ( array( 'p', 'page_id', 'post_parent' ) as $key ) {
			$query = $this->build_secondary_query( array( $key => $source ) );
			$module->translate_query_ids( $query );

			$this->assertSame( $target, $query->get( $key ), $key . ' was not translated' );
		}

		// Lists keep their shape and their untranslated members.
		$query = $this->build_secondary_query(
			array(
				'post__in'     => array( $source, $untranslated ),
				'post__not_in' => array( $source ),
			)
		);
		$module->translate_query_ids( $query );

		$this->assertSame( array( $target, $untranslated ), $query->get( 'post__in' ) );
		$this->assertSame( array( $target ), $query->get( 'post__not_in' ) );

		// Asking from the language a post is already in changes nothing.
		$this->set_request_language( 'en', '/en/' );
		$query = $this->build_secondary_query( array( 'page_id' => $source ) );
		$module->translate_query_ids( $query );

		$this->assertSame( $source, $query->get( 'page_id' ) );
	}

	/**
	 * A stored term identifier is answered with the current language's term.
	 *
	 * @return void
	 */
	public function test_stored_term_ids_are_translated_into_the_request_language() {
		$source = $this->create_category( 'Travel', 'lp-travel-ids' );
		$target = $this->create_category( 'Reisen', 'lp-reisen-ids' );

		$this->assertNotWPError(
			$this->term_translations->link_translations(
				array(
					$this->language_ids['en'] => $source,
					$this->language_ids['de'] => $target,
				),
				'category',
				$source
			)
		);

		$module = $this->build_id_translator();
		$this->set_request_language( 'de', '/de/' );

		$query = $this->build_secondary_query(
			array(
				'cat'              => $source . ',-' . $source,
				'category__in'     => array( $source ),
				'category__not_in' => array( $source ),
				'tax_query'        => array(
					array(
						'taxonomy' => 'category',
						'terms'    => array( $source ),
					),
					array(
						'taxonomy' => 'category',
						'field'    => 'slug',
						'terms'    => array( 'lp-travel-ids' ),
					),
				),
			)
		);
		$module->translate_query_ids( $query );

		// A leading minus keeps excluding, and now excludes the right term.
		$this->assertSame( $target . ',-' . $target, $query->get( 'cat' ) );
		$this->assertSame( array( $target ), $query->get( 'category__in' ) );
		$this->assertSame( array( $target ), $query->get( 'category__not_in' ) );

		$tax_query = $query->get( 'tax_query' );

		$this->assertSame( array( $target ), $tax_query[0]['terms'] );

		// A clause matching on a slug names one record and is left alone.
		$this->assertSame( array( 'lp-travel-ids' ), $tax_query[1]['terms'] );

		// Term listings pass the identifiers a site owner chose.
		$args = $module->translate_term_query_ids( array( 'include' => array( $source ) ) );

		$this->assertSame( array( $target ), $args['include'] );
	}

	/**
	 * Identifiers outside the translation engine keep their stored value.
	 *
	 * @return void
	 */
	public function test_untranslatable_and_unassigned_ids_are_left_alone() {
		register_post_type(
			'lp_widget_area',
			array(
				'public'   => false,
				'show_ui'  => true,
				'supports' => array( 'title' ),
			)
		);

		$unassigned  = $this->create_page( 'lp-no-language' );
		$unsupported = $this->create_post( 'lp_widget_area', 'lp-widget-area' );
		$module      = $this->build_id_translator();

		$this->set_request_language( 'de', '/de/' );

		$query = $this->build_secondary_query(
			array(
				'post__in' => array( $unassigned, $unsupported ),
			)
		);
		$module->translate_query_ids( $query );

		// Neither has a translation to move to, so the query keeps the results it
		// had before the module existed.
		$this->assertSame( array( $unassigned, $unsupported ), $query->get( 'post__in' ) );

		unregister_post_type( 'lp_widget_area' );
	}

	/**
	 * A query naming a language has its identifiers rewritten to that language.
	 *
	 * @return void
	 */
	public function test_query_language_argument_selects_the_translation_language() {
		list( $source, $target ) = $this->create_translated_pages( 'lp-picked', 'lp-gewaehlt' );
		$module                  = $this->build_id_translator();

		// The request is English; the query asks for German anyway.
		$this->set_request_language( 'en', '/en/' );

		$query = $this->build_secondary_query(
			array(
				'page_id'                     => $source,
				LanguageUrlManager::QUERY_VAR => 'de',
			)
		);
		$module->translate_query_ids( $query );

		$this->assertSame( $target, $query->get( 'page_id' ) );

		// Opting a query out leaves every identifier exactly as it was stored.
		$this->set_request_language( 'de', '/de/' );

		$query = $this->build_secondary_query(
			array(
				'page_id'                                => $source,
				QueryIdTranslationModule::SKIP_QUERY_VAR => true,
			)
		);
		$module->translate_query_ids( $query );

		$this->assertSame( $source, $query->get( 'page_id' ) );
	}

	/**
	 * A builder's own query opts into language filtering by argument.
	 *
	 * @return void
	 */
	public function test_query_language_argument_filters_a_secondary_query() {
		register_post_type(
			'lp_header',
			array(
				'public'      => false,
				'show_ui'     => true,
				'has_archive' => false,
			)
		);

		$eligible = static function ( $types ) {
			$types[] = 'lp_header';
			return $types;
		};
		add_filter( 'localepress_non_public_post_types', $eligible );
		add_filter( 'localepress_supported_post_types', $eligible );

		$english = $this->create_post( 'lp_header', 'lp-header-en' );
		$german  = $this->create_post( 'lp_header', 'lp-header-de' );
		$this->assertNotWPError(
			$this->post_translations->link_translations(
				array(
					$this->language_ids['en'] => $english,
					$this->language_ids['de'] => $german,
				),
				$english
			)
		);

		$this->rebuild_router();
		$this->set_request_language( 'en', '/en/' );

		// A builder's template query is neither the main query nor a block query.
		$unfiltered = new WP_Query(
			array(
				'post_type'      => 'lp_header',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$this->assertContains( $english, $unfiltered->posts );
		$this->assertContains( $german, $unfiltered->posts );

		// 'current' resolves to the request language.
		$current = new WP_Query(
			array(
				'post_type'        => 'lp_header',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'localepress_lang' => 'current',
			)
		);
		$this->assertContains( $english, $current->posts );
		$this->assertNotContains( $german, $current->posts );

		// An explicit language pins the result regardless of the request.
		$pinned = new WP_Query(
			array(
				'post_type'        => 'lp_header',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'localepress_lang' => 'de',
			)
		);
		$this->assertContains( $german, $pinned->posts );
		$this->assertNotContains( $english, $pinned->posts );

		remove_filter( 'localepress_non_public_post_types', $eligible );
		remove_filter( 'localepress_supported_post_types', $eligible );
		unregister_post_type( 'lp_header' );
	}

	/**
	 * A post type left untranslatable is returned unfiltered, not emptied.
	 *
	 * @return void
	 */
	public function test_query_language_argument_ignores_untranslatable_post_types() {
		register_post_type( 'lp_widget', array( 'public' => false, 'show_ui' => true ) );

		$post = $this->create_post( 'lp_widget', 'lp-widget-one' );
		$this->rebuild_router();
		$this->set_request_language( 'en', '/en/' );

		$query = new WP_Query(
			array(
				'post_type'        => 'lp_widget',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'localepress_lang' => 'current',
			)
		);

		$this->assertContains( $post, $query->posts );

		unregister_post_type( 'lp_widget' );
	}

	/**
	 * Open comment listings are limited to the current language.
	 *
	 * @return void
	 */
	public function test_comment_listings_follow_the_request_language() {
		list( $source, $target ) = $this->create_translated_pages( 'lp-talk', 'lp-reden' );

		$english = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $source,
				'comment_content'  => 'English comment',
				'comment_approved' => '1',
			)
		);
		$german  = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $target,
				'comment_content'  => 'German comment',
				'comment_approved' => '1',
			)
		);

		$comments = new CommentLanguageModule( $this->urls, $this->post_translations );
		$comments->register();

		$this->set_request_language( 'de', '/de/' );
		$found = get_comments( array( 'status' => 'approve', 'fields' => 'ids' ) );

		$this->assertContains( $german, $found );
		$this->assertNotContains( $english, $found );

		$this->set_request_language( 'en', '/en/' );
		$found = get_comments( array( 'status' => 'approve', 'fields' => 'ids' ) );

		$this->assertContains( $english, $found );
		$this->assertNotContains( $german, $found );

		// A thread already scoped to one post must come back whole.
		$thread = get_comments(
			array(
				'status'  => 'approve',
				'post_id' => $target,
				'fields'  => 'ids',
			)
		);

		$this->assertContains( $german, $thread );
	}

	/**
	 * The search form submits inside the language it was rendered in.
	 *
	 * @return void
	 */
	public function test_search_form_submits_to_the_current_language() {
		$search = new SearchFormModule( $this->urls );
		$search->register();

		$this->set_request_language( 'de', '/de/' );
		$form = get_search_form( array( 'echo' => false ) );

		$this->assertStringContainsString( 'action="' . esc_url( home_url( '/de/' ) ) . '"', $form );
		$this->assertStringNotContainsString( 'action="' . esc_url( home_url( '/' ) ) . '"', $form );

		$this->set_request_language( 'en', '/en/' );
		$form = get_search_form( array( 'echo' => false ) );

		$this->assertStringContainsString( 'action="' . esc_url( home_url( '/en/' ) ) . '"', $form );
	}

	/**
	 * A site served from www keeps www in the host it hands out.
	 *
	 * The comparison form of a host drops www, because www names no language.
	 * The addressable form must not: a site configured on www.example.com may
	 * hold no certificate for the bare domain, so building links without it
	 * moves every internal link onto an address that need not answer at all.
	 *
	 * @return void
	 */
	public function test_www_site_keeps_www_in_host_routing() {
		update_option( 'home', 'https://www.example.com' );
		update_option( 'siteurl', 'https://www.example.com' );
		$this->use_url_mode( 'subdomain' );

		$hosts = $this->urls->hosts();

		// The default language is the site itself, www and all.
		$this->assertSame( 'www.example.com', $this->urls->get_language_host( 'en' ) );

		// Language subdomains sit beside www rather than beneath it.
		$this->assertSame( 'de.example.com', $this->urls->get_language_host( 'de' ) );
		$this->assertSame( 'example.com', $hosts->get_base_host() );

		// Both spellings still resolve to the language they name.
		$this->assertSame( 'example.com', $hosts->normalize( 'www.example.com' ) );

		$this->set_request_host( 'www.example.com' );
		$current = $this->urls->get_current_language();
		$this->assertNotNull( $current );
		$this->assertSame( $this->language_ids['en'], $current['id'] );

		// A link built for the default language stays on www.
		$this->assertSame(
			'https://www.example.com/sample-page/',
			$this->urls->prefix_url( 'https://www.example.com/sample-page/', 'en' )
		);
		$this->assertSame(
			'https://de.example.com/sample-page/',
			$this->urls->prefix_url( 'https://www.example.com/sample-page/', 'de' )
		);
	}

	/**
	 * Domain routing keeps a configured domain exactly as it was entered.
	 *
	 * @return void
	 */
	public function test_domain_mode_keeps_the_configured_spelling() {
		$languages = new LanguageManager( new OptionsLanguageRepository(), new LanguageValidator() );
		$german    = $languages->find( $this->language_ids['de'] );

		$german['domain'] = 'www.example.de';
		$this->assertNotWPError( $languages->update( $this->language_ids['de'], $german ) );

		$this->use_url_mode( 'domain' );

		$this->assertSame( 'www.example.de', $this->urls->get_language_host( 'de' ) );

		// Either spelling of the request host still finds the language.
		$this->set_request_host( 'example.de' );
		$current = $this->urls->get_current_language();
		$this->assertNotNull( $current );
		$this->assertSame( $this->language_ids['de'], $current['id'] );

		// wp_safe_redirect() has to accept both spellings, and neither as www.www.
		$allowed = $this->router->allow_language_hosts( array() );
		$this->assertContains( 'example.de', $allowed );
		$this->assertContains( 'www.example.de', $allowed );
		$this->assertNotContains( 'www.www.example.de', $allowed );
	}

	/**
	 * A host serving no language is sent to the one that answers for it.
	 *
	 * Prefixing the default language gives it a host of its own, which leaves
	 * the site address serving a copy of it that nothing links to. Left alone,
	 * every page on the site would have two addresses.
	 *
	 * @return void
	 */
	public function test_unrouted_host_is_redirected_to_the_default_language() {
		$settings = new PluginSettings();
		$settings->update_sections( array( 'url' => array( 'prefix_default' => true ) ) );
		$this->use_url_mode( 'subdomain' );

		$site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$this->go_to( home_url( '/' ) );
		$this->set_request_host( $site );

		// The site address now names no language at all.
		$this->assertTrue( $this->urls->request_host_serves_no_language() );
		$this->assertSame(
			'http://en.' . $site . '/',
			$this->router->get_unrouted_host_redirect_url()
		);

		// On a language host there is nothing to correct.
		$this->set_request_host( 'en.' . $site );
		$this->assertFalse( $this->urls->request_host_serves_no_language() );
		$this->assertSame( '', $this->router->get_unrouted_host_redirect_url() );
	}

	/**
	 * An unprefixed default language leaves the site address serving it.
	 *
	 * @return void
	 */
	public function test_unprefixed_default_keeps_the_site_address() {
		$this->use_url_mode( 'subdomain' );

		$site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$this->go_to( home_url( '/' ) );
		$this->set_request_host( $site );

		$this->assertFalse( $this->urls->request_host_serves_no_language() );
		$this->assertSame( '', $this->router->get_unrouted_host_redirect_url() );
	}

	/**
	 * A finished URL is read back for the language it was built for.
	 *
	 * This is what the redirect guard checks before sending anyone anywhere: a
	 * target the router would read as some other language is a target the next
	 * request would try to correct again, and a reader caught between two such
	 * corrections sees a redirect loop rather than a page.
	 *
	 * @return void
	 */
	public function test_a_built_url_names_the_language_it_was_built_for() {
		$home = home_url( '/' );

		$this->assertSame(
			$this->language_ids['de'],
			$this->urls->get_url_language_id( $this->urls->prefix_url( $home . 'sample-page/', 'de' ) )
		);

		// The hidden default writes nothing a URL can be read back from.
		$this->assertSame( '', $this->urls->get_url_language_id( $home . 'sample-page/' ) );

		$this->use_url_mode( 'query' );
		$this->assertSame(
			$this->language_ids['de'],
			$this->urls->get_url_language_id( $home . 'sample-page/?lang=de' )
		);
		$this->assertSame( '', $this->urls->get_url_language_id( $home . 'sample-page/' ) );

		$this->use_url_mode( 'subdomain' );
		$site = wp_parse_url( $home, PHP_URL_HOST );
		$this->assertSame(
			$this->language_ids['de'],
			$this->urls->get_url_language_id( 'http://de.' . $site . '/sample-page/' )
		);
	}

	/**
	 * The theme's home link leads back into the language being read.
	 *
	 * A logo, a site title, and a "back to home" link are all built from the
	 * site root, which no permalink filter reaches. Left alone they are the one
	 * navigation step present on every page that drops the reader's language.
	 *
	 * @return void
	 */
	public function test_a_theme_home_link_stays_in_the_current_language() {
		$this->router->register();
		$this->set_request_language( 'de', '/de/' );

		do_action( 'template_redirect' );

		// Called the way core's Site Title block calls it.
		$home = $this->call_as(
			'render_block_core_site_title',
			static function () {
				return home_url( '/' );
			}
		);

		$this->assertSame( home_url( '/' ) . 'de/', $home );

		// Everything else keeps the address WordPress built for it.
		$this->assertStringNotContainsString( '/de/', rest_url() );
		$this->assertStringNotContainsString( '/de/', home_url( '/sample-page/' ) );
	}

	/**
	 * Calls a closure through a named function so the caller can be recognized.
	 *
	 * @param string   $name     Function name the backtrace should show.
	 * @param callable $callback Work to run.
	 * @return mixed
	 */
	private function call_as( $name, $callback ) {
		if ( ! function_exists( $name ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Builds a named frame for the backtrace to find.
			eval( 'function ' . $name . '( $callback ) { return $callback(); }' );
		}

		return call_user_func( $name, $callback );
	}

	/**
	 * Switches the configured URL mode and rebuilds the router around it.
	 *
	 * @param string $mode URL mode.
	 * @return void
	 */
	private function use_url_mode( $mode ) {
		$settings = new PluginSettings();
		$settings->update_sections( array( 'url' => array( 'mode' => $mode ) ) );

		$this->assertSame( $mode, $settings->get_section( 'url' )['mode'] );
		$this->rebuild_router();
	}

	/**
	 * Points the current request at one host.
	 *
	 * @param string $host Request host.
	 * @return void
	 */
	private function set_request_host( $host ) {
		$this->set_request_language( '', '/' );
		$_SERVER['HTTP_HOST'] = $host;
		$this->rebuild_router();
	}

	/**
	 * Post translations share the source slug in language-prefixed routes.
	 *
	 * @return void
	 */
	public function test_post_translation_url_uses_source_route() {
		list( $source, $target ) = $this->create_translated_pages( 'lp-about', 'lp-ueber' );

		$this->assertSame(
			home_url( '/de/lp-about/' ),
			$this->urls->get_post_url( $target, 'de', home_url( '/lp-ueber/' ) )
		);
		$this->assertSame(
			home_url( '/de/lp-about/' ),
			$this->urls->get_translation_url( $source, 'de' )
		);
	}

	/**
	 * Shared post routes map to the requested translation or an impossible query.
	 *
	 * @return void
	 */
	public function test_post_routes_map_translation_and_missing_translation_to_404() {
		list( $source, $target ) = $this->create_translated_pages( 'lp-route', 'lp-route-de' );
		$wp                      = new WP();
		$wp->query_vars          = array(
			LanguageUrlManager::QUERY_VAR => 'de',
			'pagename'                    => 'lp-route',
		);

		$this->router->map_translated_request( $wp );
		$this->assertSame( $target, $wp->query_vars['page_id'] );
		$this->assertArrayNotHasKey( 'pagename', $wp->query_vars );

		$untranslated = $this->create_page( 'lp-untranslated' );
		$this->post_translations->set_post_language( $untranslated, $this->language_ids['en'] );
		$wp->query_vars = array(
			LanguageUrlManager::QUERY_VAR => 'de',
			'pagename'                    => 'lp-untranslated',
		);

		$this->router->map_translated_request( $wp );
		$this->assertSame( '__localepress_missing_translation__', $wp->query_vars['pagename'] );
		$this->assertSame( 'page', $wp->query_vars['post_type'] );
		$this->assertArrayNotHasKey( 'p', $wp->query_vars );
		$this->assertSame( $source, $this->post_translations->get_source_post_id( $target ) );
	}

	/**
	 * A public CPT's custom query variable maps through the generic engine.
	 *
	 * @return void
	 */
	public function test_custom_post_type_query_var_maps_to_translation() {
		register_post_type(
			'localepress_route',
			array(
				'public'      => true,
				'show_ui'     => true,
				'has_archive' => true,
			)
		);
		$source = $this->create_post( 'localepress_route', 'lp-route-source' );
		$target = $this->create_post( 'localepress_route', 'lp-route-target' );
		$result = $this->post_translations->link_translations(
			array(
				$this->language_ids['en'] => $source,
				$this->language_ids['de'] => $target,
			),
			$source
		);
		$this->assertNotWPError( $result );

		$wp             = new WP();
		$wp->query_vars = array(
			LanguageUrlManager::QUERY_VAR => 'de',
			'localepress_route'           => 'lp-route-source',
		);
		$this->router->map_translated_request( $wp );

		$this->assertSame( $target, $wp->query_vars['p'] );
		$this->assertSame( 'localepress_route', $wp->query_vars['post_type'] );
		$this->assertArrayNotHasKey( 'localepress_route', $wp->query_vars );
	}

	/**
	 * Taxonomy links use translated term slugs and requests map accordingly.
	 *
	 * @return void
	 */
	public function test_taxonomy_translation_urls_and_request_mapping() {
		$source = $this->create_category( 'Travel', 'lp-travel' );
		$target = $this->create_category( 'Reisen', 'lp-reisen' );

		$this->term_translations->link_translations(
			array(
				$this->language_ids['en'] => $source,
				$this->language_ids['de'] => $target,
			),
			'category',
			$source
		);

		$this->assertSame(
			home_url( '/de/category/lp-reisen/' ),
			$this->urls->get_translation_url( $source, 'de', 'category' )
		);

		$wp             = new WP();
		$wp->query_vars = array(
			LanguageUrlManager::QUERY_VAR => 'de',
			'category_name'               => 'lp-travel',
		);
		$this->router->map_translated_request( $wp );

		$this->assertSame( 'lp-reisen', $wp->query_vars['category_name'] );
	}

	/**
	 * The main query uses one indexed assignment join for language filtering.
	 *
	 * @return void
	 */
	public function test_main_query_is_constrained_to_current_language() {
		global $wp_the_query;

		$previous_main = $wp_the_query;
		$query         = new WP_Query();
		$wp_the_query  = $query;
		$this->set_request_language( 'de', '/de/' );
		$clauses      = $this->router->filter_posts_by_language(
			array(
				'join'  => '',
				'where' => ' WHERE 1=1',
			),
			$query
		);
		$wp_the_query = $previous_main;

		$this->assertStringContainsString( DatabaseTranslationRepository::assignments_table(), $clauses['join'] );
		$this->assertStringContainsString( 'localepress_route_language.language_id', $clauses['where'] );
		$this->assertStringContainsString( $this->language_ids['de'], $clauses['where'] );
		$this->assertStringNotContainsString( 'post_id IS NULL', $clauses['where'] );

		$wp_the_query = $query;
		$this->set_request_language( 'en', '/en/' );
		$default_clauses = $this->router->filter_posts_by_language(
			array(
				'join'  => '',
				'where' => ' WHERE 1=1',
			),
			$query
		);
		$wp_the_query    = $previous_main;

		$this->assertStringContainsString( 'post_id IS NULL', $default_clauses['where'] );
	}

	/**
	 * A main query reading no translatable post type keeps its clauses.
	 *
	 * Only the post types a site chose to translate ever carry a language, so
	 * constraining a commerce route on a site that never enabled its post type
	 * matches no assignment row and empties every language but the default. The
	 * single item, its archive, and its taxonomy archive all reach the same
	 * content, so all three have to be left alone.
	 *
	 * @return void
	 */
	public function test_main_query_skips_untranslatable_post_types() {
		global $wp_the_query;

		register_post_type(
			'localepress_shop',
			array(
				'public'      => true,
				'show_ui'     => true,
				'has_archive' => true,
				'query_var'   => 'localepress_shop',
			)
		);
		register_taxonomy(
			'localepress_shop_cat',
			'localepress_shop',
			array(
				'public'    => true,
				'show_ui'   => true,
				'query_var' => 'localepress_shop_cat',
			)
		);

		$this->set_request_language( 'de', '/de/' );

		$original      = array(
			'join'  => '',
			'where' => ' WHERE 1=1',
		);
		$previous_main = $wp_the_query;

		// A taxonomy archive names no post type of its own: WordPress resolves it
		// from the post types that registered the queried taxonomy.
		$taxonomy_archive = new WP_Query();
		$taxonomy_archive->parse_query( array( 'localepress_shop_cat' => 'winter' ) );

		$untranslatable = array(
			'single'   => $this->build_secondary_query(
				array(
					'post_type' => 'localepress_shop',
					'name'      => 'a-listed-item',
				)
			),
			'archive'  => $this->build_secondary_query( array( 'post_type' => 'localepress_shop' ) ),
			'taxonomy' => $taxonomy_archive,
		);

		foreach ( $untranslatable as $label => $query ) {
			$wp_the_query = $query;
			$clauses      = $this->router->filter_posts_by_language( $original, $query );
			$wp_the_query = $previous_main;

			$this->assertSame(
				$original,
				$clauses,
				"An untranslatable {$label} request must keep its clauses."
			);
		}

		// The gate must not switch language filtering off for content the site
		// does translate, so a plain posts request is still constrained.
		$translatable = $this->build_secondary_query( array() );
		$wp_the_query = $translatable;
		$clauses      = $this->router->filter_posts_by_language( $original, $translatable );
		$wp_the_query = $previous_main;

		$this->assertStringContainsString( 'localepress_route_language.language_id', $clauses['where'] );
		$this->assertStringContainsString( $this->language_ids['de'], $clauses['where'] );
	}

	/**
	 * A frontend term listing is constrained to the language being viewed.
	 *
	 * @return void
	 */
	public function test_frontend_term_listing_is_constrained_to_current_language() {
		$original = array(
			'join'  => '',
			'where' => ' WHERE 1=1',
		);

		$this->set_request_language( 'de', '/de/' );

		$clauses = $this->router->filter_terms_by_language( $original, array( 'category' ), array() );

		$this->assertStringContainsString( DatabaseTermTranslationRepository::assignments_table(), $clauses['join'] );
		$this->assertStringContainsString( $this->language_ids['de'], $clauses['where'] );

		// The terms one object holds are never narrowed.
		$this->assertSame(
			$original,
			$this->router->filter_terms_by_language(
				$original,
				array( 'category' ),
				array( 'object_ids' => array( 1 ) )
			)
		);

		// A sitemap names every language on purpose.
		$this->assertSame(
			$original,
			$this->router->filter_terms_by_language(
				$original,
				array( 'category' ),
				array( SitemapModule::QUERY_MARKER => true )
			)
		);
	}

	/**
	 * A Query Loop block query inherits the requested language.
	 *
	 * @return void
	 */
	public function test_query_loop_block_query_is_constrained_to_current_language() {
		$this->set_request_language( 'de', '/de/' );

		$query_vars = $this->router->mark_query_loop_block_vars(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 3,
			)
		);

		$this->assertTrue( $query_vars['localepress_block_query'] );

		$clauses = $this->router->filter_posts_by_language(
			array(
				'join'  => '',
				'where' => ' WHERE 1=1',
			),
			$this->build_secondary_query( $query_vars )
		);

		$this->assertStringContainsString( DatabaseTranslationRepository::assignments_table(), $clauses['join'] );
		$this->assertStringContainsString( $this->language_ids['de'], $clauses['where'] );
	}

	/**
	 * A listing anywhere on the page is scoped the same way the page is.
	 *
	 * A page builder's archive widget and a theme's related-posts loop are both
	 * answering the question the main query answers, and neither knows that
	 * LocalePress exists. Scoping them is what carries the reader's language into
	 * code that was never written for it.
	 *
	 * @return void
	 */
	public function test_secondary_listing_queries_inherit_the_request_language() {
		$this->set_request_language( 'de', '/de/' );

		$original = array(
			'join'  => '',
			'where' => ' WHERE 1=1',
		);
		$listing  = $this->router->filter_posts_by_language(
			$original,
			$this->build_secondary_query( array( 'post_type' => 'post' ) )
		);

		$this->assertStringContainsString( 'localepress_route_language.language_id', $listing['where'] );
		$this->assertStringContainsString( $this->language_ids['de'], $listing['where'] );

		// A query naming one object is answered by that object in every language,
		// the same way WordPress gives a singular request no taxonomy clause.
		$singular = new WP_Query();
		$singular->parse_query( array( 'p' => $this->create_page( 'lp-secondary-single' ) ) );

		$this->assertSame(
			$original,
			$this->router->filter_posts_by_language( $original, $singular ),
			'A secondary query naming one post must keep its clauses.'
		);

		// And a query that asks for no scoping keeps none.
		$this->assertSame(
			$original,
			$this->router->filter_posts_by_language(
				$original,
				$this->build_secondary_query(
					array(
						'post_type'                        => 'post',
						'localepress_skip_language_filter' => true,
					)
				)
			),
			'A query opting out must keep its clauses.'
		);
	}

	/**
	 * A listing that suppressed filters is still scoped to the language.
	 *
	 * `get_posts()` suppresses filters by default, and the language constraint is
	 * a filter, so those listings answered in every language at once.
	 *
	 * @return void
	 */
	public function test_suppressed_listing_queries_are_scoped_to_the_language() {
		$this->set_request_language( 'de', '/de/' );

		$original = array(
			'join'  => '',
			'where' => ' WHERE 1=1',
		);
		$listing  = $this->build_secondary_query(
			array(
				'post_type'        => 'post',
				'suppress_filters' => true,
			)
		);

		$this->router->scope_suppressed_query( $listing );

		$this->assertFalse( $listing->get( 'suppress_filters' ) );
		$this->assertStringContainsString(
			$this->language_ids['de'],
			$this->router->filter_posts_by_language( $original, $listing )['where']
		);

		// Everything the constraint would have left alone keeps its suppression,
		// because lifting it would only expose the query to every other plugin
		// for no gain of ours.
		$untouched = array(
			'an untranslated post type' => array( 'post_type' => 'wp_navigation' ),
			'every post type at once'   => array( 'post_type' => 'any' ),
			'a query opting out'        => array(
				'post_type'                        => 'post',
				'localepress_skip_language_filter' => true,
			),
		);

		foreach ( $untouched as $label => $query_vars ) {
			$query = $this->build_secondary_query( array_merge( array( 'suppress_filters' => true ), $query_vars ) );
			$this->router->scope_suppressed_query( $query );

			$this->assertTrue( $query->get( 'suppress_filters' ), "Suppression must be kept for {$label}." );
		}

		// And a site can refuse the whole thing.
		add_filter( 'localepress_scope_suppressed_queries', '__return_false' );

		$refused = $this->build_secondary_query(
			array(
				'post_type'        => 'post',
				'suppress_filters' => true,
			)
		);
		$this->router->scope_suppressed_query( $refused );

		remove_filter( 'localepress_scope_suppressed_queries', '__return_false' );

		$this->assertTrue( $refused->get( 'suppress_filters' ) );
	}

	/**
	 * The narrower block filter still governs the queries it was written for.
	 *
	 * @return void
	 */
	public function test_block_query_filter_governs_only_block_queries() {
		$this->set_request_language( 'de', '/de/' );

		$parsed_block = array(
			'blockName' => 'core/latest-posts',
			'attrs'     => array(),
		);
		$original     = array(
			'join'  => '',
			'where' => ' WHERE 1=1',
		);

		add_filter( 'localepress_filter_block_query_by_language', '__return_false' );

		$this->router->open_block_query_scope( $parsed_block );
		$inside = $this->router->filter_posts_by_language( $original, $this->build_secondary_query( array( 'post_type' => 'post' ) ) );
		$this->router->close_block_query_scope( '', $parsed_block );
		$outside = $this->router->filter_posts_by_language( $original, $this->build_secondary_query( array( 'post_type' => 'post' ) ) );

		remove_filter( 'localepress_filter_block_query_by_language', '__return_false' );

		$this->assertSame( $original, $inside, 'The block filter must still switch a block query off.' );
		$this->assertStringContainsString( $this->language_ids['de'], $outside['where'] );
	}

	/**
	 * Block queries for untranslatable post types are never constrained.
	 *
	 * @return void
	 */
	public function test_block_query_skips_untranslatable_post_types() {
		$this->set_request_language( 'de', '/de/' );

		$original = array(
			'join'  => '',
			'where' => ' WHERE 1=1',
		);

		foreach ( array( 'wp_navigation', 'any' ) as $post_type ) {
			$query_vars = $this->router->mark_query_loop_block_vars( array( 'post_type' => $post_type ) );

			$this->assertSame(
				$original,
				$this->router->filter_posts_by_language( $original, $this->build_secondary_query( $query_vars ) ),
				"Post type {$post_type} must not receive a language constraint."
			);
		}
	}

	/**
	 * Static front page routing selects a translation without changing settings.
	 *
	 * @return void
	 */
	public function test_static_front_page_uses_translated_page_and_home_route() {
		list( $source, $target ) = $this->create_translated_pages( 'lp-home', 'lp-startseite' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $source );
		$this->rebuild_router();

		$wp             = new WP();
		$wp->query_vars = array( LanguageUrlManager::QUERY_VAR => 'de' );
		$this->router->map_translated_request( $wp );

		$this->assertSame( $target, $wp->query_vars['page_id'] );
		$this->assertSame( $target, $this->router->filter_front_page_option( $source ) );
		$this->assertSame( home_url( '/de/' ), $this->urls->get_post_url( $target, 'de' ) );
		$this->assertSame( $source, $this->urls->get_front_page_id() );
	}

	/**
	 * A posts page resolves to its translation while keeping the source route.
	 *
	 * @return void
	 */
	public function test_posts_page_route_maps_to_translated_blog_page() {
		list( $source, $target ) = $this->create_translated_pages( 'lp-blog', 'lp-blog-de' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_for_posts', $source );
		update_option( 'page_on_front', $this->create_page( 'lp-front' ) );
		$this->rebuild_router();

		$wp             = new WP();
		$wp->query_vars = array(
			LanguageUrlManager::QUERY_VAR => 'de',
			'pagename'                    => 'lp-blog',
		);

		$this->router->map_translated_request( $wp );

		// WordPress compares the queried page with the option to decide is_home().
		$this->assertSame( $target, $wp->query_vars['page_id'] );
		$this->assertArrayNotHasKey( 'pagename', $wp->query_vars );
		$this->assertSame( $target, $this->router->filter_posts_page_option( $source ) );
		$this->assertSame( $source, $this->urls->get_posts_page_id() );

		// The route itself stays the source page's, like every other translation.
		$this->assertSame( home_url( '/de/lp-blog/' ), $this->urls->get_post_url( $target, 'de' ) );
	}

	/**
	 * An untranslated posts page keeps its route instead of becoming a 404.
	 *
	 * @return void
	 */
	public function test_untranslated_posts_page_keeps_source_route() {
		$posts_page = $this->create_page( 'lp-blog-en-only' );
		$this->post_translations->set_post_language( $posts_page, $this->language_ids['en'] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_for_posts', $posts_page );
		$this->rebuild_router();

		$wp             = new WP();
		$wp->query_vars = array(
			LanguageUrlManager::QUERY_VAR => 'de',
			'pagename'                    => 'lp-blog-en-only',
		);

		$this->router->map_translated_request( $wp );

		$this->assertSame( 'lp-blog-en-only', $wp->query_vars['pagename'] );
		$this->assertArrayNotHasKey( 'page_id', $wp->query_vars );
		$this->assertSame( $posts_page, $this->router->filter_posts_page_option( $posts_page ) );
	}

	/**
	 * Static page options return their stored value during WordPress writes.
	 *
	 * @return void
	 */
	public function test_static_page_options_are_not_translated_while_wordpress_writes_them() {
		list( $source, $target ) = $this->create_translated_pages( 'lp-guarded', 'lp-guarded-de' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $source );
		$this->rebuild_router();

		$wp             = new WP();
		$wp->query_vars = array( LanguageUrlManager::QUERY_VAR => 'de' );
		$this->router->map_translated_request( $wp );
		$this->assertSame( $target, $this->router->filter_front_page_option( $source ) );

		$observed = null;
		$capture  = function () use ( &$observed, $source ) {
			$observed = $this->router->filter_front_page_option( $source );
		};

		// _reset_front_page_settings_for_post() must see the stored ID, not a translation.
		add_action( 'wp_trash_post', $capture );
		do_action( 'wp_trash_post', $target );
		remove_action( 'wp_trash_post', $capture );

		$this->assertSame( $source, $observed );
	}

	/**
	 * An untranslated static front page still resolves its language root.
	 *
	 * The switcher sends every untranslated language to its root, so answering
	 * that route with a 404 would send visitors to a dead end.
	 *
	 * @return void
	 */
	public function test_untranslated_static_front_page_keeps_language_root_reachable() {
		$front_page = $this->create_page( 'lp-home-en-only' );
		$this->post_translations->set_post_language( $front_page, $this->language_ids['en'] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page );
		$this->rebuild_router();

		$wp             = new WP();
		$wp->query_vars = array( LanguageUrlManager::QUERY_VAR => 'de' );

		$this->router->map_translated_request( $wp );

		$this->assertSame( array( LanguageUrlManager::QUERY_VAR => 'de' ), $wp->query_vars );
		$this->assertArrayNotHasKey( 'pagename', $wp->query_vars );
		$this->assertArrayNotHasKey( 'page_id', $wp->query_vars );
	}

	/**
	 * An untranslated front page leaves every other route in its language alone.
	 *
	 * The front page mapping answers a request that names the front page. A
	 * request that names no page at all is every other route on the site, and
	 * pointing one at a missing translation turns the whole language into a 404.
	 *
	 * @return void
	 */
	public function test_untranslated_front_page_leaves_other_routes_alone() {
		global $wp_the_query;

		$front_page = $this->create_page( 'lp-home-untranslated' );
		$this->post_translations->set_post_language( $front_page, $this->language_ids['en'] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page );
		$this->rebuild_router();

		$wp             = new WP();
		$wp->query_vars = array( LanguageUrlManager::QUERY_VAR => 'de' );
		$this->router->map_translated_request( $wp );

		$previous_main = $wp_the_query;

		$routes = array(
			'a single post'     => array( 'name' => 'lp-a-german-post' ),
			'a term archive'    => array( 'category_name' => 'lp-a-german-category' ),
			'the language root' => array(),
		);

		foreach ( $routes as $label => $query_vars ) {
			$query        = $this->build_secondary_query( $query_vars );
			$wp_the_query = $query;
			$this->router->map_front_page( $query );
			$wp_the_query = $previous_main;

			$this->assertSame(
				'',
				$query->get( 'page_id' ),
				"An untranslated front page must leave {$label} reachable."
			);
		}
	}

	/**
	 * A translated front page is still substituted for its source page.
	 *
	 * @return void
	 */
	public function test_front_page_request_resolves_to_its_translation() {
		global $wp_the_query;

		list( $source, $target ) = $this->create_translated_pages( 'lp-home-mapped', 'lp-startseite-mapped' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $source );
		$this->rebuild_router();

		$wp             = new WP();
		$wp->query_vars = array( LanguageUrlManager::QUERY_VAR => 'de' );
		$this->router->map_translated_request( $wp );

		$previous_main = $wp_the_query;
		$query         = $this->build_secondary_query( array( 'page_id' => $source ) );
		$wp_the_query  = $query;
		$this->router->map_front_page( $query );
		$wp_the_query  = $previous_main;

		$this->assertSame( $target, $query->get( 'page_id' ) );
	}

	/**
	 * Search, pagination, previews, and CPT archive links retain URL components.
	 *
	 * @return void
	 */
	public function test_generic_frontend_and_preview_urls_are_prefixed() {
		register_post_type(
			'localepress_book',
			array(
				'public'      => true,
				'show_ui'     => true,
				'has_archive' => 'books',
			)
		);
		$this->set_request_language( 'de', '/de/search/route/page/2/' );
		$post_id = $this->create_page( 'lp-preview' );
		$this->post_translations->set_post_language( $post_id, $this->language_ids['de'] );

		$this->assertSame(
			home_url( '/de/books/' ),
			$this->urls->filter_current_url( home_url( '/books/' ) )
		);
		$this->assertSame(
			home_url( '/de/?p=' . $post_id . '&preview=true' ),
			$this->urls->filter_preview_url( home_url( '/?p=' . $post_id . '&preview=true' ), get_post( $post_id ) )
		);

		unregister_post_type( 'localepress_book' );
	}

	/**
	 * Rebuilds URL and routing services after front-page option changes.
	 *
	 * @param LanguageManager|null $languages Optional language manager.
	 * @return void
	 */
	private function rebuild_router( $languages = null ) {
		if ( null === $languages ) {
			$languages = new LanguageManager(
				new OptionsLanguageRepository(),
				new LanguageValidator()
			);
		}

		$this->urls   = new LanguageUrlManager(
			$languages,
			$this->post_translations,
			$this->term_translations
		);
		$this->router = new RoutingModule(
			$this->urls,
			$this->post_translations,
			$this->term_translations
		);
	}

	/**
	 * Creates and links an English/German page pair.
	 *
	 * @param string $source_slug Source page slug.
	 * @param string $target_slug Target page slug.
	 * @return array<int, int>
	 */
	/**
	 * A translation keeps its own route when the group's source has none.
	 *
	 * WordPress creates an `auto-draft` placeholder as soon as Add New is opened.
	 * Such a post has no slug, so its permalink is the unresolvable `?p=<id>`
	 * form. Borrowing it would hand every translation in the group the same dead
	 * address.
	 *
	 * @return void
	 */
	public function test_translation_route_survives_a_sourceless_group() {
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'auto-draft',
				'post_title'  => '',
				'post_name'   => '',
			)
		);

		$target           = $this->create_page( 'reachable-translation' );
		$this->post_ids[] = $source;

		$this->post_translations->set_post_language( $target, $this->language_ids['de'] );

		$url = $this->urls->get_post_url( $target );

		$this->assertStringNotContainsString( '?p=', $url );
		$this->assertStringContainsString( '/de/reachable-translation/', $url );
	}

	/**
	 * An auto-draft is never given a language or made a group source.
	 *
	 * @return void
	 */
	public function test_auto_draft_cannot_be_assigned_a_language() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'auto-draft',
				'post_name'   => '',
			)
		);

		$this->post_ids[] = $post_id;

		$result = $this->post_translations->set_post_language( $post_id, $this->language_ids['en'] );

		$this->assertWPError( $result );
		$this->assertSame( 'auto_draft_translation_post', $result->get_error_code() );
	}

	private function create_translated_pages( $source_slug, $target_slug ) {
		$source = $this->create_page( $source_slug );
		$target = $this->create_page( $target_slug );
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
	 * Creates and tracks a published page.
	 *
	 * @param string $slug Page slug.
	 * @return int
	 */
	private function create_page( $slug ) {
		return $this->create_post( 'page', $slug );
	}

	/**
	 * Builds the identifier translator around the current test services.
	 *
	 * @return QueryIdTranslationModule
	 */
	private function build_id_translator() {
		return new QueryIdTranslationModule(
			$this->urls,
			$this->post_translations,
			$this->term_translations
		);
	}

	/**
	 * Builds an unexecuted secondary query carrying the given query vars.
	 *
	 * @param array<string, mixed> $query_vars Query vars to apply.
	 * @return WP_Query
	 */
	private function build_secondary_query( array $query_vars ) {
		$query = new WP_Query();

		foreach ( $query_vars as $key => $value ) {
			$query->set( $key, $value );
		}

		return $query;
	}

	/**
	 * Creates and tracks a published post object.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug      Post slug.
	 * @return int
	 */
	private function create_post( $post_type, $slug ) {
		$post_id = self::factory()->post->create(
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
	 * Sets both parsed query state and the request path.
	 *
	 * @param string $slug Language slug, or empty to test path detection.
	 * @param string $path Request URI.
	 * @return void
	 */
	private function set_request_language( $slug, $path ) {
		global $wp;

		if ( ! $wp instanceof WP ) {
			$wp = new WP();
		}

		$wp->query_vars         = '' === $slug
			? array()
			: array( LanguageUrlManager::QUERY_VAR => $slug );
		$_SERVER['REQUEST_URI'] = $path;
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
