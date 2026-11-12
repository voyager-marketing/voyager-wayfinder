# AI context format, v1

The contract for what Wayfinder hands to AI agents. This file is canonical for the bytes.

## Goals

1. Answer most questions about how a site is built from the brief and index alone.
2. Cost as few tokens as possible, and the same tokens every time the site has not changed.
3. Let an agent fetch more only when it needs it.

## Tiers

| Tier | What | Budget on the fixture | Format |
|---|---|---|---|
| brief | site identity, content counts (banded), top edit locations, note count, what else exists | 2,400 chars (about 600 tokens) | compact text |
| index | every item, one line each | 16,000 chars (about 4,000 tokens) | compact text |
| section | full detail for one section or item | none | JSON |
| find-edit-location | URL and optional text or class in, source chain and edit path out | 3 hits and 3 chain entries unless `detail` is `full`: about 3,000 chars (about 800 tokens) | JSON |
| changes-since | manifest hash in, changed item ids out | none | JSON |

Token figures from chars/4 are estimates and are labeled as such. The benchmark reports real counts.

## Item ids

| Prefix | Thing | Example |
|---|---|---|
| `pt:` | post type | `pt:service` |
| `tax:` | taxonomy | `tax:service_area` |
| `field:` | registered meta or ACF field, `<post_type>.<key>` | `field:service.price_from` |
| `tpl:` | template | `tpl:single-service` |
| `part:` | template part | `part:footer` |
| `pat:` | synced or registered pattern, by slug | `pat:cta-banner` |
| `nav:` | navigation menu, by slug | `nav:primary` |
| `loc:` | classic theme menu location, by location slug | `loc:primary` |
| `sidebar:` | classic theme sidebar (widget area), by sidebar id | `sidebar:sidebar-1` |
| `bind:` | binding source and key | `bind:vwf-fixture/business-info.phone` |
| `route:` | URL pattern | `route:single-service` |
| `plugin:` | active plugin, by folder slug; with its role in `facts.role` | `plugin:advanced-custom-fields` |
| `opt:` | allowlisted site setting, by option name | `opt:show_on_front` |
| `optpage:` | ACF options page on a classic theme, by menu slug | `optpage:theme-settings` |
| `core:` | WordPress core | `core:wordpress` |
| `gates:` | editor controls theme.json, the theme and the user's global styles turn off; one item per site | `gates:theme` |
| `btpl:` | a page builder's own template (Elementor library, Divi layout, Bricks template), by post slug | `btpl:site-header` |
| `rest:` | REST route a theme, plugin or mu-plugin registers, without its leading slash | `rest:voyagermark/v1/contact` |
| `filter:` | theme, plugin or mu-plugin callback on a head hook, `<hook>@<callback>` | `filter:rank_math/opengraph/facebook/og_title@voyagermark_og_title` |

Use slugs. Fall back to a row id only where WordPress has no slug, and prefix it `#`. The same goes for values: `opt:page_on_front` and `opt:page_for_posts` carry the page slug, not its id.

Plugin roles are `ai`, `analytics`, `backup`, `builder`, `cache`, `ecommerce`, `email`, `fields`, `forms`, `hosting`, `multilingual`, `security`, `seo`, and `other` for a plugin `data/plugin-roles.json` does not list.

## Compact text grammar

```
# wayfinder v1 <tier> h:<first 6 hex of manifest hash>
<key>: <value> | <value> | ...
<id> | <label> | <key facts> | <edit path> | <refs, comma separated>
```

- One item per line in the index, sorted by id.
- Edit paths are relative to wp-admin (`edit.php?post_type=service`) or a short Site Editor trail when no stable URL exists.
- Templates, parts and menus use the Site Editor's own routes, which open the item: `site-editor.php?p=/wp_template/<theme>//<slug>&canvas=edit`, `site-editor.php?p=/wp_template_part/<theme>//<slug>&canvas=edit` and `site-editor.php?p=/wp_navigation/<id>`. The older `postType=&postId=` form still loads in WordPress 7.1, but it redirects to the Templates or Patterns list instead of the item.
- Omit empty columns from the end of a line, never from the middle. An empty middle column is printed as `-`.
- A `|` inside a value becomes `/`, and any run of whitespace, newlines included, becomes one space, so a value can never break a line.
- Key facts are a few facts per kind, joined with `; `: count bands, admin-only, REST visibility, archive path, field keys and `defined in` for a post type; terms, object types and `defined in` for a taxonomy; type, source, REST visibility and an ACF field's sub fields or layouts for a field; a template's or part's file (`file themes/vwf-fixture/templates/single-service.html`, or `file from <theme>` when the file is not known), what it overrides and, for a classic PHP file, its `reads`, its links as `hard codes links /contact/ in the file (no field or setting changes them)` and, for a template, `never shows the post's own title or content` when it prints neither (see "Files and reads"); a registered pattern's file and the options it reads, grouped by the screen that edits them (`registered; file themes/voyagermark/patterns/site-phone.php; reads option:voyager_site_phone (Phone Number), edited at options-general.php?page=voyager-blocks&tab=site-data`, with `empty now` beside a read that is empty and `reads option:acme_promo, edit screen not traced` for the rest); who can open a template, part, synced pattern, menu, binding, menu location or sidebar when the client role cannot (`editor cannot open: needs edit_theme_options`), then, apart from it, what a block template's, part's or synced pattern's layout allows (see "Who can open it, and what its lock allows"); what a binding binds, `reads`, `defined in` and the settings saved beside it that nothing reads; `used in` for parts, patterns, menus and bindings; the pattern (or fixed path) and template for a route; the assigned menu and its item band for a menu location (`menu Main; 1-5 items`, or `no menu assigned`); the widget band for a sidebar; the fields and ACF groups of an options page; a builder template's builder, type and `content not read` (`Elementor template; type header; content not read`); a REST route's methods, handler and file, who may call it and, when traced, what it does (`POST voyagermark_contact_handle in themes/voyagermark/inc/contact-lead.php; public; records an Orbit lead; mails the address in constant VOYAGERMARK_CONTACT_NOTIFY (declared in themes/voyagermark/inc/contact-lead.php) through plugin:postmark-approved-wordpress-plugin; fires voyagermark_contact_submit (nothing listens)`); a head filter's change, route, callback and file (`changes og:title on route:front-page (is_front_page()); voyagermark_og_title in themes/voyagermark/functions.php; returns fixed text typed into that file; applied by plugin:seo-by-rank-math`), see "Code section"; a route's scalar facts, `builder` and `coverage` among them on a builder page; each page builder's published band on a post type (`6-20 Elementor documents`). Everything else is in the section tier. See "Files and reads".
- `used in` names at most 5 layout containers the reader can see, then `+N` for the other layout containers (`N folded items` when none is listed), then the content that uses the item as a band: `used in tpl:single-service, content 1-5`. Content is never named in the brief or the index; the section tier has the list.
- Agency notes appear as `note: <text>` lines indented under their item, with `(needs review)` when the item changed after the note was written.

### Count bands

The brief and the index print every count of content as a band: `0`, `1-5`, `6-20`, `21-100`, `100+`. That covers published, draft and private posts, terms, menu items and content usage. Their bytes do not change while a count moves within its band, so publishing a seventh service (6 to 7, both `6-20`) leaves them, and the prompt cache, alone. Nothing else in them depends on an exact count: a rule that keys on one (such as which synced patterns earn an edit line) uses the bottom of a band. Counts of structure, such as a post type's fields or the layout items the brief lists, stay exact. The section tier prints exact stats.

### Brief

Lines in this order, each omitted when it has nothing to say: header, `site:`, `content:` (post types with their published band, each page builder's published documents as a band, and up to 3 field keys, custom types first, `admin-only` where there is no public URL: `Pages 6-20, Elementor 6-20`), `terms:` (custom taxonomies first; empty built-in ones left out), `layout:` (counts, and how many items the index folds), `locks:`, `builders:`, `plugins:`, `gates:`, up to 8 `edit:` lines, `notes:`, `more:`.

`builders:` names each page builder that owns something on the site, by slug order, with its published documents across post types as a band and its `btpl:` templates as an exact count, then says what Wayfinder does not do: `builders: Elementor 6-20 documents, 2 templates | content not read; edit in the builder`. It is omitted when no builder owns a document, a template or a route's page. Like `locks:`, it always stays when the brief overflows. See "Builders".

`locks:` says what the layout of the first header part and the first footer part allows, the ones the edit lines name, header first, from their `lock` facts. Parts that read the same share one entry: `locks: part:header, part:footer: not locked at the edit path, content only inside a template (WordPress 7 default)`. A part with locked blocks reads `2 locked blocks at the edit path, strictest all (template_lock)`. The line never says who can open a part: that is on its edit line. It is omitted when neither part carries a lock fact, as on a classic theme. Like `layout:`, it always stays when the brief overflows.

`gates:` names, comma separated, each control the theme or the user's global styles turn off, then whether appearanceTools is on, how many per-block overrides there are and which post types limit their blocks: `gates: custom colors off, custom font sizes off, appearanceTools on`. It is omitted when nothing is off or limited. See "Theme gates".

`edit:` lines are ranked: custom post types, then the header and footer parts, then bindings and ACF options pages, then synced patterns in use (higher usage band first), then menus and the classic menu locations that have a menu. Within a rank, by id. Custom post types with nothing published get no line, and the rest take at most 3 lines, higher published band first. Only the first header part and the first footer part get one. So on a site with many post types or parts, the bindings still get a line. A binding's line names what it reads before where it is used: `edit: bind:vwf-fixture/business-info.phone | Business info: phone | reads option:vwf_phone; in part:footer; editor cannot open: needs manage_options | options-general.php?page=vwf-business-info`. Any edit line whose screen the client role cannot open says so, as there. The first binding line of a source also names the settings saved beside it that nothing reads: `the same settings also hold vwf_hours (Hours), which no block or theme file reads, so it shows nowhere`.

### Index folding

A child theme inherits every template and part its parent ships, and those pull in the parent's registered patterns. The brief and the index list:

- a template or part served from the parent theme's file only when a listed item refs it (a route refs its template) or published content uses it,
- a registered pattern only when a listed item refs it or published content uses it,
- a plugin's REST route only when a listed item refs it. The theme's and mu-plugins' routes, and every head filter, are always listed.

Everything else becomes one line per kind at the end of the index, e.g. `tpl: +8 unused from twentytwentyfive (section layout)`, `pat: +13 unused registered (section layout)` or `rest: +40 from plugins (section code)`. The manifest and the section tier keep every item.

When the index lists any binding, a closing line after the fold lines states the limit on them: `bind: keys are known only from blocks that use them, so a source may serve more; settings saved beside them that nothing reads are named on the source's first binding line`. It is kept when the index overflows. Those settings (`unread_settings`) are named once per source, on the line of its binding that sorts first, in the index as in the brief.

