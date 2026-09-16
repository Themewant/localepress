<?php
/**
 * Language-aware REST requests for the block editor.
 *
 * @package LocalePress
 */

namespace LocalePress\Rest;

use LocalePress\Assets;
use LocalePress\Content\LanguageQueryConstraint;
use LocalePress\Content\PostTranslationManager;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\Language\LanguageManager;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Taxonomy\TermTranslationManager;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Scopes editor REST collections to the language of the post being edited.
 *
 * The block editor loads parent pages, categories, tags, and link search results
 * over the REST API. Without a language those lists mix every language, so an
 * editor working in one language is offered content from another. A small
 * `apiFetch` middleware appends the edited post's language to the requests that
 * can be filtered, and this module applies it on the server.
 *
 * Only collections that were explicitly marked are constrained, so REST requests
 * from other clients keep their existing behavior.
 */
final class RestLanguageModule implements ModuleInterface {

	/**
	 * Query variable carrying the requested language.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'localepress_rest_language';

	/**
	 * Editor script handle.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'localepress-block-editor';

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation relationship manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Shared language query constraint.
	 *
	 * @var LanguageQueryConstraint
	 */
	private $constraint;

	/**
	 * Language URL service, which owns the language of the running request.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Language each dispatch displaced, keyed by the request that displaced it.
	 *
	 * @var array<string, string>
	 */
	private $displaced = array();

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations,
		LanguageManager $language_manager,
		LanguageUrlManager $url_manager
	) {
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
		$this->language_manager  = $language_manager;
		$this->url_manager       = $url_manager;
		$this->constraint        = new LanguageQueryConstraint();
	}

	/**
	 * Returns the language the running REST call is answering in.
	 *
	 * @return string Empty when the call named no language.
	 */
	private function request_language_id() {
		return $this->url_manager->background()->resolve_language_id();
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_collection_filters' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'capture_request_language' ), 10, 3 );

		add_filter( 'rest_request_after_callbacks', array( $this, 'release_request_language' ), 10000, 3 );
		add_filter( 'posts_clauses', array( $this, 'filter_posts_by_language' ), 10, 2 );
		add_filter( 'terms_clauses', array( $this, 'filter_terms_by_language' ), 10, 3 );
		add_filter( 'block_editor_rest_api_preload_paths', array( $this, 'add_language_to_preload_paths' ), 50, 2 );

		add_filter( 'localepress_new_term_language_id', array( $this, 'filter_new_term_language' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_script' ) );
	}

	/**
	 * Marks the REST collections that may be constrained by language.
	 *
	 * @return void
	 */
	public function register_collection_filters() {
		foreach ( $this->post_translations->get_supported_post_types() as $post_type ) {
			add_filter( "rest_{$post_type}_query", array( $this, 'mark_collection_args' ) );
		}

		foreach ( $this->term_translations->get_supported_taxonomies() as $taxonomy ) {
			add_filter( "rest_{$taxonomy}_query", array( $this, 'mark_collection_args' ) );
		}

		add_filter( 'rest_post_search_query', array( $this, 'mark_collection_args' ) );
	}

	/**
	 * Makes the language a REST call asked for the language it is answered in.
	 *
	 * The language is recorded where every other part of LocalePress already
	 * looks for it, so a REST request answers the way a page in that language
	 * does: its strings, its locale, its home URL, and the listings any block it
	 * renders builds for itself.
	 *
	 * A dispatch that names no language leaves the one already in place alone.
	 * Anything may dispatch a REST request of its own while one is running — a
	 * block that hydrates itself, a controller that reads another route — and
	 * clearing the language for that inner call would leave the outer request
	 * unscoped for the rest of its life.
	 *
	 * @param mixed            $result  Response to replace the requested version with.
	 * @param mixed            $server  REST server instance.
	 * @param \WP_REST_Request $request Dispatched request.
	 * @return mixed Untouched result.
	 */
	public function capture_request_language( $result, $server, $request ) {
		unset( $server );

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return $result;
		}

		$requested = $request->get_param( 'lang' );

		if ( ! is_scalar( $requested ) || '' === (string) $requested ) {
			return $result;
		}

		$language_id = $this->resolve_language_id( (string) $requested );

		if ( '' === $language_id ) {
			/*
			 * A call that named a language and named it wrong is not a call that
			 * named none. Answering it with every language at once would be a
			 * wrong answer wearing the shape of a complete one, so it is answered
			 * with the language the site answers in when nothing else is known.
			 */
			$language_id = $this->language_manager->get_default_id();
		}

		if ( '' === $language_id ) {
			return $result;
		}

		$background = $this->url_manager->background();

		if ( $language_id === $background->get_override() ) {
			return $result;
		}

		$this->displaced[ spl_object_hash( $request ) ] = $background->set_override( $language_id );

		$this->announce_language( $language_id );

		return $result;
	}

	/**
	 * Puts back the language the finished dispatch displaced.
	 *
	 * Restoration is keyed by the request itself rather than by nesting order,
	 * so a dispatch that never reaches its callbacks cannot put back a language
	 * that belongs to a different call.
	 *
	 * @param mixed            $response Response being returned.
	 * @param mixed            $handler  Route handler that ran.
	 * @param \WP_REST_Request $request  Request that has finished.
	 * @return mixed Untouched response.
	 */
	public function release_request_language( $response, $handler, $request ) {
		unset( $handler );

		if ( ! is_object( $request ) ) {
			return $response;
		}

		$key = spl_object_hash( $request );

		if ( ! isset( $this->displaced[ $key ] ) ) {
			return $response;
		}

		$restored = $this->displaced[ $key ];
		unset( $this->displaced[ $key ] );

		$this->url_manager->background()->set_override( $restored );
		$this->announce_language( $restored );

		return $response;
	}

	/**
	 * Tells the rest of the plugin that the running request changed language.
	 *
	 * Most language lookups are answered on demand and need no warning. The
	 * locale is the exception: WordPress loads its text domains once, long
	 * before a REST body can be read, so whatever loaded them has to be told
	 * when the answer changes underneath it.
	 *
	 * @param string $language_id Language the request now answers in.
	 * @return void
	 */
	private function announce_language( $language_id ) {
		/**
		 * Fires when a REST dispatch changes the language the request answers in.
		 *
		 * @param string $language_id Language identifier, empty when cleared.
		 */
		do_action( 'localepress_request_language_changed', $language_id );
	}

	/**
	 * Records the captured language on a filterable REST collection.
	 *
	 * @param array<string, mixed> $args Prepared query arguments.
	 * @return array<string, mixed>
	 */
	public function mark_collection_args( $args ) {
		$language_id = $this->request_language_id();

		if ( ! is_array( $args ) || '' === $language_id ) {
			return $args;
		}

		$args[ self::QUERY_VAR ] = $language_id;

		return $args;
	}

	/**
	 * Starts a term created during a REST request in that request's language.
	 *
	 * @param string $language_id Language identifier resolved so far.
	 * @return string
	 */
	public function filter_new_term_language( $language_id ) {
		$requested = $this->request_language_id();

		return '' === $requested ? $language_id : $requested;
	}

	/**
	 * Constrains a marked REST post collection to the requested language.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param WP_Query              $query   Current query.
	 * @return array<string, string>
	 */
	public function filter_posts_by_language( $clauses, $query ) {
		if ( ! $query instanceof WP_Query ) {
			return $clauses;
		}

		$language_id = $query->get( self::QUERY_VAR );

		if ( ! is_string( $language_id ) || '' === $language_id ) {
			return $clauses;
		}

		return $this->constrain( $clauses, $language_id, $query, 'posts' );
	}

	/**
	 * Constrains a marked REST term collection to the requested language.
	 *
	 * @param array<string, string> $clauses    SQL clauses.
	 * @param array<int, string>    $taxonomies Queried taxonomies.
	 * @param array<string, mixed>  $args       Term query arguments.
	 * @return array<string, string>
	 */
	public function filter_terms_by_language( $clauses, $taxonomies, $args ) {
		unset( $taxonomies );

		if ( is_array( $args ) && ! empty( $args['object_ids'] ) ) {
			return $clauses;
		}

		$language_id = is_array( $args ) && isset( $args[ self::QUERY_VAR ] ) && is_string( $args[ self::QUERY_VAR ] )
			? $args[ self::QUERY_VAR ]
			: '';

		if ( '' === $language_id ) {
			return $clauses;
		}

		return $this->constrain( $clauses, $language_id, $args, 'terms' );
	}

	/**
	 * Adds the edited post's language to the editor's preloaded REST paths.
	 *
	 * Preloaded responses are served from the page itself, so a path without the
	 * language would hand the editor an unfiltered list before the middleware ever
	 * runs.
	 *
	 * @param array<int, mixed> $preload_paths Paths the editor preloads.
	 * @param mixed             $context       Block editor context.
	 * @return array<int, mixed>
	 */
	public function add_language_to_preload_paths( $preload_paths, $context ) {
		if ( ! is_array( $preload_paths ) || ! is_object( $context ) || ! isset( $context->post ) ) {
			return $preload_paths;
		}

		$post = $context->post;

		if ( ! $post instanceof \WP_Post || ! $this->post_translations->supports_post_type( $post->post_type ) ) {
			return $preload_paths;
		}

		$language_id = $this->post_translations->get_post_language_id( $post->ID );

		if ( '' === $language_id ) {

			$language_id = $this->language_manager->get_default_id();
		}

		if ( '' === $language_id ) {
			return $preload_paths;
		}

		$routes = $this->get_filterable_routes();

		foreach ( $preload_paths as $index => $path ) {
			$is_pair = is_array( $path );
			$target  = $is_pair && isset( $path[0] ) && is_string( $path[0] ) ? $path[0] : $path;

			if ( ! is_string( $target ) || '' === $target ) {
				continue;
			}

			$parts = wp_parse_url( $target );

			if ( ! isset( $parts['path'] ) || ! in_array( trim( $parts['path'], '/' ), $routes, true ) ) {
				continue;
			}

			$params = array();

			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $params );
			}

			$params['lang'] = $language_id;

			ksort( $params );
			$rebuilt = add_query_arg( urlencode_deep( $params ), $parts['path'] );

			if ( $is_pair ) {
				$preload_paths[ $index ][0] = $rebuilt;
			} else {
				$preload_paths[ $index ] = $rebuilt;
			}
		}

		return $preload_paths;
	}

	/**
	 * Loads the middleware that appends the language to editor REST requests.
	 *
	 * @return void
	 */
	public function enqueue_editor_script() {
		$routes = $this->get_filterable_routes();

		if ( empty( $routes ) || ! $this->language_manager->has_languages() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			LOCALEPRESS_URL . 'assets/js/block-editor.js',
			array( 'wp-api-fetch' ),
			Assets::version( 'assets/js/block-editor.js' ),
			true
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.localePressEditor = ' . wp_json_encode(
				array(
					'routes'          => array_values( $routes ),
					'defaultLanguage' => $this->language_manager->get_default_id(),
					'field'           => 'localepress_language_id',
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Returns the REST routes that accept a language.
	 *
	 * @return array<int, string>
	 */
	public function get_filterable_routes() {
		$routes = array();

		foreach ( $this->post_translations->get_supported_post_types() as $post_type ) {
			$route = $this->get_rest_route( get_post_type_object( $post_type ) );

			if ( '' !== $route ) {
				$routes[] = $route;
			}
		}

		foreach ( $this->term_translations->get_supported_taxonomies() as $taxonomy ) {
			$route = $this->get_rest_route( get_taxonomy( $taxonomy ) );

			if ( '' !== $route ) {
				$routes[] = $route;
			}
		}

		$routes[] = 'wp/v2/search';

		/**
		 * Filters the REST routes the editor tags with a language.
		 *
		 * @param array<int, string> $routes Route paths without a leading slash.
		 */
		$filtered = apply_filters( 'localepress_language_rest_routes', array_values( array_unique( $routes ) ) );

		return is_array( $filtered ) ? array_values( array_unique( array_filter( $filtered, 'is_string' ) ) ) : $routes;
	}

	/**
	 * Builds the collection route for a post type or taxonomy object.
	 *
	 * @param mixed $type_object Post type or taxonomy object.
	 * @return string
	 */
	private function get_rest_route( $type_object ) {
		if ( ! is_object( $type_object ) || empty( $type_object->show_in_rest ) ) {
			return '';
		}

		$base      = ! empty( $type_object->rest_base ) && is_string( $type_object->rest_base ) ? $type_object->rest_base : $type_object->name;
		$namespace = ! empty( $type_object->rest_namespace ) && is_string( $type_object->rest_namespace ) ? $type_object->rest_namespace : 'wp/v2';

		return trim( $namespace, '/' ) . '/' . trim( (string) $base, '/' );
	}

	/**
	 * Applies the shared constraint after letting developers opt out.
	 *
	 * @param array<string, string>        $clauses     SQL clauses.
	 * @param string                       $language_id Requested language identifier.
	 * @param WP_Query|array<string,mixed> $query     Query or arguments being filtered.
	 * @param string                       $type        Either posts or terms.
	 * @return array<string, string>
	 */
	private function constrain( $clauses, $language_id, $query, $type ) {
		if ( ! is_array( $clauses ) ) {
			return $clauses;
		}

		/**
		 * Filters whether a REST collection should be constrained by language.
		 *
		 * @param bool                          $filter      Whether filtering should run.
		 * @param string                        $language_id Requested language identifier.
		 * @param WP_Query|array<string, mixed> $query       Query or arguments being filtered.
		 * @param string                        $type        Either posts or terms.
		 */
		if ( ! apply_filters( 'localepress_filter_rest_query_by_language', true, $language_id, $query, $type ) ) {
			return $clauses;
		}

		$default_id = $this->language_manager->get_default_id();

		return 'terms' === $type
			? $this->constraint->apply_to_terms( $clauses, $language_id, $default_id )
			: $this->constraint->apply_to_posts( $clauses, $language_id, $default_id );
	}

	/**
	 * Resolves a requested language to a stored identifier.
	 *
	 * The editor sends the identifier stored on the post. A URL slug, a language
	 * code, and a WordPress locale are accepted too, because a client that is
	 * not the editor holds one of those rather than an identifier only
	 * LocalePress knows about.
	 *
	 * @param string $requested Requested language identifier, slug, code, or locale.
	 * @return string
	 */
	private function resolve_language_id( $requested ) {
		return $this->url_manager->resolve_language_id( $requested );
	}
}
