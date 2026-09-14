<?php
/**
 * Exercises each fix against the real class that implements it.
 */

require __DIR__ . '/bootstrap.php';

use LocalePress\Content\PostTranslationManager;
use LocalePress\Language\BrowserLanguageDetector;
use LocalePress\Language\LanguageManager;
use LocalePress\Routing\HostOriginModule;
use LocalePress\Routing\LanguageDetectionModule;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Routing\RoutingModule;
use LocalePress\SEO\SitemapModule;
use LocalePress\Settings\PluginSettings;
use LocalePress\Taxonomy\TermTranslationManager;

$languages = array(
	'en' => array(
		'id'       => 'en',
		'url_slug' => 'en',
		'domain'   => '',
		'enabled'  => true,
	),
	'bn' => array(
		'id'       => 'bn',
		'url_slug' => 'bn',
		'domain'   => '',
		'enabled'  => true,
	),
);

function lp_urls( $mode, $prefix_default = false, $languages = null ) {
	global $languages_default;
	lp_set( 'prefix_default', $prefix_default );
	return new LanguageUrlManager(
		new PluginSettings( $mode, $prefix_default ),
		null === $languages ? $languages_default : $languages
	);
}
$languages_default = $languages;

function lp_private( $object, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

/*
 * Run before anything is printed. vary_response() declines once headers are
 * gone, and in the CLI the first echo sends them, so this is the only point in
 * the script where the real guard can be observed.
 */
$vary_probe = array();
( function () use ( &$vary_probe, $languages ) {
	$module = new LanguageDetectionModule(
		new LanguageUrlManager( new PluginSettings( 'directory' ), $languages ),
		new LanguageManager( array() ),
		new BrowserLanguageDetector(),
		new PluginSettings( 'directory' )
	);

	$varied = new ReflectionProperty( $module, 'varied' );
	$varied->setAccessible( true );

	$vary_probe['before']  = $varied->getValue( $module );
	$vary_probe['headers'] = headers_sent();

	ob_start();
	lp_private( $module, 'vary_response' );
	$vary_probe['headers_written'] = headers_list();
	ob_end_clean();

	$vary_probe['after'] = $varied->getValue( $module );

	lp_private( $module, 'vary_response' );
	$vary_probe['still_once'] = $varied->getValue( $module );
} )();

// =====================================================================
echo "\n### 1. www preserved in host modes (subdomain, www site)\n";
// =====================================================================

$GLOBALS['lp_home'] = 'https://www.example.com';
$urls               = lp_urls( 'subdomain' );

lp_check( 'default language host keeps www', $urls->get_language_host( 'en' ), 'www.example.com' );
lp_check( 'other language sits beside www', $urls->get_language_host( 'bn' ), 'bn.example.com' );
lp_check( 'www request still resolves to default', $urls->hosts()->normalize( 'www.example.com' ), 'example.com' );

// =====================================================================
echo "\n### 2. AJAX / REST / assets stay on the request host\n";
// =====================================================================

$GLOBALS['lp_home']    = 'https://example.com';
$GLOBALS['lp_filters'] = array();
$_SERVER['HTTP_HOST']  = 'bn.example.com';

$urls   = lp_urls( 'subdomain' );
$origin = new HostOriginModule( $urls );
$origin->register();

lp_check(
	'REST root moves to the language host',
	apply_filters( 'rest_url', 'https://example.com/wp-json/' ),
	'https://bn.example.com/wp-json/'
);
lp_check(
	'theme asset moves to the language host',
	apply_filters( 'content_url', 'https://example.com/wp-content/themes/x/app.css' ),
	'https://bn.example.com/wp-content/themes/x/app.css'
);
lp_check(
	'admin-ajax moves to the language host',
	apply_filters( 'admin_url', 'https://example.com/wp-admin/admin-ajax.php', 'admin-ajax.php' ),
	'https://bn.example.com/wp-admin/admin-ajax.php'
);
lp_check(
	'every other admin URL stays where the session is',
	apply_filters( 'admin_url', 'https://example.com/wp-admin/edit.php', 'edit.php' ),
	'https://example.com/wp-admin/edit.php'
);
lp_check(
	'a third-party host is left alone',
	apply_filters( 'content_url', 'https://cdn.other.net/x.js' ),
	'https://cdn.other.net/x.js'
);
$uploads = apply_filters(
	'upload_dir',
	array(
		'url'     => 'https://example.com/wp-content/uploads/2026/01',
		'baseurl' => 'https://example.com/wp-content/uploads',
	)
);
lp_check( 'uploads move to the language host', $uploads['url'], 'https://bn.example.com/wp-content/uploads/2026/01' );

// Directory mode must not touch any of this.
$GLOBALS['lp_filters'] = array();
$origin                = new HostOriginModule( lp_urls( 'directory' ) );
$origin->register();
lp_check( 'directory mode registers no origin filters', empty( $GLOBALS['lp_filters'] ), true );

// =====================================================================
echo "\n### 3. Cookie domain per mode\n";
// =====================================================================

function lp_cookie_domain( $mode ) {
	$urls = lp_urls( $mode );
	$module = new LanguageDetectionModule(
		$urls,
		new LanguageManager( array() ),
		new BrowserLanguageDetector(),
		new PluginSettings( $mode )
	);
	return lp_private( $module, 'get_cookie_domain' );
}

$GLOBALS['lp_home'] = 'https://example.com';
lp_check( 'subdomain mode spans the whole domain', lp_cookie_domain( 'subdomain' ), '.example.com' );
lp_check( 'domain mode stays host-only', lp_cookie_domain( 'domain' ), '' );
lp_check( 'directory mode keeps COOKIE_DOMAIN', lp_cookie_domain( 'directory' ), COOKIE_DOMAIN );

$GLOBALS['lp_home'] = 'https://www.example.com';
lp_check( 'a www site spans the registrable domain', lp_cookie_domain( 'subdomain' ), '.example.com' );
$GLOBALS['lp_home'] = 'https://example.com';

// =====================================================================
echo "\n### 4. Cache headers announce what varies\n";
// =====================================================================

$urls   = lp_urls( 'directory' );
$module = new LanguageDetectionModule(
	$urls,
	new LanguageManager( array() ),
	new BrowserLanguageDetector(),
	new PluginSettings( 'directory' )
);

$GLOBALS['lp_actions'] = array();
$module->register();

$order = array();
foreach ( $GLOBALS['lp_actions']['template_redirect'] as $callback ) {
	$order[] = $callback[1];
}
lp_check( 'the Vary headers are decided first', $order[0], 'protect_detected_response' );
lp_check( 'before the detection redirect', $order[1], 'redirect_preferred_language' );

lp_check( 'nothing announced before the response starts', $vary_probe['before'], false );
lp_check( 'headers were still open when it ran', $vary_probe['headers'], false );
lp_check( 'the response is marked as varying', $vary_probe['after'], true );

// The detection redirect and the canonical 301 share one response, so the
// headers are written once rather than once per exit path.
lp_check( 'and is not marked again on a second exit path', $vary_probe['still_once'], true );

// =====================================================================
echo "\n### 5. Bare-domain redirect under host routing\n";
// =====================================================================

function lp_router( $mode, $prefix_default = false ) {
	$urls   = lp_urls( $mode, $prefix_default );
	$router = new RoutingModule( $urls, new PostTranslationManager(), new TermTranslationManager() );
	return array( $urls, $router );
}

lp_set( 'request_uri', '/' );

// Every language prefixed: the site address names nobody.
$_SERVER['HTTP_HOST'] = 'example.com';
list( $urls, $router ) = lp_router( 'subdomain', true );
lp_check( 'site address serves no language', $urls->request_host_serves_no_language(), true );
lp_check(
	'and is forwarded to the default one',
	$router->get_unrouted_host_redirect_url(),
	'https://en.example.com/'
);

// On a language host there is nothing to correct.
$_SERVER['HTTP_HOST'] = 'bn.example.com';
list( $urls, $router ) = lp_router( 'subdomain', true );
lp_check( 'a language host is left alone', $router->get_unrouted_host_redirect_url(), '' );

// Unprefixed default: the site address is the default language.
$_SERVER['HTTP_HOST'] = 'example.com';
list( $urls, $router ) = lp_router( 'subdomain', false );
lp_check( 'unprefixed default keeps the site address', $router->get_unrouted_host_redirect_url(), '' );

// Directory mode has no host to correct.
list( $urls, $router ) = lp_router( 'directory', true );
lp_check( 'directory mode never corrects a host', $router->get_unrouted_host_redirect_url(), '' );

// A 404 is not redirected.
lp_set( 'is_404', true );
$_SERVER['HTTP_HOST'] = 'example.com';
list( $urls, $router ) = lp_router( 'subdomain', true );
lp_check( 'a 404 is left where it is', $router->get_unrouted_host_redirect_url(), '' );
lp_set( 'is_404', false );

// =====================================================================
echo "\n### 6. Sitemap scope per host\n";
// =====================================================================

function lp_sitemap_ids( $mode, $host ) {
	$_SERVER['HTTP_HOST'] = $host;
	$urls                 = lp_urls( $mode );
	$module               = new SitemapModule( $urls, new LanguageManager( $GLOBALS['languages_default'] ) );
	return lp_private( $module, 'get_enabled_language_ids' );
}

lp_check( 'a language host lists only its own', lp_sitemap_ids( 'subdomain', 'bn.example.com' ), array( 'bn' ) );
lp_check( 'the default host lists only the default', lp_sitemap_ids( 'subdomain', 'example.com' ), array( 'en' ) );
lp_check( 'directory mode lists every language', lp_sitemap_ids( 'directory', 'example.com' ), array( 'en', 'bn' ) );

// =====================================================================
echo "\n### 7. Theme home link in directory mode\n";
// =====================================================================

$GLOBALS['lp_filters'] = array();
$_SERVER['HTTP_HOST']  = 'example.com';
lp_set( 'current_language', 'bn' );
lp_set( 'did_action_template_redirect', 1 );

list( $urls, $router ) = lp_router( 'directory', false );
add_filter( 'home_url', array( $router, 'filter_home_url' ), 20, 2 );

lp_check(
	'the Site Title block gets the language home',
	lp_call_as(
		'render_block_core_site_title',
		static function () {
			return home_url( '/' );
		}
	),
	'https://example.com/bn/'
);
lp_check(
	'a plain internal call is untouched',
	home_url( '/sample-page/' ),
	'https://example.com/sample-page/'
);
lp_check(
	'the search form keeps the bare root',
	lp_call_as(
		'get_search_form',
		static function () {
			return home_url( '/' );
		}
	),
	'https://example.com/'
);
lp_check(
	'an unrecognized caller is untouched',
	home_url( '/' ),
	'https://example.com/'
);

lp_set( 'did_action_template_redirect', 0 );
lp_check(
	'nothing is localized before the page is decided',
	lp_call_as(
		'render_block_core_site_title',
		static function () {
			return home_url( '/' );
		}
	),
	'https://example.com/'
);

// =====================================================================
echo "\n### 8. Host mode still rewrites every home URL\n";
// =====================================================================

$GLOBALS['lp_filters'] = array();
$_SERVER['HTTP_HOST']  = 'bn.example.com';
list( $urls, $router ) = lp_router( 'subdomain', false );
add_filter( 'home_url', array( $router, 'filter_home_url' ), 20, 2 );

lp_check( 'the site root follows the language host', home_url( '/' ), 'https://bn.example.com/' );
lp_check( 'and so does a path below it', home_url( '/shop/' ), 'https://bn.example.com/shop/' );
lp_check( 'but never a wp-json address', home_url( '/wp-json/' ), 'https://example.com/wp-json/' );

// =====================================================================
echo "\n### 9. A redirect is only issued once its target is settled\n";
// =====================================================================

$GLOBALS['lp_filters'] = array();
$_SERVER['HTTP_HOST']  = 'example.com';
list( $urls, $router ) = lp_router( 'subdomain', true );

$en = $urls->resolve_language( 'en' );
$bn = $urls->resolve_language( 'bn' );

lp_check(
	'a target naming its own language is settled',
	lp_private( $router, 'is_settled_redirect', array( 'https://bn.example.com/x/', $bn ) ),
	true
);
lp_check(
	'a target naming a different language is not',
	lp_private( $router, 'is_settled_redirect', array( 'https://bn.example.com/x/', $en ) ),
	false
);
lp_check(
	'nor is one naming no language at all',
	lp_private( $router, 'is_settled_redirect', array( 'https://other.test/x/', $en ) ),
	false
);

lp_report();
