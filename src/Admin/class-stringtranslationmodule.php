<?php
/**
 * String translation admin module.
 *
 * @package LocalePress
 */

namespace LocalePress\Admin;

use LocalePress\Assets;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\StringTranslation\StringManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and secures the central registered-string editor.
 */
final class StringTranslationModule implements ModuleInterface {

	/**
	 * String manager.
	 *
	 * @var StringManager
	 */
	private $string_manager;

	/**
	 * String translation query service.
	 *
	 * @var StringTranslationQuery
	 */
	private $query;

	/**
	 * Registered admin screen hook suffix.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param StringManager          $string_manager String manager.
	 * @param StringTranslationQuery $query          Query service.
	 */
	public function __construct( StringManager $string_manager, StringTranslationQuery $query ) {
		$this->string_manager = $string_manager;
		$this->query          = $query;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_localepress_save_string_translations', array( $this, 'save_translations' ) );
		add_filter( 'set_screen_option_localepress_strings_per_page', array( $this, 'save_per_page' ), 10, 3 );
	}

	/**
	 * Registers LocalePress -> String Translation.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = (string) add_submenu_page(
			'localepress',
			esc_html__( 'LocalePress String Translation', 'localepress' ),
			esc_html__( 'String Translation', 'localepress' ),
			AdminModule::capability(),
			'localepress-string-translations',
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
				'label'   => __( 'Strings per page', 'localepress' ),
				'default' => 20,
				'option'  => 'localepress_strings_per_page',
			)
		);
	}

	/**
	 * Validates the per-page preference.
	 *
	 * @param mixed  $status Existing screen option value.
	 * @param string $option Screen option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public function save_per_page( $status, $option, $value ) {
		if ( 'localepress_strings_per_page' !== $option ) {
			return $status;
		}

		return min( 100, max( 1, absint( $value ) ) );
	}

	/**
	 * Loads admin styling only on the string translation screen.
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
	 * Renders filters and the registered-string editor.
	 *
	 * @return void
	 */
	public function render() {
		$this->authorize();
		$this->string_manager->flush_registered_strings();

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new StringTranslationListTable( $this->query );
		$table->prepare_items();
		$args        = $table->get_query_args();
		$languages   = $table->get_languages();
		$language_id = $args['language_id'];

		echo '<div class="wrap localepress-string-translation-page">';
		echo '<h1>' . esc_html__( 'String Translation', 'localepress' ) . '</h1>';
		$this->render_notice();

		if ( empty( $languages ) ) {
			echo '<div class="localepress-notice notice notice-warning inline"><p>';
			esc_html_e( 'Enable at least one language before translating registered strings.', 'localepress' );
			echo '</p></div>';
		}

		$this->render_filters( $table, $args );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="localepress_save_string_translations" />';
		echo '<input type="hidden" name="language_id" value="' . esc_attr( $language_id ) . '" />';
		$this->render_redirect_fields( $args );
		wp_nonce_field( 'localepress_save_string_translations_' . $language_id );
		$table->display();

		if ( '' !== $language_id && $table->has_items() ) {
			submit_button( __( 'Save translations', 'localepress' ) );
		}

		echo '</form></div>';
	}

