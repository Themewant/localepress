=== LocalePress ===
Contributors: shapecode
Tags: multilingual, language, localization, rtl
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, extensible multilingual foundation for WordPress.

== Description ==

LocalePress provides secure language registration, core translation relationships, a central translation dashboard, registered-string translation, language-prefixed frontend routing, optional browser language detection, accessible language switchers, multilingual navigation menus, Gutenberg-safe manual translation workflows, two-phase copy and synchronization, Elementor page, Theme Builder, and popup translation, and essential multilingual SEO metadata. It supports posts, pages, public custom post types, synced patterns, categories, tags, and public custom taxonomies.

Creating a translation always copies the source content, taxonomies, public custom fields, page template, featured image, and the other core post fields, so no editor starts from an empty screen. You can additionally choose items to keep synchronized afterwards, so editing one language updates the rest. Synchronization is permission aware: only editors who may edit every translation in a group can change shared data.

In the block editor, parent pages, categories, tags, and the link dialog are scoped to the language you are writing in, so you are never offered content from another language. Block themes get a Language Switcher for the Navigation block, which inherits your menu's colors, spacing, and mobile overlay.

Optional media translation gives each language its own title, alternative text, caption, and description for the same file. The file itself is never duplicated: every language points at one image on disk, so translated pages get accurate alternative text without a second upload.

This free version does not include automated translation, WooCommerce-specific data handling, translation of arbitrary custom-field values, advanced translated schema or slugs, translation of the text inside Elementor widget settings, dynamic-tag translation, forms translation, or other addon features.


== Installation ==

1. Upload the `localepress` folder to `/wp-content/plugins/`.
2. Activate LocalePress through the Plugins screen.
3. Complete the LocalePress setup screen that opens after activation.

== Changelog ==

= 1.0.0 =
* Added SEO field translation for Yoast SEO, Rank Math, and SEOPress. A new translation now opens with the source's SEO title, meta description, social titles and images, robots settings, and primary category already filled in, so a translator has something to translate from instead of a blank panel. The primary category follows the category's own translation. Once translated, the text is left alone: editing the source no longer overwrites a description somebody wrote in another language. Each plugin's own title and description templates become translatable strings as well. A canonical is never copied, because a translation has an address of its own.
* Added one sitemap per language. The index at wp-sitemap.xml stays where it is and stays the only address to submit, but it now lists a separate post and taxonomy sitemap for each language, at that language's own address, each holding only its own URLs. A post type the site does not translate stays whole, a language with nothing of a kind is left out rather than given an empty file, and languages on separate hosts are unchanged because each host already answers for itself. Settings > SEO turns it off.
* Added Elementor Theme Builder and popup translation. A translated header, footer, single, archive, 404, search, or popup template now carries the display conditions, trigger settings, and location that decide when it appears, and the rules that name a page or a category are rewritten to point at that page or category in the language the template belongs to. Each language then renders its own template: Elementor is asked, through its own filters, for the template written in the language being read, falling back to the source template for a language that has none of its own and for a translation that is not published yet.
* Added an Elementor widget, LocalePress > Language Switcher, so a header built in Elementor can carry a switcher with Elementor's own typography, color, flag, dropdown, and spacing controls. It renders through the same switcher service as the shortcode and the block rather than repeating it.
* Added a floating language switcher, a vertical strip pinned halfway down the right edge of the screen on every page and on by default, so a newly registered language is reachable before anyone has placed a switcher in a menu or a template. Every language is shown at once with its flag, the one being read filled in. Settings > Switcher turns it off, hides the flags, moves it to the left edge or to any corner, or collapses it to a dropdown. It hides itself inside Elementor and other page builder editors, where it would only sit over the editing canvas.
* Added a per-language default category, so a post saved without a category lands in the default term of its own language instead of the site-wide one. Translating Uncategorized is all it takes, and Settings > Content now offers a default term per language for sites that want a different one. Default terms of custom taxonomies follow the same rule.
* Added translation of the post and term IDs stored by themes, page builders, and widgets, so a featured page, a listed category, or an excluded post follows the language being viewed instead of returning the original language or nothing at all.
* Added a query argument URL format, so /about/?lang=de works on sites that cannot use language directories, including sites with plain permalinks.
* Fixed bundled stylesheets and scripts being served from a browser cache after they change, by versioning each asset URL against the file itself instead of the plugin version alone.
* Added an All languages view to String Translation, so one string can be filled in every language from the same row instead of one language per visit.
* Added out-of-the-box string translation for the site title, tagline, date and time patterns, and widget titles and text, so a new site has something to translate without any code. Sites can name their own options through localepress_core_string_catalog.
* Added support for the wpml-config.xml files plugins and themes already ship, so their custom post types, taxonomies, and custom fields are handled without either side writing integration code. Settings options declared under admin-texts become translatable strings and are substituted on the front end.
* Changed translatable post types and taxonomies to be opt-in. New sites start with posts, pages, categories, and tags selected, and every other public type waits to be chosen. Existing sites keep their stored policy.
* Limited the Content settings lists to post types and taxonomies registered with public => true, so internal editorial types are no longer offered.
* Added canonical compatibility with SEOPress, All in One SEO, Slim SEO, The SEO Framework, SmartCrawl, and Squirrly SEO alongside Yoast SEO and Rank Math.
* Added a core sitemap constraint that excludes content assigned to a disabled language, so no unprefixed duplicate URL is submitted.
* Added WordPress locale switching on prefixed frontend URLs, so theme and plugin strings, date formatting, text direction, and the document language attribute follow the language being viewed. Administration, login, REST, cron, and CLI requests keep the site locale.
* Added an admin bar language filter that limits post, term, and media listings to one language per user, and starts new content in it.
* Added optional browser language detection that sends a first-time visitor from the site root to the language their browser requests, remembering the choice in a cookie.
* Added a prefixed template API in includes/api.php so themes and plugins can add LocalePress support without using namespaced services.
* Removed the Debug Information table from the Advanced settings tab.
* Completed the production security, performance, compatibility, lifecycle, internationalization, and packaging audit.
* Added network-wide activation and deactivation handling with bounded multisite processing.
* Eliminated per-item translation relationship queries when rendering navigation menus.
* Hardened filtered settings persistence, JSON response headers, release packaging, and block editor compatibility.

