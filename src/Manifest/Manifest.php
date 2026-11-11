<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Manifest;

use InvalidArgumentException;
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
