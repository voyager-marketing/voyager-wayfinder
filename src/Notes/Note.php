<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Notes;

/**
 * One agency note, pinned to a manifest item id. A row of `{prefix}vwf_notes`.
 */
final class Note
{
    /**
     * @param string $updatedAt UTC, `Y-m-d H:i:s` as MySQL stores it.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $itemId,
        public readonly string $body,
        public readonly string $status,
        public readonly int $authorId,
        public readonly string $itemHashAtWrite,
        public readonly string $updatedAt
    ) {
    }

    /**
     * @param array<string, mixed> $row A row as $wpdb returns it with ARRAY_A.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['item_id'] ?? ''),
            (string) ($row['body'] ?? ''),
            (string) ($row['status'] ?? NoteStatus::OK),
            (int) ($row['author_id'] ?? 0),
            (string) ($row['item_hash_at_write'] ?? ''),
            (string) ($row['updated_at'] ?? '')
        );
    }

    /**
     * The public shape, for REST and WP-CLI. The pinned hash stays internal.
     *
     * @return array{id: int, item_id: string, status: string, body: string, author_id: int, updated_at: string}
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'item_id'    => $this->itemId,
            'status'     => $this->status,
            'body'       => $this->body,
            'author_id'  => $this->authorId,
            'updated_at' => $this->updatedAt === '' ? '' : str_replace(' ', 'T', $this->updatedAt) . 'Z',
        ];
    }
}
