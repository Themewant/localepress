<?php
/**
 * wpml-config.xml compatibility integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Infrastructure\DatabaseStringRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Integrations\Wpml\WpmlConfigModule;
use LocalePress\Integrations\Wpml\WpmlConfigReader;
use LocalePress\Language\CurrentLanguageResolver;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Settings\SyncCatalog;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\StringTranslation\OptionStringTranslator;
use LocalePress\StringTranslation\RegisteredStringValidator;
use LocalePress\StringTranslation\StringManager;

/**
 * Verifies that declarations shipped by third parties are read and applied.
 */
class Test_LocalePress_Wpml_Config extends WP_UnitTestCase {
	// Test cleanup intentionally queries the custom tables owned by LocalePress.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Absolute path of the configuration file under test.
	 *
	 * @var string
	 */
	private $fixture = '';

	/**
	 * Configuration reader under test.
	 *
	 * @var WpmlConfigReader
	 */
	private $reader;

	/**
	 * Language manager backing the module.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Stable language IDs keyed by language code.
	 *
	 * @var array<string, string>
	 */
	private $language_ids = array();

	/**
	 * Writes the configuration file and points discovery at it.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->fixture = trailingslashit( get_temp_dir() ) . 'localepress-wpml-config.xml';

		file_put_contents( $this->fixture, $this->fixture_contents() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		add_filter( 'localepress_wpml_config_files', array( $this, 'use_fixture' ) );

		$this->reader = new WpmlConfigReader();
		$this->reader->flush();

		DatabaseStringRepository::install();
		$this->clear_string_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		OptionsLanguageRepository::install();

		$this->languages = new LanguageManager(
			new OptionsLanguageRepository(),
			new LanguageValidator()
		);

		foreach (
			array(
				array( 'English', 'en_US', 'en' ),
				array( 'German', 'de_DE', 'de' ),
			) as $language_data
		) {
			$language = $this->languages->create(
				array(
					'name'          => $language_data[0],
					'native_name'   => $language_data[0],
					'locale'        => $language_data[1],
					'language_code' => $language_data[2],
					'url_slug'      => $language_data[2],
					'is_rtl'        => false,
					'enabled'       => true,
				)
			);

			$this->language_ids[ $language_data[2] ] = $language['id'];
		}
	}

	/**
	 * Removes the configuration file, filters, and stored strings.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'localepress_wpml_config_files', array( $this, 'use_fixture' ) );
		remove_filter( 'localepress_current_language_id', array( $this, 'use_german' ) );
		remove_all_filters( 'option_acme_settings' );
		remove_all_filters( 'option_acme_tagline' );

		$this->reader->flush();

		if ( file_exists( $this->fixture ) ) {
			unlink( $this->fixture ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		}

		delete_option( 'acme_settings' );
		delete_option( 'acme_tagline' );
		delete_option( WorkflowSettings::OPTION_NAME );
		$this->clear_string_tables();
		delete_option( OptionsLanguageRepository::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * Points discovery at the fixture instead of the installed plugins.
	 *
	 * @param array<string, string> $files Discovered files.
	 * @return array<string, string>
	 */
	public function use_fixture( $files ) {
		unset( $files );

		return array( 'plugins/acme' => $this->fixture );
	}

	/**
	 * Selects German as the current language.
	 *
	 * @return string
	 */
	public function use_german() {
		return $this->language_ids['de'];
	}

	/**
	 * Every supported declaration is read into its own rule group.
	 *
	 * @return void
	 */
	public function test_declarations_are_parsed_into_rule_groups() {
		$rules = $this->reader->get_rules();

		$this->assertTrue( $rules['post_types']['acme_portfolio'] );
		$this->assertFalse( $rules['post_types']['acme_log'] );
		$this->assertTrue( $rules['taxonomies']['acme_genre'] );
		$this->assertFalse( $rules['taxonomies']['acme_internal'] );

		$this->assertSame( 'copy', $rules['post_meta']['_acme_layout'] );
		$this->assertSame( 'translate', $rules['post_meta']['_acme_subtitle'] );
		$this->assertSame( 'copy-once', $rules['post_meta']['_acme_intro'] );
		$this->assertSame( 'ignore', $rules['post_meta']['_acme_views'] );

		// An omitted action is an ignore: a value only travels when asked for.
		$this->assertSame( 'ignore', $rules['post_meta']['_acme_undeclared'] );
		$this->assertSame( 'copy', $rules['term_meta']['acme_term_icon'] );

		$this->assertSame(
			array(
				'header_text' => true,
				'footer'      => array( 'copyright' => true ),
			),
			$rules['admin_texts']['plugins/acme']['acme_settings']
		);
		$this->assertTrue( $rules['admin_texts']['plugins/acme']['acme_tagline'] );

		$this->assertSame( array( '//h2[@class="acme"]' ), $rules['blocks']['acme/hero']['xpath'] );
		$this->assertSame( 'json,urlencode', $rules['blocks']['acme/hero']['encodings']['slides'] );
		$this->assertArrayNotHasKey( 'acme/disabled', $rules['blocks'] );
	}

