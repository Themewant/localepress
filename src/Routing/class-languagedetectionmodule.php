<?php
/**
 * Visitor language detection module.
 *
 * @package LocalePress
 */

namespace LocalePress\Routing;

use LocalePress\Contracts\ModuleInterface;
use LocalePress\Language\BrowserLanguageDetector;
use LocalePress\Language\LanguageManager;
use LocalePress\Settings\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a first-time visitor to the language their browser asks for.
 *
 * Detection runs only on an unprefixed front page request, so a shared or
 * bookmarked language URL always wins over the browser preference. A returning
 * visitor is recognized by cookie before the header is read, and an internal
 * referrer suppresses detection entirely so following a link back to the site
 * root never bounces the visitor out of the language they were reading.
 */
final class LanguageDetectionModule implements ModuleInterface {

	/**
	 * Cookie that remembers the visitor's last language.
	 */
	const COOKIE_NAME = 'localepress_language';

	/**
	 * Language URL API.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Accept-Language negotiation service.
	 *
	 * @var BrowserLanguageDetector
	 */
	private $detector;

	/**
	 * Plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager      $url_manager      Language URL API.
	 * @param LanguageManager         $language_manager Language manager.
	 * @param BrowserLanguageDetector $detector         Accept-Language negotiation.
	 * @param PluginSettings|null     $settings         Plugin settings.
	 */
	public function __construct(
		LanguageUrlManager $url_manager,
		LanguageManager $language_manager,
		BrowserLanguageDetector $detector,
		?PluginSettings $settings = null
	) {
		$this->url_manager      = $url_manager;
		$this->language_manager = $language_manager;
		$this->detector         = $detector;
		$this->settings         = null === $settings ? new PluginSettings() : $settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		// Runs before RoutingModule::redirect_unprefixed_request() at priority 1.
		add_action( 'template_redirect', array( $this, 'redirect_preferred_language' ), 0 );
		add_action( 'template_redirect', array( $this, 'remember_current_language' ), 5 );
	}

	/**
	 * Redirects an undecided front page request to the visitor's language.
	 *
	 * @return void
	 */
	public function redirect_preferred_language() {
		if ( ! $this->is_detection_request() ) {
			return;
		}

		$language = $this->resolve_preferred_language();

		if ( null === $language ) {
			return;
		}

		$url = $this->preserve_query_string( $this->url_manager->get_language_home_url( $language ) );

		if ( '' === $url || $this->is_current_url( $url ) ) {
			return;
		}

		/**
		 * Filters the URL a detected visitor is sent to.
		 *
		 * Returning an empty string cancels the redirect and leaves the request
		 * to the normal LocalePress routing rules.
		 *
		 * @param string               $url      Target URL.
		 * @param array<string, mixed> $language Detected language record.
		 */
		$url = apply_filters( 'localepress_detected_language_url', $url, $language );

		if ( ! is_string( $url ) || '' === $url || '' === wp_validate_redirect( $url, '' ) ) {
			return;
		}

		$this->store_language_cookie( $language );

		// Tell shared caches that this response depends on the request header.
		header( 'Vary: Accept-Language', false );

		/**
		 * Fires immediately before a detected visitor is redirected.
		 *
		 * @param array<string, mixed> $language Detected language record.
		 * @param string               $url      Target URL.
		 */
		do_action( 'localepress_language_detected', $language, $url );

		wp_safe_redirect( $url, 302, 'LocalePress' );
		exit;
	}

	/**
	 * Remembers the language of an explicit language request.
	 *
	 * Only prefixed frontend requests are recorded, so the cookie always
	 * reflects a language the visitor actually navigated to.
	 *
	 * @return void
	 */
	public function remember_current_language() {
		if (
			! $this->is_detection_enabled()
			|| ! $this->is_public_frontend_request()
			|| ! $this->url_manager->request_has_language_prefix()
		) {
			return;
		}

		$language = $this->url_manager->get_current_language();

		if ( is_array( $language ) ) {
			$this->store_language_cookie( $language );
		}
	}

	/**
	 * Reports whether the current request may be redirected by detection.
	 *
	 * @return bool
	 */
	private function is_detection_request() {
		$allowed = $this->is_detection_enabled()
			&& $this->is_public_frontend_request()
			&& is_front_page()
			&& ! $this->url_manager->request_names_language()
			&& $this->is_plain_get_request();

		/**
		 * Filters whether visitor language detection may run for this request.
		 *
		 * Useful to exclude crawlers, or to disable detection while a full page
		 * cache serves the site root.
		 *
		 * @param bool $allowed Whether detection may run.
		 */
		return (bool) apply_filters( 'localepress_should_detect_language', $allowed );
	}

