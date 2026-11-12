<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use InvalidArgumentException;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;

/**
 * The section tier: full JSON for one section (`layout`) or one item
 * (`pat:cta-banner`), exactly as the manifest holds it. Nothing is folded,
 * and counts are exact, not banded. An item also names its section and
 * carries its stats and agency notes; a section carries its items' stats by
 * id. Neither stats nor notes are covered by the hashes printed beside them.
 *
 * A section can be asked for one page at a time: PAGE_ITEMS items, by id,
 * with `page` and `pages` beside them and the whole section's hash. The
 * index names these pages for what it left out. Pages are cut by item count,
 * never by size, so they move only when items are added or removed.
 *
 * Compact canonical JSON, so the same site state gives the same bytes.
 *
 * @phpstan-import-type NotesByItem from AgencyNotes
 */
final class SectionRenderer
{
    public const TIER = 'section';

    /** Items on one page of a section. */
    public const PAGE_ITEMS = 25;

    /**
     * @param NotesByItem $notes
     * @param int|null    $page  One page of a section, from 1. Null returns the whole section. An item ignores it.
     * @return string|null Null when no section or item has that id.
     *
     * @throws InvalidArgumentException On a page the section does not have.
     */
    public function render(Manifest $manifest, string $id, array $notes = [], ?int $page = null): ?string
    {
        $section = $manifest->section($id);

        if ($section !== null) {
            $data = $section->toArray();
            $stats = $section->stats();

            if ($page !== null) {
                $pages = self::pageCount(count($section->items));

                if ($page < 1 || $page > $pages) {
                    throw new InvalidArgumentException(sprintf(
                        'Section %s has %d page%s of up to %d items; there is no page %d.',
                        esc_html($section->name),
                        (int) $pages,
                        $pages === 1 ? '' : 's',
                        (int) self::PAGE_ITEMS,
                        (int) $page
                    ));
                }

                $items = array_slice($section->items, ($page - 1) * self::PAGE_ITEMS, self::PAGE_ITEMS);
                $ids = array_map(static fn (Item $item): string => $item->id, $items);

                $data['items'] = array_map(static fn (Item $item): array => $item->toArray(), $items);
                $data['page'] = $page;
                $data['pages'] = $pages;
                $stats = array_intersect_key($stats, array_flip($ids));
            }

            if ($stats !== []) {
                $data['stats'] = $stats;
            }

            return CanonicalJson::encode($data);
        }

        foreach ($manifest->sections as $section) {
            $item = $section->item($id);

            if ($item === null) {
                continue;
            }

            $data = $item->toArray() + ['section' => $section->name];

            if ($item->stats !== []) {
                $data['stats'] = $item->stats;
            }

            if (($notes[$id] ?? []) !== []) {
                $data['notes'] = $notes[$id];
            }

            return CanonicalJson::encode($data);
        }

        return null;
    }

    /**
     * Pages in a section of this many items. An empty section has one, empty.
     */
    public static function pageCount(int $items): int
    {
        return max(1, intdiv($items + self::PAGE_ITEMS - 1, self::PAGE_ITEMS));
    }

    /**
     * The page that holds the item at this position, from 0, in a section sorted by id.
     */
    public static function pageOf(int $position): int
    {
        return intdiv($position, self::PAGE_ITEMS) + 1;
    }
}