	/**
	 * Declarations the reader cannot honor exactly are skipped, not guessed.
	 *
	 * @return void
	 */
	public function test_unsupported_declarations_are_skipped() {
		$options = $this->reader->get_rules()['admin_texts']['plugins/acme'];

		$this->assertArrayNotHasKey( 'acme_typed', $options );
		$this->assertArrayNotHasKey( 'acme_regex', $options );
	}

	/**
	 * Declared objects are offered for translation, and refused ones removed.
	 *
	 * @return void
	 */
	public function test_declared_objects_change_what_a_site_may_translate() {
		$module = $this->module();

		$post_types = $module->filter_post_types( array( 'post', 'page', 'acme_log' ) );

		$this->assertContains( 'acme_portfolio', $post_types );
		$this->assertContains( 'page', $post_types );
		$this->assertNotContains( 'acme_log', $post_types );

		// A non-public builder type has to be declared eligible as well, or the
		// post type policy drops it before a site ever sees it.
		$this->assertContains( 'acme_portfolio', $module->filter_non_public_post_types( array( 'wp_block' ) ) );
		$this->assertContains( 'wp_block', $module->filter_non_public_post_types( array( 'wp_block' ) ) );

		$taxonomies = $module->filter_taxonomies( array( 'category', 'acme_internal' ) );

		$this->assertContains( 'acme_genre', $taxonomies );
		$this->assertNotContains( 'acme_internal', $taxonomies );
	}

	/**
	 * Field actions decide what travels in each phase of a translation.
	 *
	 * @return void
	 */
	public function test_field_actions_decide_what_travels() {
		$module = $this->module();
		$copy   = $module->filter_post_meta_keys( array( '_acme_views' ), false );

		$this->assertContains( '_acme_layout', $copy );
		$this->assertContains( '_acme_subtitle', $copy );
		$this->assertContains( '_acme_intro', $copy );
		$this->assertNotContains( '_acme_views', $copy );

		// Custom field synchronization is off by default, and a declaration is
		// not allowed to start it. Removals still apply.
		$off = $module->filter_post_meta_keys( array( '_acme_layout', '_acme_views' ), true );

		$this->assertNotContains( '_acme_layout', $off );
		$this->assertNotContains( '_acme_views', $off );

		( new WorkflowSettings() )->update( array( SyncCatalog::sync_key( 'post_meta' ) => true ) );

		$sync = $module->filter_post_meta_keys( array(), true );

		$this->assertContains( '_acme_layout', $sync );

		// Seeded fields are the translator's afterwards: a later save of the
		// source must not overwrite the work done on them.
		$this->assertNotContains( '_acme_subtitle', $sync );
		$this->assertNotContains( '_acme_intro', $sync );
	}

	/**
	 * Declared options are registered and translated on the front end.
	 *
	 * @return void
	 */
	public function test_declared_options_are_registered_and_translated() {
		update_option(
			'acme_settings',
			array(
				'header_text' => 'Book your stay',
				'footer'      => array(
					'copyright' => 'Acme Ltd',
					'year'      => '2026',
				),
				'undeclared'  => 'Left alone',
			)
		);

		$strings    = $this->strings();
		$translator = new OptionStringTranslator( $strings );
		$translator->register( $this->reader->get_rules()['admin_texts'] );

		$this->assertContains( 'acme_settings', $translator->get_option_names() );

		// Reading the option registers its originals, which is what puts them on
		// the string translation screen without anyone declaring them twice.
		$value = get_option( 'acme_settings' );

		$this->assertSame( 'Book your stay', $value['header_text'] );
		$strings->flush_registered_strings();

		$repository = new DatabaseStringRepository();
		$header_id  = StringManager::generate_string_id( 'plugins/acme', 'acme_settings.header_text' );
		$nested_id  = StringManager::generate_string_id( 'plugins/acme', 'acme_settings.footer.copyright' );

		$this->assertNotNull( $repository->find_definition( $header_id ) );
		$this->assertNotNull( $repository->find_definition( $nested_id ) );
		$this->assertNull(
			$repository->find_definition(
				StringManager::generate_string_id( 'plugins/acme', 'acme_settings.undeclared' )
			)
		);

		$strings->save_translations(
			$this->language_ids['de'],
			array(
				$header_id => 'Buchen Sie Ihren Aufenthalt',
				$nested_id => 'Acme GmbH',
			)
		);
		add_filter( 'localepress_current_language_id', array( $this, 'use_german' ) );

		$translated = get_option( 'acme_settings' );

		$this->assertSame( 'Buchen Sie Ihren Aufenthalt', $translated['header_text'] );
		$this->assertSame( 'Acme GmbH', $translated['footer']['copyright'] );
		$this->assertSame( '2026', $translated['footer']['year'] );
		$this->assertSame( 'Left alone', $translated['undeclared'] );
	}

