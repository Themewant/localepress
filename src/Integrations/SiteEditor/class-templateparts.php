<?php
/**
 * Block theme template part translation.
 *
 * @package LocalePress
 */

namespace LocalePress\Integrations\SiteEditor;

use LocalePress\Content\PostTranslationManager;
use LocalePress\Language\LanguageManager;
use WP_Block_Template;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the template part state that belongs to one language.
 *
 * A block theme builds every page from a template, and a template names its
 * header and footer through `core/template-part` blocks that carry a slug.
 * The slug is the only thing that decides which part renders, and nothing about
 * it belongs to a language, so a site with an English and a Bengali header has
 * no way to say which of them a Bengali page should show.
 *
 * A template part is therefore translated the way a post is: it carries a
 * language, it belongs to a translation group, and the group is what says these
 * two headers are the same header. What makes it unlike a post is that the
 * choice is made by name rather than by identifier, so the language is written
 * into the slug as well — see TemplateSlug for why, and for what that buys.
 *
 * Two things follow from a theme being allowed to ship a part as a file. A part
 * that has never been customized has no post, so there is nothing to translate
 * from and nothing to hold a language; ensure_source_post() is what turns such a
 * file into the post the group needs, which is the same thing the Site Editor
 * does the first time someone edits it. And a translation has to be published
 * rather than drafted, because WordPress renders published parts only: a drafted
 * translation would leave the location empty rather than falling back.
 */
final class TemplateParts {

	/**
	 * Post type holding customized and site-authored template parts.
	 */
	const POST_TYPE = 'wp_template_part';

	/**
	 * Taxonomy recording which theme a part belongs to.
	 */
	const THEME_TAXONOMY = 'wp_theme';

	/**
	 * Taxonomy recording which area a part fills.
	 */
	const AREA_TAXONOMY = 'wp_template_part_area';

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Parts of the active theme, keyed by slug.
	 *
	 * @var array<string, WP_Block_Template>|null
	 */
	private $parts;

	/**
	 * Part posts found one slug at a time, including the misses.
	 *
	 * @var array<string, WP_Post|null>
	 */
	private $posts = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 */
	public function __construct( LanguageManager $language_manager, PostTranslationManager $post_translations ) {
		$this->language_manager  = $language_manager;
		$this->post_translations = $post_translations;
	}

