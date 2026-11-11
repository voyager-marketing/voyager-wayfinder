<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use ReflectionProperty;
use Throwable;
use WP_Block;
use WP_Block_Bindings_Source;
use WP_Block_Patterns_Registry;
use WP_Post;
use WP_Term;
use WP_Theme;

/**
 * LayoutSource over a live WordPress site.
 *
 * Every call reads at scan time, never at construction, so building the
 * default scanners stays free when only another section is scanned.
 *
 * @phpstan-import-type TemplateRow from LayoutSource
 * @phpstan-import-type PostRow from LayoutSource
 * @phpstan-import-type PatternRow from LayoutSource
 * @phpstan-import-type ContentRow from LayoutSource
 * @phpstan-import-type MenuLocationRow from LayoutSource
 * @phpstan-import-type SidebarRow from LayoutSource
 */
final class WordPressLayoutSource implements LayoutSource
{
    /**
     * Filter that tells Wayfinder where a bound value is edited.
     * Arguments: (string|null $path, string $source, string|null $key).
     */
    public const BINDING_EDIT_PATH_FILTER = 'vwf_binding_edit_path';

    /**
     * Filter that lets a binding source state what it reads and where it is
     * edited, instead of having its reads traced. Arguments: (array $meta,
     * string $source, string|null $key). Return `reads` (a list of
     * `option:<name>` and `meta:<key>`) and `edit` (a wp-admin path); each
     * one given replaces what Wayfinder would find itself.
     */
    public const BINDING_META_FILTER = 'vwf_binding_meta';

    /** Settings groups core's own screens save. settingsBeside() never reads them. */
    public const CORE_SETTINGS_GROUPS = ['discussion', 'general', 'media', 'misc', 'options', 'permalink', 'privacy', 'reading', 'writing'];

    /**
     * Content is read only when it carries one of these, so a site with
     * thousands of plain posts does not load all of them.
     */
    private const CONTENT_MARKERS = [
        '<!-- wp:block ',
        '<!-- wp:pattern ',
        '<!-- wp:template-part ',
        '<!-- wp:navigation ',
        '"bindings"',
    ];

    /**
     * @return list<TemplateRow>
     */
    public function templates(string $type): array
    {
        if (ClassicTheme::isActive()) {
            $theme = ClassicTheme::active();
            $rows = $type === 'wp_template_part' ? $theme->parts() : $theme->templates();

            if (!ClassicTheme::isHybrid()) {
                return $rows;
            }

            // A hybrid theme's Site Editor rows sit beside its PHP files, under their own key.
            foreach ($this->blockTemplates($type) as $row) {
                $rows[] = ['key' => ClassicTheme::blockKey($row['theme'], $row['slug'])] + $row;
            }

            return $rows;
        }

        return $this->blockTemplates($type);
    }

