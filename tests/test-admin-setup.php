<?php
/**
 * Admin setup lifecycle tests.
 *
 * @package LocalePress
 */

use LocalePress\Admin\SetupWizardModule;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Language\LanguageCatalog;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageValidator;
use LocalePress\Lifecycle\Activator;
use LocalePress\Lifecycle\Deactivator;
use LocalePress\Lifecycle\Installer;
use LocalePress\Routing\LanguageHostResolver;
use LocalePress\Routing\RoutingModule;
use LocalePress\Settings\PluginSettings;

/**
 * Verifies setup redirect marker lifecycle behavior.
 */
class Test_LocalePress_Admin_Setup extends WP_UnitTestCase {

	/**
	 * Site created by a multisite-only test.
	 *
	 * @var int
	 */
	private $site_id = 0;

	/**
	 * Language manager backing the wizard under test.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Settings backing the wizard under test.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Removes setup state before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_transient( Activator::SETUP_REDIRECT_TRANSIENT );
		$_POST = array();
	}

	/**
	 * Removes setup state after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST = array();
		remove_filter( 'localepress_language_catalog', array( $this, 'offer_german' ) );
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		delete_transient( Activator::SETUP_REDIRECT_TRANSIENT );

		if ( 0 < $this->site_id && is_multisite() ) {
			wpmu_delete_blog( $this->site_id, true );
			$this->site_id = 0;
		}

		parent::tear_down();
	}

	/**
	 * A normal site activation schedules the one-time setup redirect.
	 *
	 * @return void
	 */
	public function test_site_activation_schedules_setup_redirect() {
		Activator::activate( false );

		$this->assertSame( 1, get_transient( Activator::SETUP_REDIRECT_TRANSIENT ) );
	}

	/**
	 * Network activation must not schedule a per-site setup redirect.
	 *
	 * @return void
	 */
	public function test_network_activation_does_not_schedule_setup_redirect() {
		Activator::activate( true );

		$this->assertFalse( get_transient( Activator::SETUP_REDIRECT_TRANSIENT ) );
	}

	/**
	 * Deactivation removes a pending setup redirect without deleting data.
	 *
	 * @return void
	 */
	public function test_deactivation_clears_setup_redirect() {
		set_transient( Activator::SETUP_REDIRECT_TRANSIENT, 1, MINUTE_IN_SECONDS );

		Deactivator::deactivate();

		$this->assertFalse( get_transient( Activator::SETUP_REDIRECT_TRANSIENT ) );
	}

