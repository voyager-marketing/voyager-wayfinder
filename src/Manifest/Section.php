<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Manifest;

use InvalidArgumentException;

/**
 * One manifest section (`site`, `content_model`, `layout`, ...) and its items,
 * sorted by id whatever order the scanner produced them in.
 */
final class Section
{
    public readonly string $name;

    /** @var list<Item> */
    public readonly array $items;

    public readonly string $hash;

    /**
     * @param list<Item> $items
     *
     * @throws InvalidArgumentException On a bad section name or two items with one id.
     */
    public function __construct(string $name, array $items)
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid section name "%s". Use lowercase letters, digits and underscores.',
                esc_html($name)
            ));
        }

        $byId = [];

        foreach ($items as $item) {
            if (isset($byId[$item->id])) {
                throw new InvalidArgumentException(sprintf(
                    'Section "%s" has two items with id "%s".',
                    esc_html($name),
                    esc_html($item->id)
                ));
            }

            $byId[$item->id] = $item;
        }

        ksort($byId, SORT_STRING);

        $this->name = $name;
        $this->items = array_values($byId);
        $this->hash = CanonicalJson::hash($this->body());
    }

    public function item(string $id): ?Item
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Stats by item id, sorted, for the items that have any. Not hashed.
     *
     * @return array<string, array<string, mixed>>
     */
    public function stats(): array
    {
        $stats = [];

        foreach ($this->items as $item) {
            if ($item->stats !== []) {
                $stats[$item->id] = $item->stats;
            }
        }

        return $stats;
    }

    /**
     * Everything the hash covers. Each item's own hash is part of it, and no
     * item's stats are.
     *
     * @return array{items: list<array<string, mixed>>, name: string}
     */
    public function body(): array
    {
        return [
            'items' => array_map(static fn (Item $item): array => $item->toArray(), $this->items),
            'name'  => $this->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->body() + ['hash' => $this->hash];
    }
}