    /**
     * What core's get_block_templates() returns for the active theme, one
     * row per slug.
     *
     * Read in en_US: core titles its default templates with `_x()` as they
     * are read, so a site in another language would get translated titles
     * (see EnglishLabels).
     *
     * @return list<TemplateRow>
     */
    private function blockTemplates(string $type): array
    {
        $templates = EnglishLabels::whileSwitched(
            static fn (): bool => switch_to_locale('en_US'),
            'restore_previous_locale',
            static fn (): array => get_block_templates([], $type)
        );

        $rows = [];

        foreach ($templates as $template) {
            $origin = $template->origin ?? '';
            $source = match (true) {
                (bool) $template->has_theme_file                         => 'theme',
                $template->source === 'plugin' || $origin === 'plugin' => 'plugin',
                default                                                => 'custom',
            };

            $file = $source === 'theme' ? self::themeFile($type, (string) $template->slug) : null;

            $rows[] = [
                'slug'          => (string) $template->slug,
                'title'         => (string) $template->title,
                'theme'         => (string) $template->theme,
                'source'        => $source,
                // A Site Editor copy (source `custom`) over a theme or plugin file.
                'user_modified' => $template->source === 'custom' && $source !== 'custom',
                'area'          => $type === 'wp_template_part' ? (string) $template->area : '',
                'content'       => (string) $template->content,
                'file'          => $file['file'] ?? null,
                'file_theme'    => $file['theme'] ?? null,
                'overrides'     => $file['overrides'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Which theme's file WordPress serves for a template or part, and the
     * parent theme it shadows when a child theme ships its own copy.
     *
     * WordPress reports every theme file under the active stylesheet, so the
     * template object cannot say this; the theme folders are checked the way
     * core's _get_block_template_file() does, child first. Theme slugs and a
     * path relative to wp-content leave this method, never an absolute path.
     *
     * @return array{theme: string, overrides: string|null, file: string|null}|null Null when neither theme has the file.
     */
    private static function themeFile(string $type, string $slug): ?array
    {
        $stylesheet = get_stylesheet();
        $parent = get_template();
        $inChild = self::themePath($stylesheet, get_stylesheet_directory(), $type, $slug);

        if ($stylesheet === $parent) {
            return $inChild !== null ? ['theme' => $stylesheet, 'overrides' => null, 'file' => SourceFile::relative($inChild)] : null;
        }

        $inParent = self::themePath($parent, get_template_directory(), $type, $slug);

        if ($inChild !== null) {
            return ['theme' => $stylesheet, 'overrides' => $inParent !== null ? $parent : null, 'file' => SourceFile::relative($inChild)];
        }

        return $inParent !== null ? ['theme' => $parent, 'overrides' => null, 'file' => SourceFile::relative($inParent)] : null;
    }

    /**
     * The absolute path of a theme's template or part file, or null when the
     * theme has none.
     */
    private static function themePath(string $theme, string $directory, string $type, string $slug): ?string
    {
        $folder = get_block_theme_folders($theme)[$type] ?? null;
        $path = is_string($folder) ? $directory . '/' . $folder . '/' . $slug . '.html' : null;

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * @return list<PatternRow>
     */
    public function syncedPatterns(): array
    {
        $rows = [];

        foreach ($this->posts('wp_block') as $post) {
            $rows[] = self::postRow($post) + [
                'synced' => get_post_meta($post->ID, 'wp_pattern_sync_status', true) !== 'unsynced',
            ];
        }

        return $rows;
    }

    /**
     * @return list<PostRow>
     */
    public function navigations(): array
    {
        return array_map([self::class, 'postRow'], $this->posts('wp_navigation'));
    }

    /**
     * Published posts of every public post type whose content carries a
     * CONTENT_MARKERS string.
     *
     * @return list<ContentRow>
     */
    public function content(): array
    {
        return $this->publishedContaining(self::CONTENT_MARKERS, null);
    }

    /**
     * @return list<ContentRow>
     */
    public function contentMatching(array $needles): array
    {
        $needles = array_values(array_filter($needles, static fn (string $needle): bool => $needle !== ''));

        return $needles === [] ? [] : $this->publishedContaining($needles, self::MATCHING_CAP);
    }

    /**
     * @return list<ContentRow>
     */
    public function contentOfType(string $type, ?int $id = null): array
    {
        return $type === '' ? [] : $this->publishedContaining([], self::MATCHING_CAP, $type, $id);
    }

    /**
     * Published posts of every public post type but attachments, or of
     * `$type` alone, whose content contains any of the strings, oldest
     * first. With `$type` and no strings, every post of that type. With
     * `$id`, that post alone, before the limit applies.
     *
     * @param list<string> $needles
     * @return list<ContentRow>
     */
    private function publishedContaining(array $needles, ?int $limit, ?string $type = null, ?int $id = null): array
    {
        global $wpdb;

        $types = $type !== null ? [$type] : array_values(array_diff(get_post_types(['public' => true]), ['attachment']));

        if ($types === [] || ($needles === [] && $type === null)) {
            return [];
        }

        $likes = array_map(
            static fn (string $needle): string => '%' . $wpdb->esc_like($needle) . '%',
            $needles
        );

        $typePlaceholders = implode(', ', array_fill(0, count($types), '%s'));
        $likeClauses = $likes === [] ? '1=1' : implode(' OR ', array_fill(0, count($likes), 'post_content LIKE %s'));
        $limitClause = $limit !== null ? ' LIMIT ' . (int) $limit : '';
        $idClause = $id !== null ? ' AND ID = ' . (int) $id : '';

        // The interpolated strings are placeholder lists built above and ints; every value goes through prepare().
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $sql = $wpdb->prepare("SELECT ID, post_type, post_name, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$typePlaceholders}) AND ({$likeClauses}){$idClause} ORDER BY ID ASC{$limitClause}", ...$types, ...$likes);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
        $results = $wpdb->get_results($sql, ARRAY_A);

        $rows = [];

        foreach (is_array($results) ? $results : [] as $result) {
            $slug = (string) $result['post_name'];

            $rows[] = [
                'type'    => (string) $result['post_type'],
                'slug'    => $slug !== '' ? $slug : '#' . $result['ID'],
                'content' => (string) $result['post_content'],
                'id'      => (int) $result['ID'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{title: string, content: string}|null
     */
    public function registeredPattern(string $slug): ?array
    {
        if (!class_exists(WP_Block_Patterns_Registry::class)) {
            return null;
        }

        $pattern = WP_Block_Patterns_Registry::get_instance()->get_registered($slug);

        if (!is_array($pattern)) {
            return null;
        }

        return [
            'title'   => is_string($pattern['title'] ?? null) ? $pattern['title'] : '',
            'content' => is_string($pattern['content'] ?? null) ? $pattern['content'] : '',
        ];
    }

    /**
     * What patternFile() found, by slug, kept for the request: a pattern
     * file is included at most once whatever asks for it.
     *
     * @var array<string, array{file: string|null, reads: list<string>|null}|null>
     */
    private static array $patternFiles = [];

    /** @var array<string, string|null>|null */
    private static ?array $siteData = null;

    /**
     * @return array{file: string|null, reads: list<string>|null}|null
     */
    public function patternFile(string $slug): ?array
    {
        if (array_key_exists($slug, self::$patternFiles)) {
            return self::$patternFiles[$slug];
        }

        $path = self::themePatternPath($slug);

        return self::$patternFiles[$slug] = $path === null
            ? null
            : ['file' => SourceFile::relative($path), 'reads' => ReadTracer::traceFile($path)];
    }

    /**
     * The absolute path of the file core registers a theme pattern slug
     * from: the active theme's `patterns/` folder, then its parent's, read
     * with WP_Theme::get_block_patterns() as _register_theme_block_patterns()
     * reads them (WordPress 6.4+). Found this way rather than from the
     * registry, which forgets a pattern's file once its content is built.
     */
    private static function themePatternPath(string $slug): ?string
    {
        $theme = wp_get_theme();
        $parent = $theme->parent();

        foreach ($parent instanceof WP_Theme ? [$theme, $parent] : [$theme] as $candidate) {
            foreach ($candidate->get_block_patterns() as $file => $pattern) {
                if (is_array($pattern) && ($pattern['slug'] ?? null) === $slug) {
                    $path = $candidate->get_stylesheet_directory() . '/patterns/' . $file;

                    return is_file($path) ? $path : null;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string|null>
     */
    public function siteDataFields(): array
    {
        return self::$siteData ??= OptionScreens::siteData();
    }

    public function siteDataScreen(): ?string
    {
        return OptionScreens::siteDataScreen();
    }

    public function optionText(string $option): ?string
    {
        $value = get_option($option, '');

        if ($value === false || $value === null || $value === []) {
            return '';
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }

    public function bindingSourceLabel(string $source): ?string
    {
        if (!function_exists('get_block_bindings_source')) {
            return null;
        }

        $registered = get_block_bindings_source($source);

        return $registered !== null ? (new EnglishLabels())->bindingSource($source, (string) $registered->label) : null;
    }

    public function bindingEditPath(string $source, ?string $key): ?string
    {
        $stated = self::statedMeta($source, $key);

        if (isset($stated['edit'])) {
            return $stated['edit'];
        }

        $path = apply_filters(self::BINDING_EDIT_PATH_FILTER, null, $source, $key);

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return $source === OptionScreens::SITE_DATA_SOURCE ? $this->siteDataScreen() : null;
    }

    public function bindingSourceFile(string $source): ?string
    {
        $registered = function_exists('get_block_bindings_source') ? get_block_bindings_source($source) : null;

        if (!$registered instanceof WP_Block_Bindings_Source) {
            return null;
        }

        try {
            // Core keeps the callback private; reading it is the only way to know where it lives.
            $callback = (new ReflectionProperty(WP_Block_Bindings_Source::class, 'get_value_callback'))->getValue($registered);
        } catch (Throwable $e) {
            return null;
        }

        return SourceFile::ofCallable($callback);
    }

    /**
     * Asks the source for its value the way core does while rendering,
     * through a WP_Block with no post as context, and records what it reads.
     * The value itself is thrown away.
     *
     * @return list<string>|null
     */
    public function bindingReads(string $source, ?string $key, array $args, string $block, string $attribute): ?array
    {
        $stated = self::statedMeta($source, $key);

        if (isset($stated['reads'])) {
            return $stated['reads'];
        }

        $registered = function_exists('get_block_bindings_source') ? get_block_bindings_source($source) : null;

        if (!$registered instanceof WP_Block_Bindings_Source) {
            return null;
        }

        return ReadTracer::trace(static function () use ($registered, $args, $block, $attribute): void {
            $instance = new WP_Block(
                ['blockName' => $block, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []],
                []
            );

            $registered->get_value($args, $instance, $attribute);
        });
    }

    /**
     * What a source states about one key through BINDING_META_FILTER, with
     * anything malformed left out.
     *
     * @return array{reads?: list<string>, edit?: string}
     */
    private static function statedMeta(string $source, ?string $key): array
    {
        $meta = apply_filters(self::BINDING_META_FILTER, [], $source, $key);
        $stated = [];

        if (is_array($meta) && is_array($meta['reads'] ?? null)) {
            $stated['reads'] = ReadTracer::ids($meta['reads']);
        }

        if (is_array($meta) && is_string($meta['edit'] ?? null) && $meta['edit'] !== '') {
            $stated['edit'] = $meta['edit'];
        }

        return $stated;
    }

    /**
     * From the settings register_setting() records, the array
     * get_registered_settings() returns. Each row carries its option's
     * `group` (register_setting() sets it, wp-includes/option.php) and, from
     * WordPress 6.6, its `label`. The global is read rather than the
     * function because the WordPress stubs leave `group` out of the row's
     * shape.
     *
     * @return array<string, string|null>
     */
    public function settingsBeside(string $option): array
    {
        global $wp_registered_settings;

        $settings = is_array($wp_registered_settings) ? $wp_registered_settings : [];
        $row = $settings[$option] ?? null;
        $group = is_array($row) ? ($row['group'] ?? null) : null;

        if (!is_string($group) || $group === '' || in_array($group, self::CORE_SETTINGS_GROUPS, true)) {
            return [];
        }

        $beside = [];

        foreach ($settings as $name => $args) {
            if ((string) $name !== $option && is_array($args) && ($args['group'] ?? null) === $group) {
                $label = $args['label'] ?? null;
                $beside[(string) $name] = is_string($label) && $label !== '' ? $label : null;
            }
        }

        ksort($beside, SORT_STRING);

        return $beside;
    }

    /**
     * @return list<string>
     */
    public function metaPostTypes(string $key): array
    {
        $types = [];

        foreach (get_post_types() as $postType) {
            if (isset(get_registered_meta_keys('post', $postType)[$key])) {
                $types[] = $postType;
            }
        }

        sort($types, SORT_STRING);

        return $types;
    }

    /**
     * @return list<MenuLocationRow>
     */
    public function menuLocations(): array
    {
        if (!ClassicTheme::isActive()) {
            return [];
        }

        $assigned = get_nav_menu_locations();
        $rows = [];

        foreach (get_registered_nav_menus() as $slug => $label) {
            $menu = isset($assigned[$slug]) ? wp_get_nav_menu_object((int) $assigned[$slug]) : false;

            $rows[] = [
                'slug'  => (string) $slug,
                'label' => (string) $label,
                'menu'  => $menu instanceof WP_Term
                    ? ['id' => (int) $menu->term_id, 'name' => (string) $menu->name, 'items' => (int) $menu->count]
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<SidebarRow>
     */
    public function sidebars(): array
    {
        global $wp_registered_sidebars;

        if (!ClassicTheme::isActive() || !is_array($wp_registered_sidebars)) {
            return [];
        }

        // What core's wp_get_sidebars_widgets() returns outside a Customizer
        // preview. That function is marked private, so read the option and
        // run core's filter on it here.
        $widgets = get_option('sidebars_widgets', []);

        if (is_array($widgets)) {
            unset($widgets['array_version']);
        }

        $widgets = apply_filters('sidebars_widgets', $widgets);
        $widgets = is_array($widgets) ? $widgets : [];
        $rows = [];

        foreach ($wp_registered_sidebars as $id => $sidebar) {
            $id = (string) $id;
            $name = is_array($sidebar) && is_string($sidebar['name'] ?? null) ? $sidebar['name'] : '';

            $rows[] = [
                'id'      => $id,
                'name'    => $name,
                'widgets' => is_array($widgets[$id] ?? null) ? count($widgets[$id]) : 0,
            ];
        }

        return $rows;
    }

    /**
     * @return list<WP_Post>
     */
    private function posts(string $postType): array
    {
        return array_values(get_posts([
            'post_type'   => $postType,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'ID',
            'order'       => 'ASC',
        ]));
    }

    /**
     * @return PostRow
     */
    private static function postRow(WP_Post $post): array
    {
        return [
            'id'      => (int) $post->ID,
            'slug'    => (string) $post->post_name,
            'title'   => (string) $post->post_title,
            'content' => (string) $post->post_content,
        ];
    }

    public function builderTemplates(): array
    {
        return WordPressBuilders::templates();
    }
}
