<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Closure;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;

/**
 * The `integrations` section: one `plugin:<slug>` item per active plugin,
 * with its name, version and role (forms, seo, fields, ...) from PluginRoles.
 *
 * Only plugin headers are read. Nothing here reads a plugin's settings,
 * licenses or keys.
 */
final class IntegrationsScanner implements Scanner
{
    public const SECTION = 'integrations';

    /** @var Closure(): array<string, array<string, mixed>> */
    private Closure $activePlugins;

    private ?PluginRoles $roles;

    /**
     * @param (callable(): array<string, array<string, mixed>>)|null $activePlugins Active plugins as get_plugins() returns them: `folder/main.php` => headers.
     * @param PluginRoles|null                                        $roles         Defaults to data/plugin-roles.json, read on the first scan.
     */
    public function __construct(?callable $activePlugins = null, ?PluginRoles $roles = null)
    {
        $this->activePlugins = $activePlugins !== null
            ? Closure::fromCallable($activePlugins)
            : self::lookupActivePlugins(...);

        $this->roles = $roles;
    }

    /**
     * Default lookup: active plugins as get_plugins() returns them. Builders
     * (see WordPressBuilders) read the same list, so plugins are scanned once.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function lookupActivePlugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // get_plugins() reads headers untranslated, so names do not depend on the locale.
        return array_filter(get_plugins(), 'is_plugin_active', ARRAY_FILTER_USE_KEY);
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function scan(): Section
    {
        $this->roles ??= PluginRoles::bundled();

        $plugins = ($this->activePlugins)();
        $slugs = [];

        foreach (array_keys($plugins) as $file) {
            $slugs[(string) $file] = self::slug((string) $file);
        }

        $perSlug = array_count_values($slugs);
        $items = [];

        foreach ($plugins as $file => $headers) {
            $file = (string) $file;
            $slug = $slugs[$file];

            // Two active plugin files in one folder share a slug. Name both by
            // file instead (`folder/main`), so neither replaces the other.
            $id = $perSlug[$slug] > 1 ? self::safe((string) preg_replace('/\.php$/', '', $file)) : $slug;

            $items[] = new Item(
                'plugin:' . $id,
                'plugin',
                self::header($headers, 'Name') ?? $slug,
                [
                    'role'    => $this->roles->role($slug),
                    'version' => self::header($headers, 'Version'),
                ],
                'plugins.php'
            );
        }

        return new Section(self::SECTION, $items);
    }

    /**
     * The plugin's folder, or the file name of a single-file plugin
     * (`hello.php` is `hello`): the slug WordPress.org and WP-CLI use.
     */
    public static function slug(string $file): string
    {
        $slash = strpos($file, '/');

        return self::safe($slash === false ? basename($file, '.php') : substr($file, 0, $slash));
    }

    /**
     * Ids may not hold whitespace, `|` or `,`. A folder name can.
     */
    private static function safe(string $slug): string
    {
        return (string) preg_replace('/[\s|,]+/', '-', $slug);
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function header(array $headers, string $key): ?string
    {
        $value = $headers[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
