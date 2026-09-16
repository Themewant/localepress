<?php
/**
 * Shared language constraint for post and term queries.
 *
 * @package LocalePress
 */

namespace LocalePress\Content;

use LocalePress\Infrastructure\DatabaseTermTranslationRepository;
use LocalePress\Infrastructure\DatabaseTranslationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the indexed assignment join that limits a query to one language.
 *
 * Frontend routing and the REST API both need the same constraint, so the SQL
 * lives here once. Queries for the default language also include content with no
 * stored assignment, which keeps a site that installed LocalePress later working
 * before every item has been assigned.
 */
final class LanguageQueryConstraint {

	/**
	 * SQL alias used for the post assignment join.
	 *
	 * @var string
	 */
	const POST_ALIAS = 'localepress_route_language';

	/**
	 * SQL alias used for the term assignment join.
	 *
	 * @var string
	 */
	const TERM_ALIAS = 'localepress_term_language';

	/**
	 * Constrains a post query to one language.
	 *
	 * @param array<string, string> $clauses             SQL clauses.
	 * @param string                $language_id         Requested language identifier.
	 * @param string                $default_language_id Default language identifier.
	 * @return array<string, string>
	 */
	public function apply_to_posts( array $clauses, $language_id, $default_language_id ) {
		if ( ! isset( $clauses['join'], $clauses['where'] ) || '' === (string) $language_id ) {
			return $clauses;
		}

		if ( false === strpos( $clauses['join'], self::POST_ALIAS ) ) {
			$clauses['join'] .= $this->posts_join_clause();
		}

		$clauses['where'] .= $this->posts_where_clause( $language_id, $default_language_id );

		return $clauses;
	}

	/**
	 * Returns the join that carries each post's language assignment.
	 *
	 * WordPress answers some pages without WP_Query — the adjacent post links,
	 * the archive list, the calendar — and each writes its own SQL against the
	 * posts table. They are handed this fragment rather than a clause array,
	 * and the one that aliases the posts table says so.
	 *
	 * @param string $posts_alias Name the posts table is addressed by, if not its own.
	 * @return string
	 */
	public function posts_join_clause( $posts_alias = '' ) {
		global $wpdb;

		$table       = DatabaseTranslationRepository::assignments_table();
		$alias       = self::POST_ALIAS;
		$posts_alias = is_string( $posts_alias ) && '' !== $posts_alias ? $posts_alias : $wpdb->posts;

		// Every name here is a WordPress or LocalePress identifier; none is input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return " LEFT JOIN {$table} AS {$alias} ON ({$posts_alias}.ID = {$alias}.post_id)";
	}

	/**
	 * Returns the language test as a `WHERE` continuation.
	 *
	 * @param string $language_id         Requested language identifier.
	 * @param string $default_language_id Default language identifier.
	 * @return string Empty when no language was asked for.
	 */
	public function posts_where_clause( $language_id, $default_language_id ) {
		$condition = $this->posts_language_condition( $language_id, $default_language_id );

		return '' === $condition ? '' : ' AND ' . $condition;
	}

	/**
	 * Returns the language test on its own, with nothing joining it to a clause.
	 *
	 * A query that already has a `WHERE` of its own and a trailing `GROUP BY`,
	 * `ORDER BY`, or `LIMIT` cannot have a condition appended to the end of it.
	 * Such a query is given this to put directly after its own `WHERE`.
	 *
	 * @param string $language_id         Requested language identifier.
	 * @param string $default_language_id Default language identifier.
	 * @return string Empty when no language was asked for.
	 */
	public function posts_language_condition( $language_id, $default_language_id ) {
		global $wpdb;

		if ( '' === (string) $language_id ) {
			return '';
		}

		if ( (string) $default_language_id === (string) $language_id ) {
			/*
			 * Content written before LocalePress carries no assignment at all,
			 * and the default language is the one it was written in.
			 */
			// The SQL alias is a fixed LocalePress identifier; only the value is variable.
			return $wpdb->prepare(
				'(localepress_route_language.language_id = %s OR localepress_route_language.post_id IS NULL)',
				$language_id
			);
		}

		// The SQL alias is a fixed LocalePress identifier; only the value is variable.
		return $wpdb->prepare(
			'localepress_route_language.language_id = %s',
			$language_id
		);
	}

