<?php
/**
 * Post translation relationship integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Content\PostTranslationManager;
use LocalePress\Content\PostTypeSupport;
use LocalePress\Content\TranslationLifecycleModule;
use LocalePress\Infrastructure\DatabaseTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Settings\WorkflowSettings;

/**
 * Verifies Phase 2 relationship rules against WordPress posts and custom tables.
 */
class Test_LocalePress_Post_Translations extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Translation manager under test.
	 *
	 * @var PostTranslationManager
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
	 * Post IDs created by a test.
	 *
	 * @var array<int, int>
	 */
	private $post_ids = array();

	/**
	 * Prepares empty language and relationship storage.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'localepress_auto_assign_default_post_language', array( $this, 'disable_automatic_default_assignment' ) );
		$this->language_ids = array();
		$this->post_ids     = array();

		DatabaseTranslationRepository::install();
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

		$this->translations = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$this->languages,
			new PostTypeSupport()
		);
	}

	/**
	 * Removes test posts and storage.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( array_unique( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );

		if ( post_type_exists( 'localepress_book' ) ) {
			unregister_post_type( 'localepress_book' );
		}

		remove_filter( 'localepress_auto_assign_default_post_language', array( $this, 'disable_automatic_default_assignment' ) );

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
	 * A language assignment creates a singleton source group.
	 *
	 * @return void
	 */
	public function test_post_language_assignment_creates_source_group() {
		$post_id = $this->create_post();
		$result  = $this->translations->set_post_language( $post_id, $this->language_ids['en'] );

		$this->assertNotWPError( $result );
		$this->assertSame( $this->language_ids['en'], $this->translations->get_post_language_id( $post_id ) );
		$this->assertSame( $post_id, $this->translations->get_source_post_id( $post_id ) );
	}

	/**
	 * Unassigned existing content immediately uses the default language virtually.
	 *
	 * @return void
	 */
	public function test_unassigned_post_uses_default_language_without_read_time_write() {
		$post_id = $this->create_post();

		$this->assertSame( $this->language_ids['en'], $this->translations->get_post_language_id( $post_id ) );
		$this->assertSame( array( $this->language_ids['en'] => $post_id ), $this->translations->get_translations( $post_id ) );
		$this->assertSame( '', $this->translations->get_group_id( $post_id ) );
	}

	/**
	 * The global lifecycle persists the default language for newly saved content.
	 *
	 * @return void
	 */
	public function test_new_post_automatically_persists_default_language() {
		$post_id   = $this->create_post();
		$lifecycle = new TranslationLifecycleModule( $this->translations );

		remove_filter( 'localepress_auto_assign_default_post_language', array( $this, 'disable_automatic_default_assignment' ) );
		$lifecycle->assign_default_language( $post_id, get_post( $post_id ), false, null );
		add_filter( 'localepress_auto_assign_default_post_language', array( $this, 'disable_automatic_default_assignment' ) );

		$assignment = ( new DatabaseTranslationRepository() )->find_by_post( $post_id );

		$this->assertIsArray( $assignment );
		$this->assertSame( $this->language_ids['en'], $assignment['language_id'] );
	}

	/**
	 * One-click translation materializes an existing virtual default source.
	 *
	 * @return void
	 */
	public function test_translation_materializes_unassigned_default_source() {
		$source     = $this->create_post();
		$translated = $this->translations->create_translation( $source, $this->language_ids['de'] );

		$this->assertNotWPError( $translated );
		$this->post_ids[] = $translated;
		$this->assertNotSame( '', $this->translations->get_group_id( $source ) );
		$this->assertSame( $translated, $this->translations->get_translation( $source, $this->language_ids['de'] ) );
	}

	/**
	 * Three posts can share one translation group with unique languages.
	 *
	 * @return void
	 */
	public function test_three_languages_share_one_translation_group() {
		$english = $this->create_post();
		$german  = $this->create_post();
		$french  = $this->create_post();
		$result  = $this->translations->link_translations(
			array(
				$this->language_ids['en'] => $english,
				$this->language_ids['de'] => $german,
				$this->language_ids['fr'] => $french,
			),
			$english
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $this->translations->get_group_id( $english ), $this->translations->get_group_id( $german ) );
		$this->assertSame( $this->translations->get_group_id( $english ), $this->translations->get_group_id( $french ) );
		$this->assertCount( 3, $this->translations->get_translations( $english ) );
	}

	/**
	 * A group cannot contain two posts for one language.
	 *
	 * @return void
	 */
	public function test_duplicate_language_is_rejected() {
		$english = $this->create_post();
		$german  = $this->create_post();

		$this->translations->link_translations(
			array(
				$this->language_ids['en'] => $english,
				$this->language_ids['de'] => $german,
			),
			$english
		);

		$result = $this->translations->set_post_language( $german, $this->language_ids['en'] );

		$this->assertWPError( $result );
		$this->assertSame( 'duplicate_translation_language', $result->get_error_code() );
		$this->assertSame( $this->language_ids['de'], $this->translations->get_post_language_id( $german ) );
	}

	/**
	 * Existing independent groups are not merged implicitly.
	 *
	 * @return void
	 */
	public function test_conflicting_groups_are_rejected() {
		$english = $this->create_post();
		$german  = $this->create_post();

		$this->translations->set_post_language( $english, $this->language_ids['en'] );
		$this->translations->set_post_language( $german, $this->language_ids['de'] );
		$result = $this->translations->link_translations(
			array(
				$this->language_ids['en'] => $english,
				$this->language_ids['de'] => $german,
			),
			$english
		);

		$this->assertWPError( $result );
		$this->assertSame( 'conflicting_translation_groups', $result->get_error_code() );
	}

	/**
	 * Creating a translation copies only core post fields into a linked draft.
	 *
	 * @return void
	 */
	public function test_create_translation_copies_core_fields_only() {
		$source = $this->create_post(
			array(
				'post_title'   => 'Source title',
				'post_content' => 'Source content',
			)
		);

		add_post_meta( $source, '_localepress_test_private', 'do-not-copy' );
		$this->translations->set_post_language( $source, $this->language_ids['en'] );
		$translated = $this->translations->create_translation( $source, $this->language_ids['de'] );

		$this->assertNotWPError( $translated );
		$this->post_ids[] = $translated;
		$this->assertSame( 'draft', get_post_status( $translated ) );
		$this->assertSame( 'Source title', get_post_field( 'post_title', $translated ) );
		$this->assertSame( 'Source content', get_post_field( 'post_content', $translated ) );
		$this->assertSame( '', get_post_meta( $translated, '_localepress_test_private', true ) );
		$this->assertSame( $translated, $this->translations->get_translation( $source, $this->language_ids['de'] ) );
	}

	/**
	 * Gutenberg markup is copied byte-for-byte without parsing or serialization.
	 *
	 * @return void
	 */
	public function test_create_translation_preserves_common_gutenberg_blocks() {
		$block_content         = implode(
			"\n\n",
			array(
				'<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Heading</h3><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>Paragraph</p><!-- /wp:paragraph -->',
				'<!-- wp:image {"id":91} --><figure class="wp-block-image"><img src="image.jpg" alt="" class="wp-image-91"/></figure><!-- /wp:image -->',
				'<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":92} --><figure class="wp-block-image"><img src="gallery.jpg" alt="" class="wp-image-92"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->',
				'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Button</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
				'<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Column</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->',
				'<!-- wp:cover {"url":"cover.jpg","id":93} --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background wp-image-93" alt="" src="cover.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->',
				'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Item</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
				'<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>Cell</td></tr></tbody></table></figure><!-- /wp:table -->',
				'<!-- wp:query {"query":{"perPage":3,"postType":"post","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- /wp:post-template --></div><!-- /wp:query -->',
				'<!-- wp:block {"ref":123} /-->',
			)
		);
		$source                = $this->create_post(
			array(
				'post_title'   => 'Block source',
				'post_content' => $block_content,
				'post_excerpt' => 'Manual excerpt',
			)
		);
		$stored_source_content = get_post_field( 'post_content', $source );

		$this->translations->set_post_language( $source, $this->language_ids['en'] );
		$translated = $this->translations->create_translation( $source, $this->language_ids['de'] );

		$this->assertNotWPError( $translated );
		$this->post_ids[] = $translated;
		$this->assertSame( $stored_source_content, get_post_field( 'post_content', $translated ) );
		$this->assertSame( 'Manual excerpt', get_post_field( 'post_excerpt', $translated ) );
		$this->assertSame( parse_blocks( $stored_source_content ), parse_blocks( get_post_field( 'post_content', $translated ) ) );
		$this->assertStringContainsString( '<!-- wp:block {"ref":123} /-->', get_post_field( 'post_content', $translated ) );
	}

	/**
	 * Copy options can create an empty manual-translation draft.
	 *
	 * @return void
	 */
	public function test_create_translation_can_leave_content_and_excerpt_empty() {
		$source = $this->create_post(
			array(
				'post_title'   => 'Source title',
				'post_content' => '<!-- wp:paragraph --><p>Source</p><!-- /wp:paragraph -->',
				'post_excerpt' => 'Source excerpt',
			)
		);

		$this->translations->set_post_language( $source, $this->language_ids['en'] );
		$translated = $this->translations->create_translation(
			$source,
			$this->language_ids['de'],
			array( 'copy_content' => false )
		);

		$this->assertNotWPError( $translated );
		$this->post_ids[] = $translated;
		$this->assertSame( 'Source title', get_post_field( 'post_title', $translated ) );
		$this->assertSame( '', get_post_field( 'post_content', $translated ) );
		$this->assertSame( '', get_post_field( 'post_excerpt', $translated ) );
	}

	/**
	 * Featured-image sharing is explicit and never copies unrelated metadata.
	 *
	 * @return void
	 */
	public function test_featured_image_copy_behavior_is_configurable() {
		$attachment       = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$source           = $this->create_post();
		$this->post_ids[] = $attachment;
		set_post_thumbnail( $source, $attachment );
		add_post_meta( $source, '_localepress_unrelated_meta', 'not-copied' );

		$this->translations->set_post_language( $source, $this->language_ids['en'] );
		$german = $this->translations->create_translation( $source, $this->language_ids['de'] );
		$french = $this->translations->create_translation(
			$source,
			$this->language_ids['fr'],
			array( 'copy_featured_image' => false )
		);

		$this->assertNotWPError( $german );
		$this->assertNotWPError( $french );
		$this->post_ids[] = $german;
		$this->post_ids[] = $french;
		$this->assertSame( $attachment, get_post_thumbnail_id( $german ) );
		$this->assertSame( 0, get_post_thumbnail_id( $french ) );
		$this->assertSame( '', get_post_meta( $german, '_localepress_unrelated_meta', true ) );
	}

	/**
	 * Trash preserves relationships and source deletion selects a surviving member.
	 *
	 * @return void
	 */
	public function test_trash_and_delete_preserve_group_integrity() {
		$source = $this->create_post();

		$this->translations->set_post_language( $source, $this->language_ids['en'] );
		$translated = $this->translations->create_translation( $source, $this->language_ids['de'] );
		$this->assertNotWPError( $translated );
		$this->post_ids[] = $translated;

		wp_trash_post( $translated );
		$this->assertSame( $translated, $this->translations->get_translation( $source, $this->language_ids['de'] ) );

		wp_untrash_post( $translated );
		$this->translations->remove_post( $source );
		$this->assertSame( $translated, $this->translations->get_source_post_id( $translated ) );
	}

	/**
	 * A replacement translation releases the slot without taking the language.
	 *
	 * Restoring the old post runs wp_update_post(), which is where an assignment
	 * row that no longer exists used to be recreated with the default language.
	 *
	 * @return void
	 */
	public function test_released_translation_keeps_its_language_after_restore() {
		$source = $this->create_post();
		$this->translations->set_post_language( $source, $this->language_ids['en'] );

		$original = $this->translations->create_translation( $source, $this->language_ids['de'] );
		$this->assertNotWPError( $original );
		$this->post_ids[] = $original;

		$group_id = $this->translations->get_group_id( $source );

		wp_trash_post( $original );

		$replacement = $this->translations->create_translation( $source, $this->language_ids['de'] );
		$this->assertNotWPError( $replacement );
		$this->post_ids[] = $replacement;

		// The replacement holds the slot; the trashed post keeps German on its own.
		$this->assertSame( $replacement, $this->translations->get_translation( $source, $this->language_ids['de'] ) );
		$this->assertSame( $this->language_ids['de'], $this->translations->get_post_language_id( $original ) );
		$this->assertNotSame( $group_id, $this->translations->get_group_id( $original ) );
		$this->assertNotSame( '', $this->translations->get_group_id( $original ) );

		$lifecycle = new TranslationLifecycleModule( $this->translations );
		$lifecycle->register();
		remove_filter( 'localepress_auto_assign_default_post_language', array( $this, 'disable_automatic_default_assignment' ) );

		wp_untrash_post( $original );

		add_filter( 'localepress_auto_assign_default_post_language', array( $this, 'disable_automatic_default_assignment' ) );

		$this->assertSame( $this->language_ids['de'], $this->translations->get_post_language_id( $original ) );
		$this->assertSame( $original, $this->translations->get_source_post_id( $original ) );
		$this->assertSame( $replacement, $this->translations->get_translation( $source, $this->language_ids['de'] ) );
	}

	/**
	 * Languages assigned to content cannot be deleted.
	 *
	 * @return void
	 */
	public function test_assigned_language_cannot_be_deleted() {
		$post_id = $this->create_post();

		// A non-default language, because the default is refused before the
		// in-use check is ever reached.
		$this->translations->set_post_language( $post_id, $this->language_ids['fr'] );
		$result = $this->languages->delete( $this->language_ids['fr'] );

		$this->assertWPError( $result );
		$this->assertSame( 'language_in_use', $result->get_error_code() );
	}

	/**
	 * Public custom post types receive the generic translation architecture.
	 *
	 * @return void
	 */
	public function test_public_custom_post_type_is_supported() {
		register_post_type(
			'localepress_book',
			array(
				'public'  => true,
				'show_ui' => true,
			)
		);

		$this->assertTrue( $this->translations->supports_post_type( 'localepress_book' ) );
	}

	/**
	 * The integration helper resolves an object ID into the requested language.
	 *
	 * @return void
	 */
	public function test_object_id_resolves_a_post_into_another_language() {
		$source = $this->create_post();
		$this->translations->set_post_language( $source, $this->language_ids['en'] );
		$german = $this->translations->create_translation( $source, $this->language_ids['de'] );

		$this->assertNotWPError( $german );
		$this->post_ids[] = $german;

		$this->assertSame( $german, localepress_object_id( $source, 'post', true, 'de' ) );
		$this->assertSame( $source, localepress_object_id( $german, 'post', true, 'en' ) );
	}

	/**
	 * An object already in the requested language resolves to itself.
	 *
	 * @return void
	 */
	public function test_object_id_returns_the_same_post_for_its_own_language() {
		$source = $this->create_post();
		$this->translations->set_post_language( $source, $this->language_ids['en'] );

		$this->assertSame( $source, localepress_object_id( $source, 'post', true, 'en' ) );
	}

	/**
	 * A builder keeps rendering the original template when nothing is translated.
	 *
	 * @return void
	 */
	public function test_object_id_falls_back_to_the_original_when_untranslated() {
		$source = $this->create_post();
		$this->translations->set_post_language( $source, $this->language_ids['en'] );

		$this->assertSame( $source, localepress_object_id( $source, 'post', true, 'fr' ) );
		$this->assertNull( localepress_object_id( $source, 'post', false, 'fr' ) );
		$this->assertSame( 0, localepress_object_id( 0, 'post', true, 'fr' ) );
	}

	/**
	 * Creates and tracks a draft page.
	 *
	 * @param array<string, mixed> $overrides Post field overrides.
	 * @return int
	 */
	private function create_post( $overrides = array() ) {
		$post_id = self::factory()->post->create(
			wp_parse_args(
				$overrides,
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
				)
			)
		);

		$this->post_ids[] = $post_id;

		return $post_id;
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
