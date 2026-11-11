<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Manifest;

/**
 * The section tier: full JSON for one section (`layout`) or one item
 * (`pat:cta-banner`), exactly as the manifest holds it. Nothing is folded,
 * and counts are exact, not banded. An item also names its section and
 * carries its stats and agency notes; a section carries its items' stats by
 * id. Neither stats nor notes are covered by the hashes printed beside them.
 *
 * Compact canonical JSON, so the same site state gives the same bytes.
 *
 * @phpstan-import-type NotesByItem from AgencyNotes
 */
final class SectionRenderer
{
    public const TIER = 'section';

    /**
     * @param NotesByItem $notes
     * @return string|null Null when no section or item has that id.
     */
    public function render(Manifest $manifest, string $id, array $notes = []): ?string
    {
        $section = $manifest->section($id);

        if ($section !== null) {
            $data = $section->toArray();

            if ($section->stats() !== []) {
                $data['stats'] = $section->stats();
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
}
