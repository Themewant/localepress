<?php
/**
 * Keeps template part slugs and translation groups consistent.
 *
 * @package LocalePress
 */

namespace LocalePress\Integrations\SiteEditor;

use LocalePress\Content\PostTranslationManager;
use LocalePress\Contracts\ModuleInterface;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the two rules the rest of the integration depends on.
 *
 * Writing the language into the slug buys a fallback in one query, and the price
 * is that the slug has to stay true. Three things can make it false, and each of
 * them is answered here.
 *
 * A part can be given a language, or be created already carrying one: the Site
 * Editor lets someone duplicate a header and name it anything. A part in the
 * default language must then lose any suffix, and a part in another language
 * must gain one, or the query that looks for `header___bn` finds nothing.
 *
 * The default language can change. Every part then has to be renamed at once,
 * because the unsuffixed name belongs to whichever language is default, and the
 * language that was default has to take a suffix it never had.
 *
 * And a part can be deleted. Deleting the one every other language falls back to
 * leaves translations pointing at a part that is gone, so the group goes with it.
 */
final class TemplatePartLifecycle implements ModuleInterface {

	/**
	 * Template part translation service.
	 *
	 * @var TemplateParts
	 */
	private $parts;

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Whether a rename is already running.
	 *
	 * @var bool
	 */
	private $renaming = false;

	/**
	 * Constructor.
	 *
	 * @param TemplateParts          $parts             Template part translation service.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 */
	public function __construct(
		TemplateParts $parts,
		PostTranslationManager $post_translations
	) {
		$this->parts             = $parts;
		$this->post_translations = $post_translations;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->parts->is_available() ) {
			return;
		}

		add_action( 'localepress_post_language_changed', array( $this, 'rename_on_language_change' ), 10, 2 );
		add_action( 'localepress_default_language_changed', array( $this, 'rename_every_part' ), 10, 2 );

		// Before the translation engine forgets the group at priority 10.
		add_action( 'before_delete_post', array( $this, 'delete_translations' ), 8, 2 );

		add_action( 'wp_after_insert_post', array( $this, 'name_new_part' ), 25, 2 );
	}

	/**
	 * Renames a part when the language it belongs to changes.
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $language_id New language identifier.
	 * @return void
	 */
	public function rename_on_language_change( $post_id, $language_id ) {
		if ( $this->renaming || ! $this->is_part( $post_id ) ) {
			return;
		}

		$this->renaming = true;
		$this->parts->rename_for_language( $post_id, $language_id );
		$this->renaming = false;
	}

	/**
	 * Gives a part created in the editor the name its language calls for.
	 *
	 * The Site Editor writes parts through the REST API and names them after
	 * their title, so a part duplicated into another language arrives with a name
	 * that says nothing about language. The assignment is the truth; the slug is
	 * made to agree with it.
	 *
	 * @param int   $post_id Post identifier.
	 * @param mixed $post    Post object.
	 * @return void
	 */
	public function name_new_part( $post_id, $post ) {
		if ( $this->renaming || ! $post instanceof WP_Post || TemplateParts::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$language_id = $this->post_translations->get_post_language_id( $post_id );

		if ( '' === $language_id ) {
			return;
		}

		$this->renaming = true;
		$this->parts->rename_for_language( $post_id, $language_id );
		$this->renaming = false;
	}

	/**
	 * Renames every template part after the default language changes.
	 *
	 * @param string $new_default New default language identifier.
	 * @param string $old_default Previous default language identifier.
	 * @return void
	 */
	public function rename_every_part( $new_default, $old_default ) {
		unset( $new_default, $old_default );

		if ( $this->renaming ) {
			return;
		}

		$this->renaming = true;
		$this->parts->forget();

		/*
		 * The language that is becoming default is renamed first, so its suffix is
		 * released before the part that has to take the unsuffixed name asks for
		 * it. Renaming the other way round would find the name taken and give up.
		 */
		$posts = $this->all_part_posts();

		foreach ( $posts as $post ) {
			$language_id = $this->post_translations->get_post_language_id( $post->ID );

			if ( '' !== $language_id && $language_id === $this->parts->default_language_id() ) {
				continue;
			}

			$this->parts->rename_for_language( $post->ID, $language_id );
		}

		foreach ( $posts as $post ) {
			$language_id = $this->post_translations->get_post_language_id( $post->ID );

			if ( '' !== $language_id && $language_id === $this->parts->default_language_id() ) {
				$this->parts->rename_for_language( $post->ID, $language_id );
			}
		}

		$this->renaming = false;
		$this->parts->forget();
	}

	/**
	 * Deletes the translations of a part in the default language.
	 *
	 * Only from the default language down. A translation is what a site made on
	 * purpose and may delete on purpose, and deleting it leaves the rest of the
	 * group renderable. The part every language falls back to is the one that
	 * cannot go on its own.
	 *
	 * @param int   $post_id Post identifier.
	 * @param mixed $post    Post object.
	 * @return void
	 */
	public function delete_translations( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || TemplateParts::POST_TYPE !== $post->post_type ) {
			return;
		}

		$language_id = $this->post_translations->get_post_language_id( $post_id );

		if ( '' === $language_id || $language_id !== $this->parts->default_language_id() ) {
			return;
		}

		/**
		 * Filters whether deleting a template part deletes its translations.
		 *
		 * @param bool $cascade Whether translations are deleted too.
		 * @param int  $post_id Template part being deleted.
		 */
		if ( ! apply_filters( 'localepress_delete_template_part_translations', true, absint( $post_id ) ) ) {
			return;
		}

		foreach ( $this->post_translations->get_translations( $post_id ) as $translated_id ) {
			$translated_id = absint( $translated_id );

			if ( 0 !== $translated_id && $translated_id !== absint( $post_id ) ) {
				wp_delete_post( $translated_id, true );
			}
		}

		$this->parts->forget();
	}

	/**
	 * Returns every template part post of the active theme.
	 *
	 * @return array<int, WP_Post>
	 */
	private function all_part_posts() {
		$posts = get_posts(
			array(
				'post_type'                        => TemplateParts::POST_TYPE,
				'post_status'                      => array( 'publish', 'draft' ),
				'posts_per_page'                   => 200,
				'no_found_rows'                    => true,
				'localepress_skip_language_filter' => true,
				'tax_query'                        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => TemplateParts::THEME_TAXONOMY,
						'field'    => 'name',
						'terms'    => $this->parts->theme(),
					),
				),
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Reports whether a post is a template part.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_part( $post_id ) {
		return TemplateParts::POST_TYPE === get_post_type( absint( $post_id ) );
	}
}