	/**
	 * Constrains a post query to a set of languages, keeping unassigned posts.
	 *
	 * Used where "every publicly reachable language" is the rule rather than one
	 * request language, so content assigned to a disabled language is excluded
	 * while content that predates LocalePress still qualifies.
	 *
	 * @param array<string, string> $clauses      SQL clauses.
	 * @param array<int, string>    $language_ids Allowed language identifiers.
	 * @return array<string, string>
	 */
	public function restrict_posts_to_languages( array $clauses, array $language_ids ) {
		global $wpdb;

		$language_ids = array_values( array_unique( array_filter( array_map( 'strval', $language_ids ) ) ) );

		if ( ! isset( $clauses['join'], $clauses['where'] ) || empty( $language_ids ) ) {
			return $clauses;
		}

		if ( false === strpos( $clauses['join'], self::POST_ALIAS ) ) {
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN %i AS localepress_route_language ON ({$wpdb->posts}.ID = localepress_route_language.post_id)",
				DatabaseTranslationRepository::assignments_table()
			);
		}

		/*
		 * The placeholder list is built inside the call rather than beside it:
		 * one `%s` per identifier, so the statement carries no value of its own.
		 */
		$clauses['where'] .= $wpdb->prepare(
			' AND (localepress_route_language.language_id IN ('
				. implode( ', ', array_fill( 0, count( $language_ids ), '%s' ) )
				. ') OR localepress_route_language.post_id IS NULL)',
			$language_ids
		);