	/** Network lifecycle operations install and invalidate every existing site. */
	public function test_network_lifecycle_processes_each_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required for network lifecycle coverage.' );
		}

		$this->site_id = self::factory()->blog->create();
		switch_to_blog( $this->site_id );
		delete_option( Installer::VERSION_OPTION );
		update_option( RoutingModule::REWRITE_SIGNATURE_OPTION, 'stale' );
		update_option( 'rewrite_rules', array( 'stale' => 'index.php' ) );
		restore_current_blog();

		Activator::activate( true );
		switch_to_blog( $this->site_id );
		$this->assertSame( LOCALEPRESS_VERSION, get_option( Installer::VERSION_OPTION ) );
		$this->assertFalse( get_option( RoutingModule::REWRITE_SIGNATURE_OPTION ) );
		update_option( RoutingModule::REWRITE_SIGNATURE_OPTION, 'stale' );
		restore_current_blog();

		Deactivator::deactivate( true );
		switch_to_blog( $this->site_id );
		$this->assertFalse( get_option( RoutingModule::REWRITE_SIGNATURE_OPTION ) );
		$this->assertFalse( get_option( 'rewrite_rules' ) );
		restore_current_blog();
	}

	/**
	 * Adds German to the language catalog.
	 *
	 * @param array<string, array<string, mixed>> $catalog Catalog presets.
	 * @return array<string, array<string, mixed>>
	 */
	public function offer_german( $catalog ) {
		$catalog['de_DE'] = array(
			'name'          => 'German',
			'native_name'   => 'Deutsch',
			'locale'        => 'de_DE',
			'language_code' => 'de',
			'url_slug'      => 'de',
			'is_rtl'        => false,
		);

		return $catalog;
	}

	/**
	 * Builds a wizard module backed by isolated language and settings storage.
	 *
	 * @return SetupWizardModule
	 */
	private function wizard() {
		delete_option( OptionsLanguageRepository::OPTION_NAME );
		OptionsLanguageRepository::install();

		$this->languages = new LanguageManager(
			new OptionsLanguageRepository(),
			new LanguageValidator()
		);
		$this->settings  = new PluginSettings();

		return new SetupWizardModule( $this->languages, new LanguageCatalog(), $this->settings );
	}

	/**
	 * Runs one private wizard step handler.
	 *
	 * The public entry point redirects and exits, so the step itself is what a
	 * test can observe.
	 *
	 * @param SetupWizardModule $wizard Wizard module.
	 * @param int               $step   Step number.
	 * @return mixed
	 */
	private function run_step( SetupWizardModule $wizard, $step ) {
		$method = new ReflectionMethod( SetupWizardModule::class, 'save_step_' . $step );
		$method->setAccessible( true );

		return $method->invoke( $wizard );
	}

	/**
	 * Registers one catalog language directly.
	 *
	 * @param string $name   Language name.
	 * @param string $locale Locale.
	 * @param string $code   Language code.
	 * @return string Language identifier.
	 */
	private function add_language( $name, $locale, $code ) {
		$language = $this->languages->create(
			array(
				'name'          => $name,
				'native_name'   => $name,
				'locale'        => $locale,
				'language_code' => $code,
				'url_slug'      => $code,
				'is_rtl'        => false,
				'enabled'       => true,
			)
		);

		$this->assertNotWPError( $language );

		return $language['id'];
	}

	/**
	 * Adding a language keeps the wizard on the language step.
	 *
	 * @return void
	 */
	public function test_adding_a_language_stays_on_the_language_step() {
		$wizard = $this->wizard();
		$this->add_language( 'English', 'en_US', 'en' );

		// The catalog is normally fetched from WordPress.org, which a test run
		// cannot reach, so the locale being added is supplied directly.
		add_filter( 'localepress_language_catalog', array( $this, 'offer_german' ) );

		$_POST = array(
			'wizard_action' => 'add',
			'add_locale'    => 'de_DE',
		);

		$this->assertSame( 2, $this->run_step( $wizard, 2 ) );
		$this->assertCount( 2, $this->languages->get_languages() );
		$this->assertSame( 2, $this->settings->get_setup_step() );
	}

	/**
	 * The language step marks the chosen default before moving on.
	 *
	 * @return void
	 */
	public function test_language_step_sets_the_default_and_continues() {
		$wizard  = $this->wizard();
		$english = $this->add_language( 'English', 'en_US', 'en' );
		$german  = $this->add_language( 'German', 'de_DE', 'de' );

		$this->assertSame( $english, $this->languages->get_default_id() );

		$_POST = array(
			'wizard_action'    => 'continue',
			'default_language' => $german,
		);

		$this->assertTrue( $this->run_step( $wizard, 2 ) );
		$this->assertSame( $german, $this->languages->get_default_id() );
		$this->assertSame( 3, $this->settings->get_setup_step() );
	}

	/**
	 * The URL step stores the chosen format and each language domain.
	 *
	 * @return void
	 */
	public function test_url_step_stores_the_format_and_domains() {
		$wizard  = $this->wizard();
		$english = $this->add_language( 'English', 'en_US', 'en' );

		$_POST = array(
			'url_mode'       => LanguageHostResolver::MODE_DOMAIN,
			'prefix_default' => '1',
			'domain'         => array( $english => 'example.com' ),
		);

		$this->assertTrue( $this->run_step( $wizard, 3 ) );

		$url      = $this->settings->get_section( 'url' );
		$language = $this->languages->find( $english );

		$this->assertSame( LanguageHostResolver::MODE_DOMAIN, $url['mode'] );
		$this->assertTrue( (bool) $url['prefix_default'] );
		$this->assertSame( 'example.com', $language['domain'] );
	}

	/**
	 * A stale or tampered format leaves the stored one alone.
	 *
	 * @return void
	 */
	public function test_url_step_keeps_the_stored_format_for_an_unknown_value() {
		$wizard = $this->wizard();
		$this->add_language( 'English', 'en_US', 'en' );

		$_POST = array( 'url_mode' => LanguageHostResolver::MODE_SUBDOMAIN );
		$this->assertTrue( $this->run_step( $wizard, 3 ) );

		$_POST = array( 'url_mode' => 'nonsense' );
		$this->assertTrue( $this->run_step( $wizard, 3 ) );

		$url = $this->settings->get_section( 'url' );

		$this->assertSame( LanguageHostResolver::MODE_SUBDOMAIN, $url['mode'] );
	}
}
