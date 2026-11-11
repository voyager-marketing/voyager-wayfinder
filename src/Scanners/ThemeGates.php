<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Voyager\Wayfinder\Manifest\Item;

/**
 * The `gates:theme` item: which editor controls theme.json, the theme and
 * the user's global styles turn off, where each one is turned off, and which
 * post types limit the blocks the editor offers.
 *
 * Built from plain settings arrays so it runs without WordPress. SiteScanner
 * reads them (wp_get_global_settings() and allowed_block_types_all) and hands
 * them over. Only on and off is recorded, never a preset, palette or token.
 *
 * Facts:
 * - `settings`: every tracked setting, `path => bool` (true when the control
 *   shows). appearanceTools is on when set, or when everything it turns on is.
 * - `gates`: the tracked settings that are off, `path => origin`.
 * - `block_overrides`: per-block settings that differ from the top level,
 *   `{block, origin, setting, value}`, sorted by block then setting.
 * - `allowed_blocks`: post type => `all`, or how many blocks its editor allows.
 *
 * Origin is `user` when the user's global styles change the value, `theme`
 * when the theme declares the setting (theme.json, its parent's, or a theme
 * support such as `disable-custom-colors`), else `default` (core's own
 * theme.json, or a block or plugin layer).
 */
final class ThemeGates
{
    public const ID = 'gates:theme';

    public const KIND = 'gates';

    /** Styles in the Site Editor: where a user-origin gate is turned back on. */
    public const STYLES_EDIT = 'site-editor.php?p=/styles&canvas=edit';

    /**
     * Tracked settings, by theme.json path. `layout.*` and
     * `typography.fontFamilies` record presence, never the value.
     */
    public const SETTINGS = [
        'appearanceTools',
        'border.color',
        'border.radius',
        'border.style',
        'border.width',
        'color.custom',
        'color.customGradient',
        'color.defaultPalette',
        'layout.contentSize',
        'layout.wideSize',
        'spacing.blockGap',
        'spacing.margin',
        'spacing.padding',
        'typography.customFontSize',
        'typography.fontFamilies',
    ];

    /**
     * Settings appearanceTools turns on. Core expands appearanceTools into
     * them and then unsets it, so merged settings never carry it as true;
     * it reads as on when they are all on. blockGap is left out: themes turn
     * it off on its own for layout reasons.
     */
    public const APPEARANCE_TOOLS = [
        'border.color',
        'border.radius',
        'border.style',
        'border.width',
        'spacing.margin',
        'spacing.padding',
    ];

    /** Plain words for an off setting, as the brief and the index print it. */
    public const WORDS = [
        'appearanceTools'           => 'appearanceTools off',
        'border.color'              => 'border color off',
        'border.radius'             => 'border radius off',
        'border.style'              => 'border style off',
        'border.width'              => 'border width off',
        'color.custom'              => 'custom colors off',
        'color.customGradient'      => 'custom gradients off',
        'color.defaultPalette'      => 'default palette off',
        'layout.contentSize'        => 'no content width',
        'layout.wideSize'           => 'no wide width',
        'spacing.blockGap'          => 'block spacing off',
        'spacing.margin'            => 'margin off',
        'spacing.padding'           => 'padding off',
        'typography.customFontSize' => 'custom font sizes off',
        'typography.fontFamilies'   => 'no font families',
    ];

    /**
     * @param array<string, mixed> $merged   Settings with every layer merged, the user's included.
     * @param array<string, mixed> $base     The same without the user's global styles.
     * @param array<string, mixed> $declared What the theme itself declares.
     * @param array<string, mixed> $allowed  Post type => what allowed_block_types_all returned for its editor.
     */
    public static function item(array $merged, array $base, array $declared, array $allowed = []): Item
    {
        $settings = [];
        $gates = [];

        foreach (self::SETTINGS as $path) {
            $value = self::on($merged, $path);
            $settings[$path] = $value;

            if (!$value) {
                $gates[$path] = self::origin($path, $merged, $base, $declared);
            }
        }

        $overrides = self::blockOverrides($merged, $base, $declared, $settings);

        $facts = [
            'allowed_blocks'  => self::allowedBlocks($allowed),
            'block_overrides' => $overrides,
            'gates'           => $gates,
            'settings'        => $settings,
        ];

        $user = in_array('user', $gates, true)
            || in_array('user', array_column($overrides, 'origin'), true);

        return new Item(self::ID, self::KIND, 'Theme gates', $facts, $user ? self::STYLES_EDIT : null);
    }

