# LocalePress

LocalePress is a modular multilingual foundation for WordPress. This repository contains the free plugin and currently implements language management, post and taxonomy translation relationships, a central translation dashboard, a registered-string translation system, language-prefixed frontend routing, context-aware language switching, multilingual navigation, Gutenberg-safe manual translation workflows with two-phase copy and synchronization, optional media translation, basic Elementor document compatibility, and essential multilingual SEO metadata.

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer

The requirements are declared in the plugin header and in the overridable `LOCALEPRESS_MINIMUM_PHP_VERSION` and `LOCALEPRESS_MINIMUM_WP_VERSION` constants. Compatibility is checked during activation and normal plugin loading.

## Storage decision

Languages are stored in one non-autoloaded WordPress option named `localepress_language_registry`. Its value is a versioned registry containing:

```text
schema_version
default_language_id
languages
  <stable UUID>
    name, native_name, locale, language_code, url_slug
    is_rtl, enabled, order, created_at, updated_at
```

Language registries are small configuration datasets, so the Options API provides native caching, serialization, multisite per-site isolation, and simple backup behavior. A custom table would add schema and migration costs without a useful query benefit at this stage. A custom post type would incorrectly model languages as editable content.

Persistence sits behind `LanguageRepositoryInterface`. An addon can replace the repository through `localepress_language_repository` without changing validation, admin, or resolution code. The repository caches its normalized registry within the request, and operations that create an initial default language persist the record and default ID atomically in one option write.

Post translation relationships use two site-prefixed custom tables:

```text
<prefix>localepress_translation_groups
  group_id, source_post_id, created_at

<prefix>localepress_post_translations
  post_id, group_id, language_id, created_at, updated_at
```

Relationship data grows with content and must support indexed group lookups. The assignment table therefore has a primary key on `post_id` and a unique key on `group_id + language_id`. These database constraints guarantee that one post cannot belong to multiple groups and one group cannot contain duplicate translations for a language, including during concurrent requests. The group table records the original source post and selects a surviving member if that source is permanently deleted.

Persistence sits behind `TranslationRepositoryInterface` and can be replaced through `localepress_translation_repository`. The default repository uses request-level caches and bulk-primes assignment, source-group, and group-member records for admin and frontend result sets. It intentionally avoids persistent object caching so writes from another request cannot leave stale relationships.

Dashboard reporting is a separate optional `TranslationDashboardRepositoryInterface`. The default database implementation runs one prepared count query and one prepared source-ID query against the existing post/group indexes, then the dashboard query service bulk-primes source posts, relationships, and translated posts. Keeping reporting outside the mutation repository avoids breaking custom relationship-storage implementations. A custom repository can supply compatible reporting through `localepress_translation_dashboard_repository` or return `null` to disable the screen safely.

Registered strings and their translations use two site-prefixed custom tables:

```text
<prefix>localepress_strings
  string_id, string_group, string_key, original_string, created_at, updated_at

<prefix>localepress_string_translations
  string_id, language_id, translation, updated_at
```

Definitions must be searched, grouped, paginated, and joined to a growing language-specific dataset, so an option would require loading and rewriting the entire registry. The SHA-256 `string_id` is deterministic from the validated group and key. A unique `string_group + string_key` index and the translation table's `string_id + language_id` primary key enforce one definition and one value per language at the database boundary. Tables are installed and upgraded with WordPress's `dbDelta()` lifecycle.

Persistence is replaceable through `StringRepositoryInterface` and `localepress_string_repository`. Registrations are deduplicated in memory and flushed in one bounded definition check at shutdown; the admin editor flushes before querying. Definition and translation lookups use the WordPress Object Cache, including negative cache entries, while admin rows bulk-load one page of translations without N+1 queries. Re-registering a changed original updates the definition and preserves existing translations.

Term translation relationships use two separate site-prefixed tables:

```text
<prefix>localepress_term_translation_groups
  group_id, source_term_taxonomy_id, taxonomy, created_at

<prefix>localepress_term_translations
  term_taxonomy_id, term_id, taxonomy, group_id, language_id, created_at, updated_at
```

Term data is keyed by `term_taxonomy_id`, WordPress's stable identity for a term inside one taxonomy. The assignment table enforces unique `term_taxonomy_id`, `term_id + taxonomy`, and `group_id + language_id` values. This prevents cross-taxonomy ambiguity, conflicting group membership, and duplicate language slots at the database boundary. Persistence is replaceable through `localepress_term_translation_repository` and primes taxonomy list screens in two relationship queries.

Navigation menus remain native `nav_menu` terms and `nav_menu_item` posts. A menu's language is stored as `_localepress_language_id` term meta. Per-language choices for registered theme locations are stored in the active theme's `localepress_nav_menu_locations` theme mod, alongside but separate from WordPress's normal `nav_menu_locations` theme mod. This keeps menus compatible with core menu editing, Customizer behavior, walkers, and theme switching while allowing each theme to retain its own language matrix.

Translated-draft workflow settings are stored in the non-autoloaded `localepress_workflow_settings` option. The settings control source content/excerpt copying, Elementor element JSON when applicable, and featured-image reuse; they do not enable automatic translation or copy arbitrary post metadata.

User-facing configuration is stored in the non-autoloaded, versioned `localepress_settings` option. Its normalized sections are `url`, `content`, `switcher`, `seo`, `advanced`, and `setup`. Unknown keys are discarded, enum values are allowlisted, booleans are normalized, and site-specific menu IDs remain in their existing WordPress term meta and theme-mod storage. `PluginSettings` is the shared schema and cache boundary, available through `LocalePress\Plugin::instance()->settings()` and replaceable at the value level through documented filters.

## Architecture

```text
localepress.php                         Plugin metadata, constants, compatibility checks, hooks
src/class-autoloader.php                Namespace autoloader
src/class-plugin.php                    Service composition and module registration
src/class-assets.php                    Cache-busting versions for bundled CSS and JS
src/Contracts/                         Repository and module contracts
src/Infrastructure/                    Options and translation-table repositories
src/Language/                          Catalog, validation, lifecycle service, current-language resolver, locale switching, Accept-Language negotiation
src/Content/                           Post-type policy, relationship API, global lifecycle handling, stored-identifier translation
src/Taxonomy/                          Taxonomy policy, term relationship API, hierarchy and lifecycle handling
src/Routing/                           Current-language detection, rewrites, query mapping, URLs, canonicals, and visitor detection
src/SEO/                               Language attributes, hreflang, canonicals, provider compatibility, and core sitemap constraints
src/Navigation/                        Menu languages, theme-location mapping, and translated links
src/Switcher/                          Shared switcher model/renderer and WordPress integrations
src/Settings/                          Versioned plugin settings, copy and sync catalog, and safe transfer
src/Sync/                              Two-phase translation copy and permission-aware synchronization
src/Media/                             Shared-file media translation and attachment resolution
src/Rest/                              Language-scoped REST collections for the block editor
src/StringTranslation/                 Registered-string validation, retrieval, lifecycle handling, and the option value translator with its core catalog
src/Integrations/Elementor/            Optional Elementor document copy compatibility
src/Integrations/Wpml/                 wpml-config.xml discovery, parsing, and option string translation
src/Admin/                              Language screens, translation dashboard, editor UI, admin language filter, actions, and list columns
src/Lifecycle/                          Installation, upgrades, activation, and deactivation
blocks/language-switcher/               Dynamic Gutenberg block metadata and editor controls
blocks/navigation-language-switcher/    Navigation block switcher metadata and editor controls
assets/                                 Minimal frontend switcher CSS and admin assets
includes/                               Prefixed template functions and the public developer API
tests/                                  WordPress integration tests
```

The `Plugin` class composes shared services. `LanguageManager` is the language mutation boundary. `PostTranslationManager` and `TermTranslationManager` own assignments, groups, translated-copy creation, conflict validation, and deletion repair. Repositories own persistence. Admin modules handle WordPress UI and capability checks, while lifecycle modules run in every request context so REST, CLI, cron, and custom-code deletions cannot leave stale rows.

After a normal single-site activation, the first eligible administrator request is redirected to initial setup. Network, bulk, AJAX, cron, and CLI activation contexts do not interrupt their workflows. The short-lived redirect marker is consumed once and does not alter the language registry. When no languages exist, opening the main screen directly also displays setup.

Network activation, deactivation, and uninstall process existing multisite sites in bounded batches and always restore the active blog context. New sites install their per-site schema through the normal version check on first load. Network activation does not create per-site setup redirects.

`LocalePress > Setup Wizard` is always available and resumes from its last saved step. It selects the existing-content language, optionally adds a second catalog language, configures directory URLs, saves switcher defaults, and can insert one idempotent virtual switcher item into an existing classic menu. The catalog path uses the same language validator as normal administration and allocates a non-conflicting URL slug. Existing configured installations are marked complete during upgrade so activation does not reopen setup unexpectedly.

`LocalePress > Settings` uses tab-scoped, nonce-protected POST handlers over the versioned settings service. A custom handler is used instead of independent Settings API callbacks because General and Switcher saves coordinate language-registry invariants, term meta, theme mods, and workflow options in one validated operation. Each tab changes only its own fields.

Settings exports use stable locales instead of language UUIDs and exclude non-portable navigation menu and theme-location IDs. Import accepts JSON up to 1 MB, validates the complete schema and every referenced locale, post type, and taxonomy before mutation, and then maps locales to local language IDs. Imported languages must already be registered. URL changes produce a dedicated cache/link warning. Uninstall preserves all data by default; complete option, metadata, theme-mod, and custom-table cleanup runs only when explicitly enabled under Advanced before uninstalling.

Supported posts and terms without a stored assignment use the configured default language immediately. This is an effective fallback only and does not write while content is being read. A normal save persists the default assignment, and clicking a translation action materializes a legacy source assignment before creating the target. Explicit editor language selections always take precedence.

Post types and taxonomies become translatable by selection, not by discovery. Settings > Content offers every post type and taxonomy registered with `public => true` and an administrative UI, and a new site starts with `post`, `page`, `category`, and `post_tag` selected so the plugin is usable immediately. Everything else — including a plugin's or theme's own custom post types — stays untouched until a site owner chooses it, so registering a post type never silently adds a language control to it. An unreadable submitted policy falls back to the narrower one, so a malformed request cannot widen what is translatable.

Attachments are never listed there; media has its own switch, described under Media translation. Non-public editorial post types are not listed either, `wp_block` included: a site that wants each language to own its synced patterns adds that post type through `localepress_supported_post_types`, which is also the extension point for any other post type outside the public set. A detected WooCommerce `product` post type uses this generic core-field workflow only once selected. LocalePress Free does not copy product metadata, variations, stock, prices, or SEO metadata. Elementor metadata receives only the basic allowlisted behavior documented below.

Existing sites keep the policy they already stored. Upgrading does not narrow a site that was configured to translate every type, because that would orphan translations already created; switch the policy on the Content tab to adopt the selective default.

Categories, tags, and public custom taxonomies with an administrative UI are supported through one taxonomy policy. Hierarchical translated terms use the matching translated parent when it exists and remain at the root when it does not. Creating a missing parent translation later repairs descendant relationships. Only core term fields are copied; term metadata and WooCommerce-specific category or attribute behavior are not implemented.

Future free or Pro modules can implement `ModuleInterface` and join through `localepress_modules`. This remains the composition boundary for additional page-builder integrations, advanced SEO or Elementor behavior, and Pro-only commerce behavior. None of those later-phase features are implemented here.

## Admin language filter

An admin bar menu lists every enabled language plus **Show all languages**, and the choice is stored per user in the `localepress_admin_language` user meta key. Because the choice is remembered, ordinary admin links do not need to carry it: the `lang` query argument only announces a change. An unknown or removed slug clears the filter rather than leaving a stale one in place.

The filter is a per-user display preference that writes no site state and changes nothing another user sees, which is why the `lang` argument is accepted without a form token. It is offered to users who can `edit_posts`, never in network admin, and `localepress_enable_admin_language_filter` disables it entirely.

While one language is selected:

| Screen | Effect |
| --- | --- |
| Posts, Pages, and translatable custom post types | The list table shows only that language. |
| Media library, list and grid | Filtered when media translation is enabled, including the `query-attachments` request the media modal uses. |
| Category, tag, and translatable taxonomy term lists | The term list table shows only that language. |
| New posts and terms | The language control starts on the filtered language instead of the site default. |

Filtering reuses `LanguageQueryConstraint`, the same indexed join frontend routing and the REST API use, so a filtered listing costs one extra join rather than a second query. Queries for the default language also match content with no stored assignment, so a site that installed LocalePress after publishing still lists everything.

A post saved without an explicit language while the filter is active is assigned the filtered language, on `wp_after_insert_post` before `TranslationLifecycleModule` would assign the site default. The guards are identical, so only the chosen language differs.

Two limits are deliberate. The status links above a list table (`All`, `Published`, `Draft`) keep WordPress's unfiltered counts, because filtering them means one extra counting query per status. A taxonomy control inside the post editor follows the language of the post being edited rather than the admin filter, which the block editor integration already supplies.


## Translation dashboard

`LocalePress > Translations` presents one row per source post or translation group. Enabled languages become matrix columns: a completed public translation shows a check action, a draft or other non-public translation shows an edit action with its status in the accessible label, and an open language slot shows a nonce-protected add action. A trashed translation stops counting as one, so its language reads as an open slot again: a post its author has thrown away is not something a reader can be sent to, and leaving a completed check on it hid the add action and refused a replacement. The relationship row stays where it is, so restoring the post restores the translation with no repair step. Creating a replacement while the old one sits in the trash releases the slot to the new draft, and restoring the old post afterwards returns it unlinked but still in its own language, because releasing a slot moves the post into a group of its own rather than deleting its assignment.

The content type, target language, translation status, and search controls execute in SQL before pagination. Without a language selection, Missing means at least one enabled language is absent, Completed means every enabled language has a public post, and Draft means at least one enabled language has a draft post. Selecting a language applies the status to that language slot. Search covers both source and translated titles.

