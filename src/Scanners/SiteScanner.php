<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Closure;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use WP_Block_Editor_Context;
use WP_Post;

/**
 * The `site` section: one `opt:` item per allowlisted setting, the WordPress
 * version, the active theme and its parent, and the theme.json gates
 * (ThemeGates).
 *
 * Options are read only by the names OptionAllowlist::names() returns, so a
 * setting the allowlist does not name is never read, let alone emitted.
 */
final class SiteScanner implements Scanner
{
    public const SECTION = 'site';

    /** Options naming a theme. Their items also carry its name and version. */
    private const THEME_OPTIONS = ['stylesheet', 'template'];

    /** @var Closure(string): mixed */
    private Closure $getOption;

    /** @var Closure(int): ?string */
    private Closure $pageSlug;

    /** @var Closure(string): ?array{name: string, version: string} */
    private Closure $theme;

    /** @var Closure(): string */
    private Closure $wordpressVersion;

    /** @var Closure(string): ?array<string, mixed> */
    private Closure $globalSettings;

    /** @var Closure(): array<string, mixed> */
    private Closure $allowedBlocks;

    /**
     * The resolver the theme's own settings come from. A typed property, not
     * a class constant, so static analysis keeps the method_exists() guard.
     */
    private static string $resolver = 'WP_Theme_JSON_Resolver';

