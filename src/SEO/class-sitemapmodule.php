<?php
/**
 * WordPress core sitemap compatibility module.
 *
 * @package LocalePress
 */

namespace LocalePress\SEO;

use LocalePress\Content\LanguageQueryConstraint;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\Language\LanguageManager;
use LocalePress\Routing\LanguageUrlManager;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the core sitemap listing exactly the URLs a visitor can reach.
 *
 * Permalink filters already give every entry the prefix of the language its own
 * post or term is assigned to, so translations are listed under their own
 * routes without any work here. What the sitemap cannot resolve on its own is
 * content assigned to a language that is no longer enabled: such an item has no
 * route of its own, so its permalink falls back to the unprefixed URL, which
 * belongs to the default language. Listing it would submit the same address
 * twice under different content.
 *
 * Both providers are therefore constrained at query level, which is the only
 * place core allows an entry to be dropped.
 */
final class SitemapModule implements ModuleInterface {

	/**
	 * Query argument marking a query built by a sitemap provider.
	 */
	const QUERY_MARKER = 'localepress_sitemap_query';

	/**
	 * Language URL API.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Shared language SQL constraint.
	 *
	 * @var LanguageQueryConstraint
	 */
	private $constraint;

	/**
	 * Enabled language identifiers, resolved once per request.
	 *
	 * @var array<int, string>|null
	 */
	private $enabled_ids;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager $url_manager      Language URL API.
	 * @param LanguageManager    $language_manager Language manager.
	 */
	public function __construct( LanguageUrlManager $url_manager, LanguageManager $language_manager ) {
		$this->url_manager      = $url_manager;
		$this->language_manager = $language_manager;
		$this->constraint       = new LanguageQueryConstraint();
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->url_manager->supports_language_prefixes() ) {
			return;
		}

		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'mark_provider_query' ), 20 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'mark_provider_query' ), 20 );
		add_filter( 'posts_clauses', array( $this, 'filter_sitemap_posts' ), 20, 2 );
		add_filter( 'terms_clauses', array( $this, 'filter_sitemap_terms' ), 20, 3 );
	}

	/**
	 * Marks a provider's query arguments so its clauses can be recognized.
	 *
	 * Both providers hand these arguments straight to `WP_Query` and
	 * `get_terms()`, and both keep unrecognized arguments, so the marker is
	 * still present when the clauses are assembled.
	 *
	 * @param array<string, mixed>|mixed $args Query arguments.
	 * @return array<string, mixed>|mixed
	 */
	public function mark_provider_query( $args ) {
		if ( is_array( $args ) ) {
			$args[ self::QUERY_MARKER ] = true;
		}

		return $args;
	}

	/**
	 * Limits a sitemap post query to publicly reachable languages.
	 *
	 * @param array<string, string>|mixed $clauses SQL clauses.
	 * @param WP_Query|mixed              $query   Query being prepared.
	 * @return array<string, string>|mixed
	 */
	public function filter_sitemap_posts( $clauses, $query ) {
		if ( ! is_array( $clauses ) || ! $query instanceof WP_Query || ! $query->get( self::QUERY_MARKER ) ) {
			return $clauses;
		}

		$language_ids = $this->get_enabled_language_ids();

		return empty( $language_ids )
			? $clauses
			: $this->constraint->restrict_posts_to_languages( $clauses, $language_ids );
	}

	/**
	 * Limits a sitemap term query to publicly reachable languages.
	 *
	 * @param array<string, string>|mixed $clauses    SQL clauses.
	 * @param array<int, string>|mixed    $taxonomies Queried taxonomies.
	 * @param array<string, mixed>|mixed  $args       Term query arguments.
	 * @return array<string, string>|mixed
	 */
	public function filter_sitemap_terms( $clauses, $taxonomies, $args ) {
		unset( $taxonomies );

		if ( ! is_array( $clauses ) || ! is_array( $args ) || empty( $args[ self::QUERY_MARKER ] ) ) {
			return $clauses;
		}

		$language_ids = $this->get_enabled_language_ids();

		return empty( $language_ids )
			? $clauses
			: $this->constraint->restrict_terms_to_languages( $clauses, $language_ids );
	}

	/**
	 * Returns the identifiers of the languages this sitemap may list.
	 *
	 * One sitemap covers every language while they share a host, because they
	 * share an address to be listed under. Host routing gives each language its
	 * own, and a sitemap may only list URLs on the host that served it — a
	 * search engine treats anything else as a cross-domain submission and needs
	 * the other host verified separately before it will read it. So each host
	 * answers for itself, which is also the form a site owner submits: one
	 * sitemap per property, exactly as the property is registered.
	 *
	 * @return array<int, string>
	 */
	private function get_enabled_language_ids() {
		if ( null !== $this->enabled_ids ) {
			return $this->enabled_ids;
		}

		$ids = array();

		if ( $this->url_manager->uses_host_routing() ) {
			$current = $this->url_manager->get_current_language();
			$ids     = null === $current ? array() : array( (string) $current['id'] );
		} else {
			foreach ( $this->language_manager->get_languages( true ) as $language ) {
				if ( is_array( $language ) && isset( $language['id'] ) && is_scalar( $language['id'] ) ) {
					$ids[] = (string) $language['id'];
				}
			}
		}

		/**
		 * Filters the languages whose content may appear in the core sitemap.
		 *
		 * @param array<int, string> $ids Enabled language identifiers.
		 */
		$filtered = apply_filters( 'localepress_sitemap_language_ids', $ids );

		$this->enabled_ids = is_array( $filtered ) ? array_values( array_filter( array_map( 'strval', $filtered ) ) ) : $ids;

		return $this->enabled_ids;
	}
}
