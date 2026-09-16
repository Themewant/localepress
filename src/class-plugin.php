<?php
/**
 * LocalePress application bootstrap.
 *
 * @package LocalePress
 */

namespace LocalePress;

defined( 'ABSPATH' ) || exit;

use LocalePress\Admin\AdminLanguageFilter;
use LocalePress\Admin\AdminLanguageFilterModule;
use LocalePress\Admin\AdminModule;
use LocalePress\Admin\AdminNoticeGate;
use LocalePress\Admin\AdminTextDirectionModule;
use LocalePress\Admin\ContentTranslationModule;
use LocalePress\Admin\SetupWizardModule;
use LocalePress\Admin\SettingsModule;
use LocalePress\Admin\StringTranslationModule;
use LocalePress\Admin\StringTranslationQuery;
use LocalePress\Admin\MediaTranslationFields;
use LocalePress\Admin\TermTranslationModule;
use LocalePress\Admin\TranslationDashboardModule;
use LocalePress\Admin\TranslationDashboardQuery;
use LocalePress\Content\CommentLanguageModule;
use LocalePress\Content\PostTranslationManager;
use LocalePress\Content\PostTypeSupport;
use LocalePress\Content\DirectQueryLanguageModule;
use LocalePress\Content\QueryIdTranslationModule;
use LocalePress\Content\TranslationLifecycleModule;
use LocalePress\Contracts\LanguageRepositoryInterface;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\Contracts\StringRepositoryInterface;
use LocalePress\Contracts\TermTranslationRepositoryInterface;
use LocalePress\Contracts\TranslationDashboardRepositoryInterface;
use LocalePress\Contracts\TranslationRepositoryInterface;
use LocalePress\Infrastructure\DatabaseTermTranslationRepository;
use LocalePress\Infrastructure\DatabaseStringRepository;
use LocalePress\Infrastructure\DatabaseTranslationDashboardRepository;
use LocalePress\Infrastructure\DatabaseTranslationRepository;
use LocalePress\Infrastructure\OptionsLanguageRepository;
use LocalePress\Integrations\Elementor\ElementorCompatibility;
use LocalePress\Integrations\Cache\CacheCompatibilityModule;
use LocalePress\Integrations\Elementor\ElementorModule;
use LocalePress\Integrations\Elementor\ElementorThemeBuilder;
use LocalePress\Integrations\Elementor\ElementorThemeBuilderModule;
use LocalePress\Integrations\Elementor\ElementorWidgetModule;
use LocalePress\Integrations\Seo\RankMathProvider;
use LocalePress\Integrations\Seo\SeoMetaModule;
use LocalePress\Integrations\Seo\SeoPressProvider;
use LocalePress\Integrations\Seo\YoastSeoProvider;
use LocalePress\Integrations\Wpml\WpmlConfigModule;
use LocalePress\Integrations\Wpml\WpmlConfigReader;
use LocalePress\Language\BrowserLanguageDetector;
use LocalePress\Language\CurrentLanguageResolver;
use LocalePress\Language\LanguageCatalog;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageTag;
use LocalePress\Language\LanguageValidator;
use LocalePress\Language\LocaleModule;
use LocalePress\Lifecycle\Installer;
use LocalePress\Media\MediaModule;
use LocalePress\Media\MediaTranslationManager;
use LocalePress\Navigation\MenuLanguageManager;
use LocalePress\Navigation\MenuLocationsModule;
use LocalePress\Navigation\NavigationModule;
use LocalePress\Rest\RestLanguageModule;
use LocalePress\Routing\HostOriginModule;
use LocalePress\Routing\LanguageDetectionModule;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Routing\RoutingModule;
use LocalePress\Routing\SearchFormModule;
use LocalePress\SEO\SeoMetadata;
use LocalePress\SEO\SeoModule;
use LocalePress\SEO\SitemapModule;
use LocalePress\Settings\PluginSettings;
use LocalePress\Settings\WorkflowSettings;
use LocalePress\StringTranslation\CoreStringModule;
use LocalePress\StringTranslation\OptionStringTranslator;
use LocalePress\StringTranslation\RegisteredStringValidator;
use LocalePress\Sync\SyncModule;
use LocalePress\Sync\TranslationSynchronizer;
use LocalePress\StringTranslation\StringManager;
use LocalePress\StringTranslation\StringModule;
use LocalePress\Switcher\LanguageSwitcher;
use LocalePress\Switcher\NavigationMenuIntegration;
use LocalePress\Switcher\SwitcherModule;
use LocalePress\Taxonomy\DefaultTermModule;
use LocalePress\Taxonomy\TaxonomySupport;
use LocalePress\Taxonomy\TermTranslationLifecycleModule;
use LocalePress\Taxonomy\TermTranslationManager;

