<?php
/**
 * Translation dashboard integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Admin\TranslationActions;
use LocalePress\Admin\TranslationDashboardListTable;
use LocalePress\Admin\TranslationDashboardQuery;
use LocalePress\Content\PostTranslationManager;
use LocalePress\Content\PostTypeSupport;
use LocalePress\Infrastructure\DatabaseTranslationDashboardRepository;
use LocalePress\Infrastructure\DatabaseTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;

/**
 * Verifies Phase 9 reporting, filters, pagination, and matrix rendering.
 */
class Test_LocalePress_Translation_Dashboard extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Translation manager under test.
	 *
	 * @var PostTranslationManager
	 */
	private $translations;

	/**
	 * Dashboard query service under test.
	 *
	 * @var TranslationDashboardQuery
	 */
	private $dashboard;

	/**
	 * Registered language IDs keyed by language code.
	 *
	 * @var array<string, string>
	 */
	private $language_ids = array();

	/**
	 * Created post IDs keyed by fixture name.
	 *
	 * @var array<string, int>
	 */
	private $posts = array();

	/**
	 * All post IDs created by a test.
	 *
	 * @var array<int, int>
	 */
	private $post_ids = array();

	/**
	 * Prepares four source groups with complete, draft, and missing states.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		register_post_type(
			'localepress_book',
			array(
				'public'  => true,
				'show_ui' => true,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		DatabaseTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		OptionsLanguageRepository::install();

		$languages = new LanguageManager(
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

		$this->translations = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$languages,
			new PostTypeSupport()
		);
		$this->dashboard    = new TranslationDashboardQuery(
			new DatabaseTranslationDashboardRepository(),
			$this->translations,
			$languages
		);

		$this->create_fixtures();
	}

	/**
	 * Removes test content and isolated storage.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( array_unique( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		unregister_post_type( 'localepress_book' );
		remove_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Dashboard rows represent source groups and include one bulk-loaded map.
	 *
	 * @return void
	 */
	public function test_query_returns_one_row_per_source_group() {
		$result     = $this->dashboard->query( array( 'per_page' => 20 ) );
		$source_ids = wp_list_pluck( $result['items'], 'source_post_id' );
		$alpha      = $this->find_row( $result['items'], $this->posts['alpha'] );

		$this->assertSame( 4, $result['total'] );
		$this->assertEqualsCanonicalizing(
			array(
				$this->posts['alpha'],
				$this->posts['bare'],
				$this->posts['complete'],
				$this->posts['book'],
			),
			$source_ids
		);
		$this->assertNotContains( $this->posts['alpha_de'], $source_ids, true );
		$this->assertCount( 3, $alpha['translations'] );
	}

	/**
	 * Status and language filters use group-level SQL rather than row queries.
	 *
	 * @return void
	 */
	public function test_status_and_language_filters() {
		$this->assertQuerySources( array( 'status' => 'missing' ), array( 'bare', 'book' ) );
		$this->assertQuerySources( array( 'status' => 'completed' ), array( 'complete' ) );
		$this->assertQuerySources( array( 'status' => 'draft' ), array( 'alpha' ) );
		$this->assertQuerySources(
			array(
				'language_id' => $this->language_ids['de'],
				'status'      => 'all',
			),
			array( 'alpha', 'complete' )
		);
		$this->assertQuerySources(
			array(
				'language_id' => $this->language_ids['fr'],
				'status'      => 'missing',
			),
			array( 'bare', 'book' )
		);
	}

	/**
	 * Content type and search filters cover public CPTs and translated titles.
	 *
	 * @return void
	 */
	public function test_content_type_and_translated_title_filters() {
		$this->assertQuerySources( array( 'post_type' => 'localepress_book' ), array( 'book' ) );
		$this->assertQuerySources( array( 'search' => 'Needle' ), array( 'alpha' ) );
	}

	/**
	 * Pagination limits rows while preserving the full source-group count.
	 *
	 * @return void
	 */
	public function test_pagination_reports_the_total() {
		$first_page  = $this->dashboard->query( array( 'per_page' => 2 ) );
		$second_page = $this->dashboard->query(
			array(
				'page'     => 2,
				'per_page' => 2,
			)
		);

		$this->assertSame( 4, $first_page['total'] );
		$this->assertCount( 2, $first_page['items'] );
		$this->assertSame( 4, $second_page['total'] );
		$this->assertCount( 2, $second_page['items'] );
		$this->assertEmpty(
			array_intersect(
				wp_list_pluck( $first_page['items'], 'source_post_id' ),
				wp_list_pluck( $second_page['items'], 'source_post_id' )
			)
		);
	}

	/**
	 * Query count stays bounded as one page loads multiple translation groups.
	 *
	 * @return void
	 */
	public function test_query_count_is_bounded() {
		global $wpdb;

		$before = $wpdb->num_queries;
		$result = $this->dashboard->query( array( 'per_page' => 20 ) );
		$used   = $wpdb->num_queries - $before;

		$this->assertSame( 4, $result['total'] );
		$this->assertLessThanOrEqual( 10, $used );
	}

	/**
	 * Users lacking edit_others_posts see only their own source rows.
	 *
	 * @return void
	 */
	public function test_query_respects_edit_others_capability() {
		$administrator_id = get_current_user_id();
		$author_id        = self::factory()->user->create( array( 'role' => 'author' ) );
		$own_post_id      = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
				'post_title'  => 'Author source',
				'post_type'   => 'post',
			)
		);
		$this->post_ids[] = $own_post_id;

		wp_set_current_user( $author_id );
		$result = $this->dashboard->query( array( 'per_page' => 20 ) );
		wp_set_current_user( $administrator_id );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( array( $own_post_id ), wp_list_pluck( $result['items'], 'source_post_id' ) );
	}

	/**
	 * The list table renders filters and accessible add/edit actions.
	 *
	 * @return void
	 */
	public function test_list_table_renders_matrix_actions() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Test-only read filter state.
		$previous_get = $_GET;
		$_GET         = array( 'page' => 'localepress-translations' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$table = new TranslationDashboardListTable(
			$this->dashboard,
			$this->translations,
			new TranslationActions()
		);
		$table->prepare_items();

		ob_start();
		$table->display();
		$html = ob_get_clean();
		$_GET = $previous_get;

		$this->assertStringContainsString( 'name="localepress_post_type"', $html );
		$this->assertStringContainsString( 'name="localepress_language"', $html );
		$this->assertStringContainsString( 'name="localepress_status"', $html );
		$this->assertStringContainsString( 'Add German translation', $html );
		$this->assertStringContainsString( 'German translation is complete', $html );
	}

	/**
	 * Creates the dashboard content fixtures.
	 *
	 * @return void
	 */
	private function create_fixtures() {
		$this->posts['alpha']    = $this->create_post( 'Alpha', 'page', 'publish' );
		$this->posts['alpha_de'] = $this->create_post( 'Needle German', 'page', 'publish' );
		$this->posts['alpha_fr'] = $this->create_post( 'Alpha French', 'page', 'draft' );
		$this->link(
			array(
				'en' => $this->posts['alpha'],
				'de' => $this->posts['alpha_de'],
				'fr' => $this->posts['alpha_fr'],
			),
			$this->posts['alpha']
		);

		$this->posts['bare'] = $this->create_post( 'Bare source', 'post', 'publish' );

		$this->posts['complete']    = $this->create_post( 'Complete source', 'page', 'publish' );
		$this->posts['complete_de'] = $this->create_post( 'Complete German', 'page', 'publish' );
		$this->posts['complete_fr'] = $this->create_post( 'Complete French', 'page', 'publish' );
		$this->link(
			array(
				'en' => $this->posts['complete'],
				'de' => $this->posts['complete_de'],
				'fr' => $this->posts['complete_fr'],
			),
			$this->posts['complete']
		);

		$this->posts['book'] = $this->create_post( 'Book source', 'localepress_book', 'publish' );
	}

	/**
	 * Creates and tracks a post fixture.
	 *
	 * @param string $title     Post title.
	 * @param string $post_type Post type.
	 * @param string $status    Post status.
	 * @return int
	 */
	private function create_post( $title, $post_type, $status ) {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => $post_type,
				'post_status' => $status,
			)
		);

		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Links fixtures using short language-code keys.
	 *
	 * @param array<string, int> $posts         Posts keyed by language code.
	 * @param int                $source_post_id Source post identifier.
	 * @return void
	 */
	private function link( $posts, $source_post_id ) {
		$translations = array();

		foreach ( $posts as $code => $post_id ) {
			$translations[ $this->language_ids[ $code ] ] = $post_id;
		}

		$this->assertNotWPError( $this->translations->link_translations( $translations, $source_post_id ) );
	}

	/**
	 * Asserts the source rows produced by dashboard filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @param array<int, string>   $keys Expected fixture keys.
	 * @return void
	 */
	private function assertQuerySources( $args, $keys ) {
		$result   = $this->dashboard->query( array_merge( array( 'per_page' => 20 ), $args ) );
		$expected = array();

		foreach ( $keys as $key ) {
			$expected[] = $this->posts[ $key ];
		}

		$this->assertSame( count( $expected ), $result['total'] );
		$this->assertEqualsCanonicalizing( $expected, wp_list_pluck( $result['items'], 'source_post_id' ) );
	}

	/**
	 * Finds one prepared row by source post ID.
	 *
	 * @param array<int, array<string, mixed>> $items          Prepared dashboard rows.
	 * @param int                              $source_post_id Source post identifier.
	 * @return array<string, mixed>
	 */
	private function find_row( $items, $source_post_id ) {
		foreach ( $items as $item ) {
			if ( $source_post_id === $item['source_post_id'] ) {
				return $item;
			}
		}

		return array();
	}

	/**
	 * Clears the two custom relationship tables.
	 *
	 * @return void
	 */
	private function clear_relationship_tables() {
		global $wpdb;

		$assignments_table = DatabaseTranslationRepository::assignments_table();
		$groups_table      = DatabaseTranslationRepository::groups_table();

		// Table names are generated by the repository from the WordPress prefix.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$assignments_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$groups_table}" );
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
