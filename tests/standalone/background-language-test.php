<?php
/**
 * Which language an AJAX or REST request is answered in.
 *
 * These are the rules that decide whether a request with no page address of its
 * own carries a language, and they are all pure PHP, so they are asked here
 * rather than against a database.
 */

require __DIR__ . '/bootstrap.php';

use LocalePress\Routing\BackgroundLanguageResolver;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Settings\PluginSettings;

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

$urls = new LanguageUrlManager( new PluginSettings( 'directory' ), $languages );

/**
 * Builds a resolver for one request shape.
 *
 * A new one is built per case because a resolver answers its request once and
 * remembers the answer, which is exactly what it should do inside a request and
 * exactly what would hide a bug across several of them.
 *
 * @param array       $arguments Request arguments, as $_GET would hold them.
 * @param string|bool $referer   Address of the calling page, or false for none.
 * @param bool        $admin     Whether WordPress considers this an admin request.
 * @return BackgroundLanguageResolver
 */
function lp_resolver( array $arguments = array(), $referer = false, $admin = true ) {
	global $urls;

	$_GET  = $arguments;
	$_POST = array();

	lp_set( 'referer', $referer );
	lp_set( 'is_admin', $admin );

	return new BackgroundLanguageResolver( $urls );
}

echo "### 1. An ordinary page is not a background request\n";
lp_set( 'doing_ajax', false );
$page = lp_resolver( array(), false, false );
lp_check( 'a frontend page names nothing here', $page->resolve_language_id(), '' );
lp_check( 'but its filters still apply', $page->scopes_request(), true );
lp_check( 'an administration screen is left alone', lp_resolver( array(), false, true )->scopes_request(), false );

echo "\n### 2. AJAX takes the language of the page that called it\n";
lp_set( 'doing_ajax', true );

lp_check(
	'a prefixed page hands over its language',
	lp_resolver( array(), 'https://example.com/bn/hello/' )->resolve_language_id(),
	'bn'
);
lp_check(
	'an unprefixed page means the default one',
	lp_resolver( array(), 'https://example.com/hello/' )->resolve_language_id(),
	'en'
);
lp_check(
	'and the call is then scoped like that page',
	lp_resolver( array(), 'https://example.com/bn/hello/' )->scopes_request(),
	true
);
lp_check(
	'an explicit argument beats the calling page',
	lp_resolver( array( 'lang' => 'bn' ), 'https://example.com/hello/' )->resolve_language_id(),
	'bn'
);
lp_check(
	'an argument naming nothing we know is ignored',
	lp_resolver( array( 'lang' => 'zz' ), 'https://example.com/bn/hello/' )->resolve_language_id(),
	'bn'
);

echo "\n### 3. The administration is left exactly as it was\n";
lp_check(
	'a call from a screen names no language',
	lp_resolver( array(), 'https://example.com/wp-admin/edit.php' )->resolve_language_id(),
	''
);
lp_check(
	'not even when the screen filter names one',
	lp_resolver( array( 'lang' => 'bn' ), 'https://example.com/wp-admin/edit.php?lang=bn' )->resolve_language_id(),
	''
);
lp_check(
	'so nothing about that call is scoped',
	lp_resolver( array( 'lang' => 'bn' ), 'https://example.com/wp-admin/edit.php' )->scopes_request(),
	false
);
lp_check(
	'a call from nowhere is treated as one of those',
	lp_resolver( array( 'lang' => 'bn' ), false )->resolve_language_id(),
	''
);
lp_check(
	'and the backend argument settles it against any page',
	lp_resolver(
		array( BackgroundLanguageResolver::BACKEND_ARGUMENT => '1' ),
		'https://example.com/bn/hello/'
	)->resolve_language_id(),
	''
);

echo "\n### 4. The plugin's own argument is the unambiguous opt-in\n";
lp_check(
	'it is honoured with no referrer at all',
	lp_resolver( array( LanguageUrlManager::QUERY_VAR => 'bn' ), false )->resolve_language_id(),
	'bn'
);
lp_check(
	'and from an administration screen too',
	lp_resolver(
		array( LanguageUrlManager::QUERY_VAR => 'bn' ),
		'https://example.com/wp-admin/admin.php'
	)->resolve_language_id(),
	'bn'
);

echo "\n### 5. A dispatcher can name the language itself\n";
$dispatched = lp_resolver( array(), 'https://example.com/wp-admin/post.php' );
lp_check( 'nothing to start with', $dispatched->resolve_language_id(), '' );
$displaced = $dispatched->set_override( 'bn' );
lp_check( 'the override answers whatever the call looked like', $dispatched->resolve_language_id(), 'bn' );
lp_check( 'and it reports what it displaced', $displaced, '' );
$displaced_again = $dispatched->set_override( 'en' );
lp_check( 'a nested one displaces the first', $dispatched->resolve_language_id(), 'en' );
lp_check( 'which it can hand back', $displaced_again, 'bn' );
$dispatched->set_override( $displaced_again );
lp_check( 'leaving the outer language where it was', $dispatched->resolve_language_id(), 'bn' );

echo "\n### 6. REST reads what it was asked for and nothing else\n";
/*
 * Defined here rather than at the top of the file: it cannot be undefined
 * again, so everything that has to be observed outside a REST request has to
 * have been observed by now.
 */
define( 'REST_REQUEST', true );
lp_set( 'doing_ajax', false );

lp_check(
	'the argument is read',
	lp_resolver( array( 'lang' => 'bn' ), false )->resolve_language_id(),
	'bn'
);
lp_check(
	'even with the editor screen as the referrer',
	lp_resolver( array( 'lang' => 'bn' ), 'https://example.com/wp-admin/post.php?post=1' )->resolve_language_id(),
	'bn'
);
lp_check(
	'a calling page is never read instead',
	lp_resolver( array(), 'https://example.com/bn/hello/' )->resolve_language_id(),
	''
);
lp_check(
	'a collection nobody gave a language to stays unscoped',
	lp_resolver( array(), false )->scopes_request(),
	false
);
lp_check(
	'and one that was given a language is scoped',
	lp_resolver( array( 'lang' => 'bn' ), false )->scopes_request(),
	true
);
lp_check(
	'but never by the rules a rendered page follows',
	lp_resolver( array( 'lang' => 'bn' ), false )->scopes_rendered_page(),
	false
);

lp_report();
