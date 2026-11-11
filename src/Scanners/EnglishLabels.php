<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Closure;

/**
 * Core's labels in English, so the manifest body is the same bytes in any
 * site language (AGENTS.md rule 1).
 *
 * WordPress translates its own labels in two ways, and each has its fix:
 *
 * - Default template titles are `_x()` strings, translated when templates
 *   are read. WordPressLayoutSource reads them inside whileSwitched(), with
 *   WordPress switched to en_US.
 * - Core post types, taxonomies and block binding sources are labelled when
 *   core registers them. postType(), taxonomy() and bindingSource() swap
 *   such a label for core's English one.
 *
 * A label is swapped only while it is the one core gives it in the site's
 * language, so a label a theme or plugin registered, or changed on a core
 * type, is recorded as registered. On an en_US site nothing changes.
 */
final class EnglishLabels
{
    /**
     * Core post types the content model can hold, with core's English label
     * and the gettext context core translates it with (WordPress 7.1.2). The
     * menu name is the same string.
     */
    public const POST_TYPES = [
        'attachment' => ['Media', 'post type general name'],
        'page'       => ['Pages', 'post type general name'],
        'post'       => ['Posts', 'post type general name'],
    ];

    /** Core taxonomies the content model can hold, as POST_TYPES. */
    public const TAXONOMIES = [
        'category'    => ['Categories', 'taxonomy general name'],
        'post_format' => ['Formats', 'post format'],
        'post_tag'    => ['Tags', 'taxonomy general name'],
    ];

    /** Every block bindings source WordPress 7.1.2 registers, as POST_TYPES. */
    public const BINDING_SOURCES = [
        'core/pattern-overrides' => ['Pattern Overrides', 'block bindings source'],
        'core/post-data'         => ['Post Data', 'block bindings source'],
        'core/post-meta'         => ['Post Meta', 'block bindings source'],
        'core/term-data'         => ['Term Data', 'block bindings source'],
    ];

    /** @var Closure(string, string): string */
    private Closure $translate;

    /**
     * @param (callable(string, string): string)|null $translate Core's translation of an English string with a gettext
     *                                                           context, in the language WordPress is in. Defaults to
     *                                                           lookupTranslation().
     */
    public function __construct(?callable $translate = null)
    {
        $this->translate = $translate !== null ? Closure::fromCallable($translate) : self::lookupTranslation(...);
    }

    public function postType(string $slug, string $label): string
    {
        return $this->english(self::POST_TYPES[$slug] ?? null, $label);
    }

    public function taxonomy(string $slug, string $label): string
    {
        return $this->english(self::TAXONOMIES[$slug] ?? null, $label);
    }

    public function bindingSource(string $source, string $label): string
    {
        return $this->english(self::BINDING_SOURCES[$source] ?? null, $label);
    }

    /**
     * @param array{0: string, 1: string}|null $core Core's English label and its gettext context.
     */
    private function english(?array $core, string $label): string
    {
        if ($core === null) {
            return $label;
        }

        [$english, $context] = $core;

        return $label === ($this->translate)($english, $context) ? $english : $label;
    }

    /**
     * Runs `$read` with WordPress switched to en_US, and switches back only
     * when `$switch` did: switch_to_locale() returns false on a site that is
     * already in en_US, and restoring then would pop someone else's switch.
     *
     * A switch fires `change_locale`, on which core registers its own post
     * types and taxonomies again. That drops what was added to them after
     * init, such as a custom post type attached to `category`, so the
     * objects are put back afterwards and a scan leaves the site as it found
     * it.
     *
     * @template T
     * @param callable(): bool $switch  Switches WordPress to en_US. False when it did not switch.
     * @param callable(): mixed $restore Switches back.
     * @param callable(): T    $read
     * @return T
     */
    public static function whileSwitched(callable $switch, callable $restore, callable $read): mixed
    {
        $saved = [];

        foreach (['wp_post_types', 'wp_taxonomies'] as $global) {
            if (array_key_exists($global, $GLOBALS)) {
                $saved[$global] = $GLOBALS[$global];
            }
        }

        $switched = $switch();

        try {
            return $read();
        } finally {
            if ($switched) {
                $restore();

                foreach ($saved as $global => $value) {
                    // Core's own objects as they were before the switch, not new ones.
                    $GLOBALS[$global] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                }
            }
        }
    }

    /**
     * Default lookup: what core's translation catalog gives for an English
     * string. It reads the catalog rather than calling `_x()`, so a gettext
     * filter that renames a core label is a change the site made, and the
     * renamed label is kept.
     */
    public static function lookupTranslation(string $text, string $context): string
    {
        if (!function_exists('get_translations_for_domain')) {
            return $text;
        }

        return (string) get_translations_for_domain('default')->translate($text, $context);
    }
}
