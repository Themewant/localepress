<?php
/**
 * Term translation relationship integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Infrastructure\DatabaseTermTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationLifecycleModule;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies Phase 3 relationship rules against core and custom taxonomies.
 */
class Test_LocalePress_Term_Translations extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Term translation manager under test.
	 *
	 * @var TermTranslationManager
	 */
	private $translations;

	/**
	 * Language manager under test.
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
	 * Created terms keyed by taxonomy.
	 *
	 * @var array<string, array<int, int>>
	 */
	private $term_ids = array();

	/**
	 * Prepares empty language and term relationship storage.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'localepress_auto_assign_default_term_language', array( $this, 'disable_automatic_default_assignment' ) );
		$this->language_ids = array();
		$this->term_ids     = array();

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

		$this->translations = $this->new_translation_manager();
	}

	/**
	 * Removes created terms and relationship storage.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->term_ids as $taxonomy => $term_ids ) {
			foreach ( array_reverse( array_unique( $term_ids ) ) as $term_id ) {
				wp_delete_term( $term_id, $taxonomy );
			}
		}

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );

		if ( taxonomy_exists( 'localepress_region' ) ) {
			unregister_taxonomy( 'localepress_region' );
		}

		remove_filter( 'localepress_auto_assign_default_term_language', array( $this, 'disable_automatic_default_assignment' ) );

		parent::tear_down();
	}

	/**
	 * Disables the shared plugin lifecycle while isolated managers are tested.
	 *
	 * @return bool
	 */
	public function disable_automatic_default_assignment() {
		return false;
	}

	/**
	 * Unassigned existing terms immediately use the default language virtually.
	 *
	 * @return void
	 */
	public function test_unassigned_term_uses_default_language_without_read_time_write() {
		$term_id = $this->create_term( 'Legacy category', 'category' );

		$this->assertSame( $this->language_ids['en'], $this->translations->get_term_language_id( $term_id, 'category' ) );
		$this->assertSame( array( $this->language_ids['en'] => $term_id ), $this->translations->get_translations( $term_id, 'category' ) );
		$this->assertSame( '', $this->translations->get_group_id( $term_id, 'category' ) );
	}

	/**
	 * The global lifecycle persists the default language for newly created terms.
	 *
	 * @return void
	 */
	public function test_new_term_automatically_persists_default_language() {
		$term_id   = $this->create_term( 'Automatic category', 'category' );
		$term      = get_term( $term_id, 'category' );
		$lifecycle = new TermTranslationLifecycleModule( $this->translations );

		remove_filter( 'localepress_auto_assign_default_term_language', array( $this, 'disable_automatic_default_assignment' ) );
		$lifecycle->assign_default_language( $term_id, $term->term_taxonomy_id, 'category' );
		add_filter( 'localepress_auto_assign_default_term_language', array( $this, 'disable_automatic_default_assignment' ) );

		$assignment = ( new DatabaseTermTranslationRepository() )->find_by_term( $term_id, 'category' );

		$this->assertIsArray( $assignment );
		$this->assertSame( $this->language_ids['en'], $assignment['language_id'] );
	}

	/**
	 * One-click translation materializes an existing virtual default source term.
	 *
	 * @return void
	 */
	public function test_translation_materializes_unassigned_default_term() {
		$source     = $this->create_term( 'Legacy source', 'category' );
		$translated = $this->translations->create_translation( $source, 'category', $this->language_ids['de'] );

		$this->assertNotWPError( $translated );
		$this->term_ids['category'][] = $translated;
		$this->assertNotSame( '', $this->translations->get_group_id( $source, 'category' ) );
		$this->assertSame( $translated, $this->translations->get_translation( $source, 'category', $this->language_ids['de'] ) );
	}

	/**
	 * Categories can be assigned and linked across three languages.
	 *
	 * @return void
	 */
	public function test_categories_share_one_translation_group() {
		$english = $this->create_term( 'Travel', 'category' );
		$german  = $this->create_term( 'Reisen', 'category' );
		$french  = $this->create_term( 'Voyage', 'category' );
		$result  = $this->translations->link_translations(
			array(
				$this->language_ids['en'] => $english,
				$this->language_ids['de'] => $german,
				$this->language_ids['fr'] => $french,
			),
			'category',
			$english
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $this->translations->get_group_id( $english, 'category' ), $this->translations->get_group_id( $german, 'category' ) );
		$this->assertSame( $french, $this->translations->get_translation( $english, 'category', $this->language_ids['fr'] ) );
		$this->assertCount( 3, $this->translations->get_translations( $english, 'category' ) );
	}

	/**
	 * Tags use the generic translated-copy workflow without term metadata copying.
	 *
	 * @return void
	 */
	public function test_tag_translation_copies_only_core_term_fields() {
		$source = $this->create_term( 'City Breaks', 'post_tag', array( 'description' => 'Source description' ) );

		add_term_meta( $source, '_localepress_test_private', 'do-not-copy' );
		$this->translations->set_term_language( $source, 'post_tag', $this->language_ids['en'] );
		$translated = $this->translations->create_translation( $source, 'post_tag', $this->language_ids['de'] );

		$this->assertNotWPError( $translated );
		$this->term_ids['post_tag'][] = $translated;

		$term = get_term( $translated, 'post_tag' );

		$this->assertSame( 'Source description', $term->description );
		$this->assertSame( 0, $term->parent );
		$this->assertSame( '', get_term_meta( $translated, '_localepress_test_private', true ) );
		$this->assertSame( $translated, $this->translations->get_translation( $source, 'post_tag', $this->language_ids['de'] ) );
	}

	/**
	 * A translated parent created later repairs translated custom-taxonomy children.
	 *
	 * @return void
	 */
	public function test_hierarchical_custom_taxonomy_maintains_translated_parent() {
		$this->register_region_taxonomy();
		$parent = $this->create_term( 'Europe', 'localepress_region' );
		$child  = $this->create_term( 'Coast', 'localepress_region', array( 'parent' => $parent ) );

		$this->translations->set_term_language( $parent, 'localepress_region', $this->language_ids['en'] );
		$this->translations->set_term_language( $child, 'localepress_region', $this->language_ids['en'] );
		$german_child = $this->translations->create_translation( $child, 'localepress_region', $this->language_ids['de'] );

		$this->assertNotWPError( $german_child );
		$this->term_ids['localepress_region'][] = $german_child;
		$this->assertSame( 0, get_term( $german_child, 'localepress_region' )->parent );

		$german_parent = $this->translations->create_translation( $parent, 'localepress_region', $this->language_ids['de'] );

		$this->assertNotWPError( $german_parent );
		$this->term_ids['localepress_region'][] = $german_parent;
		$this->assertSame( $german_parent, get_term( $german_child, 'localepress_region' )->parent );
	}

	/**
	 * Deleting a translated term removes its assignment and keeps the source group.
	 *
	 * @return void
	 */
	public function test_deleting_translation_repairs_group() {
		$source = $this->create_term( 'Journal', 'category' );

		$this->translations->set_term_language( $source, 'category', $this->language_ids['en'] );
		$translated = $this->translations->create_translation( $source, 'category', $this->language_ids['de'] );

		$this->assertNotWPError( $translated );
		$this->term_ids['category'][] = $translated;
		wp_delete_term( $translated, 'category' );
		$translations = $this->new_translation_manager();

		$this->assertSame( $source, $translations->get_source_term_id( $source, 'category' ) );
		$this->assertSame( 0, $translations->get_translation( $source, 'category', $this->language_ids['de'] ) );
		$this->assertCount( 1, $translations->get_translations( $source, 'category' ) );
	}

	/**
	 * Language changes work only while the target slot remains free.
	 *
	 * @return void
	 */
	public function test_language_change_and_duplicate_prevention() {
		$english = $this->create_term( 'Guides', 'category' );
		$german  = $this->create_term( 'Ratgeber', 'category' );

		$this->translations->link_translations(
			array(
				$this->language_ids['en'] => $english,
				$this->language_ids['de'] => $german,
			),
			'category',
			$english
		);

		$changed = $this->translations->set_term_language( $german, 'category', $this->language_ids['fr'] );
		$this->assertNotWPError( $changed );
		$this->assertSame( $this->language_ids['fr'], $this->translations->get_term_language_id( $german, 'category' ) );

		$duplicate = $this->translations->set_term_language( $german, 'category', $this->language_ids['en'] );
		$this->assertWPError( $duplicate );
		$this->assertSame( 'duplicate_term_translation_language', $duplicate->get_error_code() );
		$this->assertSame( $this->language_ids['fr'], $this->translations->get_term_language_id( $german, 'category' ) );
	}

	/**
	 * A language assigned to a term cannot be deleted.
	 *
	 * @return void
	 */
	public function test_assigned_term_language_cannot_be_deleted() {
		$term_id = $this->create_term( 'Protected language', 'category' );

		// A non-default language, because the default is refused before the
		// in-use check is ever reached.
		$this->translations->set_term_language( $term_id, 'category', $this->language_ids['fr'] );
		$result = $this->languages->delete( $this->language_ids['fr'] );

		$this->assertWPError( $result );
		$this->assertSame( 'language_in_use', $result->get_error_code() );
	}

	/**
	 * Public custom taxonomies are supported by the generic architecture.
	 *
	 * @return void
	 */
	public function test_public_custom_taxonomy_is_supported() {
		$this->register_region_taxonomy();

		$this->assertTrue( $this->translations->supports_taxonomy( 'localepress_region' ) );
	}

	/**
	 * A taxonomy name sends the integration helper down the term path.
	 *
	 * @return void
	 */
	public function test_object_id_resolves_a_term_when_given_a_taxonomy() {
		$source = $this->create_term( 'Guides', 'category' );
		$this->translations->set_term_language( $source, 'category', $this->language_ids['en'] );
		$german = $this->translations->create_translation( $source, 'category', $this->language_ids['de'] );

		$this->assertNotWPError( $german );
		$this->term_ids['category'][] = $german;

		$this->assertSame( $german, localepress_object_id( $source, 'category', true, 'de' ) );
		$this->assertSame( $source, localepress_object_id( $source, 'category', true, 'fr' ) );
	}

	/**
	 * Creates and tracks one term.
	 *
	 * @param string               $name     Term name.
	 * @param string               $taxonomy Taxonomy name.
	 * @param array<string, mixed> $args     Optional term arguments.
	 * @return int
	 */
	private function create_term( $name, $taxonomy, $args = array() ) {
		$result = wp_insert_term( $name, $taxonomy, $args );

		$this->assertNotWPError( $result );
		$term_id                       = absint( $result['term_id'] );
		$this->term_ids[ $taxonomy ][] = $term_id;

		return $term_id;
	}

	/**
	 * Registers a hierarchical public taxonomy for tests.
	 *
	 * @return void
	 */
	private function register_region_taxonomy() {
		if ( taxonomy_exists( 'localepress_region' ) ) {
			return;
		}

		register_taxonomy(
			'localepress_region',
			'post',
			array(
				'public'       => true,
				'show_ui'      => true,
				'hierarchical' => true,
			)
		);
	}

	/**
	 * Builds a fresh manager with an empty request cache.
	 *
	 * @return TermTranslationManager
	 */
	private function new_translation_manager() {
		return new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$this->languages,
			new TaxonomySupport()
		);
	}

	/**
	 * Clears the term relationship tables.
	 *
	 * @return void
	 */
	private function clear_relationship_tables() {
		global $wpdb;

		$assignments_table = DatabaseTermTranslationRepository::assignments_table();
		$groups_table      = DatabaseTermTranslationRepository::groups_table();

		// Table names are generated by the repository from the WordPress prefix.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$assignments_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$groups_table}" );
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
