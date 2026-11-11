<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Export;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;
use Voyager\Wayfinder\Drift\Refresher;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\SnapshotStore;
use Voyager\Wayfinder\Render\Ai\AgencyNotes;
use Voyager\Wayfinder\Render\Ai\Context;

/**
 * The one path `wp wayfinder export` and the Export button share, so both
 * hand over the same bytes.
 *
 * It reads through the AI tiers' Context: the same scan and the same notes,
 * so the export shows what the tiers show and nothing they exclude.
 *
 * The CLI stores the scan first by default, as the Wayfinder page does on
 * each view, so every note's status matches the scan it is printed with.
 * `--no-refresh` stores nothing, and notes keep the status last stored. The
 * button leaves the store alone: the page it sits on refreshed it when it was
 * viewed.
 *
 * @phpstan-import-type NotesByItem from AgencyNotes
 */
final class SiteExport
{
    public const FORMATS = [MarkdownExporter::FORMAT, HtmlExporter::FORMAT];

    /**
     * @param Context|null $context Defaults to a fresh scan that stores nothing.
     *
     * @throws InvalidArgumentException On an unknown format.
     * @throws UnexpectedValueException When a scanner fails.
     */
    public static function render(string $format, ?Context $context = null): string
    {
        if (!in_array($format, self::FORMATS, true)) {
            // Message never reaches HTML: WP-CLI prints it, and the Export button checks the format first.
            throw new InvalidArgumentException(sprintf('Unknown format "%s". Use one of: %s.', $format, implode(', ', self::FORMATS))); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $context ??= new Context();

        // The manifest first: a refreshing context stores the scan before the notes are read.
        $manifest = $context->manifest();

        $handoff = new Handoff(
            $manifest,
            $context->notes(),
            (string) get_bloginfo('name'),
            admin_url(),
            (string) get_bloginfo('language')
        );

        return $format === HtmlExporter::FORMAT
            ? (new HtmlExporter())->export($handoff)
            : (new MarkdownExporter())->export($handoff);
    }

    /**
     * A Context for the CLI. With $refresh on, its scan is stored before
     * anything reads it when it differs from the stored manifest or nothing
     * is stored yet, the check Admin\ManualPage makes on each view. The notes
     * are then read after their statuses were checked against the scan.
     *
     * @param (callable(): Manifest)|null     $scan  Defaults to every registered scanner.
     * @param (callable(Manifest): void)|null $store Defaults to a Refresher run when the scan differs from the stored one.
     * @param (callable(): NotesByItem)|null  $notes Defaults to the notes table.
     */
    public static function context(bool $refresh = true, ?callable $scan = null, ?callable $store = null, ?callable $notes = null): Context
    {
        $scan = $scan !== null ? Closure::fromCallable($scan) : static fn (): Manifest => Refresher::scan();
        $store = $store !== null ? Closure::fromCallable($store) : self::storeIfChanged(...);

        return new Context(
            static function () use ($scan, $store, $refresh): Manifest {
                $manifest = $scan();

                if ($refresh) {
                    $store($manifest);
                }

                return $manifest;
            },
            $notes
        );
    }

    /**
     * `riverbend-plumbing-site-manual.md`: the site name as a slug, plain
     * ASCII so it is safe in a Content-Disposition header.
     */
    public static function filename(string $format): string
    {
        $slug = (string) preg_replace('/[^a-z0-9-]+/', '', sanitize_title((string) get_bloginfo('name')));

        return ($slug !== '' ? $slug : 'site') . '-site-manual.' . $format;
    }

    public static function contentType(string $format): string
    {
        return $format === HtmlExporter::FORMAT ? 'text/html; charset=utf-8' : 'text/markdown; charset=utf-8';
    }

    /**
     * @throws InvalidArgumentException|UnexpectedValueException When the refresh fails. Nothing is stored then.
     */
    private static function storeIfChanged(Manifest $manifest): void
    {
        $snapshots = new SnapshotStore();

        if (($snapshots->snapshot()['hash'] ?? null) !== $manifest->hash || get_option(Refresher::LAST_SCAN_OPTION) === false) {
            (new Refresher($snapshots))->refresh($manifest);
        }
    }
}
