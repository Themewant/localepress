<?php
/**
 * Frontend language routing module.
 *
 * @package LocalePress
 */

namespace LocalePress\Routing;

use LocalePress\Content\LanguageQueryConstraint;
use LocalePress\Content\PostTranslationManager;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\SEO\SitemapModule;
use LocalePress\Taxonomy\TermTranslationManager;
use WP_Post;
use WP_Query;
use WP_Tax_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates language prefixes with WordPress rewrites and frontend queries.
 */
final class RoutingModule implements ModuleInterface {

	/**
	 * Stored rewrite signature option.
	 *
	 * @var string
	 */
	const REWRITE_SIGNATURE_OPTION = 'localepress_rewrite_signature';

	/**
	 * Routing schema version.
	 *
	 * @var string
	 */
	const REWRITE_SCHEMA_VERSION = '1';

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Post translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Language selected by the parsed rewrite for this request.
	 *
	 * @var string
	 */
	private $request_language_id = '';

	/**
	 * Nesting depth of blocks that run their own post queries.
	 *
	 * @var int
	 */
	private $block_query_depth = 0;

	/**
	 * Shared language query constraint.
	 *
	 * @var LanguageQueryConstraint
	 */
	private $constraint;

	/**
	 * Resolved block names that run their own post query.
	 *
	 * @var array<int, string>|null
	 */
	private $post_query_block_names = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 */
	public function __construct(
		LanguageUrlManager $url_manager,
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations
	) {
		$this->url_manager       = $url_manager;
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
		$this->constraint        = new LanguageQueryConstraint();
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->url_manager->is_frontend_routing_enabled() ) {
			if ( false !== get_option( self::REWRITE_SIGNATURE_OPTION, false ) ) {
				delete_option( self::REWRITE_SIGNATURE_OPTION );
				add_action( 'init', array( $this, 'flush_disabled_rewrite_rules' ), 99 );
			}

			return;
		}

		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_filter( 'rewrite_rules_array', array( $this, 'add_language_rewrite_rules' ), 20 );
		add_filter( 'localepress_current_language_id', array( $this->url_manager, 'filter_current_language_id' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_language_hosts' ) );
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 20, 2 );
		add_action( 'parse_request', array( $this, 'apply_request_language' ), 5 );
		add_action( 'parse_request', array( $this, 'map_translated_request' ), 20 );
		add_filter( 'option_page_on_front', array( $this, 'filter_front_page_option' ) );
		add_filter( 'option_page_for_posts', array( $this, 'filter_posts_page_option' ) );
		add_action( 'pre_get_posts', array( $this, 'map_front_page' ), 1 );
		add_action( 'pre_get_posts', array( $this, 'scope_suppressed_query' ), 5 );
		add_filter( 'posts_clauses', array( $this, 'filter_posts_by_language' ), 10, 2 );
		add_filter( 'terms_clauses', array( $this, 'filter_terms_by_language' ), 10, 3 );
		add_filter( 'query_loop_block_query_vars', array( $this, 'mark_query_loop_block_vars' ), 20 );
		add_filter( 'render_block_data', array( $this, 'open_block_query_scope' ) );
		add_filter( 'render_block', array( $this, 'close_block_query_scope' ), 10, 2 );
		add_filter( 'the_posts', array( $this, 'prime_post_translation_cache' ), 10, 2 );
		add_filter( 'get_terms', array( $this, 'prime_term_translation_cache' ), 10, 4 );
		add_action( 'template_redirect', array( $this, 'redirect_unprefixed_request' ), 1 );
		add_action( 'template_redirect', array( $this, 'redirect_noncanonical_object_route' ), 2 );
		add_filter( 'redirect_canonical', array( $this, 'preserve_canonical_language' ), 10, 2 );

		add_filter( 'post_link', array( $this->url_manager, 'filter_post_permalink' ), 10, 2 );
		add_filter( 'post_type_link', array( $this->url_manager, 'filter_post_permalink' ), 10, 2 );
		add_filter( 'page_link', array( $this->url_manager, 'filter_page_permalink' ), 10, 2 );
		add_filter( 'preview_post_link', array( $this->url_manager, 'filter_preview_url' ), 10, 2 );
		add_filter( 'term_link', array( $this->url_manager, 'filter_term_permalink' ), 10, 3 );
		add_filter( 'post_type_archive_link', array( $this->url_manager, 'filter_current_url' ) );
		add_filter( 'author_link', array( $this->url_manager, 'filter_current_url' ) );
		add_filter( 'year_link', array( $this->url_manager, 'filter_current_url' ) );
		add_filter( 'month_link', array( $this->url_manager, 'filter_current_url' ) );
		add_filter( 'day_link', array( $this->url_manager, 'filter_current_url' ) );
		add_filter( 'search_link', array( $this->url_manager, 'filter_current_url' ) );
		add_filter( 'get_pagenum_link', array( $this->url_manager, 'filter_current_url' ) );
	}

	/**
	 * Removes stale LocalePress rewrites after another router takes ownership.
	 *
	 * This runs once when the stored routing signature shows that LocalePress had
	 * previously generated rules.
	 *
	 * @return void
	 */
	public function flush_disabled_rewrite_rules() {
		flush_rewrite_rules( false );
	}

	/**
	 * Registers the public language query variable.
	 *
	 * @param array<int, string> $query_vars Existing public query variables.
	 * @return array<int, string>
	 */
	public function register_query_var( $query_vars ) {
		$query_vars[] = LanguageUrlManager::QUERY_VAR;

		return array_values( array_unique( $query_vars ) );
	}

	/**
	 * Prefixes WordPress-generated public rewrite rules with one language match.
	 *
	 * A single alternation is used for every enabled language, keeping rule count
	 * constant as languages are added. Original non-prefixed rules remain below
	 * for canonical migration and WordPress infrastructure compatibility.
	 *
	 * @param array<string, string> $rules Core and extension rewrite rules.
	 * @return array<string, string>
	 */
	public function add_language_rewrite_rules( $rules ) {
		$slugs = $this->url_manager->get_language_slugs();

		// Host and query routing keep core's own paths; the language never enters
		// the path, so there is nothing extra to match.
		if (
			empty( $slugs )
			|| $this->url_manager->uses_host_routing()
			|| $this->url_manager->uses_query_routing()
		) {
			return $rules;
		}

		$escaped   = array_map(
			static function ( $slug ) {
				return preg_quote( $slug, '#' );
			},
			$slugs
		);
		$pattern   = '(' . implode( '|', $escaped ) . ')';
		$localized = array(
			'^' . $pattern . '/?$' => 'index.php?' . LanguageUrlManager::QUERY_VAR . '=$matches[1]',
		);

		foreach ( $rules as $regex => $query ) {
			if ( ! $this->should_localize_rule( $regex, $query ) ) {
				continue;
			}

			$localized_regex               = '^' . $pattern . '/' . ltrim( preg_replace( '/^\^/', '', $regex ), '/' );
			$localized_query               = $this->shift_rewrite_matches( $query );
			$separator                     = false === strpos( $localized_query, '?' ) ? '?' : '&';
			$localized_query              .= $separator . LanguageUrlManager::QUERY_VAR . '=$matches[1]';
			$localized[ $localized_regex ] = $localized_query;
		}

		return $localized + $rules;
	}

