<?php
/**
 * Language flag registry tests.
 *
 * @package LocalePress
 */

use LocalePress\Language\FlagRegistry;

/**
 * Verifies flag code resolution, overrides, and rendered markup.
 */
class Test_LocalePress_Flag_Registry extends WP_UnitTestCase {

	/**
	 * Registry under test.
	 *
	 * @var FlagRegistry
	 */
	private $registry;

	/**
	 * Builds a fresh registry.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->registry = new FlagRegistry();
	}

	/**
	 * Builds a minimal language record.
	 *
	 * @param string $locale        Language locale.
	 * @param string $language_code Language code.
	 * @return array<string, mixed>
	 */
	private function language( $locale, $language_code ) {
		return array(
			'id'            => 'lp-' . strtolower( $locale ),
			'name'          => $locale,
			'native_name'   => $locale,
			'locale'        => $locale,
			'language_code' => $language_code,
			'url_slug'      => $language_code,
			'is_rtl'        => false,
			'enabled'       => true,
		);
	}

	/**
	 * The region subtag of a locale names the flag.
	 *
	 * @return void
	 */
	public function test_region_subtag_resolves_flag_code() {
		$this->assertSame( 'us', $this->registry->get_flag_code( $this->language( 'en_US', 'en' ) ) );
		$this->assertSame( 'bd', $this->registry->get_flag_code( $this->language( 'bn_BD', 'bn' ) ) );
		$this->assertSame( 'br', $this->registry->get_flag_code( $this->language( 'pt_BR', 'pt' ) ) );
		$this->assertSame( 'ca', $this->registry->get_flag_code( $this->language( 'fr_CA', 'fr' ) ) );
	}

	/**
	 * Locales without a region fall back to the curated map or the language code.
	 *
	 * @return void
	 */
	public function test_region_less_locales_use_fallbacks() {
		$this->assertSame( 'sa', $this->registry->get_flag_code( $this->language( 'ar', 'ar' ) ) );
		$this->assertSame( 'jp', $this->registry->get_flag_code( $this->language( 'ja', 'ja' ) ) );
		$this->assertSame( 'ua', $this->registry->get_flag_code( $this->language( 'uk', 'uk' ) ) );
		$this->assertSame( 'de', $this->registry->get_flag_code( $this->language( 'de', 'de' ) ) );
	}

	/**
	 * Languages named by a code that is also another country resolve correctly.
	 *
	 * Bashkir is `ba`, which is also the country code for Bosnia and Herzegovina,
	 * and Afrikaans is `af`, which is also Afghanistan. Both have to come from the
	 * curated map rather than from a bundled file that happens to share the name.
	 *
	 * @return void
	 */
	public function test_language_codes_do_not_borrow_a_matching_country_flag() {
		$this->assertSame( 'ru', $this->registry->get_flag_code( $this->language( 'ba', 'ba' ) ) );
		$this->assertSame( 'za', $this->registry->get_flag_code( $this->language( 'af', 'af' ) ) );
		$this->assertSame( 'et', $this->registry->get_flag_code( $this->language( 'am', 'am' ) ) );
		$this->assertSame( 'ee', $this->registry->get_flag_code( $this->language( 'et', 'et' ) ) );
	}

	/**
	 * Languages without a country of their own still resolve to a bundled flag.
	 *
	 * @return void
	 */
	public function test_stateless_languages_resolve_to_a_bundled_flag() {
		foreach (
			array(
				'eo'  => 'esperanto',
				'ckb' => 'kurdistan',
				'vec' => 'veneto',
				'wol' => 'sn',
				'kin' => 'rw',
				'mlt' => 'mt',
			) as $locale => $expected
		) {
			$language = $this->language( $locale, $locale );

			$this->assertSame( $expected, $this->registry->get_flag_code( $language ), $locale );
			$this->assertTrue( $this->registry->has_flag( $language ), $locale );
		}
	}

	/**
	 * Unknown languages resolve to no flag instead of a broken image.
	 *
	 * @return void
	 */
	public function test_unknown_language_has_no_flag() {
		$language = $this->language( 'qqq', 'qqq' );

		$this->assertSame( '', $this->registry->get_flag_code( $language ) );
		$this->assertSame( '', $this->registry->get_flag_url( $language ) );
		$this->assertFalse( $this->registry->has_flag( $language ) );
		$this->assertSame( '', $this->registry->get_flag_html( $language ) );
	}

	/**
	 * Bundled flags render as a sized image element.
	 *
	 * @return void
	 */
	public function test_bundled_flag_renders_image() {
		$language = $this->language( 'bn_BD', 'bn' );

		$this->assertTrue( $this->registry->has_flag( $language ) );
		$this->assertStringContainsString( 'assets/flags/bd.svg', $this->registry->get_flag_url( $language ) );

		$html = $this->registry->get_flag_html( $language );

		$this->assertStringContainsString( 'class="localepress-flag"', $html );
		$this->assertStringContainsString( 'width="' . FlagRegistry::FLAG_WIDTH . '"', $html );
		$this->assertStringContainsString( 'height="' . FlagRegistry::FLAG_HEIGHT . '"', $html );
	}

	/**
	 * The flag code filter overrides locale-based resolution.
	 *
	 * @return void
	 */
	public function test_flag_code_filter_overrides_locale() {
		$filter = static function () {
			return 'fr';
		};

		add_filter( 'localepress_flag_code', $filter );
		$url = $this->registry->get_flag_url( $this->language( 'en_US', 'en' ) );
		remove_filter( 'localepress_flag_code', $filter );

		$this->assertStringContainsString( 'assets/flags/fr.svg', $url );
	}

	/**
	 * A custom flag URL wins over the bundled flag.
	 *
	 * @return void
	 */
	public function test_custom_flag_url_filter_wins() {
		$filter = static function () {
			return 'https://example.org/flags/custom.png';
		};

		add_filter( 'localepress_custom_flag_url', $filter );
		$flag = ( new FlagRegistry() )->get_flag_information( $this->language( 'de_DE', 'de' ) );
		remove_filter( 'localepress_custom_flag_url', $filter );

		$this->assertSame( 'https://example.org/flags/custom.png', $flag['url'] );
		$this->assertTrue( $flag['custom'] );
	}

	/**
	 * Filtered markup is sanitized before it reaches a page.
	 *
	 * @return void
	 */
	public function test_filtered_markup_is_sanitized() {
		$filter = static function () {
			return '<img class="localepress-flag" src="https://example.org/f.svg" onerror="alert(1)" /><script>alert(1)</script>';
		};

		add_filter( 'localepress_flag_html', $filter );
		$html = $this->registry->get_flag_html( $this->language( 'de_DE', 'de' ) );
		remove_filter( 'localepress_flag_html', $filter );

		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'https://example.org/f.svg', $html );
	}
}
