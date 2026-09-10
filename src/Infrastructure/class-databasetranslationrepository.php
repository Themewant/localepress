<?php
/**
 * Database-backed post translation repository.
 *
 * @package LocalePress
 */

namespace LocalePress\Infrastructure;

use LocalePress\Contracts\TranslationRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Stores translation groups and memberships in indexed custom tables.
 */
final class DatabaseTranslationRepository implements TranslationRepositoryInterface {
	// Direct queries are isolated here because this repository owns custom tables.
	// Every read path uses an explicit request-level cache.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Translation group table suffix.
	 *
	 * @var string
	 */
	const GROUPS_TABLE_SUFFIX = 'localepress_translation_groups';

	/**
	 * Translation membership table suffix.
	 *
	 * @var string
	 */
	const ASSIGNMENTS_TABLE_SUFFIX = 'localepress_post_translations';

	/**
	 * Assignment cache keyed by post ID. Null values represent cache misses.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private $assignments = array();

	/**
	 * Group cache keyed by group ID. Null values represent cache misses.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private $groups = array();

	/**
	 * Group member cache keyed by group ID.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $members = array();

	/**
	 * Site prefix associated with the request cache.
	 *
	 * @var string
	 */
	private $cache_prefix = '';

	/**
	 * Creates or upgrades the translation tables.
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
			source_post_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (group_id),
			KEY source_post_id (source_post_id)
		) {$charset_collate};";

		$assignments_sql = "CREATE TABLE {$assignments_table} (
			post_id bigint(20) unsigned NOT NULL,
			group_id char(36) NOT NULL,
			language_id varchar(64) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (post_id),
			UNIQUE KEY group_language (group_id, language_id),
			KEY group_id (group_id),
			KEY language_id (language_id)
		) {$charset_collate};";

		// Table names and collation are generated exclusively by WordPress.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		dbDelta( $groups_sql );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		dbDelta( $assignments_sql );
	}

	/**
	 * Returns the site-specific translation group table name.
	 *
	 * @return string
	 */
	public static function groups_table() {
		global $wpdb;

		return $wpdb->prefix . self::GROUPS_TABLE_SUFFIX;
	}

