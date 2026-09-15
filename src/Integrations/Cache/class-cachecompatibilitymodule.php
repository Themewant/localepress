<?php
/**
 * Page cache compatibility module.
 *
 * @package LocalePress
 */

namespace LocalePress\Integrations\Cache;

use LocalePress\Content\PostTranslationManager;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\Routing\LanguageDetectionModule;
use LocalePress\Routing\LanguageUrlManager;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the plugin working on a site served through a full page cache.
 *
 * Two things a multilingual site depends on stop happening once pages are
 * cached. The language cookie is never written, because the response that
 * would have carried it is a stored copy PHP never produced — and on the one
 * request that is rendered, writing it is worse than not, since the header is
 * stored with the page and replayed to every later reader. And a cache purge
 * triggered by saving a post only knows the URLs WordPress reports for it,
 * which are the default language's.
 *
 * So the cookie moves to the browser, where it is written per reader on a
 * cached page like any other, and post type archive URLs are reported in the
 * language of the post whose cache is being cleaned.
 */
final class CacheCompatibilityModule implements ModuleInterface {

	/**
	 * Script handle the inline cookie writer is attached to.
	 */
	const SCRIPT_HANDLE = 'localepress-language-cookie';

	/**
	 * Detection module that owns the language cookie.
	 *
	 * @var LanguageDetectionModule
	 */
	private $detection;

	/**
	 * Language URL API.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Post translation API.
	 *
	 * @var PostTranslationManager
	 */
	private $posts;

	/**
	 * Language the archive links are currently reported in.
	 *
	 * @var array<string, mixed>|null
	 */
	private $archive_language = null;

	/**
	 * Whether the archive link filter is already attached.
	 *
	 * @var bool
	 */
	private $filtering_archives = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageDetectionModule $detection   Detection module.
	 * @param LanguageUrlManager      $url_manager Language URL API.
	 * @param PostTranslationManager  $posts       Post translation API.
	 */
	public function __construct(
		LanguageDetectionModule $detection,
		LanguageUrlManager $url_manager,
		PostTranslationManager $posts
	) {
		$this->detection   = $detection;
		$this->url_manager = $url_manager;
		$this->posts       = $posts;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		/*
		 * Both listeners ask whether a cache is active when they run rather
		 * than here, so a site that answers the question from a theme or a
		 * must-use plugin — a CDN leaves no constant to detect — is still
		 * heard. Until then they cost one early return each.
		 */
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_language_cookie_script' ) );
		add_action( 'clean_post_cache', array( $this, 'report_archives_in_post_language' ), 1 );
	}

	/**
	 * Writes the language cookie from the browser on a page-cached site.
	 *
	 * @return void
	 */
	public function enqueue_language_cookie_script() {
		if ( ! CacheCompatibility::is_active() || ! $this->detection->is_language_cookie_enabled() ) {
			return;
		}

		// An embed is someone else's page, and a 404 or a feed names no
		// language the visitor chose.
		if ( is_embed() || is_404() || is_feed() || is_preview() ) {
			return;
		}

		$language = $this->url_manager->get_current_language();

		if ( ! is_array( $language ) || ! isset( $language['url_slug'] ) ) {
			return;
		}

		$slug = sanitize_title( (string) $language['url_slug'] );

		if ( '' === $slug ) {
			return;
		}

		$script = $this->build_cookie_script( $slug, $this->detection->get_cookie_arguments( $language ) );

		if ( '' === $script ) {
			return;
		}

		wp_register_script( self::SCRIPT_HANDLE, '', array(), LOCALEPRESS_VERSION, true );
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_add_inline_script( self::SCRIPT_HANDLE, $script );
	}

	/**
	 * Builds the inline script that writes the cookie.
	 *
	 * The arguments are the ones PHP would have written the cookie with, so
	 * the two describe one cookie rather than two under the same name.
	 *
	 * @param string               $slug      Language URL slug.
	 * @param array<string, mixed> $arguments Cookie arguments.
	 * @return string
	 */
	private function build_cookie_script( $slug, array $arguments ) {
		$path = isset( $arguments['path'] ) ? (string) $arguments['path'] : '';

		$cookie = array(
			'name'     => LanguageDetectionModule::COOKIE_NAME,
			'value'    => $slug,
			'path'     => '' === $path ? '/' : $path,
			'domain'   => isset( $arguments['domain'] ) ? (string) $arguments['domain'] : '',
			'expires'  => isset( $arguments['expires'] ) ? (int) $arguments['expires'] : 0,
			'secure'   => ! empty( $arguments['secure'] ),
			'sameSite' => isset( $arguments['samesite'] ) ? (string) $arguments['samesite'] : 'Lax',
		);

		$encoded = wp_json_encode( $cookie );

		if ( ! is_string( $encoded ) ) {
			return '';
		}

		// An expiry of zero means a session cookie, which is the attribute
		// being absent rather than a date in the past.
		return '(function(c){'
			. 'var p=[c.name+"="+encodeURIComponent(c.value),"path="+c.path];'
			. 'if(c.domain){p.push("domain="+c.domain);}'
			. 'if(c.expires){p.push("expires="+new Date(c.expires*1000).toUTCString());}'
			. 'if(c.secure){p.push("secure");}'
			. 'if(c.sameSite){p.push("SameSite="+c.sameSite);}'
			. 'document.cookie=p.join("; ");'
			. '}(' . $encoded . '));';
	}

	/**
	 * Reports post type archive URLs in the language of the cleaned post.
	 *
	 * A cache plugin listening to this action asks WordPress which URLs the
	 * post appears on so it can drop them. Left alone, WordPress names the
	 * archive in the default language whatever language the post is in, so
	 * saving a translation purges a page it never appeared on and leaves the
	 * one it did stale.
	 *
	 * @param int $post_id Post identifier.
	 * @return void
	 */
	public function report_archives_in_post_language( $post_id ) {
		if ( ! CacheCompatibility::is_active() ) {
			return;
		}

		$language = $this->posts->get_post_language( absint( $post_id ) );

		if ( ! is_array( $language ) ) {
			return;
		}

		$this->archive_language = $language;

		/*
		 * Attached once and pointed at whichever post is being cleaned, rather
		 * than once per post: clearing a cache can walk thousands of them in a
		 * single request, and a listener added for each would still be there
		 * for all the ones after it.
		 */
		if ( ! $this->filtering_archives ) {
			$this->filtering_archives = true;
			add_filter( 'post_type_archive_link', array( $this, 'filter_archive_link' ), 99, 2 );
		}
	}

	/**
	 * Points a post type archive link at the language being cleaned.
	 *
	 * @param string $link      Archive URL.
	 * @param string $post_type Post type name.
	 * @return string
	 */
	public function filter_archive_link( $link, $post_type ) {
		/*
		 * Only while the cache is being cleaned. The filter outlives the action
		 * it was attached for, and every other archive link in the request —
		 * a menu, a widget, the admin — belongs to the current language, not to
		 * whatever post was saved.
		 */
		if ( ! doing_action( 'clean_post_cache' ) || null === $this->archive_language ) {
			return $link;
		}

		// The posts archive is the blog page, which LocalePress routes to its
		// own translation; rewriting the raw link would name a second address
		// for a page that already has one.
		if ( 'post' === $post_type || ! $this->posts->supports_post_type( $post_type ) ) {
			return $link;
		}

		return $this->url_manager->prefix_url( $link, $this->archive_language );
	}
}
