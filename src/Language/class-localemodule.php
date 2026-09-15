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
 * Makes WordPress itself run in the language the request is answering in.
 *
 * Translating content is not enough for a page to read as one language. Theme
 * and plugin strings, date and number formatting, text direction, and the
 * document language WordPress prints all derive from `get_locale()`, so a
 * request in a language has to answer with that language's locale.
 *
 * The switch is bound to a request that named a language: a frontend URL under
 * a language prefix, and equally an AJAX or REST call that named one, because a
 * fragment of a page belongs to the same language as the page it joins.
 * Administration screens, login, cron, and CLI name none, so they keep the site
 * locale without needing a guard of their own.
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
	 * Locale this module last handed WordPress, or null before the first call.
	 *
	 * @var string|null
	 */
	private $applied = null;

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

		/*
		 * A REST call can name its language in a body, which cannot be read
		 * until the request is dispatched — long after the text domains were
		 * loaded. When that happens the locale has to be resolved again.
		 */
		add_action( 'localepress_request_language_changed', array( $this, 'refresh_locale' ) );
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
			$this->applied = is_string( $locale ) ? $locale : '';

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
		$applied  = is_string( $filtered ) && '' !== $filtered ? $filtered : $locale;

		$this->applied = is_string( $applied ) ? $applied : '';

		return $applied;
	}

	/**
	 * Resolves the locale again after the request changed language mid-flight.
	 *
	 * Answering `get_locale()` differently from here on is not enough on its
	 * own: the text domains WordPress, the theme, and every plugin loaded are
	 * already in memory in the locale that was current when they were read.
	 * Switching is what reloads them, so the strings a REST call returns are
	 * written in the language it asked for rather than the one the site was
	 * booted in.
	 *
	 * @return void
	 */
	public function refresh_locale() {
		$previous       = $this->applied;
		$this->resolved = null;
		$this->applied  = null;

		/*
		 * Nothing has asked for a locale yet, so nothing has been loaded in the
		 * wrong one and the next caller resolves this for itself.
		 */
		if ( null === $previous || ! did_action( 'init' ) ) {
			return;
		}

		$resolved = $this->resolve_request_locale();

		// An empty resolution means the site's own locale applies again, and
		// get_locale() answers with it now that the cache has been cleared.
		$target = '' === $resolved ? get_locale() : $resolved;

		if ( ! is_string( $target ) || '' === $target || $target === $previous ) {
			return;
		}

		switch_to_locale( $target );
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
			&& $this->url_manager->background()->scopes_request();

		/**
		 * Filters whether LocalePress may switch the WordPress locale.
		 *
		 * @param bool $allowed Whether the locale may be switched.
		 */
		return (bool) apply_filters( 'localepress_should_switch_locale', $allowed );
	}
}
