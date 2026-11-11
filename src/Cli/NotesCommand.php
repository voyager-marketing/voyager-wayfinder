<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Cli;

use InvalidArgumentException;
use RuntimeException;
use Voyager\Wayfinder\Notes\Note;
use Voyager\Wayfinder\Notes\NoteService;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Agency notes pinned to manifest item ids.
 */
final class NotesCommand
{
    /** Columns `notes list` prints unless --fields says otherwise. */
    public const DEFAULT_FIELDS = 'item_id,status,body';

    /**
     * Adds a note to an item in the stored manifest.
     *
     * ## OPTIONS
     *
     * <item_id>
     * : Manifest item id, for example `pt:service`.
     *
     * <text>
     * : The note. Plain text.
     *
     * ## EXAMPLES
     *
     *     wp wayfinder notes add pt:service "Prices show on the service cards"
     *
     * @param list<string>          $args
     * @param array<string, string> $assocArgs Unused.
     */
    public function add(array $args, array $assocArgs): void
    {
        try {
            $note = (new NoteService())->add($args[0] ?? '', $args[1] ?? '', get_current_user_id());
        } catch (InvalidArgumentException | RuntimeException $e) {
            WP_CLI::error($e->getMessage());
        }

        WP_CLI::success(sprintf('Added note %d on %s.', $note->id, $note->itemId));
    }

    /**
     * Lists notes and whether each still matches its item.
     *
     * Status is `ok`, `needs_review` when the item changed after the note was
     * saved, or `orphaned` when the item is gone. Notes are sorted by item id,
     * then note id.
     *
     * ## OPTIONS
     *
     * [--item=<item_id>]
     * : Only notes on this item.
     *
     * [--fields=<fields>]
     * : Comma-separated columns: id, item_id, status, body, author_id, updated_at.
     * ---
     * default: item_id,status,body
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp wayfinder notes list --format=csv
     *
     * @subcommand list
     *
     * @param list<string>          $args      Unused.
     * @param array<string, string> $assocArgs
     */
    public function listNotes(array $args, array $assocArgs): void
    {
        $item = $assocArgs['item'] ?? '';
        $notes = (new NoteService())->all($item === '' ? null : $item);
        $fields = array_map('trim', explode(',', $assocArgs['fields'] ?? self::DEFAULT_FIELDS));

        Utils\format_items(
            $assocArgs['format'] ?? 'table',
            array_map(static fn (Note $note): array => $note->toArray(), $notes),
            $fields
        );
    }
}
