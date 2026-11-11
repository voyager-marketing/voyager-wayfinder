<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Notes;

use InvalidArgumentException;
use RuntimeException;
use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\SnapshotStore;

/**
 * Writes notes against the stored manifest and keeps their status current.
 *
 * A note pins its item's hash from the stored snapshot, which is the manual
 * the writer was reading. CLI and REST both come through here, so the rules
 * live in one place.
 */
final class NoteService
{
    private Repository $repository;

    private SnapshotStore $snapshots;

    public function __construct(?Repository $repository = null, ?SnapshotStore $snapshots = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->snapshots = $snapshots ?? new SnapshotStore();
    }

    /**
     * @return list<Note>
     */
    public function all(?string $itemId = null): array
    {
        return $this->repository->all($itemId);
    }

    public function find(int $id): ?Note
    {
        return $this->repository->find($id);
    }

    /**
     * @throws InvalidArgumentException On an empty body, a bad id, or an item the stored manifest does not have.
     * @throws RuntimeException When the database write fails.
     */
    public function add(string $itemId, string $body, int $authorId): Note
    {
        $body = self::cleanBody($body);
        IdFormat::assertValid($itemId);

        $hash = $this->currentHashes()[$itemId] ?? null;

        if ($hash === null) {
            throw new InvalidArgumentException(sprintf(
                'The stored manifest has no item "%s". Check the id with `wp wayfinder scan --format=ids`.',
                esc_html($itemId)
            ));
        }

        $note = $this->repository->insert($itemId, $body, NoteStatus::OK, $authorId, $hash);

        if ($note === null) {
            throw new RuntimeException('The note could not be saved.');
        }

        return $note;
    }

    /**
     * Saves a note again with new text, pinning its item's current hash, so a
     * note that needed review reads `ok`. A note whose item is gone stays
     * `orphaned`: there is no item to pin it to.
     *
     * @throws InvalidArgumentException On an empty body.
     * @throws RuntimeException When the database write fails.
     */
    public function resave(int $id, string $body, int $authorId): ?Note
    {
        $body = self::cleanBody($body);
        $note = $this->repository->find($id);

        if ($note === null) {
            return null;
        }

        $current = $this->currentHashes()[$note->itemId] ?? null;
        $hash = $current ?? $note->itemHashAtWrite;

        $saved = $this->repository->update($id, $body, NoteStatus::evaluate($current, $hash), $authorId, $hash);

        if ($saved === null) {
            throw new RuntimeException('The note could not be saved.');
        }

        return $saved;
    }

    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    /**
     * After a plugin upgrade, moves each note that was current to its item's
     * new hash, so a fact the new release added does not flag it. See
     * NoteStatus::repins().
     *
     * @param array<string, string> $storedHashes Item hash by id in the manifest stored before the upgrade.
     * @param array<string, string> $newHashes    Item hash by id in the manifest just scanned.
     *
     * @return array<int, string> New pinned hash by note id.
     */
    public function repin(array $storedHashes, array $newHashes): array
    {
        $repins = NoteStatus::repins($this->repository->all(), $storedHashes, $newHashes);

        foreach ($repins as $id => $hash) {
            $this->repository->repin($id, $hash);
        }

        return $repins;
    }

    /**
     * Brings every note's status in line with the given item hashes.
     *
     * @param array<string, string> $itemHashes Item hash by id in the manifest just stored.
     *
     * @return array<int, string> New status by note id, for the notes that changed.
     */
    public function reconcile(array $itemHashes): array
    {
        $changes = NoteStatus::changes($this->repository->all(), $itemHashes);

        foreach ($changes as $id => $status) {
            $this->repository->setStatus($id, $status);
        }

        return $changes;
    }

    /**
     * @return array<string, string>
     *
     * @throws InvalidArgumentException Before the first stored scan.
     */
    private function currentHashes(): array
    {
        $hashes = $this->snapshots->itemHashes();

        if ($hashes === []) {
            throw new InvalidArgumentException('No manifest is stored yet. Run `wp wayfinder scan --refresh` first.');
        }

        return $hashes;
    }

    /**
     * Plain text: tags are stripped, line breaks kept.
     *
     * @throws InvalidArgumentException On a body that is empty once cleaned.
     */
    private static function cleanBody(string $body): string
    {
        $body = trim(sanitize_textarea_field($body));

        if ($body === '') {
            throw new InvalidArgumentException('A note needs some text.');
        }

        return $body;
    }
}
