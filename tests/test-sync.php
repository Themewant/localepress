<?php
/**
 * Translation copy and synchronization integration tests.
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
use LocalePress\Settings\SyncCatalog;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\Sync\SyncModule;
use LocalePress\Sync\TranslationSynchronizer;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies the two-phase copy and synchronization engine.
 */
class Test_LocalePress_Sync extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
	 * Copy and synchronization engine under test.
	 *
	 * @var TranslationSynchronizer
	 */
	private $synchronizer;

	/**
	 * Workflow settings under test.
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
	 * Administrator running the synchronization tests.
	 *
	 * @var int
	 */
	private $administrator = 0;

	/**
	 * Prepares isolated language, relationship, and workflow storage.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'localepress_auto_assign_default_post_language', '__return_false' );

		// Synchronization is permission aware, so the tests need an editor who
		// may change every translation in a group.
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator );
		$this->language_ids = array();
		$this->post_ids     = array();
		$this->term_ids     = array();

		DatabaseTranslationRepository::install();
		DatabaseTermTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		OptionsLanguageRepository::install();
		WorkflowSettings::install();

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

		$this->workflow          = new WorkflowSettings();
		$this->translations      = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$this->languages,
			new PostTypeSupport(),
			$this->workflow
		);
		$this->term_translations = new TermTranslationManager(
			new DatabaseTermTranslationRepository(),
			$this->languages,
			new TaxonomySupport()
		);
		$this->synchronizer      = new TranslationSynchronizer(
			$this->translations,
			$this->term_translations,
			$this->workflow,
			new TaxonomySupport()
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
		delete_option( WorkflowSettings::OPTION_NAME );
		remove_filter( 'localepress_auto_assign_default_post_language', '__return_false' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Public custom fields and the page template reach a new translation.
	 *
	 * @return void
	 */
	public function test_copy_writes_public_meta_and_page_template() {
		list( $source, $target ) = $this->create_pair();

		update_post_meta( $source, 'subtitle', 'Source subtitle' );
		update_post_meta( $source, '_wp_page_template', 'full-width.php' );
		update_post_meta( $source, '_private_state', 'source only' );
		update_post_meta( $target, 'stale_field', 'remove me' );

		$this->synchronizer->copy( $target, $source, $this->language_ids['de'] );

		$this->assertSame( 'Source subtitle', get_post_meta( $target, 'subtitle', true ) );
		$this->assertSame( 'full-width.php', get_post_meta( $target, '_wp_page_template', true ) );
		$this->assertSame( '', get_post_meta( $target, '_private_state', true ) );
		$this->assertSame( '', get_post_meta( $target, 'stale_field', true ) );
	}

	/**
	 * Every value of a multi-value custom field survives the copy.
	 *
	 * @return void
	 */
	public function test_copy_preserves_multiple_values_of_one_field() {
		list( $source, $target ) = $this->create_pair();

		add_post_meta( $source, 'tagline', 'first' );
		add_post_meta( $source, 'tagline', 'second' );

		$this->synchronizer->copy( $target, $source, $this->language_ids['de'] );

		$this->assertSame( array( 'first', 'second' ), get_post_meta( $target, 'tagline' ) );
	}

	/**
	 * Copying is unconditional and is not affected by synchronization choices.
	 *
	 * @return void
	 */
	public function test_copy_runs_even_when_synchronization_is_off() {
		list( $source, $target ) = $this->create_pair();

		update_post_meta( $source, 'subtitle', 'Source subtitle' );
		update_post_meta( $source, '_wp_page_template', 'full-width.php' );

		$this->assertFalse( $this->workflow->has_enabled_sync_items() );

		$this->synchronizer->copy( $target, $source, $this->language_ids['de'] );

		$this->assertSame( 'Source subtitle', get_post_meta( $target, 'subtitle', true ) );
		$this->assertSame( 'full-width.php', get_post_meta( $target, '_wp_page_template', true ) );
	}

	/**
	 * The published date follows its synchronization setting in both phases.
	 *
	 * @return void
	 */
	public function test_published_date_copy_follows_its_synchronization_setting() {
		$this->assertFalse( $this->workflow->is_copy_enabled( 'post_date' ) );

		$this->workflow->update( array( SyncCatalog::sync_key( 'post_date' ) => true ) );

		$this->assertTrue( $this->workflow->is_copy_enabled( 'post_date' ) );
		$this->assertTrue( $this->workflow->is_copy_enabled( 'post_meta' ), 'Every other item is always copied.' );
	}

	/**
	 * A sticky source makes its new translation sticky, without any setting.
	 *
	 * @return void
	 */
	public function test_copy_carries_sticky_state() {
		list( $source, $target ) = $this->create_pair();

		stick_post( $source );
		$this->synchronizer->copy( $target, $source, $this->language_ids['de'] );
		$is_sticky = is_sticky( $target );
		unstick_post( $source );
		unstick_post( $target );

		$this->assertTrue( $is_sticky );
	}

	/**
	 * Copied terms use their own translation when the taxonomy is translatable.
	 *
	 * @return void
	 */
	public function test_copy_maps_terms_to_their_translations() {
		list( $source, $target ) = $this->create_pair();

		$source_term  = $this->create_category( 'Travel', 'lp-travel' );
		$target_term  = $this->create_category( 'Reisen', 'lp-reisen' );
		$untranslated = $this->create_category( 'News', 'lp-news' );

		$this->assertNotWPError(
			$this->term_translations->link_translations(
				array(
					$this->language_ids['en'] => $source_term,
					$this->language_ids['de'] => $target_term,
				),
				'category',
				$source_term
			)
		);
		wp_set_object_terms( $source, array( $source_term, $untranslated ), 'category' );

		$this->synchronizer->copy( $target, $source, $this->language_ids['de'] );

		$assigned = wp_get_object_terms( $target, 'category', array( 'fields' => 'ids' ) );

		$this->assertContains( $target_term, $assigned );
		$this->assertNotContains( $source_term, $assigned );
		$this->assertNotContains(
			$untranslated,
			$assigned,
			'A term without a translation is dropped rather than assigned in another language.'
		);
	}

	/**
	 * A term only the translation can express survives a synchronizing save.
	 *
	 * @return void
	 */
	public function test_sync_keeps_a_term_the_source_language_cannot_express() {
		list( $source, $target ) = $this->create_pair();

		$english = $this->create_category( 'Apple EN', 'lp-apple-en' );
		$german  = $this->create_category( 'Apple DE', 'lp-apple-de' );

		// Deliberately unlinked: neither term is a translation of the other.
		$this->assertNotWPError( $this->term_translations->set_term_language( $english, 'category', $this->language_ids['en'] ) );
		$this->assertNotWPError( $this->term_translations->set_term_language( $german, 'category', $this->language_ids['de'] ) );

		wp_set_object_terms( $source, array( $english ), 'category' );
		wp_set_object_terms( $target, array( $german ), 'category' );

		$this->synchronizer->copy( $target, $source, $this->language_ids['de'] );

		$assigned = wp_get_object_terms( $target, 'category', array( 'fields' => 'ids' ) );

		$this->assertContains(
			$german,
			$assigned,
			'A German-only term is not the English post to remove.'
		);
		$this->assertNotContains( $english, $assigned );
	}

	/**
	 * Unassigning a translated term still propagates to the translation.
	 *
	 * @return void
	 */
	public function test_sync_still_removes_a_term_the_source_can_express() {
		list( $source, $target ) = $this->create_pair();

		$english = $this->create_category( 'Travel', 'lp-travel-2' );
		$german  = $this->create_category( 'Reisen', 'lp-reisen-2' );

		$this->assertNotWPError(
			$this->term_translations->link_translations(
				array(
					$this->language_ids['en'] => $english,
					$this->language_ids['de'] => $german,
				),
				'category',
				$english
			)
		);

		wp_set_object_terms( $target, array( $german ), 'category' );
		wp_set_object_terms( $source, array(), 'category' );

		$this->synchronizer->copy( $target, $source, $this->language_ids['de'] );

		$this->assertNotContains(
			$german,
			wp_get_object_terms( $target, 'category', array( 'fields' => 'ids' ) ),
			'A term the source has a translation of stays removable.'
		);
	}

	/**
	 * A filter can keep an untranslated term on the translation.
	 *
	 * @return void
	 */
	public function test_a_filter_can_keep_an_untranslated_term() {
		list( $source, $target ) = $this->create_pair();

		$untranslated = $this->create_category( 'News', 'lp-news' );

		wp_set_object_terms( $source, array( $untranslated ), 'category' );

		$keep = static function ( $translation, $term_id ) {
			return 0 < $translation ? $translation : $term_id;
		};

		add_filter( 'localepress_mapped_term_id', $keep, 10, 2 );
		$this->synchronizer->copy( $target, $source, $this->language_ids['de'] );
		remove_filter( 'localepress_mapped_term_id', $keep, 10 );

		$this->assertContains(
			$untranslated,
			wp_get_object_terms( $target, 'category', array( 'fields' => 'ids' ) )
		);
	}

	/**
	 * A meta key that only describes one post is never written to another.
	 *
	 * @return void
	 */
	public function test_blocked_meta_keys_survive_a_permissive_filter() {
		list( $source, $target ) = $this->create_pair();

		add_filter( 'localepress_copy_post_meta_keys', array( $this, 'request_blocked_meta_key' ) );
		$keys = $this->synchronizer->get_meta_keys( $source, $target, $this->language_ids['de'], false );
		remove_filter( 'localepress_copy_post_meta_keys', array( $this, 'request_blocked_meta_key' ) );

		$this->assertNotContains( '_edit_lock', $keys );
		$this->assertContains( 'addon_field', $keys );
	}

	/**
	 * Requests one blocked and one allowed meta key.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @return array<int, string>
	 */
	public function request_blocked_meta_key( $keys ) {
		return array_merge( (array) $keys, array( '_edit_lock', 'addon_field' ) );
	}

	/**
	 * Nothing is synchronized while every synchronization item is disabled.
	 *
	 * @return void
	 */
	public function test_synchronization_stays_off_until_an_item_is_enabled() {
		list( $source, $target ) = $this->create_pair();

		update_post_meta( $source, 'subtitle', 'Updated subtitle' );
		$this->synchronizer->synchronize_from( $source );

		$this->assertSame( '', get_post_meta( $target, 'subtitle', true ) );
		$this->assertFalse( $this->workflow->has_enabled_sync_items() );

		$this->workflow->update( array( SyncCatalog::sync_key( 'post_meta' ) => true ) );

		$this->assertTrue( $this->workflow->has_enabled_sync_items() );

		$this->synchronizer->synchronize_from( $source );

		$this->assertSame( 'Updated subtitle', get_post_meta( $target, 'subtitle', true ) );
	}

	/**
	 * Enabled core post fields follow later edits of any post in the group.
	 *
	 * @return void
	 */
	public function test_synchronization_aligns_enabled_post_fields() {
		list( $source, $target ) = $this->create_pair();

		$this->workflow->update(
			array(
				SyncCatalog::sync_key( 'comment_status' ) => true,
				SyncCatalog::sync_key( 'menu_order' )     => true,
			)
		);
		wp_update_post(
			array(
				'ID'             => $source,
				'comment_status' => 'closed',
				'menu_order'     => 7,
			)
		);

		$this->synchronizer->synchronize_from( $source );

		$this->assertSame( 'closed', get_post_field( 'comment_status', $target ) );
		$this->assertSame( 7, (int) get_post_field( 'menu_order', $target ) );
		$this->assertSame( 'open', get_post_field( 'ping_status', $target ), 'A disabled item is left alone.' );
	}

	/**
	 * An editor who cannot edit every translation may not change synced fields.
	 *
	 * @return void
	 */
	public function test_metadata_guard_blocks_editors_without_group_access() {
		list( $source, $target ) = $this->create_pair();

		update_post_meta( $source, 'subtitle', 'Shared subtitle' );
		$this->workflow->update( array( SyncCatalog::sync_key( 'post_meta' ) => true ) );
		$module = new SyncModule( $this->synchronizer, $this->translations, $this->workflow );

		add_filter( 'localepress_current_user_can_synchronize', '__return_false' );
		$blocked = $module->guard_synchronized_metadata( null, $source, 'subtitle' );
		$ignored = $module->guard_synchronized_metadata( null, $source, '_private_state' );
		remove_filter( 'localepress_current_user_can_synchronize', '__return_false' );

		$allowed = $module->guard_synchronized_metadata( null, $source, 'subtitle' );

		$this->assertFalse( $blocked );
		$this->assertNull( $ignored, 'A key outside the synchronized set is not guarded.' );
		$this->assertNull( $allowed );
		$this->assertGreaterThan( 0, $target );
	}

	/**
	 * Synced patterns are translatable so each language can hold its own copy.
	 *
	 * @return void
	 */
	public function test_synced_patterns_are_translatable() {
		$support = new PostTypeSupport();

		$this->assertContains( 'wp_block', $support->get_available_post_types() );
		$this->assertTrue( $support->supports( 'wp_block' ) );
		$this->assertNotContains( 'wp_template', $support->get_available_post_types() );
	}

	/**
	 * The catalog stores one disabled synchronization key per item.
	 *
	 * @return void
	 */
	public function test_catalog_drives_stored_option_keys() {
		$defaults = WorkflowSettings::defaults();

		$this->assertArrayNotHasKey( 'copy_post_meta', $defaults, 'Copying is unconditional and has no stored key.' );
		$this->assertArrayNotHasKey( 'sync_content', $defaults, 'Content is copied once and never synchronized.' );
		$this->assertArrayHasKey( 'copy_content', $defaults, 'The programmatic copy overrides remain available.' );
		$this->assertArrayHasKey( 'copy_featured_image', $defaults );

		foreach ( SyncCatalog::items() as $item_id => $item ) {
			$key = SyncCatalog::sync_key( $item_id );

			$this->assertArrayHasKey( $key, $defaults );
			$this->assertFalse( $defaults[ $key ], "Synchronization of {$item_id} must default to disabled." );
		}
	}

	/**
	 * Creates a linked source and translation pair.
	 *
	 * @return array<int, int>
	 */
	private function create_pair() {
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Source',
			)
		);
		$target = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Translation',
			)
		);

		$this->post_ids[] = $source;
		$this->post_ids[] = $target;

		$this->assertNotWPError(
			$this->translations->link_translations(
				array(
					$this->language_ids['en'] => $source,
					$this->language_ids['de'] => $target,
				),
				$source
			)
		);

		return array( $source, $target );
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
