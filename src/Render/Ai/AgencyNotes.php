<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use Voyager\Wayfinder\Notes\Note;
use Voyager\Wayfinder\Notes\NoteStatus;
use Voyager\Wayfinder\Notes\Repository;
use Voyager\Wayfinder\Notes\Schema;

/**
 * Agency notes, shaped for the AI tiers: text and status by item id. Never
 * the author.
 *
 * Reads through the notes Repository, and reads nothing until the notes
 * table exists (Schema installs it on `init`), so a tier rendered before the
 * first install still works.
 *
 * @phpstan-type TierNote array{body: string, status: string}
 * @phpstan-type NotesByItem array<string, list<TierNote>>
 */
final class AgencyNotes
{
    public const NEEDS_REVIEW = NoteStatus::NEEDS_REVIEW;

    /**
     * @return NotesByItem
     */
    public static function fromDatabase(): array
    {
        global $wpdb;

        $table = Schema::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            return [];
        }

        return self::group(array_map(
            static fn (Note $note): array => ['item_id' => $note->itemId, 'body' => $note->body, 'status' => $note->status],
            (new Repository())->all()
        ));
    }

    /**
     * Notes by item id. Item ids sorted, and each item's notes sorted by
     * text, so the tiers print them the same way whatever order rows arrive in.
     *
     * @param array<mixed> $rows Rows with item_id, body and status.
     * @return NotesByItem
     */
    public static function group(array $rows): array
    {
        $notes = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['item_id'] ?? null) || !is_string($row['body'] ?? null)) {
                continue;
            }

            $notes[$row['item_id']][] = [
                'body'   => $row['body'],
                'status' => is_string($row['status'] ?? null) ? $row['status'] : NoteStatus::OK,
            ];
        }

        ksort($notes, SORT_STRING);

        foreach ($notes as &$itemNotes) {
            usort($itemNotes, static fn (array $a, array $b): int => [$a['body'], $a['status']] <=> [$b['body'], $b['status']]);
        }
        unset($itemNotes);

        return $notes;
    }
}