		return $clauses;
	}

	/**
	 * Constrains a term query to a set of languages, keeping unassigned terms.
	 *
	 * @param array<string, string> $clauses      SQL clauses.
	 * @param array<int, string>    $language_ids Allowed language identifiers.
	 * @return array<string, string>
	 */
	public function restrict_terms_to_languages( array $clauses, array $language_ids ) {
		global $wpdb;

		$language_ids = array_values( array_unique( array_filter( array_map( 'strval', $language_ids ) ) ) );

		if ( ! isset( $clauses['join'], $clauses['where'] ) || empty( $language_ids ) ) {
			return $clauses;
		}

		if ( false === strpos( $clauses['join'], self::TERM_ALIAS ) ) {
			$clauses['join'] .= $wpdb->prepare(
				' LEFT JOIN %i AS localepress_term_language ON (tt.term_taxonomy_id = localepress_term_language.term_taxonomy_id)',
				DatabaseTermTranslationRepository::assignments_table()
			);
		}

		/** This placeholder list is built the way restrict_posts_to_languages() explains. */
		$clauses['where'] .= $wpdb->prepare(
			' AND (localepress_term_language.language_id IN ('
				. implode( ', ', array_fill( 0, count( $language_ids ), '%s' ) )
				. ') OR localepress_term_language.term_taxonomy_id IS NULL)',
			$language_ids
		);

		return $clauses;
	}

	/**
	 * Constrains a term query to one language.
	 *
	 * WP_Term_Query always aliases the term taxonomy table as `tt`, which carries
	 * the identifier the term assignment table is keyed by.
	 *
	 * @param array<string, string> $clauses             SQL clauses.
	 * @param string                $language_id         Requested language identifier.
	 * @param string                $default_language_id Default language identifier.
	 * @return array<string, string>
	 */
	public function apply_to_terms( array $clauses, $language_id, $default_language_id ) {
		global $wpdb;

		if ( ! isset( $clauses['join'], $clauses['where'] ) || '' === (string) $language_id ) {
			return $clauses;
		}

		$table = DatabaseTermTranslationRepository::assignments_table();
		$alias = self::TERM_ALIAS;

		if ( false === strpos( $clauses['join'], $alias ) ) {
			// The table name is generated exclusively by the repository and WordPress prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$clauses['join'] .= " LEFT JOIN {$table} AS {$alias} ON (tt.term_taxonomy_id = {$alias}.term_taxonomy_id)";
		}

		if ( (string) $default_language_id === (string) $language_id ) {
			// The SQL alias is a fixed LocalePress identifier; only the value is variable.
			$clauses['where'] .= $wpdb->prepare(
				' AND (localepress_term_language.language_id = %s OR localepress_term_language.term_taxonomy_id IS NULL)',
				$language_id
			);
		} else {
			// The SQL alias is a fixed LocalePress identifier; only the value is variable.
			$clauses['where'] .= $wpdb->prepare(
				' AND localepress_term_language.language_id = %s',
				$language_id
			);
		}

		return $clauses;
	}

	/**
	 * Reports whether one language has anything to show for a set of post types.
	 *
	 * An archive is a route, not a translatable object: a language cannot be
	 * offered one because a matching row exists, only because the archive would
	 * hold something once the language filter runs. Asking that question here
	 * keeps it on the same rule the filter itself applies, including the part
	 * where unassigned content answers for the default language.
	 *
	 * Existence is all the caller needs, so the query stops at the first row
	 * rather than counting every one of them.
	 *
	 * @param array<int, string> $post_types          Post types the archive lists.
	 * @param string             $language_id         Language being offered.
	 * @param string             $default_language_id Default language identifier.
	 * @return bool
	 */
	public function has_posts_in_language( array $post_types, $language_id, $default_language_id ) {
		global $wpdb;

		$post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) );

		if ( empty( $post_types ) || '' === (string) $language_id ) {
			return false;
		}

		/*
		 * The table name arrives as an identifier placeholder and every value as
		 * its own, so the two forms of the language test are written out in full
		 * rather than assembled: a statement built from fragments cannot be read
		 * as the statement it becomes.
		 */
		$values = array_merge(
			array( DatabaseTranslationRepository::assignments_table() ),
			$post_types,
			array( (string) $language_id )
		);

		if ( (string) $language_id === (string) $default_language_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return null !== $wpdb->get_var(
				$wpdb->prepare(
					"SELECT {$wpdb->posts}.ID FROM {$wpdb->posts}
					LEFT JOIN %i AS localepress_route_language ON ({$wpdb->posts}.ID = localepress_route_language.post_id)
					WHERE {$wpdb->posts}.post_status = 'publish'
					AND {$wpdb->posts}.post_type IN (" . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ')
					AND (localepress_route_language.language_id = %s OR localepress_route_language.post_id IS NULL)
					LIMIT 1',
					$values
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$wpdb->posts}.ID FROM {$wpdb->posts}
				LEFT JOIN %i AS localepress_route_language ON ({$wpdb->posts}.ID = localepress_route_language.post_id)
				WHERE {$wpdb->posts}.post_status = 'publish'
				AND {$wpdb->posts}.post_type IN (" . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ')
				AND localepress_route_language.language_id = %s
				LIMIT 1',
				$values
			)
		);
	}

	/**
	 * Reports whether the site publishes anything at all in one language.
	 *
	 * This asks a wider question than has_posts_in_language(): not "is there
	 * something here", but "does this site speak this language". A reader on an
	 * untranslated cart page is still served by a switcher that offers a language
	 * the site genuinely publishes in, while a language nothing has been written
	 * in yet has nothing to offer and can leave.
	 *
	 * @param string $language_id         Target language identifier.
	 * @param string $default_language_id Default language identifier.
	 * @return bool
	 */
	public function has_any_post_in_language( $language_id, $default_language_id ) {
		global $wpdb;

		if ( '' === (string) $language_id ) {
			return false;
		}

		// Everything written before a language was ever assigned belongs to the
		// default one, so the site always speaks it.
		if ( (string) $language_id === (string) $default_language_id ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT assignment.post_id FROM %i AS assignment
				INNER JOIN {$wpdb->posts} AS post ON (post.ID = assignment.post_id)
				WHERE assignment.language_id = %s
				AND post.post_status = 'publish'
				LIMIT 1",
				DatabaseTranslationRepository::assignments_table(),
				(string) $language_id
			)
		);
	}
}
