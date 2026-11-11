<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

/**
 * Everything LayoutScanner reads from WordPress, as plain rows.
 *
 * WordPressLayoutSource is the real one. The unit suite passes an in-memory
 * source over the fixture theme's block markup, so the scanner runs with no
 * database. Rows come back in any order: the scanner sorts.
 *
 * @phpstan-type TemplateRow array{key?: string, slug: string, title: string, theme: string, source: string, user_modified: bool, area: string, content: string, file?: string|null, file_theme?: string|null, overrides?: string|null, includes?: list<string>, reads?: list<string>, links?: list<string>, shows_post?: list<string>}
 * @phpstan-type PostRow array{id: int, slug: string, title: string, content: string}
 * @phpstan-type PatternRow array{id: int, slug: string, title: string, content: string, synced: bool}
 * @phpstan-type ContentRow array{type: string, slug: string, content: string, id?: int}
 * @phpstan-type MenuLocationRow array{slug: string, label: string, menu: array{id: int, name: string, items: int}|null}
 * @phpstan-type SidebarRow array{id: string, name: string, widgets: int}
 * @phpstan-type BuilderTemplateRow array{builder: string, id: int, slug: string, title: string, type: string, template_type: string|null, edit: string}
 */
interface LayoutSource
{
    /** Most rows contentMatching() returns. */
    public const MATCHING_CAP = 200;

    /**
     * Block templates (`wp_template`) or template parts (`wp_template_part`)
     * for the active theme, one row per slug, with the content WordPress
     * would render: the Site Editor copy when there is one, else the file.
     *
     * On a classic theme (see ClassicTheme) the rows are its PHP templates
     * and the parts they include instead. Their content is empty and
     * `includes` holds the slugs of the parts each one includes, found
     * without running the file. `reads` and `links` hold what the file reads
     * and the paths it hard codes (see ClassicTheme::reads() and links()).
     * A template row also has `shows_post`, what it and the parts it
     * includes print of the post WordPress serves it for (see
     * ClassicTheme::showsPost()). Only classic rows carry `includes`,
     * `reads`, `links` and `shows_post`.
     *
     * A hybrid theme (a classic theme that supports block templates, see
     * ClassicTheme::isHybrid()) returns both: its PHP rows, then the block
     * rows a block theme would get, each with a `key` of `<theme>//<slug>`.
     * The key is the slug of the item id, so a Site Editor `page` template
     * (`tpl:acme//page`) never meets the PHP `page.php` (`tpl:page`). Rows
     * without a key use their slug.
     *
     * `source` is `theme`, `plugin` or `custom` (made in the Site Editor).
     * `user_modified` is true when a Site Editor copy overrides a theme or
     * plugin file. `area` is empty for templates.
     *
     * `file_theme` is the theme whose file WordPress serves, which for a
     * child theme may be the parent. `overrides` is the parent theme when a
     * child theme ships its own copy of a file the parent also has. Both are
     * theme slugs, never paths, and null when they do not apply.
     *
     * `file` is the theme file in `file_theme`, relative to wp-content
     * (`themes/acme/templates/home.html`). Null when there is no theme file
     * or it lies outside wp-content, never an absolute path.
     *
     * @return list<TemplateRow>
     */
    public function templates(string $type): array;

    /**
     * Published `wp_block` posts. `synced` is false for patterns saved as
     * unsynced, which core/block cannot reference.
     *
     * @return list<PatternRow>
     */
    public function syncedPatterns(): array;

    /**
     * Published `wp_navigation` posts.
     *
     * @return list<PostRow>
     */
    public function navigations(): array;

    /**
     * Published content that may reference a template part, pattern, menu or
     * binding. `type` is the post type, `slug` the post_name and `id`, when
     * the source knows it, the post id. A source may
     * leave out posts whose content references none of them.
     *
     * @return list<ContentRow>
     */
    public function content(): array;

    /**
     * Published content of any public post type whose markup contains one
     * of the needles, compared without case. Find narrows its search of
     * content off the URL's route with this, then matches in PHP, so a
     * source may return rows that do not hold the needle in the end, and
     * may stop at MATCHING_CAP rows.
     *
     * @param list<string> $needles
     * @return list<ContentRow>
     */
    public function contentMatching(array $needles): array;

    /**
     * Published posts of one post type, oldest first, at most MATCHING_CAP,
     * each with its `id`. Anchors look a block up by fingerprint in these.
     * With `$id`, that post alone when it is published and of that type,
     * however many older posts the type has.
     *
     * @return list<ContentRow>
     */
    public function contentOfType(string $type, ?int $id = null): array;

