<?php
/**
 * Language manager integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\CurrentLanguageResolver;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;

/**
 * Verifies Phase 1 language lifecycle rules against the Options API.
 */
class Test_LocalePress_Language_Manager extends WP_UnitTestCase {

	/**
	 * Language manager under test.
	 *
	 * @var LanguageManager
	 */
	private $manager;

	/**
	 * Prepares an empty registry.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( OptionsLanguageRepository::OPTION_NAME );
		OptionsLanguageRepository::install();

		$this->manager = new LanguageManager(
			new OptionsLanguageRepository(),
			new LanguageValidator()
		);
	}

	/**
	 * Removes filters and test data.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'localepress_current_language_id' );
		delete_option( OptionsLanguageRepository::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * The first language establishes an enabled default.
	 *
	 * @return void
	 */
	public function test_first_language_becomes_enabled_default() {
		$language = $this->manager->create( $this->language_input( array( 'enabled' => false ) ) );

		$this->assertNotWPError( $language );
		$this->assertTrue( $language['enabled'] );
		$this->assertSame( $language['id'], $this->manager->get_default_id() );
	}

	/**
	 * Creating the initial language persists its default state in one option write.
	 *
	 * @return void
	 */
	public function test_first_language_uses_one_registry_write() {
		$writes = 0;

		$counter = static function ( $value ) use ( &$writes ) {
			++$writes;

			return $value;
		};

		add_filter( 'pre_update_option_localepress_language_registry', $counter );
		$this->manager->create( $this->language_input() );
		remove_filter( 'pre_update_option_localepress_language_registry', $counter );

		$this->assertSame( 1, $writes );
	}

	/**
	 * Locale and URL slug must be unique.
	 *
	 * @return void
	 */
	public function test_duplicate_identifiers_are_rejected() {
		$this->manager->create( $this->language_input() );

		$duplicate_locale = $this->manager->create(
			$this->language_input(
				array(
					'name'        => 'US English',
					'native_name' => 'US English',
					'url_slug'    => 'us-english',
				)
			)
		);

		$this->assertWPError( $duplicate_locale );
		$this->assertSame( 'duplicate_locale', $duplicate_locale->get_error_code() );

		$duplicate_slug = $this->manager->create(
			$this->language_input(
				array(
					'name'          => 'French',
					'native_name'   => 'French',
					'locale'        => 'fr_FR',
					'language_code' => 'fr',
				)
			)
		);

		$this->assertWPError( $duplicate_slug );
		$this->assertSame( 'duplicate_url_slug', $duplicate_slug->get_error_code() );
	}

	/**
	 * A default language cannot be disabled.
	 *
	 * @return void
	 */
	public function test_default_language_cannot_be_disabled() {
		$language = $this->manager->create( $this->language_input() );
		$result   = $this->manager->toggle( $language['id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'cannot_disable_default', $result->get_error_code() );
	}

	/**
	 * API-style false strings are normalized as false.
	 *
	 * @return void
	 */
	public function test_boolean_strings_are_normalized() {
		$this->manager->create( $this->language_input() );
		$french = $this->manager->create(
			$this->language_input(
				array(
					'name'          => 'French',
					'native_name'   => 'French',
					'locale'        => 'fr_FR',
					'language_code' => 'fr',
					'url_slug'      => 'fr',
					'is_rtl'        => 'false',
					'enabled'       => 'false',
				)
			)
		);

		$this->assertFalse( $french['is_rtl'] );
		$this->assertFalse( $french['enabled'] );
	}

	/**
	 * Malformed data from the pre-validation filter fails without a type error.
	 *
	 * @return void
	 */
	public function test_filtered_non_scalar_fields_are_rejected_safely() {
		$filter = static function ( $input ) {
			$input['name'] = array( 'invalid' );

			return $input;
		};

		add_filter( 'localepress_pre_validate_language', $filter );
		$result = $this->manager->create( $this->language_input() );
		remove_filter( 'localepress_pre_validate_language', $filter );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	/**
	 * The default language is protected from deletion.
	 *
	 * @return void
	 */
	public function test_the_default_language_cannot_be_deleted() {
		$english = $this->manager->create( $this->language_input() );
		$this->manager->create(
			$this->language_input(
				array(
					'name'          => 'French',
					'native_name'   => 'French',
					'locale'        => 'fr_FR',
					'language_code' => 'fr',
					'url_slug'      => 'fr',
				)
			)
		);

		$result = $this->manager->delete( $english['id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'default_language_locked', $result->get_error_code() );
		$this->assertNotNull( $this->manager->find( $english['id'] ) );
		$this->assertSame( $english['id'], $this->manager->get_default_id() );
	}

	/**
	 * Promoting another language first releases the previous default.
	 *
	 * @return void
	 */
	public function test_a_former_default_can_be_deleted_after_promotion() {
		$english = $this->manager->create( $this->language_input() );
		$french  = $this->manager->create(
			$this->language_input(
				array(
					'name'          => 'French',
					'native_name'   => 'French',
					'locale'        => 'fr_FR',
					'language_code' => 'fr',
					'url_slug'      => 'fr',
				)
			)
		);

		$this->manager->set_default( $french['id'] );

		$this->assertTrue( $this->manager->delete( $english['id'] ) );
		$this->assertNull( $this->manager->find( $english['id'] ) );
		$this->assertSame( $french['id'], $this->manager->get_default_id() );
	}

	/**
	 * Explicit ordering can move a language one position.
	 *
	 * @return void
	 */
	public function test_languages_can_be_reordered() {
		$this->manager->create( $this->language_input() );
		$french = $this->manager->create(
			$this->language_input(
				array(
					'name'          => 'French',
					'native_name'   => 'French',
					'locale'        => 'fr_FR',
					'language_code' => 'fr',
					'url_slug'      => 'fr',
				)
			)
		);

		$this->manager->move( $french['id'], 'up' );
		$languages = $this->manager->get_languages();

		$this->assertSame( $french['id'], $languages[0]['id'] );
	}

	/**
	 * Invalid detector output falls back to the enabled default language.
	 *
	 * @return void
	 */
	public function test_current_language_resolver_rejects_unknown_ids() {
		$english  = $this->manager->create( $this->language_input() );
		$resolver = new CurrentLanguageResolver( $this->manager );

		add_filter(
			'localepress_current_language_id',
			static function () {
				return 'not-a-language';
			}
		);

		$current = $resolver->resolve();

		$this->assertSame( $english['id'], $current['id'] );
	}

	/**
	 * Returns a valid language payload with optional overrides.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function language_input( array $overrides = array() ) {
		return wp_parse_args(
			$overrides,
			array(
				'name'          => 'English',
				'native_name'   => 'English',
				'locale'        => 'en_US',
				'language_code' => 'en',
				'url_slug'      => 'en',
				'is_rtl'        => false,
				'enabled'       => true,
			)
		);
	}
}
