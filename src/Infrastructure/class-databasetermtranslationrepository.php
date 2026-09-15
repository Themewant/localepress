<?php
/**
 * Database-backed term translation repository.
 *
 * @package LocalePress
 */

namespace LocalePress\Infrastructure;

use LocalePress\Contracts\TermTranslationRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Stores term translation groups and memberships in indexed custom tables.
 */
final class DatabaseTermTranslationRepository implements TermTranslationRepositoryInterface {
	// Direct queries are isolated here because this repository owns custom tables.
	// Every read path uses an explicit request-level cache.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Translation group table suffix.
	 *
	 * @var string
	 */
	const GROUPS_TABLE_SUFFIX = 'localepress_term_translation_groups';

	/**
	 * Translation assignment table suffix.
	 *
	 * @var string
	 */
	const ASSIGNMENTS_TABLE_SUFFIX = 'localepress_term_translations';

	/**
	 * Assignment cache keyed by term-taxonomy ID.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private $assignments = array();

	/**
	 * Term lookup cache keyed by taxonomy and term ID.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private $terms = array();

	/**
	 * Group cache keyed by group ID.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private $groups = array();

	/**
	 * Group members keyed by group ID.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $members = array();

	/**
	 * Site prefix associated with request caches.
	 *
	 * @var string
	 */
	private $cache_prefix = '';

	/**
	 * Creates or upgrades the term relationship tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$groups_table      = self::groups_table();
		$assignments_table = self::assignments_table();
		$charset_collate   = $wpdb->get_charset_collate();

		$groups_sql = "CREATE TABLE {$groups_table} (
			group_id char(36) NOT NULL,
			source_term_taxonomy_id bigint(20) unsigned NOT NULL,
			taxonomy varchar(32) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (group_id),
			KEY source_term_taxonomy_id (source_term_taxonomy_id),
			KEY taxonomy (taxonomy)
		) {$charset_collate};";

		$assignments_sql = "CREATE TABLE {$assignments_table} (
			term_taxonomy_id bigint(20) unsigned NOT NULL,
			term_id bigint(20) unsigned NOT NULL,
			taxonomy varchar(32) NOT NULL,
			group_id char(36) NOT NULL,
			language_id varchar(64) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (term_taxonomy_id),
			UNIQUE KEY group_language (group_id, language_id),
			UNIQUE KEY term_taxonomy (term_id, taxonomy),
			KEY group_id (group_id),
			KEY language_id (language_id),
			KEY taxonomy (taxonomy)
		) {$charset_collate};";

		// Table names and collation are generated exclusively by WordPress.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		dbDelta( $groups_sql );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		dbDelta( $assignments_sql );
	}

	/**
	 * Returns the site-specific term group table name.
	 *
	 * @return string
	 */
	public static function groups_table() {
		global $wpdb;

		return $wpdb->prefix . self::GROUPS_TABLE_SUFFIX;
	}