/**
 * Composes services and modules for the plugin.
 */
final class Plugin {

	/**
	 * Shared plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Whether modules have been registered.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager|null
	 */
	private $language_manager;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver|null
	 */
	private $current_language_resolver;

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager|null
	 */
	private $post_translation_manager;

	/**
	 * Term translation relationship manager.
	 *
	 * @var TermTranslationManager|null
	 */
	private $term_translation_manager;

	/**
	 * Language-aware frontend URL service.
	 *
	 * @var LanguageUrlManager|null
	 */
	private $language_url_manager;

	/**
	 * Language switcher API and renderer.
	 *
	 * @var LanguageSwitcher|null
	 */
	private $language_switcher;

	/**
	 * Multilingual SEO metadata API.
	 *
	 * @var SeoMetadata|null
	 */
	private $seo_metadata;

	/**
	 * Navigation menu language service.
	 *
	 * @var MenuLanguageManager|null
	 */
	private $menu_language_manager;

	/**
	 * Translation workflow settings.
	 *
	 * @var WorkflowSettings|null
	 */
	private $workflow_settings;

	/**
	 * Central plugin configuration.
	 *
	 * @var PluginSettings|null
	 */
	private $plugin_settings;

	/**
	 * Optional Elementor document compatibility service.
	 *
	 * @var ElementorCompatibility|null
	 */
	private $elementor_compatibility;

	/**
	 * Registered string translation service.
	 *
	 * @var StringManager|null
	 */
	private $string_manager;

	/**
	 * Translation copy and synchronization engine.
	 *
	 * @var TranslationSynchronizer|null
	 */
	private $translation_synchronizer;

	/**
	 * Media translation service.
	 *
	 * @var MediaTranslationManager|null
	 */
	private $media_translations;

	/**
	 * Returns the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Builds services and registers plugin modules.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		Installer::maybe_upgrade();

		$repository = new OptionsLanguageRepository();

		/**
		 * Filters the language repository implementation.
		 *
		 * Addons may provide a different repository before LocalePress boots.
		 *
		 * @param LanguageRepositoryInterface $repository Default Options API repository.
		 */
		$repository = apply_filters( 'localepress_language_repository', $repository );

		if ( ! $repository instanceof LanguageRepositoryInterface ) {
			$repository = new OptionsLanguageRepository();
		}

		$this->language_manager          = new LanguageManager( $repository, new LanguageValidator() );
		$this->current_language_resolver = new CurrentLanguageResolver( $this->language_manager );

		$string_repository = new DatabaseStringRepository();

		/**
		 * Filters the registered string repository implementation.
		 *
		 * @param StringRepositoryInterface $string_repository Default database repository.
		 */
		$string_repository = apply_filters( 'localepress_string_repository', $string_repository );

		if ( ! $string_repository instanceof StringRepositoryInterface ) {
			$string_repository = new DatabaseStringRepository();
		}

		$this->string_manager          = new StringManager(
			$string_repository,
			$this->language_manager,
			$this->current_language_resolver,
			new RegisteredStringValidator()
		);
		$this->plugin_settings         = new PluginSettings();
		$this->workflow_settings       = new WorkflowSettings();
		$this->menu_language_manager   = new MenuLanguageManager( $this->language_manager );
		$this->elementor_compatibility = new ElementorCompatibility();

		$translation_repository = new DatabaseTranslationRepository();

		/**
		 * Filters the post translation repository implementation.
		 *
		 * @param TranslationRepositoryInterface $translation_repository Default database repository.
		 */
		$translation_repository = apply_filters( 'localepress_translation_repository', $translation_repository );

		if ( ! $translation_repository instanceof TranslationRepositoryInterface ) {
			$translation_repository = new DatabaseTranslationRepository();
		}

