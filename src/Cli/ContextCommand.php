<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Cli;

use InvalidArgumentException;
use UnexpectedValueException;
use Voyager\Wayfinder\Render\Ai\Context;
use WP_CLI;

/**
 * `wp wayfinder context` and `wp wayfinder stats`: the AI tiers from the
 * command line, through the same Context the REST routes use.
 */
final class ContextCommand
{
    /** @var (callable(): Context)|null */
    private $contextFactory;

    /**
     * @param (callable(): Context)|null $contextFactory Defaults to a fresh Context per command.
     */
    public function __construct(?callable $contextFactory = null)
    {
        $this->contextFactory = $contextFactory;
    }

    /**
     * Prints one AI context tier.
     *
     * ## OPTIONS
     *
     * [--tier=<tier>]
     * : Which tier to print.
     * ---
     * default: brief
     * options:
     *   - brief
     *   - index
     *   - section
     * ---
     *
     * [--id=<id>]
     * : Section name or item id. Required for the section tier.
     *
     * ## EXAMPLES
     *
     *     wp wayfinder context
     *     wp wayfinder context --tier=index
     *     wp wayfinder context --tier=section --id=pat:cta-banner
     *
     * @param list<string>          $args      Unused.
     * @param array<string, string> $assocArgs
     */
    public function context(array $args, array $assocArgs): void
    {
        $tier = (string) ($assocArgs['tier'] ?? 'brief');
        $id = trim((string) ($assocArgs['id'] ?? ''));

        try {
            $body = $this->newContext()->render($tier, $id);
        } catch (InvalidArgumentException | UnexpectedValueException $e) {
            WP_CLI::error($e->getMessage());
        }

        if ($body === null) {
            WP_CLI::error(sprintf('No section or item has the id "%s".', $id));
        }

        // The renderer ends text tiers with a newline; line() adds one more.
        WP_CLI::line(rtrim($body, "\n"));
    }

    /**
     * Prints the size of the brief and the index.
     *
     * One row per tier: tier, chars, est_tokens. est_tokens is chars / 4,
     * rounded up, and is an estimate: the benchmark reports real token counts.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp wayfinder stats
     *     wp wayfinder stats --format=csv
     *
     * @param list<string>          $args      Unused.
     * @param array<string, string> $assocArgs
     */
    public function stats(array $args, array $assocArgs): void
    {
        $format = (string) ($assocArgs['format'] ?? 'table');

        try {
            $rows = $this->newContext()->stats();
        } catch (InvalidArgumentException | UnexpectedValueException $e) {
            WP_CLI::error($e->getMessage());
        }

        WP_CLI\Utils\format_items($format, $rows, ['tier', 'chars', 'est_tokens']);

        if ($format === 'table') {
            WP_CLI::line('est_tokens is chars / 4, an estimate.');
        }
    }

    private function newContext(): Context
    {
        return $this->contextFactory !== null ? ($this->contextFactory)() : new Context();
    }
}
