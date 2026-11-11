<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

/**
 * Everything the code section reads from WordPress, as plain rows: the REST
 * routes the server has, the callbacks on head hooks, who listens to an
 * action, and where PHP is declared.
 *
 * WordPressCodeSource is the real one: `rest_get_server()->get_routes()`,
 * `$wp_filter` and Reflection. The unit suite passes an in-memory source
 * over PHP source strings. Paths are opaque to the scanner: it reads a file
 * through read() and names it in the manifest through relative().
 *
 * A callback is named the way TokenReader names declarations: a function by
 * name, a method as `Class::method` with the class's namespace, a closure as
 * `{closure}`. `file` is null for a PHP built-in (`trim`, `strip_tags`) or a
 * callable reflection cannot place, and `start` and `end` are its lines.
 *
 * @phpstan-type CodeCallback array{name: string, file: string|null, start: int|null, end: int|null}
 * @phpstan-type RestEndpoint array{route: string, namespace: string|null, methods: list<string>, callback: CodeCallback, permission: string}
 * @phpstan-type HookCallback array{hook: string, priority: int, callback: CodeCallback}
 */
interface CodeSource
{
    /** A permission callback of `__return_true`: anyone may call the route. */
    public const PUBLIC = 'public';

    /** Any other permission callback: the route checks who calls it. */
    public const CHECKED = 'checked';

    /** No permission callback at all, which WordPress treats as public. */
    public const MISSING = 'missing';

    /**
     * Every REST endpoint the server registers outside Wayfinder itself, in
     * registration order: one row per route and handler. `permission` is
     * PUBLIC, CHECKED or MISSING.
     *
     * @return list<RestEndpoint>
     */
    public function restEndpoints(): array;

    /**
     * The callbacks on every registered hook whose name `$matches` accepts,
     * by hook, then priority, then the order they were added.
     *
     * @param callable(string): bool $matches
     * @return list<HookCallback>
     */
    public function hookCallbacks(callable $matches): array;

    /**
     * The files of the callbacks hooked to an action, in the order they
     * run, null for one reflection cannot place. Empty when nothing listens.
     *
     * @return list<string|null>
     */
    public function listeners(string $hook): array;

    /**
     * The form a path may take in the manifest (SourceFile::relative()):
     * `themes/acme/functions.php`, `core`, or null outside every root.
     */
    public function relative(string $file): ?string;

    /**
     * A file's PHP source, or null when it cannot be read.
     */
    public function read(string $file): ?string;

    /**
     * The file that declares a user function, or null for a PHP built-in or
     * a function that does not exist. Named as TokenReader calls it.
     */
    public function functionFile(string $name): ?string;

    /**
     * The file that declares a method, or null.
     */
    public function methodFile(string $class, string $method): ?string;

    /**
     * Where to look for a constant no followed file declares: the active
     * theme's PHP files, child first, then the parent's.
     *
     * @return list<string>
     */
    public function themeFiles(): array;
}
