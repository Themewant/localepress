<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * @package LocalePress
 */

$localepress_tests_directory = getenv( 'WP_TESTS_DIR' );

if ( ! $localepress_tests_directory ) {
	$localepress_tests_directory = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $localepress_tests_directory . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI bootstrap reports to STDERR.
	fwrite( STDERR, "WordPress test suite not found. Set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $localepress_tests_directory . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/localepress.php';
	}
);

// Routing tests instantiate their own module with isolated language storage.
tests_add_filter( 'localepress_enable_frontend_routing', '__return_false' );

require $localepress_tests_directory . '/includes/bootstrap.php';
