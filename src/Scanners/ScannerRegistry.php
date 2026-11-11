<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;
use Voyager\Wayfinder\Manifest\Section;

/**
 * The scanners that make up a manifest, one per section.
 *
 * Other code adds or replaces scanners through the `vwf_scanners` filter. A
 * filter that returns something other than scanners fails loudly: a manual
 * that silently drops a section is worse than no manual.
 *
 * Before the first scanner runs, the registry reads the REST server once
 * (`rest_get_server()`). Outside a REST request that fires `rest_api_init`,
 * where core and plugins register settings, so without it what the site
 * section reads would depend on whether the code section had read routes
 * first, and WP-CLI, wp-admin and REST could disagree (rule 1). In a REST
 * request the server already exists and nothing changes.
 */
final class ScannerRegistry
{
    public const FILTER = 'vwf_scanners';

    /** @var array<string, Scanner> Keyed and sorted by section name. */
    private array $scanners = [];

    /** @var Closure(): void Runs once per scan, before any scanner. */
    private Closure $prime;

    /**
     * @param array<mixed>          $scanners Scanner instances. Typed loosely because filters return anything.
     * @param (callable(): void)|null $prime    Runs before the first scanner of each scan. Defaults to nothing;
     *                                          fromFilters() passes primeRestServer().
     *
     * @throws InvalidArgumentException On a non-scanner or two scanners for one section.
     */
    public function __construct(array $scanners, ?callable $prime = null)
    {
        $this->prime = $prime !== null ? Closure::fromCallable($prime) : static function (): void {
        };

        foreach ($scanners as $scanner) {
            if (!$scanner instanceof Scanner) {
                throw new InvalidArgumentException(sprintf(
                    '%s must hold only %s instances, got %s.',
                    esc_html(self::FILTER),
                    esc_html(Scanner::class),
                    esc_html(get_debug_type($scanner))
                ));
            }

            $section = $scanner->section();

            if (isset($this->scanners[$section])) {
                throw new InvalidArgumentException(sprintf('Two scanners claim the "%s" section.', esc_html($section)));
            }

            $this->scanners[$section] = $scanner;
        }

        ksort($this->scanners, SORT_STRING);
    }

    /**
     * The built-in scanners, passed through the `vwf_scanners` filter.
     */
    public static function fromFilters(): self
    {
        $scanners = apply_filters(self::FILTER, self::defaults());

        if (!is_array($scanners)) {
            throw new InvalidArgumentException(sprintf('%s must return an array.', esc_html(self::FILTER)));
        }

        return new self($scanners, [self::class, 'primeRestServer']);
    }

    /**
     * Builds the REST server, which fires `rest_api_init` the first time
     * outside a REST request. Every scan context then sees the same
     * registered settings and routes.
     */
    public static function primeRestServer(): void
    {
        if (function_exists('rest_get_server')) {
            rest_get_server();
        }
    }

    /**
     * @return list<Scanner>
     */
    public static function defaults(): array
    {
        return [new CodeScanner(), new ContentModelScanner(), new IntegrationsScanner(), new LayoutScanner(), new RouteScanner(), new SiteScanner()];
    }

    /**
     * @return list<string>
     */
    public function sections(): array
    {
        return array_keys($this->scanners);
    }

    /**
     * Runs every scanner, or only the one for `$only`.
     *
     * @return list<Section>
     *
     * @throws InvalidArgumentException When `$only` names no registered section.
     * @throws UnexpectedValueException When a scanner returns a section under another name.
     */
    public function scan(?string $only = null): array
    {
        $scanners = $this->scanners;

        if ($only !== null) {
            if (!isset($scanners[$only])) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown section "%s". Known sections: %s.',
                    esc_html($only),
                    esc_html(implode(', ', $this->sections()))
                ));
            }

            $scanners = [$only => $scanners[$only]];
        }

        ($this->prime)();

        $sections = [];

        foreach ($scanners as $name => $scanner) {
            $section = $scanner->scan();

            if ($section->name !== $name) {
                throw new UnexpectedValueException(sprintf(
                    '%s declares the "%s" section but returned "%s".',
                    esc_html(get_class($scanner)),
                    esc_html($name),
                    esc_html($section->name)
                ));
            }

            $sections[] = $section;
        }

        return $sections;
    }
}