    /**
     * @param (callable(string): mixed)|null                                 $getOption        Defaults to get_option().
     * @param (callable(int): ?string)|null                                  $pageSlug         Post name for a post id, '' when it has none, null when there is no such post.
     * @param (callable(string): ?array{name: string, version: string})|null $theme            Name and Version headers of an installed theme, null when it is not installed.
     * @param (callable(): string)|null                                      $wordpressVersion Defaults to get_bloginfo('version').
     * @param (callable(string): ?array<string, mixed>)|null                  $globalSettings   Settings for a layer: `merged` (every origin), `base` (all but the user's global styles) or `theme` (what the theme declares). Null when WordPress has none.
     * @param (callable(): array<string, mixed>)|null                         $allowedBlocks    Post type => what allowed_block_types_all returns for its editor.
     */
    public function __construct(
        ?callable $getOption = null,
        ?callable $pageSlug = null,
        ?callable $theme = null,
        ?callable $wordpressVersion = null,
        ?callable $globalSettings = null,
        ?callable $allowedBlocks = null
    ) {
        // An explicit default skips the defaults register_setting() declares.
        // Core registers WPLANG with `en_US` on rest_api_init and admin_init
        // only, so without it REST read `en_US` where WP-CLI read nothing,
        // and the same site had two manifest hashes.
        $this->getOption = $getOption !== null
            ? Closure::fromCallable($getOption)
            : static fn (string $name): mixed => get_option($name, false);

        $this->pageSlug = $pageSlug !== null
            ? Closure::fromCallable($pageSlug)
            : static function (int $id): ?string {
                $post = get_post($id);

                return $post instanceof WP_Post ? $post->post_name : null;
            };

        $this->theme = $theme !== null
            ? Closure::fromCallable($theme)
            : static function (string $slug): ?array {
                $theme = wp_get_theme($slug);

                if (!$theme->exists()) {
                    return null;
                }

                // get() is the raw header: never translated, so locale-independent.
                $name = $theme->get('Name');
                $version = $theme->get('Version');

                return [
                    'name'    => is_string($name) ? $name : '',
                    'version' => is_string($version) ? $version : '',
                ];
            };

        $this->wordpressVersion = $wordpressVersion !== null
            ? Closure::fromCallable($wordpressVersion)
            : static fn (): string => (string) get_bloginfo('version');

        $this->globalSettings = $globalSettings !== null
            ? Closure::fromCallable($globalSettings)
            : Closure::fromCallable([self::class, 'wordpressSettings']);

        $this->allowedBlocks = $allowedBlocks !== null
            ? Closure::fromCallable($allowedBlocks)
            : Closure::fromCallable([self::class, 'wordpressAllowedBlocks']);
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function scan(): Section
    {
        $values = [];

        foreach (OptionAllowlist::names() as $name) {
            $values[$name] = $this->value($name);
        }

        $items = [
            new Item('core:wordpress', 'core', 'WordPress', ['version' => ($this->wordpressVersion)()], 'update-core.php'),
        ];

        foreach ($values as $name => $value) {
            [$label, $edit] = OptionAllowlist::OPTIONS[$name];
            $facts = ['value' => $value];
            $refs = [];

            if ($value !== null && in_array($name, self::THEME_OPTIONS, true)) {
                $facts += ($this->theme)($value) ?? [];
            }

            // A child theme points at its parent.
            if ($name === 'stylesheet' && $value !== null && isset($values['template']) && $values['template'] !== $value) {
                $refs[] = 'opt:template';
            }

            $items[] = new Item('opt:' . $name, 'option', $label, $facts, $edit, $refs);
        }

        $merged = ($this->globalSettings)('merged');

        if ($merged !== null) {
            $items[] = ThemeGates::item(
                $merged,
                ($this->globalSettings)('base') ?? $merged,
                ($this->globalSettings)('theme') ?? [],
                ($this->allowedBlocks)()
            );
        }

        return new Section(self::SECTION, $items);
    }

    /**
     * Settings from wp_get_global_settings(), the one public reader. Its
     * `origin` context takes `base` for everything but the user's global
     * styles; any other value, `theme` included, returns the merged
     * settings. What the theme declares on its own has no public reader, so
     * it comes from the resolver behind a method_exists() guard, and is
     * empty without one (every gate then reads as `default` or `user`).
     *
     * @return array<string, mixed>|null
     */
    private static function wordpressSettings(string $layer): ?array
    {
        if (!function_exists('wp_get_global_settings')) {
            return null;
        }

        if ($layer === 'theme') {
            $resolver = self::$resolver;

            if (!class_exists($resolver) || !method_exists($resolver, 'get_theme_data')) {
                return [];
            }

            $data = $resolver::get_theme_data();
            $settings = is_object($data) && method_exists($data, 'get_settings') ? $data->get_settings() : [];
        } else {
            $settings = wp_get_global_settings([], $layer === 'base' ? ['origin' => 'base'] : []);
        }

        return is_array($settings) ? $settings : [];
    }

    /**
     * What allowed_block_types_all returns for a new post of each public
     * post type the block editor can open, sorted by post type.
     *
     * @return array<string, mixed>
     */
    private static function wordpressAllowedBlocks(): array
    {
        if (!function_exists('get_allowed_block_types') || !class_exists(WP_Block_Editor_Context::class)) {
            return [];
        }

        $allowed = [];
        $types = get_post_types(['public' => true, 'show_in_rest' => true], 'names');
        sort($types, SORT_STRING);

        foreach ($types as $type) {
            if ($type === 'attachment' || !post_type_supports($type, 'editor')) {
                continue;
            }

            $post = new WP_Post((object) ['ID' => 0, 'post_type' => $type, 'post_status' => 'auto-draft', 'filter' => 'raw']);
            $allowed[$type] = get_allowed_block_types(new WP_Block_Editor_Context(['post' => $post]));
        }

        return $allowed;
    }

    /**
     * Strings as stored, page ids as page slugs, anything else as null.
     */
    private function value(string $name): ?string
    {
        $value = ($this->getOption)($name);

        if (!in_array($name, OptionAllowlist::PAGE_OPTIONS, true)) {
            // get_option() returns false for a missing option; record that as null.
            return is_string($value) ? $value : null;
        }

        $id = is_numeric($value) ? (int) $value : 0;

        if ($id <= 0) {
            return null;
        }

        $slug = ($this->pageSlug)($id);

        // The format doc's fallback where WordPress has no slug: the row id, prefixed `#`.
        return $slug === '' ? '#' . $id : $slug;
    }
}
