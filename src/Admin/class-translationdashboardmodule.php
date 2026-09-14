<?php
/**
 * Translation dashboard admin module.
 *
 * @package LocalePress
 */

namespace LocalePress\Admin;

use LocalePress\Assets;
use LocalePress\Content\PostTranslationManager;
use LocalePress\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the central translation dashboard.
 */
final class TranslationDashboardModule implements ModuleInterface {

	/**
	 * Dashboard query service.
	 *
	 * @var TranslationDashboardQuery
	 */
	private $dashboard_query;

	/**
	 * Translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $translation_manager;

	/**
	 * Registered admin screen hook suffix.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param TranslationDashboardQuery $dashboard_query     Dashboard query service.
	 * @param PostTranslationManager    $translation_manager Translation manager.
	 */
	public function __construct(
		TranslationDashboardQuery $dashboard_query,
		PostTranslationManager $translation_manager
	) {
		$this->dashboard_query     = $dashboard_query;
		$this->translation_manager = $translation_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'set_screen_option_localepress_translations_per_page', array( $this, 'save_per_page' ), 10, 3 );
	}

	/**
	 * Registers LocalePress -> Translations and its load callback.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = (string) add_submenu_page(
			'localepress',
			esc_html__( 'LocalePress Translations', 'localepress' ),
			esc_html__( 'Translations', 'localepress' ),
			AdminModule::capability(),
			'localepress-translations',
			array( $this, 'render' )
		);

		if ( '' !== $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'add_screen_options' ) );
		}
	}

	/**
	 * Adds the standard per-page screen option.
	 *
	 * @return void
	 */
	public function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Content per page', 'localepress' ),
				'default' => 20,
				'option'  => 'localepress_translations_per_page',
			)
		);
	}

	/**
	 * Validates the dashboard per-page preference.
	 *
	 * @param mixed  $status Existing screen option value.
	 * @param string $option Screen option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public function save_per_page( $status, $option, $value ) {
		if ( 'localepress_translations_per_page' !== $option ) {
			return $status;
		}

		return min( 100, max( 1, absint( $value ) ) );
	}

	/**
	 * Loads dashboard styles only on this screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->hook_suffix || $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'localepress-admin',
			LOCALEPRESS_URL . 'assets/css/admin.css',
			array(),
			Assets::version( 'assets/css/admin.css' )
		);
	}

	/**
	 * Renders the translations list table.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( AdminModule::capability() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to access this page.', 'localepress' ),
				'',
				array( 'response' => 403 )
			);
		}

		echo '<div class="wrap localepress-translation-dashboard">';
		echo '<h1>' . esc_html__( 'Translations', 'localepress' ) . '</h1>';

		if ( ! $this->dashboard_query->is_available() ) {
			echo '<div class="localepress-notice notice notice-warning inline"><p>';
			esc_html_e( 'The active translation storage does not provide dashboard reporting.', 'localepress' );
			echo '</p></div></div>';
			return;
		}

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new TranslationDashboardListTable(
			$this->dashboard_query,
			$this->translation_manager,
			new TranslationActions()
		);
		$table->prepare_items();

		/**
		 * Fires before the translation dashboard form.
		 *
		 * @param TranslationDashboardListTable $table Prepared list table.
		 */
		do_action( 'localepress_translation_dashboard_before_table', $table );

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="localepress-translations" />';
		$table->search_box( __( 'Search content', 'localepress' ), 'localepress-translations' );
		$table->display();
		echo '</form>';

		/**
		 * Fires after the translation dashboard form.
		 *
		 * @param TranslationDashboardListTable $table Prepared list table.
		 */
		do_action( 'localepress_translation_dashboard_after_table', $table );
		echo '</div>';
	}
}
