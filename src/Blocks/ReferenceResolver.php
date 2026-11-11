<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Blocks;

use Closure;
use WP_Block_Patterns_Registry;
use WP_Post;

/**
 * Resolves the block types that point at content stored elsewhere.
 *
 * Ported from voyager-orbit src/Modules/Blocks/Services/ReferenceResolver.php
 * @ abe7d26. Two additions: a synced pattern resolves to its `slug` (post_name)
 * as well as its title, because manifest ids use slugs, and `core/navigation`
 * refs resolve to their `wp_navigation` post the same way.
 *
 * The walker never inlines a synced pattern, a registered pattern, a template
 * part or a menu: it records what the block points at and whether the target
 * exists. The lookups are the only WordPress calls here, so they are
 * injectable: the unit suite passes closures over a fixture map, and the
 * defaults call `get_post()` and `WP_Block_Patterns_Registry` when they exist.
 */
final class ReferenceResolver
{
    private Closure $syncedPatternLookup;

    private Closure $patternLookup;

    private Closure $navigationLookup;

    /**
     * @param (callable(int): (array{id?: int, title?: string, slug?: string}|null))|null                  $syncedPatternLookup
     * @param (callable(string): (array{slug?: string, title?: string, registered?: bool}|null))|null      $patternLookup
     * @param (callable(int): (array{id?: int, title?: string, slug?: string}|null))|null                  $navigationLookup
     */
    public function __construct(
        ?callable $syncedPatternLookup = null,
        ?callable $patternLookup = null,
        ?callable $navigationLookup = null
    ) {
        $this->syncedPatternLookup = $syncedPatternLookup !== null
            ? Closure::fromCallable($syncedPatternLookup)
            : static fn (int $id): ?array => self::lookupPost($id, 'wp_block');

        $this->patternLookup = $patternLookup !== null
            ? Closure::fromCallable($patternLookup)
            : self::lookupPattern(...);

        $this->navigationLookup = $navigationLookup !== null
            ? Closure::fromCallable($navigationLookup)
            : static fn (int $id): ?array => self::lookupPost($id, 'wp_navigation');
    }

    /**
     * The synced pattern (`wp_block` post) a `core/block` ref points at.
     *
     * @return array{id: int, title: string, slug: string}|null Null when the post is missing or not a wp_block.
     */
    public function syncedPattern(int $id): ?array
    {
        return self::post(($this->syncedPatternLookup)($id), $id);
    }

    /**
     * The menu (`wp_navigation` post) a `core/navigation` ref points at.
     *
     * @return array{id: int, title: string, slug: string}|null Null when the post is missing or not a wp_navigation.
     */
    public function navigation(int $id): ?array
    {
        return self::post(($this->navigationLookup)($id), $id);
    }

    /**
     * The registered pattern a `core/pattern` slug points at.
     *
     * @return array{slug: string, title: string, registered: bool}|null Null when no registry is loaded,
     *                                                                  so "unknown" stays distinct from "not registered".
     */
    public function pattern(string $slug): ?array
    {
        $resolved = ($this->patternLookup)($slug);

        return is_array($resolved)
            ? [
                'slug'       => (string) ($resolved['slug'] ?? $slug),
                'title'      => (string) ($resolved['title'] ?? ''),
                'registered' => (bool) ($resolved['registered'] ?? false),
            ]
            : null;
    }

    /**
     * Default lookup over the posts table.
     *
     * @return array{id: int, title: string, slug: string}|null
     */
    public static function lookupPost(int $id, string $postType): ?array
    {
        if ($id <= 0 || !function_exists('get_post')) {
            return null;
        }

        $post = get_post($id);
        if (!$post instanceof WP_Post || $post->post_type !== $postType) {
            return null;
        }

        return ['id' => (int) $post->ID, 'title' => (string) $post->post_title, 'slug' => (string) $post->post_name];
    }

    /**
     * Default lookup over the block pattern registry.
     *
     * @return array{slug: string, title: string, registered: bool}|null
     */
    public static function lookupPattern(string $slug): ?array
    {
        if ($slug === '' || !class_exists(WP_Block_Patterns_Registry::class)) {
            return null;
        }

        $registry = WP_Block_Patterns_Registry::get_instance();
        if (!$registry->is_registered($slug)) {
            return ['slug' => $slug, 'title' => '', 'registered' => false];
        }

        $pattern = $registry->get_registered($slug);
        $title   = is_array($pattern) && isset($pattern['title']) && is_string($pattern['title'])
            ? $pattern['title']
            : '';

        return ['slug' => $slug, 'title' => $title, 'registered' => true];
    }

    /**
     * @param mixed $resolved What a lookup returned.
     * @return array{id: int, title: string, slug: string}|null
     */
    private static function post(mixed $resolved, int $id): ?array
    {
        return is_array($resolved)
            ? [
                'id'    => (int) ($resolved['id'] ?? $id),
                'title' => (string) ($resolved['title'] ?? ''),
                'slug'  => (string) ($resolved['slug'] ?? ''),
            ]
            : null;
    }
}