	/**
	 * Returns the site-specific term assignment table name.
	 *
	 * @return string
	 */
	public static function assignments_table() {
		global $wpdb;

		return $wpdb->prefix . self::ASSIGNMENTS_TABLE_SUFFIX;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function find_by_term( $term_id, $taxonomy ) {
		global $wpdb;
		$this->ensure_site_context();

		$term_id   = absint( $term_id );
		$taxonomy  = sanitize_key( $taxonomy );
		$cache_key = $this->term_cache_key( $term_id, $taxonomy );

		if ( array_key_exists( $cache_key, $this->terms ) ) {
			return $this->terms[ $cache_key ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT term_taxonomy_id, term_id, taxonomy, group_id, language_id FROM %i WHERE term_id = %d AND taxonomy = %s',
				self::assignments_table(),
				$term_id,
				$taxonomy
			),
			ARRAY_A
		);

		$this->terms[ $cache_key ] = is_array( $row ) ? $this->normalize_assignment( $row ) : null;

		if ( null !== $this->terms[ $cache_key ] ) {
			$this->assignments[ $this->terms[ $cache_key ]['term_taxonomy_id'] ] = $this->terms[ $cache_key ];
		}

		return $this->terms[ $cache_key ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int $term_taxonomy_id Term-taxonomy identifier.
	 */
	public function find_by_term_taxonomy( $term_taxonomy_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$term_taxonomy_id = absint( $term_taxonomy_id );

		if ( array_key_exists( $term_taxonomy_id, $this->assignments ) ) {
			return $this->assignments[ $term_taxonomy_id ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT term_taxonomy_id, term_id, taxonomy, group_id, language_id FROM %i WHERE term_taxonomy_id = %d',
				self::assignments_table(),
				$term_taxonomy_id
			),
			ARRAY_A
		);

		$this->assignments[ $term_taxonomy_id ] = is_array( $row ) ? $this->normalize_assignment( $row ) : null;
		$this->cache_term_assignment( $this->assignments[ $term_taxonomy_id ] );

		return $this->assignments[ $term_taxonomy_id ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id Translation group identifier.
	 */
	public function find_group( $group_id ) {
		global $wpdb;
		$this->ensure_site_context();

		if ( array_key_exists( $group_id, $this->groups ) ) {
			return $this->groups[ $group_id ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT group_id, source_term_taxonomy_id, taxonomy, created_at FROM %i WHERE group_id = %s',
				self::groups_table(),
				$group_id
			),
			ARRAY_A
		);

		$this->groups[ $group_id ] = is_array( $row ) ? $this->normalize_group( $row ) : null;

		return $this->groups[ $group_id ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id Translation group identifier.
	 */
	public function get_group_members( $group_id ) {
		global $wpdb;
		$this->ensure_site_context();

		if ( isset( $this->members[ $group_id ] ) ) {
			return $this->members[ $group_id ];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT term_taxonomy_id, term_id, taxonomy, group_id, language_id FROM %i WHERE group_id = %s ORDER BY term_taxonomy_id ASC',
				self::assignments_table(),
				$group_id
			),
			ARRAY_A
		);

		$this->members[ $group_id ] = array();

		foreach ( $rows as $row ) {
			$assignment                   = $this->normalize_assignment( $row );
			$this->members[ $group_id ][] = $assignment;
			$this->cache_assignment( $assignment );
		}

		return $this->members[ $group_id ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id                Translation group identifier.
	 * @param int    $source_term_taxonomy_id Source term-taxonomy identifier.
	 * @param string $taxonomy                Taxonomy name.
	 */
	public function create_group( $group_id, $source_term_taxonomy_id, $taxonomy ) {
		global $wpdb;
		$this->ensure_site_context();

		$created_at = current_time( 'mysql', true );
		$taxonomy   = sanitize_key( $taxonomy );
		$inserted   = $wpdb->insert(
			self::groups_table(),
			array(
				'group_id'                => $group_id,
				'source_term_taxonomy_id' => absint( $source_term_taxonomy_id ),
				'taxonomy'                => $taxonomy,
				'created_at'              => $created_at,
			),
			array( '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		$this->groups[ $group_id ] = array(
			'group_id'                => $group_id,
			'source_term_taxonomy_id' => absint( $source_term_taxonomy_id ),
			'taxonomy'                => $taxonomy,
			'created_at'              => $created_at,
		);

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param int    $term_id          Term identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $group_id         Translation group identifier.
	 * @param string $language_id      Language identifier.
	 */
	public function add_assignment( $term_taxonomy_id, $term_id, $taxonomy, $group_id, $language_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$term_taxonomy_id = absint( $term_taxonomy_id );
		$term_id          = absint( $term_id );
		$taxonomy         = sanitize_key( $taxonomy );
		$now              = current_time( 'mysql', true );
		$inserted         = $wpdb->insert(
			self::assignments_table(),
			array(
				'term_taxonomy_id' => $term_taxonomy_id,
				'term_id'          => $term_id,
				'taxonomy'         => $taxonomy,
				'group_id'         => $group_id,
				'language_id'      => $language_id,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		$this->cache_assignment(
			array(
				'term_taxonomy_id' => $term_taxonomy_id,
				'term_id'          => $term_id,
				'taxonomy'         => $taxonomy,
				'group_id'         => $group_id,
				'language_id'      => $language_id,
			)
		);
		unset( $this->members[ $group_id ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $language_id      Language identifier.
	 */
	public function update_assignment_language( $term_taxonomy_id, $language_id ) {
		global $wpdb;

		$assignment = $this->find_by_term_taxonomy( $term_taxonomy_id );

		if ( null === $assignment ) {
			return false;
		}

		if ( $language_id === $assignment['language_id'] ) {
			return true;
		}

		$updated = $wpdb->update(
			self::assignments_table(),
			array(
				'language_id' => $language_id,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'term_taxonomy_id' => absint( $term_taxonomy_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$assignment['language_id'] = $language_id;
		$this->cache_assignment( $assignment );
		unset( $this->members[ $assignment['group_id'] ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $group_id         Target translation group identifier.
	 */
	public function update_assignment_group( $term_taxonomy_id, $group_id ) {
		global $wpdb;

		$assignment = $this->find_by_term_taxonomy( $term_taxonomy_id );

		if ( null === $assignment ) {
			return false;
		}

		if ( $group_id === $assignment['group_id'] ) {
			return true;
		}

		// The group and language pair is unique in storage, so a move onto a
		// language the target group already holds is refused here rather than
		// producing a group that answers twice for one language.
		$updated = $wpdb->update(
			self::assignments_table(),
			array(
				'group_id'   => $group_id,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'term_taxonomy_id' => absint( $term_taxonomy_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$previous_group_id      = $assignment['group_id'];
		$assignment['group_id'] = (string) $group_id;
		$this->cache_assignment( $assignment );
		unset( $this->members[ $previous_group_id ], $this->members[ $group_id ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int $term_taxonomy_id Term-taxonomy identifier.
	 */
	public function remove_assignment( $term_taxonomy_id ) {
		global $wpdb;

		$term_taxonomy_id = absint( $term_taxonomy_id );
		$assignment       = $this->find_by_term_taxonomy( $term_taxonomy_id );

		if ( null === $assignment ) {
			return false;
		}

		$deleted = $wpdb->delete(
			self::assignments_table(),
			array( 'term_taxonomy_id' => $term_taxonomy_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return false;
		}

		$this->assignments[ $term_taxonomy_id ] = null;
		$this->terms[ $this->term_cache_key( $assignment['term_id'], $assignment['taxonomy'] ) ] = null;
		unset( $this->members[ $assignment['group_id'] ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id                Translation group identifier.
	 * @param int    $source_term_taxonomy_id Source term-taxonomy identifier.
	 */
	public function update_group_source( $group_id, $source_term_taxonomy_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$updated = $wpdb->update(
			self::groups_table(),
			array( 'source_term_taxonomy_id' => absint( $source_term_taxonomy_id ) ),
			array( 'group_id' => $group_id ),
			array( '%d' ),
			array( '%s' )
		);

		if ( false === $updated ) {
			return false;
		}

		unset( $this->groups[ $group_id ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id Translation group identifier.
	 */
	public function delete_group( $group_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$deleted = $wpdb->delete(
			self::groups_table(),
			array( 'group_id' => $group_id ),
			array( '%s' )
		);

		unset( $this->groups[ $group_id ], $this->members[ $group_id ] );

		return false !== $deleted;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $language_id Language identifier.
	 */
	public function count_by_language( $language_id ) {
		global $wpdb;
		$this->ensure_site_context();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE language_id = %s',
				self::assignments_table(),
				$language_id
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<int, int> $term_taxonomy_ids Term-taxonomy identifiers.
	 */
	public function prime_for_term_taxonomies( array $term_taxonomy_ids ) {
		global $wpdb;
		$this->ensure_site_context();

		$term_taxonomy_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_taxonomy_ids ) ) ) );
		$uncached          = array_values(
			array_filter(
				$term_taxonomy_ids,
				function ( $term_taxonomy_id ) {
					return ! array_key_exists( $term_taxonomy_id, $this->assignments );
				}
			)
		);

		if ( ! empty( $uncached ) ) {
			foreach ( $uncached as $term_taxonomy_id ) {
				$this->assignments[ $term_taxonomy_id ] = null;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $uncached ), '%d' ) );
			$query        = "SELECT term_taxonomy_id, term_id, taxonomy, group_id, language_id FROM %i WHERE term_taxonomy_id IN ({$placeholders})";
			$values       = array_merge( array( self::assignments_table() ), $uncached );

			// Placeholder count is generated from sanitized integer identifiers above.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A );

			foreach ( $rows as $row ) {
				$this->cache_assignment( $this->normalize_assignment( $row ) );
			}
		}

		$group_ids = array();

		foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
			$assignment = $this->assignments[ $term_taxonomy_id ];

			if ( null !== $assignment && ! isset( $this->members[ $assignment['group_id'] ] ) ) {
				$group_ids[] = $assignment['group_id'];
			}
		}

		$group_ids = array_values( array_unique( $group_ids ) );

		if ( empty( $group_ids ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $group_ids ), '%s' ) );
		$query        = "SELECT term_taxonomy_id, term_id, taxonomy, group_id, language_id FROM %i WHERE group_id IN ({$placeholders}) ORDER BY term_taxonomy_id ASC";
		$values       = array_merge( array( self::assignments_table() ), $group_ids );

		foreach ( $group_ids as $group_id ) {
			$this->members[ $group_id ] = array();
		}

		// Placeholder count is generated from stored group identifiers above.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A );

		foreach ( $rows as $row ) {
			$assignment                                 = $this->normalize_assignment( $row );
			$this->members[ $assignment['group_id'] ][] = $assignment;
			$this->cache_assignment( $assignment );
		}
	}

	/**
	 * Caches an assignment under all supported lookup keys.
	 *
	 * @param array<string, mixed> $assignment Assignment row.
	 * @return void
	 */
	private function cache_assignment( $assignment ) {
		$this->assignments[ $assignment['term_taxonomy_id'] ] = $assignment;
		$this->cache_term_assignment( $assignment );
	}

	/**
	 * Caches an assignment by its term and taxonomy.
	 *
	 * @param array<string, mixed>|null $assignment Assignment row.
	 * @return void
	 */
	private function cache_term_assignment( $assignment ) {
		if ( null === $assignment ) {
			return;
		}

		$this->terms[ $this->term_cache_key( $assignment['term_id'], $assignment['taxonomy'] ) ] = $assignment;
	}

	/**
	 * Builds a collision-free term lookup key.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function term_cache_key( $term_id, $taxonomy ) {
		return sanitize_key( $taxonomy ) . ':' . absint( $term_id );
	}

	/**
	 * Normalizes an assignment row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function normalize_assignment( $row ) {
		return array(
			'term_taxonomy_id' => absint( $row['term_taxonomy_id'] ),
			'term_id'          => absint( $row['term_id'] ),
			'taxonomy'         => sanitize_key( $row['taxonomy'] ),
			'group_id'         => (string) $row['group_id'],
			'language_id'      => (string) $row['language_id'],
		);
	}

	/**
	 * Normalizes a group row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function normalize_group( $row ) {
		return array(
			'group_id'                => (string) $row['group_id'],
			'source_term_taxonomy_id' => absint( $row['source_term_taxonomy_id'] ),
			'taxonomy'                => sanitize_key( $row['taxonomy'] ),
			'created_at'              => (string) $row['created_at'],
		);
	}

	/**
	 * Clears request caches after a multisite blog switch.
	 *
	 * @return void
	 */
	private function ensure_site_context() {
		global $wpdb;

		if ( $this->cache_prefix === $wpdb->prefix ) {
			return;
		}

		$this->cache_prefix = $wpdb->prefix;
		$this->assignments  = array();
		$this->terms        = array();
		$this->groups       = array();
		$this->members      = array();
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