	/**
	 * Saves the submitted page of translations, one language at a time.
	 *
	 * Fields are always posted as `translations[language][string]`, so a page
	 * showing every language saves through exactly the same path as a page
	 * showing one. Each language is validated against the enabled registry
	 * before the manager sees it, and an unknown language is skipped rather
	 * than failing the whole page.
	 *
	 * @return void
	 */
	public function save_translations() {
		$this->authorize();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Selects the nonce action below.
		$view = isset( $_POST['language_id'] ) && is_scalar( $_POST['language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( 'localepress_save_string_translations_' . $view );

		// Verified above; the manager validates every ID and value before mutation.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted = isset( $_POST['translations'] ) && is_array( $_POST['translations'] )
			? wp_unslash( $_POST['translations'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$enabled = array_values( wp_list_pluck( $this->query->get_languages(), 'id' ) );
		$error   = null;

		foreach ( $submitted as $language_id => $translations ) {
			$language_id = is_scalar( $language_id ) ? sanitize_text_field( (string) $language_id ) : '';

			if ( ! in_array( $language_id, $enabled, true ) || ! is_array( $translations ) ) {
				continue;
			}

			$result = $this->string_manager->save_translations( $language_id, $translations );

			if ( is_wp_error( $result ) ) {
				$error = $result;

				break;
			}
		}

		$this->redirect_after_save( $error );
	}

	/**
	 * Renders the GET filter form.
	 *
	 * @param StringTranslationListTable $table Prepared table.
	 * @param array<string, mixed>       $args  Normalized filters.
	 * @return void
	 */
	private function render_filters( $table, $args ) {
		echo '<form method="get" class="localepress-string-filters">';
		echo '<input type="hidden" name="page" value="localepress-string-translations" />';
		echo '<label class="screen-reader-text" for="localepress-string-group">' . esc_html__( 'Filter by string group', 'localepress' ) . '</label>';
		echo '<select id="localepress-string-group" name="localepress_group">';
		echo '<option value="">' . esc_html__( 'All string groups', 'localepress' ) . '</option>';

		foreach ( $table->get_groups() as $group ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $group ),
				selected( $args['group'], $group, false ),
				esc_html( $group )
			);
		}

		echo '</select>';
		echo '<label class="screen-reader-text" for="localepress-string-language">' . esc_html__( 'Select translation language', 'localepress' ) . '</label>';
		echo '<select id="localepress-string-language" name="localepress_language">';

		if ( empty( $table->get_languages() ) ) {
			echo '<option value="">' . esc_html__( 'No enabled languages', 'localepress' ) . '</option>';
		} else {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( StringTranslationQuery::ALL_LANGUAGES ),
				selected( $args['language_id'], StringTranslationQuery::ALL_LANGUAGES, false ),
				esc_html__( 'All languages', 'localepress' )
			);
		}

		foreach ( $table->get_languages() as $language_id => $language ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $language_id ),
				selected( $args['language_id'], $language_id, false ),
				esc_html( $language['name'] )
			);
		}

		echo '</select>';
		submit_button( __( 'Filter', 'localepress' ), '', 'filter_action', false );
		$table->search_box( __( 'Search strings', 'localepress' ), 'localepress-strings' );
		echo '</form>';
	}

	/**
	 * Preserves allowlisted filters after a save redirect.
	 *
	 * @param array<string, mixed> $args Normalized filters.
	 * @return void
	 */
	private function render_redirect_fields( $args ) {
		$fields = array(
			'localepress_group' => $args['group'],
			's'                 => $args['search'],
			'paged'             => $args['page'],
			'orderby'           => $args['orderby'],
			'order'             => $args['order'],
		);

		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="redirect[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '" />';
		}
	}

	/**
	 * Redirects to a controlled dashboard URL after a mutation.
	 *
	 * @param WP_Error|null $error Optional save error.
	 * @return void
	 */
	private function redirect_after_save( $error ) {
		// Save nonce verified before this helper; individual values are sanitized below.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$redirect    = isset( $_POST['redirect'] ) && is_array( $_POST['redirect'] )
			? wp_unslash( $_POST['redirect'] )
			: array();
		$language_id = isset( $_POST['language_id'] ) && is_scalar( $_POST['language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$args = array(
			'page'                      => 'localepress-string-translations',
			'localepress_language'      => $language_id,
			'localepress_group'         => isset( $redirect['localepress_group'] ) && is_scalar( $redirect['localepress_group'] )
				? sanitize_text_field( $redirect['localepress_group'] )
				: '',
			's'                         => isset( $redirect['s'] ) && is_scalar( $redirect['s'] )
				? sanitize_text_field( $redirect['s'] )
				: '',
			'paged'                     => isset( $redirect['paged'] ) ? max( 1, absint( $redirect['paged'] ) ) : 1,
			'orderby'                   => isset( $redirect['orderby'] ) && is_scalar( $redirect['orderby'] )
				? sanitize_key( $redirect['orderby'] )
				: 'group',
			'order'                     => isset( $redirect['order'] ) && 'DESC' === strtoupper( sanitize_text_field( $redirect['order'] ) )
				? 'DESC'
				: 'ASC',
			'localepress_string_notice' => null === $error ? 'saved' : 'invalid',
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders a controlled status notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted notice.
		$notice = isset( $_GET['localepress_string_notice'] )
			? sanitize_key( wp_unslash( $_GET['localepress_string_notice'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'saved' === $notice ) {
			echo '<div class="localepress-notice notice notice-success is-dismissible"><p>' . esc_html__( 'String translations saved.', 'localepress' ) . '</p></div>';
		} elseif ( 'invalid' === $notice ) {
			echo '<div class="localepress-notice notice notice-error"><p>' . esc_html__( 'The string translations could not be saved.', 'localepress' ) . '</p></div>';
		}
	}

	/**
	 * Enforces the shared LocalePress management capability.
	 *
	 * @return void
	 */
	private function authorize() {
		if ( current_user_can( AdminModule::capability() ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You are not allowed to manage string translations.', 'localepress' ),
			'',
			array( 'response' => 403 )
		);
	}
}