= 0.11.0 =
* Added a five-step, resumable setup wizard for site language, second language, URL behavior, and switcher insertion.
* Added tabbed General, URL, Content, Switcher, SEO, and Advanced settings with runtime-configurable policies.
* Added validate-first JSON export/import using portable locales instead of site-specific language IDs.
* Added default-language prefix control, dangerous URL-change notices, diagnostics, and opt-in uninstall cleanup.

= 0.10.0 =
* Added a developer-controlled plain-text string registration and retrieval API without gettext replacement or source scanning.
* Added searchable, group-filtered, language-filtered string translation administration with pagination and secure page saves.
* Added indexed custom-table persistence, WordPress Object Cache integration, bulk loading, lifecycle cleanup, hooks, and tests.

= 0.9.0 =
* Added a central paginated translation-status dashboard for posts, pages, and supported public custom post types.
* Added content type, language, status, translated-title search, missing, completed, and draft filters.
* Added accessible add/edit translation actions, per-page screen options, bounded reporting queries, and bulk-action extension hooks.

= 0.8.0 =
* Added language-specific HTML lang attributes, document direction, and RTL body classes.
* Added public translation-aware hreflang sets with self references, deduplication, and x-default behavior.
* Added language-specific core canonicals plus documented Yoast SEO and Rank Math canonical adapters.
* Added noindex protection for previews and 404s while suppressing LocalePress metadata on non-indexable requests.

= 0.7.0 =
* Added optional Elementor detection with zero coupling when Elementor is inactive.
* Added independent copying for basic Elementor document JSON, page settings, document type, version, and WordPress page template.
* Added generated CSS, asset, usage, screenshot, SVG, interaction, and render-cache invalidation for translated post IDs.
* Added overwrite protection, JSON validation, workflow-aware empty documents, developer hooks, and common free-widget structure tests.

= 0.6.0 =
* Added language assignments for classic navigation menus and per-language menu selection for registered theme locations.
* Added translated post, term, and same-site custom links while preserving WordPress menu objects, walkers, and external links.
* Added configurable source content, excerpt, and featured-image reuse for new translated drafts.
* Added byte-preserving Gutenberg block-copy coverage for common blocks, Query blocks, and synced-pattern references.

= 0.5.0 =
* Added one context-aware language switcher renderer for shortcodes, blocks, templates, and classic navigation menus.
* Added name, native-name, code, list, dropdown, visibility, unavailable-translation, and optional flag-provider settings.
* Added semantic accessible markup, minimal theme-customizable CSS, and switcher extension filters.

= 0.4.0 =
* Added current-language detection and language-prefixed frontend URLs.
* Added rewrite and permalink integration for singular content, taxonomies, archives, search, pagination, previews, front pages, and blog pages.
* Added canonical default-prefix redirects, missing-translation 404 handling, conditional rewrite flushing, and frontend relationship cache priming.

= 0.3.1 =
* Made the configured default language effective immediately for unassigned posts and terms.
* Added automatic default-language persistence for newly saved content and newly created terms.
* Added one-click translation icons to content and taxonomy edit screens and list tables.

= 0.3.0 =
* Added language assignments and translation groups for categories, tags, and public custom taxonomies.
* Added secure taxonomy form controls, translated-term creation, and taxonomy list columns.
* Added duplicate-language constraints, translated parent synchronization, and safe term deletion repair.

= 0.2.0 =
* Added post, page, and public custom post type language assignments.
* Added indexed translation groups with source and translated-content relationships.
* Added editor controls, translated draft creation, and admin list columns.
* Added duplicate/conflict validation and safe trash/delete handling.

= 0.1.0 =
* Initial Phase 1 foundation and language management release.
