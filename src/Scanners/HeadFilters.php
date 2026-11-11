<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\Item;

/**
 * `filter:` items in the code section: one per theme, plugin or mu-plugin
 * callback on a hook that changes what a page puts in its head (the
 * document title, the meta description, Open Graph and Twitter tags) or an
 * archive's title and description. Core's own callbacks and PHP built-ins
 * (`trim` on `wp_title`) are left out.
 *
 * Each names the hook, what it changes (`og:title`), the callback and its
 * file. A callback in the active theme or an mu-plugin is also read, its
 * own body and one level below: the conditional tags it checks
 * (`is_front_page()`, `is_post_type_archive('services')`), each mapped to the
 * route it limits the filter to; whether it returns fixed text typed into
 * the code; and the settings it reads (`get_bloginfo('name')` reads option
 * `blogname`). The text itself is not kept.
 *
 * A hook an SEO plugin owns (`rank_math/`, `wpseo_`, `aioseo_`,
 * `seopress_`) carries `applied_by`, the plugin that applies it: a theme
 * filter on `rank_math/opengraph/facebook/og_title` changes the og:title
 * Rank Math prints. The plugin's own head callbacks are added only on front
 * end requests, so a scan never sees them, and no callback on a core title
 * hook does not mean nothing changes the title: where the title comes from
 * otherwise is not traced.
 *
 * @phpstan-import-type HookCallback from CodeSource
 * @phpstan-import-type Step from TokenReader
 */
final class HeadFilters
{
    public const PREFIX = 'filter:';

    public const KIND = 'head_filter';

    /** Hooks read in full, by what they change. */
    public const HOOKS = [
        'aioseo_description'             => 'description',
        'aioseo_title'                   => 'title',
        'document_title'                 => 'title',
        'document_title_parts'           => 'title',
        'document_title_separator'       => 'title',
        'get_the_archive_description'    => 'archive description',
        'get_the_archive_title'          => 'archive title',
        'pre_get_document_title'         => 'title',
        'rank_math/frontend/description' => 'description',
        'rank_math/frontend/title'       => 'title',
        'seopress_titles_desc'           => 'description',
        'seopress_titles_title'          => 'title',
        'wp_title'                       => 'title',
        'wpseo_metadesc'                 => 'description',
        'wpseo_title'                    => 'title',
    ];

    /** The SEO plugin that applies a hook, by the hook's prefix. */
    public const APPLIED_BY = [
        'aioseo_'    => 'all-in-one-seo-pack',
        'rank_math/' => 'seo-by-rank-math',
        'seopress_'  => 'wp-seopress',
        'wpseo_'     => 'wordpress-seo',
    ];

    /** Conditional tags, and the route each limits a filter to when it does. */
    public const CONDITIONS = [
        'is_404'               => null,
        'is_archive'           => null,
        'is_author'            => null,
        'is_category'          => null,
        'is_front_page'        => 'route:front-page',
        'is_home'              => 'route:posts-page',
        'is_page'              => null,
        'is_post_type_archive' => 'route:archive-%s',
        'is_search'            => null,
        'is_single'            => null,
        'is_singular'          => 'route:single-%s',
        'is_tag'               => null,
        'is_tax'               => null,
    ];

    private CodeSource $source;

    private TokenReader $reader;

    public function __construct(CodeSource $source, TokenReader $reader)
    {
        $this->source = $source;
        $this->reader = $reader;
    }

    /**
     * Whether a hook is one this reads.
     */
    public static function matches(string $hook): bool
    {
        return self::changes($hook) !== null;
    }

