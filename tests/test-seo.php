<?php
/**
 * Multilingual SEO integration tests.
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
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\SEO\SeoMetadata;
use LocalePress\SEO\SeoModule;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies Phase 8 metadata and compatibility behavior.
 */
class Test_LocalePress_SEO extends WP_UnitTestCase {
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
	 * SEO service under test.
	 *
	 * @var SeoMetadata
	 */
	private $seo;

	/**
	 * Language IDs keyed by code.
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
				array( 'English', 'English', 'en_US', 'en', false ),
				array( 'German', 'Deutsch', 'de_DE', 'de', false ),
				array( 'Arabic', 'Arabic', 'ar', 'ar', true ),
			) as $language_data
		) {
			$language = $this->languages->create(
				array(
					'name'          => $language_data[0],
					'native_name'   => $language_data[1],
					'locale'        => $language_data[2],
					'language_code' => $language_data[3],
					'url_slug'      => $language_data[3],
					'is_rtl'        => $language_data[4],
					'enabled'       => true,
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
		$this->rebuild_seo();
		$this->set_request_language( 'en', '/en/' );
	}

	/**
	 * Removes test data and restores URL settings.
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

		if ( post_type_exists( 'localepress_seo_book' ) ) {
			unregister_post_type( 'localepress_seo_book' );
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
	 * WordPress locale forms become consistently cased language tags.
	 *
	 * @return void
	 */
	public function test_language_tag_normalization() {
		$formatter = new LanguageTag();

		$this->assertSame( 'en-US', $formatter->format( array( 'locale' => 'en_US' ) ) );
		$this->assertSame( 'zh-Hans-CN', $formatter->format( array( 'locale' => 'zh_Hans_CN' ) ) );
		$this->assertSame( 'de-DE-formal', $formatter->format( array( 'locale' => 'de_DE_formal' ) ) );
	}

	/**
	 * Document attributes and body classes follow the current RTL language.
	 *
	 * @return void
	 */
	public function test_document_language_and_rtl_attributes() {
		$this->set_request_language( 'ar', '/ar/' );

		$attributes = $this->seo->filter_language_attributes( 'lang="en-US" dir="ltr" data-mode="site"' );
		$classes    = $this->seo->filter_body_class( array( 'home', 'rtl' ) );

		$this->assertSame( 'lang="ar" dir="rtl" data-mode="site"', $attributes );
		$this->assertContains( 'rtl', $classes );

		$this->set_request_language( 'en', '/en/' );
		$this->assertNotContains( 'rtl', $this->seo->filter_body_class( $classes ) );
	}

	/**
	 * Singular alternates include public translations, self, and x-default.
	 *
	 * @return void
	 */
	public function test_singular_hreflang_omits_missing_language() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-seo-about', 'lp-seo-about-de' );
		$this->set_singular_request( $source, 'page', '/en/lp-seo-about/' );

		$alternates = $this->seo->get_alternate_urls();

