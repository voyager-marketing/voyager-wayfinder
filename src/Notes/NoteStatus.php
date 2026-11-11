<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Notes;

/**
 * Whether a note still describes its item.
 *
 * A note pins the hash its item had when the note was written. The status is
 * worked out from that hash and the item's current one, never stored on its
 * own: an item that changes and changes back reads `ok` again, because the
 * note describes it again.
 */
final class NoteStatus
{
    public const OK = 'ok';

    public const NEEDS_REVIEW = 'needs_review';

    public const ORPHANED = 'orphaned';

    public const ALL = [self::OK, self::NEEDS_REVIEW, self::ORPHANED];

    /**
     * @param string|null $currentHash The item's hash in the current manifest, null when the item is gone.
     * @param string      $hashAtWrite The item's hash when the note was last saved.
     */
    public static function evaluate(?string $currentHash, string $hashAtWrite): string
    {
        if ($currentHash === null) {
            return self::ORPHANED;
        }

        return $currentHash === $hashAtWrite ? self::OK : self::NEEDS_REVIEW;
    }

    /**
     * After a plugin upgrade, the new hash for each note that was current.
     *
     * A new release can add a fact to an item, which moves its hash although
     * nothing on the site changed. A note pinned to the item's hash in the
     * stored manifest described the item as it was, so it moves to the new
     * hash and stays `ok`. A note already behind the stored manifest still
     * needs review, and a note whose item is gone stays orphaned.
     *
     * @param list<Note>            $notes
     * @param array<string, string> $storedHashes Item hash by id in the manifest stored before the upgrade.
     * @param array<string, string> $newHashes    Item hash by id in the manifest the new release scanned.
     *
     * @return array<int, string> New pinned hash by note id, sorted by id.
     */
    public static function repins(array $notes, array $storedHashes, array $newHashes): array
    {
        $repins = [];

        foreach ($notes as $note) {
            $stored = $storedHashes[$note->itemId] ?? null;
            $new = $newHashes[$note->itemId] ?? null;

            if ($stored !== null && $new !== null && $stored !== $new && $note->itemHashAtWrite === $stored) {
                $repins[$note->id] = $new;
            }
        }

        ksort($repins);

        return $repins;
    }

    /**
     * The notes whose status differs from what the item hashes now say.
     *
     * @param list<Note>            $notes
     * @param array<string, string> $itemHashes Item hash by id in the current manifest.
     *
     * @return array<int, string> New status by note id, sorted by id.
     */
    public static function changes(array $notes, array $itemHashes): array
    {
        $changes = [];

        foreach ($notes as $note) {
            $status = self::evaluate($itemHashes[$note->itemId] ?? null, $note->itemHashAtWrite);

            if ($status !== $note->status) {
                $changes[$note->id] = $status;
            }
        }

        ksort($changes);

        return $changes;
    }
}
