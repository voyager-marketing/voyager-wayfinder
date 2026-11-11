<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use WP_Hook;

/**
 * The code section's view of the running site: the REST server's routes,
 * `$wp_filter`, and Reflection for where each callback and function is
 * declared. Nothing is called: no handler, no filter, no permission check.
 *
 * Wayfinder's own routes and callbacks are left out, so the manual does not
 * describe the tool that writes it.
 *
 * @phpstan-import-type CodeCallback from CodeSource
 */
final class WordPressCodeSource implements CodeSource
{
    /** Folders WP_Theme::scandir() skips, and so does the search for constants. */
    private const SKIPPED_FOLDERS = ['node_modules', 'vendor'];

    public function restEndpoints(): array
    {
        if (!function_exists('rest_get_server')) {
            return [];
        }

        $server = rest_get_server();
        $rows = [];

        foreach ($server->get_routes() as $route => $handlers) {
            $options = $server->get_route_options((string) $route);
            $namespace = is_array($options) && is_string($options['namespace'] ?? null) && $options['namespace'] !== '' ? $options['namespace'] : null;

            foreach ($handlers as $handler) {
                if (!is_array($handler) || !isset($handler['callback'])) {
                    continue;
                }

                $callback = self::describe($handler['callback']);

                if (self::isOwn($callback['file'])) {
                    continue;
                }

                $methods = is_array($handler['methods'] ?? null) ? array_keys(array_filter($handler['methods'])) : [];
                $permission = $handler['permission_callback'] ?? null;

                $rows[] = [
                    'route'      => (string) $route,
                    'namespace'  => $namespace,
                    'methods'    => array_map('strval', $methods),
                    'callback'   => $callback,
                    'permission' => match (true) {
                        $permission === null || $permission === '' => self::MISSING,
                        $permission === '__return_true'            => self::PUBLIC,
                        default                                    => self::CHECKED,
                    },
                ];
            }
        }

        return $rows;
    }

    public function hookCallbacks(callable $matches): array
    {
        global $wp_filter;

        $hooks = is_array($wp_filter) ? array_keys($wp_filter) : [];
        $hooks = array_values(array_filter(array_map('strval', $hooks), $matches));
        sort($hooks, SORT_STRING);

        $rows = [];

        foreach ($hooks as $hook) {
            foreach (self::callbacksOn($hook) as [$priority, $function]) {
                $callback = self::describe($function);

                if (!self::isOwn($callback['file'])) {
                    $rows[] = ['hook' => $hook, 'priority' => $priority, 'callback' => $callback];
                }
            }
        }

        return $rows;
    }

    public function listeners(string $hook): array
    {
        $files = [];

        foreach (self::callbacksOn($hook) as [, $function]) {
            $files[] = self::describe($function)['file'];
        }

        return $files;
    }

    public function relative(string $file): ?string
    {
        return SourceFile::relative($file);
    }

    public function read(string $file): ?string
    {
        $php = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

        return $php === false ? null : $php;
    }

    public function functionFile(string $name): ?string
    {
        if (!function_exists($name)) {
            return null;
        }

        try {
            return self::fileOf(new ReflectionFunction($name));
        } catch (ReflectionException $e) {
            return null;
        }
    }

    public function methodFile(string $class, string $method): ?string
    {
        // A class not loaded yet stays unread: autoloading it would run plugin code during a scan.
        if (!class_exists($class, false) && !trait_exists($class, false) && !enum_exists($class, false)) {
            return null;
        }

        try {
            return self::fileOf(new ReflectionMethod($class, $method));
        } catch (ReflectionException $e) {
            return null;
        }
    }

    public function themeFiles(): array
    {
        $dirs = [get_stylesheet_directory()];

        if (get_template_directory() !== $dirs[0]) {
            $dirs[] = get_template_directory();
        }

        $files = [];

        foreach ($dirs as $dir) {
            $dir = rtrim($dir, '/\\');
            $found = glob($dir . '/*.php') ?: [];

            foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $folder) {
                if (!in_array(basename($folder), self::SKIPPED_FOLDERS, true)) {
                    $found = [...$found, ...(glob($folder . '/*.php') ?: [])];
                }
            }

            sort($found, SORT_STRING);
            $files = [...$files, ...$found];
        }

        return $files;
    }

    /**
     * Name, file and lines of a callable, without calling it.
     *
     * @return CodeCallback
     */
    public static function describe(mixed $callback): array
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
            $reflection = null;
        }

        if ($reflection === null) {
            return ['name' => is_string($callback) ? $callback : 'unknown', 'file' => null, 'start' => null, 'end' => null];
        }

        $name = match (true) {
            $reflection instanceof ReflectionMethod => $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName(),
            $reflection->isClosure()                => '{closure}',
            default                                 => $reflection->getName(),
        };

        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        return [
            'name'  => $name,
            'file'  => self::fileOf($reflection),
            'start' => is_int($start) ? $start : null,
            'end'   => is_int($end) ? $end : null,
        ];
    }

    private static function fileOf(ReflectionFunctionAbstract $reflection): ?string
    {
        if ($reflection->isInternal()) {
            return null;
        }

        $file = $reflection->getFileName();

        return is_string($file) ? $file : null;
    }

    /**
     * A callback in Wayfinder's own files.
     */
    private static function isOwn(?string $file): bool
    {
        return $file !== null && str_starts_with(SourceFile::normalize($file), SourceFile::normalize(dirname(__DIR__, 2)) . '/');
    }

    /**
     * Priority and callable of each callback on a hook, in the order they run.
     *
     * @return list<array{0: int, 1: mixed}>
     */
    private static function callbacksOn(string $hook): array
    {
        global $wp_filter;

        $registered = is_array($wp_filter) ? ($wp_filter[$hook] ?? null) : null;

        if (!$registered instanceof WP_Hook) {
            return [];
        }

        $byPriority = $registered->callbacks;
        ksort($byPriority, SORT_NUMERIC);
        $callbacks = [];

        foreach ($byPriority as $priority => $entries) {
            foreach ($entries as $entry) {
                $callbacks[] = [(int) $priority, $entry['function']];
            }
        }

        return $callbacks;
    }
}
