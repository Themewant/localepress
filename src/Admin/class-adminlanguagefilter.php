<?php
/**
 * Per-user admin language filter state.
 *
 * @package LocalePress
 */

namespace LocalePress\Admin;

use LocalePress\Language\LanguageManager;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and remembers the language an administrator is filtering by.
 *
 * The choice arrives as a `lang` query argument and is stored per user, so it
 * survives navigation between admin screens without every link carrying the
 * argument. It is a display preference for the current user only: it changes
 * nothing another user sees and writes no site state, which is why an
 * unauthenticated form token is not required to change it.
 */
final class AdminLanguageFilter {

	/**
	 * User meta key holding the remembered URL slug.
	 *
	 * @var string
	 */
	const USER_META_KEY = 'localepress_admin_language';

	/**
	 * Query argument carrying an explicit choice.
	 *
	 * @var string
	 */
	const QUERY_ARG = 'lang';

	/**
	 * Query argument value that clears the filter.
	 *
	 * @var string
	 */
	const SHOW_ALL = 'all';

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Resolved language record, or null while filtering is off.
	 *
	 * @var array<string, mixed>|null
	 */
	private $language;

	/**
	 * Whether resolution has already run for this request.
	 *
	 * @var bool
	 */
	private $resolved = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager $language_manager Language manager.
	 */
	public function __construct( LanguageManager $language_manager ) {
		$this->language_manager = $language_manager;
	}

	/**
	 * Returns the language currently filtered on, or null for every language.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_language() {
		if ( ! $this->resolved ) {
			$this->resolved = true;
			$this->language = $this->resolve();
		}

		return $this->language;
	}

	/**
	 * Returns the filtered language identifier, or an empty string.
	 *
	 * @return string
	 */
	public function get_language_id() {
		$language = $this->get_language();

		return null === $language ? '' : (string) $language['id'];
	}

	/**
	 * Reports whether a single language is currently selected.
	 *
	 * @return bool
	 */
	public function is_filtering() {
		return null !== $this->get_language();
	}

	/**
	 * Returns the enabled languages offered by the filter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_available_languages() {
		return $this->language_manager->get_languages( true );
	}

	/**
	 * Returns the current admin URL carrying one filter choice.
	 *
	 * Pagination and per-row action arguments are dropped because they describe
	 * a listing of the previous language, not the one being switched to.
	 *
	 * @param string $slug URL slug, or the show-all value.
	 * @return string
	 */
	public function get_filter_url( $slug ) {
		$slug = sanitize_title( (string) $slug );
		$base = $this->get_current_admin_url();

		$base = remove_query_arg(
			array( self::QUERY_ARG, 'paged', 'page_number', 'post', 'action', 'action2', '_wpnonce', '_wp_http_referer' ),
			$base
		);

		return add_query_arg( self::QUERY_ARG, '' === $slug ? self::SHOW_ALL : $slug, $base );
	}

	/**
	 * Reads an explicit choice, remembers it, and resolves it to a language.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve() {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Per-user display preference, see the class docblock.
		$requested = isset( $_GET[ self::QUERY_ARG ] ) && is_scalar( $_GET[ self::QUERY_ARG ] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Per-user display preference, see the class docblock.
			? sanitize_title( wp_unslash( $_GET[ self::QUERY_ARG ] ) )
			: '';

		if ( '' !== $requested ) {
			$language = self::SHOW_ALL === $requested ? null : $this->find_by_slug( $requested );

			// An unknown slug clears the filter rather than silently keeping the old one.
			update_user_meta( $user_id, self::USER_META_KEY, null === $language ? '' : (string) $language['url_slug'] );

			return $language;
		}

		$stored = get_user_meta( $user_id, self::USER_META_KEY, true );

		return is_string( $stored ) && '' !== $stored ? $this->find_by_slug( $stored ) : null;
	}

	/**
	 * Returns an enabled language matching a URL slug.
	 *
	 * @param string $slug URL slug.
	 * @return array<string, mixed>|null
	 */
	private function find_by_slug( $slug ) {
		$slug = sanitize_title( (string) $slug );

		if ( '' === $slug ) {
			return null;
		}

		foreach ( $this->language_manager->get_languages( true ) as $language ) {
			if ( isset( $language['url_slug'] ) && sanitize_title( (string) $language['url_slug'] ) === $slug ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Returns the admin URL being requested.
	 *
	 * @return string
	 */
	private function get_current_admin_url() {
		$request = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( '' === $request ) {
			return admin_url();
		}

		$path = wp_parse_url( $request, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '';
		$file = '' === $path ? 'index.php' : basename( $path );
		$args = wp_parse_url( $request, PHP_URL_QUERY );

		return admin_url( $file . ( is_string( $args ) && '' !== $args ? '?' . $args : '' ) );
	}
}
