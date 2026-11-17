<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Manifest;

use Closure;
use Throwable;
use Voyager\Wayfinder\Drift\Fingerprint;
use Voyager\Wayfinder\Plugin;
use WP_Post;

/**
 * The last scan, body and stats, kept so an agent's reads do not each scan
 * the site.
 *
 * An entry is served only while its key still matches the site. The key is a
 * hash of four things that move when the map can move:
 *
 * - the drift fingerprint (types, taxonomies, meta keys, plugins, theme,
 *   allowlisted options, layout posts, ACF groups);
 * - a content generation counter, bumped when a post, term or classic menu
 *   is saved or deleted, because counts and used-in lists live in the
 *   unhashed stats and move with content;
 * - the newest file time in the theme and its parent (root, templates,
 *   parts, patterns), because a deploy can change those with no WordPress
 *   event at all;
 * - the plugin version.
 *
 * An entry older than the age cap is a miss too, as a backstop for anything
 * the key does not see. `vwf_manifest_cache_ttl` sets the cap in seconds;
 * 0 turns the cache off.
 *
 * The entry is canonical JSON, never a serialized PHP object, and it is only
 * served when Manifest::fromJson() reproduces both of its hashes. A persistent
 * object cache holds it when the site has one; otherwise an option with
 * autoload off does.
 */
final class ManifestCache
{
    public const OPTION = 'vwf_manifest_cache';

    public const GENERATION_OPTION = 'vwf_content_generation';

    public const GROUP = 'voyager-wayfinder';

    public const TTL_FILTER = 'vwf_manifest_cache_ttl';

    /** One hour, in seconds. */
    public const DEFAULT_TTL = 3600;

    /** Theme folders whose files can change the map. The theme root is read too. */
    public const THEME_DIRS = ['templates', 'parts', 'patterns'];

    /** Content events that move counts or used-in lists. */
    public const CONTENT_HOOKS = [
        'save_post',
        'deleted_post',
        'trashed_post',
        'untrashed_post',
        'created_term',
        'edited_term',
        'delete_term',
        'set_object_terms',
        'wp_update_nav_menu',
    ];

    /** @var Closure(): string */
    private Closure $key;

    /** @var Closure(): mixed */
    private Closure $read;

    /** @var Closure(array{key: string, stored_at: int, manifest: string}): void */
    private Closure $write;

    /** @var Closure(): int */
    private Closure $now;

    /** @var Closure(): int */
    private Closure $ttl;

    /**
     * @param callable(): string                                                     $key   The current site key.
     * @param callable(): mixed                                                      $read  The stored entry, or anything else when there is none.
     * @param callable(array{key: string, stored_at: int, manifest: string}): void   $write Stores an entry.
     * @param callable(): int                                                        $now   Unix time.
     * @param callable(): int                                                        $ttl   The age cap in seconds; 0 or less turns the cache off.
     */
    public function __construct(callable $key, callable $read, callable $write, callable $now, callable $ttl)
    {
        $this->key = Closure::fromCallable($key);
        $this->read = Closure::fromCallable($read);
        $this->write = Closure::fromCallable($write);
        $this->now = Closure::fromCallable($now);
        $this->ttl = Closure::fromCallable($ttl);
    }

    /**
     * Over the live site.
     */
    public static function forSite(): self
    {
        $external = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        return new self(
            [self::class, 'siteKey'],
            $external
                ? static fn (): mixed => wp_cache_get(self::OPTION, self::GROUP)
                : static fn (): mixed => get_option(self::OPTION, null),
            $external
                ? static function (array $entry): void {
                    wp_cache_set(self::OPTION, $entry, self::GROUP);
                }
                : static function (array $entry): void {
                    update_option(self::OPTION, $entry, false);
                },
            'time',
            static fn (): int => (int) apply_filters(self::TTL_FILTER, self::DEFAULT_TTL)
        );
    }

    /**
     * The stored manifest when its entry is current, or null.
     */
    public function get(): ?Manifest
    {
        $ttl = ($this->ttl)();

        if ($ttl <= 0) {
            return null;
        }

        $entry = ($this->read)();

        if (
            !is_array($entry)
            || !is_string($entry['key'] ?? null)
            || !is_int($entry['stored_at'] ?? null)
            || !is_string($entry['manifest'] ?? null)
        ) {
            return null;
        }

        $age = ($this->now)() - $entry['stored_at'];

        if ($age < 0 || $age >= $ttl || !hash_equals($entry['key'], ($this->key)())) {
            return null;
        }

        return Manifest::fromJson($entry['manifest']);
    }

    public function put(Manifest $manifest): void
    {
        if (($this->ttl)() <= 0) {
            return;
        }

        ($this->write)([
            'key'       => ($this->key)(),
            'stored_at' => ($this->now)(),
            'manifest'  => $manifest->toJson(),
        ]);
    }

    /**
     * The cached manifest, or a fresh scan that is then stored.
     *
     * @param callable(): Manifest $scan
     */
    public function remember(callable $scan): Manifest
    {
        $manifest = $this->get();

        if ($manifest !== null) {
            return $manifest;
        }

        $manifest = $scan();

        try {
            $this->put($manifest);
        } catch (Throwable $e) {
            // A cache that cannot be written must not fail the read; the next read scans again.
            error_log('Wayfinder could not store the manifest cache: ' . $e->getMessage());
        }

        return $manifest;
    }

    /**
     * The key from its four parts.
     */
    public static function key(string $fingerprint, int $generation, int $themeTime, string $version): string
    {
        return CanonicalJson::hash([
            'fingerprint' => $fingerprint,
            'generation'  => $generation,
            'theme_time'  => $themeTime,
            'version'     => $version,
        ]);
    }

    /**
     * The key for the live site.
     */
    public static function siteKey(): string
    {
        $roots = array_values(array_unique([get_stylesheet_directory(), get_template_directory()]));

        return self::key(
            (new Fingerprint())->hash(),
            (int) get_option(self::GENERATION_OPTION, 0),
            self::themeTime($roots),
            Plugin::VERSION
        );
    }

    /**
     * The newest modification time among each root's own files and folders
     * and the files one level inside THEME_DIRS. No deeper: a theme's assets
     * and vendor folders do not change the map, and reading them would cost.
     *
     * @param list<string> $roots
     */
    public static function themeTime(array $roots): int
    {
        $newest = 0;

        foreach ($roots as $root) {
            foreach (array_merge([$root], array_map(static fn (string $dir): string => $root . '/' . $dir, self::THEME_DIRS)) as $dir) {
                if (!is_dir($dir)) {
                    continue;
                }

                $newest = max($newest, (int) filemtime($dir));
                $names = scandir($dir);

                foreach ($names === false ? [] : $names as $name) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }

                    $newest = max($newest, (int) filemtime($dir . '/' . $name));
                }
            }
        }

        return $newest;
    }

    /**
     * Bumps the content generation on every content event.
     */
    public static function register(): void
    {
        foreach (self::CONTENT_HOOKS as $hook) {
            add_action($hook, [self::class, 'onContentChange'], 10, 2);
        }
    }

    /**
     * Revisions and autosaves are skipped: they change no count and no
     * used-in list, and an open editor autosaves every minute.
     */
    public static function onContentChange(mixed $id = null, mixed $post = null): void
    {
        if ($post instanceof WP_Post && $post->post_type === 'revision') {
            return;
        }

        update_option(self::GENERATION_OPTION, (int) get_option(self::GENERATION_OPTION, 0) + 1, false);
    }
}
