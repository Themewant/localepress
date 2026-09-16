=== LocalePress ===
Contributors: shapecode
Tags: multilingual, language, localization, translation, translate
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

The bundled scripts are plain ES5 and load without a build step. They use a few modern browser APIs where a browser offers them — `Element.closest`, `Element.matches`, `dataset`, `classList`, `requestAnimationFrame`, `String.prototype.normalize` for accent-insensitive search, and `navigator.clipboard` for the copy buttons on the Switcher screen. Every one of them is checked before use, and each screen renders, saves, and switches languages without JavaScript at all, so an older browser loses presentation rather than function.

This free version does not include automated translation, WooCommerce-specific data handling, translation of arbitrary custom-field values, advanced translated schema or slugs, translation of the text inside Elementor widget settings, dynamic-tag translation, forms translation, or other addon features.


== Installation ==

1. Upload the `localepress` folder to `/wp-content/plugins/`.
2. Activate LocalePress through the Plugins screen.
3. Complete the LocalePress setup screen that opens after activation.

== Changelog ==


= 1.0.0 =
* Initial Phase 1 foundation and language management release.