<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

/**
 * The changes-since tier: which item ids changed since a manifest hash the
 * agent already holds.
 *
 * Reads the chain SnapshotStore keeps in `vwf_manifest_history`: the last
 * five stores, oldest first, each `{previous, hash, added, removed, changed}`.
 * The answer is every id added, removed or changed in the links from the
 * agent's hash to the site as it is now. Anything the chain cannot answer is
 * `{"full": true}`: fetch the brief and index again.
 */
final class Changes
{
    /** Item id lists a history entry carries. */
    private const ID_LISTS = ['added', 'changed', 'removed'];

    /**
     * @param mixed $history The `vwf_manifest_history` value, as stored.
     * @return array{changed?: list<string>, full: bool, hash: string}
     */
    public static function since(string $since, string $current, mixed $history): array
    {
        if ($since === $current) {
            return ['changed' => [], 'full' => false, 'hash' => $current];
        }

        $full = ['full' => true, 'hash' => $current];
        $entries = is_array($history) ? array_values($history) : [];

        // The link that starts at the agent's hash.
        $from = null;
        foreach ($entries as $i => $entry) {
            if (is_array($entry) && ($entry['previous'] ?? null) === $since) {
                $from = $i;
            }
        }

        if ($from === null) {
            return $full;
        }

        $ids = [];
        $expected = $since;

        foreach (array_slice($entries, $from) as $entry) {
            // Every link must continue the one before it.
            if (!is_array($entry) || ($entry['previous'] ?? null) !== $expected || !is_string($entry['hash'] ?? null)) {
                return $full;
            }

            foreach (self::ID_LISTS as $list) {
                foreach (is_array($entry[$list] ?? null) ? $entry[$list] : [] as $id) {
                    if (is_string($id)) {
                        $ids[$id] = true;
                    }
                }
            }

            $expected = $entry['hash'];
        }

        // The chain must end at the site as it is now.
        if ($expected !== $current) {
            return $full;
        }

        $changed = array_map('strval', array_keys($ids));
        sort($changed, SORT_STRING);

        return ['changed' => $changed, 'full' => false, 'hash' => $current];
    }
}
