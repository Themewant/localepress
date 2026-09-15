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

function lp_router( $mode, $prefix_default = false, $split_sitemaps = true ) {
	$urls   = lp_urls( $mode, $prefix_default );
	$router = new RoutingModule(
		$urls,
		new PostTranslationManager(),
		new TermTranslationManager(),
		new PluginSettings( $mode, $prefix_default, true, $split_sitemaps )
	);
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
	$module               = new SitemapModule(
		$urls,
		new LanguageManager( $GLOBALS['languages_default'] ),
		new PostTranslationManager(),
		new TermTranslationManager(),
		new PluginSettings( $mode )
	);
	return $module->get_sitemap_language_ids();
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

// =====================================================================
echo "\n### 10. Which sitemap addresses gain a language\n";
// =====================================================================

// The rules WordPress registers for its own sitemaps, verbatim.
$core_sitemap_rules = array(
	'^wp-sitemap\.xml$'                                        => 'index.php?sitemap=index',
	'^wp-sitemap\.xsl$'                                        => 'index.php?sitemap-stylesheet=sitemap',
	'^wp-sitemap-index\.xsl$'                                  => 'index.php?sitemap-stylesheet=index',
	'^wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$'         => 'index.php?sitemap=$matches[1]&sitemap-subtype=$matches[2]&paged=$matches[3]',
	'^wp-sitemap-([a-z]+?)-(\d+?)\.xml$'                       => 'index.php?sitemap=$matches[1]&paged=$matches[2]',
);

$_SERVER['HTTP_HOST']  = 'example.com';
list( $urls, $router ) = lp_router( 'directory' );
$rules                 = $router->add_language_rewrite_rules( $core_sitemap_rules );
$added                 = array_diff_key( $rules, $core_sitemap_rules );

// The bare language rule is always added; ignore it when counting sitemaps.
unset( $added['^(en|bn)/?$'] );

lp_check( 'only the two page rules gain one', count( $added ), 2 );
lp_check(
	'the index keeps a single address',
	isset( $added['^(en|bn)/wp-sitemap\.xml$'] ),
	false
);
lp_check(
	'and so do the stylesheets',
	isset( $added['^(en|bn)/wp-sitemap\.xsl$'] ) || isset( $added['^(en|bn)/wp-sitemap-index\.xsl$'] ),
	false
);
lp_check(
	'a subtype page is served in its language',
	isset( $added['^(en|bn)/wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$'] )
		? $added['^(en|bn)/wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$']
		: '',
	'index.php?sitemap=$matches[2]&sitemap-subtype=$matches[3]&paged=$matches[4]&localepress_lang=$matches[1]'
);
lp_check(
	'a subtype-less page too',
	isset( $added['^(en|bn)/wp-sitemap-([a-z]+?)-(\d+?)\.xml$'] )
		? $added['^(en|bn)/wp-sitemap-([a-z]+?)-(\d+?)\.xml$']
		: '',
	'index.php?sitemap=$matches[2]&paged=$matches[3]&localepress_lang=$matches[1]'
);
lp_check( 'the unprefixed rules are all kept', count( array_intersect_key( $rules, $core_sitemap_rules ) ), 5 );

// With splitting off, no sitemap address gains a language at all.
list( $urls, $router ) = lp_router( 'directory', false, false );
$off                   = array_diff_key( $router->add_language_rewrite_rules( $core_sitemap_rules ), $core_sitemap_rules );
unset( $off['^(en|bn)/?$'] );
lp_check( 'turning the split off adds none', count( $off ), 0 );

// =====================================================================
echo "\n### 11. The unprefixed default language is reachable\n";
// =====================================================================

function lp_detection( $prefix_default ) {
	return new LanguageDetectionModule(
		lp_urls( 'directory', $prefix_default ),
		new LanguageManager( array() ),
		new BrowserLanguageDetector(),
		new PluginSettings( 'directory', $prefix_default )
	);
}

/*
 * With no prefix of its own the default language has only the site root to be
 * reached at, which is also the address detection answers. A cookie left by the
 * page the visitor came from must not send them back to it.
 */
lp_set( 'referer', 'https://example.com/bn/' );
lp_check( 'an internal link to the root is a choice', lp_private( lp_detection( false ), 'request_chose_unprefixed_default_language' ), true );
lp_check( 'but not while that language is prefixed', lp_private( lp_detection( true ), 'request_chose_unprefixed_default_language' ), false );

lp_set( 'referer', false );
lp_check( 'and a bookmark is not a choice either', lp_private( lp_detection( false ), 'request_chose_unprefixed_default_language' ), false );

// The end of it: a remembered language no longer outvotes the visitor.
$_COOKIE['localepress_language'] = 'bn';
lp_set( 'referer', 'https://example.com/bn/' );
lp_check( 'so the root is left alone', lp_private( lp_detection( false ), 'resolve_preferred_language' ), null );

lp_set( 'referer', false );
$remembered = lp_private( lp_detection( false ), 'resolve_preferred_language' );
lp_check( 'while a direct visit is still answered', is_array( $remembered ) ? $remembered['id'] : '', 'bn' );
unset( $_COOKIE['localepress_language'] );

// An unprefixed default-language page is what keeps the cookie current.
lp_set( 'has_prefix', false );
lp_set( 'current_language', 'en' );
lp_check( 'the unprefixed page records its language', lp_private( lp_detection( false ), 'serves_unprefixed_default_language' ), true );
lp_check( 'a prefixed site has no such page', lp_private( lp_detection( true ), 'serves_unprefixed_default_language' ), false );

lp_set( 'current_language', 'bn' );
lp_check( 'and no other language is mistaken for it', lp_private( lp_detection( false ), 'serves_unprefixed_default_language' ), false );
lp_set( 'current_language', 'en' );

// =====================================================================
echo "\n### 12. One cookie, however it is written\n";
// =====================================================================

$arguments = lp_detection( false )->get_cookie_arguments( array( 'id' => 'bn', 'url_slug' => 'bn' ) );
lp_check( 'the browser writer is given a path', $arguments['path'], COOKIEPATH );
lp_check( 'the same domain PHP would use', $arguments['domain'], COOKIE_DOMAIN );
lp_check( 'and the same SameSite policy', $arguments['samesite'], 'Lax' );
lp_check( 'readable from script, which is the point', $arguments['httponly'], false );
lp_check( 'a year out by default', $arguments['expires'] > time() + YEAR_IN_SECONDS - 60, true );

// A page cache would store a Set-Cookie header and replay it to everyone.
lp_check( 'no cache is assumed by default', LocalePress\Integrations\Cache\CacheCompatibility::is_active(), false );
add_filter( 'localepress_is_cache_active', function () {
	return true;
} );
lp_check( 'until the site says otherwise', LocalePress\Integrations\Cache\CacheCompatibility::is_active(), true );

lp_report();
