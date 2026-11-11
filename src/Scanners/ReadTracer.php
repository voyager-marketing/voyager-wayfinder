<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Throwable;

/**
 * Records the options and post meta a binding source's callback reads while
 * it runs, so a binding item can say which option holds its value without
 * anyone reading the source's code.
 *
 * Reads are seen through the `pre_option` and `get_post_metadata` filters,
 * which fire on every get_option() and get_post_meta() call, cached or not.
 * Only reads the callback's own code makes count: the read is called from
 * outside wp-includes and wp-admin, and no core frame sits between it and
 * the callback. So a read core makes for its own work (`home` inside
 * home_url()) is left out, and so is one a filter makes from inside a core
 * function. Both say nothing about the source, and both can differ between
 * surfaces: WP-CLI filters home_url and reads `home` itself, REST does not.
 *
 * traceFile() does the same for a registered pattern's PHP file, which it
 * includes itself, as core does to build the pattern's content. There the
 * file's own reads are the ones made below that include with no core frame
 * between, so a theme helper the file calls counts and `home_url()` does not.
 */
final class ReadTracer
{
    /** Functions that read an option. The call site of the outermost one is what counts. */
    private const OPTION_READERS = ['get_option'];

    /** Functions that read post meta, outermost last. */
    private const META_READERS = ['get_metadata_raw', 'get_metadata', 'get_post_meta', 'metadata_exists'];

    /** The core method that calls a binding source's callback. */
    private const ENTRY = ['WP_Block_Bindings_Source', 'get_value'];

    /** The method traceFile() includes a file from. */
    private const FILE_ENTRY = [self::class, 'includeFile'];

    /** @var array<string, true> */
    private array $reads = [];

    /**
     * Runs `$run` with its output discarded and returns what it read:
     * `option:<name>` and `meta:<key>`, sorted. Null when it throws, so a
     * failing callback costs only its reads.
     *
     * @return list<string>|null
     */
    public static function trace(callable $run): ?array
    {
        $tracer = new self();
        $onOption = [$tracer, 'onOption'];
        $onMeta = [$tracer, 'onMeta'];

        add_filter('pre_option', $onOption, PHP_INT_MIN, 2);
        add_filter('get_post_metadata', $onMeta, PHP_INT_MIN, 3);

        $level = ob_get_level();
        ob_start();

        try {
            $run();
        } catch (Throwable $e) {
            return null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            remove_filter('pre_option', $onOption, PHP_INT_MIN);
            remove_filter('get_post_metadata', $onMeta, PHP_INT_MIN);
        }

        $reads = array_keys($tracer->reads);
        sort($reads, SORT_STRING);

        return $reads;
    }

    /**
     * Includes a PHP file with its output discarded and returns what it
     * read, as trace() does. Null when the file cannot be read, when it
     * throws, or when it declares a named function, class, interface, trait
     * or enum: core includes a pattern file too, and a second include of a
     * declaration is a fatal error, so such a file is left untraced.
     *
     * @return list<string>|null
     */
    public static function traceFile(string $path): ?array
    {
        $code = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($code === false || self::declares($code)) {
            return null;
        }

        return self::trace(static function () use ($path): void {
            self::includeFile($path);
        });
    }

    /**
     * Whether PHP source declares a named function, class, interface, trait
     * or enum, guarded or not. A closure, `new class` and `Foo::class` are
     * not declarations.
     */
    public static function declares(string $code): bool
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (mixed $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        foreach ($tokens as $i => $token) {
            $id = is_array($token) ? $token[0] : null;
            $before = $tokens[$i - 1] ?? null;
            $before = is_array($before) ? $before[0] : $before;

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $before !== T_DOUBLE_COLON && $before !== T_NEW) {
                return true;
            }

            if ($id !== T_FUNCTION) {
                continue;
            }

            // `function &name()`: PHP 8.1 tokenizes that `&` on its own.
            $name = $tokens[$i + 1] ?? null;
            $name = $name === '&' || (is_array($name) && $name[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) ? ($tokens[$i + 2] ?? null) : $name;

            if (is_array($name) && $name[0] === T_STRING) {
                return true;
            }
        }

        return false;
    }

    /**
     * The include traceFile() runs, in a scope of its own so the file's
     * variables stay in it.
     */
    private static function includeFile(string $path): void
    {
        include $path;
    }

    /**
     * Read ids as a source may state them: `option:<name>` or `meta:<key>`,
     * with no whitespace, `|` or `,`. Anything else is dropped. Sorted,
     * duplicates removed.
     *
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    public static function ids(array $values): array
    {
        $ids = array_values(array_unique(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && preg_match('/^(option|meta):[^\s|,]+$/', $value) === 1
        )));
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * `pre_option` callback. Passes the value through untouched.
     */
    public function onOption(mixed $pre, mixed $option = null): mixed
    {
        if (is_string($option) && $option !== '' && self::readOutsideCore(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), self::OPTION_READERS)) {
            $this->reads['option:' . $option] = true;
        }

        return $pre;
    }

    /**
     * `get_post_metadata` callback. Passes the value through untouched. A
     * read of every key at once (an empty key) names no field and is skipped.
     */
    public function onMeta(mixed $value, mixed $objectId = null, mixed $key = null): mixed
    {
        if (is_string($key) && $key !== '' && self::readOutsideCore(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), self::META_READERS)) {
            $this->reads['meta:' . $key] = true;
        }

        return $value;
    }

    /**
     * Whether the outermost reader function on the stack was called by the
     * binding source's own code: walking out from that call, the first frame
     * in wp-includes or wp-admin is the one where core calls the source's
     * callback. A frame with no file (PHP calling a function itself, from
     * array_map, say) does not count as core. With no core frame at all,
     * which only happens outside WordPress, the read counts. Reaching the
     * include traceFile() runs before any core frame counts too: the read is
     * the included file's own, wherever the scan was started from.
     *
     * @param array<int, array<string, mixed>>       $trace   From debug_backtrace(), innermost first.
     * @param list<string>                           $readers Function names, without a class.
     * @param list<array{0: string, 1: string}>|null $roots   Defaults to SourceFile::roots().
     */
    public static function readOutsideCore(array $trace, array $readers, ?array $roots = null): bool
    {
        $trace = array_values($trace);
        $outermost = null;

        foreach ($trace as $i => $frame) {
            if (!isset($frame['class']) && in_array($frame['function'] ?? null, $readers, true)) {
                $outermost = $i;
                continue;
            }

            if ($outermost !== null) {
                break;
            }
        }

        if ($outermost === null) {
            return false;
        }

        for ($i = $outermost, $count = count($trace); $i < $count; $i++) {
            if (($trace[$i]['class'] ?? null) === self::FILE_ENTRY[0] && ($trace[$i]['function'] ?? null) === self::FILE_ENTRY[1]) {
                return true;
            }

            $file = $trace[$i]['file'] ?? null;

            if (is_string($file) && SourceFile::relative($file, $roots) === SourceFile::CORE) {
                $caller = $trace[$i + 1] ?? [];

                return ($caller['class'] ?? null) === self::ENTRY[0] && ($caller['function'] ?? null) === self::ENTRY[1];
            }
        }

        return true;
    }
}