		$post_type_support              = new PostTypeSupport( $this->plugin_settings );
		$this->post_translation_manager = new PostTranslationManager(
			$translation_repository,
			$this->language_manager,
			$post_type_support,
			$this->workflow_settings
		);

		$term_translation_repository = new DatabaseTermTranslationRepository();

		/**
		 * Filters the term translation repository implementation.
		 *
		 * @param TermTranslationRepositoryInterface $term_translation_repository Default database repository.
		 */
		$term_translation_repository = apply_filters(
			'localepress_term_translation_repository',
			$term_translation_repository
		);

		if ( ! $term_translation_repository instanceof TermTranslationRepositoryInterface ) {
			$term_translation_repository = new DatabaseTermTranslationRepository();
		}

		$taxonomy_support               = new TaxonomySupport( $this->plugin_settings );
		$this->term_translation_manager = new TermTranslationManager(
			$term_translation_repository,
			$this->language_manager,
			$taxonomy_support
		);
		$this->language_url_manager     = new LanguageUrlManager(
			$this->language_manager,
			$this->post_translation_manager,
			$this->term_translation_manager,
			$this->plugin_settings
		);
		$language_tag                   = new LanguageTag();
		$this->language_switcher        = new LanguageSwitcher(
			$this->language_manager,
			$this->language_url_manager,
			$language_tag,
			$this->plugin_settings
		);
		$this->seo_metadata             = new SeoMetadata(
			$this->language_manager,
			$this->language_url_manager,
			$this->post_translation_manager,
			$this->term_translation_manager,
			$language_tag,
			$this->plugin_settings
		);
		$this->booted                   = true;

		$this->media_translations       = new MediaTranslationManager( $this->post_translation_manager );
		$this->translation_synchronizer = new TranslationSynchronizer(
			$this->post_translation_manager,
			$this->term_translation_manager,
			$this->workflow_settings,
			$taxonomy_support
		);

		$option_strings = new OptionStringTranslator( $this->string_manager );

		// Held in a variable because the cache compatibility module writes the
		// same cookie from the browser, and has to describe it identically.
		$language_detection = new LanguageDetectionModule(
			$this->language_url_manager,
			$this->language_manager,
			new BrowserLanguageDetector(),
			$this->plugin_settings
		);

