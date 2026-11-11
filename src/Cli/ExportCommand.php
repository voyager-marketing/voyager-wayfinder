<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Cli;

use InvalidArgumentException;
use UnexpectedValueException;
use Voyager\Wayfinder\Render\Export\SiteExport;
use WP_CLI;

/**
 * `wp wayfinder export`: the handoff manual, the same file the Export button
 * on the Wayfinder page downloads.
 */
final class ExportCommand
{
    /**
     * Prints the site manual for a handoff, or writes it to a file.
     *
     * Markdown pastes into Notion. HTML is one self-contained page to print
     * or save as a PDF.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: md
     * options:
     *   - md
     *   - html
     * ---
     *
     * [--output=<file>]
     * : Write to this file instead of printing.
     *
     * [--[no-]refresh]
     * : Store the scan first when the site changed since the last one, as the
     * Wayfinder page does, so each note's status matches it. On by default.
     * With --no-refresh the site is still scanned but nothing is stored, and
     * notes keep the status last stored.
     *
     * ## EXAMPLES
     *
     *     wp wayfinder export
     *     wp wayfinder export --format=html --output=site-manual.html
     *     wp wayfinder export --no-refresh
     *
     * @param list<string>               $args      Unused.
     * @param array<string, string|bool> $assocArgs
     */
    public function export(array $args, array $assocArgs): void
    {
        $format = (string) ($assocArgs['format'] ?? 'md');
        $output = $assocArgs['output'] ?? null;

        if ($output === true || $output === '') {
            WP_CLI::error('Give --output a file name, such as --output=site-manual.md.');
        }

        // WP-CLI reads --no-refresh as refresh => false.
        $refresh = (bool) ($assocArgs['refresh'] ?? true);

        try {
            $body = SiteExport::render($format, SiteExport::context($refresh));
        } catch (InvalidArgumentException | UnexpectedValueException $e) {
            WP_CLI::error($e->getMessage());
        }

        if ($output === null) {
            // The export ends with a newline; line() adds one more.
            WP_CLI::line(rtrim($body, "\n"));

            return;
        }

        $file = (string) $output;

        if (file_put_contents($file, $body) === false) {
            WP_CLI::error(sprintf('Could not write %s.', $file));
        }

        WP_CLI::success(sprintf('Wrote %s (%d bytes).', $file, strlen($body)));
    }
}
