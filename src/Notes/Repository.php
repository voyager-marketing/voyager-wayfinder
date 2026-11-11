<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Notes;

use wpdb;

/**
 * Reads and writes `{prefix}vwf_notes`. Every query goes through
 * `$wpdb->prepare()` or the `$wpdb` insert, update and delete helpers.
 */
final class Repository
{
    private wpdb $wpdb;

    private string $table;

    public function __construct(?wpdb $db = null)
    {
        global $wpdb;

        $this->wpdb = $db ?? $wpdb;
        $this->table = $this->wpdb->prefix . Schema::TABLE;
    }

    /**
     * Every note, or every note on one item, sorted by item id and then note id.
     *
     * @return list<Note>
     */
    public function all(?string $itemId = null): array
    {
        $wpdb = $this->wpdb;

        $rows = $itemId === null
            ? $wpdb->get_results(
                $wpdb->prepare('SELECT * FROM %i ORDER BY item_id ASC, id ASC', $this->table),
                ARRAY_A
            )
            : $wpdb->get_results(
                $wpdb->prepare('SELECT * FROM %i WHERE item_id = %s ORDER BY id ASC', $this->table, $itemId),
                ARRAY_A
            );

        return array_map([Note::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    public function find(int $id): ?Note
    {
        $wpdb = $this->wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table, $id),
            ARRAY_A
        );

        return is_array($row) ? Note::fromRow($row) : null;
    }

    public function insert(string $itemId, string $body, string $status, int $authorId, string $itemHash): ?Note
    {
        $inserted = $this->wpdb->insert(
            $this->table,
            [
                'item_id'            => $itemId,
                'body'               => $body,
                'status'             => $status,
                'author_id'          => $authorId,
                'item_hash_at_write' => $itemHash,
                'updated_at'         => self::now(),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $inserted === false ? null : $this->find((int) $this->wpdb->insert_id);
    }

    /**
     * Re-saves a note: new body, author and pinned hash.
     */
    public function update(int $id, string $body, string $status, int $authorId, string $itemHash): ?Note
    {
        $updated = $this->wpdb->update(
            $this->table,
            [
                'body'               => $body,
                'status'             => $status,
                'author_id'          => $authorId,
                'item_hash_at_write' => $itemHash,
                'updated_at'         => self::now(),
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );

        return $updated === false ? null : $this->find($id);
    }

    /**
     * Changes the status only. A drift check is not an edit, so `updated_at` stays.
     */
    public function setStatus(int $id, string $status): void
    {
        $this->wpdb->update($this->table, ['status' => $status], ['id' => $id], ['%s'], ['%d']);
    }

    /**
     * Pins a note to a new item hash. A plugin upgrade is not an edit, so the
     * body, author and `updated_at` stay.
     */
    public function repin(int $id, string $itemHash): void
    {
        $this->wpdb->update($this->table, ['item_hash_at_write' => $itemHash], ['id' => $id], ['%s'], ['%d']);
    }

    public function delete(int $id): bool
    {
        return (int) $this->wpdb->delete($this->table, ['id' => $id], ['%d']) > 0;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
