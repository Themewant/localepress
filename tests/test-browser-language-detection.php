<?php
/**
 * Browser language negotiation tests.
 *
 * @package LocalePress
 */

use LocalePress\Language\BrowserLanguageDetector;

/**
 * Verifies Accept-Language parsing and registered-language matching.
 */
class Test_LocalePress_Browser_Language_Detection extends WP_UnitTestCase {

	/**
	 * Detector under test.
	 *
	 * @var BrowserLanguageDetector
	 */
	private $detector;

	/**
	 * Sets up the detector.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->detector = new BrowserLanguageDetector();
	}

	/**
	 * Returns the language records used across the tests.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function languages() {
		return array(
			array(
				'id'            => 'english',
				'locale'        => 'en_US',
				'language_code' => 'en',
				'url_slug'      => 'en',
				'enabled'       => true,
			),
			array(
				'id'            => 'bengali',
				'locale'        => 'bn_BD',
				'language_code' => 'bn',
				'url_slug'      => 'bn',
				'enabled'       => true,
			),
			array(
				'id'            => 'portuguese',
				'locale'        => 'pt_BR',
				'language_code' => 'pt',
				'url_slug'      => 'brasil',
				'enabled'       => true,
			),
		);
	}

	/**
	 * Returns the matched language ID, or an empty string.
	 *
	 * @param string $header Accept-Language header.
	 * @return string
	 */
	private function match( $header ) {
		$language = $this->detector->match( $header, $this->languages() );

		return is_array( $language ) ? (string) $language['id'] : '';
	}

	/**
	 * Ranges are ordered by weight, and equal weights keep browser order.
	 *
	 * @return void
	 */
	public function test_ranges_are_sorted_by_quality() {
		$ranges = $this->detector->parse( 'en;q=0.4,bn;q=0.9,ru,fr;q=0.7' );
		$tags   = wp_list_pluck( $ranges, 'tag' );

		$this->assertSame( array( 'ru', 'bn', 'fr', 'en' ), $tags );
		$this->assertSame( 1.0, $ranges[0]['quality'] );
		$this->assertSame( 0.4, $ranges[3]['quality'] );
	}

	/**
	 * An exact locale is preferred over a looser range with lower weight.
	 *
	 * @return void
	 */
	public function test_exact_locale_wins() {
		$this->assertSame( 'bengali', $this->match( 'bn-BD,bn;q=0.9,en-US;q=0.8,en;q=0.7' ) );
		$this->assertSame( 'english', $this->match( 'en-US,en;q=0.9' ) );
	}

	/**
	 * A weaker but supported range wins over an unsupported stronger one.
	 *
	 * @return void
	 */
	public function test_unsupported_ranges_are_skipped() {
		$this->assertSame( 'bengali', $this->match( 'fr-FR,fr;q=0.9,bn;q=0.5' ) );
		$this->assertSame( '', $this->match( 'fr-FR,fr;q=0.9' ) );
	}

	/**
	 * A regional variant falls back to the language sharing its primary subtag.
	 *
	 * @return void
	 */
	public function test_region_falls_back_to_primary_subtag() {
		$this->assertSame( 'portuguese', $this->match( 'pt-PT,pt;q=0.9' ) );
		$this->assertSame( 'english', $this->match( 'en-GB,en;q=0.8' ) );
		$this->assertSame( 'english', $this->match( 'zh-Hans-CN,zh;q=0.9,en;q=0.8' ) );
	}

	/**
	 * A URL slug that is not a language code still matches.
	 *
	 * @return void
	 */
	public function test_url_slug_is_matched() {
		$this->assertSame( 'portuguese', $this->match( 'brasil' ) );
	}

	/**
	 * Wildcards, empty headers, and zero weights select nothing.
	 *
	 * @return void
	 */
	public function test_unusable_headers_match_nothing() {
		$this->assertSame( '', $this->match( '*' ) );
		$this->assertSame( '', $this->match( '' ) );
		$this->assertSame( '', $this->match( 'en-US;q=0' ) );
		$this->assertSame( array(), $this->detector->parse( array( 'bn' ) ) );
	}

	/**
	 * Malformed and hostile headers are discarded without matching.
	 *
	 * @return void
	 */
	public function test_malformed_ranges_are_discarded() {
		$this->assertSame( 'bengali', $this->match( '<script>alert(1)</script>,bn;q=0.9' ) );
		$this->assertSame( 'bengali', $this->match( '   BN-bd  ,  EN ; q = 0.5 ' ) );
		$this->assertSame( 'bengali', $this->match( 'bn_BD,en;q=0.8' ) );
	}

	/**
	 * A flooded header is capped instead of scanned in full.
	 *
	 * @return void
	 */
	public function test_range_count_is_capped() {
		$header = str_repeat( 'xx,', 200 ) . 'bn';

		$this->assertCount( BrowserLanguageDetector::MAX_RANGES, $this->detector->parse( $header ) );
		$this->assertSame( '', $this->match( $header ) );
	}

	/**
	 * Matching against no candidates never fails.
	 *
	 * @return void
	 */
	public function test_empty_language_set_matches_nothing() {
		$this->assertNull( $this->detector->match( 'bn-BD', array() ) );
		$this->assertNull( $this->detector->match( 'bn-BD', array( array( 'no' => 'id' ) ) ) );
	}
}