	/**
	 * Returns the language a visitor should land on, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve_preferred_language() {
		$remembered = $this->get_remembered_language();

		if ( null !== $remembered ) {
			return $remembered;
		}

		// An internal referrer means the visitor navigated here on purpose.
		if ( '' !== (string) wp_get_referer() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Parsed and validated by the detector.
		$header = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) )
			: '';

		if ( '' === $header ) {
			return null;
		}

		$languages = $this->language_manager->get_languages( true );

		/**
		 * Filters the languages offered to browser preference matching.
		 *
		 * @param array<int, array<string, mixed>> $languages Enabled languages.
		 * @param string                           $header    Accept-Language header.
		 */
		$filtered = apply_filters( 'localepress_browser_detection_languages', $languages, $header );

		return $this->detector->match( $header, is_array( $filtered ) ? $filtered : $languages );
	}

	/**
	 * Returns the enabled language stored in the visitor's cookie.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_remembered_language() {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$slug     = sanitize_title( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		$language = '' === $slug ? null : $this->url_manager->resolve_language( $slug );

		return is_array( $language ) && ! empty( $language['enabled'] ) ? $language : null;
	}

	/**
	 * Stores the visitor's language for the next visit.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return void
	 */
	private function store_language_cookie( array $language ) {
		if ( headers_sent() || ! isset( $language['url_slug'] ) ) {
			return;
		}

		$slug = sanitize_title( (string) $language['url_slug'] );

		if ( '' === $slug ) {
			return;
		}

		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) && $slug === sanitize_title( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) ) {
			return;
		}

		/**
		 * Filters how long the visitor's language is remembered, in seconds.
		 *
		 * Return zero to send a session cookie instead.
		 *
		 * @param int                  $lifetime Cookie lifetime in seconds.
		 * @param array<string, mixed> $language Language record.
		 */
		$lifetime = (int) apply_filters( 'localepress_language_cookie_lifetime', YEAR_IN_SECONDS, $language );
		$expires  = 0 < $lifetime ? time() + $lifetime : 0;

		$_COOKIE[ self::COOKIE_NAME ] = $slug;

		/*
		 * COOKIE_DOMAIN names the site's own host. Under host routing this request
		 * may be on a language host that does not fall under it, and a browser
		 * silently discards a cookie scoped to a domain it is not visiting — which
		 * would leave detection re-running on every page. A host-only cookie is
		 * always accepted, at the cost of not being shared between language hosts.
		 */
		$domain = $this->url_manager->uses_host_routing() ? '' : COOKIE_DOMAIN;

		setcookie(
			self::COOKIE_NAME,
			$slug,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => $domain,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Reports whether browser detection is enabled and usable.
	 *
	 * This also gates the language cookie, which exists solely to answer
	 * detection without reading the browser header again. Writing a value
	 * nothing reads would add a consent obligation and a cache variant for no
	 * behavior, so detection being off means no cookie is set at all.
	 *
	 * @return bool
	 */
	private function is_detection_enabled() {
		return $this->settings->should_detect_browser_language()
			&& $this->url_manager->supports_language_prefixes();
	}

	/**
	 * Reports whether this is a normal public HTML frontend request.
	 *
	 * @return bool
	 */
	private function is_public_frontend_request() {
		return ! is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! is_robots()
			&& ! is_feed()
			&& ! is_embed()
			&& ! is_preview()
			&& ! $this->url_manager->is_builder_preview_request()
			&& ! is_404();
	}

	/**
	 * Reports whether the request is a plain GET without submitted data.
	 *
	 * Redirecting a form submission would discard it.
	 *
	 * @return bool
	 */
	private function is_plain_get_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Emptiness check only, no data is read.
		return 'GET' === $method && empty( $_POST );
	}

	/**
	 * Appends the current query string to a redirect target.
	 *
	 * @param string $url Target URL.
	 * @return string
	 */
	private function preserve_query_string( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$query = wp_parse_url( $this->url_manager->get_current_request_url(), PHP_URL_QUERY );

		if ( ! is_string( $query ) || '' === $query ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
	}

	/**
	 * Reports whether a URL is the request already being served.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_current_url( $url ) {
		$current = $this->url_manager->get_current_request_url();

		return untrailingslashit( strtok( $url, '?' ) ) === untrailingslashit( strtok( $current, '?' ) );
	}
}
