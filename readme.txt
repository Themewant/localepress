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

LocalePress provides secure language registration, core translation relationships, a central translation dashboard, registered-string translation, language-prefixed frontend routing, optional browser language detection, accessible language switchers, multilingual navigation menus, Gutenberg-safe manual translation workflows, two-phase copy and synchronization, block theme template part translation, Elementor page, Theme Builder, popup, and header and footer builder translation, and essential multilingual SEO metadata. It supports posts, pages, public custom post types, synced patterns, categories, tags, and public custom taxonomies.

Creating a translation always copies the source content, taxonomies, public custom fields, page template, featured image, and the other core post fields, so no editor starts from an empty screen. You can additionally choose items to keep synchronized afterwards, so editing one language updates the rest. Synchronization is permission aware: only editors who may edit every translation in a group can change shared data.

In the block editor, parent pages, categories, tags, and the link dialog are scoped to the language you are writing in, so you are never offered content from another language. Block themes get a Language Switcher for the Navigation block, which inherits your menu's colors, spacing, and mobile overlay.

Block themes get a header and footer per language. A template part is translated the way a post is — it carries a language and belongs to a translation group — and it is managed where it is edited: a Languages panel in the Site Editor sidebar names the language of the part on screen and lists the others beside it, each with its flag and one control. A language that has a version of this part opens it; a language that does not gets it started, with the original's content ready to translate, and the editor lands in it. Swapping the part swaps everything inside it at once, so each language can carry its own navigation, logo, and switcher.

The language a part belongs to is written into its name as well: the default language keeps the name the theme gave it, `header`, and every other language suffixes it, `header___bn`. That is what makes the fallback free. WordPress chooses a part by name, so the request asks for both names at once and takes the first that exists, which means a part you have not translated keeps rendering the one every other language sees without a second query ever running. Renaming, changing which language is the default, and deleting are all kept in step with it, and deleting the part every language falls back to takes its translations with it.

The Site Editor itself becomes language aware. Opening a language's template part tells the editor which language it is working in, so the pages offered to a link, the results of a search, and the terms in a panel are that language's rather than every language's at once — the same scoping the post editor already has.

The header and footer builders are covered too: the Elementor Pro Theme Builder, Elementor Header &amp; Footer Builder, the Essential Addons Theme Builder, the ElementsKit header and footer builder, the Happy Elementor Addons Theme Builder, the Royal Elementor Addons Theme Builder, and the Jeg Kit for Elementor header and footer builder all become translatable, so each language gets its own header and footer. Translating a template carries its template type and display rules across, rewriting any rule that names a specific page or term so it points at that page's translation, and a location falls back to the source language whenever this language has no published template of its own. For the Elementor Theme Builder this covers every location it has, so single posts, single pages, archives, search results, the 404 page, and popups each get a template per language on the same terms as a header. A translated Jeg Kit template is named after its language and says so on the builder's own theme builder screen, so a list holding one header per language reads as one. The Elementor Theme Builder screen does the same: every template card names the language it was written in and carries a flag per language, leading to that language's translation or offering to start one.

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