    /**
     * Whether the control a path stands for shows. A missing or null value
     * counts as off: core reads it that way.
     *
     * @param array<string, mixed> $settings
     */
    public static function on(array $settings, string $path): bool
    {
        $value = self::at($settings, $path);

        if ($path === 'appearanceTools' && $value !== true) {
            foreach (self::APPEARANCE_TOOLS as $optIn) {
                if (!self::on($settings, $optIn)) {
                    return false;
                }
            }

            return true;
        }

        if ($path === 'typography.fontFamilies') {
            return self::hasPresets($value);
        }

        if (str_starts_with($path, 'layout.')) {
            return is_string($value) && $value !== '';
        }

        return $value === true;
    }

    /**
     * Who set the value the merged settings carry.
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $base
     * @param array<string, mixed> $declared
     */
    private static function origin(string $path, array $merged, array $base, array $declared): string
    {
        if (self::on($merged, $path) !== self::on($base, $path)) {
            return 'user';
        }

        return self::declares($declared, $path) ? 'theme' : 'default';
    }

    /**
     * Whether the theme sets a path. For appearanceTools, which core unsets
     * once expanded, also whether the theme turns off something it expands
     * into: a theme that only turns padding on has not turned it off.
     *
     * @param array<string, mixed> $declared
     */
    private static function declares(array $declared, string $path): bool
    {
        if (self::at($declared, $path) !== null) {
            return true;
        }

        if ($path !== 'appearanceTools') {
            return false;
        }

        foreach (self::APPEARANCE_TOOLS as $optIn) {
            if (self::at($declared, $optIn) !== null && !self::on($declared, $optIn)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per-block settings that set a tracked path to something other than
     * the top level does.
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $base
     * @param array<string, mixed> $declared
     * @param array<string, bool>  $top
     * @return list<array{block: string, origin: string, setting: string, value: bool}>
     */
    private static function blockOverrides(array $merged, array $base, array $declared, array $top): array
    {
        $blocks = $merged['blocks'] ?? null;

        if (!is_array($blocks)) {
            return [];
        }

        $overrides = [];

        foreach ($blocks as $name => $settings) {
            if (!is_string($name) || !is_array($settings)) {
                continue;
            }

            foreach (self::SETTINGS as $path) {
                if (self::at($settings, $path) === null) {
                    continue;
                }

                $value = self::on($settings, $path);

                if ($value === $top[$path]) {
                    continue;
                }

                $overrides[] = [
                    'block'   => $name,
                    'origin'  => self::origin($path, $settings, self::blockSettings($base, $name), self::blockSettings($declared, $name)),
                    'setting' => $path,
                    'value'   => $value,
                ];
            }
        }

        usort($overrides, static fn (array $a, array $b): int => [$a['block'], $a['setting']] <=> [$b['block'], $b['setting']]);

        return $overrides;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function blockSettings(array $settings, string $name): array
    {
        $block = $settings['blocks'][$name] ?? null;

        return is_array($block) ? $block : [];
    }

    /**
     * `all` when the editor allows every block, else how many it allows.
     *
     * @param array<string, mixed> $allowed
     * @return array<string, int|string>
     */
    private static function allowedBlocks(array $allowed): array
    {
        $counts = [];

        foreach ($allowed as $postType => $blocks) {
            if (is_array($blocks)) {
                $counts[(string) $postType] = count(array_unique(array_filter($blocks, 'is_string')));
            } else {
                $counts[(string) $postType] = $blocks === false ? 0 : 'all';
            }
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * Font family presets from any origin. Merged settings key presets by
     * origin (`default`, `theme`, `custom`); a raw theme.json lists them.
     */
    private static function hasPresets(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }

        if (array_is_list($value)) {
            return true;
        }

        foreach ($value as $presets) {
            if (is_array($presets) && $presets !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function at(array $settings, string $path): mixed
    {
        $value = $settings;

        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}