The screen uses an isolated `WP_List_Table` adapter for native pagination, screen options, filters, row actions, and table markup. LocalePress Free does not define a mutating bulk operation, but addons can register an action through the documented bulk filters and receive nonce-verified, capability-filtered source IDs. Reporting query arguments and rows have separate filters so future modules do not need to replace the screen.

## String translation

Developers register stable, LocalePress-controlled plain-text strings on `init` or later. Registration does not hook or replace WordPress gettext, scan source files, or read and write PO/MO catalogs.

```php
add_action(
	'init',
	static function () {
		localepress_register_string( 'theme', 'footer_notice', 'All rights reserved.' );
	}
);

$notice = localepress_translate_string( 'theme', 'footer_notice', 'All rights reserved.' );
echo esc_html( $notice );
```

`localepress_register_string()` returns a deterministic string ID or `WP_Error`. `localepress_translate_string()` accepts an optional fourth language-ID argument, otherwise it uses LocalePress's current enabled language. A missing value falls back to the registered original. Passing a non-empty fallback also queues that definition for registration. Values are plain text and the caller must always apply output-context escaping.

`LocalePress > String Translation` provides native search, group and enabled-language filters, pagination, screen options, immutable originals, and editable values. Saving an empty translation deletes that value and restores the original fallback. Each submitted page is fully validated before any row changes.

The language filter offers one language at a time, which keeps the table narrow on a site with many languages, and **All languages**, which puts a field for every enabled language in the same row so a string can be finished in one pass while its meaning is fresh. Both views load their values in one query, and both post the same `translations[language][string]` shape, so one save path serves them.

The all-languages view caps its own page size. Its form posts one field per string per language, and PHP silently discards everything past `max_input_vars`, so a page that would exceed that limit is shortened instead. Fewer rows are visible and recoverable; a save that drops half its translations is neither.

### What is translatable without any code

A site owner should not have to write PHP to translate their own site title, so LocalePress names the options every site has and registers their values itself. Nothing needs configuring; the strings appear the first time something reads the option.

