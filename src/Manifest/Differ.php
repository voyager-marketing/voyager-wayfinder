<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Manifest;

use InvalidArgumentException;

/**
 * What changed between two manifests, by item id.
 *
 * Works on item hashes rather than whole items, so a live Manifest can be
 * compared with a stored body without rebuilding one: every item in a body
 * already carries its own hash.
 */
final class Differ
{
    /**
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    public static function between(Manifest $before, Manifest $after): array
    {
        return self::diff(self::itemHashes($before->body()), self::itemHashes($after->body()));
    }

    /**
     * @param array<string, string> $before Item hash by id.
     * @param array<string, string> $after  Item hash by id.
     *
     * @return array{added: list<string>, removed: list<string>, changed: list<string>} Each list sorted.
     */
    public static function diff(array $before, array $after): array
    {
        $changed = [];

        foreach (array_intersect_key($after, $before) as $id => $hash) {
            if ($before[$id] !== $hash) {
                $changed[] = (string) $id;
            }
        }

        return [
            'added'   => self::sorted(array_keys(array_diff_key($after, $before))),
            'removed' => self::sorted(array_keys(array_diff_key($before, $after))),
            'changed' => self::sorted($changed),
        ];
    }

    /**
     * Item hash by id, from a manifest body: Manifest::body() or the same
     * body decoded from JSON.
     *
     * @param array<mixed> $body
     *
     * @return array<string, string> Sorted by id.
     *
     * @throws InvalidArgumentException When the body is not shaped like a manifest body.
     */
    public static function itemHashes(array $body): array
    {
        $sections = $body['sections'] ?? null;

        if (!is_array($sections)) {
            throw new InvalidArgumentException('A manifest body needs a sections list.');
        }

        $hashes = [];

        foreach ($sections as $section) {
            $items = is_array($section) ? ($section['items'] ?? null) : null;

            if (!is_array($items)) {
                throw new InvalidArgumentException('Every manifest section needs an items list.');
            }

            foreach ($items as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                $hash = is_array($item) ? ($item['hash'] ?? null) : null;

                if (!is_string($id) || !is_string($hash)) {
                    throw new InvalidArgumentException('Every manifest item needs a string id and hash.');
                }

                if (isset($hashes[$id])) {
                    throw new InvalidArgumentException(sprintf('Item "%s" appears in two sections.', esc_html($id)));
                }

                $hashes[$id] = $hash;
            }
        }

        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * @param array<int|string> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        $ids = array_map('strval', $ids);
        sort($ids, SORT_STRING);

        return $ids;
    }
}
