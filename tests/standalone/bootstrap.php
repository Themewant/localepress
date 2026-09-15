<?php
/**
 * Harness that runs real LocalePress routing classes against fake collaborators.
 *
 * Each fake carries the exact fully-qualified name of the class it stands in
 * for and is defined before the real file is required, so the real code under
 * test is loaded unmodified and its type hints are satisfied.
 */

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'COOKIE_DOMAIN', 'example.com' );
	define( 'COOKIEPATH', '/' );
	define( 'YEAR_IN_SECONDS', 31536000 );

	const LP_SRC = __DIR__ . '/../../src/';

	$GLOBALS['lp_home']       = 'https://example.com';
	$GLOBALS['lp_filters']    = array();
	$GLOBALS['lp_actions']    = array();
	$GLOBALS['lp_state']      = array();
	$GLOBALS['lp_pass']       = 0;
	$GLOBALS['lp_fail']       = 0;
	$GLOBALS['lp_failed']     = array();

	function lp_state( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['lp_state'] ) ? $GLOBALS['lp_state'][ $key ] : $default;
	}
	function lp_set( $key, $value ) {
		$GLOBALS['lp_state'][ $key ] = $value;
	}

	// --- WordPress surface -------------------------------------------------

	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = str_replace( '.', '-', $title );
		$title = preg_replace( '/[^a-z0-9 _-]/', '', $title );
		return trim( preg_replace( '/[\s_]+/', '-', $title ), '-' );
	}
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/' ) . '/';
	}
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/' );
	}
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['lp_filters'][ $hook ][] = $callback;
		return true;
	}
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['lp_actions'][ $hook ][] = $callback;
		return true;
	}
	function apply_filters( $hook, $value ) {
		$extra = array_slice( func_get_args(), 2 );
		foreach ( isset( $GLOBALS['lp_filters'][ $hook ] ) ? $GLOBALS['lp_filters'][ $hook ] : array() as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $extra ) );
		}
		return $value;
	}
	function did_action( $hook ) {
		return (int) lp_state( 'did_action_' . $hook, 0 );
	}
	function get_option( $name, $default = false ) {
		return 'home' === $name ? $GLOBALS['lp_home'] : $default;
	}
	function home_url( $path = '' ) {
		return apply_filters( 'home_url', $GLOBALS['lp_home'] . ( '' === $path ? '' : $path ), $path );
	}
	function get_theme_root() {
		return dirname( __DIR__, 4 ) . '/themes';
	}
	function is_admin() {
		return (bool) lp_state( 'is_admin' );
	}
	function wp_doing_cron() {
		return false;
	}
	function wp_doing_ajax() {
		return (bool) lp_state( 'doing_ajax' );
	}
	function wp_get_referer() {
		return lp_state( 'referer', false );
	}
	function is_404() {
		return (bool) lp_state( 'is_404' );
	}
	function is_preview() {
		return false;
	}
	function is_front_page() {
		return (bool) lp_state( 'is_front_page', true );
	}
	function get_query_var( $name, $default = '' ) {
		return $default;
	}
	function is_ssl() {
		return true;
	}

	// --- Fake collaborators ------------------------------------------------

	require __DIR__ . '/fakes.php';

	// --- Real classes under test -------------------------------------------

	require LP_SRC . 'Contracts/interface-module.php';
	require LP_SRC . 'Integrations/Cache/class-cachecompatibility.php';
	require LP_SRC . 'Routing/class-backgroundlanguageresolver.php';
	require LP_SRC . 'Routing/class-languagehostresolver.php';
	require LP_SRC . 'Routing/class-hostoriginmodule.php';
	require LP_SRC . 'Routing/class-routingmodule.php';
	require LP_SRC . 'Routing/class-languagedetectionmodule.php';
	require LP_SRC . 'SEO/class-sitemapmodule.php';

	// --- Assertions --------------------------------------------------------

	function lp_check( $label, $actual, $expected ) {
		if ( $actual === $expected ) {
			++$GLOBALS['lp_pass'];
			printf( "  ok   %-56s %s\n", $label, var_export( $actual, true ) );
		} else {
			++$GLOBALS['lp_fail'];
			$GLOBALS['lp_failed'][] = $label;
			printf( "  FAIL %-56s got %s, want %s\n", $label, var_export( $actual, true ), var_export( $expected, true ) );
		}
	}

	function lp_report() {
		printf( "\n%d passed, %d failed\n", $GLOBALS['lp_pass'], $GLOBALS['lp_fail'] );
		foreach ( $GLOBALS['lp_failed'] as $label ) {
			echo "  - $label\n";
		}
		exit( $GLOBALS['lp_fail'] > 0 ? 1 : 0 );
	}

	/**
	 * Calls a closure through a named function so a backtrace can recognize it.
	 */
	function lp_call_as( $name, $callback ) {
		if ( ! function_exists( $name ) ) {
			eval( 'function ' . $name . '( $cb ) { return $cb(); }' );
		}
		return call_user_func( $name, $callback );
	}
}
