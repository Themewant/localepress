<?php
/**
 * Stand-ins for the collaborators the routing classes are constructed with.
 *
 * They carry the real fully-qualified names so the real classes load unchanged.
 */

namespace LocalePress\Settings {
	class PluginSettings {
		public $mode;
		public $prefix_default;
		public $detect_browser;
		public $split_sitemaps;

		public function __construct( $mode = 'directory', $prefix_default = false, $detect_browser = true, $split_sitemaps = true ) {
			$this->mode           = $mode;
			$this->prefix_default = $prefix_default;
			$this->detect_browser = $detect_browser;
			$this->split_sitemaps = $split_sitemaps;
		}
		public function is_sitemap_split_enabled() {
			return $this->split_sitemaps;
		}
		public function get_section( $section ) {
			return array( 'mode' => $this->mode );
		}
		public function should_prefix_default_language() {
			return $this->prefix_default;
		}
		public function should_detect_browser_language() {
			return $this->detect_browser;
		}
	}
}

namespace LocalePress\Content {
	class PostTranslationManager {
		public function get_post_language_id( $post_id ) {
			return '';
		}
		public function get_supported_post_types() {
			return array( 'post', 'page' );
		}
	}
	class LanguageQueryConstraint {}
}

namespace LocalePress\Taxonomy {
	class TermTranslationManager {
		public function get_term_language_id( $term_id, $taxonomy ) {
			return '';
		}
		public function get_supported_taxonomies() {
			return array( 'category' );
		}
	}
}

namespace LocalePress\Language {
	class LanguageManager {
		private $languages;
		public function __construct( array $languages ) {
			$this->languages = $languages;
		}
		public function get_languages( $enabled_only = false ) {
			return $this->languages;
		}
		public function get_default_id() {
			return 'en';
		}
	}
	class BrowserLanguageDetector {
		public function match( $header, $languages ) {
			return null;
		}
	}
}

namespace LocalePress\Routing {

	use LocalePress\Settings\PluginSettings;

	/**
	 * A LanguageUrlManager with just the surface the modules under test use.
	 */
	final class LanguageUrlManager {
		const QUERY_VAR        = 'localepress_lang';
		const PUBLIC_QUERY_VAR = 'lang';

		public $languages;
		public $default_id = 'en';
		private $hosts;
		private $background = null;

		public function __construct( PluginSettings $settings, array $languages ) {
			$this->hosts     = new LanguageHostResolver( $settings );
			$this->languages = $languages;
		}

		public function hosts() {
			return $this->hosts;
		}
		public function is_frontend_routing_enabled() {
			return true;
		}
		public function uses_host_routing() {
			return $this->hosts->uses_host_routing();
		}
		public function uses_query_routing() {
			return $this->hosts->uses_query_routing();
		}
		public function should_prefix_default_language() {
			return $this->hosts->uses_host_routing() || \lp_state( 'prefix_default' );
		}
		public function get_language_slugs() {
			return array_keys( $this->languages );
		}
		public function background() {
			if ( null === $this->background ) {
				$this->background = new BackgroundLanguageResolver( $this );
			}
			return $this->background;
		}
		public function get_public_query_var() {
			return self::PUBLIC_QUERY_VAR;
		}
		public function resolve_language( $language ) {
			if ( is_array( $language ) ) {
				$language = $language['id'];
			}
			return isset( $this->languages[ (string) $language ] ) ? $this->languages[ (string) $language ] : null;
		}
		public function resolve_language_id( $language ) {
			$record = $this->resolve_language( $language );
			return null === $record ? '' : (string) $record['id'];
		}
		public function get_default_language() {
			return $this->resolve_language( $this->default_id );
		}
		public function get_current_language() {
			$record = $this->hosts->match(
				$this->hosts->get_request_host(),
				$this->languages,
				$this->default_id
			);

			if ( null !== $record ) {
				return $record;
			}

			$slug = (string) \lp_state( 'current_language', $this->default_id );

			return $this->resolve_language( $slug );
		}
		public function get_language_host( $language ) {
			$record = $this->resolve_language( $language );
			if ( null === $record ) {
				return '';
			}
			return $this->hosts->get_host( $record, $record['id'] === $this->default_id );
		}
		public function request_host_serves_no_language() {
			if ( ! $this->uses_host_routing() ) {
				return false;
			}
			return null === $this->hosts->match(
				$this->hosts->get_request_host(),
				$this->languages,
				$this->default_id
			);
		}
		public function get_language_home_url( $language ) {
			$record = $this->resolve_language( $language );
			if ( null === $record ) {
				return '';
			}
			return $this->prefix_url( LanguageHostResolver::site_url(), $record );
		}
		public function get_current_request_url() {
			$host = $this->uses_host_routing()
				? $this->hosts->get_request_host()
				: wp_parse_url( LanguageHostResolver::site_url(), PHP_URL_HOST );

			return 'https://' . $host . (string) \lp_state( 'request_uri', '/' );
		}
		/**
		 * Enough of the real prefixing to exercise the callers, per mode.
		 */
		public function prefix_url( $url, $language ) {
			$record = $this->resolve_language( $language );
			if ( null === $record ) {
				return $url;
			}
			$parts = wp_parse_url( $url );
			if ( $this->uses_host_routing() ) {
				$host = $this->get_language_host( $record );
				return 'https://' . $host . ( isset( $parts['path'] ) ? $parts['path'] : '/' );
			}
			if ( $this->uses_query_routing() ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'lang=' . $record['url_slug'];
			}
			$path = isset( $parts['path'] ) ? $parts['path'] : '/';
			if ( $record['id'] === $this->default_id && ! \lp_state( 'prefix_default' ) ) {
				return 'https://' . $parts['host'] . $path;
			}
			return 'https://' . $parts['host'] . '/' . $record['url_slug'] . $path;
		}
		public function is_excluded_url( $url ) {
			return (bool) preg_match( '#/(wp-admin|wp-json|wp-content|wp-includes)(/|$)#', (string) $url );
		}
		public function is_builder_preview_request() {
			return false;
		}
		public function get_url_language_id( $url ) {
			if ( $this->uses_host_routing() ) {
				$record = $this->hosts->match( wp_parse_url( $url, PHP_URL_HOST ), $this->languages, $this->default_id );
				return null === $record ? '' : $record['id'];
			}

			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$first = sanitize_title( (string) strtok( trim( $path, '/' ), '/' ) );

			return isset( $this->languages[ $first ] ) ? (string) $this->languages[ $first ]['id'] : '';
		}
		public function get_front_page_id() {
			return 0;
		}
		public function get_posts_page_id() {
			return 0;
		}
		public function request_has_language_prefix() {
			return (bool) \lp_state( 'has_prefix' );
		}
		public function request_names_language() {
			if ( ! $this->uses_host_routing() ) {
				return $this->request_has_language_prefix();
			}
			return $this->hosts->normalize( $this->hosts->get_request_host() ) !== $this->hosts->get_site_host();
		}
		public function supports_language_prefixes() {
			return true;
		}
	}
}
