<?php
/**
 * Standalone check of LanguageHostResolver's www handling.
 */

namespace LocalePress\Settings {
	class PluginSettings {
		public $mode;
		public $prefix_default;
		public function __construct( $mode = 'directory', $prefix_default = false ) {
			$this->mode           = $mode;
			$this->prefix_default = $prefix_default;
		}
		public function get_section( $section ) {
			return array( 'mode' => $this->mode );
		}
		public function should_prefix_default_language() {
			return $this->prefix_default;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['lp_home'] = 'https://www.example.com';

	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = str_replace( '.', '-', $title );
		$title = preg_replace( '/[^a-z0-9 _-]/', '', $title );
		return trim( preg_replace( '/[\s_]+/', '-', $title ), '-' );
	}
	function did_action( $name ) { return 0; }
	function apply_filters( $name, $value ) {
		return $value;
	}
	function trailingslashit( $value ) {
		return rtrim( $value, '/' ) . '/';
	}
	function get_option( $name, $default = false ) {
		return 'home' === $name ? $GLOBALS['lp_home'] : $default;
	}
	function home_url( $path = '' ) {
		return $GLOBALS['lp_home'] . $path;
	}

	require __DIR__ . '/../../src/Routing/class-languagehostresolver.php';

	use LocalePress\Routing\LanguageHostResolver;
	use LocalePress\Settings\PluginSettings;

	$en = array( 'id' => 'en', 'url_slug' => 'en', 'domain' => '' );
	$bn = array( 'id' => 'bn', 'url_slug' => 'bn', 'domain' => '' );
	$es = array( 'id' => 'es', 'url_slug' => 'es', 'domain' => 'www.example.es' );

	$pass = 0;
	$fail = 0;

	function check( $label, $actual, $expected ) {
		global $pass, $fail;
		if ( $actual === $expected ) {
			++$pass;
			printf( "  ok   %-52s %s\n", $label, var_export( $actual, true ) );
		} else {
			++$fail;
			printf( "  FAIL %-52s got %s, want %s\n", $label, var_export( $actual, true ), var_export( $expected, true ) );
		}
	}

	echo "\n== subdomain / www site / default unprefixed ==\n";
	$r = new LanguageHostResolver( new PluginSettings( 'subdomain', false ) );
	check( 'default keeps www', $r->get_host( $en, true ), 'www.example.com' );
	check( 'other language subdomain', $r->get_host( $bn, false ), 'bn.example.com' );
	check( 'base host is registrable', $r->get_base_host(), 'example.com' );
	check( 'site host compares bare', $r->get_site_host(), 'example.com' );
	check( 'canonical keeps www', $r->get_canonical_site_host(), 'www.example.com' );
	$m = $r->match( 'www.example.com', array( 'en' => $en, 'bn' => $bn ), 'en' );
	check( 'www host matches default', is_array( $m ) ? $m['id'] : null, 'en' );
	$m = $r->match( 'example.com', array( 'en' => $en, 'bn' => $bn ), 'en' );
	check( 'bare host matches default too', is_array( $m ) ? $m['id'] : null, 'en' );
	$m = $r->match( 'bn.example.com', array( 'en' => $en, 'bn' => $bn ), 'en' );
	check( 'subdomain host matches', is_array( $m ) ? $m['id'] : null, 'bn' );

	echo "\n== subdomain / www site / default prefixed ==\n";
	$r = new LanguageHostResolver( new PluginSettings( 'subdomain', true ) );
	check( 'default gets its own subdomain', $r->get_host( $en, true ), 'en.example.com' );
	check( 'site host now serves nobody', $r->match( 'www.example.com', array( 'en' => $en, 'bn' => $bn ), 'en' ), null );

	echo "\n== domain / www site ==\n";
	$r = new LanguageHostResolver( new PluginSettings( 'domain', false ) );
	check( 'unconfigured language keeps www', $r->get_host( $en, true ), 'www.example.com' );
	check( 'configured domain kept verbatim', $r->get_host( $es, false ), 'www.example.es' );
	$m = $r->match( 'example.es', array( 'en' => $en, 'es' => $es ), 'en' );
	check( 'bare spelling of a www domain matches', is_array( $m ) ? $m['id'] : null, 'es' );

	echo "\n== non-www site ==\n";
	$GLOBALS['lp_home'] = 'https://example.com';
	$r                  = new LanguageHostResolver( new PluginSettings( 'subdomain', false ) );
	check( 'default stays bare', $r->get_host( $en, true ), 'example.com' );
	check( 'subdomain unchanged', $r->get_host( $bn, false ), 'bn.example.com' );

	echo "\n== normalize is for comparing only ==\n";
	check( 'strips www, port, scheme, path', $r->normalize( 'https://www.Example.com:8080/x?y' ), 'example.com' );
	check( 'rejects a non-host', $r->normalize( 'not a host!' ), '' );

	printf( "\n%d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
