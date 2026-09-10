# LocalePress Free 1.0 Release Checklist

Run this matrix on a disposable site with `WP_DEBUG` and `SCRIPT_DEBUG` enabled. Repeat frontend URL checks with both `/%postname%/` and a dated post permalink structure.

## Languages

- Complete setup with one language, add a second language, and rerun setup without duplicates.
- Add, edit, order, enable, disable, set default, and delete an unused LTR and RTL language.
- Reject malformed locales, codes, slugs, duplicates, disabled defaults, missing nonces, and insufficient capabilities.

## Content

- Create, assign, translate, edit, trash, restore, and permanently delete Posts, Pages, and one public custom post type.
- Confirm default-language assignment, one-click add/edit actions, duplicate-language prevention, source repair, and list columns.
- Copy and independently edit Gutenberg Heading, Paragraph, Image, Gallery, Buttons, Columns, Cover, List, Table, and Query blocks.

## Taxonomies

- Repeat assignment, translation, duplicate prevention, language changes, and deletion for Categories, Tags, and one hierarchical custom taxonomy.
- Confirm translated parents are used when available and missing translated parents do not create invalid relationships.
- Confirm a post saved without a category lands in the default category of its own language in both editors, that an untranslated default is left alone, and that a chosen category is never rewritten.
- Choose a per-language default term in Settings > Content, confirm it is used ahead of the translated default, that a term of the wrong language is rejected, and that an export omits the choices while an import keeps them.

## URLs And SEO

- Check `/`, `/en/`, `/de/`, translated singulars, front page, posts page, CPT archives, taxonomy archives, pagination, search, previews, and 404s.
- Repeat with the default prefix enabled and disabled; confirm canonical redirects do not loop.
- Verify `html[lang]`, RTL direction/classes, unique hreflang entries, `x-default`, public canonicals, missing translations, and noindex contexts.
- Repeat canonical checks with Yoast SEO and Rank Math individually active.

## Switcher And Menus

- Exercise shortcode, block, template API, classic-menu item, list/dropdown layouts, labels, visibility settings, flags filter, and every missing-translation fallback.
- Navigate by keyboard and verify labels, current state, disabled state, translated deep links, external links, and per-language theme-location menus.

## Visitor detection

- With detection enabled and no cookie, request the site root sending `Accept-Language: bn-BD,bn;q=0.9,en;q=0.8` and confirm one 302 to the Bengali home with `Vary: Accept-Language`.
- Confirm prefixed URLs, interior pages, POST requests, feeds, and 404s are never redirected by detection.
- Confirm an internal referrer and an existing cookie both suppress header negotiation.
- Confirm the site root is unchanged when detection is disabled.

## Builders

- Open copied core block content in Gutenberg and confirm source and translation remain independent.
- With Elementor Free active, copy pages using containers, Heading, Text Editor, Image, Button, Icon, and legacy sections; open and save both language documents independently.
- Confirm LocalePress has no Elementor errors when Elementor is inactive.

## Administration

- Exercise Translation Dashboard filters, translated-title search, statuses, pagination, screen options, add/edit links, and a dataset over 1,000 groups.
- Register, search, filter, paginate, save, clear, cache, and retrieve string translations in two languages.
- Save every settings tab, export/import valid JSON, and reject malformed, oversized, wrong-version, unknown-locale, unavailable-type, and unauthorized imports without partial changes.

## Lifecycle And Compatibility

- Activate, deactivate, reactivate, upgrade, and uninstall with data deletion disabled and enabled.
- Repeat network activation/deactivation on multisite and confirm each existing site installs and invalidates rewrite state without showing setup redirects.
- Run PHPUnit on WordPress 6.4.x, the previous stable branch, and latest stable with PHP 7.4, 8.1, and 8.4 where WordPress supports the combination.
- Run PHPCS, Plugin Check against the release ZIP, PHP lint, JavaScript syntax checks, and verify a clean `debug.log` after browser smoke tests.
- Confirm WooCommerce receives generic CPT behavior only and that no WooCommerce, AI, ACF, Bricks, Divi, or Pro module ships in the archive.
