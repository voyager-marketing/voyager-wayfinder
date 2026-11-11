<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Voyager\Wayfinder\Manifest\Section;

/**
 * The `code` section: what theme and plugin PHP does at runtime that no edit
 * screen shows. REST routes (RestRoutes, `rest:` items) and the filters that
 * change a page's head (HeadFilters, `filter:` items), each pointing at the
 * file and function that hold it.
 *
 * Nothing is run. Routes and callbacks come from the registries WordPress
 * already holds (the REST server, `$wp_filter`), their files from
 * Reflection, and what the active theme's and mu-plugins' code does from
 * TokenReader, which reads it without running it. A plugin's code is named,
 * not read.
 *
 * The REST server is read at scan start, before any scanner, by
 * ScannerRegistry, because reading routes outside a REST request fires
 * `rest_api_init`, which registers settings the site section reads.
 *
 * @phpstan-import-type CodeCallback from CodeSource
 * @phpstan-import-type Step from TokenReader
 */
final class CodeScanner implements Scanner
{
    public const SECTION = 'code';

    private ?CodeSource $source;

    private ?PhpSinks $sinks;

    public function __construct(?CodeSource $source = null, ?PhpSinks $sinks = null)
    {
        $this->source = $source;
        $this->sinks = $sinks;
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function scan(): Section
    {
        $this->source ??= new WordPressCodeSource();
        $this->sinks ??= PhpSinks::bundled();
        $reader = self::reader($this->source);

        return new Section(self::SECTION, [
            ...(new HeadFilters($this->source, $reader))->items(),
            ...(new RestRoutes($this->source, $reader, $this->sinks))->items(),
        ]);
    }

    /**
     * A reader that resolves functions, methods and constants through the
     * source, outside core only.
     */
    public static function reader(CodeSource $source): TokenReader
    {
        $read = static function (?string $file) use ($source): ?array {
            if ($file === null || $source->relative($file) === SourceFile::CORE) {
                return null;
            }

            $php = $source->read($file);

            return $php !== null ? ['file' => $file, 'php' => $php] : null;
        };

        return new TokenReader(
            static fn (string $name): ?array => $read($source->functionFile($name)),
            static fn (string $class, string $method): ?array => $read($source->methodFile($class, $method)),
            static function (string $name) use ($source): ?array {
                foreach ($source->themeFiles() as $file) {
                    $php = $source->read($file);
                    $line = $php !== null ? (TokenReader::constants($php)[$name] ?? null) : null;

                    if ($line !== null) {
                        return ['file' => $file, 'line' => $line];
                    }
                }

                return null;
            }
        );
    }

    /**
     * Who a file belongs to, from its relative path: `theme` and the theme
     * folder, `plugin` and the plugin folder, `mu-plugin` and the file
     * under mu-plugins, or `other` for anything else in wp-content. Null for
     * core, and for a file outside every root (a PHP built-in has none).
     *
     * @return array{kind: string, slug: string}|null
     */
    public static function owner(?string $relative): ?array
    {
        if ($relative === null || $relative === SourceFile::CORE) {
            return null;
        }

        $parts = explode('/', $relative);

        return match (true) {
            $parts[0] === 'themes' && count($parts) > 2     => ['kind' => 'theme', 'slug' => $parts[1]],
            $parts[0] === 'plugins' && count($parts) > 2    => ['kind' => 'plugin', 'slug' => $parts[1]],
            $parts[0] === 'plugins'                         => ['kind' => 'plugin', 'slug' => basename($parts[1] ?? '', '.php')],
            $parts[0] === 'mu-plugins' && count($parts) > 1 => ['kind' => 'mu-plugin', 'slug' => implode('/', array_slice($parts, 1))],
            default                                         => ['kind' => 'other', 'slug' => $relative],
        };
    }

    /**
     * Whether the code section reads an owner's code: the theme's and the
     * mu-plugins', which are the site's own. A plugin's is named only.
     *
     * @param array{kind: string, slug: string} $owner
     */
    public static function follows(array $owner): bool
    {
        return $owner['kind'] === 'theme' || $owner['kind'] === 'mu-plugin';
    }

    /**
     * The steps TokenReader follows from a callback, or null when it cannot
     * be read: no tokenizer, no file, or a file the source cannot read.
     *
     * @param CodeCallback $callback
     * @return list<Step>|null
     */
    public static function follow(CodeSource $source, TokenReader $reader, array $callback, int $depth = TokenReader::DEPTH): ?array
    {
        if (!TokenReader::available() || $callback['file'] === null) {
            return null;
        }

        $php = $source->read($callback['file']);

        if ($php === null) {
            return null;
        }

        $closure = $callback['name'] === '{closure}';
        $steps = $reader->follow($callback['file'], $php, $closure ? '' : $callback['name'], $closure ? (int) $callback['start'] : 0, $depth);

        return $steps !== [] ? $steps : null;
    }

    /**
     * `themes/acme/functions.php:604-609`, or `:604` for one line. Null
     * when the file or the line is not known.
     */
    public static function span(?string $relative, ?int $start, ?int $end): ?string
    {
        if ($relative === null || $start === null || $start <= 0) {
            return null;
        }

        return $relative . ':' . $start . ($end !== null && $end > $start ? '-' . $end : '');
    }
}