    /**
     * What a hook changes: `title`, `description`, `og:title`,
     * `archive description`, ... Null for a hook this does not read.
     */
    public static function changes(string $hook): ?string
    {
        if (isset(self::HOOKS[$hook])) {
            return self::HOOKS[$hook];
        }

        if (str_starts_with($hook, 'rank_math/opengraph/')) {
            $last = substr((string) strrchr('/' . $hook, '/'), 1);

            return preg_match('/^(og|twitter)_(.+)$/', $last, $match) === 1 ? $match[1] . ':' . $match[2] : 'og:' . $last;
        }

        foreach (['wpseo_opengraph_' => 'og:', 'wpseo_twitter_' => 'twitter:'] as $prefix => $tag) {
            if (str_starts_with($hook, $prefix) && strlen($hook) > strlen($prefix)) {
                return $tag . substr($hook, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * The SEO plugin that applies a hook, by slug, or null for a core hook.
     */
    public static function appliedBy(string $hook): ?string
    {
        foreach (self::APPLIED_BY as $prefix => $plugin) {
            if (str_starts_with($hook, $prefix)) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * @return list<Item> Sorted by id.
     */
    public function items(): array
    {
        $items = [];

        foreach ($this->source->hookCallbacks(self::matches(...)) as $row) {
            $callback = $row['callback'];
            $relative = $callback['file'] !== null ? $this->source->relative($callback['file']) : null;
            $owner = CodeScanner::owner($relative);

            if ($owner === null) {
                continue;
            }

            $id = self::id($row['hook'], $callback['name']);

            // Two closures on one hook: number the later ones, in the order they run.
            for ($n = 2; isset($items[$id]); $n++) {
                $id = self::id($row['hook'], $callback['name']) . '-' . $n;
            }

            $items[$id] = $this->item($id, $row, $relative, $owner);
        }

        ksort($items, SORT_STRING);

        return array_values($items);
    }

    /**
     * `filter:rank_math/opengraph/facebook/og_title@voyagermark_og_title`.
     */
    public static function id(string $hook, string $callback): string
    {
        return self::PREFIX . (string) preg_replace('/[\s|,]+/', '-', $hook . '@' . ltrim($callback, '\\'));
    }

    /**
     * @param HookCallback                      $row
     * @param array{kind: string, slug: string} $owner
     */
    private function item(string $id, array $row, ?string $relative, array $owner): Item
    {
        $hook = $row['hook'];
        $callback = $row['callback'];
        $appliedBy = self::appliedBy($hook);
        $refs = [];

        if ($appliedBy !== null) {
            $refs[] = 'plugin:' . $appliedBy;
        }

        if ($owner['kind'] === 'plugin') {
            $refs[] = 'plugin:' . $owner['slug'];
        }

        $facts = [
            'applied_by' => $appliedBy,
            'callback'   => $callback['name'],
            'changes'    => (string) self::changes($hook),
            'file'       => $relative,
            'hook'       => $hook,
            'owner'      => $owner,
            'priority'   => $row['priority'],
        ];

        $steps = CodeScanner::follows($owner) ? CodeScanner::follow($this->source, $this->reader, $callback, 1) : null;

        if ($steps === null || $steps === []) {
            $facts['traced'] = false;
        } else {
            [$conditions, $routes] = self::conditions($steps[0]);
            $facts += [
                'conditions'         => $conditions,
                'reads'              => self::reads($steps),
                'returns_fixed_text' => self::returnsFixedText($steps[0]),
                'traced'             => true,
            ];
            $refs = [...$refs, ...$routes];
        }

        return new Item(
            $id,
            self::KIND,
            'Filter on ' . (string) self::changes($hook),
            $facts,
            null,
            $refs,
            ['lines' => ['callback' => CodeScanner::span($relative, $callback['start'], $callback['end'])]]
        );
    }

    /**
     * The conditional tags the callback's own body checks, as written with
     * their literal arguments (`is_post_type_archive('services')`), sorted,
     * and the routes they name.
     *
     * @param Step $step
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function conditions(array $step): array
    {
        $conditions = [];
        $routes = [];

        foreach ($step['body']['calls'] as $call) {
            if (!array_key_exists($call['function'], self::CONDITIONS)) {
                continue;
            }

            $arg = $call['args'][0] ?? null;
            $literal = ($arg['kind'] ?? null) === 'string' && preg_match('/^[a-z0-9_-]+$/', (string) $arg['value']) === 1 ? (string) $arg['value'] : null;
            $conditions[] = $call['function'] . '(' . ($literal !== null ? "'" . $literal . "'" : ($arg !== null ? '...' : '')) . ')';
            $route = self::CONDITIONS[$call['function']];

            if ($route === null) {
                continue;
            }

            if (str_contains($route, '%s')) {
                $route = $literal !== null ? sprintf($route, $literal) : null;
            }

            if ($route !== null && IdFormat::isValid($route)) {
                $routes[] = $route;
            }
        }

        $conditions = array_values(array_unique($conditions));
        sort($conditions, SORT_STRING);

        return [$conditions, array_values(array_unique($routes))];
    }

    /**
     * Whether the callback's own body returns a string literal, bare or as
     * one branch of a ternary.
     *
     * @param Step $step
     */
    private static function returnsFixedText(array $step): bool
    {
        foreach ($step['body']['returns'] as $return) {
            if ($return['kind'] === 'string' && trim((string) $return['value']) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * What the callback and what it calls read, by name, sorted:
     * `option:<name>` (get_option(), get_bloginfo()), `meta:<key>`
     * (get_post_meta() with a literal key, `meta:*` otherwise) and
     * `theme_mod:<name>`.
     *
     * @param list<Step> $steps
     * @return list<string>
     */
    private static function reads(array $steps): array
    {
        $reads = [];

        foreach ($steps as $step) {
            foreach ($step['body']['calls'] as $call) {
                $first = ($call['args'][0]['kind'] ?? null) === 'string' ? (string) $call['args'][0]['value'] : null;
                $second = ($call['args'][1]['kind'] ?? null) === 'string' ? (string) $call['args'][1]['value'] : null;

                $read = match (true) {
                    $call['function'] === 'get_option' && $first !== null                                          => 'option:' . $first,
                    $call['function'] === 'get_bloginfo' && isset(RestRoutes::BLOGINFO_OPTIONS[(string) $first])   => 'option:' . RestRoutes::BLOGINFO_OPTIONS[(string) $first],
                    $call['function'] === 'get_theme_mod' && $first !== null                                       => 'theme_mod:' . $first,
                    $call['function'] === 'get_post_meta'                                                          => 'meta:' . ($second ?? '*'),
                    default                                                                                        => null,
                };

                if ($read !== null && preg_match('/^[^\s|,]+$/', $read) === 1) {
                    $reads[] = $read;
                }
            }
        }

        $reads = array_values(array_unique($reads));
        sort($reads, SORT_STRING);

        return $reads;
    }
}
