<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Blocks;

use Voyager\Wayfinder\Manifest\IdFormat;

/**
 * Turns what a block points at into manifest ids, so the layout section, the
 * route map and the edit locator all read pointers and bindings one way.
 */
final class BlockRefs
{
    /**
     * The id a pointer block's reference record (BlockNode::$reference)
     * points at: `part:`, `pat:` or `nav:`. A synced pattern or menu that did
     * not resolve keeps its row id, `#<id>`, so it reads as missing rather
     * than vanishing.
     *
     * @param array<string, mixed>|null $reference
     */
    public static function pointer(?array $reference): ?string
    {
        $type = $reference['type'] ?? null;

        if ($type === 'template_part' || $type === 'pattern') {
            $slug = is_string($reference['slug'] ?? null) ? $reference['slug'] : '';

            return $slug === '' ? null : ($type === 'pattern' ? 'pat:' : 'part:') . $slug;
        }

        if ($type === 'synced_pattern' || $type === 'navigation') {
            $slug = is_string($reference['slug'] ?? null) ? $reference['slug'] : '';
            $id = is_int($reference['id'] ?? null) ? $reference['id'] : 0;

            if ($id <= 0) {
                return null;
            }

            return ($type === 'navigation' ? 'nav:' : 'pat:') . ($slug !== '' ? $slug : '#' . $id);
        }

        return null;
    }

    /**
     * The bindings on one block, keyed by the attribute each one binds.
     *
     * @return array<string, array{id: string, source: string, key: string|null, args: array<array-key, mixed>}>
     */
    public static function bindingsOf(BlockNode $node): array
    {
        $metadata = $node->attrs['metadata'] ?? null;
        $entries = is_array($metadata) ? ($metadata['bindings'] ?? null) : null;
        $bindings = [];

        foreach (is_array($entries) ? $entries : [] as $attribute => $entry) {
            $binding = self::binding($entry);

            if ($binding !== null) {
                $bindings[(string) $attribute] = $binding;
            }
        }

        return $bindings;
    }

    /**
     * One `metadata.bindings` entry as `bind:<source>.<key>`. The key is
     * `args.key`, else the first arg value; with neither the id is
     * `bind:<source>`.
     *
     * @return array{id: string, source: string, key: string|null, args: array<array-key, mixed>}|null
     *         Null for an entry with no usable source.
     */
    public static function binding(mixed $entry): ?array
    {
        if (!is_array($entry) || !is_string($entry['source'] ?? null) || $entry['source'] === '') {
            return null;
        }

        $source = $entry['source'];
        $args = is_array($entry['args'] ?? null) ? $entry['args'] : [];
        $key = self::key($args);
        $id = 'bind:' . $source . ($key !== null ? '.' . $key : '');

        if (!IdFormat::isValid($id)) {
            return null;
        }

        return ['id' => $id, 'source' => $source, 'key' => $key, 'args' => $args];
    }

    /**
     * @param array<array-key, mixed> $args
     */
    private static function key(array $args): ?string
    {
        if ($args === []) {
            return null;
        }

        $value = is_string($args['key'] ?? null) && $args['key'] !== '' ? $args['key'] : reset($args);

        if (is_string($value) || is_int($value) || is_float($value)) {
            $value = (string) $value;

            return $value !== '' ? $value : null;
        }

        return null;
    }
}