    /**
     * A registered (code) pattern by slug.
     *
     * @return array{title: string, content: string}|null Null when no pattern has that slug.
     */
    public function registeredPattern(string $slug): ?array;

    /**
     * The theme file a registered pattern is built from, when the active
     * theme or its parent registers that slug from its `patterns/` folder
     * (child first, as core does). `file` is relative to wp-content (null
     * outside it). `reads` is what including the file reads, as
     * `option:<name>` and `meta:<key>` ids sorted (see
     * ReadTracer::traceFile()), or null when it could not be traced. A source
     * includes each file at most once. Null when no theme file has the slug:
     * a pattern a plugin registers, or one registered with inline content.
     *
     * @return array{file: string|null, reads: list<string>|null}|null
     */
    public function patternFile(string $slug): ?array;

    /**
     * The Site Data fields of the plugin that owns the `voyager/site-data`
     * source (voyager-blocks, else the voyager-open-blocks pack), option name
     * => English label (null for none), sorted, or empty when none is active.
     * See OptionScreens.
     *
     * @return array<string, string|null>
     */
    public function siteDataFields(): array;

    /**
     * Where the owner of those fields saves them, relative to wp-admin, or
     * null when none is active. See OptionScreens.
     */
    public function siteDataScreen(): ?string;

    /**
     * An option's stored value as text, trimmed, to test for empty or to
     * compare with a query. It never leaves Wayfinder. '' when the option is
     * unset or empty, null when it holds an array or object.
     */
    public function optionText(string $option): ?string;

    /**
     * The label a block bindings source was registered with, in English for
     * a core source (see EnglishLabels), or null when no source has that name.
     */
    public function bindingSourceLabel(string $source): ?string;

    /**
     * Where a bound value is edited, relative to wp-admin, or null when
     * nothing says. The `edit` a source states with the `vwf_binding_meta`
     * filter wins over the older `vwf_binding_edit_path` filter. When neither
     * says, `voyager/site-data` is edited at siteDataScreen().
     */
    public function bindingEditPath(string $source, ?string $key): ?string;

    /**
     * The file that defines a binding source's value callback, relative to
     * wp-content (`mu-plugins/acme.php`), or `core` for one WordPress
     * registers. Null when no source has that name or the file is outside
     * wp-content.
     */
    public function bindingSourceFile(string $source): ?string;

    /**
     * What a binding reads, as `option:<name>` and `meta:<key>` ids, sorted.
     * A source can state them with the `vwf_binding_meta` filter; otherwise
     * the source is asked for its value once, as `$block`'s `$attribute`
     * with no post as context, and the reads it makes are recorded. Null when
     * no source has that name or its callback throws.
     *
     * @param array<array-key, mixed> $args The binding's `args`.
     * @return list<string>|null
     */
    public function bindingReads(string $source, ?string $key, array $args, string $block, string $attribute): ?array;

    /**
     * The other options registered with register_setting() in the same
     * settings group as `$option`, each with the label it was registered
     * with (null for none). Empty when `$option` is not registered, or its
     * group is one of core's (`general`, `reading` and the like), whose
     * options say nothing about a plugin's settings page.
     *
     * @return array<string, string|null> Option name => label.
     */
    public function settingsBeside(string $option): array;

    /**
     * Post types that register `$key` as post meta. `core/post-meta`
     * bindings ref the `field:<post_type>.<key>` items for these.
     *
     * @return list<string>
     */
    public function metaPostTypes(string $key): array;

    /**
     * Nav menu locations a classic theme registers, each with the menu
     * assigned to it (`items` is its item count), or null when none is.
     * Empty on a block theme.
     *
     * @return list<MenuLocationRow>
     */
    public function menuLocations(): array;

    /**
     * Sidebars (widget areas) a classic theme registers, with the number of
     * widgets in each. Empty on a block theme.
     *
     * @return list<SidebarRow>
     */
    public function sidebars(): array;

    /**
     * Published posts in the post types active page builders keep their
     * templates in (`elementor_library`, `et_pb_layout`, `bricks_template`),
     * each with its builder's slug, the post type, the template's own type
     * when the builder records one (`header`, `footer`, ...) and the
     * builder's edit URL for it. Empty when no builder with templates is
     * active. See Builders.
     *
     * @return list<BuilderTemplateRow>
     */
    public function builderTemplates(): array;
}