### Budgets

The brief must fit 2,400 characters and the index 16,000. On overflow the brief gives up detail in this order, stopping as soon as it fits: field names per post type go from 3 to 1 to none, then the `content:`, `terms:` and `plugins:` lines keep fewer entries and end in `+N more`, then the `gates:` line does the same, then edit lines go from the end. The header, `site:`, `layout:`, `locks:`, `builders:`, `notes:` and `more:` lines always stay. On overflow the index gives up detail in this order, stopping as soon as it fits: drop the refs column, collapse post type and options page field lists to a count (`3 fields`), then drop items from the end and print `+N more in section <name>` for each section that lost items. `wp wayfinder stats` prints `tier,chars,est_tokens`; `est_tokens` is characters / 4, rounded up, and is an estimate.

## Manifest JSON

`wp wayfinder scan --format=json` prints the manifest envelope:

```
{"body":{"schema_version":1,"sections":[<section>, ...]},"header":{"generated_at":..,"plugin_version":..,"site_url":..},"manifest_hash":"<sha256 hex>","stats":{"<item id>":{..}, ...},"stats_hash":"<sha256 hex>"}
section: {"hash":..,"items":[<item>, ...],"name":"site"}
item:    {"edit":"<path>"|null,"facts":{..},"hash":..,"id":"opt:blogname","kind":"option","label":"Site title","refs":[<id>, ...]}
```

- `manifest_hash` is sha256 of the canonical JSON of `body`. On the stub `site` section, `jq -jcS .body | sha256sum` reproduces it; jq may format floats differently, so PHP's `CanonicalJson` is the reference.
- A section hash covers its `name` and `items`; an item hash covers every item field except `hash`.
- `refs` are sorted and unique. `facts` is always an object, `{}` when empty.
- The CLI pretty prints. Hashes are always taken over the compact form.

### Stats

Structure is hashed, volatile content is not. A stat is any count of content, any list of content that uses an item, and the post a route samples. They sit in `stats`, keyed by item id, beside `body` and outside every hash:

| Item kind | Stats |
|---|---|
| post type | `counts`: posts by status (`draft`, `private`, `publish`, and `inherit` for media); `builder_docs`: published posts each active page builder owns, by builder slug, only when one owns any (see "Builders") |
| taxonomy | `terms` |
| template part, pattern, binding | `usage_count`, `used_in` |
| registered pattern | `empty_reads`: the `option:` reads of its file that are unset or empty now, only when there are any (see "Files and reads"). Never the value |
| menu | `item_count`, `usage_count`, `used_in` |
| menu location | `item_count` of the assigned menu, when there is one |
| sidebar | `widget_count` |
| `route:single-<post_type>` | `example`: the sampled post's `path`, `content_source` and `refs`, `template` when it differs (see "Routes section"), and `builder` when a page builder owns it |
| REST route | `lines`: one entry per endpoint, `handler` (`themes/voyagermark/inc/contact-lead.php:126-216`) and, when traced, `calls`: where each effect is called (`mail`, `record`, `post`, `redirect`, as `file:line`) and where each constant recipient is declared (`constants`). See "Code section" |
| head filter | `lines`: `callback`, as `file:start-end` |

- `stats_hash` is sha256 of the canonical JSON of `stats` (`{}` when there are none). `wp wayfinder scan --refresh` prints it next to the manifest hash. It is never used for the ETag, notes or change history.
- Facts never carry a count of content or a sampled post, and a post type's `summary` carries no number ("Services. Fields: duration (text), internal sku (text, not in REST), price from (number). Grouped by service area."). Renderers add the numbers from stats.
- Plugin, theme and WordPress versions stay in facts and stay hashed.

## ACF fields

An active ACF field group gives a `field:<post_type>.<key>` item for each of its top-level fields on each post type it is located on. Layout fields (`accordion`, `message`, `tab`) store nothing and get no item. When registered meta and ACF describe one key, the registered meta entry stays.

- A group belongs to every post type a `post_type ==` rule names, and to `page` for a `page_template ==` rule, since page templates belong to pages: a group located on `page-templates/landing.php` gives `field:page.page_sections`. Any rule but `post_type ==` keeps the whole location, one line per OR group, in the `location` fact (`["page_template == page-templates/landing.php"]`), because it narrows which posts the field shows on.
- A repeater or group field carries `sub_fields`, the names of its sub fields, and a flexible content field carries `layouts`, the names of its layouts. Both are sorted and present only when non-empty, so every other field's facts are unchanged. Sub fields of a layout and deeper nesting are not covered.
- The index line reads `repeater; ACF group Project details; sub fields phase_name, phase_weeks` or `flexible_content; ACF group Landing page; layouts cta_band, hero`.
- A group located on an options page names its fields on the `optpage:` item instead (see "Classic themes").

## Theme gates

The `site` section carries one `gates:theme` item (kind `gates`, label "Theme gates") whenever WordPress has global settings. It records which editor controls are turned off, so "I cannot change the color" can be answered with "the theme allows the palette only" instead of a search. It reads `wp_get_global_settings()` and nothing else for settings, and records on or off only: never a preset, palette or token value.

| Fact | Value |
|---|---|
| `settings` | every tracked setting as `path => bool`, true when the control shows: `appearanceTools`, `border.color`, `border.radius`, `border.style`, `border.width`, `color.custom`, `color.customGradient`, `color.defaultPalette`, `layout.contentSize` and `layout.wideSize` (set or not), `spacing.blockGap`, `spacing.margin`, `spacing.padding`, `typography.customFontSize`, `typography.fontFamilies` (any font family preset) |
| `gates` | the settings that are off, `path => origin` |
| `block_overrides` | per-block settings (`settings.blocks.<name>`) that set a tracked path to something other than the top level, `{"block":..,"origin":..,"setting":..,"value":bool}`, sorted by block then setting |
| `allowed_blocks` | each public post type the block editor opens, `post_type => "all"` or how many blocks `allowed_block_types_all` allows for a new post of that type (`0` when it returns false) |