	/**
	 * Flushes rewrites only when language slugs or routing structure changed.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( wp_installing() ) {
			return;
		}

		$signature = $this->get_rewrite_signature();

		if ( hash_equals( (string) get_option( self::REWRITE_SIGNATURE_OPTION, '' ), $signature ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_SIGNATURE_OPTION, $signature, false );
	}

	/**
	 * Treats every language host as an internal redirect target.
	 *
	 * wp_safe_redirect() refuses any host but the site's own, which would silently
	 * drop every canonical and browser-detection redirect the moment a language
	 * lives on a host of its own.
	 *
	 * @param array<int, string> $hosts Allowed redirect hosts.
	 * @return array<int, string>
	 */
	public function allow_language_hosts( $hosts ) {
		if ( ! $this->url_manager->uses_host_routing() ) {
			return $hosts;
		}

		$hosts = is_array( $hosts ) ? $hosts : array();

		foreach ( $this->url_manager->get_language_slugs() as $slug ) {
			$host = $this->url_manager->get_language_host( $slug );

			if ( '' !== $host ) {
				$hosts[] = $host;
				$hosts[] = 'www.' . $host;
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Points home_url() at the host serving the current language.
	 *
	 * Permalink filters already localize post, term, and archive links. This
	 * covers everything a theme builds from the site root itself — logo links,
	 * search actions, hand-built URLs — so one page never mixes two hosts.
	 *
	 * @param string $url  Complete home URL.
	 * @param string $path Path relative to the home URL.
	 * @return string
	 */
	public function filter_home_url( $url, $path ) {
		if (
			! $this->url_manager->uses_host_routing()
			|| is_admin()
			|| wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| $this->url_manager->is_excluded_url( $url )
		) {
			return $url;
		}

		$language = $this->url_manager->get_current_language();

		if ( null === $language ) {
			return $url;
		}

		$host = $this->url_manager->get_language_host( $language );

		if ( '' === $host ) {
			return $url;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || $parts['host'] === $host ) {
			return $url;
		}

		unset( $path );
		$parts['host'] = $host;

		return $this->rebuild_url( $parts, $url );
	}

	/**
	 * Reassembles a parsed URL after its host was replaced.
	 *
	 * @param array<string, mixed> $parts    Parsed URL parts.
	 * @param string               $original Original URL.
	 * @return string
	 */
	private function rebuild_url( $parts, $original ) {
		$url = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//' ) . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$url .= ':' . absint( $parts['port'] );
		}

		$url .= isset( $parts['path'] ) ? $parts['path'] : '';

		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$url .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$url .= '#' . $parts['fragment'];
		}

		return '' === $url ? $original : $url;
	}

	/**
	 * Publishes the request's language as its language query variable.
	 *
	 * Directory routing gets this variable from a rewrite rule. Host and query
	 * routing have no such rule, so it is set here instead, before anything
	 * downstream reads it. Everything that follows — translation mapping, query
	 * constraints, the canonical redirect — then behaves identically in all four
	 * modes.
	 *
	 * A host always names a language, so host routing publishes one for every
	 * request. A query argument does not: a request that carries none is the
	 * unprefixed request the canonical redirect and visitor detection both exist
	 * to answer, and claiming a language for it would hide it from them. The main
	 * query still falls back to the default language on its own.
	 *
	 * @param \WP $wp Parsed WordPress request.
	 * @return void
	 */
	public function apply_request_language( $wp ) {
		if ( is_admin() ) {
			return;
		}

		if ( $this->url_manager->uses_query_routing() ) {
			if ( ! $this->url_manager->request_has_language_prefix() ) {
				return;
			}
		} elseif ( ! $this->url_manager->uses_host_routing() ) {
			return;
		}

		$language = $this->url_manager->get_current_language();

		if ( null === $language ) {
			return;
		}

		$wp->query_vars[ LanguageUrlManager::QUERY_VAR ] = $language['url_slug'];
	}

	/**
	 * Maps shared source routes to the requested post or term translation.
	 *
	 * @param \WP $wp Parsed WordPress request.
	 * @return void
	 */
	public function map_translated_request( $wp ) {
		if (
			! isset( $wp->query_vars[ LanguageUrlManager::QUERY_VAR ] )
			|| ! is_scalar( $wp->query_vars[ LanguageUrlManager::QUERY_VAR ] )
		) {
			return;
		}

		$language = $this->url_manager->resolve_language(
			$wp->query_vars[ LanguageUrlManager::QUERY_VAR ]
		);

		if ( null === $language ) {
			return;
		}

		$this->request_language_id = $language['id'];
		$this->map_root_front_page( $wp->query_vars, $language['id'] );
		$custom_post = $this->normalize_custom_post_query_vars( $wp->query_vars );

		if ( $custom_post instanceof WP_Post ) {
			$this->map_post_query_vars( $wp->query_vars, $custom_post, $language['id'] );
		} elseif ( ! empty( $wp->query_vars['pagename'] ) ) {
			$post = $this->find_page_by_path( $wp->query_vars['pagename'] );

			if ( $post instanceof WP_Post ) {
				$this->map_post_query_vars( $wp->query_vars, $post, $language['id'] );
			}
		} elseif ( ! empty( $wp->query_vars['name'] ) ) {
			$post = $this->find_named_post( $wp->query_vars['name'], $wp->query_vars );

			if ( $post instanceof WP_Post ) {
				$this->map_post_query_vars( $wp->query_vars, $post, $language['id'] );
			}
		}

		$this->map_term_query_vars( $wp->query_vars, $language['id'] );
	}

	/**
	 * Makes WordPress's effective static front page language-specific.
	 *
	 * This preserves native front-page conditionals and template hierarchy. The
	 * configured source page remains unchanged in the database.
	 *
	 * @param mixed $front_page_id Configured source front page identifier.
	 * @return int
	 */
	public function filter_front_page_option( $front_page_id ) {
		return $this->filter_static_page_option(
			$front_page_id,
			$this->url_manager->get_front_page_id()
		);
	}

	/**
	 * Makes WordPress's effective posts page language-specific.
	 *
	 * WordPress decides `is_home()` and `is_posts_page` by comparing this option
	 * with the queried page, so translating it is what lets a translated blog
	 * page behave as an archive instead of a singular page.
	 *
	 * @param mixed $posts_page_id Configured source posts page identifier.
	 * @return int
	 */
	public function filter_posts_page_option( $posts_page_id ) {
		return $this->filter_static_page_option(
			$posts_page_id,
			$this->url_manager->get_posts_page_id()
		);
	}

	/**
	 * Resolves one static page option to the requested language.
	 *
	 * @param mixed $stored_id Stored option value.
	 * @param int   $source_id Captured source page identifier.
	 * @return int
	 */
	private function filter_static_page_option( $stored_id, $source_id ) {
		if ( '' === $this->request_language_id || 1 > $source_id || $this->is_static_page_option_locked() ) {
			return absint( $stored_id );
		}

		$target_id = $this->post_translations->get_translation(
			$source_id,
			$this->request_language_id
		);

		return 0 < $target_id ? $target_id : $source_id;
	}

	/**
	 * Reports whether a static page option must return its stored value.
	 *
	 * WordPress reads these options while writing them and while resetting the
	 * front-page settings of a post being trashed or deleted. Returning a
	 * translation during those operations would rewrite the wrong record.
	 *
	 * @return bool
	 */
	private function is_static_page_option_locked() {
		return doing_action( 'update_option_page_on_front' )
			|| doing_action( 'update_option_page_for_posts' )
			|| doing_action( 'before_delete_post' )
			|| doing_action( 'wp_trash_post' )
			|| doing_action( 'switch_blog' );
	}

	/**
	 * Maps a static front page to its requested translation.
	 *
	 * Only a request that names the front page is answered here. A request that
	 * names no page at all is every other route on the site, and the language
	 * root of an untranslated front page is deliberately one of them:
	 * map_root_front_page() leaves it to resolve as that language's posts
	 * listing rather than a dead end.
	 *
	 * @param WP_Query $query Main query.
	 * @return void
	 */
	public function map_front_page( $query ) {
		if (
			! $query instanceof WP_Query
			|| ! $this->should_filter_query( $query )
			|| 'page' !== get_option( 'show_on_front' )
		) {
			return;
		}

		$front_page_id = $this->url_manager->get_front_page_id();
		$queried_id    = absint( $query->get( 'page_id' ) );

		if ( 1 > $front_page_id || 1 > $queried_id || '' === $this->request_language_id ) {
			return;
		}

		$target_id = $this->post_translations->get_translation(
			$front_page_id,
			$this->request_language_id
		);

		if ( ! in_array( $queried_id, array( $front_page_id, $target_id ), true ) ) {
			return;
		}

		$query->set( 'page_id', 0 < $target_id ? $target_id : -1 );
	}

	/**
	 * Lets a listing that asked for no filters still be scoped to the language.
	 *
	 * `get_posts()` suppresses filters unless its caller says otherwise, and the
	 * language constraint is a filter, so every listing built that way answers in
	 * every language at once. A page builder's archive widget, a theme's "recent
	 * posts", a related-posts block: none of them meant to opt out of the site's
	 * language, they simply called the function WordPress offers.
	 *
	 * WordPress's own multilingual convention stores the language as a taxonomy,
	 * which is applied while the query is assembled rather than through a filter,
	 * and so is unaffected by suppression. LocalePress keeps its assignments in
	 * an indexed table instead, and reaches the same place by lifting the
	 * suppression on exactly the queries it would have scoped anyway.
	 *
	 * That is a real change to those queries: every other plugin's `posts_*`
	 * filters run on them too, which is what suppression was holding back. It is
	 * limited to rendered frontend listings of translated post types, and the
	 * filter below turns it off for one query or for all of them.
	 *
	 * @param WP_Query $query Query about to run.
	 * @return void
	 */
	public function scope_suppressed_query( $query ) {
		if (
			! $query instanceof WP_Query
			|| ! $query->get( 'suppress_filters' )
			|| ! $this->should_filter_secondary_query( $query )
		) {
			return;
		}

		/**
		 * Filters whether LocalePress may lift one query's filter suppression.
		 *
		 * Returning false leaves the query exactly as its caller built it, which
		 * also leaves it unscoped: a listing no filter can reach is a listing no
		 * language can reach either.
		 *
		 * @param bool     $scope Whether the suppression may be lifted.
		 * @param WP_Query $query Query about to run.
		 */
		if ( ! apply_filters( 'localepress_scope_suppressed_queries', true, $query ) ) {
			return;
		}

		$query->set( 'suppress_filters', false );
	}

	/**
	 * Marks Query Loop block queries so they inherit the request language.
	 *
	 * Every block in the Query Loop family — post template, pagination, total, and
	 * the no-results fallback — builds its query vars through this filter, so one
	 * marker keeps a paginated loop and its counters consistent.
	 *
	 * @param array<string, mixed> $query_vars Query vars built for the block.
	 * @return array<string, mixed>
	 */
	public function mark_query_loop_block_vars( $query_vars ) {
		if ( ! is_array( $query_vars ) ) {
			return $query_vars;
		}

		$query_vars['localepress_block_query'] = true;

		return $query_vars;
	}

	/**
	 * Opens the render scope for a block that runs its own post query.
	 *
	 * @param array<string, mixed> $parsed_block Parsed block being rendered.
	 * @return array<string, mixed>
	 */
	public function open_block_query_scope( $parsed_block ) {
		if ( is_array( $parsed_block ) && $this->is_post_query_block( $parsed_block ) ) {
			++$this->block_query_depth;
		}

		return $parsed_block;
	}

	/**
	 * Closes the render scope opened for a post-query block.
	 *
	 * @param string               $block_content Rendered block markup.
	 * @param array<string, mixed> $parsed_block  Parsed block that rendered.
	 * @return string
	 */
	public function close_block_query_scope( $block_content, $parsed_block ) {
		if ( is_array( $parsed_block ) && $this->is_post_query_block( $parsed_block ) ) {
			$this->block_query_depth = max( 0, $this->block_query_depth - 1 );
		}

		return $block_content;
	}

	/**
	 * Restricts frontend post queries to the requested language.
	 *
	 * The main query is constrained as before. Block-driven secondary queries are
	 * constrained separately so a translated page's Query Loop, pagination, and
	 * post-list blocks cannot mix languages.
	 *
	 * Default-language queries also include legacy posts with no stored assignment.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param WP_Query              $query   Current query.
	 * @return array<string, string>
	 */
	public function filter_posts_by_language( $clauses, $query ) {
		if ( ! $query instanceof WP_Query ) {
			return $clauses;
		}

		$language_id = $this->get_query_language_id( $query );
		$default     = $this->url_manager->get_default_language();

		if ( '' === $language_id || null === $default ) {
			return $clauses;
		}

		return $this->constraint->apply_to_posts( $clauses, $language_id, $default['id'] );
	}

	/**
	 * Constrains a frontend term listing to the language being viewed.
	 *
	 * A category widget, a tag cloud, and a term block all read terms through
	 * one query, so the visited language decides which of them a visitor is
	 * offered. Without this the sidebar lists every language's categories and
	 * each link leaves the language the reader chose.
	 *
	 * @param array<string, string> $clauses    SQL clauses.
	 * @param array<int, string>    $taxonomies Queried taxonomies.
	 * @param array<string, mixed>  $args       Term query arguments.
	 * @return array<string, string>
	 */
	public function filter_terms_by_language( $clauses, $taxonomies, $args ) {
		if ( ! is_array( $clauses ) || ! is_array( $taxonomies ) || 1 !== count( $taxonomies ) ) {
			return $clauses;
		}

		/*
		 * Only a rendered page is scoped by the language its URL names. REST and
		 * AJAX carry their own language, and RestLanguageModule already applies
		 * it: constraining those again would AND two languages together and
		 * answer the editor with an empty list.
		 */
		if ( is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		) {
			return $clauses;
		}

		// Reading the terms one object holds answers what that object was given,
		// so it keeps every one of them whatever language is being viewed. A
		// sitemap query names every language on purpose and narrows itself.
		if ( is_array( $args )
			&& ( ! empty( $args['object_ids'] )
				|| ! empty( $args['localepress_skip_language_filter'] )
				|| ! empty( $args[ SitemapModule::QUERY_MARKER ] ) )
		) {
			return $clauses;
		}

		$taxonomy = (string) reset( $taxonomies );

		if ( ! $this->term_translations->supports_taxonomy( $taxonomy ) ) {
			return $clauses;
		}

		$language_id = $this->get_current_language_id();
		$default     = $this->url_manager->get_default_language();

		if ( '' === $language_id || null === $default ) {
			return $clauses;
		}

		/**
		 * Filters whether a frontend term listing is constrained by language.
		 *
		 * @param bool                 $filter      Whether language filtering should run.
		 * @param string               $taxonomy    Taxonomy name.
		 * @param string               $language_id Language being viewed.
		 * @param array<string, mixed> $args        Term query arguments.
		 */
		$filter = apply_filters(
			'localepress_filter_terms_by_language',
			true,
			$taxonomy,
			$language_id,
			is_array( $args ) ? $args : array()
		);

		if ( ! $filter ) {
			return $clauses;
		}

		return $this->constraint->apply_to_terms( $clauses, $language_id, $default['id'] );
	}

	/**
	 * Returns the language one post query must be constrained to.
	 *
	 * @param WP_Query $query Query object.
	 * @return string Empty when the query must not be constrained.
	 */
	private function get_query_language_id( WP_Query $query ) {
		if ( $query->get( 'suppress_filters' ) || $query->get( 'localepress_skip_language_filter' ) ) {
			return '';
		}
		if ( $this->should_filter_query( $query ) ) {
			/*
			 * A request that names one object is answered with that object,
			 * whatever language it is in. This is the rule WordPress applies to
			 * a language stored as a taxonomy, where the term query is joined
			 * only for a query that is not singular: an identifier or a slug
			 * already names exactly one record, so a language can add nothing to
			 * the answer and can only take the record away.
			 *
			 * Nothing is lost by it. A route that names an object in the wrong
			 * language is corrected by redirect_noncanonical_object_route(),
			 * which sends the reader to that same object on its own language
			 * route rather than showing them a page that is missing.
			 *
			 * A page builder preview depends on this. The builder previews the
			 * document an editor opened through a URL of its own making, and a
			 * translated draft has no other URL to offer: it carries no language
			 * prefix, so the request resolves to the default language. Filtered
			 * to it, the builder is handed a 404 in place of the document it
			 * asked for, renders the ordinary page instead, and the editor waits
			 * for a preview that never arrives.
			 */
			if ( $query->is_singular ) {
				return '';
			}

			/*
			 * A request that reads nothing translatable is left as it is, because
			 * a post type with no assignments would answer every language but the
			 * default with nothing at all. On a single post that silence is a 404
			 * for a page that plainly exists, so those stay unconstrained.
			 *
			 * An archive listing is the exception. Leaving it alone hands a reader
			 * who asked for one language a page of posts written in another, and
			 * an untranslated shop then looks translated. Constraining it says the
			 * true thing instead: this language has nothing here.
			 */
			if (
				! $this->query_reads_supported_post_types( $query )
				&& ! $this->constrains_untranslated_archive( $query )
			) {
				return '';
			}

			/**
			 * Filters whether LocalePress should constrain the main frontend query.
			 *
			 * @param bool     $filter Whether language filtering should run.
			 * @param WP_Query $query  Main frontend query.
			 */
			return apply_filters( 'localepress_filter_main_query_by_language', true, $query )
				? $this->get_current_language_id()
				: '';
		}

		if ( $this->should_filter_secondary_query( $query ) ) {
			$filter = true;

			if ( $this->is_block_query( $query ) ) {
				/**
				 * Filters whether LocalePress should constrain a block's own post query.
				 *
				 * The narrower name, kept for integrations written while blocks were
				 * the only secondary queries LocalePress constrained.
				 *
				 * @param bool     $filter Whether language filtering should run.
				 * @param WP_Query $query  Secondary query built while rendering a block.
				 */
				$filter = (bool) apply_filters( 'localepress_filter_block_query_by_language', $filter, $query );
			}

			/**
			 * Filters whether LocalePress should constrain one secondary post query.
			 *
			 * @param bool     $filter Whether language filtering should run.
			 * @param WP_Query $query  Secondary frontend query.
			 */
			return apply_filters( 'localepress_filter_secondary_query_by_language', $filter, $query )
				? $this->get_current_language_id()
				: '';
		}

		return $this->get_requested_query_language_id( $query );
	}

	/**
	 * Returns the language a query asked for by argument.
	 *
	 * Themes and page builders run their own queries to decide which template,
	 * header, or footer to render, and LocalePress cannot recognize those on its
	 * own. Passing the language as a query argument opts one in:
	 *
	 *     new WP_Query(
	 *         array(
	 *             'post_type'      => 'elementor-hf',
	 *             'localepress_lang' => 'current', // or a slug, code, or ID
	 *         )
	 *     );
	 *
	 * Unlike the automatic paths this is honored in the admin and the REST API
	 * too, because it was asked for explicitly rather than inferred.
	 *
	 * @param WP_Query $query Query object.
	 * @return string Empty when no usable language was requested.
	 */
	private function get_requested_query_language_id( WP_Query $query ) {
		$requested = $query->get( LanguageUrlManager::QUERY_VAR );
		$opt_in    = $query->get( 'localepress_filter_by_language' );
		$has_value = is_scalar( $requested ) && '' !== (string) $requested && false !== $requested;

		if ( ! $has_value && empty( $opt_in ) ) {
			return '';
		}

		/*
		 * A post type with no language assignments would come back empty rather
		 * than unfiltered, which reads as data loss. Declining to filter leaves
		 * the caller with the same results it had before asking.
		 */
		if ( ! $this->query_targets_supported_post_types( $query ) ) {
			return '';
		}

		if ( ! $has_value || 'current' === $requested || true === $requested ) {
			return $this->get_current_language_id();
		}

		$language = $this->url_manager->resolve_language( $requested );

		return null === $language ? '' : $language['id'];
	}

	/**
	 * Returns the current request language identifier.
	 *
	 * @return string
	 */
	private function get_current_language_id() {
		$language = $this->url_manager->get_current_language();

		return null === $language ? '' : $language['id'];
	}

	/**
	 * Primes post relationship caches for frontend result sets.
	 *
	 * @param array<int, WP_Post> $posts Queried posts.
	 * @param WP_Query            $query Query object.
	 * @return array<int, WP_Post>
	 */
	public function prime_post_translation_cache( $posts, $query ) {
		if ( is_admin() || ! $query instanceof WP_Query || ! is_array( $posts ) || 2 > count( $posts ) ) {
			return $posts;
		}

		$post_ids = array();

		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post && $this->post_translations->supports_post_type( $post->post_type ) ) {
				$post_ids[] = $post->ID;
			}
		}

