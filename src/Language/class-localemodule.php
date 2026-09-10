<?php
/**
 * WordPress locale switching module.
 *
 * @package LocalePress
 */

namespace LocalePress\Language;

use LocalePress\Contracts\ModuleInterface;
use LocalePress\Routing\LanguageUrlManager;

defined( 'ABSPATH' ) || exit;

/**
 * Makes WordPress itself run in the language of a prefixed frontend request.
 *
 * Translating content is not enough for a page to read as one language. Theme
 * and plugin strings, date and number formatting, text direction, and the
 * document language WordPress prints all derive from `get_locale()`, so a
 * request under a language prefix has to answer with that language's locale.
 *
 * The switch is deliberately bound to an explicit language prefix in the
 * request URL. Administration, login, REST, cron, and CLI requests carry no
 * prefix, so they keep the site locale without needing a guard of their own.
 */
final class LocaleModule implements ModuleInterface {

	/**
	 * Guards against a filter callback re-entering locale resolution.
	 *
	 * @var bool
	 */
	private $resolving = false;

	/**
	 * Locale resolved for this request, or an empty string when none applies.
	 *
	 * @var string|null
	 */
	private $resolved;

	/**
	 * Language URL API.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager $url_manager Language URL API.
	 */
	public function __construct( LanguageUrlManager $url_manager ) {
		$this->url_manager = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		/*
		 * WordPress loads the default text domain after `plugins_loaded`, and
		 * themes and plugins load theirs later still, so registering here is
		 * early enough for every normal text domain.
		 */
		add_filter( 'locale', array( $this, 'filter_locale' ), 20 );
	}

	/**
	 * Replaces the site locale with the request language's locale.
	 *
	 * @param string|mixed $locale Locale WordPress resolved.
	 * @return string|mixed
	 */
	public function filter_locale( $locale ) {
		$resolved = $this->resolve_request_locale();

		if ( '' === $resolved ) {
			return $locale;
		}

		/**
		 * Filters the locale LocalePress applies to a prefixed frontend request.
		 *
		 * Returning an empty string restores the locale WordPress resolved.
		 *
		 * @param string       $resolved Locale for the request language.
		 * @param string|mixed $locale   Locale WordPress resolved.
		 */
		$filtered = apply_filters( 'localepress_request_locale', $resolved, $locale );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $locale;
	}

	/**
	 * Returns the locale this request should run in, resolved once.
	 *
	 * @return string Empty when the site locale must be left alone.
	 */
	private function resolve_request_locale() {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		/*
		 * Language lookup reads options and runs extension filters, either of
		 * which may call get_locale() again. Answer that inner call with the
		 * unfiltered locale instead of recursing.
		 */
		if ( $this->resolving || ! $this->is_switchable_request() ) {
			return '';
		}

		$this->resolving = true;

		try {
			$language = $this->url_manager->request_has_language_prefix()
				? $this->url_manager->get_current_language()
				: null;

			$locale = is_array( $language ) && isset( $language['locale'] ) && is_scalar( $language['locale'] )
				? trim( (string) $language['locale'] )
				: '';

			$this->resolved = preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,12}){0,3}$/', $locale ) ? $locale : '';
		} finally {
			$this->resolving = false;
		}

		return $this->resolved;
	}

	/**
	 * Reports whether this request may run in a translated locale.
	 *
	 * @return bool
	 */
	private function is_switchable_request() {
		$allowed = $this->url_manager->supports_language_prefixes()
			&& ! is_admin()
			&& ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );

		/**
		 * Filters whether LocalePress may switch the WordPress locale.
		 *
		 * @param bool $allowed Whether the locale may be switched.
		 */
		return (bool) apply_filters( 'localepress_should_switch_locale', $allowed );
	}
}
