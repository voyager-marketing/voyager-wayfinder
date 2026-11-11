<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Manifest;

use InvalidArgumentException;
use stdClass;

/**
 * One thing on the site: a post type, a template, a setting.
 *
 * Immutable. The hash covers every field but `stats` and is computed once,
 * here, so two items built from the same site state always carry the same
 * hash. Stats are what moves whenever someone publishes: counts of content,
 * lists of the content that uses the item, and the post a route samples.
 * Structure is hashed, volatile content is not, so publishing a post never
 * reads as a change.
 */
final class Item
{
    public readonly string $id;

    public readonly string $kind;

    public readonly string $label;

    /** @var array<string, mixed> */
    public readonly array $facts;

    public readonly ?string $edit;

    /** @var list<string> Sorted and unique. */
    public readonly array $refs;

    /** @var array<string, mixed> Outside every hash. */
    public readonly array $stats;

    public readonly string $hash;

    /**
     * @param array<string, mixed> $facts Keyed by name. Key order does not matter.
     * @param string|null          $edit  Edit path relative to wp-admin, or a Site Editor trail.
     * @param list<string>         $refs  Ids of related items. Order and duplicates do not matter.
     * @param array<string, mixed> $stats Counts of content, lists of content that use the item, a route's sampled example.
     *
     * @throws InvalidArgumentException On a bad id or ref, or an empty kind.
     */
    public function __construct(
        string $id,
        string $kind,
        string $label,
        array $facts = [],
        ?string $edit = null,
        array $refs = [],
        array $stats = []
    ) {
        IdFormat::assertValid($id);

        if ($kind === '') {
            throw new InvalidArgumentException(sprintf('Item "%s" has an empty kind.', esc_html($id)));
        }

        foreach ($refs as $ref) {
            IdFormat::assertValid($ref);
        }

        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);

        $this->id = $id;
        $this->kind = $kind;
        $this->label = $label;
        $this->facts = $facts;
        $this->edit = $edit === '' ? null : $edit;
        $this->refs = $refs;
        $this->stats = $stats;
        $this->hash = CanonicalJson::hash($this->body());
    }

    /**
     * Everything the hash covers. `stats` is not part of it.
     *
     * @return array{edit: string|null, facts: array<string, mixed>|stdClass, id: string, kind: string, label: string, refs: list<string>}
     */
    public function body(): array
    {
        return [
            'edit'  => $this->edit,
            // An empty map must still encode as {}.
            'facts' => $this->facts === [] ? new stdClass() : $this->facts,
            'id'    => $this->id,
            'kind'  => $this->kind,
            'label' => $this->label,
            'refs'  => $this->refs,
        ];
    }

    /**
     * The hashed fields and the hash. Stats travel separately, in the
     * manifest's `stats` block.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->body() + ['hash' => $this->hash];
    }
}