	/**
	 * Reports whether the active theme renders through the Site Editor.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

		/**
		 * Filters whether the Site Editor integration is available.
		 *
		 * A hybrid theme that registers template parts without being a block
		 * theme can turn this on, and a block theme that would rather keep one
		 * header for every language can turn it off.
		 *
		 * @param bool $available Whether the active theme is a block theme.
		 */
		return (bool) apply_filters( 'localepress_site_editor_available', $available );
	}

	/**
	 * Returns the stylesheet template parts are namespaced to.
	 *
	 * @return string
	 */
	public function theme() {
		return (string) get_stylesheet();
	}

	/**
	 * Returns the identifiers of every language the site registers.
	 *
	 * @return array<int, string>
	 */
	public function language_ids() {
		$ids = array();

		foreach ( $this->language_manager->get_languages() as $language ) {
			if ( isset( $language['id'] ) && '' !== (string) $language['id'] ) {
				$ids[] = (string) $language['id'];
			}
		}

		return $ids;
	}

	/**
	 * Returns the identifier of the site's default language.
	 *
	 * @return string
	 */
	public function default_language_id() {
		return (string) $this->language_manager->get_default_id();
	}

	/**
	 * Reads one slug's language and base name.
	 *
	 * @param string $slug Template part slug.
	 * @return TemplateSlug
	 */
	public function read_slug( $slug ) {
		return new TemplateSlug( $slug, $this->language_ids() );
	}

	/**
	 * Returns the slug a part would carry in one language.
	 *
	 * @param string $slug        Template part slug, translated or not.
	 * @param string $language_id Target language identifier.
	 * @return string
	 */
	public function slug_in_language( $slug, $language_id ) {
		return $this->read_slug( $slug )->in_language( $language_id, $this->default_language_id() );
	}

	/**
	 * Returns the template part post one slug names in the active theme.
	 *
	 * Asked one slug at a time, because the frontend asks it on every page that
	 * renders a header and listing every part means a query plus a scan of the
	 * theme's directory to answer about one of them.
	 *
	 * @param string $slug Template part slug.
	 * @return WP_Post|null
	 */
	public function find_post( $slug ) {
		$slug = $this->sanitize_slug( $slug );

		if ( '' === $slug ) {
			return null;
		}

		if ( ! array_key_exists( $slug, $this->posts ) ) {
			$query = new WP_Query(
				array(
					'post_type'                        => self::POST_TYPE,
					'post_status'                      => array( 'publish', 'draft' ),
					'post_name__in'                    => array( $slug ),
					'posts_per_page'                   => 1,
					'no_found_rows'                    => true,
					'ignore_sticky_posts'              => true,
					'lazy_load_term_meta'              => false,
					'localepress_skip_language_filter' => true,
					/*
					 * This asks about the part with exactly this name. Without the
					 * marker the resolver would widen it to the language being
					 * read, and a question about `header` would be answered with
					 * `header___bn` — which is how a rename decides the wrong part
					 * is in its way.
					 */
					'localepress_exact_part'           => true,
					'tax_query'                        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => self::THEME_TAXONOMY,
							'field'    => 'name',
							'terms'    => $this->theme(),
						),
					),
				)
			);

			$found                = $query->have_posts() ? $query->posts[0] : null;
			$this->posts[ $slug ] = $found instanceof WP_Post ? $found : null;
		}

		return $this->posts[ $slug ];
	}

	/**
	 * Returns every template part the active theme can render, keyed by slug.
	 *
	 * Both the parts the theme ships as files and the ones the site authored or
	 * customized, because either may be the header a template names.
	 *
	 * @return array<string, WP_Block_Template>
	 */
	public function get_parts() {
		if ( null !== $this->parts ) {
			return $this->parts;
		}

		$this->parts = array();

		if ( ! function_exists( 'get_block_templates' ) ) {
			return $this->parts;
		}

		foreach ( get_block_templates( array(), self::POST_TYPE ) as $part ) {
			if ( ! $part instanceof WP_Block_Template ) {
				continue;
			}

			$slug = $this->sanitize_slug( isset( $part->slug ) ? $part->slug : '' );

			if ( '' !== $slug && ! isset( $this->parts[ $slug ] ) ) {
				$this->parts[ $slug ] = $part;
			}
		}

		return $this->parts;
	}

	/**
	 * Returns the parts a template names, without the translations.
	 *
	 * A translation is not something a template names — the slug in the template
	 * stays the untranslated one — so listing it as a source would invite a site
	 * to translate a translation.
	 *
	 * @return array<string, WP_Block_Template>
	 */
	public function get_source_parts() {
		$sources = array();

		foreach ( $this->get_parts() as $slug => $part ) {
			if ( ! $this->read_slug( $slug )->has_language() ) {
				$sources[ $slug ] = $part;
			}
		}

		return $sources;
	}

	/**
	 * Returns one part of the active theme, file or post.
	 *
	 * @param string $slug Template part slug.
	 * @return WP_Block_Template|null
	 */
	public function find( $slug ) {
		$slug = $this->sanitize_slug( $slug );

		if ( '' === $slug ) {
			return null;
		}

		if ( null !== $this->parts ) {
			return isset( $this->parts[ $slug ] ) ? $this->parts[ $slug ] : null;
		}

		$template = function_exists( 'get_block_template' )
			? get_block_template( $this->theme() . '//' . $slug, self::POST_TYPE )
			: null;

		return $template instanceof WP_Block_Template ? $template : null;
	}

	/**
	 * Reports whether the active theme can render a part.
	 *
	 * @param string $slug Template part slug.
	 * @return bool
	 */
	public function exists( $slug ) {
		return null !== $this->find( $slug );
	}

	/**
	 * Returns the area a part fills.
	 *
	 * @param string $slug Template part slug.
	 * @return string Empty when the part is unknown.
	 */
	public function get_area( $slug ) {
		$part = $this->find( $slug );

		if ( null === $part ) {
			return '';
		}

		return isset( $part->area ) && is_string( $part->area ) ? (string) $part->area : '';
	}

	/**
	 * Returns the language one part belongs to.
	 *
	 * The assignment is the answer where there is one. A part the Site Editor
	 * created a moment ago may not have been given one yet, and the slug still
	 * says what it is, so the name is read as a second opinion rather than
	 * treated as nothing.
	 *
	 * @param string $slug Template part slug.
	 * @return string Empty when the part is unknown.
	 */
	public function get_language_id( $slug ) {
		$slug = $this->sanitize_slug( $slug );
		$post = $this->find_post( $slug );

		if ( $post instanceof WP_Post ) {
			$assigned = $this->post_translations->get_post_language_id( $post->ID );

			if ( '' !== $assigned ) {
				return $assigned;
			}
		}

		$read = $this->read_slug( $slug );

		if ( $read->has_language() ) {
			return $read->language();
		}

		return '' === $slug ? '' : $this->default_language_id();
	}

	/**
	 * Returns every language's version of one part, keyed by language.
	 *
	 * @param string $slug Template part slug, translated or not.
	 * @return array<string, string> Slugs by language identifier.
	 */
	public function get_translations( $slug ) {
		$base         = $this->read_slug( $slug )->base();
		$translations = array();

		if ( '' === $base ) {
			return $translations;
		}

		$post = $this->find_post( $base );

		if ( $post instanceof WP_Post ) {
			foreach ( $this->post_translations->get_translations( $post->ID ) as $language_id => $post_id ) {
				$translated = get_post( absint( $post_id ) );

				if ( $translated instanceof WP_Post && self::POST_TYPE === $translated->post_type ) {
					$translations[ (string) $language_id ] = (string) $translated->post_name;
				}
			}
		}

		/*
		 * The group answers for everything it holds, and the theme's own file
		 * answers for the default language when no post has been made for it yet.
		 * Without that a site would be told its header has no English version,
		 * which is untrue of every block theme ever shipped.
		 */
		$default = $this->default_language_id();

		if ( '' !== $default && ! isset( $translations[ $default ] ) && $this->exists( $base ) ) {
			$translations[ $default ] = $base;
		}

		foreach ( $this->language_ids() as $language_id ) {
			if ( isset( $translations[ $language_id ] ) ) {
				continue;
			}

			$candidate = $this->slug_in_language( $base, $language_id );

			if ( $candidate !== $base && $this->exists( $candidate ) ) {
				$translations[ $language_id ] = $candidate;
			}
		}

		return $translations;
	}

	/**
	 * Returns the part post a translation group should be built from.
	 *
	 * A theme's own file is not a post, so there is nothing to attach a language
	 * to and nothing for a translation to point back at. Creating the post from
	 * the file is what the Site Editor does the first time someone edits a part,
	 * and the content is copied verbatim, so the site renders exactly what it
	 * rendered before.
	 *
	 * @param string $slug Template part slug in the default language.
	 * @return int|WP_Error Post identifier.
	 */
	public function ensure_source_post( $slug ) {
		$slug = $this->sanitize_slug( $slug );
		$post = $this->find_post( $slug );

		if ( $post instanceof WP_Post ) {
			$this->assign_default_language( $post->ID );

			return $post->ID;
		}

		$part = $this->find( $slug );

		if ( null === $part ) {
			return new WP_Error(
				'localepress_part_not_found',
				__( 'That template part is not part of the active theme.', 'localepress' )
			);
		}

		$post_id = $this->insert_part(
			$slug,
			isset( $part->title ) ? (string) $part->title : $slug,
			isset( $part->content ) ? (string) $part->content : '',
			isset( $part->description ) ? (string) $part->description : '',
			isset( $part->area ) ? (string) $part->area : ''
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->assign_default_language( $post_id );

		return $post_id;
	}

	/**
	 * Creates one language's version of a template part.
	 *
	 * @param string $slug        Template part slug in the default language.
	 * @param string $language_id Target language identifier.
	 * @return int|WP_Error Created post identifier.
	 */
	public function create_translation( $slug, $language_id ) {
		$slug        = $this->sanitize_slug( $slug );
		$language_id = is_scalar( $language_id ) ? (string) $language_id : '';
		$read        = $this->read_slug( $slug );

		if ( $read->has_language() ) {
			return new WP_Error(
				'localepress_part_is_translation',
				__( 'That template part is already a translation.', 'localepress' )
			);
		}

		if ( null === $this->language_manager->find( $language_id ) ) {
			return new WP_Error(
				'localepress_language_not_found',
				__( 'The selected language is not registered.', 'localepress' )
			);
		}

		$source_id = $this->ensure_source_post( $slug );

		if ( is_wp_error( $source_id ) ) {
			return $source_id;
		}

		if ( $language_id === $this->default_language_id() ) {
			return $source_id;
		}

		$existing = absint( $this->post_translations->get_translation( $source_id, $language_id ) );

		if ( 0 !== $existing && $existing !== $source_id ) {
			return new WP_Error(
				'localepress_part_exists',
				__( 'That template part already has a version in this language.', 'localepress' )
			);
		}

		$source = get_post( $source_id );

		if ( ! $source instanceof WP_Post ) {
			return new WP_Error( 'localepress_part_not_found', __( 'The source template part could not be read.', 'localepress' ) );
		}

		$target_slug = $this->slug_in_language( $slug, $language_id );

		if ( $this->exists( $target_slug ) ) {
			return new WP_Error(
				'localepress_part_slug_taken',
				__( 'A template part with that name already exists.', 'localepress' )
			);
		}

		/**
		 * Filters the content a language's version of a template part starts from.
		 *
		 * The source part's content, so the first thing an editor sees is the
		 * header the site already has with only the words left to change.
		 *
		 * @param string  $content     Block markup the translation starts with.
		 * @param WP_Post $source      Source template part.
		 * @param string  $language_id Target language identifier.
		 */
		$content = (string) apply_filters(
			'localepress_template_part_content',
			(string) $source->post_content,
			$source,
			$language_id
		);

		$post_id = $this->insert_part(
			$target_slug,
			$this->translation_title( $source, $language_id ),
			$content,
			(string) $source->post_excerpt,
			$this->get_area( $slug )
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		/*
		 * The language the source actually holds, not the default. They are the
		 * same on every site that has not moved a part between languages, and on
		 * the one that has, linking against the default would be refused for
		 * contradicting the assignment the source already carries.
		 */
		$source_language = $this->post_translations->get_post_language_id( $source_id );

		if ( '' === $source_language ) {
			$source_language = $this->default_language_id();
		}

		$linked = $this->post_translations->link_translations(
			array(
				$source_language => $source_id,
				$language_id     => $post_id,
			),
			$source_id
		);

		if ( is_wp_error( $linked ) ) {
			wp_delete_post( $post_id, true );

			return $linked;
		}

		$this->forget();

		/**
		 * Fires after one language's version of a template part has been created.
		 *
		 * @param int    $post_id     Created template part identifier.
		 * @param int    $source_id   Template part it was created from.
		 * @param string $language_id Language it belongs to.
		 */
		do_action( 'localepress_template_part_created', $post_id, $source_id, $language_id );

		return $post_id;
	}

	/**
	 * Renames a part so its slug names the language it belongs to.
	 *
	 * @param int    $post_id     Template part identifier.
	 * @param string $language_id Language the part belongs to.
	 * @return string The slug the part now carries.
	 */
	public function rename_for_language( $post_id, $language_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return '';
		}

		$target = $this->slug_in_language( $post->post_name, $language_id );

		if ( '' === $target || $target === $post->post_name ) {
			return (string) $post->post_name;
		}

		$conflict = $this->find_post( $target );

		if ( $conflict instanceof WP_Post && $conflict->ID !== $post->ID ) {
			return (string) $post->post_name;
		}

		wp_update_post(
			array(
				'ID'        => $post->ID,
				'post_name' => $target,
			)
		);

		$this->forget();

		return $target;
	}

	/**
	 * Reports whether the Site Editor is the screen being served.
	 *
	 * @return bool
	 */
	public function is_site_editor() {
		return isset( $GLOBALS['pagenow'] ) && 'site-editor.php' === $GLOBALS['pagenow'];
	}

	/**
	 * Returns the template part slug the Site Editor was opened on.
	 *
	 * WordPress 6.8 replaced the `postType` and `postId` pair with a single `p`
	 * path, and both forms are still reached — a bookmarked address, or a link
	 * written by a plugin that has not caught up. Neither is trusted beyond its
	 * shape, and a part belonging to another theme is not this theme's to answer
	 * for.
	 *
	 * @return string Empty when the Site Editor is not on a template part.
	 */
	public function get_edited_slug() {
		if ( ! $this->is_site_editor() ) {
			return '';
		}

		$template_id = '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, and validated by shape below.
		if ( isset( $_GET['p'] ) && is_string( $_GET['p'] ) ) {
			$matches = array();

			if ( preg_match( '#^/' . self::POST_TYPE . '/(.+)$#', sanitize_text_field( wp_unslash( $_GET['p'] ) ), $matches ) ) {
				$template_id = $matches[1];
			}
		} elseif (
			isset( $_GET['postType'], $_GET['postId'] )
			&& self::POST_TYPE === sanitize_key( wp_unslash( $_GET['postType'] ) )
			&& is_string( $_GET['postId'] )
		) {
			$template_id = sanitize_text_field( wp_unslash( $_GET['postId'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$components = '' === $template_id ? array() : explode( '//', $template_id, 2 );

		if ( 2 !== count( $components ) || $components[0] !== $this->theme() ) {
			return '';
		}

		return $this->sanitize_slug( $components[1] );
	}

	/**
	 * Returns the Site Editor address of one template part.
	 *
	 * @param string $slug Template part slug.
	 * @return string Empty when the part cannot be edited.
	 */
	public function editor_url( $slug ) {
		$slug = $this->sanitize_slug( $slug );

		if ( '' === $slug || ! current_user_can( 'edit_theme_options' ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'p'      => '/' . self::POST_TYPE . '/' . rawurlencode( $this->theme() . '//' . $slug ),
				'canvas' => 'edit',
			),
			admin_url( 'site-editor.php' )
		);
	}

	/**
	 * Forgets what was read about the theme's parts.
	 *
	 * @return void
	 */
	public function forget() {
		$this->parts = null;
		$this->posts = array();
	}

	/**
	 * Returns a value usable as a template part slug.
	 *
	 * @param mixed $slug Candidate slug.
	 * @return string
	 */
	public function sanitize_slug( $slug ) {
		if ( ! is_scalar( $slug ) ) {
			return '';
		}

		$slug = sanitize_title( (string) $slug );

		return 0 === validate_file( $slug ) ? $slug : '';
	}

	/**
	 * Writes one template part post for the active theme.
	 *
	 * Published rather than drafted. WordPress renders published parts only, so a
	 * drafted translation would leave the location empty instead of falling back
	 * to the part the site already had, which is worse than not translating at
	 * all.
	 *
	 * @param string $slug        Slug to write.
	 * @param string $title       Part title.
	 * @param string $content     Block markup.
	 * @param string $description Part description.
	 * @param string $area        Area the part fills.
	 * @return int|WP_Error
	 */
	private function insert_part( $slug, $title, $content, $description, $area ) {
		/*
		 * Saving a supported post type hands it the default language, which is
		 * right for everything an editor writes and wrong for exactly this: a
		 * part being written for another language would take the default's slot
		 * before it could be linked, and the link would then be refused for
		 * disagreeing with an assignment this made a moment earlier. Both callers
		 * say which language they mean straight afterwards.
		 */
		$suppress = static function () {
			return false;
		};

		add_filter( 'localepress_auto_assign_default_post_language', $suppress, 99 );

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $description,
			),
			true
		);

		remove_filter( 'localepress_auto_assign_default_post_language', $suppress, 99 );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_post_terms( $post_id, array( $this->theme() ), self::THEME_TAXONOMY );

		if ( '' !== $area ) {
			wp_set_post_terms( $post_id, array( $area ), self::AREA_TAXONOMY );
		}

		$this->forget();

		return absint( $post_id );
	}

	/**
	 * Gives a part the default language when it holds none.
	 *
	 * @param int $post_id Template part identifier.
	 * @return void
	 */
	private function assign_default_language( $post_id ) {
		if ( '' === $this->post_translations->get_post_language_id( absint( $post_id ) ) ) {
			$this->post_translations->assign_default_language( absint( $post_id ) );
		}
	}

	/**
	 * Returns the title one language's version of a part is created with.
	 *
	 * @param WP_Post $source      Source template part.
	 * @param string  $language_id Target language identifier.
	 * @return string
	 */
	private function translation_title( WP_Post $source, $language_id ) {
		$language = $this->language_manager->find( $language_id );
		$title    = '' !== (string) $source->post_title ? (string) $source->post_title : (string) $source->post_name;
		$label    = $language_id;

		if ( is_array( $language ) && ! empty( $language['native_name'] ) ) {
			$label = (string) $language['native_name'];
		}

		return sprintf(
			/* translators: 1: template part title, 2: language name. */
			__( '%1$s (%2$s)', 'localepress' ),
			$title,
			$label
		);
	}
}
