<?php
/**
 * Elementor Theme Builder integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Content\PostTranslationManager;
use LocalePress\Content\PostTypeSupport;
use LocalePress\Infrastructure\DatabaseTermTranslationRepository;
use LocalePress\Infrastructure\DatabaseTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Integrations\Elementor\ElementorCompatibility;
use LocalePress\Integrations\Elementor\ElementorThemeBuilder;
use LocalePress\Integrations\Elementor\ElementorThemeBuilderModule;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies template, condition, and popup behavior without Elementor installed.
 */
class Test_LocalePress_Elementor_Theme_Builder extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Theme Builder language resolution under test.
	 *
	 * @var ElementorThemeBuilder
	 */
	private $theme_builder;

	/**
	 * Theme Builder module under test.
	 *
	 * @var ElementorThemeBuilderModule
	 */
	private $module;

	/**
	 * Elementor document copy service.
	 *
	 * @var ElementorCompatibility
	 */
	private $compatibility;

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
	 * Prepares isolated language and relationship storage.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		add_filter( 'localepress_elementor_available', '__return_true' );

		$this->post_ids     = array();
		$this->term_ids     = array();
		$this->language_ids = array();

		DatabaseTranslationRepository::install();
		DatabaseTermTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
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
		$this->theme_builder     = new ElementorThemeBuilder( $this->translations, $this->term_translations );
		$this->compatibility     = new ElementorCompatibility();
		$this->module            = new ElementorThemeBuilderModule(
			$this->theme_builder,
			$this->translations,
			new LanguageUrlManager(
				$this->languages,
				$this->translations,
				$this->term_translations
			)
		);
	}

	/**
	 * Removes test content and storage.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( array_unique( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		foreach ( array_unique( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		remove_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		remove_filter( 'localepress_elementor_available', '__return_true' );

		parent::tear_down();
	}

	/**
	 * A translated template receives the rules that make it displayable.
	 *
	 * @return void
	 */
	public function test_conditions_location_and_popup_settings_are_copied() {
		list( $source, $target ) = $this->create_template_pair();

		update_post_meta( $source, ElementorThemeBuilder::CONDITIONS_META_KEY, array( 'include/general' ) );
		update_post_meta( $source, ElementorThemeBuilder::LOCATION_META_KEY, 'header' );
		update_post_meta(
			$source,
			ElementorThemeBuilder::POPUP_SETTINGS_META_KEY,
			array(
				'triggers'       => array( 'page_load' => 'yes' ),
				'timing'         => array(),
				'entrance_delay' => 3,
			)
		);

		$this->assertTrue( $this->compatibility->copy_document( $target, $source ) );

		$this->assertSame(
			array( 'include/general' ),
			get_post_meta( $target, ElementorThemeBuilder::CONDITIONS_META_KEY, true )
		);
		$this->assertSame( 'header', get_post_meta( $target, ElementorThemeBuilder::LOCATION_META_KEY, true ) );
		$this->assertSame(
			array(
				'triggers'       => array( 'page_load' => 'yes' ),
				'timing'         => array(),
				'entrance_delay' => 3,
			),
			get_post_meta( $target, ElementorThemeBuilder::POPUP_SETTINGS_META_KEY, true )
		);
	}

	/**
	 * A condition naming a page follows that page into the target language.
	 *
	 * @return void
	 */
	public function test_copied_conditions_point_at_the_target_language_content() {
		$this->module->register();

		list( $english_page, $bengali_page ) = $this->create_post_pair( 'page' );
		list( $source, $target )             = $this->create_template_pair();

		update_post_meta(
			$source,
			ElementorThemeBuilder::CONDITIONS_META_KEY,
			array( 'include/general', 'exclude/singular/page/' . $english_page )
		);

		$this->assertTrue( $this->compatibility->copy_document( $target, $source ) );

		$this->assertSame(
			array( 'include/general', 'exclude/singular/page/' . $bengali_page ),
			get_post_meta( $target, ElementorThemeBuilder::CONDITIONS_META_KEY, true )
		);

		// The source keeps its own rule; copying is not synchronization.
		$this->assertSame(
			array( 'include/general', 'exclude/singular/page/' . $english_page ),
			get_post_meta( $source, ElementorThemeBuilder::CONDITIONS_META_KEY, true )
		);
	}

	/**
	 * A category condition follows the term translation.
	 *
	 * @return void
	 */
	public function test_archive_conditions_follow_term_translations() {
		$english_term = self::factory()->category->create( array( 'name' => 'News' ) );
		$bengali_term = self::factory()->category->create( array( 'name' => 'Khobor' ) );

		$this->term_ids[] = $english_term;
		$this->term_ids[] = $bengali_term;

		$this->assertNotWPError(
			$this->term_translations->link_translations(
				array(
					$this->language_ids['en'] => $english_term,
					$this->language_ids['bn'] => $bengali_term,
				),
				'category',
				$english_term
			)
		);

		$this->assertSame(
			'include/archive/category/' . $bengali_term,
			$this->theme_builder->translate_condition(
				'include/archive/category/' . $english_term,
				$this->language_ids['bn']
			)
		);
		$this->assertSame(
			'include/singular/in_category/' . $bengali_term,
			$this->theme_builder->translate_condition(
				'include/singular/in_category/' . $english_term,
				$this->language_ids['bn']
			)
		);
	}

	/**
	 * An author condition names a user, which has no translation to follow.
	 *
	 * @return void
	 */
	public function test_author_conditions_are_left_alone() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		foreach (
			array(
				'include/singular/by_author/' . $author,
				'include/archive/author/' . $author,
			) as $condition
		) {
			$this->assertSame(
				$condition,
				$this->theme_builder->translate_condition( $condition, $this->language_ids['bn'] )
			);
		}
	}

	/**
	 * A location resolves to the translation only once that translation is live.
	 *
	 * @return void
	 */
	public function test_location_resolves_to_a_published_translation_only() {
		list( $source, $target ) = $this->create_template_pair();

		// LocalePress creates every translation as a draft. Answering a location
		// with one would leave Elementor with nothing it is willing to render.
		$this->assertSame( 0, $this->theme_builder->translate_template_id( $source, $this->language_ids['bn'] ) );

		wp_update_post(
			array(
				'ID'          => $target,
				'post_status' => 'publish',
			)
		);

		$this->assertSame(
			$target,
			$this->theme_builder->translate_template_id( $source, $this->language_ids['bn'] )
		);

		// A template already written in the language being read stays put.
		$this->assertSame( 0, $this->theme_builder->translate_template_id( $target, $this->language_ids['bn'] ) );
	}

	/**
	 * An untranslated template keeps serving every language.
	 *
	 * @return void
	 */
	public function test_untranslated_template_is_not_replaced() {
		$template = $this->create_template( 'Standalone header' );

		$this->assertNotWPError(
			$this->translations->set_post_language( $template, $this->language_ids['en'] )
		);

		$this->assertSame( 0, $this->theme_builder->translate_template_id( $template, $this->language_ids['bn'] ) );
	}

	/**
	 * Conditions can be copied verbatim when a site asks for that.
	 *
	 * @return void
	 */
	public function test_condition_rewriting_can_be_disabled() {
		$this->module->register();
		add_filter( 'localepress_elementor_translate_conditions', '__return_false' );

		list( $english_page ) = $this->create_post_pair( 'page' );
		list( $source, $target ) = $this->create_template_pair();

		update_post_meta(
			$source,
			ElementorThemeBuilder::CONDITIONS_META_KEY,
			array( 'include/singular/page/' . $english_page )
		);

		$this->assertTrue( $this->compatibility->copy_document( $target, $source ) );

		$this->assertSame(
			array( 'include/singular/page/' . $english_page ),
			get_post_meta( $target, ElementorThemeBuilder::CONDITIONS_META_KEY, true )
		);

		remove_filter( 'localepress_elementor_translate_conditions', '__return_false' );
	}

	/**
	 * Creates an Elementor template post.
	 *
	 * @param string $title Post title.
	 * @return int
	 */
	private function create_template( $title = 'Header' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		$this->post_ids[] = $post_id;

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'header' );
		update_post_meta(
			$post_id,
			ElementorCompatibility::DATA_META_KEY,
			wp_slash( wp_json_encode( array( array( 'elType' => 'container' ) ) ) )
		);

		return $post_id;
	}

	/**
	 * Creates a linked source template and its translated draft.
	 *
	 * @return array<int, int>
	 */
	private function create_template_pair() {
		$source = $this->create_template( 'English header' );
		$target = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Bengali header',
			)
		);

		$this->post_ids[] = $target;

		$this->assertNotWPError(
			$this->translations->link_translations(
				array(
					$this->language_ids['en'] => $source,
					$this->language_ids['bn'] => $target,
				),
				$source
			)
		);

		return array( $source, $target );
	}

	/**
	 * Creates a linked pair of ordinary posts.
	 *
	 * @param string $post_type Post type name.
	 * @return array<int, int>
	 */
	private function create_post_pair( $post_type = 'page' ) {
		$source = self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => 'About',
			)
		);
		$target = self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => 'Amader somporke',
			)
		);

		$this->post_ids[] = $source;
		$this->post_ids[] = $target;

		$this->assertNotWPError(
			$this->translations->link_translations(
				array(
					$this->language_ids['en'] => $source,
					$this->language_ids['bn'] => $target,
				),
				$source
			)
		);

		return array( $source, $target );
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