		$modules = array(
			// Registered first: the locale filter must exist before WordPress
			// loads the default text domain right after `plugins_loaded`.
			new LocaleModule( $this->language_url_manager ),
			new StringModule( $this->string_manager ),
			// One translator is shared, so an option named by more than one source
			// is filtered once and keeps the group it was first registered under.
			// The catalog runs first for that reason: the site title stays in
			// `WordPress` even if a plugin's configuration file also claims it.
			new CoreStringModule( $option_strings, $this->language_manager ),
			// Registered before the content modules: what a wpml-config.xml file
			// declares has to be in place before anything asks which post types,
			// taxonomies, or custom fields are translatable.
			new WpmlConfigModule(
				new WpmlConfigReader(),
				$option_strings,
				$this->language_manager,
				$this->workflow_settings
			),
			// After both string sources, so an option one of them already claimed
			// keeps the group it was registered under. Its own work is on the
			// copy filters, which nothing has reached yet.
			new SeoMetaModule(
				array(
					new YoastSeoProvider(),
					new RankMathProvider(),
					new SeoPressProvider(),
				),
				$this->term_translation_manager,
				$this->workflow_settings,
				$option_strings,
				$this->language_manager
			),
			new TranslationLifecycleModule( $this->post_translation_manager ),
			new TermTranslationLifecycleModule( $this->term_translation_manager ),
			new DefaultTermModule(
				$this->term_translation_manager,
				$this->post_translation_manager,
				$this->language_manager,
				$taxonomy_support,
				$this->current_language_resolver,
				$this->plugin_settings
			),
			new RoutingModule(
				$this->language_url_manager,
				$this->post_translation_manager,
				$this->term_translation_manager,
				$this->plugin_settings
			),
			$language_detection,
			// Registered next to detection because it stands in for it wherever a
			// page cache answers a request before PHP can.
			new CacheCompatibilityModule(
				$language_detection,
				$this->language_url_manager,
				$this->post_translation_manager
			),
			// Host routing serves the document from one host; this keeps every URL
			// the document loads on that same host.
			new HostOriginModule( $this->language_url_manager ),
			// After routing: the request language has to be resolved before a
			// query's identifiers can be rewritten to it.
			new QueryIdTranslationModule(
				$this->language_url_manager,
				$this->post_translation_manager,
				$this->term_translation_manager
			),
			new CommentLanguageModule(
				$this->language_url_manager,
				$this->post_translation_manager
			),
			// The pages WordPress answers with SQL of its own rather than through
			// WP_Query: the adjacent post links, the archive list, the calendar.
			new DirectQueryLanguageModule(
				$this->language_url_manager,
				$this->post_translation_manager
			),
			new SearchFormModule( $this->language_url_manager ),
			new NavigationModule(
				$this->menu_language_manager,
				$this->post_translation_manager,
				$this->term_translation_manager,
				$this->language_url_manager
			),
			new SwitcherModule(
				$this->language_switcher,
				new NavigationMenuIntegration( $this->language_switcher ),
				$this->language_manager,
				$this->plugin_settings,
				$this->language_url_manager
			),
			new RestLanguageModule(
				$this->post_translation_manager,
				$this->term_translation_manager,
				$this->language_manager,
				$this->language_url_manager
			),
			new SeoModule( $this->seo_metadata ),
			new SitemapModule(
				$this->language_url_manager,
				$this->language_manager,
				$this->post_translation_manager,
				$this->term_translation_manager,
				$this->plugin_settings
			),
			new ElementorModule( $this->elementor_compatibility ),
			// Kept apart from the document copy for the same reason: this one
			// answers which template a theme location renders, which happens on
			// every front-end request whether or not anything was ever translated.
			new ElementorThemeBuilderModule(
				new ElementorThemeBuilder(
					$this->post_translation_manager,
					$this->term_translation_manager
				),
				$this->post_translation_manager,
				$this->language_url_manager
			),
			// Its hooks exist only while Elementor is drawing its panel, so the
			// module costs a site without Elementor two listeners that never run.
			new ElementorWidgetModule(),
			new SyncModule(
				$this->translation_synchronizer,
				$this->post_translation_manager,
				$this->workflow_settings
			),
			new MediaModule(
				$this->media_translations,
				$this->post_translation_manager,
				$this->current_language_resolver
			),
		);

		if ( is_admin() ) {
			$modules[] = new AdminNoticeGate();
			$modules[] = new AdminModule( $this->language_manager, new LanguageCatalog() );
			$modules[] = new AdminLanguageFilterModule(
				new AdminLanguageFilter( $this->language_manager ),
				$this->language_manager,
				$this->post_translation_manager,
				$this->term_translation_manager
			);
			$modules[] = new SetupWizardModule(
				$this->language_manager,
				new LanguageCatalog(),
				$this->plugin_settings
			);
			$modules[] = new StringTranslationModule(
				$this->string_manager,
				new StringTranslationQuery( $string_repository, $this->language_manager )
			);

			$dashboard_repository = $translation_repository instanceof TranslationDashboardRepositoryInterface
				? $translation_repository
				: null;

			if ( null === $dashboard_repository && $translation_repository instanceof DatabaseTranslationRepository ) {
				$dashboard_repository = new DatabaseTranslationDashboardRepository();
			}

			/**
			 * Filters the optional translation dashboard reporting repository.
			 *
			 * Custom translation storage can return null to disable the dashboard or
			 * provide its own bounded reporting implementation.
			 *
			 * @param TranslationDashboardRepositoryInterface|null $dashboard_repository   Reporting repository.
			 * @param TranslationRepositoryInterface                $translation_repository Active relationship repository.
			 */
			$dashboard_repository = apply_filters(
				'localepress_translation_dashboard_repository',
				$dashboard_repository,
				$translation_repository
			);

			if ( ! $dashboard_repository instanceof TranslationDashboardRepositoryInterface ) {
				$dashboard_repository = null;
			}

			$modules[] = new TranslationDashboardModule(
				new TranslationDashboardQuery(
					$dashboard_repository,
					$this->post_translation_manager,
					$this->language_manager
				),
				$this->post_translation_manager
			);
			$modules[] = new MenuLocationsModule(
				$this->menu_language_manager,
				$this->language_manager
			);
			$modules[] = new SettingsModule(
				$this->language_manager,
				$this->menu_language_manager,
				$this->workflow_settings,
				$this->plugin_settings,
				$post_type_support,
				$taxonomy_support,
				$this->term_translation_manager
			);
			$modules[] = new ContentTranslationModule( $this->post_translation_manager, $this->language_manager );
			$modules[] = new TermTranslationModule( $this->term_translation_manager, $this->language_manager );
			$modules[] = new MediaTranslationFields( $this->post_translation_manager, $this->language_manager );
			$modules[] = new AdminTextDirectionModule( $this->post_translation_manager, $this->language_manager );
		}