- Core expands `appearanceTools` into the settings it turns on and then unsets it, so `wp_get_global_settings()` never returns it as true. `settings.appearanceTools` is therefore true when the raw value is true or when `border.color`, `border.radius`, `border.style`, `border.width`, `spacing.margin` and `spacing.padding` are all on, and its origin is `theme` when the theme declares `appearanceTools` or turns one of them off.
- A missing or null value is off, as core reads it. `spacing.blockGap` null (no gap support) and false (no control) are both off.
- Origin is `user` when the user's global styles change the value (`wp_get_global_settings()` differs from `wp_get_global_settings([], ['origin' => 'base'])`), `theme` when the theme declares the setting (its theme.json, its parent's, or a theme support such as `disable-custom-colors`), else `default` (core's theme.json, or a block or plugin layer). What the theme declares comes from `WP_Theme_JSON_Resolver::get_theme_data()` behind a `method_exists()` guard; without it every non-user gate reads `default`.
- The edit path is `site-editor.php?p=/styles&canvas=edit` when any gate or block override has origin `user`, else null: a theme gate is changed in the theme's files.
- The index line reads `appearanceTools on; custom colors off (user), default palette off (theme); block overrides core/button: color.custom: off; allowed blocks service 2`, or `no controls turned off`.

## Layout section

`layout` holds templates (`tpl:`), template parts (`part:`), patterns (`pat:`), menus (`nav:`) and block bindings (`bind:`), on a classic theme menu locations (`loc:`) and sidebars (`sidebar:`), and with an active page builder that keeps templates the builder's templates (`btpl:`). See "Classic themes" and "Builders".

- `refs` are direct. A template that includes the footer refs `part:footer`, not the footer's menu or bindings.
- `pat:` covers synced patterns (`wp_block`, slug from `post_name`) and registered patterns. A registered pattern is listed only when something references it. Facts `synced` and `registered` tell them apart. A registered pattern also carries `file` and `reads`, and `read_screens` when it reads an option whose screen is known (see "Files and reads").
- `bind:<source>.<key>` takes the key from `args.key`, else the first arg value. A binding with no args is `bind:<source>`. A `core/post-meta` binding refs `field:<post_type>.<key>` for each post type that registers the key.
- Parts, patterns, menus and bindings carry `used_in` and `usage_count` in their stats, and menus `item_count`. `used_in` lists layout ids first, then published content as `<post_type>:<slug>` (e.g. `page:about`), each group sorted, capped at 50. `usage_count` is the full total.
- A ref to a template, part, pattern or menu that does not exist moves to the `missing_refs` fact. An unresolved row id keeps its `#`, e.g. `pat:#999`.
- Templates and parts carry `file_theme`, the theme whose file WordPress serves (the parent theme for an inherited one), and `overrides`, the parent theme when a child theme ships its own copy of a file the parent also has. Both are theme slugs and null when they do not apply (Site Editor or plugin templates). WordPress reports every theme file under the active stylesheet, so these come from checking the theme folders the way core does, child first. `file` is that theme file's path (see "Files and reads").
- Bindings carry `defined_in` and `reads` (see "Files and reads"). Their keys are known only from usage: a key no block binds has no item, so the list is never every key a source serves. What the source saves is known when it reads an option registered with `register_setting()`: a binding then carries `unread_settings`, the other options of that settings group that no binding and no classic theme file reads, as `[{"label":"Hours","option":"vwf_hours"}]` sorted by option (`label` is the one registered, null without one). Core's own groups (`general`, `reading` and the like) are never read for it. It is present only when not empty. So "set up but shown nowhere" is answered without crawling.
- Labels are English, so a site gives the same bytes in any language. Block templates and parts are read with WordPress switched to en_US, which makes core's default template titles English. Core's binding sources (and, in `content_model`, its post types and taxonomies) take the English label from the fixed map in `EnglishLabels` while their label is the one core gives them. A label a theme or plugin registered, or changed on a core type, is recorded as registered.
- Block templates, parts and synced patterns carry a `lock` fact, `{"effective":..,"locked":<n>,"src":..}`: the strictest lock any of their blocks is under at the item's own edit path, its source, and how many blocks are locked at all (a count of structure, so a fact). A part on WordPress 7.0 or later adds `"in_template":"content_only"`, what a template that places it makes it. A classic PHP file, a menu and a registered pattern have none. See "Locks".
- Beside it, `locked_blocks` lists each locked block in parse order, at most 50 (`locked` counts them all): `{"block":"core/paragraph","effective":"all","name":"Renewal terms","path":"3.0","src":"template_lock","text_editable":true}`. `name` is the List View name (`metadata.name`), null without one. A block that sets `templateLock` locks its children, not itself, so the group is not in the list and its children are.
- Every layout item with an edit path has `stats.client`, `{"can_open":bool,"needs":"edit_theme_options","role":"editor"}`: whether the client role (`vwf_client_role`, default `editor`) has the capability that path needs (the table find uses, see `client_can`). It is a stat, outside the hash, because it follows the site's roles and not its structure: changing the client role moves no item hash, so no anchor or note goes stale over it. A settings page added with its own capability reads that capability in wp-admin, where the scan on `admin_init` runs, and the screen's default (`manage_options` for `options-general.php`) from WP-CLI and REST, as find does.
- The same block templates, parts and synced patterns carry the selector-like attributes a client never sees in the editor, counted in the same walk:
  - `classes`: each `className` token (split on whitespace, lowercased) with how many blocks carry it, sorted by class, e.g. `[{"class":"hide-mobile","count":2},{"class":"is-style-outline","count":1,"style":"outline"}]`. A style variation's class also gives its slug as `style`.
  - `block_names`: each `metadata.name` (the name a block is given in List View) with its count, sorted.
  - `hidden_classes`: true when any class is not a style variation, so nothing in the editor shows it. The index and the Wayfinder page then say "carries hidden CSS classes".
  - At most 50 classes and 50 names are listed; `classes_more` counts the rest and is present only then. Pointer blocks count too: their class sits in the container's markup.

## Locks

Whether a block can be moved, removed or rewritten in the editor. Resolved in the walk that already reads each container, from these sources in order:

1. The nearest ancestor `templateLock` (`all`, `insert`, `contentOnly`; `false` clears an inherited `all` or `insert`), else for post content the post type's `template_lock`.
2. `contentOnly` above a block is final: nothing below loosens it.
3. The block's own `lock.move` and `lock.remove`, when set, replace the inherited value (the 5.9 table: `remove: true` cannot remove anywhere; unset cannot remove under `all` and `insert`; `move` unset cannot move under `all` only).
4. From WordPress 7.0, unsynced pattern instances (a registered pattern placed with `core/pattern`, or an inlined block carrying `metadata.patternName`) are content only. The `disableContentOnlyForUnsyncedPatterns` editor setting, as filtered by `block_editor_settings_all`, turns that off.
5. From WordPress 7.0, a template part is content only where a template places it, unless the `disableContentOnlyForTemplateParts` editor setting is on. At its own edit path it is not: the editor opens a part, a synced pattern or a menu on its own (isolated), and there neither this rule nor rule 4 applies (`isIsolatedEditorKey` in core's `editor.js` and `isSectionBlockCandidate` in `block-editor.js`, read on 7.1.2). So a part's `lock` fact and find's locks on it are read from an unlocked root, which shows any lock its own blocks carry, and the part's fact adds `in_template` for this rule. Before 0.8.0 the fact recorded every 7.x part as `content_only` (`wp7_default`), which hid the part's own locks and gave the wrong reason for "can the client change the footer".

A lock object is `{"effective":..,"src":..,"text_editable":bool}`.

| Field | Values |
|---|---|
| `effective` | `none`, `move` (cannot move), `remove` (cannot remove), `insert` (can move and remove, nothing can be added beside it), `all` (cannot move or remove; its text still changes), `content_only` (only the text changes), least strict first |
| `src` | `block` (its own `lock`), `template_lock` (an ancestor's `templateLock`), `post_type` (the post type's `template_lock`), `wp7_default` (rules 4 and 5), `none` |
| `text_editable` | the block has an attribute with `role: content` in the block type registry (`__experimentalRole` before 6.7) that no binding fills; with no role registered, it shows text of its own or has a text attribute (`content`, `label`, `alt` and the like) |

The `lock` fact on a layout item swaps `text_editable` for `locked`, the count of blocks whose `effective` is not `none`, and on a part adds `in_template` (rule 5).

### Who can open it, and what its lock allows

The brief, the index and find keep two questions apart, and always in this order:

1. **Who can open it.** Whether the client role can reach the edit screen at all (`stats.client` on a layout item, `client_can` and `client_can_why` in find). A role that cannot is told the capability, `editor cannot open: needs edit_theme_options`, and never the lock as the reason.
2. **What its layout allows once open.** The lock mode and its effect in plain words: `all (template_lock): text can change, cannot be moved or removed`, `content only (template_lock): text can change, cannot be moved, removed or restyled`, `not locked at its edit path`, `content only inside a template (WordPress 7 default)`.

In the index a block template, part or synced pattern names its locked blocks grouped by lock, at most 5 names a group: `locked blocks Plan name, Plan price, Plan inclusions (content only, template_lock: text can change, cannot be moved, removed or restyled) and License notice (all, block: text can change, cannot be moved or removed)`. A block without a List View name is `<type> at <path>` (`paragraph at 2.1`). The role is named as able to open an item only when there is a lock to state beside it.

## Files and reads

These facts say where a thing lives in code, so an agent can answer "which file", "which option" and "which link" without opening the code. They are structure, so they are hashed.

| Fact | On | Value |
|---|---|---|
| `file` | `tpl:`, `part:` | the theme file, e.g. `themes/vwf-fixture/templates/single-service.html` |
| `file` | registered `pat:` | the theme file core builds the pattern from, e.g. `themes/voyagermark/patterns/site-phone.php` |
| `reads` | registered `pat:` | what including that file reads, as `option:<name>` and `meta:<key>`, sorted, e.g. `["option:voyager_site_phone"]`; `[]` when it reads nothing |
| `read_screens` | registered `pat:` | where each `option:` read is edited, for the options Wayfinder can name, in read order: `[{"edit":"options-general.php?page=voyager-blocks&tab=site-data","label":"Phone Number","read":"option:voyager_site_phone"}]`. Present only when not empty |
| `defined_in` | `pt:`, `tax:`, `bind:` | the file that registers the type or taxonomy, or defines the binding source's value callback, e.g. `mu-plugins/vwf-fixture-core.php`; `core` for WordPress's own |
| `reads` | `bind:` | what the source reads for the key, as `option:<name>` and `meta:<key>`, sorted, e.g. `["option:vwf_phone"]` |
| `reads` | classic `tpl:`, `part:` | what the PHP file reads, as `acf_option:<key>`, `acf:<key>` and `option:<name>`, sorted, e.g. `["acf_option:company_phone"]` (see "Classic themes") |
| `links` | classic `tpl:`, `part:` | the paths the PHP file hard codes through `home_url()`, sorted, e.g. `["/contact/"]`. No field or setting changes them; the index says so |
| `shows_post` | classic `tpl:` | what the template and every part it includes print of the post it is served for, sorted: `title` (`the_title()`, `single_post_title()`, `get_the_title()` with no argument), `content` (`the_content()`, `get_the_content()` with none), `excerpt` (`the_excerpt()`, `get_the_excerpt()` with none). `[]` for a landing page template built from fields. A getter given an argument reads another post and does not count, so a theme that passes the current post's id to it reads as not printing it |

- Paths are relative to wp-content, never absolute. Plugins, mu-plugins and themes keep their `plugins/`, `mu-plugins/` and `themes/` prefix even when a site moves those folders. A file outside all of them (or reached through a symlink, which PHP resolves) gives null, never a guess.
- `file`, `defined_in` and a binding's `reads` are null when they are not known; a classic file's `reads` and `links` are empty lists when it has none. In the brief and the index a null or empty fact prints nothing.
- `defined_in` for a post type or taxonomy comes from the `registered_post_type` and `registered_taxonomy` actions, which Wayfinder hooks while its plugin file loads. The first stack frame outside wp-includes, wp-admin and Wayfinder is the file. Types registered before Wayfinder loads (in an mu-plugin's body rather than on `init`) get null. Types WordPress registers itself (`_builtin`) are `core`.
- `defined_in` for a binding comes from reflecting on the source's value callback. Core's sources (`core/post-meta`, `core/pattern-overrides`, ...) are `core`.
- `reads` comes from asking the source for its value once while scanning, as the attribute that sorts first in `bound`, with the args whose canonical JSON sorts first and no post as context. The `pre_option` and `get_post_metadata` filters record what the source's own code reads: a read made from wp-includes or wp-admin, or from a filter running inside a core function, does not count. Those say nothing about the source, and they differ between surfaces (WP-CLI filters `home_url` and reads `home` itself; REST does not). The value is thrown away and anything the callback prints is discarded.
- `core/post-meta` is not evaluated: it refs its `field:` items instead, and its `reads` is null. A source whose callback throws, or that is not registered, keeps its item with `reads` null.
- A registered pattern's `file` is found the way core registers theme patterns, from the `patterns/` folder of the active theme and then its parent (`WP_Theme::get_block_patterns()`), never from the pattern registry, which forgets a file once its content is built. A pattern a plugin registers, or one registered with inline content, has `file` and `reads` null.
- Its `reads` come from including that file once per request, as core does to build the content, with the output discarded and the same filters as a binding's. A read counts when it is made below that include with no core frame between, so a theme helper the file calls counts (`voyagermark_site_phone()` reading `voyager_site_phone`) and `home_url()` reading `home` does not, on every surface. A file that declares a named function, class, interface, trait or enum is never included, because core includes it too and a second declaration is fatal: its `reads` is null. A meta read needs a post, and none is given, so most patterns show only `option:` reads.
- `read_screens` names a screen only where Wayfinder can derive it, never from an option's name: an option on the allowlist (its core screen, `options-general.php` for `blogdescription`), or a Site Data field of whichever plugin owns the `voyager/site-data` source, asked when the scan runs: voyager-blocks first (the fields `VoyagerBlocks\SiteDataBindings\get_settings_fields()` lists, at `options-general.php?page=voyager-blocks&tab=site-data`), else the voyager-open-blocks pack (the fields `VoyagerOpenBlocks\SiteData\fields()` stores in an option, at `options-general.php?page=voyager-site-data`), each with the field's label read in English. With neither plugin active no screen is named. The `voyager/site-data` binding's `edit` is that same screen. Any other option is named with `edit screen not traced`. Whether a read option is empty now is the `empty_reads` stat, so setting it moves no hash; its value is never kept or printed.
- A source that reads post meta through its block context reads nothing here, because no post is given. It can state its reads with the `vwf_binding_meta` filter: `apply_filters('vwf_binding_meta', [], $source, $key)` may return `reads` (a list of `option:<name>` and `meta:<key>`, anything else dropped) and `edit` (a wp-admin path). Each one returned replaces what Wayfinder would find: `reads` is then not traced, and `edit` wins over the `vwf_binding_edit_path` filter.
- Line numbers are never hashed. They move with every edit above them. The code section keeps them in `stats.lines`, outside every hash.

## Routes section

`routes` holds one `route:` item per kind of URL, sampled rather than listed: `route:front-page`, `route:posts-page`, `route:single-<post_type>` (one route per post type, shown through the published item whose slug sorts first), `route:archive-<post_type>` and `route:page-<slug>` for each page with a non-default template.

- A `route:single-<post_type>` item describes every post of its type, so it hashes only what holds for all of them:
  - `pattern`: the URL shape with placeholders, e.g. `/services/{slug}/`. It is built from the permalink structure and the post type's rewrite slug and `with_front`, the way core builds permalinks, never from a post. Structure tags become placeholders (`/{year}/{monthnum}/{slug}/`), hierarchical types use `{path}` (`/{path}/` for pages), and plain permalinks or a type with rewrites off give the query form (`/?p={id}`, `/?page_id={id}`, `/?service={slug}`). Filters such as `post_type_link` are not applied.
  - `template`: the template the type is served with, from the type's candidates alone (`single-<type>`, `single`, `singular`, `index`; `page`, `singular`, `index` for pages), without the per-post ones.
- The sampled item is a stat, `stats.example`: its `path`, its `content_source`, `refs` (the ids only its content reaches, sorted), and `template` when the item itself is served with a different one (an assigned template or a `single-<type>-<slug>` file). Publishing a post that sorts first changes `stats_hash` and nothing else. The brief and the index print the pattern; the section tier shows the example.
- Every other route keeps a fixed path, hashed. Facts: `path` (relative URL), `template` (the slug WordPress serves, or null), `content_source` (`page:<slug>` for a page, `query:<post_type>` for a listing). The front page and the posts page come from Settings > Reading, and `route:page-<slug>` from the page's template.
- The template is found by passing core's template hierarchy candidates for the route, in loader order, to `resolve_block_template()`, or on a classic theme to `locate_template()` as PHP file names, and on a hybrid theme to both (see "Classic themes"). The `{$type}_template_hierarchy` filters and paged, search, taxonomy, author and date URLs are not covered.
- `refs` are transitive, unlike the layout section's: the template, every part, pattern, menu and binding reachable from it and, for a fixed route, from its page's content, and the `pt:` post type.
- When the route's own page is a page builder's document, a fixed route carries `builder` (the builder slug) and `coverage: partial`, and a sampled route's `stats.example` carries `builder`, since the sample is volatile. `template` stays what WordPress resolves. See "Builders".
- Edit: `options-reading.php` for the front and posts pages, the page's edit screen for `route:page-<slug>`, null otherwise (the template carries its own).
- find-edit-location does not read these items. It resolves the URL it is given with that post's own template and content.

## Code section

`code` holds what theme and plugin PHP does at runtime that no edit screen shows: the REST routes they register (`rest:`, kind `rest_route`) and their callbacks on hooks that change a page's head (`filter:`, kind `head_filter`). Every item points at the file and function that hold it. Nothing is run: routes come from the REST server (`rest_get_server()->get_routes()`), callbacks from `$wp_filter`, files and lines from Reflection, and what the code does from a token read of its source. Core's own routes and callbacks, PHP built-ins (`trim` on `wp_title`), and Wayfinder's own are left out.

- **Owner.** `owner` is `{"kind": "theme" | "plugin" | "mu-plugin" | "other", "slug": ..}`, read from the callback file's path. The active theme's and mu-plugins' code is the site's own and is read (`traced: true`). A plugin's is named and not read (`traced: false`): its effects belong to the plugin. A plugin owner is a ref (`plugin:<slug>`).
- **The reader.** `src/Scanners/TokenReader.php`, shared with the redirect scan. It reads calls with the shape of each argument (a string, a constant by name, a variable and what it was last assigned, a call and its first argument, an array's literal keys), the constants a body reads and the line that declares each (`const` or `define()`), what it returns, literal path pairs and `__DIR__` file names. It follows calls into user functions and resolvable methods two levels below the entry: a static call, `$this->`, `self::`, a call chained on `Class::instance()`, and a variable assigned `new Class()` or `Class::instance()`. Calls on properties, objects passed in and containers stay unresolved, and so do values held in variables and work a listener, cron job or queue does later. Without the tokenizer extension nothing is traced, never "does nothing".
- **Sinks.** `data/php-sinks.json` says what a call means: `wp_mail` sends mail, Orbit's `Voyager\Orbit\Modules\Leads\Module::insertLead` records an Orbit lead, `wp_insert_post` creates a post, `wp_redirect` and `wp_safe_redirect` redirect. A call it does not list is not an effect.

### REST routes

One item per route, its label the route (`/voyagermark/v1/contact`). Facts: `namespace`, `owner`, and `endpoints`, one per handler the route registers, each with `methods` (sorted), `handler` (the function, or `Class::method` with its namespace, or `{closure}`), `file` (relative), `permission` (`public` for `__return_true`, `checked` for any other callback, `missing` for none, which WordPress treats as public) and `traced`. A traced endpoint also carries:

- `records`: the sink labels its code reaches (`["an Orbit lead"]`).
- `mails`: each recipient, by where it comes from and never by value: `{"to": "constant", "name": "VOYAGERMARK_CONTACT_NOTIFY", "declared_in": "themes/voyagermark/inc/contact-lead.php"}`, `{"to": "option", "name": "admin_email"}` (`get_option()`, or `get_bloginfo('admin_email')`), `{"to": "filter", "name": "voyagermark_scorecard_alert_to"}`, `{"to": "address in code"}` for a literal, `{"to": "not traced"}` for anything else. `[]` means no mail call in the code it follows, two levels deep: an inferred negative with that scope, not a proof.
- `mail_through`: when it mails, where `wp_mail()` is defined: `plugin:<slug>` for a plugin that replaces the pluggable function (Postmark), `core` otherwise. A plugin is also a ref.
- `fires`: each action it fires with a literal name, and the files that listen to it (`[]` when nothing does).
- `creates_posts` (post types a literal `post_type` names) and `redirects: true`, when present.

A constant's value is never read: rule 3 stands, so a route says which constant holds the address and where it is declared.

### Head filters

One item per callback on a hook that changes the head: the document title (`pre_get_document_title`, `document_title_parts`, `document_title`, `document_title_separator`, `wp_title`), the archive title and description (`get_the_archive_title`, `get_the_archive_description`), and an SEO plugin's title, description, Open Graph and Twitter hooks (`rank_math/frontend/title`, `rank_math/frontend/description`, every `rank_math/opengraph/...`, Yoast's `wpseo_title`, `wpseo_metadesc`, `wpseo_opengraph_*`, `wpseo_twitter_*`, All in One SEO's and SEOPress's title and description). Rank Math's sitemap, robots and schema hooks are not read. Two closures on one hook take `-2`, `-3` in the order they run.

Facts: `hook`, `changes` (`title`, `description`, `og:title`, `og:site_name`, `archive description`, ...), `callback`, `file`, `owner`, `priority`, `applied_by` (the SEO plugin whose hook it is, `seo-by-rank-math` for `rank_math/`, null for a core hook; also a ref) and `traced`. A traced callback also carries:

- `conditions`: the conditional tags its own body checks, with a literal argument when it has one (`["is_post_type_archive('services')"]`). `is_front_page()` refs `route:front-page`, `is_home()` `route:posts-page`, `is_post_type_archive('<type>')` `route:archive-<type>` and `is_singular('<type>')` `route:single-<type>`.
- `returns_fixed_text`: true when its own body returns a string literal, bare or as a branch of a ternary. The text is not kept.
- `reads`: what it and what it calls read, by name: `option:<name>` (`get_bloginfo('name')` reads `option:blogname`), `theme_mod:<name>`, `meta:<key>` (`meta:*` when the key is not a literal).

A theme filter on a `rank_math/` hook shows Rank Math applies it. An SEO plugin adds its own head callbacks only on front end requests, so a scan never sees them, and a page with no filter here may still have its title written by the SEO plugin's settings: where it comes from is then not traced.

### Scan context

Reading routes outside a REST request fires `rest_api_init`, where core and plugins register settings. So the scan reads the REST server once before any scanner runs (`ScannerRegistry::primeRestServer()`), and every scanner sees the same registry from WP-CLI, wp-admin and REST.

## Classic themes

Classic support switches on when the active theme is not a block theme (`wp_is_block_theme()` is false). A block theme's manifest has none of what follows and is byte for byte what it was. A classic theme that also supports block templates is a hybrid theme (see "Hybrid themes" below).

- Templates are the PHP files in the theme root that core's template hierarchy loads (`index.php`, `front-page.php`, `home.php`, `singular.php`, `single.php` and `single-*.php`, `page.php` and `page-*.php`, `archive*`, `category*`, `tag*`, `taxonomy*`, `author*`, `date.php`, `search.php`, `404.php`, `attachment.php`, `embed*`, `privacy-policy.php`), plus any file at most one folder deep with a `Template Name:` header. The child theme's file wins over the parent's, name by name. The slug is the file name without `.php` (`tpl:single`, `tpl:templates/full-width`), the label the `Template Name` or the file name.
- Routes pass the same candidates as on a block theme to `locate_template()` as `<slug>.php`, which tries each name in the child theme, then the parent, and returns the first file found.
- Parts are what a template includes through `get_header()`, `get_footer()` and `get_template_part()`, followed into the parts those include. Calls are read from the file's tokens; theme PHP is never run. An argument counts only as a string literal: `get_header('shop')` tries `header-shop.php`, then `header.php`, and a dynamic name (`get_template_part('template-parts/content', get_post_type())`) tries the base file alone. A call with a dynamic slug is skipped. The part's slug is the file it resolves to (`part:header`, `part:template-parts/content-single`); one that resolves to no file is a `missing_refs` entry under its first candidate. `area` is `header` for a part `get_header()` includes, `footer` for `get_footer()`, else `uncategorized`.
- Templates and parts carry the same `file`, `file_theme`, `overrides`, `slug`, `source` (`theme`) and `user_modified` (false) facts as block ones. A template refs the parts it includes. Their edit path is null: a PHP file has no edit screen, and `file` says where it is.
- They also carry `reads` and `links`, found in the same token pass (see "Files and reads"). `reads` lists what the file reads through calls with a literal first argument: `get_field()`, `the_field()` and `have_rows()` read an ACF field, `acf_option:<key>` when the second argument is `'option'` or `'options'` (an options page field) and `acf:<key>` otherwise; `get_option()` reads `option:<name>`. `links` lists the paths the file hard codes through `home_url('/path/')`. A dynamic argument and a method call are skipped, and both lists are sorted and empty when the file has none. So `part:footer` reading `acf_option:company_phone` says the footer shows the options page's `company_phone` field, and a field no file reads is shown nowhere by the theme. A block theme's templates and parts have neither fact.
- A template also carries `shows_post`, what it and every part it includes print of the post it is served for (see "Files and reads"). A landing page template that builds the page from fields reads `[]`, and both the index line (`never shows the post's own title or content`) and find's post content entry say so, so its "headline" is never sent to the post's title. A block template has no `shows_post` yet.
- `loc:<location>` (kind `menu_location`): each location `register_nav_menus()` registers, labelled as registered. Fact `menu`, the name of the menu assigned to it, or null. Edit `nav-menus.php?action=edit&menu=<term id>` for the assigned menu, else `nav-menus.php?action=locations`. The menu's item count is the stat `item_count`.
- `sidebar:<id>` (kind `sidebar`): each registered sidebar, labelled by its name. Edit `widgets.php`. The number of widgets in it is the stat `widget_count`.
- `optpage:<menu slug>` (kind `options_page`, in `content_model`): each ACF options page (`acf_get_options_pages()`, ACF PRO). Facts `field_groups` (titles of the active field groups located on it with `options_page == <menu slug>`), `fields` (their field names, layout fields left out) and `parent` (the parent menu slug, or null). Edit as core's `menu_page_url()` builds it: `<parent file>?page=<slug>` under a wp-admin file such as `options-general.php`, else `admin.php?page=<slug>`.
- Labels of locations and sidebars come from the theme as registered, so a site in another locale may show translated labels.

### Hybrid themes

A hybrid theme is a classic theme that supports block templates (`current_theme_supports('block-templates')`). It is read as classic, as above, and its block templates and parts are read beside the PHP files, as a block theme's are. A classic theme without that support is byte for byte what it was.

- Block templates and parts are what core's `get_block_templates()` returns for the theme: Site Editor ones (`source` `custom`) and any the theme or a plugin ships. Their facts and edit path are a block theme's (`site-editor.php?p=/wp_template/<theme>//<slug>&canvas=edit`).
- Their id takes core's `<theme>//<slug>` as its slug, so the Site Editor `page` template is `tpl:acme//page` and never meets `page.php`, which stays `tpl:page`. Parts do the same (`part:acme//header`).
- A template part block in a block template or part points at the theme's block part, never its PHP file: `<!-- wp:template-part {"slug":"header"} /-->` refs `part:acme//header`, and is a `missing_refs` entry when the theme has no such block part, since core then renders nothing there.
- Routes follow core's `locate_block_template()`: `locate_template()` finds the PHP file for the candidates, the candidates after that file's name are dropped, and a block template among the rest, as specific as the file or more, wins and is the route's `template` (`acme//page`). With none, the PHP file is used. So a block `page` template wins over `page.php`, and `single.php` wins over a block `singular` template.

## Find edit location

`wp wayfinder find <url> [--text=] [--class=] [--detail=compact|full] [--format=ids|json]` and `GET wayfinder/v1/find?url=&text=&class=&detail=` (needs `edit_pages`; anonymous gets 401, an unknown URL 404, a `detail` other than `compact` or `full` 400).

```
{"detail":"compact","url":"/","route":"route:front-page","template":"tpl:page","text":"555-0142","class":null,"matched":true,"ambiguous":false,"message":null,"chain":[<entry>, ...],"hits":[<hit>, ...],"chain_more":1}
entry: {"id":"bind:vwf-fixture/business-info.phone","label":..,"edit":"options-general.php?page=vwf-business-info","instruction":"Phone in the footer. It comes from Business info: options-general.php?page=vwf-business-info","match":true}
```

- **Compact by default.** `detail` is `compact` unless the request says `full` (an empty value is `compact`). The compact answer keeps the first three hits and the first three chain entries, in the order below, and counts the rest in `hits_more` and `chain_more`, each present only when something was left out. It leaves out the post content entry's `classes`, `block_names`, `hidden_classes`, `classes_more` and `locked_blocks`, and a synced pattern's `used_in` (its instruction names the first five usages and its `usage_count` stays). Every entry and hit it keeps is unchanged, and so is `verify`. When it cuts the chain of a miss, the message's last sentence says so: `The chain lists the first three things the page is built from; ask with detail full for the rest.` `full` returns everything below. Every answer carries `detail`. On a real site with hundreds of classes per page, the whole result ran to 14,000 characters on average and 67,000 at most over the find eval's questions; the compact answer ran to 2,500 on average and 3,400 at most. `FindPayloadSizeTest` holds a large test page with three candidates under 3,200. The inspector and the Wayfinder page read the whole result.
- The chain is most specific first. Without text: the post content, the template, then what they reach breadth first, then the route. With a match on this URL: the top hit, then what it sits in, up to the route.
- `hits` lists every home of the match, best first: on this URL's route and off it (every other template, part, pattern, menu and published post is searched too). Each hit is `{"rank":1,"id":"pt:page","block_path":"0.2.1","snippet":"Call us at (540) 555-0142 any time.","why":["all words present","on this URL, 0 steps from the content","reached by 2 routes"],"on_route":true,"distance":0,"routes":2}`. `id` is the chain id that holds the block: `pt:<post_type>` for this URL's content, `<post_type>:<slug>` for other posts, the binding's id for a bound value, `option:<name>` for an option a registered pattern prints. `block_path` is the parse-order path (null for the post title). `snippet` is at most 80 characters of the matched text; a binding's snippet names the binding, never its value. `distance` counts steps from the content (the content 0, its template 1, a part in the template 2, a binding one below its container), null off the route. `routes` counts the route items in the stored manifest whose refs reach the hit (the post content through `pt:<post_type>`), null when no manifest is stored.
- Hits rank by, in order: the match tier (exact text, then a `label` attribute, then another text attribute, then all words present); on this URL's route first; a location hint the hit's container answers (a query word naming a part by area, slug or title, or footer, header, sidebar, menu, navigation; hint words are taken out of the text to match unless nothing else is left); smaller distance; homes with an edit screen before code-only ones (a classic PHP file, a registered pattern); then routes, fewer first when the query names a page by its slug and more first otherwise; then id and block path. Recency is never used.
- `ambiguous` is true when the top two hits tie on the match tier and on being on this route: two copies on the page are two places to edit, whichever a hint or the distance puts first.
- The query and the text are normalized the same way: tags stripped, entities decoded, whitespace and NBSP collapsed, lowercased. A digit-heavy query (four or more digits, at least half its characters) also matches on digits alone, so `(540) 555-0142`, `540.555.0142` and `555-0142` meet the same bound value.
- `wp wayfinder find --format=ids` prints the chain's ids, then one `hit <rank> <id> <block path> <fp> <snippet>` line per hit (`-` for a missing path or fingerprint), then `ambiguous: ...` when it is, then `coverage: partial (builder_document)` on a builder page, then `more: <n> hits, <n> chain entries; --detail=full lists them` when the compact answer left some out.
- Every hit carries `anchor` (null for the post title, which is not a block), and the chain entry of the top hit carries the same `anchor`. The result carries `verify`. See "Anchors".
- The post content is listed as `pt:<post_type>` with the post's edit screen. When the post is a page builder's document, the entry's `edit` is the builder's edit URL, its `builder` names the builder, and its instruction says the content is built there and not read. The result then carries `coverage: partial` and `reason: builder_document`; a result without them has full coverage. A miss on such a page is `matched: false` with a message pointing at the builder, not a plain miss. See "Builders".
- Text matches a block's own text, its text attributes (`content`, `label`, `alt` and the like), the post title, and the value a binding source returns for a bound attribute (see the tiers above). Bound values are compared, never returned. Nothing is rendered over HTTP.
- In a registered pattern that reads options, a matching block whose text an option holds is a hit on the option instead (`option:voyager_site_phone`, one step below the pattern, with an edit screen and so never code only): the query is in the option's value, and the whole value is in the block's text, or for a digit-heavy query the value's digits hold the query's, so `+15402270214` meets `(540) 227-0214`. Option values are compared, never returned. Its chain entry's `edit` is the option's screen from the pattern's `read_screens`, null when not traced: `The option voyager_site_phone ("Phone Number"), which the "Site phone" pattern prints. Edit it here: options-general.php?page=voyager-blocks&tab=site-data`. Text typed into the pattern stays a hit on the pattern.
- Without text, each option a registered pattern on the page reads follows the first pattern that reads it in the chain, as an entry of its own, and says when it is empty now: `It is empty now, so nothing from it shows until it is set.`
- A registered pattern's own entry names its file and the options it prints: `Pattern "Site phone" is registered in code, built by the theme file themes/voyagermark/patterns/site-phone.php, so it has no edit screen. It prints the option voyager_site_phone ("Phone Number"), edited here: options-general.php?page=voyager-blocks&tab=site-data. Change that value there, not in the file. Change the rest of the pattern in that file, or replace it with a synced pattern.` One that reads nothing says `Change it in that file`, and one no theme file registers keeps `Change it in code`.
- `class` finds blocks that carry a `className` token or are named it (`metadata.name`), compared lowercased and whole; a leading dot is dropped (`.hide-mobile`). Pointer blocks count for the container that places them. Each hit entry carries `class_count`, how many of its blocks matched. With `text` too, a block must match both, and a binding is a hit only when its block carries the class. The post title never matches a class.
- With `detail` `full`, the post content entry (`pt:<post_type>`) carries the content's `classes`, `block_names` and `hidden_classes`, as layout items do, and `locked_blocks`, each locked block in the post's content as layout items list theirs (see "Layout section"). So "which blocks on this page can the client not delete" is the list's names, and a group that sets `templateLock` is not among them: its children are.
- On a classic theme whose template never prints the post's title or content (`shows_post`), the post content entry's instruction says so and names what the template reads instead: `Its template, themes/vwf-classic/page-templates/landing.php, never shows this page's title or content: what the page shows comes from acf:featured_projects, acf:page_sections.` A classic template entry names the links it hard codes: `It hard codes links to /contact/ in the file; no field or setting changes them.`
- When a head filter from the stored code section runs on this URL (its conditional tags name this route, or it checks none), the chain carries an entry for it just before the route, with `match` false and the filter's id: `The og:title on this page is set by the theme filter voyagermark_og_title in themes/voyagermark/functions.php (hook rank_math/opengraph/facebook/og_title, which plugin:seo-by-rank-math applies). It returns fixed text typed into that file, so change it there; no settings screen changes it.` A filter that reads one allowlisted option edits at that option's screen (`options-general.php` for `blogname`); any other has no edit path. A plugin's filter says what it sets is not traced. A filter tied to another route, or to a condition no route stands for (`is_page('contact')`), is left out. Without a stored code section there are no such entries. See "Code section".
- `matched` is null without text or class, false when nothing on this URL matched (the chain is then the whole page and `message` says so; `hits` may still list homes elsewhere on the site).
- Every entry carries `lock` (see "Locks"), `client_can` and `client_can_why`. A hit's `lock` is that of the first block in it that matched, so a phone number bound in the footer reports the bound paragraph's lock with `text_editable` false. Any other entry's lock is its container's as a whole, as its edit path opens it: content only on 7.0 for a registered pattern, the post type's `template_lock` for the post content, else `none` (a part and a synced pattern open isolated, see "Locks" rule 5). The route, bindings with no matching block and classic PHP files read `{"effective":"none","src":"none","text_editable":false}`.
- `client_can` is `yes`, `partial` (content only: the text can change, nothing else) or `no`, for the role in option `vwf_client_role` (default `editor`). It is `no` when the entry has no edit screen, when the role lacks the capability the screen needs (the same table the Wayfinder page links by: `site-editor.php` needs `edit_theme_options`, a settings page the capability it was added with, a post's screen its post type's `edit_others_posts`), and under content only when the block has no editable text. `client_can_why` answers who can open it first, then what the lock allows: `the editor role cannot open it: it needs edit_theme_options`, `the editor role can open it; content only (template_lock): text can change, cannot be moved, removed or restyled`, `the editor role can open it; all (block): text can change, cannot be moved or removed`, `the editor role can open it; not locked`. A role that cannot open the screen is never given the lock as the reason.
- Synced pattern entries carry `usage_count`, and with `detail` `full` `used_in`. A `core/post-meta` or `voyager/post-meta-text` binding edits on the post (a `voyager/post-meta-text` key with a dot path, `stats.0.value`, names the field before the first dot); `voyager/site-data` edits at its owner's Site Data screen; other sources take their edit path from the `edit` a `vwf_binding_meta` filter states, then the `vwf_binding_edit_path` filter, else the instruction asks for an agency note.

## Builders

Most inherited sites are built with a page builder. Wayfinder does not read builders' data; it says which builder owns a post, where to edit it and that its own reading is partial, so an agent stops searching and routes the edit to the builder.

| Builder | Active when | A post is its document when | Edit URL | Template post type |
|---|---|---|---|---|
| `beaver-builder` | plugin `bb-plugin` or `beaver-builder-lite-version` | `_fl_builder_enabled` is set | `<permalink>?fl_builder` | none |
| `breakdance` | plugin `breakdance` | `_breakdance_data` is set | `<home>?breakdance=builder&id=<id>` | none |
| `bricks` | theme `bricks` (active or parent) | `_bricks_editor_mode` is `bricks` | `<permalink>?bricks=run` | `bricks_template` |
| `divi` | plugin `divi-builder`, or theme `Divi` or `Extra` | `_et_pb_use_builder` is `on` | `<permalink>?et_fb=1` | `et_pb_layout` |
| `elementor` | plugin `elementor` | `_elementor_data` is set | `post.php?post=<id>&action=elementor` | `elementor_library` |

- The table lives in `data/builders.json`. "Is set" means any value but `''` and `'0'`. A post carrying two builders' markers belongs to the first by slug.
- Active plugins are the list the integrations section reads (no second plugin scan); themes are the active stylesheet and template. An inactive builder's markers are ignored, so a page goes back to a plain `pt:page` entry when its builder is deactivated.
- Builder code is never loaded or called. Only plugin and theme slugs and the marker meta are read, and a marker's value only to compare it.
- Not read: `_elementor_data`, Divi shortcodes, Bricks, Beaver and Breakdance data, and anything a builder renders. Find never matches text inside a builder document, and a `btpl:` item holds no refs.
- Edit URLs without a leading slash are relative to wp-admin, as every edit path. One with a leading slash (and no wp-admin script right after it, unlike `/edit.php`) is relative to the site root: the builder edits on the front end. The Wayfinder page links it on the site's origin, and `client_can` checks `edit_post` for the post the URL names (`id=`), else `edit_posts`. `<permalink>` keeps its query string, so `/?page_id=12&bricks=run` works with plain permalinks.
- `btpl:<slug>` (kind `builder_template`, in `layout`): each published post, at most 200 per type, in an active builder's template post type. Facts `builder`, `coverage` (`partial`), `post_type` and `template_type` (the builder's own `header`, `footer`, ... from `_elementor_template_type` or `_bricks_template_type`, else null). Two templates sharing a slug are both `btpl:<post type>.<slug>`. Edit is the builder's edit URL.
- `coverage` is the one coverage word: `partial`, with `reason: builder_document` on find, means part of what is on the page is not read. Its absence means full.

## Anchors

Wayfinder is read-only. A writer (any agent tool) addresses a block by its parse-order path, and a path shifts when a sibling is inserted, so find gives it something to check before and after it writes.

```
anchor: {"entity":"part:footer","post":null,"path":"0.1","fp":"3f9c0a1b2c3d4e5f","expect_hash":"<item hash>"}
verify: {"changes_since":"<stored manifest_hash>","expect_changed":[],"recheck":<the top hit's anchor>}
```

- `entity` is the item that holds the block: `tpl:`, `part:`, `pat:` or `nav:`, or `pt:<post_type>` for a post's content, with `post` the post id (null otherwise). A hit on a bound value is anchored in the container of the bound block, so the footer phone number's anchor is `part:footer`.
- `fp` is the block's fingerprint: the first 8 bytes, as 16 hex characters, of sha256 over the canonical JSON (`CanonicalJson`) of `{name, attrs without className and lock, text}`, the text with whitespace and NBSP collapsed and trimmed. Inserting a sibling moves the path, never the fingerprint; editing the block's text or any other attribute moves the fingerprint; a class, a style variation or a lock does not. Children are not part of it. Two identical blocks share one.
- `expect_hash` is the entity's item hash from the layout scan find read (the `hash` beside the item in `scan --section=layout`). Post content is not a manifest item, so a `pt:` anchor has null. Item hashes cover structure (refs, locks, classes, edit path), never text: the hash says the container was restructured, the fingerprint says the block changed.
- `wp wayfinder anchor <entity> <path> [--post=<id>]` prints a block's anchor now. `wp wayfinder anchor --fp=<fp> <entity> [--post=<id>]` (or `--fp <fp> <entity>`) prints `[{"post":..,"path":..}]` for every block in the entity carrying that fingerprint, every published post of the type when a `pt:` entity has no post (at most 200), and exits 1 when none does.
- The loop: find, then before writing check the block at `anchor.path` still has `anchor.fp` (or re-anchor with `--fp`) and, for a layout entity, that its hash is still `expect_hash`; write; `wp wayfinder scan --refresh`; ask `get-changes-since` from `verify.changes_since` and expect no ids beyond `verify.expect_changed`; read the anchor at `verify.recheck` again and expect a new fingerprint.
- `verify.changes_since` is the stored manifest hash (null when nothing is stored), where the changes tier counts from. `verify.expect_changed` is empty: a text edit, or a new value behind a binding, moves no item hash, because the manifest describes structure and leaves text and values out. Any id `get-changes-since` returns after a text edit means the write changed more than text. `verify.recheck` is the top hit's anchor on this URL, null when nothing on it matched.

## PHP API

A plugin that writes blocks (Orbit) checks and recomputes anchors in the same PHP request as its write, through three global functions. They are defined when the plugin file loads, and `Voyager\Wayfinder\Api` sits behind them. Nothing else in `src/` is a contract.

```
vwf_api_version(): string
vwf_anchor(string $entity, string $path, ?int $post = null): ?array
vwf_anchor_check(array $anchor): array
```

- `vwf_api_version()` returns `"1"`. Any change a caller could notice in an existing function's arguments, its return shape or its `reason` values bumps it. A new function does not.
- Test `function_exists('vwf_api_version')` when you call, not when your plugin loads: plugins load in folder order, so voyager-orbit loads before voyager-wayfinder. A checkout without `composer install` loads nothing and defines none of them.
- `vwf_anchor()` returns the block's anchor now (see "Anchors"), or null when the entity, the post or the path holds no block. A `pt:` entity needs `$post`.
- `vwf_anchor_check()` takes an anchor as find or `vwf_anchor()` returns it, and returns `{ok, current, reason}`:

```
{"ok":true,"current":{"entity":"part:footer","post":null,"path":"0.1","fp":"3f9c0a1b2c3d4e5f","expect_hash":"<item hash>"},"reason":"match"}
```

| `reason` | `ok` | `current` | When |
|---|---|---|---|
| `match` | true | the block now | The block at the anchor's path has `fp`, or exactly one block in the entity does (it moved, and `current.path` is the new path). For a layout entity, the item hash is still `expect_hash`. |
| `container_restructured` | false | the block now, with the new `expect_hash` | The block was found, but the layout entity's item hash differs from a non-null `expect_hash`. |
| `fp_changed` | false | the block at the path now | A block sits at the path with another fingerprint, and no block in the entity carries `fp`. |
| `ambiguous` | false | null | The path does not carry `fp`, and several blocks in the entity do. |
| `gone` | false | null | The entity, the post or the path holds no block, and no block carries `fp`. |

- `ok` is true only for `match`. Write at `current.path`, never at the path you passed. After a refusal, `current` is what to re-anchor on.
- The block is looked for first, at its path and then by fingerprint. The container is compared only once the block is found, so a block edited inside a restructured part reads `fp_changed`.
- `pt:` anchors ignore `expect_hash`, because post content is not a manifest item. A `pt:` anchor's post is read on its own, however many older posts the type has; only the list of a whole type stops at 200, oldest first. `wp wayfinder anchor --post` reads the post the same way.
- What an anchor cannot tell. `fp_changed` looks the same for an edited block and for a removed one whose next sibling took its path, so read `current` before acting on it. A block whose fingerprint occurs more than once in its entity is matched by path, so a shift that brings an identical copy onto the path reads `match`. Containers are the usual case: a group's fingerprint leaves its children out.
- A malformed anchor throws `InvalidArgumentException`: no `entity` or `path`, an `fp` that is not 16 lowercase hex characters, a `post` that is neither an int nor null, an `expect_hash` that is neither a string nor null, or a `pt:` entity with no post. Validate or catch agent input before passing it.
- Each call reads the site afresh, so a check after a write sees the write. Nothing here writes to the site, and none of it is exposed over REST or as an ability.
- The loop in one request: `vwf_anchor_check($anchor)`, refuse unless `ok`, write at `current.path`, then `vwf_anchor()` on `current`'s entity, path and post and expect a new `fp` (the recheck in "Anchors").

## Snapshots and notes

- `wp wayfinder scan --refresh` stores the body as canonical JSON in option `vwf_manifest_snapshot` (`{"hash":..,"body":"<json>"}`, autoload off). sha256 of the stored body is the stored hash.
- `vwf_manifest_history` keeps the last 5 changes, oldest first: `{previous, hash, added, removed, changed, generated_at}`. `previous` is null on the first store. Storing an unchanged manifest adds nothing. The changes-since tier reads this chain.
- `added`, `removed` and `changed` are item ids, each list sorted. An item is changed when its hash differs, so a new ref (a field added to a post type) changes the item that refs it.
- A drift check runs on `admin_init` at most every 5 minutes and rescans when the fingerprint moves. Theme switches, plugin (de)activation, ACF group saves and saves of `wp_template`, `wp_template_part`, `wp_block`, `wp_navigation` and a page builder's template post types (`elementor_library`, `et_pb_layout`, `bricks_template`) force a rescan on the next admin request.
- Notes pin their item's hash from the stored snapshot. Status is `ok` while the item's hash matches, `needs_review` when it differs and `orphaned` when the item is gone. It is recomputed on every refresh, so an item that changes back reads `ok` again. Re-saving a note pins the current hash.
- The snapshot records the `plugin_version` that scanned it. A refresh under a different version is an upgrade: a new release may add facts, which moves item hashes on a site that did not change. So before statuses are recomputed, every note pinned to its item's stored hash moves to the item's new hash and stays `ok`. A note already behind the stored manifest keeps `needs_review`, and an orphan stays orphaned. A snapshot stored before versions were recorded counts as an upgrade. Only a version bump is seen: a build that changes scanner output without bumping the version still flags notes.
- The snapshot stores the body only, never `stats`. Item hashes leave stats out, so publishing a service changes `stats_hash` and nothing else, even when its slug sorts first and it becomes `route:single-service`'s example: `manifest_hash`, the ETag, the history and every note on `pt:service` and the route stay as they were. A new field (`field:service.warranty_years`) is structure: it adds an item, changes the `pt:service` hash through its refs, and flags the note.

## Determinism

- Body bytes depend only on site state. No `generated_at`, user names or locale-dependent strings in the body.
- Arrays sort by id; object keys sort lexically in JSON output.
- `manifest_hash` is sha256 of the canonical JSON body. Each section and item carries its own hash. None of them covers `stats`.
- REST responses send `ETag: "<manifest_hash>"` and honor `If-None-Match` with 304. Agency notes are rendered into the tiers but are not part of the manifest, so when any exist the ETag is `"<manifest_hash>-<first 12 hex of the notes hash>"`: a new note must not be answered with 304.
- The REST server is read before any scanner runs, so what `rest_api_init` registers is there for every section whatever the scan context (see "Code section").
- Options are read with an explicit default, so a default from `register_setting()` never leaks in. Core registers `WPLANG` with a default of `en_US` only on `rest_api_init` and `admin_init`, which once made REST and WP-CLI hash the same site differently.

## Surfaces

| Surface | Brief and index | Section | Changes |
|---|---|---|---|
| REST (`edit_pages`) | `GET wayfinder/v1/context?tier=brief\|index`, text/plain | `GET wayfinder/v1/context?tier=section&id=<section or item id>`, application/json | `GET wayfinder/v1/changes?since=<hash>` |
| WP-CLI | `wp wayfinder context --tier=brief\|index` | `wp wayfinder context --tier=section --id=<id>` | none |
| Abilities (`edit_pages`) | `voyager-wayfinder/get-brief`, `voyager-wayfinder/get-index` | `voyager-wayfinder/get-section` | `voyager-wayfinder/get-changes-since` |
| MCP resources (`edit_pages`) | `wayfinder://brief`, `wayfinder://index`, text/plain | none | none |

The section tier answers 400 with no id and 404 for an id that is neither a section nor an item. An item's JSON adds `section` and, when it has any, `stats` (exact, not banded) and `notes`. A section's JSON adds `stats`, keyed by item id, when any of its items has some. Neither is covered by the `hash` beside it.

`changes` follows the `vwf_manifest_history` chain (see "Snapshots and notes") from the link whose `previous` is `since`. It returns `{"changed": [...], "full": false, "hash": "<current>"}`, every id added, removed or changed on the way, sorted, when `since` is the current hash or the chain runs unbroken from it to the hash of the site as scanned now. Otherwise it returns `{"full": true, "hash": "<current>"}`: fetch the brief and index again. A site that changed after the last stored scan answers `full` until the next refresh.

## Wayfinder page

The page in wp-admin (`edit_pages`) is the human reader of the same manifest: one card per item, with `data-vwf-item="<id>"` and the HTML id `vwf-item-<id>`. It is not a format, but these things it does bear on the tiers:

- Each view scans, as the tiers do. When the scan differs from the stored manifest, or nothing is stored, the view refreshes the store before it reads the notes, so a note on a changed item reads `needs_review` on the page that shows the change. Rescan (`manage_options`, `admin-post.php?action=vwf_rescan`) forces a refresh. Every refresh writes `vwf_last_scan`, the scan's `generated_at`.
- "Copy AI brief" copies the brief tier rendered from that scan and the stored notes, byte for byte what `context?tier=brief` returns.
- The settings panel (`manage_options`) saves `vwf_mcp_enabled` through options.php, as `1` or `0`.
- Edit paths the reader cannot open are printed, not linked: an editor sees the Site Editor and settings paths as text.
- Each card says "Client can edit: yes / content only / no" with the reason, for the role in `vwf_client_role` (default `editor`), from the item's edit path and its `lock` fact, as find's `client_can` does.
- Export (`manage_options`, `admin-post.php?action=vwf_export`) downloads the handoff manual, the same bytes as `wp wayfinder export --format=md|html [--output=<file>]` apart from the scan time. It reads through the tiers' Context. The CLI first stores the scan when it differs from the stored one, as the page does on each view, so note statuses match it; `--no-refresh` scans without storing anything, and notes keep the status last stored. Sections follow the page's order; Content, Layout and Routes always get a heading and `## Notes` comes last. Edit paths are full admin URLs. Notes are what the tiers print, text and status, under their items. Items the tiers fold become one count line per section, unless they carry a note. The HTML is one file with inline print CSS, its colors and fonts in `--vwf-*` custom properties.

## Front-end inspector

"Where is this edited?" in the admin bar on the front end, for users with `edit_theme_options`, reloads the page with `?vwf_inspect=1&_vwfnonce=<nonce>` (action `vwf_inspect`). It is not a format, but its marks use the same ids:

- Only when the switch, the nonce and the capability all check out, a `render_block` filter marks the first tag of each `core/template-part`, `core/block`, `core/navigation` with a `ref`, `core/post-content` and block with `metadata.bindings`. Nothing else changes, so a visitor gets the same bytes with or without `?vwf_inspect=1`.
- `data-vwf-src` is the id chain from the outermost container to the block, joined with `>`: `part:footer>bind:vwf-fixture/business-info.phone`, `pt:page>pat:cta-banner`. Ids are read from the block the way the scanners read them (`BlockRefs`). The template is not in the chain, and a block that binds several attributes is named by its `content` or `text` binding.
- `data-vwf-edit` is the full admin URL of the last id's edit path, from find on this URL without text, so the inspector, find and the page agree. It is left off when find has no edit path for the id, and on post content of a post other than the one this URL shows.
- When a container renders no wrapper (a synced pattern), the first tag keeps the mark of the block inside it, whose chain names the container anyway.
- The label shows find's `label`, the chain as labels, and `client_can` with its reason. A click keeps it open instead of following a link; Esc or "Stop inspecting" reloads the page without the arguments.

## Abilities

Five read-only abilities, the tools, in the `voyager-wayfinder` category, registered on `wp_abilities_api_init` (the category on `wp_abilities_api_categories_init`). Each one runs the code its REST route runs, so the bytes are the same. All need `edit_pages`, carry the annotations readonly true, destructive false and idempotent true, and set `show_in_rest`. Two more carry the MCP resources (see "MCP resources").

| Ability | Input | Output |
|---|---|---|
| `voyager-wayfinder/get-brief` | none | `{"hash": "<manifest hash>", "text": "<brief>"}` |
| `voyager-wayfinder/get-index` | none | `{"hash": "<manifest hash>", "text": "<index>"}` |
| `voyager-wayfinder/get-section` | `{"id": "<section or item id>"}` | the section tier JSON; an unknown id is a `vwf_not_found` error |
| `voyager-wayfinder/find-edit-location` | `{"url": "/", "text": "555-0142", "class": "hide-mobile", "detail": "full"}`, text, class and detail optional | the find result above, compact unless detail is `full`; an unknown URL is a `vwf_unknown_url` error |
| `voyager-wayfinder/get-changes-since` | `{"since": "<manifest hash>"}` | the `changes` result above |

- `hash` is the full manifest hash. The compact header only carries 6 hex, and get-changes-since needs all of it.
- Input schemas are closed objects. Item and chain entry schemas in the output stay open, so a new manifest field never turns into a failed call. find-edit-location's chain entries require `lock`, `client_can` and `client_can_why`. It takes an optional `class` beside `text`, and an optional `detail` (`compact` or `full`), and its result always has `detail`, `class` (null when not given), `ambiguous` and `hits`.
- MCP exposure: `meta.mcp.public` is true only when option `vwf_mcp_enabled` is truthy, read when the abilities register. It is always set, so the MCP Adapter never falls back to core's `public` flag. Wayfinder does not bundle or require the adapter.
- Descriptions are written for agents, in English, and tell them to call get-brief first.
- The MCP Adapter JSON-encodes a result into its text block, so over MCP the brief and the index arrive as a JSON string with `\n` escapes. The REST context route still sends them as text/plain.

## MCP resources

The brief and the index are also MCP resources, for an agent that reads them once at the start. Two more abilities carry them, because the MCP Adapter makes one ability one MCP type: an ability is a resource when `meta.mcp.type` is `resource`, with its URI in `meta.mcp.uri` (mcp-adapter 0.7.0). The tools above stay as they are.

| Ability | URI | Read result |
|---|---|---|
| `voyager-wayfinder/brief-resource` | `wayfinder://brief` | one content item: `{"uri": "wayfinder://brief", "mimeType": "text/plain", "text": "<brief>", "_meta": {"wayfinder/manifest_hash": "<manifest hash>"}}` |
| `voyager-wayfinder/index-resource` | `wayfinder://index` | the same shape with `wayfinder://index` and the index |

- `text` is byte for byte what get-brief and get-index return in `text`, and what `context?tier=brief|index` sends. A client reads it as plain text, with real newlines.
- `_meta.wayfinder/manifest_hash` is the full manifest hash, the `hash` the tools return, for get-changes-since.
- The same rules as the tools: `edit_pages` (the adapter checks it before every read) and `meta.mcp.public` from `vwf_mcp_enabled`. With the option off, `resources/list` lists neither and `resources/read` answers resource not found.
- `meta.mcp.mimeType` is `text/plain`. `meta.mcp.annotations` gives audience `assistant` and priority 1.0 for the brief, 0.8 for the index. Core's readonly, non-destructive, idempotent annotations are set too; the adapter does not map them onto resources.
- The adapter's discover tool lists only abilities of type `tool`, so the two resources do not show up there beside get-brief and get-index.
- Section, find-edit-location and changes-since take input, so they stay tools.

On the default server the tool form reaches an agent through `mcp-adapter-execute-ability`, whose text block is `wp_json_encode({"success": true, "data": {"hash", "text"}})`: newlines become `\n`, slashes `\/`, and the hash and wrapper add about 100 characters. The resource form is the text alone. On the unit fixture manifest (`tests/Support/FixtureManifest.php`):

| Tier | Resource text | Tool text block | Difference |
|---|---|---|---|
| Brief | 1,583 chars (est. 396 tokens) | 1,722 chars (est. 431 tokens) | +139 chars, +8.8% |
| Index | 4,157 chars (est. 1,040 tokens) | 4,347 chars (est. 1,087 tokens) | +190 chars, +4.6% |

Estimates are chars / 4, as `wp wayfinder stats`. The tool result also repeats the payload as `structuredContent`; whether a client shows that to the model depends on the client.

## Security

- No anonymous access to any tier.
- MCP exposure is off by default (`vwf_mcp_enabled`), for the tools and the resources alike.
- Option values only from the allowlist in `src/Scanners/OptionAllowlist.php`. The `vwf_option_allowlist` filter can remove names, never add them.
- No user emails, credentials, API keys or absolute server paths. File paths are relative to wp-content (see "Files and reads"), and a unit test checks every string in a manifest built from absolute inputs.
