<?php
/**
 * Core sitemap compatibility tests.
 *
 * @package LocalePress
 */

use LocalePress\Content\LanguageQueryConstraint;

/**
 * Verifies the language constraint the sitemap providers are given.
 */
class Test_LocalePress_Sitemap extends WP_UnitTestCase {

	/**
	 * Constraint under test.
	 *
	 * @var LanguageQueryConstraint
	 */
	private $constraint;

	/**
	 * Sets up the constraint.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->constraint = new LanguageQueryConstraint();
	}

	/**
	 * Returns empty clauses to constrain.
	 *
	 * @return array<string, string>
	 */
	private function clauses() {
		return array(
			'join'  => '',
			'where' => ' AND 1=1',
		);
	}

	/**
	 * Posts assigned to a listed language, and unassigned posts, are kept.
	 *
	 * @return void
	 */
	public function test_post_constraint_keeps_listed_and_unassigned() {
		$clauses = $this->constraint->restrict_posts_to_languages(
			$this->clauses(),
			array( 'english', 'bengali' )
		);

		$this->assertStringContainsString( LanguageQueryConstraint::POST_ALIAS, $clauses['join'] );
		$this->assertStringContainsString( "IN ('english', 'bengali')", $clauses['where'] );
		$this->assertStringContainsString( 'post_id IS NULL', $clauses['where'] );
	}

	/**
	 * The assignment join is added once even when the constraint runs twice.
	 *
	 * @return void
	 */
	public function test_post_join_is_not_duplicated() {
		$clauses = $this->constraint->restrict_posts_to_languages( $this->clauses(), array( 'english' ) );
		$clauses = $this->constraint->restrict_posts_to_languages( $clauses, array( 'english' ) );

		$this->assertSame( 1, substr_count( $clauses['join'], LanguageQueryConstraint::POST_ALIAS . ' ON' ) );
	}

	/**
	 * Terms are constrained through the term taxonomy alias WP_Term_Query uses.
	 *
	 * @return void
	 */
	public function test_term_constraint_uses_term_taxonomy_alias() {
		$clauses = $this->constraint->restrict_terms_to_languages( $this->clauses(), array( 'english' ) );

		$this->assertStringContainsString( 'tt.term_taxonomy_id', $clauses['join'] );
		$this->assertStringContainsString( 'term_taxonomy_id IS NULL', $clauses['where'] );
	}

	/**
	 * Every value receives its own placeholder, whatever the language count.
	 *
	 * @return void
	 */
	public function test_placeholders_match_value_count() {
		foreach ( array( 1, 3, 7 ) as $count ) {
			$ids = array();

			for ( $index = 0; $index < $count; $index++ ) {
				$ids[] = 'language-' . $index;
			}

			$clauses = $this->constraint->restrict_posts_to_languages( $this->clauses(), $ids );

			$this->assertSame( $count, substr_count( $clauses['where'], "'language-" ) );
			$this->assertStringNotContainsString( '%s', $clauses['where'] );
		}
	}

	/**
	 * Degenerate input leaves the query untouched instead of breaking its SQL.
	 *
	 * @return void
	 */
	public function test_unusable_input_is_a_no_op() {
		$clauses = $this->clauses();

		$this->assertSame( $clauses, $this->constraint->restrict_posts_to_languages( $clauses, array() ) );
		$this->assertSame( $clauses, $this->constraint->restrict_posts_to_languages( $clauses, array( '', null ) ) );
		$this->assertSame( $clauses, $this->constraint->restrict_terms_to_languages( $clauses, array() ) );
		$this->assertSame(
			array( 'where' => 'x' ),
			$this->constraint->restrict_posts_to_languages( array( 'where' => 'x' ), array( 'english' ) )
		);
	}

	/**
	 * Repeated identifiers collapse to one placeholder each.
	 *
	 * @return void
	 */
	public function test_duplicate_language_ids_collapse() {
		$clauses = $this->constraint->restrict_posts_to_languages(
			$this->clauses(),
			array( 'english', 'english', 'bengali' )
		);

		$this->assertSame( 1, substr_count( $clauses['where'], "'english'" ) );
		$this->assertSame( 1, substr_count( $clauses['where'], "'bengali'" ) );
	}

	/**
	 * The core sitemap index stays reachable without a language prefix.
	 *
	 * @return void
	 */
	public function test_sitemap_index_is_a_reserved_route() {
		$this->assertTrue( function_exists( 'wp_sitemaps_get_server' ) );
		$this->assertStringContainsString( 'wp-sitemap.xml', wp_sitemaps_get_server()->get_index_url() );
	}
}
