<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

/**
 * Remembers which file registered each post type and taxonomy, so the
 * content model can say where one is defined.
 *
 * Plugin::register() starts listening while the plugin file loads, before
 * `init`, where themes and plugins register their types. A type registered
 * before that (in an mu-plugin's body, say) has no entry, and its
 * `defined_in` stays null rather than a guess. Types WordPress registers
 * itself are `core`, which the content model reads from `_builtin`.
 */
final class Registrations
{
    /** @var array<string, string|null> Post type => file, relative to wp-content. */
    private static array $postTypes = [];

    /** @var array<string, string|null> Taxonomy => file, relative to wp-content. */
    private static array $taxonomies = [];

    public static function listen(): void
    {
        add_action('registered_post_type', [self::class, 'postTypeRegistered'], 10, 1);
        add_action('registered_taxonomy', [self::class, 'taxonomyRegistered'], 10, 1);
    }

    public static function postTypeRegistered(mixed $postType): void
    {
        if (is_string($postType)) {
            self::$postTypes[$postType] = self::caller(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        }
    }

    public static function taxonomyRegistered(mixed $taxonomy): void
    {
        if (is_string($taxonomy)) {
            self::$taxonomies[$taxonomy] = self::caller(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        }
    }

    public static function postType(string $slug): ?string
    {
        return self::$postTypes[$slug] ?? null;
    }

    public static function taxonomy(string $slug): ?string
    {
        return self::$taxonomies[$slug] ?? null;
    }

    /**
     * The first frame of a backtrace whose file is outside wp-includes,
     * wp-admin and Wayfinder itself, relative to wp-content. Null when that
     * file is outside wp-content too, or when every frame is core.
     *
     * The register_post_type() frame carries the file it was called from,
     * so on a normal registration this is the plugin or theme file that
     * called it.
     *
     * @param array<int, array<string, mixed>>       $trace From debug_backtrace().
     * @param list<array{0: string, 1: string}>|null $roots Defaults to SourceFile::roots().
     */
    public static function caller(array $trace, ?array $roots = null): ?string
    {
        $own = SourceFile::normalize(dirname(__DIR__)) . '/';

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if (!is_string($file) || str_starts_with(SourceFile::normalize($file), $own)) {
                continue;
            }

            $relative = SourceFile::relative($file, $roots);

            if ($relative !== SourceFile::CORE) {
                return $relative;
            }
        }

        return null;
    }
}