		if ( ! empty( $post_ids ) ) {
			$this->post_translations->prime_posts( $post_ids );
		}

		return $posts;
	}

	/**
	 * Primes term relationship caches for frontend term result sets.
	 *
	 * @param array<int, WP_Term>|mixed $terms      Queried terms or another fields result.
	 * @param array<int, string>        $taxonomies Queried taxonomy names.
	 * @param array<string, mixed>      $args       Term query arguments.
	 * @param \WP_Term_Query            $term_query Term query object.
	 * @return array<int, WP_Term>|mixed
	 */
	public function prime_term_translation_cache( $terms, $taxonomies, $args, $term_query ) {
		unset( $taxonomies, $args, $term_query );

		if ( is_admin() || ! is_array( $terms ) || 2 > count( $terms ) ) {
			return $terms;
		}

		$term_taxonomy_ids = array();

		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && $this->term_translations->supports_taxonomy( $term->taxonomy ) ) {
				$term_taxonomy_ids[] = $term->term_taxonomy_id;
			}
		}

		if ( ! empty( $term_taxonomy_ids ) ) {
			$this->term_translations->prime_terms( $term_taxonomy_ids );
		}

		return $terms;
	}

	/**
	 * Redirects valid non-prefixed frontend requests to the default prefix.
	 *
	 * Existing 404 requests and WordPress infrastructure endpoints remain untouched.
	 *
	 * @return void
	 */
	public function redirect_unprefixed_request() {
		$redirect_url = $this->get_unprefixed_redirect_url();

		if ( '' === $redirect_url ) {
			return;
		}

		wp_safe_redirect( $redirect_url, 301, 'LocalePress' );
		exit;
	}

	/**
	 * Redirects prefixed object aliases to the LocalePress canonical route.
	 *
	 * Post translations use the source member's route, while term translations
	 * use the translated term's core route. Endpoint-shaped requests are left to
	 * WordPress so pagination, feeds, and embeds retain their native behavior.
	 *
	 * @return void
	 */
	public function redirect_noncanonical_object_route() {
		if (
			$this->is_excluded_request()
			|| is_404()
			|| is_preview()
			|| $this->url_manager->is_builder_preview_request()
			|| is_feed()
			|| is_embed()
			|| is_paged()
			|| 1 < absint( get_query_var( 'page' ) )
		) {
			return;
		}

		$language = $this->url_manager->get_current_language();

		if ( null === $language ) {
			return;
		}

		$queried = get_queried_object();

		/*
		 * A singular route is answered by the object it names rather than by the
		 * language the URL claims, so the object decides where the reader belongs.
		 * Sending them to the language of the URL would leave one post reachable
		 * under every language at once; sending them to the language the post is
		 * written in is the one address it has.
		 *
		 * An empty language is how the URL builders ask for exactly that, and it
		 * falls back to the requested language for an object holding none.
		 */
		if ( is_singular() ) {
			$url = $this->url_manager->get_post_url( get_queried_object_id(), '' );

			if ( '' === $url ) {
				$url = $this->url_manager->get_post_url( get_queried_object_id(), $language );
			}
		} elseif ( $queried instanceof WP_Term ) {
			$url = $this->url_manager->get_term_url( $queried, $queried->taxonomy, '' );

			if ( '' === $url ) {
				$url = $this->url_manager->get_term_url( $queried, $queried->taxonomy, $language );
			}
		} elseif ( is_home() && $queried instanceof WP_Post && 0 < $this->url_manager->get_posts_page_id() ) {
			/*
			 * A posts page is the one archive with a translated page behind it, so
			 * a translation's own slug resolves here too. Send it to the source
			 * route the rest of the site uses. A language root that merely falls
			 * back to the archive has no queried page and is left alone.
			 */
			$url = $this->url_manager->get_post_url(
				$this->url_manager->get_posts_page_id(),
				$language
			);
		} else {
			return;
		}

		$url     = $this->preserve_current_query_string( $url );
		$current = $this->url_manager->get_current_request_url();

		if ( '' === $url || $this->same_url( $url, $current ) ) {
			return;
		}

		wp_safe_redirect( $url, 301, 'LocalePress' );
		exit;
	}

	/**
	 * Returns the canonical default-language redirect for the current request.
	 *
	 * This method is public so integrations and tests can inspect the decision
	 * without executing a redirect.
	 *
	 * @return string Empty when no LocalePress redirect should occur.
	 */
	public function get_unprefixed_redirect_url() {
		if (
			$this->is_excluded_request()
			|| is_404()
			|| is_preview()
			|| $this->url_manager->is_builder_preview_request()
			/*
			 * Only a path prefix needs pretty permalinks to exist at all. A query
			 * argument is added to whatever URL WordPress already produced, so it
			 * is canonicalized on a plain-permalink site too.
			 */
			|| ( ! $this->url_manager->uses_query_routing() && '' === (string) get_option( 'permalink_structure' ) )
			/*
			 * Host routing has no unprefixed form to correct: every request already
			 * arrives on a language host, or on one that maps to no language at all
			 * and is left alone rather than bounced.
			 */
			|| $this->url_manager->uses_host_routing()
		) {
			return '';
		}

		$default = $this->url_manager->get_default_language();

		if ( null === $default ) {
			return '';
		}

		$has_prefix     = $this->url_manager->request_has_language_prefix();
		$prefix_default = $this->url_manager->should_prefix_default_language();
		$current        = $this->url_manager->get_current_language();

		if ( $prefix_default && $has_prefix ) {
			return '';
		}

		if (
			! $prefix_default
			&& (
				! $has_prefix
				|| null === $current
				|| $current['id'] !== $default['id']
			)
		) {
			return '';
		}

		if ( is_front_page() ) {
			$url = $this->url_manager->get_language_home_url( $default );
		} elseif ( is_home() ) {
			$posts_page_id = $this->url_manager->get_posts_page_id();
			$url           = 0 < $posts_page_id
				? $this->url_manager->get_post_url( $posts_page_id, $default )
				: $this->url_manager->get_language_home_url( $default );
		} elseif ( is_singular() ) {
			/*
			 * The object decides, for the same reason it decides in
			 * redirect_noncanonical_object_route(): a singular route is answered
			 * by the one record it names, and correcting the URL to the default
			 * language would move a translated post off the only address it has.
			 * The two redirects run on the same request and have to agree, or
			 * each would keep undoing the other.
			 */
			$url = $this->url_manager->get_post_url( get_queried_object_id(), '' );

			if ( '' === $url ) {
				$url = $this->url_manager->get_post_url( get_queried_object_id(), $default );
			}
		} else {
			$queried_object = get_queried_object();

			if ( $queried_object instanceof WP_Term ) {
				$url = $this->url_manager->get_term_url(
					$queried_object,
					$queried_object->taxonomy,
					''
				);

				if ( '' === $url ) {
					$url = $this->url_manager->get_term_url(
						$queried_object,
						$queried_object->taxonomy,
						$default
					);
				}
			} else {
				$url = $this->url_manager->prefix_url(
					$this->url_manager->get_current_request_url(),
					$default
				);
			}
		}

		if ( '' === $url || $this->same_url( $url, $this->url_manager->get_current_request_url() ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Prevents WordPress canonical redirects from dropping a valid prefix.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public function preserve_canonical_language( $redirect_url, $requested_url ) {
		/*
		 * WordPress already stands down for its own preview, for the reason that
		 * a preview is shown at the address the editor was handed and moving it
		 * takes the frame somewhere the builder is not watching. A page builder
		 * preview is the same request under a name WordPress does not know, so it
		 * is answered the same way.
		 */
		if ( $this->url_manager->is_builder_preview_request() ) {
			return false;
		}

		if ( ! is_string( $redirect_url ) || '' === $redirect_url || ! $this->url_manager->request_has_language_prefix() ) {
			return $redirect_url;
		}

		$language = $this->url_manager->get_current_language();

		if ( null === $language || $this->url_manager->is_excluded_url( $redirect_url ) ) {
			return $redirect_url;
		}

		$redirect_url = $this->url_manager->prefix_url( $redirect_url, $language );

		return $this->same_url( $redirect_url, $requested_url ) ? false : $redirect_url;
	}

	/**
	 * Reports whether one rewrite rule should receive a language prefix.
	 *
	 * @param string $regex Rewrite regular expression.
	 * @param string $query Rewrite query.
	 * @return bool
	 */
	private function should_localize_rule( $regex, $query ) {
		if ( ! is_string( $query ) ) {
			return false;
		}

		if (
			false !== strpos( $query, LanguageUrlManager::QUERY_VAR . '=' )
			|| preg_match( '/(?:\?|&)lang=/', $query )
		) {
			return false;
		}

		return ! preg_match(
			'/(?:wp-json|wp-sitemap|robots\\.txt|favicon\\.ico|wp-admin|wp-login)/i',
			$regex
		);
	}

	/**
	 * Increments existing rewrite match references for the language capture.
	 *
	 * @param string $query Rewrite query.
	 * @return string
	 */
	private function shift_rewrite_matches( $query ) {
		return preg_replace_callback(
			'/\$matches\[(\d+)\]|(?<!\])\$([1-9]\d*)/',
			static function ( $matches ) {
				$index = '' !== $matches[1] ? absint( $matches[1] ) : absint( $matches[2] );

				if ( 0 === $index ) {
					return $matches[0];
				}

				return '' !== $matches[1]
					? '$matches[' . ( $index + 1 ) . ']'
					: '$' . ( $index + 1 );
			},
			$query
		);
	}

	/**
	 * Returns the rewrite signature for enabled slugs and permalink settings.
	 *
	 * @return string
	 */
	private function get_rewrite_signature() {
		$slugs = $this->url_manager->get_language_slugs();
		sort( $slugs );

		$hosts = array();

		foreach ( $this->url_manager->get_language_slugs() as $slug ) {
			$hosts[ $slug ] = $this->url_manager->get_language_host( $slug );
		}

		ksort( $hosts );

		return md5(
			wp_json_encode(
				array(
					'schema'              => self::REWRITE_SCHEMA_VERSION,
					'slugs'               => $slugs,
					// Switching between directory and host routing adds or removes
					// every localized rule, so the mode and its hosts belong here.
					'mode'                => $this->url_manager->hosts()->get_mode(),
					'hosts'               => $hosts,
					'permalink_structure' => (string) get_option( 'permalink_structure' ),
				)
			)
		);
	}

	/**
	 * Maps the exact language root to the effective static front page.
	 *
	 * A public language query variable makes WordPress treat the request as
	 * non-empty, so core does not apply show_on_front by itself. When the front
	 * page has no translation, the root falls back to the language's posts
	 * listing instead of a 404.
	 *
	 * @param array<string, mixed> $query_vars  Parsed query vars, by reference.
	 * @param string               $language_id Requested language identifier.
	 * @return void
	 */
	private function map_root_front_page( &$query_vars, $language_id ) {
		$public_query_vars = array_diff_key(
			$query_vars,
			array( LanguageUrlManager::QUERY_VAR => true )
		);

		if ( ! empty( $public_query_vars ) || 'page' !== get_option( 'show_on_front' ) ) {
			return;
		}

		$source_id = $this->url_manager->get_front_page_id();
		$target_id = 0 < $source_id
			? $this->post_translations->get_translation( $source_id, $language_id )
			: 0;

		if ( 0 < $target_id ) {
			$query_vars['page_id'] = $target_id;
		}

		/*
		 * An untranslated front page leaves the query vars alone. The language
		 * variable keeps the request non-empty, so WordPress resolves the root as
		 * the posts listing, which the language clause then constrains. A language
		 * root must stay reachable: the switcher sends every untranslated language
		 * here, so answering with a 404 would send visitors to a dead end.
		 */
	}

	/**
	 * Finds a supported hierarchical post by its source route.
	 *
	 * @param string $path Requested page path.
	 * @return WP_Post|null
	 */
	private function find_page_by_path( $path ) {
		$post_types = array();

		foreach ( $this->post_translations->get_supported_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( $object && $object->hierarchical ) {
				$post_types[] = $post_type;
			}
		}

		$post = empty( $post_types )
			? null
			: get_page_by_path( $path, OBJECT, $post_types );

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Normalizes a supported CPT's custom query variable for singular mapping.
	 *
	 * @param array<string, mixed> $query_vars Parsed query vars, by reference.
	 * @return WP_Post|null
	 */
	private function normalize_custom_post_query_vars( &$query_vars ) {
		foreach ( $this->post_translations->get_supported_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( ! $object || ! is_string( $object->query_var ) || empty( $query_vars[ $object->query_var ] ) ) {
				continue;
			}

			$path = is_scalar( $query_vars[ $object->query_var ] )
				? (string) $query_vars[ $object->query_var ]
				: '';
			$post = '' === $path ? null : get_page_by_path( $path, OBJECT, $post_type );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			unset( $query_vars[ $object->query_var ] );
			$query_vars['post_type'] = $post_type;

			if ( $object->hierarchical ) {
				$query_vars['pagename'] = $path;
			} else {
				$query_vars['name'] = $post->post_name;
			}

			return $post;
		}

		return null;
	}

	/**
	 * Finds a supported non-hierarchical post by its source route.
	 *
	 * @param string               $name       Requested post slug.
	 * @param array<string, mixed> $query_vars Parsed query variables.
	 * @return WP_Post|null
	 */
	private function find_named_post( $name, $query_vars ) {
		$requested_types = isset( $query_vars['post_type'] )
			? (array) $query_vars['post_type']
			: array( 'post' );
		$post_types      = array_values(
			array_intersect(
				array_map( 'sanitize_key', $requested_types ),
				$this->post_translations->get_supported_post_types()
			)
		);

		$post = empty( $post_types )
			? null
			: get_page_by_path( $name, OBJECT, $post_types );

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Rewrites singular query vars to a requested translation.
	 *
	 * @param array<string, mixed> $query_vars  Parsed query vars, by reference.
	 * @param WP_Post              $post        Post resolved from the route.
	 * @param string               $language_id Requested language identifier.
	 * @return void
	 */
	private function map_post_query_vars( &$query_vars, WP_Post $post, $language_id ) {
		// The captured source ID is used because the option itself is translated.
		if ( $this->url_manager->get_posts_page_id() === $post->ID ) {
			$this->map_posts_page_query_vars( $query_vars, $post, $language_id );
			return;
		}

		$current_id = $this->post_translations->get_post_language_id( $post->ID );

		if ( $language_id === $current_id ) {
			return;
		}

		$target_id = $this->post_translations->get_translation( $post->ID, $language_id );

		unset(
			$query_vars['name'],
			$query_vars['pagename'],
			$query_vars['year'],
			$query_vars['monthnum'],
			$query_vars['day']
		);

		if ( 1 > $target_id ) {
			$post_type = get_post_type_object( $post->post_type );

			if ( $post_type && $post_type->hierarchical ) {
				$query_vars['pagename'] = '__localepress_missing_translation__';
			} else {
				$query_vars['name'] = '__localepress_missing_translation__';
			}

			$query_vars['post_type'] = $post->post_type;
			unset( $query_vars['p'], $query_vars['page_id'] );
			return;
		}

		$target = get_post( $target_id );

		$target_type = $target instanceof WP_Post ? get_post_type_object( $target->post_type ) : null;

		if ( $target instanceof WP_Post && $target_type && $target_type->hierarchical ) {
			$query_vars['page_id'] = $target->ID;
			unset( $query_vars['p'] );
		} else {
			$query_vars['p']         = $target_id;
			$query_vars['post_type'] = $target instanceof WP_Post ? $target->post_type : 'any';
			unset( $query_vars['page_id'] );
		}
	}

	/**
	 * Points the posts page route at its translation for the requested language.
	 *
	 * The route stays the source page's, matching every other translated object.
	 * Only the queried page changes, so themes read the translated blog title and
	 * content while WordPress still resolves the request as the posts archive.
	 *
	 * @param array<string, mixed> $query_vars  Parsed query vars, by reference.
	 * @param WP_Post              $post        Source posts page.
	 * @param string               $language_id Requested language identifier.
	 * @return void
	 */
	private function map_posts_page_query_vars( &$query_vars, WP_Post $post, $language_id ) {
		if ( $language_id === $this->post_translations->get_post_language_id( $post->ID ) ) {
			return;
		}

		$target_id = $this->post_translations->get_translation( $post->ID, $language_id );

		/*
		 * An untranslated posts page keeps the source page. The archive itself is
		 * still constrained to the requested language, so the route stays useful
		 * rather than becoming a 404.
		 */
		if ( 1 > $target_id ) {
			return;
		}

		$query_vars['page_id'] = $target_id;
		unset( $query_vars['pagename'], $query_vars['p'] );
	}

	/**
	 * Rewrites taxonomy query vars to the requested translated term.
	 *
	 * @param array<string, mixed> $query_vars  Parsed query vars, by reference.
	 * @param string               $language_id Requested language identifier.
	 * @return void
	 */
	private function map_term_query_vars( &$query_vars, $language_id ) {
		foreach ( $this->term_translations->get_supported_taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( ! $object ) {
				continue;
			}

			$query_var = isset( $query_vars['taxonomy'], $query_vars['term'] )
				&& $taxonomy === $query_vars['taxonomy']
				? 'term'
				: $this->get_taxonomy_query_var( $taxonomy, $object );

			if ( '' === $query_var || empty( $query_vars[ $query_var ] ) ) {
				continue;
			}

			$path = is_scalar( $query_vars[ $query_var ] ) ? (string) $query_vars[ $query_var ] : '';
			$slug = sanitize_title( basename( trim( $path, '/' ) ) );
			$term = get_term_by( 'slug', $slug, $taxonomy );

			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			if ( $language_id === $this->term_translations->get_term_language_id( $term->term_id, $taxonomy ) ) {
				continue;
			}

			$target_id = $this->term_translations->get_translation( $term->term_id, $taxonomy, $language_id );
			$target    = 0 < $target_id ? get_term( $target_id, $taxonomy ) : null;

			$query_vars[ $query_var ] = $target instanceof WP_Term
				? $target->slug
				: '__localepress_missing_translation__';
		}
	}

	/**
	 * Returns the public query variable for a taxonomy.
	 *
	 * @param string       $taxonomy        Taxonomy name.
	 * @param \WP_Taxonomy $taxonomy_object Taxonomy object.
	 * @return string
	 */
	private function get_taxonomy_query_var( $taxonomy, $taxonomy_object ) {
		if ( 'category' === $taxonomy ) {
			return 'category_name';
		}

		if ( 'post_tag' === $taxonomy ) {
			return 'tag';
		}

		return is_string( $taxonomy_object->query_var ) ? $taxonomy_object->query_var : '';
	}

	/**
	 * Reports whether language SQL filtering is safe for this query.
	 *
	 * @param WP_Query $query Query object.
	 * @return bool
	 */
	private function should_filter_query( WP_Query $query ) {
		return ! is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& $query->is_main_query()
			&& ! $query->get( 'suppress_filters' )
			&& ! $query->get( 'localepress_skip_language_filter' );
	}

	/**
	 * Reports whether a secondary post query must inherit the request language.
	 *
	 * A page is one page, in one language. Everything listing posts on it — a
	 * Query Loop, a page builder's archive or grid widget, a theme's "related
	 * posts" — is answering the same question the main query answers, and a
	 * reader who chose a language should not be handed a list written in another
	 * one. So a listing built anywhere on a rendered page is scoped the same way
	 * the page is, which is how the language reaches code that has never heard of
	 * LocalePress.
	 *
	 * Two things are never scoped. A query that reads a post type outside the
	 * translation engine — navigation menus, templates, other non-public types —
	 * holds no language assignment, so a constraint could only empty it. And a
	 * query that names one object is answered by that object, for the reason
	 * WordPress itself gives a singular request no taxonomy clause.
	 *
	 * A query that says which language it wants is honoured further down, in
	 * get_requested_query_language_id(), and one that wants none says so with
	 * `localepress_skip_language_filter`.
	 *
	 * @param WP_Query $query Query object.
	 * @return bool
	 */
	private function should_filter_secondary_query( WP_Query $query ) {
		if (
			is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| $query->is_main_query()
			|| $query->is_singular
			|| $query->get( 'localepress_skip_language_filter' )
		) {
			return false;
		}

		/*
		 * Filter suppression is deliberately not read here. Reaching the clause
		 * at all means the suppression was already answered for, in
		 * get_query_language_id(); asking this before the query runs is how
		 * scope_suppressed_query() decides whether the suppression is worth
		 * lifting, and it has to get a straight answer to that question.
		 */
		return $this->query_targets_supported_post_types( $query );
	}

	/**
	 * Reports whether one secondary query belongs to a block that lists posts.
	 *
	 * @param WP_Query $query Query object.
	 * @return bool
	 */
	private function is_block_query( WP_Query $query ) {
		return (bool) $query->get( 'localepress_block_query' ) || 0 < $this->block_query_depth;
	}

	/**
	 * Reports whether a block builds its own post query while rendering.
	 *
	 * The Query Loop family is recognized through its official query-vars filter,
	 * so this list only covers blocks that instantiate WP_Query directly.
	 *
	 * @param array<string, mixed> $parsed_block Parsed block being rendered.
	 * @return bool
	 */
	private function is_post_query_block( array $parsed_block ) {
		$block_name = isset( $parsed_block['blockName'] ) && is_string( $parsed_block['blockName'] )
			? $parsed_block['blockName']
			: '';

		if ( '' === $block_name ) {
			return false;
		}

		return in_array( $block_name, $this->get_post_query_block_names(), true );
	}

	/**
	 * Returns the block names scoped for language filtering.
	 *
	 * The list is resolved once per request so a filter cannot change it between
	 * the opening and closing of a render scope.
	 *
	 * @return array<int, string>
	 */
	private function get_post_query_block_names() {
		if ( null !== $this->post_query_block_names ) {
			return $this->post_query_block_names;
		}

		/**
		 * Filters block names that run their own post query while rendering.
		 *
		 * @param array<int, string> $block_names Block names scoped for language filtering.
		 */
		$block_names = apply_filters( 'localepress_post_query_block_names', array( 'core/latest-posts' ) );
		$resolved    = array();

		foreach ( is_array( $block_names ) ? $block_names : array() as $block_name ) {
			if ( is_string( $block_name ) && '' !== $block_name ) {
				$resolved[] = $block_name;
			}
		}

		$this->post_query_block_names = array_values( array_unique( $resolved ) );

		return $this->post_query_block_names;
	}

	/**
	 * Reports whether every post type a query targets is translatable.
	 *
	 * @param WP_Query $query Query object.
	 * @return bool
	 */
	private function query_targets_supported_post_types( WP_Query $query ) {
		$post_types = $query->get( 'post_type' );

		if ( '' === $post_types || null === $post_types || array() === $post_types ) {
			$post_types = 'post';
		}

		if ( ! is_array( $post_types ) ) {
			if ( ! is_string( $post_types ) || 'any' === $post_types ) {
				return false;
			}

			$post_types = array( $post_types );
		}

		foreach ( $post_types as $post_type ) {
			if ( ! is_string( $post_type ) || ! $this->post_translations->supports_post_type( $post_type ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reports whether a language constraint can match anything a query reads.
	 *
	 * Only the post types a site chose to translate ever receive a language
	 * assignment. A query that reads none of them — a product route on a site
	 * that never enabled products, an attachment page while media translation is
	 * off — has no assignment row the clause could match, so constraining it
	 * empties the request in every language but the default. Declining keeps that
	 * content reachable under every prefix, which is what an untranslated post
	 * type means.
	 *
	 * One translatable post type is enough to keep the constraint. That type is
	 * the reason the request was routed to a language at all, and the others are
	 * already limited by whatever named them.
	 *
	 * @param WP_Query $query Query object.
	 * @return bool
	 */
	private function query_reads_supported_post_types( WP_Query $query ) {
		$post_types = $this->get_queried_post_types( $query );

		// A query asking for every post type names none in particular, so there
		// is nothing here to opt out of the constraint.
		if ( empty( $post_types ) ) {
			return true;
		}

		foreach ( $post_types as $post_type ) {
			if ( $this->post_translations->supports_post_type( $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reports whether an archive of untranslated post types stays constrained.
	 *
	 * A single post of an untranslated post type is one page in one language,
	 * and hiding it from every other language turns it into a 404. An archive is
	 * a list, and an empty list is a true answer: this language has nothing of
	 * this kind. Constraining it is what keeps an untranslated shop from reading
	 * as translated, and what makes the switcher's promise match the page.
	 *
	 * @param WP_Query $query Main frontend query.
	 * @return bool
	 */
	private function constrains_untranslated_archive( WP_Query $query ) {
		if ( ! $query->is_post_type_archive() && ! $query->is_tax() ) {
			return false;
		}

		/**
		 * Filters whether an archive whose post types are all untranslated is
		 * still limited to the requested language.
		 *
		 * Returning false leaves such an archive listing the same posts in every
		 * language, which is what a site wants when the content behind it is
		 * shared rather than written in one language.
		 *
		 * @param bool     $constrain Whether the archive stays constrained.
		 * @param WP_Query $query     Main frontend query.
		 */
		return (bool) apply_filters( 'localepress_constrain_untranslated_archive', true, $query );
	}

	/**
	 * Returns the post types a query reads from, the way WordPress resolves them.
	 *
	 * A query usually names its post type outright. A custom taxonomy archive
	 * does not: WordPress fills it in from the post types that registered the
	 * queried taxonomy, which is what makes `/de/product-category/shoes/` a
	 * product request even though nothing in it says so. Category and tag
	 * archives are not derived, because core resolves those to `post`.
	 *
	 * @param WP_Query $query Query object.
	 * @return array<int, string> Empty when the query asks for every post type.
	 */
	private function get_queried_post_types( WP_Query $query ) {
		$post_types = $query->get( 'post_type' );

		if ( 'any' === $post_types ) {
			return array();
		}

		if ( is_string( $post_types ) && '' !== $post_types ) {
			return array( $post_types );
		}

		if ( is_array( $post_types ) && array() !== $post_types ) {
			return array_values( array_filter( $post_types, 'is_string' ) );
		}

		return $query->is_tax() ? $this->get_taxonomy_archive_post_types( $query ) : array( 'post' );
	}

	/**
	 * Returns the post types a custom taxonomy archive reads from.
	 *
	 * This mirrors the fully inclusive search WordPress runs for the same
	 * request, so the answer is the set of post types the archive can actually
	 * return rather than a guess at them.
	 *
	 * @param WP_Query $query Query object.
	 * @return array<int, string>
	 */
	private function get_taxonomy_archive_post_types( WP_Query $query ) {
		$taxonomies = isset( $query->tax_query ) && $query->tax_query instanceof WP_Tax_Query
			? array_keys( (array) $query->tax_query->queried_terms )
			: array();

		if ( empty( $taxonomies ) ) {
			return array( 'post' );
		}

		$post_types = array();

		foreach ( get_post_types( array( 'exclude_from_search' => false ) ) as $post_type ) {
			$object_taxonomies = 'attachment' === $post_type
				? get_taxonomies_for_attachments()
				: get_object_taxonomies( $post_type );

			if ( array_intersect( $taxonomies, $object_taxonomies ) ) {
				$post_types[] = $post_type;
			}
		}

		return empty( $post_types ) ? array( 'post' ) : $post_types;
	}

	/**
	 * Reports whether the current request must bypass canonical prefix handling.
	 *
	 * @return bool
	 */
	private function is_excluded_request() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		return ! in_array( $request_method, array( 'GET', 'HEAD' ), true )
			|| is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| get_query_var( 'sitemap' )
			|| get_query_var( 'robots' )
			|| get_query_var( 'favicon' )
			|| $this->url_manager->is_excluded_url( $this->url_manager->get_current_request_url() );
	}

	/**
	 * Compares URLs after harmless trailing-slash normalization.
	 *
	 * @param string $first  First URL.
	 * @param string $second Second URL.
	 * @return bool
	 */
	private function same_url( $first, $second ) {
		return untrailingslashit( $first ) === untrailingslashit( $second );
	}

	/**
	 * Copies the current request query string to a canonical object URL.
	 *
	 * @param string $url Canonical object URL.
	 * @return string
	 */
	private function preserve_current_query_string( $url ) {
		$current = wp_parse_url( $this->url_manager->get_current_request_url() );

		if ( false === $current || empty( $current['query'] ) ) {
			return $url;
		}

		$query_args = array();
		wp_parse_str( $current['query'], $query_args );

		return empty( $query_args ) ? $url : add_query_arg( $query_args, $url );
	}
}
