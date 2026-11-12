=== Voyager Wayfinder ===
Contributors: voyagerben
Tags: ai, mcp, documentation, site editor, abilities
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.11.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A site map for AI agents and the people who edit the site: how it is built and where each thing is edited, kept current from inside WordPress.

== Description ==

Voyager Wayfinder scans your site from inside WordPress and keeps a living manual of how it is built and where each thing is edited. One manifest feeds two readers: the people who edit the site, in wp-admin, and AI agents, through compact context they can fetch in tiers.

The scan reads what WordPress already knows: post types, taxonomies and their fields, templates and template parts, patterns, menus, block bindings, which template renders each kind of URL, the active plugins, a short list of site settings, and which editor controls the theme or the site's styles turn off. It works on block themes and on classic themes, including menu locations, sidebars and ACF options pages.

= A manual for the people who edit the site =

The Wayfinder page in wp-admin lists everything the scan found in five sections: Site, Content, Layout, Routes and Integrations. Each item says what it is, what it uses, what uses it, and links straight to the screen where it is edited. Search filters the whole manual as you type.

Administrators can pin notes to any item, such as "the footer phone number comes from Business Info, not the footer part." When the item changes, its notes are flagged for review, so the manual does not quietly go stale.

The manual refreshes itself. A theme switch, a plugin activation, a template save or an ACF field group save marks it stale, and the next admin request rescans. There is a Rescan button too.

= AI context tiers =

AI agents read the same manual in three tiers, so they fetch only what they need:

* **Brief:** site identity, content counts, the top edit locations and what else exists. Small enough to send with every request.
* **Index:** every item on one line each.
* **Section:** full detail for one section or one item, as JSON.

The output is deterministic. The same site gives the same bytes every time, so an agent can cache it, and REST responses carry an ETag. Agents can also ask what changed since a manifest hash they already hold.

= Find where something is edited =

Give Wayfinder a URL on the site, and optionally some text you can see on that page or a CSS class, and it answers where that thing is edited. The answer is the chain from the route to the template, the part, the pattern or block, and the content, ranked, with the admin path for each. It is flagged as ambiguous when two places tie. The answer keeps the three best places and says how many more there are; ask for full detail to get every one.

= Abilities and MCP =

The tiers and the edit lookup are registered as read-only WordPress abilities in the `voyager-wayfinder` category: five tools (get-brief, get-index, get-section, find-edit-location and get-changes-since), plus the brief and the index as plain-text MCP resources an agent can read once at the start. The same reads are available over REST (`wayfinder/v1`) and WP-CLI (`wp wayfinder`).

Every read needs a logged-in user who can edit pages. Exposure to MCP clients is off by default. An administrator can turn it on from the Settings panel on the Wayfinder page, and the WordPress MCP Adapter plugin then serves the abilities to connected AI tools.

= What it never does =

* It makes no external requests and sends no data anywhere.
* It calls no AI service. The scan and the output are plain code.
* It does not edit your content, templates or settings. It writes only its own notes table and its own options.
* It never outputs user emails, credentials, absolute server paths, or option values outside a short allowlist.

== Installation ==

1. In wp-admin, go to Plugins > Add New, search for Voyager Wayfinder, then install and activate it. Or upload the plugin ZIP from Plugins > Add New > Upload Plugin.
2. Open Wayfinder in the admin menu. The first visit scans the site.
3. Optional: to share the manual with AI tools over MCP, install the MCP Adapter plugin, then tick "Share the manual with AI tools over MCP" in the Settings panel on the Wayfinder page.

With WP-CLI, `wp wayfinder scan` rescans, `wp wayfinder context --tier=index` prints a tier, and `wp wayfinder find / --text="555-0142"` says where that text on the home page is edited.

== Frequently Asked Questions ==

= Does Wayfinder make external calls or send data anywhere? =

No. Wayfinder makes no external requests, sends no data anywhere and has no tracking. It reads your site from inside WordPress and stores the result in your own database. Nothing leaves the site unless a logged-in user, or a tool acting as one, asks for it.

= Is the manual exposed to AI tools over MCP? =