	/**
	 * A value carrying markup is passed through instead of being flattened.
	 *
	 * Themes keep header and footer HTML in exactly the options they declare
	 * here. Registered strings are plain text, so such a value must never round
	 * trip through the string store and reach the page with its markup removed.
	 *
	 * @return void
	 */
	public function test_markup_values_are_left_alone() {
		$markup = '<a href="tel:+8801000000">Call us</a>';

		update_option(
			'acme_settings',
			array(
				'header_text' => $markup,
				'footer'      => array( 'copyright' => 'Acme Ltd' ),
			)
		);

		$strings    = $this->strings();
		$translator = new OptionStringTranslator( $strings );
		$translator->register( $this->reader->get_rules()['admin_texts'] );

		$value = get_option( 'acme_settings' );

		$this->assertSame( $markup, $value['header_text'] );
		$strings->flush_registered_strings();

		// The value is not offered for translation either, so nobody can save a
		// plain-text translation that would drop the link.
		$this->assertNull(
			( new DatabaseStringRepository() )->find_definition(
				StringManager::generate_string_id( 'plugins/acme', 'acme_settings.header_text' )
			)
		);
	}

	/**
	 * Administration screens keep the stored value so a form cannot save over it.
	 *
	 * @return void
	 */
	public function test_administration_screens_keep_the_stored_value() {
		update_option( 'acme_tagline', 'Sleep well' );

		$strings    = $this->strings();
		$translator = new OptionStringTranslator( $strings );
		$translator->register( $this->reader->get_rules()['admin_texts'] );

		get_option( 'acme_tagline' );
		$strings->flush_registered_strings();

		$strings->save_translations(
			$this->language_ids['de'],
			array( StringManager::generate_string_id( 'plugins/acme', 'acme_tagline' ) => 'Schlaf gut' )
		);
		add_filter( 'localepress_current_language_id', array( $this, 'use_german' ) );

		$this->assertSame( 'Schlaf gut', get_option( 'acme_tagline' ) );

		set_current_screen( 'options-general.php' );

		$this->assertSame( 'Sleep well', get_option( 'acme_tagline' ) );

		set_current_screen( 'front' );
	}

	/**
	 * Builds a module bound to the fixture.
	 *
	 * @return WpmlConfigModule
	 */
	private function module() {
		return new WpmlConfigModule(
			$this->reader,
			new OptionStringTranslator( $this->strings() ),
			$this->languages,
			new WorkflowSettings()
		);
	}

	/**
	 * Builds a string manager backed by the test database.
	 *
	 * @return StringManager
	 */
	private function strings() {
		return new StringManager(
			new DatabaseStringRepository(),
			$this->languages,
			new CurrentLanguageResolver( $this->languages ),
			new RegisteredStringValidator()
		);
	}

	/**
	 * Returns the configuration document used by every test.
	 *
	 * @return string
	 */
	private function fixture_contents() {
		return '<wpml-config>
	<custom-types>
		<custom-type translate="1">acme_portfolio</custom-type>
		<custom-type translate="0">acme_log</custom-type>
	</custom-types>
	<taxonomies>
		<taxonomy translate="1">acme_genre</taxonomy>
		<taxonomy translate="0">acme_internal</taxonomy>
	</taxonomies>
	<custom-fields>
		<custom-field action="copy">_acme_layout</custom-field>
		<custom-field action="translate">_acme_subtitle</custom-field>
		<custom-field action="copy-once">_acme_intro</custom-field>
		<custom-field action="ignore">_acme_views</custom-field>
		<custom-field>_acme_undeclared</custom-field>
	</custom-fields>
	<custom-term-fields>
		<custom-term-field action="copy">acme_term_icon</custom-term-field>
	</custom-term-fields>
	<admin-texts>
		<key name="acme_settings">
			<key name="header_text" />
			<key name="footer">
				<key name="copyright" />
			</key>
		</key>
		<key name="acme_tagline" />
		<key name="acme_typed" type="line" />
		<key name="acme_regex" search-method="regex" />
	</admin-texts>
	<gutenberg-blocks>
		<gutenberg-block type="acme/hero" translate="1">
			<xpath>//h2[@class="acme"]</xpath>
			<key name="slides" encoding="json" />
		</gutenberg-block>
		<gutenberg-block type="acme/disabled" translate="0">
			<xpath>//p</xpath>
		</gutenberg-block>
	</gutenberg-blocks>
</wpml-config>';
	}

	/**
	 * Empties the registered string tables between tests.
	 *
	 * @return void
	 */
	private function clear_string_tables() {
		global $wpdb;

		$strings_table      = DatabaseStringRepository::strings_table();
		$translations_table = DatabaseStringRepository::translations_table();

		// Table names are generated by the LocalePress repository.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$translations_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$strings_table}" );

		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( DatabaseStringRepository::CACHE_GROUP );
		}
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
