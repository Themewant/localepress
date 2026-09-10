<?php
/**
 * Media translation integration tests.
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
use LocalePress\Media\MediaModule;
use LocalePress\Media\MediaTranslationManager;
use LocalePress\Settings\PluginSettings;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\Sync\TranslationSynchronizer;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Verifies that a media translation shares one file and its own text.
 */
class Test_LocalePress_Media extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Post translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $translations;

	/**
	 * Media translation service under test.
	 *
	 * @var MediaTranslationManager
	 */
	private $media;

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
	 * Prepares isolated language, relationship, and settings storage.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->language_ids = array();
		$this->post_ids     = array();

		DatabaseTranslationRepository::install();
		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		OptionsLanguageRepository::install();
		PluginSettings::install();
		WorkflowSettings::install();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

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

		$this->build_services();
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

		$this->clear_relationship_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_option( PluginSettings::OPTION_NAME );
		delete_option( WorkflowSettings::OPTION_NAME );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Media stays untranslatable until the setting is enabled.
	 *
	 * @return void
	 */
	public function test_media_support_is_opt_in() {
		$this->assertFalse( $this->translations->supports_post_type( 'attachment' ) );
		$this->assertFalse( $this->media->is_enabled() );

		$this->enable_media_support();

		$this->assertTrue( $this->translations->supports_post_type( 'attachment' ) );
		$this->assertTrue( $this->media->is_enabled() );
	}

	/**
	 * Media is governed by its own switch, not by the post type checkboxes.
	 *
	 * @return void
	 */
	public function test_attachment_never_appears_in_the_post_type_list() {
		$this->enable_media_support();
		$support = new PostTypeSupport();

		$this->assertNotContains( 'attachment', $support->get_available_post_types() );
		$this->assertContains( 'attachment', $support->get_post_types() );
	}

	/**
	 * A media translation is a second record pointing at the same file.
	 *
	 * @return void
	 */
	public function test_translation_shares_the_source_file() {
		$this->enable_media_support();
		$source      = $this->create_attachment();
		$translation = $this->translations->create_translation( $source, $this->language_ids['bn'] );

		$this->assertNotWPError( $translation );
		$this->post_ids[] = $translation;

		$this->assertNotSame( $source, $translation, 'Each language needs its own record.' );
		$this->assertSame(
			get_post_meta( $source, '_wp_attached_file', true ),
			get_post_meta( $translation, '_wp_attached_file', true ),
			'Both records must point at one file.'
		);
		$this->assertSame( get_attached_file( $source ), get_attached_file( $translation ) );
		$this->assertFileExists( get_attached_file( $translation ), 'The shared file must exist for both records.' );
		$this->assertSame(
			wp_get_attachment_metadata( $source, true ),
			wp_get_attachment_metadata( $translation, true )
		);
		$this->assertSame( 'attachment', get_post_type( $translation ) );
		$this->assertSame( 'inherit', get_post_status( $translation ) );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $translation ) );
	}

	/**
	 * Deleting one language leaves the shared file for the others.
	 *
	 * @return void
	 */
	public function test_deleting_one_language_keeps_the_shared_file() {
		$this->enable_media_support();
		$source      = $this->create_attachment();
		$translation = $this->translations->create_translation( $source, $this->language_ids['bn'] );

		$this->assertNotWPError( $translation );
		$this->post_ids[] = $translation;

		$file = get_attached_file( $source );

		$this->assertFileExists( $file );

		wp_delete_attachment( $translation, true );

		$this->assertNull( get_post( $translation ), 'The translated record is removed.' );
		$this->assertFileExists( $file, 'The file still belongs to the remaining language.' );
		$this->assertSame( '', $this->translations->get_post_language_id( $translation ) );
		$this->assertSame(
			array( $this->language_ids['en'] => $source ),
			$this->translations->get_translations( $source ),
			'The deleted language leaves the translation group.'
		);
	}

	/**
	 * Deleting the last remaining record removes the file as usual.
	 *
	 * @return void
	 */
	public function test_deleting_the_last_record_removes_the_file() {
		$this->enable_media_support();
		$source = $this->create_attachment();
		$file   = get_attached_file( $source );

		$this->assertFileExists( $file );

		wp_delete_attachment( $source, true );

		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * Editorial text starts from the source and then diverges per language.
	 *
	 * @return void
	 */
	public function test_media_text_can_differ_per_language() {
		$this->enable_media_support();
		$source      = $this->create_attachment();
		$translation = $this->translations->create_translation( $source, $this->language_ids['bn'] );

		$this->assertNotWPError( $translation );
		$this->post_ids[] = $translation;

		$this->assertSame( 'Hotel Room', get_post_field( 'post_title', $translation ) );
		$this->assertSame(
			'Beautiful hotel room',
			get_post_meta( $translation, MediaTranslationManager::ALT_META_KEY, true )
		);

		wp_update_post(
			array(
				'ID'           => $translation,
				'post_title'   => 'হোটেল রুম',
				'post_excerpt' => 'ডিলাক্স হোটেল রুম',
			)
		);
		update_post_meta( $translation, MediaTranslationManager::ALT_META_KEY, 'সুন্দর হোটেল রুম' );

		$this->assertSame( 'হোটেল রুম', get_post_field( 'post_title', $translation ) );
		$this->assertSame( 'ডিলাক্স হোটেল রুম', get_post_field( 'post_excerpt', $translation ) );
		$this->assertSame( 'Hotel Room', get_post_field( 'post_title', $source ), 'The source must be untouched.' );
		$this->assertSame(
			'Beautiful hotel room',
			get_post_meta( $source, MediaTranslationManager::ALT_META_KEY, true )
		);
		$this->assertSame(
			get_post_meta( $source, '_wp_attached_file', true ),
			get_post_meta( $translation, '_wp_attached_file', true ),
			'Diverging text must not disturb the shared file.'
		);
	}

	/**
	 * An attachment resolves to its translation, or to itself when there is none.
	 *
	 * @return void
	 */
	public function test_attachment_ids_resolve_to_their_translation() {
		$this->enable_media_support();
		$source      = $this->create_attachment();
		$translation = $this->translations->create_translation( $source, $this->language_ids['bn'] );

		$this->assertNotWPError( $translation );
		$this->post_ids[] = $translation;

		$untranslated = $this->create_attachment( 'Lobby' );

		$this->assertSame( $translation, $this->media->translate_attachment_id( $source, $this->language_ids['bn'] ) );
		$this->assertSame( $source, $this->media->translate_attachment_id( $source, $this->language_ids['en'] ) );
		$this->assertSame(
			$untranslated,
			$this->media->translate_attachment_id( $untranslated, $this->language_ids['bn'] ),
			'An untranslated attachment keeps its own identifier.'
		);
	}

	/**
	 * A translated post takes the featured image's own translation.
	 *
	 * @return void
	 */
	public function test_featured_image_uses_the_translated_attachment() {
		$this->enable_media_support();
		$image       = $this->create_attachment();
		$image_bn    = $this->translations->create_translation( $image, $this->language_ids['bn'] );
		$this->assertNotWPError( $image_bn );
		$this->post_ids[] = $image_bn;

		$post = self::factory()->post->create( array( 'post_title' => 'Hotel' ) );
		$this->post_ids[] = $post;
		set_post_thumbnail( $post, $image );
		$this->translations->set_post_language( $post, $this->language_ids['en'] );

		$post_bn = $this->translations->create_translation( $post, $this->language_ids['bn'] );

		$this->assertNotWPError( $post_bn );
		$this->post_ids[] = $post_bn;

		$this->assertSame( $image_bn, get_post_thumbnail_id( $post_bn ) );
		$this->assertSame( $image, get_post_thumbnail_id( $post ) );
	}

	/**
	 * Without media support a translated post keeps the shared attachment.
	 *
	 * @return void
	 */
	public function test_featured_image_is_shared_when_media_support_is_off() {
		$image = $this->create_attachment();
		$post  = self::factory()->post->create( array( 'post_title' => 'Hotel' ) );

		$this->post_ids[] = $post;
		set_post_thumbnail( $post, $image );
		$this->translations->set_post_language( $post, $this->language_ids['en'] );

		$post_bn = $this->translations->create_translation( $post, $this->language_ids['bn'] );

		$this->assertNotWPError( $post_bn );
		$this->post_ids[] = $post_bn;

		$this->assertSame( $image, get_post_thumbnail_id( $post_bn ) );
	}

	/**
	 * The keys describing the shared file follow every language record.
	 *
	 * @return void
	 */
	public function test_shared_file_meta_keys_reach_every_language() {
		$this->enable_media_support();
		$source      = $this->create_attachment();
		$translation = $this->translations->create_translation( $source, $this->language_ids['bn'] );

		$this->assertNotWPError( $translation );
		$this->post_ids[] = $translation;

		$synchronizer = new TranslationSynchronizer(
			$this->translations,
			new TermTranslationManager(
				new DatabaseTermTranslationRepository(),
				$this->languages,
				new TaxonomySupport()
			),
			new WorkflowSettings(),
			new TaxonomySupport()
		);

		$media_keys = $synchronizer->get_meta_keys( $source, $translation, $this->language_ids['bn'], true );
		$post_keys  = $synchronizer->get_meta_keys(
			self::factory()->post->create(),
			self::factory()->post->create(),
			$this->language_ids['bn'],
			true
		);

		foreach ( array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ) as $key ) {
			$this->assertContains( $key, $media_keys );
			$this->assertNotContains( $key, $post_keys, 'Only attachments carry the shared file keys.' );
		}
	}

	/**
	 * Enables media translation and rebuilds services reading that setting.
	 *
	 * @return void
	 */
	private function enable_media_support() {
		$settings = new PluginSettings();
		$settings->update_sections( array( 'content' => array( 'media_support' => true ) ) );

		$this->build_services();

		( new MediaModule( $this->media, $this->translations ) )->register();
	}

	/**
	 * Builds services from the currently stored settings.
	 *
	 * @return void
	 */
	private function build_services() {
		$this->translations = new PostTranslationManager(
			new DatabaseTranslationRepository(),
			$this->languages,
			new PostTypeSupport()
		);
		$this->media        = new MediaTranslationManager( $this->translations );
	}

	/**
	 * Creates an attachment fixture that points at a shared relative path.
	 *
	 * @param string $title Attachment title.
	 * @return int
	 */
	private function create_attachment( $title = 'Hotel Room' ) {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$this->post_ids[] = $attachment_id;

		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_title'   => $title,
				'post_excerpt' => 'Deluxe hotel room',
				'post_content' => 'A comfortable room with modern facilities',
			)
		);
		update_post_meta( $attachment_id, MediaTranslationManager::ALT_META_KEY, 'Beautiful hotel room' );

		return $attachment_id;
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
			) as $table
		) {
			// Table names are generated exclusively by LocalePress repositories.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table}" );
		}
	}
}