| Group | What it covers |
| --- | --- |
| `WordPress` | `blogname`, `blogdescription`, `date_format`, `time_format`. Date and time patterns are text too: a language often writes the day before the month. |
| `Widgets` | Every widget instance's `title`, `text`, and `content`, through a `widget_*` wildcard. One rule covers the widget types a site has today and the ones it adds later; a widget's post counts, menu IDs, and feed URLs are not named and stay untouched. |
| `plugins/…`, `themes/…` | Whatever a plugin or theme declared under `admin-texts` in its own [wpml-config.xml](#configuration-files-wpml-configxml). |

`localepress_core_string_catalog` adds to the first two, so a site can name one more option without writing a module:

```php
add_filter( 'localepress_core_string_catalog', function ( array $catalog ) {
    $catalog['Acme'] = array(
        'acme_settings' => array(
            'header_text' => true,
            'footer'      => array( 'copyright' => true ),
        ),
    );
    return $catalog;
} );
```

Two limits apply to every option value, whichever source named it. Registered strings are plain text, so a value carrying markup is passed through untouched rather than flattened — a text widget holding a link keeps its link and is not offered for translation. And substitution happens on the front end only, so an administration form always shows the value it will save.

Classic widgets are covered by the wildcard because WordPress stores their instances in `widget_{$id_base}`. Block widgets keep their text inside block markup in `widget_block`, which is left alone for the reason above.

## Frontend routing

A language reaches a URL in one of four ways, chosen under Settings → URL: a directory, a subdomain, a domain of its own, or a `?lang=` query argument. The first three are described below and in the host resolver; the query argument is the only one that adds nothing to the path, which makes it the only mode a site without pretty permalinks can use, and the fallback for a site whose paths are already owned by something else:

```text
/about/?lang=de         -> German page, on any permalink structure
/?p=12&lang=de          -> the same page on a plain-permalink site
/about/                 -> 301 /about/?lang=en when the default prefix is enabled
```

The argument replaces any language a URL already named, in either form, so a site that changes its mode keeps producing exactly one language per URL. `localepress_public_query_var` renames it for a site whose theme or another plugin already owns `lang`; the internal `localepress_lang` variable is accepted alongside it in every mode.

In directory mode LocalePress requires a non-empty WordPress permalink structure and uses one directory for each non-default language. The default language can either keep its directory or use the unprefixed site root:

```text
/                       -> 301 /en/ when the default prefix is enabled
/                       -> English home when the default prefix is hidden
/en/                    -> English home or front-page translation
/de/                    -> German home or front-page translation
/en/about/              -> English page
/de/about/              -> German member of the same post translation group
/de/category/reisen/    -> German category term
/de/search/route/       -> German search results
/de/page/2/             -> German pagination
```

LocalePress prepends a single `(en|de|...)` language capture to WordPress's generated public rewrite rules and shifts existing match indexes. Core non-prefixed rules remain available. With a prefixed default language, valid legacy URLs resolve before one permanent redirect to the default prefix. With a hidden default prefix, old default-prefixed requests resolve before one permanent redirect to the equivalent root URL; non-default prefixes remain unchanged. WordPress administration, login, REST, sitemaps, robots, favicon, content, and include paths are not prefixed. Unknown routes remain 404 responses and are not redirected.

Posts, pages, and public CPT translations use the original translation-group source path. For example, a German page stored with slug `ueber` still uses `/de/about/` when its source page uses `about`; translated post slugs are outside Phase 4. Term translations use their own WordPress term slug and translated hierarchy. A missing singular or term translation resolves to a 404 instead of silently serving another language.

A static front page and a posts page are changed only for the active request through WordPress's `option_page_on_front` and `option_page_for_posts` filters, preserving `is_front_page()`, `is_home()`, and the native template hierarchy without changing the saved settings. Both options return their stored value while WordPress writes them or resets a trashed post's front-page settings. Both keep one shared route: `/de/blog/` resolves the translated blog page, so themes read its translated title and content while the archive queries posts in the requested language, and a translation's own slug redirects back to the shared route. An untranslated front page or posts page falls back to the source page's route rather than a 404, because the switcher sends every untranslated language to its root. CPT, author, date, taxonomy, search, feed, embed, and pagination rules retain their native WordPress shape behind the language prefix. Preview URLs retain preview query parameters and use the previewed post's assigned language.

The main frontend post query and block-driven post queries receive the indexed language assignment join. Default-language queries include older unassigned content; other languages require an explicit assignment. Result-set relationship caches are primed in bulk to avoid permalink N+1 queries.

Block queries are recognized in two ways. Every block in the Query Loop family — post template, pagination, total, and the no-results fallback — builds its query vars through WordPress's `query_loop_block_query_vars` filter, so a single marker keeps a paginated loop and its counters consistent. Blocks that instantiate `WP_Query` directly, such as Latest Posts, are constrained while they render; `localepress_post_query_block_names` registers additional block names. A Query Loop set to inherit the template query keeps using the already-constrained main query.

### Stored identifiers

A theme, page builder, or widget stores the identifier of the thing an editor picked: a featured page, a category to list, posts to exclude. That identifier names one language's record, so read back on a translated page it points at the wrong language — and where the language constraint also applies, at nothing at all, because a `post__in` naming English posts returns an empty German loop.

`QueryIdTranslationModule` rewrites those identifiers to the language being viewed, so code that knows nothing about LocalePress produces the right language anyway. It covers `p`, `page_id`, `attachment_id`, `post_parent`, `post__in`, `post__not_in`, `post_parent__in`, `post_parent__not_in`, `cat` including its leading-minus exclusions, `tag_id`, the `category__*` and `tag__*` lists, `tax_query` clauses matching on `term_id` including nested ones, and the `include` and `exclude` arguments of a term query.

Only identifiers are translated. A slug or a name is left exactly as it was asked for, because the router already maps the routes a visitor can request and a query naming a slug is naming one specific record. Three rules decide every rewrite: the post type or taxonomy must be translatable, the object must have a language that is not already the right one, and a translation must exist. An untranslated identifier keeps its stored value, so a query returns what it returned before the module existed rather than nothing.

A query that names a language through `localepress_lang` is rewritten to that language instead of the request's — the same opt-in the language constraint honors. Previews are never rewritten, so an editor sees the post they opened. `localepress_skip_id_translation` opts one query out, `localepress_translate_query_ids` filters the decision, and administration, AJAX, cron, REST, and CLI requests are untouched.

A block query is constrained only when every post type it targets is translatable. Navigation menus, templates, template parts, `any` queries, and other non-public types are left untouched, because they carry no language assignment and an unconditional join would return nothing. Queries outside block rendering keep their original clauses, so widgets, related-post loops, and other secondary queries behave as before.

Rewrite rules flush softly only when the routing schema, enabled language slugs, or permalink structure changes. Toggling the default prefix changes generated URLs and redirects but does not flush because the rewrite rule set is unchanged. Activation and deactivation invalidate the stored signature. LocalePress does not register competing global routing filters while Polylang or WPML is active; `localepress_enable_frontend_routing` can override this decision for a controlled integration.

## Site language

Translating post content is not enough for a page to read as one language. Theme and plugin strings, date and number formatting, text direction, and the document language WordPress prints all derive from `get_locale()`, so a request under a language prefix has to answer with that language's locale.

This is not a setting. Running WordPress in the language being viewed is what makes the rest of the engine coherent, and a site with it switched off reports one language in its markup while rendering another — so it is always on. A theme that genuinely depends on a single site locale can still opt out per request through `localepress_should_switch_locale`, which is the audience such an exception actually has.

`LocaleModule` filters `locale`. It registers while LocalePress boots on `plugins_loaded`, which is before WordPress loads its default text domain and well before themes and plugins load theirs, so every normal text domain resolves in the request language.

The switch is bound to an **explicit language prefix in the request URL** rather than to a general current-language lookup. That single rule gives most of the safety for free: administration, login, REST, cron, and CLI requests carry no prefix, so they keep the site locale without depending on a guard running at the right moment. `is_admin()`, `wp_doing_cron()`, `WP_CLI`, and `REST_REQUEST` are still checked as defense in depth, and the whole module stands down while another multilingual plugin owns routing.

The stored locale is validated against the same pattern the language validator enforces before it reaches WordPress, so a malformed registry value falls back to the site locale instead of reaching translation file paths. Resolution runs once per request and is cached, because `get_locale()` is called many times. A reentrancy guard answers any nested `get_locale()` — an extension filtering `localepress_registered_languages` or `home_url`, for example — with the unfiltered locale rather than recursing.

Two filters adjust the result: `localepress_should_switch_locale` allows or blocks the switch for one request, and `localepress_request_locale` replaces the resolved locale, with an empty string restoring the one WordPress determined.

This is what makes the document language attribute robust rather than incidental. `SeoMetadata::filter_language_attributes()` still rewrites `lang` and `dir` from the request language, but with the locale switched, WordPress's own `language_attributes()` and `get_bloginfo( 'language' )` are already correct, so a theme that prints the language attribute itself stays correct too.

Turn the setting off only when a theme genuinely depends on a single site locale for its own strings.

## Visitor language detection

One optional setting under **LocalePress > Settings > URL** decides which language an undecided visitor lands on. It is off by default because it changes what the site root returns.

| Setting | Default | Behavior |
| --- | --- | --- |
| Send a first-time visitor to the language their browser asks for | Off | Negotiates `Accept-Language` and redirects the site root once. |

Detection runs on exactly one kind of request: a plain `GET` for the **front page** with **no language prefix in the URL**, outside admin, AJAX, cron, REST, CLI, robots, feed, embed, preview, and 404 contexts, with an empty `$_POST`. Every prefixed URL is therefore authoritative — a shared link, a bookmark, a search result, and a switcher click all keep the language they name, and no interior page is ever redirected by detection.

The preferred language is resolved in a fixed order:

1. **The cookie**, when the visitor already browsed the site and the remembered language is still enabled.
2. **Nothing**, when the request carries an internal referrer. Following a theme's home link is deliberate navigation, so it must not bounce the visitor out of the language they were reading.
3. **The `Accept-Language` header**, negotiated against enabled languages.
4. Otherwise LocalePress does not act, and normal routing sends the request to the default language.

`BrowserLanguageDetector` parses the header as RFC 7231 section 5.3.5 describes. Comma-separated ranges are read with their optional `q` weight, wildcards and `q=0` ranges are discarded, malformed ranges are ignored, and at most 20 ranges are kept so a hostile header stays cheap. Ranges are compared in descending weight, and equal weights keep the order the browser sent.

Each range is tried against three progressively looser comparisons before the next range is considered, so a lower-weighted exact match never beats a higher-weighted approximate one: the full locale (`bn-BD` matches `bn_BD`), then the language code or URL slug, then the primary subtag (`pt-PT` matches a `pt_BR` language). Underscores, casing, and surrounding whitespace are normalized on both sides.

The redirect is a `302` carrying `Vary: Accept-Language`, and it preserves the request's query string. The cookie is written before the redirect, so the negotiation happens once per visitor rather than on every visit.

**Caching.** A full page cache that stores the site home page can serve one visitor's detected language to every later visitor. Exclude the home page from caching, make the cache vary on `Accept-Language`, or return false from `localepress_should_detect_language` while a cache is active. The settings screen shows this warning whenever detection is enabled.

The cookie is written only while browser detection is on, because it exists to answer detection without reading the header again; nothing reads it otherwise, so nothing is stored. It holds only a language slug, is readable by JavaScript so cache-aware front ends can act on it, and is sent with `SameSite=Lax` and the `secure` flag on HTTPS. `localepress_language_cookie_lifetime` changes its lifetime, and returning zero makes it a session cookie.

### Browser translation

A browser's own translation prompt is browser UI, not page content. No site can open it, read the language chosen in it, or be notified that it ran; there is no such API. What the prompt reacts to is the document language LocalePress already writes, so `<html lang="bn-BD">` is the only lever over whether a visitor is offered a machine translation at all. A page still declaring the site language while showing translated content is the usual reason the prompt behaves unexpectedly.

## Multilingual navigation

LocalePress > Settings lists every native navigation menu and registered theme location. Assign each menu one language, then select a menu for each location/language pair. When a theme calls `wp_nav_menu()` with a configured `theme_location`, LocalePress supplies the current language's menu through the normal `menu` argument. Explicit `menu` arguments remain untouched by default, and an unconfigured language/location falls back to WordPress's normal location selection.

Before a menu walker renders, post and taxonomy items resolve through existing translation groups. Same-site custom URLs receive the current prefix, external URLs and fragment links remain unchanged, and the LocalePress language-switcher item remains available. An unavailable object translation and its descendants are hidden by default; `localepress_menu_missing_translation_behavior` can choose `home`, `current`, or `preserve` instead. Translated current-item and ancestor classes are repaired after link resolution.

Only classic/native `nav_menu` menus are assigned in this phase. LocalePress does not replace `wp_navigation` entities or alter Navigation block storage.

Block themes build their header menu from `core/navigation`, which styles only its own child blocks, so the standalone switcher block cannot sit inside one. `localepress/navigation-language-switcher` fills that gap: it declares `core/navigation` as its parent and renders each language through `core/navigation-link`, or through one `core/navigation-submenu` in dropdown mode. The switcher therefore inherits the menu's spacing, colors, typography, and responsive overlay instead of reimplementing them.

Flags need one extra step. WordPress escapes the label passed to a navigation link, so an image cannot travel through it as markup. The label is rendered as a plain token, which survives escaping unchanged, and the token is replaced with the flag and the escaped label once the surrounding link markup exists.

In the editor the block previews the real language labels laid out as menu items rather than server-rendering itself, because the navigation editor would nest the rendered list inside its own.

## Gutenberg workflow

LocalePress copies `post_content` exactly as WordPress stores it. It never parses and re-serializes blocks, so block comments, JSON attributes, nested blocks, inner HTML, media IDs, and Query block markup are preserved without text translation. This applies to Heading, Paragraph, Image, Gallery, Buttons, Columns, Cover, List, Table, Query-related, and other core blocks. On the frontend, Query Loop and Latest Posts blocks list only the requested language; the routing section describes how those block queries are recognized and constrained.

A new translation always starts from its source: the title, content and excerpt, featured image, page template, taxonomies, and public custom fields are copied for the editor to translate in place. The copy and synchronization section describes exactly what is carried over.

Synced patterns are stored as `wp_block` posts, so LocalePress treats that post type as translatable. A reference such as `<!-- wp:block {"ref":123} /-->` still points at one entity, which means an untranslated pattern is shared by every language and editing it changes all of them. Translating the pattern itself gives each language its own copy; the reference in a translated post then has to point at that translation, either by re-inserting the pattern or by detaching it.

## Default category per language

WordPress keeps one default category for the whole site, so a post saved without a category lands in the same term whatever language it was written in. LocalePress answers that option with the term that belongs to the language the post is being saved in: an English post keeps Uncategorized, a French post gets the French one. The default term of a translatable custom taxonomy that registers one is treated the same way.

Nothing has to be configured. The term translation the site already created is used as that language's default, so translating Uncategorized once is enough. **LocalePress > Settings > Content > Default terms** is there for the sites that want something else: one chooser per taxonomy and language, listing only the terms of that language, so French can default to *Actualités* rather than to a translation of Uncategorized. A language left on *Translation of the site default* keeps the automatic behavior, and a chosen term that is later deleted degrades to the stored default rather than to nothing. Term IDs name rows in one database, so these choices stay out of a settings export and an import leaves the ones this site made alone.

Two paths are covered because the editors differ. The classic editor, translated copies, and front-end insertions name their language before the term is applied, so the option itself is filtered. The block editor assigns a new post's language in the meta box request that follows its REST save, so a post left holding exactly the site-wide default term is moved into its own language once that language is known. A post carrying any other category made an editorial choice and is never rewritten.

Very large taxonomies are not listed in full: each chooser offers the first 500 terms by name, and `localepress_default_term_id` covers the rest. That filter also replaces a resolved term outright, `localepress_default_term_language_id` selects the language a default is resolved for, and `localepress_realign_default_term` turns off the move for one post.

## Copy and synchronization

Translated content is kept aligned in two phases.

**Copying** runs once, while a translation is being created, and is not configurable. An editor should never open a new translation and find an empty screen, so the source content and excerpt, taxonomies, public custom fields, page template, featured image, sticky state, post format, page parent, page order, and comment and ping status are always copied.

**Synchronization** is optional and runs afterwards: saving any post in a translation group applies the enabled items to every other language in that group. Every item is disabled until a site opts in, and the choices live in LocalePress > Settings > Synchronization: Taxonomies, Custom fields, Comment status, Ping status, Sticky status, Published date, Post format, Page parent, Page template, Page order, and Featured image.

The published date is the one item that governs both phases. A translation is normally created now and therefore carries today's date; a site that keeps dates aligned across languages wants the source date from the start, so enabling its synchronization also makes new translations inherit it.

`SyncCatalog` is the single source of truth for the synchronization phase. One entry produces the stored option key, the settings-screen row, and the engine's behavior, and `localepress_sync_catalog` lets an addon describe its own item and receive the same treatment.

Two copy overrides exist without a settings screen, because they exist for code rather than for editors: `copy_content` and `copy_featured_image`, passed to `create_translation()` or set through `localepress_translation_copy_options`. Both default to enabled. The Elementor integration reads `copy_content` to open a translated document with an empty canvas.

Custom fields cover **public** meta keys only. Protected keys — anything `is_protected_meta()` recognizes, which includes builder payloads and WordPress internals — stay with their own post, because they usually describe one specific post rather than shared editorial data. The page template and, during synchronization, the featured image are the documented exceptions and are added back by key. `localepress_copy_post_meta_keys` adjusts the resolved list, and `localepress_translate_post_meta_value` rewrites one value before it is written. A short blocklist of keys that describe a single post's editing or trash state (`_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_old_date`, the `_wp_trash_meta_*` pair, `_pingme`, `_encloseme`) is enforced after those filters, so a permissive filter cannot corrupt the target.

Keys present on either post are considered, so clearing a field on one post also clears it on the others rather than leaving a stale value behind. Multi-value fields keep every value and their order.

Terms of translatable taxonomies are mapped to their own translations, so a German post receives the German category. A term with no translation in the target language is dropped rather than carried over, because an assignment naming another language's term gives a translation terms its own language cannot list, and the editor removes them on the next save anyway. `localepress_mapped_term_id` keeps the source term instead. Taxonomies outside the translation policy are copied as stored. Post format is treated as its own item rather than as a taxonomy.

Page parent resolves to the parent's translation when one exists and otherwise reuses the source parent, so a translated draft is never left as a stray top-level page.

Synchronization is permission aware. It writes to posts the editor never opened, so it only runs when that editor could have edited each translation in the group directly. An editor without that access is also blocked from changing a synchronized custom field on the post they *can* edit: the write is refused rather than silently applying to one language only, which would leave the group inconsistent. `localepress_current_user_can_synchronize` adjusts that decision.

The engine never re-enters itself. Writes it performs are marked, so the metadata guard, the save hook, and the field update it triggers on a target post do not start another synchronization pass.

## Block editor requests

The block editor loads parent pages, categories, tags, and link search results over the REST API. Those collections have no language of their own, so an editor working in German would otherwise be offered English pages to link to and English categories to assign.

A small `apiFetch` middleware appends the language currently selected in the editor to the requests that can be filtered, and `RestLanguageModule` applies it on the server. A brand new post has no assignment yet, so the middleware falls back to the default language rather than sending nothing.

Only collections the module marked are constrained. `rest_{$post_type}_query` and `rest_{$taxonomy}_query` are hooked for translatable types, plus `rest_post_search_query`, which is what the editor's link dialog actually queries. REST requests from other clients keep their existing behavior, and single-item routes are never touched: fetching one known post must not depend on the language being edited.

Preloaded paths are handled too. The editor serves its first responses from data embedded in the page, so `block_editor_rest_api_preload_paths` receives the same language; a preloaded path without it would hand the editor an unfiltered list before the middleware ever runs. The rebuilt path sorts its query arguments, because the editor's preloading middleware matches paths by their exact query string.

`localepress_language_rest_routes` adjusts which routes are tagged, and `localepress_filter_rest_query_by_language` bypasses the constraint for one collection.

Both the routing module and this one build the same SQL through `LanguageQueryConstraint`, so posts and terms are constrained identically wherever the join is applied. Default-language collections include content with no stored assignment, matching frontend behavior.

## Media translation

Media translation is off by default and enabled under LocalePress > Settings > Content. It exists so the text that travels with a file — title, alternative text, caption, and description — can differ per language, which is what search engines and screen readers actually read.

**The file is never duplicated.** A media translation is a second `attachment` post that points at the source's stored path, generated sizes, and GUID:

```
                hotel-room.jpg
                 (one file)
                ↙          ↘
        English record   Bengali record
        Title, Alt,      Title, Alt,
        Caption, Desc    Caption, Desc
```

`MediaTranslationManager` inserts the translation with `wp_insert_attachment()` and no file argument, then applies the source's `_wp_attached_file`, `_wp_attachment_metadata`, and alternative text verbatim. Nothing is uploaded, resized, or written to disk. The alternative text arrives as a starting point for the translator, exactly like the copied post title.

Attachments never appear in the translatable post types list. One switch governs media, so a site cannot end up with two controls disagreeing. When it is on, `attachment` joins the supported post types, which gives media the Language and Translations columns in the media library, rows in the translation dashboard, and the same nonce-protected add-translation action every other post type uses. Attachments are not hierarchical and register no query variable, so frontend routing treats them exactly as before.

A translated post takes the featured image's own translation when one exists, through `localepress_translation_featured_image_id` during creation and `localepress_translate_post_meta_value` during synchronization. An attachment with no translation in the target language keeps its own identifier, so a partially translated library never breaks an image.

Three keys describe where the file lives rather than what it says — `_wp_attached_file`, `_wp_attachment_metadata`, and `_wp_attachment_backup_sizes` — so every record of one file receives them, independently of which editorial items a site chose to synchronize. Editing or cropping an image in one language therefore does not leave the other languages describing sizes that no longer exist.

Deleting one language's record must not remove the shared file. `wp_delete_post()` hands attachments to `wp_delete_attachment()` before `before_delete_post` fires, so media carries its own deletion handling: the relationship is cleared on `delete_attachment`, and when other languages remain, the attachment's file, original image, generated sizes, and backup sizes are collected and skipped through `wp_delete_file`. Each protected path is consumed once, so the protection ends with the deletion that requested it and never touches unrelated file operations. Deleting the last remaining record removes the file normally.

Media is translated on demand rather than duplicated into every language at upload time, and LocalePress does not filter the media library by language, so one file shows one row per translated language.

## Elementor compatibility

`ElementorModule` listens only to LocalePress's translated-draft event. `ElementorCompatibility` first requires Elementor's official `elementor/loaded` lifecycle, version constant, and loaded core class; when Elementor is inactive, the module performs no metadata reads or writes and does not autoload Elementor classes.

For an Elementor source document, LocalePress copies `_elementor_data`, `_elementor_edit_mode`, `_elementor_template_type`, `_elementor_page_settings`, `_elementor_version`, and `_wp_page_template` into independent target post-meta rows. This preserves container, legacy section/column, Heading, Text Editor, Image, Button, Icon, and other opaque free-widget structures without translating widget text. When the `copy_content` override is disabled, the target receives an empty `[]` Elementor element collection while retaining its editable document mode, type, page settings, and WordPress page template.

Element IDs inside `_elementor_data` are document-local and remain unchanged so nested elements, controls, CSS selectors, and internal widget references stay coherent. Elementor's generated state is not copied: post CSS, page assets, controls usage, element render cache, screenshots, inline SVG cache, markdown cache, and interaction cache are cleared on the target. Elementor then rebuilds post-scoped data for the translated post ID on editor save or frontend render. Existing target Elementor data is never overwritten, copied JSON must decode to an array, and failed partial writes restore the target's prior metadata.

The integration does not interpret or translate widget settings. Elementor Pro dynamic tags, Theme Builder conditions, forms, custom CSS behavior, and advanced template relationships are outside this Free integration. Their opaque data may remain inside a copied basic document, but LocalePress does not synchronize, translate, or provide dedicated behavior for it.

## Configuration files (wpml-config.xml)

`wpml-config.xml` is the file plugins and themes already ship to describe what a multilingual plugin should do with their content. LocalePress reads it, so a plugin that shipped one years ago is supported here without a line of code on either side, and an author who adds one gets LocalePress support along with everything else that reads the format.

Files are read from active plugins, the active theme and its parent, must-use plugins, and `wp-content/localepress/wpml-config.xml`. The plugins directory is never scanned: active plugins are already listed in the `active_plugins` option, so discovery costs one readability check per active plugin and stays flat as a site grows. Parsed rules are cached in the object cache against the modification times of the files they came from, so shipping an updated file, activating a plugin, or switching a theme invalidates them without anyone clearing a cache.

The site's own file in `wp-content/localepress/` is read last and survives plugin updates. It is the place to declare rules for a plugin that ships none. `LOCALEPRESS_LOCAL_DIR` moves that directory, `define( 'LOCALEPRESS_WPML_CONFIG', false )` turns the whole layer off, and `localepress_wpml_config_files` adds or removes individual files.

### What each declaration does

| Declaration | Effect |
| --- | --- |
| `<custom-type translate="1">` | Offers the post type on Settings > Content, including the non-public types builders use for headers and templates. |
| `<custom-type translate="0">` | Removes the post type entirely; it can no longer be selected. |
| `<taxonomy translate="…">` | The same, for taxonomies. |
| `<custom-field action="copy">` | Keeps the field aligned with the source in both phases. |
| `<custom-field action="copy-once">`<br>`<custom-field action="translate">` | Seeds a new translation with the source value and then leaves the field alone, so an editor's work is never overwritten by a later save of the source. |
| `<custom-field action="ignore">`, or no action | Never travels, including fields LocalePress would otherwise have copied on its own. |
| `<custom-term-field>` | Parsed and exposed; term metadata has no synchronization phase in Free yet. |
| `<admin-texts><key name="…">` | Registers the option's values as translatable strings and substitutes them on the front end. Nested `<key>` elements walk into array values, and a `*` in a name matches any characters, so `theme_mods_*` covers every theme a site has. |
| `<gutenberg-blocks>` | Parsed and exposed through `localepress_wpml_config_rules`; there is no block string extractor in Free yet. |
| `<custom-fields-texts>` | The same. |

Two limits are deliberate. A declaration decides what a site *may* translate, never what it *does*: `translate="1"` puts a post type on the Content tab but does not tick its box, because which content a site translates stays the site owner's decision. And a declaration cannot start ongoing synchronization for a site that turned custom fields off under Settings > Sync; it only decides which fields travel once that phase runs. Removals apply in both phases, because excluding a field an author called untranslatable is always safe.

### Translated options

An option declared under `admin-texts` arrives already translated through `get_option()`, so the plugin that owns it renders translated output without knowing LocalePress exists. Its strings appear under LocalePress > String Translation grouped by where they came from, such as `plugins/woocommerce` or `themes/astra`.

Originals are registered wherever the option is read, but substitution happens on the front end only. An administrator editing that plugin's own settings form sees the stored value, never a translation they would then save over the original. `localepress_translate_option` opts a specific request back in — an admin-ajax handler that renders front-end output, for example. Values inside objects are left alone; only arrays and scalars are walked, because replacing a value inside a shared object would leak the translation into every later read of it.

### Declaring rules for a plugin that ships none

```xml
<!-- wp-content/localepress/wpml-config.xml -->
<wpml-config>
    <custom-fields>
        <custom-field action="copy">_acme_layout</custom-field>
        <custom-field action="translate">_acme_subtitle</custom-field>
    </custom-fields>
    <admin-texts>
        <key name="acme_settings">
            <key name="header_text" />
        </key>
    </admin-texts>
</wpml-config>
```

## Integrating a page builder or theme

A builder that ships a `wpml-config.xml` needs none of what follows; the file already declares it. The filters below are the equivalent in code, for a builder that ships no file or a site that wants to go further than one.

Header, footer, and layout builders keep their templates in their own post type and pick one at render time with their own query. LocalePress cannot recognize either on its own, so it exposes the pieces an integration needs. Nothing here is Elementor-specific; the same five steps apply to any builder, and a theme can do all of it from `functions.php`.

### 1. Make the builder's post type translatable

Builder templates are usually registered as non-public, because nothing should reach them by URL. Declare the post type eligible, then add it to the supported list:

```php
add_filter( 'localepress_non_public_post_types', function ( array $types ) {
    $types[] = 'elementor-hf';
    return $types;
} );

add_filter( 'localepress_supported_post_types', function ( array $types ) {
    $types[] = 'elementor-hf';
    return $types;
} );
```

Both filters are required. The first makes a non-public type eligible; the second turns it on. A post type still has to register an administrative UI, so the language column and translation controls have somewhere to appear.

### 2. Carry the builder's own metadata into new translations

```php
add_filter(
    'localepress_copy_post_meta_keys',
    function ( array $keys, $sync, $source_id, $target_id, $language_id ) {
        if ( 'elementor-hf' === get_post_type( $source_id ) ) {
            $keys[] = 'ehf_template_type';
        }
        return $keys;
    },
    10,
    5
);
```

`$sync` is `true` during ongoing synchronization and `false` for the one-time copy made when a translation is created, so an integration can copy a key once without keeping it locked to the source afterwards.

### 3. Make the builder's own queries language-aware

A builder decides which header applies by running its own query, which is neither the main query nor a block query, so LocalePress leaves it alone. Pass the language as a query argument to opt in:

```php
$templates = new WP_Query( array(
    'post_type'        => 'elementor-hf',
    'localepress_lang' => 'current',
) );
```

`localepress_lang` accepts `'current'` for the request language, or a language slug, code, or ID to pin one. Unlike the automatic paths this is honored in the admin and the REST API too, because it was asked for rather than inferred. A post type that is not translatable is left unfiltered rather than returning nothing.

To opt out of filtering where LocalePress would otherwise apply it, pass `'localepress_skip_language_filter' => true`.

### 4. Resolve a template ID that was already chosen

When the builder's query cannot be reached, translate the ID it settled on:

```php
$header_id = localepress_object_id( $header_id, 'elementor_library' );
```

`localepress_object_id()` exists for exactly this line. It takes a post type or a taxonomy name, returns the object's own ID when it is already in the requested language, and falls back to the original ID when no translation exists, so it is safe to call unconditionally on every request.

Most builders already carry a branch of this shape for other multilingual plugins. LocalePress slots in beside them without changing the structure:

```php
if ( function_exists( 'localepress_object_id' ) ) {
    $header_id = localepress_object_id( $header_id, 'elementor_library' );
} elseif ( function_exists( 'icl_object_id' ) ) {
    $header_id = icl_object_id( $header_id, 'elementor_library', true );
} elseif ( function_exists( 'pll_get_post' ) ) {
    $header_id = pll_get_post( $header_id );
}
```

The `function_exists()` guard is what keeps the builder working when LocalePress is not installed; every function in this API is defined as soon as the plugin file loads, so the guard is answerable from `plugins_loaded` onward.

Pass `false` as the third argument to get `null` instead of the original ID, and a language reference as the fourth to pin a language rather than following the request. To distinguish a missing translation from an untranslated original, use `localepress_get_post()` or `localepress_get_term()` directly: those report `0`.

### 5. React when a translation is created

```php
add_action(
    'localepress_translation_created',
    function ( $new_post_id, $source_id, $language, $group_id, $copy_options ) {
        // Rebuild builder caches for the new post, register conditions, and so on.
    },
    10,
    5
);
```

`$language` is the full target language record and `$copy_options` reports which copy behavior actually ran, so an integration can tell a populated translation from an intentionally empty one.

### Where the request language comes from

Inside any of the above, `localepress_current_language()` returns the slug of the language being rendered, and `localepress_get_language()` returns the full record. Both are safe to call once WordPress has parsed the request.

## Multilingual SEO

LocalePress derives the frontend `<html lang>` and `dir` attributes from the language prefix and aligns WordPress's `rtl` body class with the language record. The WordPress administration locale is never changed; see [Site language](#site-language) for the frontend locale switch that keeps `get_bloginfo( 'language' )` and theme strings consistent with these attributes.

When enabled in SEO settings, indexable HTML requests receive one deduplicated hreflang set. Singular posts, pages, public CPTs, and public taxonomy archives include only existing publicly viewable translations; drafts, private posts, trashed objects, disabled languages, and missing translations are omitted. The current URL is included when it is public, and optional `x-default` points to the default language's equivalent URL when that alternate exists. Shared home, post type, author, and date archives expose equivalent routes for every enabled language and retain pagination.

WordPress core continues to own singular canonical output through `get_canonical_url`. LocalePress emits a canonical only for indexable non-singular contexts that core does not cover. Empty and external provider canonicals are preserved. Search, feed, embed, preview, trackback, and 404 requests receive no LocalePress hreflang or canonical links; WordPress robots output is reinforced with `noindex,follow` for previews and 404s.

### SEO plugin compatibility

When another SEO plugin owns canonical output, LocalePress stops emitting its own so a page never carries two canonical tags, and instead filters that plugin's canonical hook to keep the current language prefix. Yoast SEO, Rank Math, SEOPress, All in One SEO, Slim SEO, The SEO Framework, SmartCrawl, and Squirrly SEO are recognized.

Detection reads loaded constants only, and the canonical hooks are registered unconditionally, because adding a filter for a hook that never fires costs nothing while missing one would leave an unprefixed canonical. No third-party class is loaded or called. `localepress_canonical_provider_constants` and `localepress_provider_canonical_filters` extend either list for a plugin not covered here, and `localepress_has_canonical_provider` overrides the decision outright.

### Sitemaps

The core sitemap at `wp-sitemap.xml` is a reserved route: it is never prefixed, and every LocalePress redirect stands down for it, along with `robots.txt` and the favicon.

Its entries are already language-correct without further work. Permalink filters resolve each post and term against **its own** assigned language rather than the request language, so a translation is listed under its own prefixed route beside its source. Provider queries are not main queries, so the request-language constraint never applies to them and all languages are listed.

`SitemapModule` closes the one case that arrangement cannot handle. Content assigned to a language that is no longer enabled has no route of its own, so its permalink falls back to the unprefixed URL, which belongs to the default language. Listing it would submit one address twice under two different pieces of content. Both providers are therefore constrained at query level — the only point at which core allows an entry to be dropped, since `wp_sitemaps_posts_entry` cannot remove one. Content with no assignment at all is kept, which matches how the rest of the engine treats pre-LocalePress content. `localepress_sitemap_language_ids` adjusts the permitted set.

Core sitemaps accept only `loc`, `lastmod`, `changefreq`, and `priority`, and their `<urlset>` declares no XHTML namespace, so `xhtml:link` hreflang annotations cannot be added without replacing the renderer. That replacement, along with per-language sitemap indexes, translated schema, SEO-field copying, and translated slugs, is outside Free v1.

## Template API

`includes/api.php` exposes the whole engine as plain prefixed functions, so a theme or plugin can add LocalePress support without touching a namespace. Every function is guarded: it returns an empty value instead of failing when LocalePress is deactivated, has not booted, or has no languages. Wrap calls in `function_exists()` so the integration also survives LocalePress being uninstalled.

Call them on or after `init`.

A **language reference** may be a URL slug (`bn`), a language code (`bn`), a WordPress locale (`bn_BD`), a stable language ID, or a full language record. An empty reference always means the language of the current request.

```php
if ( function_exists( 'localepress_is_active' ) && localepress_is_active() ) {
	$current = localepress_current_language();               // 'bn'
	$locale  = localepress_current_language( 'locale' );     // 'bn_BD'
	$native  = localepress_current_language( 'native_name' );

	$bengali_id = localepress_get_post( get_the_ID(), 'bn' );

	if ( $bengali_id ) {
		printf( '<a href="%s">%s</a>', esc_url( get_permalink( $bengali_id ) ), esc_html( $native ) );
	}
}
```

### Languages

| Function | Returns |
| --- | --- |
| `localepress_is_active()` | Whether LocalePress booted and holds a language. |
| `localepress_current_language( $field = 'slug' )` | One field of the current language. |
| `localepress_default_language( $field = 'slug' )` | One field of the default language. |
| `localepress_languages_list( $args = array() )` | Registered languages in configured order. Accepts `fields` and `hide_disabled`. |
| `localepress_get_language( $language = '' )` | The full language record for any reference. |
| `localepress_get_language_field( $language, $field = 'slug' )` | One field of any reference or record. |
| `localepress_is_rtl( $language = '' )` | Whether a language is right to left. |
| `localepress_home_url( $language = '' )` | The home URL for one language. |

Accepted `$field` values: `slug`, `id`, `code`, `locale`, `name`, `native_name`, `is_rtl`, `enabled`, and `all` for the whole record. A language record contains `id`, `name`, `native_name`, `locale`, `language_code`, `url_slug`, `is_rtl`, and `enabled`.

### Posts and terms

| Function | Returns |
| --- | --- |
| `localepress_object_id( $object_id, $type = 'post', $return_original_if_missing = true, $language = '' )` | Translated post or term ID, falling back to the original. |
| `localepress_get_post( $post_id, $language = '' )` | Translated post ID, or `0`. |
| `localepress_get_term( $term_id, $language = '', $taxonomy = '' )` | Translated term ID, or `0`. |
| `localepress_get_post_language( $post_id, $field = 'slug' )` | The post's language. |
| `localepress_get_term_language( $term_id, $field = 'slug', $taxonomy = '' )` | The term's language. |
| `localepress_get_post_translations( $post_id )` | Whole group, keyed by URL slug. |
| `localepress_get_term_translations( $term_id, $taxonomy = '' )` | Whole group, keyed by URL slug. |
| `localepress_set_post_language( $post_id, $language )` | Assignment array, or `WP_Error`. |
| `localepress_set_term_language( $term_id, $language, $taxonomy = '' )` | Assignment array, or `WP_Error`. |
| `localepress_save_post_translations( $translations, $source_post_id = 0 )` | Stored group, or `WP_Error`. |
| `localepress_save_term_translations( $translations, $taxonomy = '', $source_term_id = 0 )` | Stored group, or `WP_Error`. |
| `localepress_is_translated_post_type( $post_type )` | Whether the post type participates. |
| `localepress_is_translated_taxonomy( $taxonomy )` | Whether the taxonomy participates. |

`$taxonomy` is read from the term itself when omitted. The keys given to `localepress_save_post_translations()` and `localepress_save_term_translations()` are language references, so slugs are enough:

```php
$result = localepress_save_post_translations(
	array(
		'en' => 12,
		'bn' => 34,
	)
);

if ( is_wp_error( $result ) ) {
	// 'localepress_unknown_language' or a relationship error.
}
```

### Registered strings

The string engine is group and key based, and stores plain text. `localepress__()` returns the raw value for the caller to escape; the `esc_` variants escape it.

| Function | Behavior |
| --- | --- |
| `localepress__( $group, $key, $fallback = '', $language = '' )` | Returns the raw string. |
| `localepress_e( … )` | Echoes it, escaped for HTML. |
| `localepress_esc_html__( … )` / `localepress_esc_html_e( … )` | Return or echo, HTML escaped. |
| `localepress_esc_attr__( … )` / `localepress_esc_attr_e( … )` | Return or echo, attribute escaped. |

```php
add_action(
	'init',
	function () {
		localepress_register_string( 'my-theme', 'read_more', 'Read more' );
	}
);

localepress_e( 'my-theme', 'read_more', 'Read more' );
```

The `$fallback` argument doubles as the original value, so passing it registers the string on first use and no separate registration call is required.

### Language switcher

| Function | Behavior |
| --- | --- |
| `localepress_the_languages( $args = array() )` | Prints the switcher. Pass `'echo' => false` to return the markup. |
| `localepress_get_language_switcher( $args = array() )` | Returns the markup. |
| `localepress_language_switcher( $args = array() )` | Prints the markup. |

Switcher arguments are documented under [Language switcher](#language-switcher). The returned markup is already escaped.

## Translation API

The relationship service is available after `localepress_loaded`:

```php
$translations = LocalePress\Plugin::instance()->translations();

$language     = $translations->get_post_language( $post_id );
$translation  = $translations->get_translation( $post_id, $language_id );
$group        = $translations->get_translations( $post_id );
$assignment   = $translations->set_post_language( $post_id, $language_id );
$link_result  = $translations->link_translations( $posts_by_language, $source_post_id );
$translated   = $translations->create_translation(
	$source_post_id,
	$language_id,
	array(
		'copy_content'        => true,
		'copy_featured_image' => true,
	)
);
```

The taxonomy relationship service uses an explicit taxonomy argument:

```php
$terms         = LocalePress\Plugin::instance()->term_translations();
$language      = $terms->get_term_language( $term_id, $taxonomy );
$translation   = $terms->get_translation( $term_id, $taxonomy, $language_id );
$group         = $terms->get_translations( $term_id, $taxonomy );
$assignment    = $terms->set_term_language( $term_id, $taxonomy, $language_id );
$link_result   = $terms->link_translations( $terms_by_language, $taxonomy, $source_term_id );
```

Mutation methods return `WP_Error` when validation or storage fails. Admin callers independently enforce nonces and the source/target post type's `edit_post` and `create_posts` capabilities.

Navigation and workflow services are also available after `localepress_loaded`:

```php
$menus    = LocalePress\Plugin::instance()->menus();
$settings = LocalePress\Plugin::instance()->workflow_settings();

$menu_language = $menus->get_menu_language_id( $menu_id );
$location_menu = $menus->get_menu_for_location( $theme_location, $language_id );
$copy_options  = $settings->get();
```

Optional Elementor compatibility is exposed through the same application boundary:

```php
$elementor = LocalePress\Plugin::instance()->elementor();

if ( $elementor->is_available() && $elementor->is_elementor_document( $source_post_id ) ) {
	$result = $elementor->copy_document( $target_post_id, $source_post_id, true );
}
```

`copy_document()` returns `true` when copied, `false` when Elementor compatibility does not apply, and `WP_Error` for invalid or unsafe copy attempts. Normal LocalePress translation creation invokes it automatically.

## Routing API

The language-aware URL service is available after `localepress_loaded`:

```php
$plugin  = LocalePress\Plugin::instance();
$urls    = $plugin->urls();
$current = $urls->get_current_language();
$default = $urls->get_default_language();

$language_home  = $urls->get_language_home_url( $language_id );
$switched_url   = $urls->switch_language_url( $language_id );
$translated_url = $urls->get_translation_url( $post_id, $language_id );
$term_url       = $urls->get_translation_url( $term_id, $language_id, $taxonomy );
```

Language references may be a stable language ID, URL slug, language code, or language record. Translation URL methods return an empty string when the target language or required object translation is unavailable. Callers must contextually escape returned URLs when rendering them.

The request-sensitive SEO API exposes the validated URLs used in document head output:

```php
$seo        = LocalePress\Plugin::instance()->seo();
$alternates = $seo->get_alternate_urls(); // Hreflang => absolute URL.
$canonical  = $seo->get_canonical_url();
```

Both methods return empty values outside indexable frontend HTML contexts. The service caches its alternate set for the request and loads each post or term translation group once rather than querying once per enabled language.

## Language switcher

All integrations use one `LanguageSwitcher` service, so posts, pages, public CPTs, terms, the front page, the posts page, archives, search, pagination, and 404 requests resolve through the same Phase 4 URL API. Singular content and taxonomy items link to their actual translation. Generic contexts retain the current route under the target prefix. The block editor renders its preview through the REST block-renderer endpoint, which sets up post data but no main query, so a REST render resolves against the previewed post instead of the request path and shows the same translated links the frontend will.

Use the shortcode or dynamic block:

```text
[localepress_switcher display="native_name" layout="horizontal"]
<!-- wp:localepress/language-switcher /-->
```

LocalePress > Settings > Switcher prints the shortcode under the controls, built from whatever those controls are currently set to and rebuilt as they change, with a copy button beside it. A placement that needs its own settings can be copied from there rather than written from memory.

### Elementor widget

**LocalePress > Language Switcher** in the Elementor panel, filed under its own category. It is a control surface and nothing else: every language it lists, every URL it links to, and every element of the markup comes from the same `LanguageSwitcher` service the shortcode, the block, the menu item, and the floating switcher render through, so a fix to how a switcher resolves a translation reaches an Elementor header without being ported there.

Content controls cover layout, the label, flags, which languages to list, hiding the current one, what a missing translation does, disabled languages, and the accessible label. The label is one choice rather than a set of checkboxes, because a switcher showing a name and a code together has to say which is the link and which is the annotation, in every language at once, and no answer to that reads well in all of them; the renderer takes a single label mode and the widget offers exactly that. Leaving Languages empty lists every enabled language, including ones registered later — a selection is a fixed list, and a selection naming only languages that have since been deleted is ignored rather than emptying the widget.

Style controls are Elementor's own: typography and normal/hover/current colors for a language, size, spacing and radius for a flag, background, border, radius, shadow and padding for the open dropdown panel, and gap, padding and alignment under Spacing. The gap writes `--localepress-switcher-gap`, which the core stylesheet already spaces the list with, so one control covers a row and a column alike. The dropdown section appears only for the dropdown layout and the flag section only when flags are on.

A widget that would render nothing — a site with one language, or a page where every other language is unavailable and set to hide — draws a dashed placeholder while the editor is open, so it stays selectable, and nothing at all on the published page.

### Floating switcher

One switcher the plugin places by itself, and the only one it does. A site that has just registered its second language has put no switcher anywhere yet — no shortcode, no block, no menu item — and until it does, that language is registered and routed and reachable by nobody reading the site. So the floating switcher starts on: LocalePress > Settings > Switcher > Floating switcher is where it is turned off again, which is what a theme that already carries a switcher of its own does.

It is printed in `wp_footer`, because that is the one hook every theme has, but it is not in the footer: it is `position: fixed`, drawn over the page rather than inside it, so no theme has to make room for it and its position owes nothing to where in the markup it was written. It defaults to `middle-right` — a vertical strip halfway down the right edge, flush against it — because that is the one part of the screen that is in view however far the reader has scrolled. `middle-left` is the same strip on the other side, and the four corners are there for a site whose sides are already taken by a chat bubble or a cookie notice; a corner keeps a margin rather than sitting flush, and the two top ones account for a logged-in visitor's admin bar. Every position is physical rather than logical, so a switcher put on the right stays on the right in a right-to-left language rather than crossing the screen.

Each language is a pill of its own rather than a row in a shared panel, and the language being read is filled in rather than merely marked, because a strip at the edge of the screen is read from the corner of the eye where a border would not survive. Flags are cropped to circles here and nowhere else — in a menu or a post a flag sits beside body text at its own proportions, while in the strip it is the marker read before the label is. On a phone the labels are dropped and the flags carry the strip alone, but only where flags are switched on; a site running the floater without them keeps its labels rather than being left with empty pills. Four custom properties carry the colors, so a theme that wants the strip in its own palette overrides those rather than fighting the rules.

Flags and layout are the floater's own settings rather than inherited ones, and flags start on here while the site-wide checkbox starts off. The two are answering different questions: elsewhere a switcher sits in running text and a flag is decoration a site opts into, while in the strip it is what identifies a language before its label is read, and on a phone it is the only thing left. Layout is separate for the same reason — a site can keep dropdowns everywhere else and still show every language at once in the strip, or the reverse. Labels and missing-translation handling do come from the switcher settings above, because those describe what a switcher says rather than where it goes. The dropdown layout loads the same measuring script every dropdown does, so a panel pinned low on the screen opens upward. Nothing renders at all where a switcher would be empty: a single-language site, or a URL mode that carries no language prefixes.

It also stands down inside a page builder. A builder renders the real front end in its canvas, so everything `wp_footer` prints turns up while editing — correct for most of what a plugin adds, and wrong for this one: the strip is not part of the page being edited, cannot be selected or moved, and sits over the corner the builder needs for its own handles. Any builder that registers its preview argument through `localepress_builder_preview_query_args` is recognized, `elementor-preview` among them, and Elementor is additionally asked directly, because that argument names only the canvas frame while the editor renders template and popup previews that announce themselves nowhere in the URL. The assets are skipped along with the markup, so the editor does not load the stylesheet either.

```php
// Keep the floater on the site but off one template.
add_filter(
	'localepress_render_floating_switcher',
	function ( $enabled ) {
		return is_cart() ? false : $enabled;
	}
);
```

The block inspector and the LocalePress item in Appearance > Menus expose the same core controls. Themes use the template tags, which return or echo escaped HTML and are safe to call before LocalePress has booted:

```php
<?php localepress_language_switcher(); ?>

<?php
localepress_language_switcher(
	array(
		'display'              => 'language_code',
		'layout'               => 'dropdown',
		'show_flags'           => true,
		'hide_current'         => false,
		'hide_missing'         => false,
		'unavailable_behavior' => 'home',
	)
);
?>
```

`localepress_get_language_switcher( $args )` returns the same markup instead of echoing it. Both accept the shortcode's argument names and fall back to the configured switcher defaults. The underlying service remains available as `LocalePress\Plugin::instance()->switcher()` for integrations that need the item model through `get_items()`.

Locale names normally carry their region in trailing parentheses, so a switcher drops that region wherever the shortened name stays unique in the rendered set: `English (United States)` alone renders as `English`, while a site offering both American and British English keeps both regions because the shortened names would collide. `localepress_switcher_labels` replaces the resolved labels outright.
 Switching to the language already on screen keeps the reader where they are, whatever the route: a page belonging to no translation group answers the translation lookup with nothing even for the language it is being read in, and treating that as unavailable would let a switcher drop the reader's own language from the reader's own URL.
`display` accepts `name`, `native_name`, or `language_code`. `layout` accepts `horizontal`, `vertical`, or `dropdown`. Missing translations can be shown as `disabled`, sent to the target language `home`, kept on the `current` URL, or handled by `hide`/`hide_missing`. `current` hands each language the route the reader is already on, addressed in that language: an archive or a search route exists in every language, so a shop with no translated products still links to that shop under the other language rather than back to the page on screen. A single post route belongs to one translation group, so a language with no member of it cannot address the route at all; those fall through to that language's home page, which is the nearest page that exists. A cart, a checkout, or an account page therefore keeps every language in the switcher and sends each one somewhere real, without the switcher needing to know what those pages are. Disabled registered languages stay hidden unless `show_disabled` is enabled and are never linked. `hide_missing` is the older spelling of `unavailable_behavior="hide"` and wins wherever the two disagree, so the Visibility checkbox overrules the Missing translation control; a shortcode that spells out `unavailable_behavior` without naming `hide_missing` is taken at its word and does not inherit the stored checkbox.
 A substitute that comes back empty — a filter that cleared it, a language with no reachable home — takes the entry out rather than leaving a label that leads nowhere, because a switcher told to hide these must not show a dead one.
`hide` asks a site-wide question rather than a per-page one. Whether this page has a translation and whether the site speaks a language are separate things, and answering the first in place of the second tells the reader something untrue: a cart page has no translation in any language, yet a site with translated posts behind it is plainly multilingual. So a language stays in the switcher as long as one published post anywhere carries it, and on a page with no translation of its own it points at that language's home — a substitute rather than a translation, marked `has-fallback` and announced as such. Only a language nothing has been written in yet leaves the switcher, because that one has nowhere to go. The lookup is a single indexed `LIMIT 1` against the assignment table, answered once per request per language, and the default language never needs asking: content with no assignment belongs to it. `localepress_language_has_content` receives that decision and the language record.

A single post is offered to a language when its translation exists. An archive has no translation to look up, because every language addresses the same route, so the equivalent question is whether that route would hold anything once the language filter runs. On a post type archive, a date archive, or an author archive, a language is therefore offered only when it has at least one published post of the listed post types. A post type the site never made translatable carries no assignment at all, so the same clause answers "nothing here" for every language but the default, and its archive is unavailable to them. The frontend query agrees: an archive listing whose post types are all untranslated stays limited to the requested language, so an untranslated shop reports no products in a language it was never written in rather than listing the default language's products as if they were translated. A single post of such a post type is left alone, because hiding it would turn a page that plainly exists into a 404. `localepress_constrain_untranslated_archive` returns that decision to a site that would rather share one archive across every language. This is what lets `unavailable_behavior` reach an archive: on a WooCommerce shop with no translated products, `hide` sends those languages to their own home instead of to an empty shop, `current` links to the shop under that language anyway and lets it say it holds nothing, and `disabled` shows the language without a link. The language the archive is currently being read in is never subject to the check — it plainly holds what the reader is looking at — so the switcher narrows rather than disappearing. The check is one indexed `LIMIT 1` lookup per language, answered once per request. `localepress_hide_archive_translation_url` receives that decision, the language record, and the post types, and returning false keeps the language on an archive that would come back empty.

Set `show_flags` to render a flag beside each label. Flags come from the bundled SVG set in `assets/flags`, keyed by the country code LocalePress derives from the language locale — `bn_BD` resolves to `bd`, `pt_BR` to `br`. Because languages and countries are not equivalent, every flag stays overridable: drop an image at `wp-content/uploads/localepress/flags/{locale}.{svg,png,webp,jpg,gif}`, or filter `localepress_flag_code`, `localepress_flag`, or `localepress_switcher_flag_url`. A language with no resolvable flag renders its text label alone rather than a broken image. Decorative flag images receive an empty alt attribute while the text label remains available. Output uses native links, lists, `<nav>`, and `<details>` elements with current/unavailable ARIA state. The list layouts load no JavaScript at all. The dropdown layout enqueues one small script, and only on a page that renders one: it measures the room around an open panel and opens it upward when the viewport would otherwise clip it, so a switcher in a footer stays usable without being told it is in a footer. Nothing depends on that script — where it does not run, every panel opens downward as before.

The same flags appear in the admin: the Language and Translations columns on post and term list tables, the post editor and term translation panels, the translation dashboard headings, the string translation column, and the Languages settings screen.

## Developer hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `localepress_non_public_post_types` | Filter | Make a non-public post type, such as a builder's header or footer template, eligible for translation. |
| `localepress_supported_post_types` | Filter | Turn a post type's translation support on. |
| `localepress_language_repository` | Filter | Replace the storage implementation. |
| `localepress_translation_repository` | Filter | Replace post translation relationship storage. |
| `localepress_translation_dashboard_repository` | Filter | Supply optional bounded reporting for the dashboard's active relationship storage. |
| `localepress_translation_dashboard_post_types` | Filter | Adjust supported post types visible to the current dashboard user. |
| `localepress_translation_dashboard_languages` | Filter | Adjust enabled language columns before reporting. |
| `localepress_translation_dashboard_query_args` | Filter | Adjust normalized, revalidated dashboard query arguments. |
| `localepress_translation_dashboard_complete_statuses` | Filter | Define post statuses counted as completed. |
| `localepress_translation_dashboard_draft_statuses` | Filter | Define post statuses included by the Draft view. |
| `localepress_translation_dashboard_items` | Filter | Adjust prepared rows after all posts and relationships are bulk-primed. |
| `localepress_translation_dashboard_content_html` | Filter | Adjust one source-content cell. |
| `localepress_translation_dashboard_cell_html` | Filter | Adjust one language-status cell. |
| `localepress_translation_dashboard_bulk_actions` | Filter | Register addon bulk actions; LocalePress Free registers none. |
| `localepress_translation_dashboard_bulk_action` | Action | Receive nonce-verified source IDs the user can edit for a registered bulk action. |
| `localepress_translation_dashboard_before_table` | Action | Render or initialize an integration before the prepared dashboard table. |
| `localepress_translation_dashboard_after_table` | Action | Render an integration after the dashboard table. |
| `localepress_string_repository` | Filter | Replace registered-string persistence and reporting storage. |
| `localepress_pre_register_string` | Filter | Adjust a developer registration before validation. |
| `localepress_registered_string_saved` | Action | React after a definition is created or its original changes. |
| `localepress_string_translation` | Filter | Adjust one resolved registered-string value. |
| `localepress_string_translation_saved` | Action | React after one language-specific value is saved. |
| `localepress_string_translation_deleted` | Action | React after one language-specific value is deleted. |
| `localepress_string_translation_languages` | Filter | Restrict or reorder enabled languages in the string editor. |
| `localepress_string_translation_groups` | Filter | Restrict or reorder registered groups in the string editor. |
| `localepress_string_translation_query_args` | Filter | Adjust normalized, revalidated string editor query arguments. |
| `localepress_string_translation_items` | Filter | Adjust one prepared page after translations are bulk-loaded. |
| `localepress_term_translation_repository` | Filter | Replace term translation relationship storage. |
| `localepress_wpml_config_files` | Filter | Add or remove a `wpml-config.xml` file before it is read. |
| `localepress_wpml_config_rules` | Filter | Adjust every parsed declaration, including the block and term-field rules Free does not act on yet. |
| `localepress_wpml_config_enabled` | Filter | Turn the `wpml-config.xml` layer off, as `LOCALEPRESS_WPML_CONFIG` does. |
| `localepress_translate_option` | Filter | Allow a declared option to be translated on a request that would otherwise keep the stored value. |
| `localepress_core_string_catalog` | Filter | Add a site's or an addon's own options to the ones LocalePress registers for translation. |
| `localepress_register_core_strings` | Filter | Stop LocalePress from registering its own catalog of core and widget options. |
| `localepress_modules` | Filter | Register modules that implement `ModuleInterface`. |
| `localepress_settings_defaults` | Filter | Adjust central defaults before normalization. |
| `localepress_settings` | Filter | Adjust normalized central settings at read time. |
| `localepress_settings_updated` | Action | React after normalized central settings are stored. |
| `localepress_settings_export` | Filter | Add portable data to a settings export while preserving its format fields. |
| `localepress_validated_settings_import` | Filter | Inspect validated import data or return `WP_Error` before mutation. |
| `localepress_settings_imported` | Action | React after a validated settings document is applied. |
| `localepress_setup_completed` | Action | React after the setup wizard is completed. |
| `localepress_supported_post_types` | Filter | Adjust eligible public post types. |
| `localepress_supported_taxonomies` | Filter | Adjust eligible public taxonomies. |
| `localepress_auto_assign_default_post_language` | Filter | Disable or conditionally control default assignment after a post save. |
| `localepress_auto_assign_default_term_language` | Filter | Disable or conditionally control default assignment after term creation. |
| `localepress_manage_languages_capability` | Filter | Change the capability required by the menu and every mutation handler. |
| `localepress_hide_foreign_admin_notices` | Filter | Keep other plugins' admin notices visible on LocalePress screens. |
| `localepress_enable_admin_language_filter` | Filter | Disable the admin bar language filter, or restrict who is offered it. |
| `localepress_new_post_language_id` | Filter | Change the language a post with no assignment starts in. |
| `localepress_new_term_language_id` | Filter | Change the language a term with no assignment starts in. |
| `localepress_language_catalog` | Filter | Add or adjust presets in the setup language selector. |
| `localepress_registered_languages` | Filter | Filter languages after loading. |
| `localepress_pre_validate_language` | Filter | Adjust submitted data before core validation. |
| `localepress_pre_delete_language` | Filter | Block deletion while a module still uses a language. |
| `localepress_language_registered` | Action | React to a newly registered language. |
| `localepress_language_updated` | Action | React to language field or status changes. |
| `localepress_language_deleted` | Action | Clean up data related to a deleted language. |
| `localepress_language_order_changed` | Action | React to explicit ordering changes. |
| `localepress_default_language_id` | Filter | Filter the default language ID. |
| `localepress_default_language` | Filter | Filter the default language record. |
| `localepress_default_language_changed` | Action | React to a stored default change. |
| `localepress_current_language_id` | Filter | Supply detection from a future URL, cookie, user, or domain module. |
| `localepress_current_language` | Filter | Filter the final resolved language record. |
| `localepress_enable_frontend_routing` | Filter | Enable or disable registration of global routing hooks. |
| `localepress_should_switch_locale` | Filter | Allow or block switching the WordPress locale for one request. |
| `localepress_request_locale` | Filter | Replace the locale applied to a prefixed frontend request. |
| `localepress_should_detect_language` | Filter | Allow or block visitor language detection for one request. |
| `localepress_browser_detection_languages` | Filter | Restrict the languages offered to browser preference matching. |
| `localepress_detected_language_url` | Filter | Change or cancel the URL a detected visitor is sent to. |
| `localepress_language_cookie_lifetime` | Filter | Change how long a visitor's language is remembered. |
| `localepress_language_detected` | Action | React immediately before a detected visitor is redirected. |
| `localepress_filter_main_query_by_language` | Filter | Bypass the indexed language constraint for a specific main query. |
| `localepress_filter_block_query_by_language` | Filter | Bypass the indexed language constraint for a specific block query. |
| `localepress_post_query_block_names` | Filter | Register block names that run their own post query while rendering. |
| `localepress_sync_catalog` | Filter | Describe an additional copyable or synchronizable item. |
| `localepress_copy_post_meta_keys` | Filter | Adjust the meta keys written into a translation, per phase. |
| `localepress_translate_post_meta_value` | Filter | Rewrite one meta value before it reaches a translation. |
| `localepress_current_user_can_synchronize` | Filter | Decide whether the current user may change synchronized data. |
| `localepress_insert_translation` | Filter | Insert a translation yourself for a post type WordPress does not create through `wp_insert_post()`. |
| `localepress_translation_featured_image_id` | Filter | Choose the attachment a translated draft uses as its featured image. |
| `localepress_media_file_shared` | Action | React after a media translation is pointed at the shared file. |
| `localepress_language_rest_routes` | Filter | Adjust which REST routes the editor tags with a language. |
| `localepress_filter_rest_query_by_language` | Filter | Bypass the language constraint for one REST collection. |
| `localepress_translation_items_copied` | Action | React after enabled items are copied into a new translation. |
| `localepress_translation_items_synchronized` | Action | React after enabled items are synchronized into one translation. |
| `localepress_missing_translation_url` | Filter | Supply an explicit fallback URL when an object translation is missing. |
| `localepress_language_tag` | Filter | Adjust one normalized HTML/hreflang language tag. |
| `localepress_language_attributes` | Filter | Adjust final frontend `lang`, `xml:lang`, and `dir` attributes. |
| `localepress_hreflang_languages` | Filter | Adjust enabled languages considered for alternate URLs. |
| `localepress_hreflang_urls` | Filter | Adjust the complete alternate URL map before final validation. |
| `localepress_x_default_url` | Filter | Change or omit the request's x-default URL. |
| `localepress_canonical_url` | Filter | Adjust LocalePress's non-singular core canonical URL. |
| `localepress_provider_canonical_url` | Filter | Adjust a same-site canonical after the current prefix is applied. |
| `localepress_output_canonical` | Filter | Disable LocalePress-owned non-singular canonical output. |
| `localepress_has_canonical_provider` | Filter | Declare that another integration owns canonical output. |
| `localepress_canonical_provider_constants` | Filter | Extend the constants that indicate an SEO plugin owns canonicals. |
| `localepress_provider_canonical_filters` | Filter | Extend the SEO provider canonical hooks made language-aware. |
| `localepress_sitemap_language_ids` | Filter | Adjust the languages whose content may appear in the core sitemap. |
| `localepress_seo_is_indexable_request` | Filter | Control LocalePress canonical and hreflang eligibility. |
| `localepress_switcher_default_args` | Filter | Change shared switcher defaults before instance arguments are merged. |
| `localepress_switcher_args` | Filter | Adjust merged switcher arguments before normalization. |
| `localepress_switcher_languages` | Filter | Adjust ordered language records before items are built. |
| `localepress_switcher_labels` | Filter | Replace the display labels one switcher pass resolved for each language. |
| `localepress_switcher_unavailable_url` | Filter | Customize a missing translation fallback URL. |
| `localepress_switcher_flag_url` | Filter | Override the switcher flag image URL for a language. |
| `localepress_switcher_flag_html` | Filter | Customize allowlisted flag markup. |
| `localepress_flag_code` | Filter | Override the country code a language resolves its flag from. |
| `localepress_flag` | Filter | Override the resolved flag URL, code, and dimensions. |
| `localepress_flag_html` | Filter | Customize allowlisted flag markup shared by admin screens. |
| `localepress_custom_flag_url` | Filter | Supply a site-specific flag image URL for one locale. |
| `localepress_switcher_item` | Filter | Adjust or omit one normalized switcher item. |
| `localepress_switcher_items` | Filter | Adjust the complete normalized item collection. |
| `localepress_switcher_html` | Filter | Replace final trusted switcher markup. |
| `localepress_render_floating_switcher` | Filter | Keep the floating switcher off one template while leaving it on for the site. |
| `localepress_switcher_menu_args` | Filter | Adjust settings for one classic menu switcher item. |
| `localepress_post_language` | Filter | Filter a post's resolved language record for display/API consumers. |
| `localepress_post_translations` | Filter | Filter a loaded language-to-post map for display/API consumers. |
| `localepress_post_language_changed` | Action | React after a post language changes. |
| `localepress_translations_linked` | Action | React after posts join one validated group. |
| `localepress_translation_copy_post_data` | Filter | Adjust core fields before a translated draft is inserted. |
| `localepress_workflow_settings` | Filter | Adjust stored translated-draft workflow defaults. |
| `localepress_translation_copy_options` | Filter | Adjust content and featured-image behavior for one translated draft. |
| `localepress_translation_featured_image_copied` | Action | React after a translated draft reuses the source attachment ID. |
| `localepress_translation_created` | Action | React after a translated draft is inserted and linked. |
| `localepress_elementor_available` | Filter | Override optional Elementor availability detection. |
| `localepress_is_elementor_document` | Filter | Adjust whether one post is treated as an Elementor document. |
| `localepress_elementor_copy_meta_keys` | Filter | Extend or restrict stable Elementor source metadata copied to a translation. |
| `localepress_elementor_copy_meta_value` | Filter | Adjust one copied Elementor metadata value before storage. |
| `localepress_elementor_generated_meta_keys` | Filter | Extend generated Elementor metadata invalidated on the target. |
| `localepress_elementor_document_copied` | Action | React after independent Elementor document metadata is copied. |
| `localepress_elementor_document_copy_failed` | Action | Observe a rejected or failed Elementor document copy. |
| `localepress_post_translation_unlinked` | Action | Clean up after permanent post deletion removes a membership. |
| `localepress_translation_group_deleted` | Action | React after the final member and its empty group are removed. |
| `localepress_translation_post_trashed` | Action | Observe trashing while the relationship remains intact. |
| `localepress_translation_post_untrashed` | Action | Observe restoration of a translated post. |
| `localepress_term_language` | Filter | Filter a term's resolved language record for display/API consumers. |
| `localepress_term_translations` | Filter | Filter a loaded language-to-term map for display/API consumers. |
| `localepress_term_language_changed` | Action | React after a term language changes. |
| `localepress_term_translations_linked` | Action | React after terms join one validated group. |
| `localepress_term_translation_copy_data` | Filter | Adjust core fields before a translated term is inserted. |
| `localepress_term_translation_created` | Action | React after a translated term is inserted and linked. |
| `localepress_term_translation_unlinked` | Action | Clean up after term deletion removes a membership. |
| `localepress_term_translation_group_deleted` | Action | React after an empty term group is removed. |
| `localepress_menu_language_id` | Filter | Filter one native menu's assigned language. |
| `localepress_menu_location_assignments` | Filter | Adjust per-language assignments for registered theme locations. |
| `localepress_menu_configuration_updated` | Action | React after menu and location assignments are saved. |
| `localepress_override_explicit_nav_menu` | Filter | Permit a language location to replace an explicit `wp_nav_menu()` menu. |
| `localepress_translate_menu_links` | Filter | Enable or bypass translated links for one rendered menu. |
| `localepress_menu_missing_translation_behavior` | Filter | Choose `hide`, `home`, `current`, or `preserve` for missing object links. |
| `localepress_menu_post_translation_available` | Filter | Adjust whether a translated post may appear in public navigation. |
| `localepress_menu_item_translated` | Action | React after an object menu item resolves to its translated object. |
| `localepress_translated_menu_items` | Filter | Adjust the final menu item objects before the walker runs. |
| `localepress_before_upgrade` | Action | Run immediately before a core schema upgrade. |
| `localepress_upgraded` | Action | Run after the core schema version is updated. |
| `localepress_loaded` | Action | Run after services and modules are registered. |

The application services are available to integrations through `LocalePress\Plugin::instance()->languages()`, `LocalePress\Plugin::instance()->translations()`, `LocalePress\Plugin::instance()->term_translations()`, `LocalePress\Plugin::instance()->strings()`, `LocalePress\Plugin::instance()->urls()`, `LocalePress\Plugin::instance()->seo()`, `LocalePress\Plugin::instance()->switcher()`, `LocalePress\Plugin::instance()->menus()`, `LocalePress\Plugin::instance()->settings()`, `LocalePress\Plugin::instance()->workflow_settings()`, `LocalePress\Plugin::instance()->elementor()`, and `LocalePress\Plugin::instance()->current_language()` after `localepress_loaded`.

## Security review

- Language management requires `manage_options` by default both when rendering the screen and handling mutations. The capability filter is applied consistently to both paths.
- All create, update, delete, status, default, and ordering requests use POST and action-specific nonces.
- Request arrays are allowlisted, unslashed, sanitized, and validated before persistence.
- Locale and language code formats are constrained; locale and URL slug values must be unique.
- Stable IDs are generated server-side with `wp_generate_uuid4()` and are never accepted from editable fields.
- Output uses contextual escaping, redirect destinations use `wp_safe_redirect()`, and notice codes map to trusted server-side messages.
- Failed form input is kept in a per-user transient for at most one minute and escaped when redisplayed.
- Visitor language detection reads only `Accept-Language` and its own cookie, validates both against registered enabled languages, caps parsed ranges, never echoes either value, and redirects through `wp_validate_redirect()` and `wp_safe_redirect()`. The cookie stores a sanitized language slug and nothing about the visitor.
- Post language saves require a post-specific nonce and `edit_post`; translated-copy actions require an action-specific nonce plus both `edit_post` and the target type's `create_posts` capability.
- New supported posts and terms persist the configured default through global lifecycle hooks; translated-copy insertion is guarded so target content is linked only to its requested language.
- Existing unassigned content receives a read-only effective default and is persisted only on save or translation creation, avoiding bulk writes and database mutations during list rendering.
- Relationship APIs validate registered/enabled languages, supported post types, common post type membership, source membership, existing groups, and duplicate language slots before writing.
- Database uniqueness constraints provide a second integrity boundary against concurrent duplicate or conflicting writes.
- Translation action parameters are allowlisted and sanitized, output is contextually escaped, and redirect targets use `wp_safe_redirect()`.
- Translation dashboard access rechecks the filtered LocalePress management capability. Reporting post types are further restricted by each type's `edit_posts` capability, while every edit/add action checks the specific post and creation capabilities.
- Dashboard filters are read-only, allowlisted, sanitized, and prepared by the reporting repository. Add actions use source/language-specific nonces; addon bulk actions require the core list-table nonce and receive only source IDs the current user can edit.
- Reporting is paginated before object loading and uses fixed SQL fragments with prepared values. Source, relationship, and translation caches are primed in bounded batches to avoid per-row queries.
- String editor rendering and saves require the shared filtered LocalePress management capability. Saves use a language-specific nonce, accept only enabled language IDs and registered deterministic string IDs, cap each submission at 100 rows, and validate every value before mutation.
- Registered groups, keys, originals, and translations are scalar-only, sanitized as plain text, and length constrained. SQL values are prepared, custom-table uniqueness backs application validation, output is contextually escaped, and save redirects rebuild only allowlisted query arguments.
- Registered-string caches contain positive and negative entries and are updated or invalidated on every mutation. Deleting a language removes its now-unreachable translation rows without blocking language deletion.
- Trash preserves relationships and prevents duplicate replacement translations. Permanent deletion removes membership globally and repairs the source pointer or deletes an empty group.
- Languages assigned to content cannot be deleted. Deactivation remains non-destructive and preserves languages and relationships.
- Term language saves require LocalePress add/edit nonces and the taxonomy's mapped capabilities; translated-term creation requires its own nonce plus source edit and target creation permissions.
- Term relationship APIs validate the taxonomy, term identity, enabled language, common taxonomy membership, source membership, existing groups, and duplicate language slots before writing.
- Hierarchy synchronization uses core term APIs, prevents self-parenting, preserves the source tree, and applies translated parents only when the matching parent translation exists.
- Permanent term deletion removes the membership globally, selects a surviving source when needed, deletes empty groups, and reconciles translated descendants.
- Languages assigned to terms cannot be deleted. Deactivation remains non-destructive and preserves term relationships.
- Public language slugs come only from the validated enabled-language registry and are escaped before entering rewrite expressions.
- Canonical redirects accept only GET and HEAD frontend requests, skip previews and infrastructure endpoints, and use `wp_safe_redirect()`.
- Singular and taxonomy routing validates the requested enabled language and maps only through existing translation groups. Missing translations become impossible queries and resolve to 404 responses.
- Frontend SQL is limited to the main public query, uses a fixed alias and a prepared language ID, and can be explicitly bypassed for compatible integrations.
- Preview URL handling preserves core preview parameters and never turns an unassigned unsupported object into a prefixed URL.
- Shortcode, block, template, and menu settings pass through one allowlisted normalizer; labels and class names are sanitized and final attributes, URLs, labels, and flag markup receive contextual escaping.
- Classic navigation menu settings require `edit_theme_options` and WordPress's core menu-update nonce before post meta is written.
- Disabled languages never receive frontend links. Missing translations are non-interactive by default, and fallback URLs are constrained through `esc_url_raw()` before rendering.
- LocalePress settings require the same filtered management capability and a dedicated POST nonce. Menu IDs, location keys, language IDs, and checkbox values are allowlisted and validated against current WordPress objects before any assignment is written.
- Each settings tab writes only its own section. Default-language changes enable the new default before switching and disable other languages afterward, so the registry cannot pass through a disabled or missing default state.
- Settings mutations and exports start from normalized stored data rather than runtime-filtered values, so extension filters cannot be persisted accidentally by an unrelated administrator save. Extension defaults are limited to known settings sections and normalized against the same schema.
- Setup steps require the management capability and step-specific nonces. Catalog locales and classic-menu IDs are validated, URL slugs are collision-safe, and rerunning switcher insertion does not create a duplicate virtual menu item.
- JSON imports are capped at 1 MB, uploaded files require a verified PHP upload and `.json` extension, and decoded values use strict schema, enum, boolean, locale, post-type, and taxonomy validation before any mutation. Raw JSON is never rendered.
- Exported language references use locales instead of UUIDs and exclude menu IDs and theme-location IDs. Imports cannot register, delete, or infer languages silently.
- URL behavior changes receive explicit post-save and post-import warnings. They do not force a rewrite flush because the underlying directory rules are unchanged.
- Uninstall cleanup runs only when the normalized stored value is the boolean `true`; deactivation and default uninstall behavior preserve all options, metadata, theme mods, and custom tables.
- One native menu cannot be assigned to conflicting language slots. Deleting a menu removes stale location mappings, and a language assigned to a menu cannot be deleted until the assignment is cleared.
- Menu translation runs only when LocalePress owns frontend prefix routing. External links, non-HTTP schemes, fragments, infrastructure paths, and explicit `wp_nav_menu()` selections are preserved by default.
- Menu translation bulk-primes post and term relationships plus translated objects before walking items, preventing per-menu-item translation queries.
- Block content is passed to `wp_insert_post()` as the original stored string and is not parsed or re-serialized. Empty-copy mode writes empty content/excerpt fields, and featured-image reuse copies only the validated attachment ID.
- Elementor compatibility runs only after Elementor's official load lifecycle and only for a newly linked translation whose source and target are different existing posts of the same type.
- Elementor source metadata is allowlisted, JSON is validated before storage, WordPress metadata APIs preserve slashing and serialization, and an existing target document cannot be overwritten.
- Generated Elementor records and per-post caches are excluded so copied CSS, asset indexes, render caches, screenshots, or edit state cannot leak between language versions.
- SEO output is limited to LocalePress-owned frontend HTML routes. Admin, AJAX, cron, REST, CLI, feed, embed, search, preview, trackback, and 404 contexts cannot emit LocalePress alternate or canonical links.
- Hreflang targets must be absolute HTTP(S) URLs, tags are validated and case-insensitively deduplicated, and post targets must be publicly viewable. Term targets require a public taxonomy.
- Canonical adapters alter only same-site provider URLs. Empty or external canonicals remain untouched, output is contextually escaped, and LocalePress suppresses its own archive canonical when a recognized SEO plugin owns the document head.
- Sitemap constraints are built with placeholders through `wpdb::prepare()`, one per language identifier, and reuse the same indexed assignment join as the routing engine. Unusable input leaves the query's SQL untouched.

## Tests

Integration tests use the standard WordPress PHPUnit test library:

```bash
WP_TESTS_DIR=/path/to/wordpress-tests-lib phpunit -c phpunit.xml.dist
```

Run WordPress Coding Standards when PHPCS and WPCS are installed:

```bash
phpcs --standard=phpcs.xml.dist
```

### Manual testing checklist

- Activate LocalePress individually on a supported site and confirm an administrator is redirected to setup once.
- Complete all five wizard steps, refresh between steps, and confirm setup resumes at the saved step without duplicating languages or switcher menu items.
- Rerun LocalePress > Setup Wizard on a configured site and confirm the current default, URL behavior, and switcher defaults are preselected.
- Open every LocalePress > Settings tab at desktop and mobile widths and confirm each save changes only that tab's fields.
- Change the default language while it is disabled in the registry, enable it in the same General save, and confirm it becomes enabled and default without a partial state.
- Disable a non-default language and confirm its content remains linked while its route and normal switcher entry disappear.
- Select only Pages and Categories under Content; confirm post, CPT, tag, and other taxonomy translation UI is removed while existing relationships remain stored.
- Change default-prefix behavior in URL settings. Verify the warning, root/default redirects, non-default prefixes, canonical URLs, and caches; confirm no redirect loop occurs.
- Change switcher defaults and verify shortcode/template calls without explicit arguments use them. Confirm existing explicitly configured blocks and menu items retain their own choices.
- Disable hreflang and x-default separately; confirm canonical handling and HTML language/RTL attributes remain active.
- Export settings, inspect that locales are present and language UUIDs/menu IDs are absent, then import on a site where all locales are registered.
- Import malformed, oversized, wrong-version, unknown-locale, unavailable-post-type, and unavailable-taxonomy JSON; confirm no language or setting changes partially apply.
- Enable uninstall deletion on a disposable site, uninstall LocalePress, and confirm its six custom tables, options, menu metadata, switcher metadata, and theme-location data are removed. Repeat with the setting off and confirm data is preserved.
- On a site with three or more languages, select All languages in String Translation, fill several rows in different languages, save once, and confirm every value is stored and the view stays on All languages. Clear one field, save, and confirm only that value returns to its original.
- On a site with two languages and no integration code, open LocalePress > String Translation and confirm the site title, tagline, date and time patterns appear under the `WordPress` group after visiting the front page.
- Add a text widget with a plain title, reload the front page, and confirm the title appears under `Widgets`. Translate it and confirm the translated sidebar heading. Add a second widget holding a link and confirm the link is untouched and never listed.
- Activate a plugin that ships a `wpml-config.xml` and confirm its declared post types and taxonomies appear on Settings > Content, that a `translate="0"` object disappears from that list, and that its `admin-texts` options appear under String Translation grouped by `plugins/<slug>`.
- Translate one of those options, view a non-default language on the front end, and confirm the plugin renders the translation while its own settings form still shows the original.
- Create a translation of a post carrying a declared `copy` field and a declared `ignore` field; confirm the first is present on the translation and the second is not. Enable custom-field synchronization, edit the source, and confirm `copy` fields follow while `translate` and `copy-once` fields keep the translator's value.
- Deactivate that plugin and confirm its declarations stop applying on the next request without clearing any cache.
- Confirm bulk, network, AJAX, cron, and CLI activation contexts do not trigger the setup redirect.
- Confirm the top-level LocalePress menu appears only for administrators.
- With an empty registry, confirm the setup form opens and the site locale preset fills all editable fields.
- Select another preset and verify locale, names, code, slug, and RTL update without submitting data automatically.
- Add a language with all fields and verify the first language is enabled and default.
- Add an RTL language and confirm its direction and native name display correctly.
- Attempt empty names, malformed locales/codes, and duplicate locale/slug values; confirm data is preserved with an error.
- Edit every field and verify the record keeps its identity and order.
- Add multiple languages, set a new enabled default, and verify the previous default can then be disabled.
- Confirm a disabled language cannot become default and the current default cannot be disabled.
- Move languages up and down, including first/last boundary buttons.
- Delete a non-default language, then delete the default and verify the next enabled language becomes default.
- Repeat each POST with a missing/invalid nonce and with a user lacking `manage_options`; confirm WordPress rejects it.
- Deactivate and reactivate; confirm the language registry remains intact and an already configured site is not sent through setup again.
- Change the stored plugin version to an older value and confirm the upgrade hooks run once and preserve languages.
- Check list and editor layouts at desktop and mobile admin widths.
- Open Posts, Pages, and a public custom post type; confirm Language and Translations columns appear.
- Open an older unassigned post and confirm it immediately displays the configured default without creating a stored group.
- Create a new post through the editor, REST API, or WP-CLI and confirm the default assignment is persisted after save.
- From both the list table and editor, click a missing-language plus icon and confirm the source is linked and the translated draft opens directly.
- Assign a language in the editor and confirm the list displays its uppercase language code.
- Create German and French translations from an English source; confirm all three editors show the same available translations.
- Confirm missing translations show `+`, existing translations show a check, and existing entries open the correct editor.
- Change a post to a language already represented in its group and confirm the original assignment remains with an error notice.
- Attempt translated-copy creation with an expired nonce, without source edit permission, and without the post type's create capability.
- Trash a translation and confirm its language reads as untranslated again while the row survives; restore it and confirm the translation returns on its own. Create a replacement while the old one is in the trash and confirm the new draft takes the slot, then restore the old post and confirm it comes back unlinked in its own language rather than in the default one.
- Permanently delete a non-source translation and confirm the remaining group is intact.
- Permanently delete the source and confirm another member becomes the source; delete the final member and confirm the group is removed.
- Assign content to a language and confirm the language cannot be deleted until all assignments are permanently removed.
- Register a public custom post type and confirm the generic UI works without custom metadata or taxonomy copying.
- With WooCommerce active, confirm products use only generic title/content/excerpt copying and no variation, stock, price, or product metadata behavior is present.
- Open LocalePress > Translations and confirm posts, pages, and each supported public CPT appear once per source group with one column per enabled language.
- Confirm completed translations show a check, missing slots show a plus action, and drafts show an edit action with the Draft status available to screen readers.
- Filter by content type, target language, Missing, Completed, and Draft; combine filters and confirm pagination preserves them.
- Search for a source title and then for text found only in a translated title; confirm the same source group is returned.
- Use each add/edit action and confirm it opens the correct linked translation without creating duplicate language slots.
- Change the per-page Screen Option, navigate multiple pages, and test an empty result set and a site with more than 1,000 source groups.
- Access the screen without the filtered LocalePress capability and attempt add actions without source edit or target creation permission; confirm access is rejected.
- Register a temporary bulk action through the Phase 9 filters and confirm invalid nonces fail and the action receives only source posts the user can edit.
- Register strings from a theme and an integration on `init`; load one request, then confirm both groups and originals appear under LocalePress > String Translation without a file scan.
- Search originals, groups, and keys; filter by group and enabled language, change the Screen Option, and confirm pagination preserves the filters.
- Save English and German values, switch frontend language prefixes, and confirm `localepress_translate_string()` returns the matching value while missing and empty values fall back to the original.
- Change a registered original in code and confirm the definition updates without deleting its saved translations. Register the same group/key repeatedly and confirm only one row exists.
- Submit HTML, an overlong value, an unknown string ID, a disabled/unknown language, an expired nonce, and a user without the management capability; confirm no partial or unauthorized mutation occurs.
- Delete a disposable language with stored string values and confirm its string-translation rows are removed while all definitions and other language values remain.
- Enable a persistent object-cache backend and confirm repeated retrievals return updated values after admin saves and do not issue one query per string.
- With Elementor inactive, create a normal post translation and confirm there are no notices, errors, or Elementor metadata writes.
- With Elementor active, create a page containing a Container plus Heading, Text Editor, Image, Button, and Icon widgets; translate it and confirm the translated draft opens in the Elementor editor with the same structure.
- Repeat with a legacy Section and Columns page where supported by the installed Elementor version.
- Open `wp-sitemap.xml` and confirm it is served unprefixed, then open a post sitemap and confirm each translation appears once under its own language prefix beside its source.
- Disable a language that has published content, reload the post and taxonomy sitemaps, and confirm that content is gone and no unprefixed duplicate of a default-language URL remains.
- Confirm content with no language assignment is still listed.
- Activate each supported SEO plugin in turn and confirm the page carries exactly one canonical, prefixed with the current language.
- Open a prefixed URL in a language whose WordPress translation files are installed and confirm theme strings, date formatting, and `<html lang>` all follow that language while wp-admin stays in the site language.
- Confirm a theme that prints `get_bloginfo( 'language' )` itself outputs the request language.
- Confirm REST, admin-ajax, cron, and WP-CLI requests keep the site locale.
- Enable browser language detection, clear the `localepress_language` cookie, and request the site root with an `Accept-Language` header naming a non-default enabled language; confirm one 302 to that language, a `Vary: Accept-Language` response header, and a preserved query string.
- Repeat the same request with a header naming only unregistered languages and confirm the visitor reaches the default language with no extra redirect.
- Reload the site root with the cookie present and confirm the remembered language is used without reading the header again.
- Switch to another language, then click the theme's home link, and confirm the internal referrer keeps the visitor in that language instead of re-detecting.
- Request `/de/about/` and any other prefixed URL with a conflicting `Accept-Language` header and confirm no redirect occurs.
- Submit a front page form and confirm the POST is not redirected.
- Disable browser detection and confirm the site root behaves exactly as before, with no cookie-driven redirect.
- Edit and save widget text/styles in the translated page, then confirm the source language's Elementor data and rendering are unchanged.
- Confirm the translated page retains Elementor Canvas/Full Width and page-level style settings while generating CSS for the translated post ID.
- Create a translation through `create_translation()` with `copy_content` disabled and confirm Elementor opens with an empty canvas but retains its page settings and template.
- Add stale generated metadata to a disposable target in a development environment and confirm CSS, page assets, usage, and element caches are absent after the copy and rebuilt on render.
- Open Categories, Tags, and a public custom taxonomy; confirm Language and Translations columns appear.
- Confirm older unassigned terms display the effective default, new terms persist it automatically, and plus icons create translations without a separate assignment step.
- Add and edit terms with each enabled language, then confirm invalid or missing LocalePress nonces leave assignments unchanged.
- Create German and French term translations and confirm every member shows Add/Edit Translation states correctly.
- Change a translated term to an unused language, then attempt a language already represented in its group and confirm the original assignment remains.
- Create a hierarchical source parent and child, translate the child first, then translate the parent and confirm the translated child adopts the translated parent.
- Delete a non-source term translation, the source term, and the final member in turn; confirm source repair and empty-group cleanup.
- Assign a term to a language and confirm that language cannot be deleted until all assigned terms are removed.
- Register a public hierarchical custom taxonomy and confirm the generic workflow works without copying term metadata.
- With WooCommerce active, confirm public product taxonomies receive only generic core term handling and no product-category or attribute-specific behavior.
- Pick one language in the admin bar filter and confirm Posts, Pages, the media library, and a translatable term list all show only that language, that navigating between those screens keeps the choice without a `lang` argument, and that Show all languages restores every row.
- While filtered, add a post and a term and confirm the language control starts on the filtered language; save a post without touching that control and confirm it is stored in the filtered language, not the site default.
- Delete or disable the language a user is filtering by and confirm their next admin screen falls back to showing every language.
- Confirm a second administrator's filter choice is independent, and that an editor without `edit_posts` never sees the menu.
- Confirm pretty permalinks are enabled, visit `/`, and verify one 301 redirect to the default language home such as `/en/`.
- Visit `/en/` and `/de/` with a posts front page; confirm each loop contains only that language and pagination remains prefixed.
- Configure a translated static front page and translated posts page; verify `/en/`, `/de/`, and both prefixed blog-page routes use native front/home conditionals.
- Visit a linked page or post at `/en/source-slug/` and `/de/source-slug/`; verify each route displays the correct translation.
- Visit the target post's stored slug under the target prefix and confirm WordPress canonicalizes to the shared source route where applicable.
- Remove one post translation and verify its missing language route returns 404 without redirecting or showing the source language.
- Visit translated category, tag, and public custom-taxonomy archives and verify each uses the translated term's own slug.
- Test post, CPT, author, and date archives, feeds, embeds, search, and page 2 under at least two prefixes.
- Generate post, page, CPT archive, term, search, and pagination links in a theme and confirm each keeps exactly one language prefix.
- Open a preview for source and translated drafts; verify the preview query parameters and assigned-language prefix remain intact.
- Request an unknown prefixed path and unknown language prefix; verify 404 responses do not redirect in a loop.
- Change an enabled language URL slug and confirm one soft rewrite refresh occurs and the old prefix stops resolving as a language.
- Disable pretty permalinks and verify LocalePress leaves core query-string URLs unchanged.
- With Polylang or WPML active, confirm LocalePress does not register a second frontend router or create nested language prefixes.
- Insert `[localepress_switcher]` on a translated post, page, CPT, term archive, front page, posts page, search, archive, pagination, and 404 view; confirm each target keeps the equivalent current context.
- On a translated page such as `/en/about/`, confirm the German item points to `/de/about/`, not `/de/`; remove the translation and test disabled, hide, language-home, and current-URL behavior.
- Insert the Language Switcher block and compare its frontend output with the editor controls for name/native name/code, horizontal/vertical/dropdown, current/missing visibility, and unavailable behavior. Confirm the editor preview links to the post's translations rather than to `/wp-json/`, and that the edited post's own language is marked current.
- Install a `localepress` locale for the admin language, reload the block editor, and confirm the inspector labels are translated alongside the PHP admin screens.
- Call `localepress_language_switcher()` and `localepress_get_language_switcher()` from a theme template and confirm both honor the configured defaults and the same explicit arguments as the shortcode.
- Add the LocalePress item under Appearance > Menus, save each setting, and confirm the virtual item renders switcher markup without an extra nested `<nav>` element.
- Enable a disabled language and test `show_disabled`; confirm it remains non-interactive. Provide test flag URLs through `localepress_switcher_flag_url` and confirm text labels remain accessible when images fail.
- Navigate the dropdown and all links using only the keyboard, verify visible focus, and inspect `aria-label`, `aria-current`, `aria-disabled`, `hreflang`, `lang`, and `dir` attributes.
- Disable pretty permalinks or activate Polylang/WPML and confirm switcher integrations return no output while LocalePress prefix routing is unavailable.
- Create separate English and German native menus, assign each language under LocalePress > Settings, select both for the same registered theme location, and confirm `wp_nav_menu()` chooses the current language's menu.
- Leave one location/language unconfigured and confirm WordPress's existing theme-location menu remains the fallback. Pass an explicit `menu` argument and confirm LocalePress does not replace it.
- Add linked page, post, CPT, category, tag, internal custom, external, fragment, nested, and language-switcher items. Confirm object links resolve to translations, same-site custom paths receive one prefix, and external/fragment/switcher URLs remain intact.
- Remove an object translation and confirm its menu item and descendants are hidden. Test `home`, `current`, and `preserve` through `localepress_menu_missing_translation_behavior`.
- Delete a language-specific menu and confirm its LocalePress theme-location assignment is removed. Attempt to assign one menu to two languages and confirm the settings are rejected without a partial save.
- Create a Gutenberg source containing Heading, Paragraph, Image, Gallery, Buttons, Columns, Cover, List, Table, Query, and synced-pattern reference blocks. Create a translation and confirm `post_content` and parsed block structure are identical.
- Disable source content copying under LocalePress > Settings and confirm new translated drafts have empty content and excerpt fields while preserving the relationship and manual editor workflow.
- Enable and disable featured-image reuse. Confirm enabled translations reference the same attachment ID, disabled translations have no thumbnail, and unrelated post meta is never copied.
- Edit a synced pattern referenced by multiple translations and confirm WordPress updates every reference; detach or create separate patterns when language-specific pattern text is required.
- Publish posts in two languages, then open a translated page carrying a Query Loop and a Latest Posts block. Confirm each lists only the requested language, that pagination links and the result count agree with the filtered list, and that a Query Loop set to inherit the template query still follows the main query. Confirm a Navigation block on the same page still renders.
- Give a source post a public custom field, a multi-value custom field, a protected `_`-prefixed field, a non-default page template, a category, and a parent page. Create a translation and confirm the public fields, page template, and mapped category arrive, the protected field does not, and the parent resolves to the parent's translation.
- Clear a public custom field on the source, create a second translation, and confirm the field is absent rather than stale on the new draft.
- Turn on **Keep synchronized** for Custom fields and Comment status, edit the German post, and confirm the English post follows. Turn both off and confirm a later edit changes nothing.
- Sign in as an editor who cannot edit one language's posts, then try to change a synchronized custom field on a post they can edit. Confirm the write is refused and no translation is modified.
- Add `wp_block` through `localepress_supported_post_types`, select it on the Content tab, translate a synced pattern, and confirm each language can edit its own copy without affecting the other.
- Register a custom post type and a custom taxonomy, then confirm neither shows a language control or translation action until it is selected on the Content tab, and that a non-public post type never appears in either list.
- Turn on media translation, translate an image, and give each language its own title, alternative text, caption, and description. Confirm both records report the same file path, that the uploads directory gained no second file, and that the media library shows one row per language.
- Set that image as a featured image on a source post, create a translation, and confirm the translated post uses the translated attachment while the source keeps its own.
- Delete one language's media record and confirm the file survives for the remaining languages and the group loses only that language. Delete the last remaining record and confirm the file and its generated sizes are removed.
- Turn media translation back off and confirm existing translated posts still render, since an attachment without a translation resolves to itself.
- Edit a German post in the block editor. Confirm the parent page dropdown, the category and tag panels, and the link dialog offer German content only, and that switching the language in the Language box updates the next request without a reload.
- Open a brand new post before choosing a language and confirm those lists show the default language rather than every language at once.
- Confirm a REST request made without a `lang` parameter, such as one from another plugin, still returns every language.
- Insert the Language Switcher into a Navigation block in a block theme. Confirm it inherits the menu's colors, spacing, and mobile overlay, that dropdown mode renders a submenu, and that flags appear next to the labels when enabled.
- Inspect English, German, and RTL pages and confirm `<html lang>`, `dir`, and the `rtl` body class match the URL language without changing the dashboard locale.
- Inspect a linked post, page, public CPT, category, tag, and public custom taxonomy. Confirm hreflang includes the current public URL and each public translation exactly once, with `x-default` pointing to the default-language equivalent.
- Draft, private, trash, or delete one translation and confirm its hreflang entry disappears without replacing it with a language home URL. Confirm a one-language singular emits no LocalePress hreflang set.
- Check the posts homepage, a static translated front page, CPT/author/date archives, and archive page 2 in two languages. Confirm equivalent alternate and canonical URLs retain one prefix and pagination.
- Visit search, preview, feed, embed, and 404 URLs. Confirm LocalePress emits no hreflang or canonical links and previews/404s have a noindex robots directive.
- With no SEO plugin active, confirm one singular canonical comes from WordPress core and one archive canonical comes from LocalePress.
- Activate Yoast SEO, then Rank Math separately. Confirm exactly one canonical remains, uses the current prefix, and LocalePress hreflang remains present; test an intentionally empty and an external custom provider canonical and confirm LocalePress preserves it.
