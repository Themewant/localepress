<?php
/**
 * REST routes behind the Site Editor language panel.
 *
 * @package LocalePress
 */

namespace LocalePress\Integrations\SiteEditor;

use LocalePress\Admin\AdminModule;
use LocalePress\Contracts\ModuleInterface;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the Site Editor start and remove a language's template part.
 *
 * The panel that offers these is inside the Site Editor, which is a single page
 * that never reloads, so the work has to happen where the editor already talks:
 * over REST. Creating answers with the address of what it made, because the one
 * thing anyone does after starting a translation is edit it.
 */
final class TemplatePartRoutes implements ModuleInterface {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'localepress/v1';

	/**
	 * Route serving one language's version of a template part.
	 */
	const ROUTE = '/template-parts/translation';

	/**
	 * Template part translation service.
	 *
	 * @var TemplateParts
	 */
	private $parts;

	/**
	 * Constructor.
	 *
	 * @param TemplateParts $parts Template part translation service.
	 */
	public function __construct( TemplateParts $parts ) {
		$this->parts = $parts;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->parts->is_available() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$arguments = array(
			'slug'     => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
			),
			'language' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => $arguments,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => $arguments,
				),
			)
		);
	}

	/**
	 * Reports whether the current user may manage template part languages.
	 *
	 * Both capabilities, because both things are being done at once: a language
	 * is being assigned, and the active theme is being given a part it did not
	 * have.
	 *
	 * @return bool|WP_Error
	 */
	public function may_manage() {
		if ( current_user_can( AdminModule::capability() ) && current_user_can( 'edit_theme_options' ) ) {
			return true;
		}

		return new WP_Error(
			'localepress_forbidden',
			__( 'You are not allowed to manage template part languages.', 'localepress' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Creates one language's version of a template part.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$slug        = (string) $request->get_param( 'slug' );
		$language_id = (string) $request->get_param( 'language' );

		/*
		 * The slug the panel holds is whichever part is open, which is as likely
		 * to be a translation as the part it was made from. Both name the same
		 * header, and only one of them can be translated from.
		 */
		$source  = $this->parts->read_slug( $slug )->base();
		$created = $this->parts->create_translation( $source, $language_id );

		if ( is_wp_error( $created ) ) {
			return $this->as_rest_error( $created );
		}

		$post = get_post( $created );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'localepress_part_missing',
				__( 'The template part was created but could not be read back.', 'localepress' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'slug'     => (string) $post->post_name,
				'language' => $language_id,
				'editUrl'  => $this->parts->editor_url( $post->post_name ),
			),
			201
		);
	}

	/**
	 * Deletes one language's version of a template part.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove( WP_REST_Request $request ) {
		$slug        = (string) $request->get_param( 'slug' );
		$language_id = (string) $request->get_param( 'language' );
		$source      = $this->parts->read_slug( $slug )->base();
		$translated  = $this->parts->get_translations( $source );

		if ( ! isset( $translated[ $language_id ] ) ) {
			return new WP_Error(
				'localepress_part_not_translated',
				__( 'That template part has no version in this language.', 'localepress' ),
				array( 'status' => 404 )
			);
		}

		$post = $this->parts->find_post( $translated[ $language_id ] );

		if ( ! $post instanceof WP_Post ) {
			/*
			 * A part the theme ships as a file and nobody has customized. There is
			 * no post to delete, and deleting the file is not this plugin's to do.
			 */
			return new WP_Error(
				'localepress_part_from_theme',
				__( 'That version comes from the theme and cannot be deleted here.', 'localepress' ),
				array( 'status' => 409 )
			);
		}

		wp_delete_post( $post->ID, true );
		$this->parts->forget();

		return new WP_REST_Response(
			array(
				'language' => $language_id,
				'source'   => $source,
				'editUrl'  => $this->parts->editor_url( $source ),
			),
			200
		);
	}

	/**
	 * Gives a service error the status the editor needs to report it.
	 *
	 * @param WP_Error $error Error raised while creating.
	 * @return WP_Error
	 */
	private function as_rest_error( WP_Error $error ) {
		$statuses = array(
			'localepress_part_not_found'      => 404,
			'localepress_language_not_found'  => 400,
			'localepress_part_is_translation' => 400,
			'localepress_part_exists'         => 409,
			'localepress_part_slug_taken'     => 409,
		);

		$code   = $error->get_error_code();
		$status = isset( $statuses[ $code ] ) ? $statuses[ $code ] : 500;

		return new WP_Error( $code, $error->get_error_message(), array( 'status' => $status ) );
	}
}