Not by default. The abilities are registered, but they are hidden from MCP clients until an administrator turns on "Share the manual with AI tools over MCP" on the Wayfinder page. Even then, the connected account needs permission to edit pages. The manual is a map of your site, so it is treated like one.

= Does it use AI to write the manual? =

No. The scan and every tier are built by plain code, with no AI calls. That is why the output is the same every time the site has not changed.

= Who can see the manual? =

Users who can edit pages, which by default means editors and administrators. Adding notes and changing settings needs an administrator.

= Does it work with classic themes? =

Yes. On a classic theme it maps the PHP templates and parts, what each one reads and links to, menu locations, sidebars and ACF options pages.

= Does it change my site? =

No. It reads the site and stores its own results: a notes table and a few options, all prefixed `vwf_`.

= What happens to my notes when I delete the plugin? =

Deleting Wayfinder from the Plugins screen removes its notes table and every setting it stored, on every site of a network. Deactivating it keeps them. To keep a copy of the manual and its notes, use Export on the Wayfinder page first.

== Screenshots ==

1. The Wayfinder page: site summary, manual sections and the AI brief.
2. An item card with its edit link, what it uses and a pinned note.
3. The Settings panel, with MCP exposure off.

== Changelog ==

= 0.11.0 =
* A Site Data field is traced to the plugin that owns it: voyager-blocks, else the voyager-open-blocks pack with its own Settings > Site Data screen. A site running only the pack no longer reads as having no screen for its `voyager_site_*` options.
* A `voyager/site-data` binding names the screen that edits its value, and `voyager/post-meta-text` bindings point at the post and the field like post meta does.

= 0.9.0 =
* A pattern the theme registers from a PHP file names that file and the options it prints, with the settings screen that edits each one where Wayfinder can tell, so a value read from a setting is no longer sent to the code.
* Find sends text a pattern prints from an option to that option's settings screen, and a page's chain lists the options its patterns read.
* An option a pattern reads that is empty now is flagged, without its value.

= 0.8.0 =
* Who can open a thing and what its lock allows are now stated apart: the manual says when the client role cannot reach an edit screen, then what the layout allows once it is open.
* A template part's lock is read as its own edit screen opens it. On WordPress 7 a part is content only inside a template, not at its own edit path, and its own block locks now show.
* Locked blocks are listed by name in templates, parts, synced patterns and find's page content, each with what its lock allows.
* A block binding names the settings saved beside it that nothing on the site reads.
* A classic template says when it never shows the post's own title or content, and that the links it hard codes live in the file.

= 0.7.0 =
* WordPress's own labels (default template titles, core post types, taxonomies and block binding sources) are read in English, so a site gives the same manifest in any language. Labels a theme or plugin registered are kept as registered. Notes on items whose labels change carry over.

= 0.6.0 =
* Page builder documents (Elementor, Beaver Builder, Divi, Bricks, Breakdance) are flagged with the builder's edit URL and partial coverage, and builder templates get their own items.

= 0.5.0 =
* Release checks now cover every manifest section (site with its theme gates, content model and integrations, as well as layout and routes). The scan reads and writes the same as 0.4.0.

= 0.4.0 =
* Renamed Voyager Wayfinder (formerly Wayfinder by Voyager). The slug, text domain and stored data are unchanged.
* Hybrid themes (classic themes with block templates) list their Site Editor templates, and routes resolve to them the way core does.
* A theme gates item says which editor controls theme.json and user styles turn off.
* The brief and index are also plain-text MCP resources.
* Find returns every place a match lives, ranked, with an ambiguous flag.
* Find results on blocks carry fingerprints, anchors and a verify recipe.
* Layout items and find results say whether a block is locked and whether a client role can edit it.
* Find by CSS class or block name.
* Classic templates and parts record what they read and link to.
* ACF fields name their sub fields and layouts.

= 0.1.0 =
* First release: scanners for the site, content model, layout, routes and integrations; the Wayfinder page with notes; AI context tiers over REST and WP-CLI; find-edit-location; read-only abilities with MCP exposure off by default.