		/**
		 * Filters the modules registered by LocalePress.
		 *
		 * Future builder and addon modules can register
		 * through this composition boundary.
		 *
		 * @param array<int, ModuleInterface> $modules Plugin modules.
		 * @param Plugin                      $plugin  Plugin application.
		 */
		$filtered_modules = apply_filters( 'localepress_modules', $modules, $this );

		if ( ! is_array( $filtered_modules ) ) {
			$filtered_modules = $modules;
		}

		if ( is_array( $filtered_modules ) ) {
			foreach ( $filtered_modules as $module ) {
				if ( $module instanceof ModuleInterface ) {
					$module->register();
				}
			}
		}

		/**
		 * Fires after LocalePress services and modules have been registered.
		 *
		 * @param Plugin $plugin Plugin application.
		 */
		do_action( 'localepress_loaded', $this );
	}

	/**
	 * Returns the language manager.
	 *
	 * @return LanguageManager
	 */
	public function languages() {
		return $this->language_manager;
	}

	/**
	 * Resolves the current language.
	 *
	 * @return array<string, mixed>|null
	 */
	public function current_language() {
		return $this->current_language_resolver->resolve();
	}

	/**
	 * Returns the post translation relationship API.
	 *
	 * @return PostTranslationManager
	 */
	public function translations() {
		return $this->post_translation_manager;
	}

	/**
	 * Returns the term translation relationship API.
	 *
	 * @return TermTranslationManager
	 */
	public function term_translations() {
		return $this->term_translation_manager;
	}

	/**
	 * Returns the language-aware frontend URL API.
	 *
	 * @return LanguageUrlManager
	 */
	public function urls() {
		return $this->language_url_manager;
	}

	/**
	 * Returns the language switcher template API.
	 *
	 * @return LanguageSwitcher
	 */
	public function switcher() {
		return $this->language_switcher;
	}

	/**
	 * Returns the multilingual SEO metadata API.
	 *
	 * @return SeoMetadata
	 */
	public function seo() {
		return $this->seo_metadata;
	}

	/**
	 * Returns the navigation menu language API.
	 *
	 * @return MenuLanguageManager
	 */
	public function menus() {
		return $this->menu_language_manager;
	}

	/**
	 * Returns translation workflow settings.
	 *
	 * @return WorkflowSettings
	 */
	public function workflow_settings() {
		return $this->workflow_settings;
	}

	/**
	 * Returns the translation copy and synchronization engine.
	 *
	 * @return TranslationSynchronizer
	 */
	public function synchronizer() {
		return $this->translation_synchronizer;
	}

	/**
	 * Returns the media translation API.
	 *
	 * @return MediaTranslationManager
	 */
	public function media() {
		return $this->media_translations;
	}

	/**
	 * Returns central plugin configuration.
	 *
	 * @return PluginSettings
	 */
	public function settings() {
		return $this->plugin_settings;
	}

	/**
	 * Returns the registered string translation API.
	 *
	 * @return StringManager|null
	 */
	public function strings() {
		return $this->string_manager;
	}

	/**
	 * Returns optional Elementor document compatibility.
	 *
	 * The service remains available when Elementor is inactive and reports that
	 * state through is_available() without loading Elementor classes.
	 *
	 * @return ElementorCompatibility
	 */
	public function elementor() {
		return $this->elementor_compatibility;
	}

	/**
	 * Prevents direct construction.
	 */
	private function __construct() {}

	/**
	 * Prevents cloning the application singleton.
	 *
	 * @return void
	 */
	private function __clone() {}
}