		$this->assertSame( home_url( '/en/lp-seo-about/' ), $alternates['en-US'] );
		$this->assertSame( home_url( '/de/lp-seo-about/' ), $alternates['de-DE'] );
		$this->assertSame( $alternates['en-US'], $alternates['x-default'] );
		$this->assertArrayNotHasKey( 'ar', $alternates );
	}

	/**
	 * Draft translations never become alternate search targets.
	 *
	 * @return void
	 */
	public function test_draft_translation_is_not_an_alternate() {
		$source = $this->create_post( 'post', 'lp-seo-public' );
		$target = $this->create_post( 'post', 'lp-seo-draft', 'draft' );
		$result = $this->post_translations->link_translations(
			array(
				$this->language_ids['en'] => $source,
				$this->language_ids['de'] => $target,
			),
			$source
		);
		$this->assertNotWPError( $result );
		$this->set_singular_request( $source, 'post', '/en/lp-seo-public/' );

		$this->assertSame( array(), $this->seo->get_alternate_urls() );
	}

	/**
	 * Taxonomy alternates use each translated term's real slug.
	 *
	 * @return void
	 */
	public function test_taxonomy_hreflang_uses_translated_term() {
		$source = $this->create_category( 'Travel', 'lp-seo-travel' );
		$target = $this->create_category( 'Reisen', 'lp-seo-reisen' );
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
		$this->set_request_language( 'en', '/en/category/lp-seo-travel/' );
		$alternates = $this->seo->get_alternate_urls();

		$this->assertSame( home_url( '/de/category/lp-seo-reisen/' ), $alternates['de-DE'] );
		$this->assertArrayNotHasKey( 'ar', $alternates );
	}

	/**
	 * Shared CPT archives expose all enabled languages and preserve pagination.
	 *
	 * @return void
	 */
	public function test_cpt_archive_hreflang_preserves_pagination() {
		$this->register_book_post_type();

		for ( $index = 1; $index <= 11; ++$index ) {
			$this->create_post( 'localepress_seo_book', 'lp-seo-archive-' . $index );
		}

		$this->go_to( home_url( '/?post_type=localepress_seo_book&paged=2' ) );
		$this->set_request_language( 'en', '/en/lp-seo-books/page/2/' );
		$alternates = $this->seo->get_alternate_urls();

		$this->assertSame( home_url( '/en/lp-seo-books/page/2/' ), $alternates['en-US'] );
		$this->assertSame( home_url( '/de/lp-seo-books/page/2/' ), $alternates['de-DE'] );
		$this->assertSame( home_url( '/ar/lp-seo-books/page/2/' ), $alternates['ar'] );
	}

	/**
	 * Public CPT singulars use the same relationship-aware SEO path as posts.
	 *
	 * @return void
	 */
	public function test_cpt_singular_hreflang_uses_source_route() {
		$this->register_book_post_type();
		list( $source ) = $this->create_translated_posts(
			'localepress_seo_book',
			'lp-seo-book',
			'lp-seo-buch'
		);
		$this->set_singular_request( $source, 'localepress_seo_book', '/en/lp-seo-books/lp-seo-book/' );
		$alternates = $this->seo->get_alternate_urls();

		$this->assertSame( home_url( '/de/lp-seo-books/lp-seo-book/' ), $alternates['de-DE'] );
	}

	/**
	 * Blog-home alternates and canonical URLs use language roots.
	 *
	 * @return void
	 */
	public function test_homepage_alternates_and_canonical() {
		$this->go_to( home_url( '/' ) );
		$this->set_request_language( 'de', '/de/' );
		$alternates = $this->seo->get_alternate_urls();

		$this->assertSame( home_url( '/en/' ), $alternates['en-US'] );
		$this->assertSame( home_url( '/de/' ), $alternates['de-DE'] );
		$this->assertSame( home_url( '/de/' ), $this->seo->get_canonical_url() );
	}

	/**
	 * A translated static front page resolves every alternate to a language root.
	 *
	 * @return void
	 */
	public function test_static_front_page_alternates_use_language_roots() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-seo-home', 'lp-seo-start' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $source );
		$this->rebuild_seo();
		$this->go_to( home_url( '/?page_id=' . $source ) );
		$this->set_request_language( 'en', '/en/' );
		$alternates = $this->seo->get_alternate_urls();

		$this->assertTrue( is_front_page() );
		$this->assertSame( home_url( '/en/' ), $alternates['en-US'] );
		$this->assertSame( home_url( '/de/' ), $alternates['de-DE'] );
	}

	/**
	 * Core, Yoast, and Rank Math adapters prefix only same-site canonicals.
	 *
	 * @return void
	 */
	public function test_provider_canonical_filter_preserves_external_and_empty_values() {
		list( $source ) = $this->create_translated_posts( 'page', 'lp-seo-canonical', 'lp-seo-kanonisch' );
		$this->set_singular_request( $source, 'page', '/de/lp-seo-canonical/' );
		$this->set_request_language( 'de', '/de/lp-seo-canonical/' );

		$this->assertSame(
			home_url( '/de/lp-seo-canonical/' ),
			$this->seo->filter_provider_canonical( home_url( '/lp-seo-canonical/' ) )
		);
		$this->assertSame( 'https://canonical.example/page/', $this->seo->filter_provider_canonical( 'https://canonical.example/page/' ) );
		$this->assertSame( '', $this->seo->filter_provider_canonical( '' ) );
	}

	/**
	 * Provider ownership suppresses LocalePress's canonical without hreflang loss.
	 *
	 * @return void
	 */
	public function test_provider_detection_avoids_duplicate_canonical_output() {
		$this->go_to( home_url( '/' ) );
		$this->set_request_language( 'en', '/en/' );
		$module = new SeoModule( $this->seo );
		$filter = '__return_true';
		add_filter( 'localepress_has_canonical_provider', $filter );

		ob_start();
		$module->render_head_links();
		$output = (string) ob_get_clean();

		remove_filter( 'localepress_has_canonical_provider', $filter );
		$this->assertStringContainsString( 'rel="alternate"', $output );
		$this->assertStringNotContainsString( 'rel="canonical"', $output );
	}

	/**
	 * Filtered language tags remain valid and case-insensitively unique.
	 *
	 * @return void
	 */
	public function test_filtered_hreflang_entries_are_validated_and_deduplicated() {
		$this->go_to( home_url( '/' ) );
		$this->set_request_language( 'en', '/en/' );
		$filter = static function ( $alternates ) {
			$alternates['EN-us']        = home_url( '/duplicate/' );
			$alternates['invalid tag!'] = home_url( '/invalid/' );

			return $alternates;
		};
		add_filter( 'localepress_hreflang_urls', $filter );

		$alternates = $this->seo->get_alternate_urls();

		remove_filter( 'localepress_hreflang_urls', $filter );
		$this->assertArrayHasKey( 'en-US', $alternates );
		$this->assertArrayNotHasKey( 'EN-us', $alternates );
		$this->assertArrayNotHasKey( 'invalid tag!', $alternates );
	}

	/**
	 * Alternate relationships and object visibility are cached for the request.
	 *
	 * @return void
	 */
	public function test_alternate_urls_are_cached_for_the_request() {
		global $wpdb;

		list( $source ) = $this->create_translated_posts( 'page', 'lp-seo-cache', 'lp-seo-cache-de' );
		$this->set_singular_request( $source, 'page', '/en/lp-seo-cache/' );
		$this->seo->get_alternate_urls();
		$query_count = $wpdb->num_queries;

		$this->seo->get_alternate_urls();
		$this->assertSame( $query_count, $wpdb->num_queries );
	}

	/**
	 * Search and 404 contexts never receive LocalePress alternate/canonical links.
	 *
	 * @return void
	 */
	public function test_non_indexable_contexts_are_suppressed() {
		$this->go_to( home_url( '/?s=localepress-seo' ) );
		$this->set_request_language( 'en', '/en/?s=localepress-seo' );
		$this->assertSame( array(), $this->seo->get_alternate_urls() );
		$this->assertSame( '', $this->seo->get_canonical_url() );

		$this->rebuild_seo();
		$this->go_to( home_url( '/localepress-seo-missing/' ) );
		$this->set_request_language( 'en', '/en/localepress-seo-missing/' );
		$robots = $this->seo->filter_wordpress_robots( array() );

		$this->assertTrue( is_404() );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['follow'] );
		$this->assertSame( array(), $this->seo->get_alternate_urls() );
	}

	/**
	 * Rebuilds request-sensitive URL and SEO services.
	 *
	 * @return void
	 */
	private function rebuild_seo() {
		$this->urls = new LanguageUrlManager(
			$this->languages,
			$this->post_translations,
			$this->term_translations
		);
		$this->seo  = new SeoMetadata(
			$this->languages,
			$this->urls,
			$this->post_translations,
			$this->term_translations,
			new LanguageTag()
		);
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
	 * Creates and tracks one post.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug      Post slug.
	 * @param string $status    Post status.
	 * @return int
	 */
	private function create_post( $post_type, $slug, $status = 'publish' ) {
		$post_id          = self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => $status,
				'post_title'  => ucwords( str_replace( '-', ' ', $slug ) ),
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
	 * Sets WordPress singular state and the LocalePress request language.
	 *
	 * @param int    $post_id   Post identifier.
	 * @param string $post_type Post type.
	 * @param string $path      Request path.
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
	 * Registers a generic public CPT with an archive route.
	 *
	 * @return void
	 */
	private function register_book_post_type() {
		register_post_type(
			'localepress_seo_book',
			array(
				'public'      => true,
				'show_ui'     => true,
				'has_archive' => 'lp-seo-books',
				'rewrite'     => array( 'slug' => 'lp-seo-books' ),
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
