<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Turns an absolute file path into the form the manifest may carry: relative
 * to wp-content (`mu-plugins/acme.php`, `themes/acme/templates/home.html`),
 * or `core` for a file in wp-includes or wp-admin. Anything else is null, so
 * an absolute server path never reaches the manifest.
 *
 * Plugins, mu-plugins and themes keep their usual prefix even when a site
 * moves those folders out of wp-content. A file reached through a symlink is
 * matched on its resolved path, which PHP reports, so it may come back null.
 */
final class SourceFile
{
    public const CORE = 'core';

    /**
     * @param list<array{0: string, 1: string}>|null $roots Prefix and absolute directory pairs. Defaults to roots().
     *                                                      A `core` prefix maps the whole directory to `core`.
     */
    public static function relative(string $file, ?array $roots = null): ?string
    {
        $file = self::normalize($file);

        foreach (self::longestFirst($roots ?? self::roots()) as [$prefix, $directory]) {
            $directory = rtrim(self::normalize($directory), '/');

            if ($directory === '' || !str_starts_with($file, $directory . '/')) {
                continue;
            }

            $rest = substr($file, strlen($directory) + 1);

            if ($prefix === self::CORE) {
                return self::CORE;
            }

            return $prefix === '' ? $rest : $prefix . '/' . $rest;
        }

        return null;
    }

    /**
     * The file a callable is defined in, relative(). Null for a PHP built-in,
     * a callable that does not resolve, or a file outside every root.
     *
     * @param list<array{0: string, 1: string}>|null $roots Defaults to roots().
     */
    public static function ofCallable(mixed $callback, ?array $roots = null): ?string
    {
        if (is_string($callback) && str_contains($callback, '::')) {
            $callback = explode('::', $callback, 2);
        }

        $method = is_array($callback) && count($callback) === 2 ? array_values($callback) : null;

        try {
            $reflection = match (true) {
                $callback instanceof Closure, is_string($callback) => new ReflectionFunction($callback),
                $method !== null && (is_object($method[0]) || is_string($method[0])) && is_string($method[1])
                    => new ReflectionMethod($method[0], $method[1]),
                is_object($callback) && method_exists($callback, '__invoke') => new ReflectionMethod($callback, '__invoke'),
                default => null,
            };
        } catch (ReflectionException $e) {
            return null;
        }

        $file = $reflection?->getFileName();

        return is_string($file) ? self::relative($file, $roots) : null;
    }

    /**
     * The directories of the running site. Nested ones win over the folder
     * that holds them, whatever order they are listed in.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function roots(): array
    {
        $roots = [];

        if (defined('WPMU_PLUGIN_DIR')) {
            $roots[] = ['mu-plugins', (string) WPMU_PLUGIN_DIR];
        }

        if (defined('WP_PLUGIN_DIR')) {
            $roots[] = ['plugins', (string) WP_PLUGIN_DIR];
        }

        if (function_exists('get_theme_root')) {
            $roots[] = ['themes', get_theme_root()];
        }

        if (defined('WP_CONTENT_DIR')) {
            $roots[] = ['', (string) WP_CONTENT_DIR];
        }

        if (defined('ABSPATH') && defined('WPINC')) {
            $roots[] = [self::CORE, ABSPATH . WPINC];
            $roots[] = [self::CORE, ABSPATH . 'wp-admin'];
        }

        return $roots;
    }

    /**
     * Forward slashes, no doubled separators, an upper-case drive letter.
     */
    public static function normalize(string $path): string
    {
        $path = (string) preg_replace('#/+#', '/', str_replace('\\', '/', $path));

        return preg_match('/^[a-z]:/', $path) === 1 ? ucfirst($path) : $path;
    }

    /**
     * @param list<array{0: string, 1: string}> $roots
     * @return list<array{0: string, 1: string}>
     */
    private static function longestFirst(array $roots): array
    {
        usort(
            $roots,
            static fn (array $a, array $b): int => strlen(self::normalize($b[1])) <=> strlen(self::normalize($a[1]))
        );

        return $roots;
    }
}