	/**
	 * Returns the site-specific assignment table name.
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
	 * @param int $post_id Post identifier.
	 */
	public function find_by_post( $post_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$post_id = absint( $post_id );

		if ( array_key_exists( $post_id, $this->assignments ) ) {
			return $this->assignments[ $post_id ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT post_id, group_id, language_id FROM %i WHERE post_id = %d',
				self::assignments_table(),
				$post_id
			),
			ARRAY_A
		);

		$this->assignments[ $post_id ] = is_array( $row ) ? $this->normalize_assignment( $row ) : null;

		return $this->assignments[ $post_id ];
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
				'SELECT group_id, source_post_id, created_at FROM %i WHERE group_id = %s',
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
				'SELECT post_id, group_id, language_id FROM %i WHERE group_id = %s ORDER BY post_id ASC',
				self::assignments_table(),
				$group_id
			),
			ARRAY_A
		);

		$this->members[ $group_id ] = array();

		foreach ( $rows as $row ) {
			$assignment                                  = $this->normalize_assignment( $row );
			$this->members[ $group_id ][]                = $assignment;
			$this->assignments[ $assignment['post_id'] ] = $assignment;
		}

		return $this->members[ $group_id ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id       Translation group identifier.
	 * @param int    $source_post_id Source post identifier.
	 */
	public function create_group( $group_id, $source_post_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$created_at = current_time( 'mysql', true );
		$inserted   = $wpdb->insert(
			self::groups_table(),
			array(
				'group_id'       => $group_id,
				'source_post_id' => absint( $source_post_id ),
				'created_at'     => $created_at,
			),
			array( '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		$this->groups[ $group_id ] = array(
			'group_id'       => $group_id,
			'source_post_id' => absint( $source_post_id ),
			'created_at'     => $created_at,
		);

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $group_id    Translation group identifier.
	 * @param string $language_id Language identifier.
	 */
	public function add_assignment( $post_id, $group_id, $language_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$post_id  = absint( $post_id );
		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			self::assignments_table(),
			array(
				'post_id'     => $post_id,
				'group_id'    => $group_id,
				'language_id' => $language_id,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		$this->assignments[ $post_id ] = array(
			'post_id'     => $post_id,
			'group_id'    => $group_id,
			'language_id' => $language_id,
		);
		unset( $this->members[ $group_id ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $language_id Language identifier.
	 */
	public function update_assignment_language( $post_id, $language_id ) {
		global $wpdb;

		$assignment = $this->find_by_post( $post_id );

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
			array( 'post_id' => absint( $post_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$assignment['language_id']               = $language_id;
		$this->assignments[ absint( $post_id ) ] = $assignment;
		unset( $this->members[ $assignment['group_id'] ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int $post_id Post identifier.
	 */
	public function remove_assignment( $post_id ) {
		global $wpdb;

		$post_id    = absint( $post_id );
		$assignment = $this->find_by_post( $post_id );

		if ( null === $assignment ) {
			return false;
		}

		$deleted = $wpdb->delete(
			self::assignments_table(),
			array( 'post_id' => $post_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return false;
		}

		$this->assignments[ $post_id ] = null;
		unset( $this->members[ $assignment['group_id'] ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id       Translation group identifier.
	 * @param int    $source_post_id Source post identifier.
	 */
	public function update_group_source( $group_id, $source_post_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$updated = $wpdb->update(
			self::groups_table(),
			array( 'source_post_id' => absint( $source_post_id ) ),
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
	 * @param array<int, int> $post_ids Post identifiers.
	 */
	public function prime_for_posts( array $post_ids ) {
		global $wpdb;
		$this->ensure_site_context();

		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$uncached = array_values(
			array_filter(
				$post_ids,
				function ( $post_id ) {
					return ! array_key_exists( $post_id, $this->assignments );
				}
			)
		);

		if ( ! empty( $uncached ) ) {
			foreach ( $uncached as $post_id ) {
				$this->assignments[ $post_id ] = null;
			}

			$post_placeholders = implode( ', ', array_fill( 0, count( $uncached ), '%d' ) );
			$post_query        = "SELECT post_id, group_id, language_id FROM %i WHERE post_id IN ({$post_placeholders})";
			$post_values       = array_merge( array( self::assignments_table() ), $uncached );

			// Placeholder count is generated from sanitized integer identifiers above.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $post_query, $post_values ), ARRAY_A );

			foreach ( $rows as $row ) {
				$assignment                                  = $this->normalize_assignment( $row );
				$this->assignments[ $assignment['post_id'] ] = $assignment;
			}
		}

		$group_ids = array();

		foreach ( $post_ids as $post_id ) {
			$assignment = $this->assignments[ $post_id ];

			if ( null !== $assignment && ! isset( $this->members[ $assignment['group_id'] ] ) ) {
				$group_ids[] = $assignment['group_id'];
			}
		}

		$group_ids = array_values( array_unique( $group_ids ) );

		if ( empty( $group_ids ) ) {
			return;
		}

		$uncached_group_ids = array_values(
			array_filter(
				$group_ids,
				function ( $group_id ) {
					return ! array_key_exists( $group_id, $this->groups );
				}
			)
		);

		if ( ! empty( $uncached_group_ids ) ) {
			foreach ( $uncached_group_ids as $group_id ) {
				$this->groups[ $group_id ] = null;
			}

			$group_record_placeholders = implode( ', ', array_fill( 0, count( $uncached_group_ids ), '%s' ) );
			$group_record_query        = "SELECT group_id, source_post_id, created_at FROM %i WHERE group_id IN ({$group_record_placeholders})";
			$group_record_values       = array_merge( array( self::groups_table() ), $uncached_group_ids );

			// Placeholder count is generated from stored group identifiers above.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$group_rows = $wpdb->get_results( $wpdb->prepare( $group_record_query, $group_record_values ), ARRAY_A );

			foreach ( $group_rows as $row ) {
				$group                              = $this->normalize_group( $row );
				$this->groups[ $group['group_id'] ] = $group;
			}
		}

		$group_placeholders = implode( ', ', array_fill( 0, count( $group_ids ), '%s' ) );
		$group_query        = "SELECT post_id, group_id, language_id FROM %i WHERE group_id IN ({$group_placeholders}) ORDER BY post_id ASC";
		$group_values       = array_merge( array( self::assignments_table() ), $group_ids );

		foreach ( $group_ids as $group_id ) {
			$this->members[ $group_id ] = array();
		}

		// Placeholder count is generated from stored group identifiers above.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$member_rows = $wpdb->get_results( $wpdb->prepare( $group_query, $group_values ), ARRAY_A );

		foreach ( $member_rows as $row ) {
			$assignment                                  = $this->normalize_assignment( $row );
			$this->members[ $assignment['group_id'] ][]  = $assignment;
			$this->assignments[ $assignment['post_id'] ] = $assignment;
		}
	}

	/**
	 * Normalizes a database assignment row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function normalize_assignment( $row ) {
		return array(
			'post_id'     => absint( $row['post_id'] ),
			'group_id'    => (string) $row['group_id'],
			'language_id' => (string) $row['language_id'],
		);
	}

	/**
	 * Normalizes a database group row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function normalize_group( $row ) {
		return array(
			'group_id'       => (string) $row['group_id'],
			'source_post_id' => absint( $row['source_post_id'] ),
			'created_at'     => (string) $row['created_at'],
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
		$this->groups       = array();
		$this->members      = array();
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
