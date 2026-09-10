<?php
/**
 * Host-based language routing resolver.
 *
 * @package LocalePress
 */

namespace LocalePress\Routing;

use LocalePress\Settings\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Maps enabled languages onto hostnames for the subdomain and domain URL modes.
 *
 * Directory routing carries the language in the request path, and query routing
 * in a query argument beside it. The remaining two carry it in the host instead,
 * so every question about "which language is this" and "where does this language
 * live" moves from the path to the hostname. This class owns that mapping in one
 * place; nothing else needs to know how a host is derived. It also answers which
 * of the four modes is configured, because that decision belongs beside it.
 */
final class LanguageHostResolver {

	/**
	 * Language lives in the first path segment.
	 *
	 * @var string
	 */
	const MODE_DIRECTORY = 'directory';

	/**
	 * Language lives in a subdomain derived from its URL slug.
	 *
	 * @var string
	 */
	const MODE_SUBDOMAIN = 'subdomain';

	/**
	 * Language lives on its own registered domain.
	 *
	 * @var string
	 */
	const MODE_DOMAIN = 'domain';

	/**
	 * Language lives in a query argument on an otherwise untouched URL.
	 *
	 * @var string
	 */
	const MODE_QUERY = 'query';

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param PluginSettings $settings Central plugin settings.
	 */
	public function __construct( PluginSettings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns every supported URL mode.
	 *
	 * @return array<int, string>
	 */
	public static function modes() {
		return array( self::MODE_DIRECTORY, self::MODE_SUBDOMAIN, self::MODE_DOMAIN, self::MODE_QUERY );
	}

	/**
	 * Returns the configured URL mode.
	 *
	 * @return string
	 */
	public function get_mode() {
		$url  = $this->settings->get_section( 'url' );
		$mode = isset( $url['mode'] ) && is_scalar( $url['mode'] ) ? (string) $url['mode'] : self::MODE_DIRECTORY;

		return in_array( $mode, self::modes(), true ) ? $mode : self::MODE_DIRECTORY;
	}

	/**
	 * Reports whether the language is carried by the hostname.
	 *
	 * @return bool
	 */
	public function uses_host_routing() {
		return in_array( $this->get_mode(), array( self::MODE_SUBDOMAIN, self::MODE_DOMAIN ), true );
	}

	/**
	 * Reports whether the language is carried by a query argument.
	 *
	 * This is the one mode that leaves both the host and the path exactly as
	 * WordPress built them, so it is also the only mode a site without pretty
	 * permalinks can use.
	 *
	 * @return bool
	 */
	public function uses_query_routing() {
		return self::MODE_QUERY === $this->get_mode();
	}

	/**
	 * Returns the host that serves one language.
	 *
	 * @param array<string, mixed> $language   Language record.
	 * @param bool                 $is_default Whether this is the default language.
	 * @return string Empty in directory mode, or when no host can be derived.
	 */
	public function get_host( array $language, $is_default = false ) {
		switch ( $this->get_mode() ) {
			case self::MODE_DOMAIN:
				$domain = isset( $language['domain'] ) ? $this->normalize( $language['domain'] ) : '';

				/*
				 * A language with no domain of its own stays on the site host. That
				 * keeps a half-configured site reachable instead of routing it to a
				 * hostname nobody has pointed anywhere.
				 */
				return '' === $domain ? $this->get_site_host() : $domain;

			case self::MODE_SUBDOMAIN:
				$base = $this->get_base_host();

				if ( '' === $base ) {
					return '';
				}

				// Reuses the directory mode's default-prefix choice: when the default
				// language shows no prefix, it also takes the bare domain here.
				if ( $is_default && ! $this->settings->should_prefix_default_language() ) {
					return $base;
				}

				$slug = sanitize_title( isset( $language['url_slug'] ) ? (string) $language['url_slug'] : '' );

				return '' === $slug ? '' : $slug . '.' . $base;
		}

		return '';
	}

	/**
	 * Finds the language served by one host.
	 *
	 * @param string                                    $host        Hostname to match.
	 * @param array<string, array<string, mixed>>       $languages   Enabled languages.
	 * @param string                                    $default_id  Default language identifier.
	 * @return array<string, mixed>|null
	 */
	public function match( $host, array $languages, $default_id = '' ) {
		$host = $this->normalize( $host );

		if ( '' === $host || ! $this->uses_host_routing() ) {
			return null;
		}

		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) || ! isset( $language['id'] ) ) {
				continue;
			}

			$candidate = $this->normalize(
				$this->get_host( $language, $language['id'] === $default_id )
			);

			if ( '' !== $candidate && $candidate === $host ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Returns the host WordPress is configured to serve.
	 *
	 * @return string
	 */
	public function get_site_host() {
		$parts = wp_parse_url( self::site_url() );

		return is_array( $parts ) && isset( $parts['host'] ) ? $this->normalize( $parts['host'] ) : '';
	}

	/**
	 * Returns the configured site address without the home_url filters.
	 *
	 * Host routing points home_url() at the current language. Everything that
	 * decides which language a URL belongs to has to compare against the site's
	 * one fixed address instead, or the answer would depend on the answer.
	 *
	 * @return string
	 */
	public static function site_url() {
		$home = get_option( 'home' );
		$home = is_string( $home ) && '' !== $home ? $home : home_url( '/' );

		return trailingslashit( $home );
	}

	/**
	 * Returns the host the current request actually arrived on.
	 *
	 * Host routing has to read the real request host: on a language domain the
	 * configured site host names a different language entirely.
	 *
	 * @return string
	 */
	public function get_request_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_scalar( $_SERVER['HTTP_HOST'] )
			? $this->normalize( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) )
			: '';

		return '' === $host ? $this->get_site_host() : $host;
	}

	/**
	 * Returns the registrable host that language subdomains are built on.
	 *
	 * @return string
	 */
	public function get_base_host() {
		$host = $this->get_site_host();

		/*
		 * A site served from www needs its language subdomains beside www, not
		 * beneath it: bn.example.com, never bn.www.example.com.
		 */
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		/**
		 * Filters the host that language subdomains are derived from.
		 *
		 * @param string $host Registrable base host.
		 */
		$filtered = apply_filters( 'localepress_subdomain_base_host', $host );

		return is_string( $filtered ) ? $this->normalize( $filtered ) : $host;
	}

	/**
	 * Reduces a hostname to a comparable form.
	 *
	 * Accepts a bare host, a host with a port, or a full URL, so a site owner can
	 * paste "https://example.fr/" into the domain field and still be understood.
	 *
	 * @param mixed $host Candidate hostname.
	 * @return string
	 */
	public function normalize( $host ) {
		if ( ! is_scalar( $host ) ) {
			return '';
		}

		$host = trim( (string) $host );

		if ( '' === $host ) {
			return '';
		}

		if ( false !== strpos( $host, '//' ) ) {
			$parts = wp_parse_url( $host );
			$host  = is_array( $parts ) && isset( $parts['host'] ) ? $parts['host'] : '';
		}

		$host = strtolower( $host );
		$host = preg_replace( '#[/?].*$#', '', $host );
		$host = is_string( $host ) ? $host : '';

		// A port never distinguishes one language from another, and dropping it
		// keeps matching stable behind proxies and local development ports.
		$host = preg_replace( '/:\d+$/', '', $host );
		$host = is_string( $host ) ? trim( $host, '.' ) : '';

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host ) ? $host : '';
	}
}
