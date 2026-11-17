<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Manifest;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * The whole scan: a header, a hashed body and the stats.
 *
 * The body is `schema_version` plus the sections sorted by name, and
 * `manifest_hash` is sha256 of its canonical JSON. The header (generated_at,
 * site_url, plugin_version) sits outside the body, so scanning an unchanged
 * site twice gives the same body bytes and the same hash. So do the stats
 * (counts, usage lists and routes' sampled examples, by item id): publishing
 * a post moves `stats_hash`
 * and leaves `manifest_hash` alone. The ETag, notes and change history read
 * only `manifest_hash`.
 */
final class Manifest
{
    public const SCHEMA_VERSION = 1;

    /** @var list<Section> */
    public readonly array $sections;

    /** @var array<string, string> */
    public readonly array $header;

    public readonly string $hash;

    /** sha256 of the canonical JSON of stats(). Never used for the ETag, notes or change history. */
    public readonly string $statsHash;

    /**
     * @param list<Section>         $sections
     * @param array<string, string> $header   generated_at, site_url, plugin_version. Never hashed.
     *
     * @throws InvalidArgumentException On two sections with one name.
     */
    public function __construct(array $sections, array $header = [])
    {
        $byName = [];

        foreach ($sections as $section) {
            if (isset($byName[$section->name])) {
                throw new InvalidArgumentException(sprintf('Two sections are named "%s".', esc_html($section->name)));
            }

            $byName[$section->name] = $section;
        }

        ksort($byName, SORT_STRING);

        $this->sections = array_values($byName);
        $this->header = $header;
        $this->hash = CanonicalJson::hash($this->body());
        $this->statsHash = CanonicalJson::hash($this->statsObject());
    }

    /**
     * The manifest toJson() wrote, rebuilt, or null when it cannot be.
     *
     * Null unless the rebuilt manifest reproduces the stored `manifest_hash`
     * and `stats_hash` exactly, so a stored copy that lost or changed a byte
     * is never served in place of a scan. It decodes from JSON rather than
     * from an array because an array cannot tell `{}` from `[]`, and the
     * hash can.
     */
    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            !$data instanceof stdClass
            || !is_string($data->manifest_hash ?? null)
            || !is_string($data->stats_hash ?? null)
            || !($data->body ?? null) instanceof stdClass
            || ($data->body->schema_version ?? null) !== self::SCHEMA_VERSION
            || !is_array($data->body->sections ?? null)
        ) {
            return null;
        }

        $stats = ($data->stats ?? null) instanceof stdClass ? get_object_vars($data->stats) : [];
        $header = [];

        foreach (($data->header ?? null) instanceof stdClass ? get_object_vars($data->header) : [] as $key => $value) {
            if (is_string($value)) {
                $header[(string) $key] = $value;
            }
        }

        try {
            $sections = [];

            foreach ($data->body->sections as $section) {
                if (!$section instanceof stdClass || !is_string($section->name ?? null) || !is_array($section->items ?? null)) {
                    return null;
                }

                $items = [];

                foreach ($section->items as $item) {
                    if (!$item instanceof stdClass || !is_string($item->id ?? null) || !is_string($item->kind ?? null) || !is_string($item->label ?? null)) {
                        return null;
                    }

                    $facts = self::thawMap($item->facts ?? null);
                    $refs = $item->refs ?? [];
                    $edit = $item->edit ?? null;
                    $itemStats = self::thawMap($stats[$item->id] ?? null);

                    if (!is_array($facts) || !is_array($refs) || !is_array($itemStats) || ($edit !== null && !is_string($edit))) {
                        return null;
                    }

                    $items[] = new Item($item->id, $item->kind, $item->label, $facts, $edit, array_map('strval', $refs), $itemStats);
                }

                $sections[] = new Section($section->name, $items);
            }

            $manifest = new self($sections, $header);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $manifest->hash === $data->manifest_hash && $manifest->statsHash === $data->stats_hash ? $manifest : null;
    }

    /**
     * An item's facts or stats: always an array at the top, empty when absent.
     * Null when the stored value is not a map.
     *
     * @return array<string, mixed>|null
     */
    private static function thawMap(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }

        if (!$value instanceof stdClass) {
            return null;
        }

        return array_map([self::class, 'thaw'], get_object_vars($value));
    }

    /**
     * A decoded JSON value back in the shape the scanners build: maps as
     * arrays, so renderers that test is_array() still work. An empty map, and
     * a map whose keys read 0..n-1, stay objects, because as arrays they
     * would encode as lists and move the hash.
     */
    private static function thaw(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'thaw'], $value);
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        $map = get_object_vars($value);

        if ($map === [] || array_is_list($map)) {
            return $value;
        }

        return array_map([self::class, 'thaw'], $map);
    }

    public function section(string $name): ?Section
    {
        foreach ($this->sections as $section) {
            if ($section->name === $name) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Every item id in every section, sorted.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = [];

        foreach ($this->sections as $section) {
            foreach ($section->items as $item) {
                $ids[] = $item->id;
            }
        }

        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Everything the manifest hash covers.
     *
     * @return array{schema_version: int, sections: list<array<string, mixed>>}
     */
    public function body(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sections'       => array_map(static fn (Section $section): array => $section->toArray(), $this->sections),
        ];
    }

    /**
     * Stats by item id across every section, sorted, for the items that have any.
     *
     * @return array<string, array<string, mixed>>
     */
    public function stats(): array
    {
        $stats = [];

        foreach ($this->sections as $section) {
            $stats += $section->stats();
        }

        ksort($stats, SORT_STRING);

        return $stats;
    }

    /**
     * @return array{body: array{schema_version: int, sections: list<array<string, mixed>>}, header: array<string, string>|stdClass, manifest_hash: string, stats: array<string, array<string, mixed>>|stdClass, stats_hash: string}
     */
    public function toArray(): array
    {
        return [
            'body'          => $this->body(),
            // An empty header must still encode as {}.
            'header'        => $this->header === [] ? new stdClass() : $this->header,
            'manifest_hash' => $this->hash,
            'stats'         => $this->statsObject(),
            'stats_hash'    => $this->statsHash,
        ];
    }

    /**
     * stats(), with no stats still encoding as {}.
     *
     * @return array<string, array<string, mixed>>|stdClass
     */
    private function statsObject(): array|stdClass
    {
        $stats = $this->stats();

        return $stats === [] ? new stdClass() : $stats;
    }

    public function toJson(bool $pretty = false): string
    {
        return CanonicalJson::encode($this->toArray(), $pretty);
    }
}
