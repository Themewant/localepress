<?php
/**
 * Term translation admin module.
 *
 * @package LocalePress
 */

namespace LocalePress\Admin;

use LocalePress\Assets;
use LocalePress\Contracts\ModuleInterface;
use LocalePress\Language\LanguageManager;
use LocalePress\Taxonomy\TermTranslationManager;
use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Registers taxonomy forms, actions, notices, and list-table integrations.
 */
final class TermTranslationModule implements ModuleInterface {

	/**
	 * Term translation manager.
	 *
	 * @var TermTranslationManager
	 */
	private $translation_manager;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Form field renderer.
	 *
	 * @var TermTranslationFields
	 */
	private $fields;

	/**
	 * List-table integration.
	 *
	 * @var TermTranslationListTable
	 */
	private $list_table;

	/**
	 * Admin action helper.
	 *
	 * @var TermTranslationActions
	 */
	private $actions;

	/**
	 * Constructor.
	 *
	 * @param TermTranslationManager $translation_manager Term translation manager.
	 * @param LanguageManager        $language_manager    Language manager.
	 */
	public function __construct( TermTranslationManager $translation_manager, LanguageManager $language_manager ) {
		$actions = new TermTranslationActions();

		$this->translation_manager = $translation_manager;
		$this->language_manager    = $language_manager;
		$this->actions             = $actions;
		$this->fields              = new TermTranslationFields( $translation_manager, $language_manager, $actions );
		$this->list_table          = new TermTranslationListTable( $translation_manager, $language_manager, $actions );
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		$this->list_table->register_hooks();
		add_action( 'admin_init', array( $this, 'register_taxonomy_hooks' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'created_term', array( $this, 'save_new_term_language' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save_edited_term_language' ), 10, 3 );
		add_action( 'admin_post_localepress_create_term_translation', array( $this, 'create_translation' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Registers dynamic hooks after public taxonomies are available.
	 *
	 * @return void
	 */
	public function register_taxonomy_hooks() {
		foreach ( $this->translation_manager->get_supported_taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( $this->fields, 'render_add_fields' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this->fields, 'render_edit_fields' ) );
			$this->list_table->register_taxonomy( $taxonomy );
		}
	}

	/**
	 * Loads translation UI styles on supported taxonomy screens.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if (
			! $screen
			|| ! in_array( $screen->base, array( 'edit-tags', 'term' ), true )
			|| ! $this->translation_manager->supports_taxonomy( $screen->taxonomy )
		) {
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
	 * Assigns the submitted language to a newly created term.
	 *
	 * @param int    $term_id          New term identifier.
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @return void
	 */
	public function save_new_term_language( $term_id, $term_taxonomy_id, $taxonomy ) {
		unset( $term_taxonomy_id );

		$taxonomy_object = get_taxonomy( $taxonomy );

		if (
			! $taxonomy_object
			|| ! $this->translation_manager->supports_taxonomy( $taxonomy )
			|| ! current_user_can( $taxonomy_object->cap->manage_terms )
			|| ! $this->verify_term_nonce( 'localepress_add_term_language_' . $taxonomy )
		) {
			return;
		}

		$this->save_language( $term_id, $taxonomy );
	}

	/**
	 * Saves the submitted language for an edited term.
	 *
	 * @param int    $term_id          Edited term identifier.
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @return void
	 */
	public function save_edited_term_language( $term_id, $term_taxonomy_id, $taxonomy ) {
		unset( $term_taxonomy_id );

		if (
			! $this->translation_manager->supports_taxonomy( $taxonomy )
			|| ! current_user_can( 'edit_term', $term_id )
			|| ! $this->verify_term_nonce( 'localepress_edit_term_language_' . $taxonomy . '_' . absint( $term_id ) )
		) {
			return;
		}

		$this->save_language( $term_id, $taxonomy );
	}

	/**
	 * Creates and opens a translated term copy.
	 *
	 * @return void
	 */
	public function create_translation() {
		// Values select the nonce action and are validated before any mutation.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$source_term_id = isset( $_GET['source_term_id'] ) ? absint( $_GET['source_term_id'] ) : 0;
		$taxonomy       = isset( $_GET['taxonomy'] ) && is_scalar( $_GET['taxonomy'] )
			? sanitize_key( wp_unslash( $_GET['taxonomy'] ) )
			: '';
		$language_id    = isset( $_GET['language_id'] ) && is_scalar( $_GET['language_id'] )
			? sanitize_text_field( wp_unslash( $_GET['language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer(
			'localepress_create_term_translation_' . $source_term_id . '_' . $taxonomy . '_' . $language_id
		);

		$term = get_term( $source_term_id, $taxonomy );

		if (
			! $term instanceof WP_Term
			|| ! $this->translation_manager->supports_taxonomy( $taxonomy )
			|| ! $this->actions->can_create_translation( $term )
		) {
			wp_die(
				esc_html__( 'You are not allowed to create this term translation.', 'localepress' ),
				'',
				array( 'response' => 403 )
			);
		}

		$result = $this->translation_manager->create_translation( $source_term_id, $taxonomy, $language_id );

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result );
			$this->redirect_to_term( $source_term_id, $taxonomy );
		}

		$edit_url = $this->actions->get_edit_url( $result, $taxonomy );

		if ( '' === $edit_url ) {
			$edit_url = add_query_arg( 'taxonomy', $taxonomy, admin_url( 'edit-tags.php' ) );
		}

		wp_safe_redirect( add_query_arg( 'localepress_notice', 'term_translation_created', $edit_url ) );
		exit;
	}

	/**
	 * Renders controlled term translation notices.
	 *
	 * @return void
	 */
	public function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted notice code.
		$notice_code = isset( $_GET['localepress_notice'] )
			? sanitize_key( wp_unslash( $_GET['localepress_notice'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'term_translation_created' === $notice_code ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Translated term created and linked.', 'localepress' )
			);
		}

		$error_code = get_transient( 'localepress_term_error_' . get_current_user_id() );
		delete_transient( 'localepress_term_error_' . get_current_user_id() );
		$errors = $this->error_messages();

		if ( is_string( $error_code ) && '' !== $error_code ) {
			$error_message = isset( $errors[ $error_code ] )
				? $errors[ $error_code ]
				: __( 'LocalePress could not complete the term translation action.', 'localepress' );

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $error_message )
			);
		}
	}

	/**
	 * Saves a sanitized submitted language through the domain service.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	private function save_language( $term_id, $taxonomy ) {
		// The calling handler has already verified the add or edit nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$language_id = isset( $_POST['localepress_term_language_id'] ) && is_scalar( $_POST['localepress_term_language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['localepress_term_language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = $this->translation_manager->set_term_language( $term_id, $taxonomy, $language_id );

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result );
		}
	}

	/**
	 * Verifies the shared submitted term-language nonce field.
	 *
	 * @param string $action Expected nonce action.
	 * @return bool
	 */
	private function verify_term_nonce( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verification is this method's purpose.
		$nonce = isset( $_POST['localepress_term_language_nonce'] ) && is_scalar( $_POST['localepress_term_language_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['localepress_term_language_nonce'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Stores a relationship error for the next admin request.
	 *
	 * @param WP_Error $error Relationship error.
	 * @return void
	 */
	private function store_error( WP_Error $error ) {
		set_transient(
			'localepress_term_error_' . get_current_user_id(),
			sanitize_key( $error->get_error_code() ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirects to a term editor or taxonomy list fallback.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	private function redirect_to_term( $term_id, $taxonomy ) {
		$url = $this->actions->get_edit_url( $term_id, $taxonomy );

		if ( '' === $url ) {
			$url = add_query_arg( 'taxonomy', $taxonomy, admin_url( 'edit-tags.php' ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Returns allowlisted relationship error messages.
	 *
	 * @return array<string, string>
	 */
	private function error_messages() {
		return array(
			'translation_term_not_found'          => __( 'The requested term could not be found.', 'localepress' ),
			'unsupported_translation_taxonomy'    => __( 'That taxonomy is not supported by LocalePress.', 'localepress' ),
			'term_translation_language_not_found' => __( 'Select a registered language.', 'localepress' ),
			'term_translation_language_disabled'  => __( 'Select an enabled language.', 'localepress' ),
			'duplicate_term_translation_language' => __( 'A term translation for that language already exists.', 'localepress' ),
			'conflicting_term_language'           => __( 'This term is already assigned to a different language.', 'localepress' ),
			'conflicting_term_translation_groups' => __( 'Terms from different translation groups cannot be linked.', 'localepress' ),
			'missing_source_term_language'        => __( 'Assign a language to the source term first.', 'localepress' ),
			'missing_default_language'            => __( 'Configure an enabled default language before translating terms.', 'localepress' ),
			'term_translation_storage_error'      => __( 'LocalePress could not save the term translation relationship.', 'localepress' ),
			'term_exists'                         => __( 'A term with that generated name already exists. Rename it or create the translation manually.', 'localepress' ),
		);
	}
}
