<?php
/**
 * LocalePress admin module.
 *
 * @package LocalePress
 */

namespace LocalePress\Admin;

use LocalePress\Assets;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\Language\LanguageCatalog;
use LocalePress\Language\LanguageManager;
use LocalePress\Lifecycle\Activator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the language administration screen and action handlers.
 */
final class AdminModule implements ModuleInterface {

	/**
	 * Required capability for language management.
	 *
	 * @var string
	 */
	const DEFAULT_CAPABILITY = 'manage_options';

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Languages page renderer.
	 *
	 * @var LanguagesPage
	 */
	private $page;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager $language_manager Language manager.
	 * @param LanguageCatalog $language_catalog Language setup catalog.
	 */
	public function __construct( LanguageManager $language_manager, LanguageCatalog $language_catalog ) {
		$this->language_manager = $language_manager;
		$this->page             = new LanguagesPage( $language_manager, $language_catalog );
	}

	/**
	 * Returns the capability required to manage languages.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to manage LocalePress languages.
		 *
		 * @param string $capability Required capability.
		 */
		$capability = apply_filters( 'localepress_manage_languages_capability', self::DEFAULT_CAPABILITY );
		$capability = is_string( $capability ) ? sanitize_key( $capability ) : '';

		return '' !== $capability ? $capability : self::DEFAULT_CAPABILITY;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_setup' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'in_admin_header', array( $this, 'hide_foreign_admin_notices' ), 999 );
		add_action( 'admin_post_localepress_save_language', array( $this, 'save_language' ) );
		add_action( 'admin_post_localepress_delete_language', array( $this, 'delete_language' ) );
		add_action( 'admin_post_localepress_set_default_language', array( $this, 'set_default_language' ) );
		add_action( 'admin_post_localepress_toggle_language', array( $this, 'toggle_language' ) );
		add_action( 'admin_post_localepress_move_language', array( $this, 'move_language' ) );
	}

	/**
	 * Redirects the first eligible administrator request to initial setup.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_setup() {
		if ( false === get_transient( Activator::SETUP_REDIRECT_TRANSIENT ) ) {
			return;
		}

		if (
			! current_user_can( self::capability() )
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| is_network_admin()
		) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bulk-activation context.
		$is_bulk_activation = isset( $_GET['activate-multi'] );

		delete_transient( Activator::SETUP_REDIRECT_TRANSIENT );

		if ( $is_bulk_activation || $this->language_manager->has_languages() ) {
			return;
		}

		$setup_url = add_query_arg( 'page', 'localepress-setup', admin_url( 'admin.php' ) );

		wp_safe_redirect( $setup_url );
		exit;
	}

	/**
	 * Registers the top-level LocalePress menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			esc_html__( 'LocalePress Languages', 'localepress' ),
			esc_html__( 'LocalePress', 'localepress' ),
			self::capability(),
			'localepress',
			array( $this->page, 'render' ),
			'dashicons-translation',
			81
		);
	}

	/**
	 * Loads assets only on the LocalePress page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_localepress' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'localepress-admin',
			LOCALEPRESS_URL . 'assets/css/admin.css',
			array(),
			Assets::version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'localepress-admin',
			LOCALEPRESS_URL . 'assets/js/admin.js',
			array(),
			Assets::version( 'assets/js/admin.js' ),
			true
		);
	}

	/**
	 * Removes unrelated admin notices from LocalePress screens.
	 *
	 * LocalePress prints its own feedback inside each page rather than on the
	 * notice hooks, so nothing of its own is lost. This runs on `in_admin_header`
	 * because every plugin has registered its notices by then and WordPress has
	 * not printed them yet.
	 *
	 * @return void
	 */
	public function hide_foreign_admin_notices() {
		$page = $this->get_current_admin_page();

		if ( '' === $page ) {
			return;
		}

		/**
		 * Filters whether LocalePress hides unrelated notices on its own screens.
		 *
		 * @param bool   $hide Whether unrelated admin notices should be removed.
		 * @param string $page Current LocalePress admin page slug.
		 */
		if ( ! apply_filters( 'localepress_hide_foreign_admin_notices', true, $page ) ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	}

	/**
	 * Returns the LocalePress admin page slug for the current request.
	 *
	 * The page query variable is used instead of the screen identifier because
	 * WordPress derives that identifier from the translated menu title.
	 *
	 * @return string Empty when this is not a LocalePress screen.
	 */
	private function get_current_admin_page() {
		if ( wp_doing_ajax() || is_network_admin() || is_user_admin() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) )
			: '';

		return 'localepress' === $page || 0 === strpos( $page, 'localepress-' ) ? $page : '';
	}

	/**
	 * Creates or updates a language.
	 *
	 * @return void
	 */
	public function save_language() {
		$this->authorize( 'localepress_save_language' );

		$language_id = $this->posted_language_id();
		$input       = $this->posted_language_input();
		$is_setup    = '' === $language_id && ! $this->language_manager->has_languages();
		$result      = '' === $language_id
			? $this->language_manager->create( $input )
			: $this->language_manager->update( $language_id, $input );

		if ( is_wp_error( $result ) ) {
			$this->store_form_error( $result, $input, $language_id );
			$this->redirect_to_editor( $language_id, $result->get_error_code() );
		}

		if ( $is_setup ) {
			$this->redirect( 'setup_complete' );
		}

		$this->redirect( '' === $language_id ? 'language_added' : 'language_updated' );
	}

	/**
	 * Deletes a language.
	 *
	 * @return void
	 */
	public function delete_language() {
		$language_id = $this->posted_language_id();
		$this->authorize( 'localepress_delete_language_' . $language_id );

		$this->redirect_from_result( $this->language_manager->delete( $language_id ), 'language_deleted' );
	}

	/**
	 * Sets the default language.
	 *
	 * @return void
	 */
	public function set_default_language() {
		$language_id = $this->posted_language_id();
		$this->authorize( 'localepress_set_default_language_' . $language_id );

		$this->redirect_from_result( $this->language_manager->set_default( $language_id ), 'default_updated' );
	}

	/**
	 * Enables or disables a language.
	 *
	 * @return void
	 */
	public function toggle_language() {
		$language_id = $this->posted_language_id();
		$this->authorize( 'localepress_toggle_language_' . $language_id );

		$this->redirect_from_result( $this->language_manager->toggle( $language_id ), 'status_updated' );
	}

	/**
	 * Moves a language one position.
	 *
	 * @return void
	 */
	public function move_language() {
		$language_id = $this->posted_language_id();
		$this->authorize( 'localepress_move_language_' . $language_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorize() verifies this request above.
		$direction = isset( $_POST['direction'] )
			? sanitize_key( wp_unslash( $_POST['direction'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->redirect_from_result( $this->language_manager->move( $language_id, $direction ), 'order_updated' );
	}

	/**
	 * Verifies permissions and the current request nonce.
	 *
	 * @param string $nonce_action Expected nonce action.
	 * @return void
	 */
	private function authorize( $nonce_action ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage LocalePress languages.', 'localepress' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Reads a language identifier from the request.
	 *
	 * @return string
	 */
	private function posted_language_id() {
		// The ID selects the nonce action that callers verify before mutation.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$language_id = isset( $_POST['language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $language_id;
	}

	/**
	 * Reads only supported scalar language fields from the request.
	 *
	 * @return array<string, string>
	 */
	private function posted_language_input() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- save_language() verifies before calling.
		$posted = isset( $_POST['language'] ) && is_array( $_POST['language'] )
			? map_deep( wp_unslash( $_POST['language'] ), 'sanitize_text_field' )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$input = array();

		foreach ( array( 'name', 'locale', 'language_code', 'url_slug', 'native_name', 'is_rtl', 'enabled', 'domain' ) as $field ) {
			$input[ $field ] = isset( $posted[ $field ] ) && is_scalar( $posted[ $field ] )
				? (string) $posted[ $field ]
				: '';
		}

		return $input;
	}

	/**
	 * Stores failed form data for one redirect.
	 *
	 * @param WP_Error             $error       Validation error.
	 * @param array<string, mixed> $input       Submitted fields.
	 * @param string               $language_id Language identifier, if editing.
	 * @return void
	 */
	private function store_form_error( WP_Error $error, array $input, $language_id ) {
		set_transient(
			'localepress_form_' . get_current_user_id(),
			array(
				'error'       => $error->get_error_code(),
				'input'       => $input,
				'language_id' => $language_id,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirects back to an editor after failed validation.
	 *
	 * @param string $language_id Language identifier, if editing.
	 * @param string $error_code  Error code.
	 * @return void
	 */
	private function redirect_to_editor( $language_id, $error_code ) {
		if ( '' !== $language_id ) {
			$editor_action = 'edit';
		} else {
			$editor_action = $this->language_manager->has_languages() ? 'add' : 'setup';
		}

		$arguments = array(
			'page'        => 'localepress',
			'action'      => $editor_action,
			'lp_error'    => sanitize_key( $error_code ),
			'language_id' => $language_id,
		);

		wp_safe_redirect( add_query_arg( $arguments, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirects from a domain operation.
	 *
	 * @param mixed  $result       Operation result.
	 * @param string $success_code Success notice code.
	 * @return void
	 */
	private function redirect_from_result( $result, $success_code ) {
		if ( is_wp_error( $result ) ) {
			$this->redirect( '', $result->get_error_code() );
		}

		$this->redirect( $success_code );
	}

	/**
	 * Redirects to the languages list with a controlled notice code.
	 *
	 * @param string $notice_code Success notice code.
	 * @param string $error_code  Error notice code.
	 * @return void
	 */
	private function redirect( $notice_code = '', $error_code = '' ) {
		$arguments = array( 'page' => 'localepress' );

		if ( '' !== $notice_code ) {
			$arguments['lp_notice'] = sanitize_key( $notice_code );
		}

		if ( '' !== $error_code ) {
			$arguments['lp_error'] = sanitize_key( $error_code );
		}

		wp_safe_redirect( add_query_arg( $arguments, admin_url( 'admin.php' ) ) );
		exit;
	}
}
