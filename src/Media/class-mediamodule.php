<?php
/**
 * Media translation module.
 *
 * @package LocalePress
 */

namespace LocalePress\Media;

use LocalePress\Content\PostTranslationManager;
use LocalePress\Contracts\ModuleInterface;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Connects media translation to translation creation and attachment resolution.
 */
final class MediaModule implements ModuleInterface {

	/**
	 * Media translation service.
	 *
	 * @var MediaTranslationManager
	 */
	private $media;

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Absolute paths that must survive the attachment being deleted.
	 *
	 * @var array<int, string>
	 */
	private $protected_files = array();

	/**
	 * Constructor.
	 *
	 * @param MediaTranslationManager $media             Media translation service.
	 * @param PostTranslationManager  $post_translations Post translation manager.
	 */
	public function __construct( MediaTranslationManager $media, PostTranslationManager $post_translations ) {
		$this->media             = $media;
		$this->post_translations = $post_translations;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'localepress_insert_translation', array( $this, 'insert_media_translation' ), 10, 3 );
		add_filter( 'localepress_translation_featured_image_id', array( $this, 'translate_featured_image_id' ), 10, 2 );
		add_filter( 'localepress_translate_post_meta_value', array( $this, 'translate_thumbnail_meta_value' ), 10, 3 );
		add_filter( 'localepress_copy_post_meta_keys', array( $this, 'add_shared_file_meta_keys' ), 10, 4 );
		add_action( 'delete_attachment', array( $this, 'protect_shared_files' ), 5 );
		add_filter( 'wp_delete_file', array( $this, 'skip_protected_file' ), 5 );
	}

	/**
	 * Keeps every language's record describing the same file the same way.
	 *
	 * These keys are structural rather than editorial: they record where the file
	 * lives and which sizes exist. Editing an image in one language rewrites them,
	 * so all of that file's records have to follow, independently of which
	 * editorial items a site chose to synchronize.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param bool               $sync      True for synchronization, false for the one-time copy.
	 * @param int                $source_id Source post identifier.
	 * @param int                $target_id Target post identifier.
	 * @return array<int, string>
	 */
	public function add_shared_file_meta_keys( $keys, $sync, $source_id, $target_id ) {
		unset( $sync, $target_id );

		if ( ! is_array( $keys ) || ! $this->media->is_enabled() || 'attachment' !== get_post_type( absint( $source_id ) ) ) {
			return $keys;
		}

		return array_merge(
			$keys,
			array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' )
		);
	}

	/**
	 * Remembers the files a deleted media translation must not remove.
	 *
	 * Translations share one file, so deleting a language's record must leave the
	 * file itself in place for the other languages. WordPress deletes attachment
	 * files after the post row is gone, so the paths are collected here, while the
	 * relationship and the metadata are still readable.
	 *
	 * @param int $post_id Attachment being deleted.
	 * @return void
	 */
	public function protect_shared_files( $post_id ) {
		$this->protected_files = array();
		$post_id               = absint( $post_id );

		if ( ! $this->media->is_enabled() || ! $this->has_other_translations( $post_id ) ) {
			return;
		}

		$file = get_attached_file( $post_id );

		if ( ! is_string( $file ) || '' === $file ) {
			return;
		}

		$file      = wp_normalize_path( $file );
		$directory = trailingslashit( dirname( $file ) );
		$paths     = array( $file );
		$metadata  = wp_get_attachment_metadata( $post_id );
		$backup    = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );

		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$paths[] = $directory . $metadata['original_image'];
		}

		$size_sets = array(
			is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array(),
			is_array( $backup ) ? $backup : array(),
		);

		foreach ( $size_sets as $sizes ) {
			foreach ( $sizes as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$paths[] = $directory . $size['file'];
				}
			}
		}

		$this->protected_files = array_values( array_unique( $paths ) );
	}

	/**
	 * Skips deleting a file another language still uses.
	 *
	 * Each protected path is consumed once, so protection ends with the deletion
	 * that requested it and never affects unrelated file operations.
	 *
	 * @param string $file Absolute path WordPress intends to delete.
	 * @return string Empty to skip the deletion, or the unmodified path.
	 */
	public function skip_protected_file( $file ) {
		if ( empty( $this->protected_files ) || ! is_string( $file ) ) {
			return $file;
		}

		$index = array_search( wp_normalize_path( $file ), $this->protected_files, true );

		if ( false === $index ) {
			return $file;
		}

		unset( $this->protected_files[ $index ] );

		return '';
	}

	/**
	 * Reports whether an attachment has at least one other language.
	 *
	 * @param int $post_id Attachment identifier.
	 * @return bool
	 */
	private function has_other_translations( $post_id ) {
		foreach ( $this->post_translations->get_translations( $post_id ) as $translation_id ) {
			if ( absint( $translation_id ) !== $post_id && 0 < absint( $translation_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates an attachment translation instead of a standard draft.
	 *
	 * @param int|\WP_Error|null   $translation_id Inserted identifier, if another handler produced one.
	 * @param array<string, mixed> $post_data      Prepared post fields.
	 * @param WP_Post              $source         Source post.
	 * @return int|\WP_Error|null
	 */
	public function insert_media_translation( $translation_id, $post_data, $source ) {
		if (
			null !== $translation_id
			|| ! $source instanceof WP_Post
			|| 'attachment' !== $source->post_type
			|| ! is_array( $post_data )
			|| ! $this->media->is_enabled()
		) {
			return $translation_id;
		}

		return $this->media->insert_translation( $post_data, $source );
	}

	/**
	 * Points a translated post at the featured image's own translation.
	 *
	 * @param int                  $thumbnail_id Source attachment identifier.
	 * @param array<string, mixed> $language     Target language record.
	 * @return int
	 */
	public function translate_featured_image_id( $thumbnail_id, $language ) {
		$language_id = is_array( $language ) && isset( $language['id'] ) && is_scalar( $language['id'] )
			? (string) $language['id']
			: '';

		return $this->media->translate_attachment_id( $thumbnail_id, $language_id );
	}

	/**
	 * Points a synchronized featured image at the translation's own attachment.
	 *
	 * @param mixed  $value       Stored meta value.
	 * @param string $meta_key    Meta key.
	 * @param string $language_id Target language identifier.
	 * @return mixed
	 */
	public function translate_thumbnail_meta_value( $value, $meta_key, $language_id ) {
		if ( '_thumbnail_id' !== $meta_key ) {
			return $value;
		}

		return $this->media->translate_attachment_id( $value, (string) $language_id );
	}
}
