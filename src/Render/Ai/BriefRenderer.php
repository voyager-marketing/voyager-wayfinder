<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Scanners\Builders;
use Voyager\Wayfinder\Scanners\ThemeGates;

/**
 * The brief: what an agent reads first. Site identity, what content the site
 * holds, where the things people ask about most are edited, how many agency
 * notes exist, and what else to ask for. About 600 tokens on the fixture.
 *
 * Counts print as bands, and nothing here depends on a count except through
 * its band, so publishing content leaves the bytes alone until a band moves.
 *
 * It must fit 2,400 characters. On overflow it gives up detail in a fixed
 * order, stopping as soon as it fits: field names per post type go from 3 to
 * 1 to none; then the `content:`, `terms:` and `plugins:` lines keep fewer
 * entries and end in `+N more`; then the `gates:` line does the same; then
 * edit lines go from the end. The header, `site:`, `layout:`, `locks:`,
 * `builders:`, `notes:` and `more:` lines always stay.
 *
 * Who can open a thing and what its layout allows are kept apart: an edit
 * line says when the client role cannot open its screen, and the `locks:`
 * line says only what the header and footer layouts allow.
 *
 * @phpstan-import-type NotesByItem from AgencyNotes
 */
final class BriefRenderer
{
    public const TIER = 'brief';

    public const BUDGET = 2400;

    public const MAX_EDIT_LINES = 8;

    /**
     * Edit lines custom post types may take, so the header, footer and
     * bindings still get theirs on a site with many post types.
     */
    public const MAX_POST_TYPE_EDIT_LINES = 3;

    /** Fields named per post type on the content line. */
    public const FIELDS_SHOWN = 3;

    /**
     * A synced pattern earns an edit line when it is used this often. It must
     * be the bottom of a band, or a count moving inside its band could add or
     * drop the line.
     */
    public const SHARED_PATTERN_USES = 1;

    /** Taxonomies WordPress registers itself. */
    public const BUILTIN_TAXONOMIES = ['category', 'post_format', 'post_tag'];

    private int $budget;

    public function __construct(int $budget = self::BUDGET)
    {
        $this->budget = $budget;
    }

    /**
     * @param NotesByItem $notes
     */
    public function render(Manifest $manifest, array $notes = []): string
    {
        $visibility = Visibility::of($manifest);

        /** @var array<string, Item> $items */
        $items = [];
        foreach ($visibility->listed as ['item' => $item]) {
            $items[$item->id] = $item;
        }

        $sections = array_map(static fn ($section): string => $section->name, $manifest->sections);
        $fixed = [
            'header' => Compact::header(self::TIER, $manifest),
            'site'   => self::siteLine($items),
            'layout' => self::layoutLine($items, $visibility),
            'locks'  => self::locksLine($items),
            'builders' => self::buildersLine($items),
            'notes'  => self::notesLine($notes, $items),
            'more'   => Compact::keyLine('more', [
                'index (every item, one line each)',
                'section <section or item id> (full JSON; sections ' . implode(', ', $sections) . ')',
                'changes since <hash> (changed ids)',
            ]),
        ];

        $terms = self::termsValues($items);
        $plugins = self::pluginsValues($items);
        $gates = isset($items[ThemeGates::ID]) ? Facts::gateWords($items[ThemeGates::ID]) : [];
        $sources = [];
        $edit = array_map(
            static function (Item $item) use (&$sources): string {
                // A source's unread settings are named once, on its first binding's line.
                $source = $item->kind === 'binding' && is_string($item->facts['source'] ?? null) ? $item->facts['source'] : null;
                $unread = $source !== null && !isset($sources[$source]) ? Facts::unreadPart($item) : '';

                if ($source !== null) {
                    $sources[$source] = true;
                }

                $hint = self::editHint($item);
                $who = Facts::cannotOpen($item);

                return Compact::keyLine('edit', [$item->id, $item->label, implode('; ', array_filter([$hint, str_contains($hint, $who) ? '' : $who, $unread])), $item->edit]);
            },
            self::editItems($items)
        );

        $text = '';
        foreach (self::steps(count(self::contentValues($items, 0)), count($terms), count($plugins), count($gates), count($edit)) as [$fields, $keep, $gatesKeep, $edits]) {
            $text = self::compose($fixed, self::contentValues($items, $fields), $terms, $plugins, $gates, array_slice($edit, 0, $edits), $keep, $gatesKeep);

            if (Compact::length($text) <= $this->budget) {
                return $text;
            }
        }

        // Nothing optional is left. Only the lines that always stay can be over.
        return $text;
    }

    /**
     * The overflow order, as [field names per post type, entries kept per
     * list line (null keeps all), entries kept on the gates line (null keeps
     * all), edit lines]. The first step is the brief with nothing given up.
     *
     * @return list<array{0: int, 1: int|null, 2: int|null, 3: int}>
     */
    private static function steps(int $content, int $terms, int $plugins, int $gates, int $edits): array
    {
        $steps = [[self::FIELDS_SHOWN, null, null, $edits], [1, null, null, $edits], [0, null, null, $edits]];

        for ($keep = max($content, $terms, $plugins) - 1; $keep >= 0; $keep--) {
            $steps[] = [0, $keep, null, $edits];
        }

        for ($keep = $gates - 1; $keep >= 0; $keep--) {
            $steps[] = [0, 0, $keep, $edits];
        }

        for ($count = $edits - 1; $count >= 0; $count--) {
            $steps[] = [0, 0, 0, $count];
        }

        return $steps;
    }

    /**
     * @param array{header: string, site: string|null, layout: string|null, locks: string|null, builders: string|null, notes: string, more: string} $fixed
     * @param list<string> $content
     * @param list<string> $terms
     * @param list<string> $plugins
     * @param list<string> $gates
     * @param list<string> $edit
     */
    private static function compose(array $fixed, array $content, array $terms, array $plugins, array $gates, array $edit, ?int $keep, ?int $gatesKeep): string
    {
        $lines = array_filter([
            $fixed['header'],
            $fixed['site'],
            self::listLine('content', $content, $keep),
            self::listLine('terms', $terms, $keep),
            $fixed['layout'],
            $fixed['locks'],
            $fixed['builders'],
            self::listLine('plugins', $plugins, $keep),
            self::gatesLine($gates, $gatesKeep),
        ], static fn (?string $line): bool => $line !== null);

        return implode("\n", [...$lines, ...$edit, $fixed['notes'], $fixed['more']]) . "\n";
    }

    /**
     * A `key: a | b | c` line, keeping the first `$keep` values and counting
     * the rest as `+N more`. Null when there is nothing to list.
     *
     * @param list<string> $values
     */
    private static function listLine(string $key, array $values, ?int $keep): ?string
    {
        if ($values === []) {
            return null;
        }

        if ($keep !== null && count($values) > $keep) {
            $values = [...array_slice($values, 0, $keep), '+' . (count($values) - $keep) . ' more'];
        }

        return Compact::keyLine($key, $values);
    }

    /**
     * `gates: custom colors off, default palette off, appearanceTools on`,
     * comma separated as it reads as one phrase. Null when the theme turns
     * nothing off.
     *
     * @param list<string> $values
     */
    private static function gatesLine(array $values, ?int $keep): ?string
    {
        if ($values === []) {
            return null;
        }

        if ($keep !== null && count($values) > $keep) {
            $values = [...array_slice($values, 0, $keep), '+' . (count($values) - $keep) . ' more'];
        }

        return 'gates: ' . implode(', ', array_map([Compact::class, 'clean'], $values));
    }

    /**
     * @param array<string, Item> $items
     */
    private static function siteLine(array $items): ?string
    {
        $values = [];

        $title = self::optionValue($items, 'blogname');
        if ($title !== null && $title !== '') {
            $values[] = $title;
        }

        $version = $items['core:wordpress']->facts['version'] ?? null;
        if (is_string($version)) {
            $values[] = 'WordPress ' . $version;
        }

        $stylesheet = self::optionValue($items, 'stylesheet');
        $template = self::optionValue($items, 'template');
        if ($stylesheet !== null) {
            $values[] = 'theme ' . $stylesheet . ($template !== null && $template !== $stylesheet ? ', child of ' . $template : '');
        }

        $showOnFront = self::optionValue($items, 'show_on_front');
        $front = self::optionValue($items, 'page_on_front');
        $posts = self::optionValue($items, 'page_for_posts');
        if ($showOnFront === 'page' && $front !== null) {
            $values[] = 'front page ' . $front;
        } elseif ($showOnFront !== null) {
            $values[] = 'front page shows latest posts';
        }
        if ($posts !== null) {
            $values[] = 'posts page ' . $posts;
        }

        $permalinks = self::optionValue($items, 'permalink_structure');
        if ($permalinks !== null) {
            $values[] = 'permalinks ' . ($permalinks === '' ? 'plain' : $permalinks);
        }

        return $values === [] ? null : Compact::keyLine('site', $values);
    }

    /**
     * Post types with their published band and up to `$fieldsShown` field
     * names, custom types first. With none shown, no field list at all.
     *
     * @param array<string, Item> $items
     * @return list<string>
     */
    private static function contentValues(array $items, int $fieldsShown): array
    {
        $custom = [];
        $builtin = [];

        foreach ($items as $item) {
            if ($item->kind !== 'post_type') {
                continue;
            }

            $value = $item->label . ' ' . Band::of(Facts::postCount($item));

            foreach (Facts::builderDocs($item) as $builder => $count) {
                $value .= ', ' . Builders::bundled()->label($builder) . ' ' . Band::of($count);
            }

            if (Facts::isAdminOnly($item)) {
                $value .= ' admin-only';
            }

            $fields = Facts::fieldKeys($item);
            if ($fields !== [] && $fieldsShown > 0) {
                $more = count($fields) - $fieldsShown;
                $value .= ' (' . implode(', ', array_slice($fields, 0, $fieldsShown)) . ($more > 0 ? ' +' . $more : '') . ')';
            }

            if (Facts::isCustomPostType($item)) {
                $custom[] = $value;
            } else {
                $builtin[] = $value;
            }
        }

        return [...$custom, ...$builtin];
    }

    /**
     * Taxonomies with their term band, custom ones first. An empty built-in
     * taxonomy (Tags, Formats) says nothing about the site and is left to
     * the index.
     *
     * @param array<string, Item> $items
     * @return list<string>
     */
    private static function termsValues(array $items): array
    {
        $custom = [];
        $builtin = [];

        foreach ($items as $item) {
            if ($item->kind !== 'taxonomy') {
                continue;
            }

            $terms = Facts::termCount($item);
            $isBuiltin = in_array(substr($item->id, 4), self::BUILTIN_TAXONOMIES, true);

            if ($isBuiltin && $terms === 0) {
                continue;
            }

            $types = $item->facts['object_types'] ?? [];
            $value = $item->label . ' ' . Band::of($terms)
                . (is_array($types) && $types !== [] ? ' (on ' . implode(', ', array_map('strval', $types)) . ')' : '');

            if ($isBuiltin) {
                $builtin[] = $value;
            } else {
                $custom[] = $value;
            }
        }

        return [...$custom, ...$builtin];
    }

    /**
     * Counts of what the index lists in the layout, and of what it folds.
     *
     * @param array<string, Item> $items
     */
    private static function layoutLine(array $items, Visibility $visibility): ?string
    {
        $nouns = [
            'template'      => 'template',
            'template_part' => 'part',
            'pattern'       => 'pattern',
            'navigation'    => 'menu',
            'binding'       => 'binding',
            'menu_location' => 'menu location',
            'sidebar'       => 'sidebar',
            'builder_template' => 'builder template',
        ];
        $counts = array_fill_keys(array_keys($nouns), 0);

        foreach ($items as $item) {
            if (isset($counts[$item->kind])) {
                $counts[$item->kind]++;
            }
        }

        if (array_sum($counts) === 0) {
            return null;
        }

        $values = [];
        foreach ($nouns as $kind => $noun) {
            if ($counts[$kind] > 0) {
                $values[] = $counts[$kind] . ' ' . $noun . ($counts[$kind] === 1 ? '' : 's');
            }
        }

        $folded = array_sum($visibility->foldedCounts);
        if ($folded > 0) {
            $values[] = $folded . ' inherited or unused, folded in the index';
        }

        return Compact::keyLine('layout', $values);
    }

    /**
     * What the layout of the first header part and the first footer part
     * allows, the parts the edit lines name, header first, with parts that
     * read the same sharing one entry: `locks: part:header, part:footer: not
     * locked at the edit path, content only inside a template (WordPress 7
     * default)`. Who can open them is on their edit lines, never here. Null
     * when neither carries a lock fact, as on a classic theme.
     *
     * @param array<string, Item> $items Sorted by id.
     */
    private static function locksLine(array $items): ?string
    {
        $parts = [];

        foreach ($items as $item) {
            $area = $item->facts['area'] ?? null;

            if ($item->kind === 'template_part' && ($area === 'header' || $area === 'footer') && is_array($item->facts['lock'] ?? null) && !isset($parts[$area])) {
                $parts[$area] = $item;
            }
        }

        /** @var array<string, list<string>> $byWords */
        $byWords = [];

        foreach (['header', 'footer'] as $area) {
            if (isset($parts[$area])) {
                $byWords[self::partLockWords($parts[$area])][] = $parts[$area]->id;
            }
        }

        $values = [];

        foreach ($byWords as $words => $ids) {
            $values[] = implode(', ', $ids) . ': ' . $words;
        }

        return $values === [] ? null : Compact::keyLine('locks', $values);
    }

    /**
     * `not locked at the edit path`, or `2 locked blocks at the edit path,
     * strictest all (template_lock)`, then `content only inside a template
     * (WordPress 7 default)` where that applies.
     */
    private static function partLockWords(Item $part): string
    {
        $lock = is_array($part->facts['lock'] ?? null) ? $part->facts['lock'] : [];
        $locked = is_int($lock['locked'] ?? null) ? $lock['locked'] : 0;

        $words = $locked > 0
            ? sprintf('%d locked block%s at the edit path, strictest %s (%s)', $locked, $locked === 1 ? '' : 's', str_replace('_', ' ', (string) ($lock['effective'] ?? '')), (string) ($lock['src'] ?? ''))
            : 'not locked at the edit path';

        return ($lock['in_template'] ?? null) === 'content_only'
            ? $words . ', content only inside a template (WordPress 7 default)'
            : $words;
    }

    /**
     * Each page builder that owns something on the site, with its published
     * documents as a band and its templates (`btpl:` items, structure, so an
     * exact count), then a reminder that Wayfinder does not read inside
     * them: `builders: Elementor 6-20 documents, 2 templates | content not
     * read; edit in the builder`. Null when no builder owns anything.
     *
     * @param array<string, Item> $items
     */
    private static function buildersLine(array $items): ?string
    {
        $docs = [];
        $templates = [];

        foreach ($items as $item) {
            foreach (Facts::builderDocs($item) as $builder => $count) {
                $docs[$builder] = ($docs[$builder] ?? 0) + $count;
            }

            $builder = $item->facts['builder'] ?? null;

            if (is_string($builder)) {
                $docs[$builder] ??= 0;

                if ($item->kind === 'builder_template') {
                    $templates[$builder] = ($templates[$builder] ?? 0) + 1;
                }
            }
        }

        if ($docs === []) {
            return null;
        }

        ksort($docs, SORT_STRING);
        $values = [];

        foreach ($docs as $builder => $count) {
            $value = Builders::bundled()->label($builder) . ' ' . Band::of($count) . ' documents';
            $value .= isset($templates[$builder]) ? ', ' . $templates[$builder] . ' template' . ($templates[$builder] === 1 ? '' : 's') : '';
            $values[] = $value;
        }

        return Compact::keyLine('builders', [...$values, 'content not read; edit in the builder']);
    }

    /**
     * @param array<string, Item> $items
     * @return list<string>
     */
    private static function pluginsValues(array $items): array
    {
        $values = [];

        foreach ($items as $item) {
            if ($item->kind === 'plugin') {
                $role = $item->facts['role'] ?? null;
                $values[] = substr($item->id, 7) . (is_string($role) ? ' (' . $role . ')' : '');
            }
        }

        return $values;
    }

    /**
     * Up to eight edit locations, ranked: custom post types, the header and
     * footer parts, bindings and ACF options pages, synced patterns in use,
     * menus and classic menu locations with a menu.
     *
     * Post types with nothing published get no line, and the rest take at
     * most three, most published band first. Only the first header part and
     * the first footer part count. Both rules exist because a site with many
     * post types or parts would otherwise spend every line before reaching
     * the bindings. A count matters here only through its band (0 is a band
     * of its own), so the bytes stay put while content grows inside one.
     *
     * @param array<string, Item> $items Sorted by id.
     * @return list<Item>
     */
    private static function editItems(array $items): array
    {
        $ranks = [[], [], [], [], []];
        $areas = [];

        foreach ($items as $item) {
            if ($item->edit === null) {
                continue;
            }

            $area = $item->facts['area'] ?? null;

            if (Facts::isCustomPostType($item)) {
                if (Facts::postCount($item) > 0) {
                    $ranks[0][] = $item;
                }
            } elseif ($item->kind === 'template_part' && ($area === 'header' || $area === 'footer')) {
                if (!isset($areas[$area])) {
                    $areas[$area] = true;
                    $ranks[1][] = $item;
                }
            } elseif ($item->kind === 'binding' || $item->kind === 'options_page') {
                $ranks[2][] = $item;
            } elseif (
                $item->kind === 'pattern'
                && ($item->facts['synced'] ?? false) === true
                && Facts::usageCount($item) >= self::SHARED_PATTERN_USES
            ) {
                $ranks[3][] = $item;
            } elseif ($item->kind === 'navigation' || ($item->kind === 'menu_location' && ($item->facts['menu'] ?? null) !== null)) {
                $ranks[4][] = $item;
            }
        }

        // The most published and the most used band first; ties stay in id order.
        usort($ranks[0], static fn (Item $a, Item $b): int => [Band::rank(Facts::postCount($b)), $a->id] <=> [Band::rank(Facts::postCount($a)), $b->id]);
        $ranks[0] = array_slice($ranks[0], 0, self::MAX_POST_TYPE_EDIT_LINES);
        usort($ranks[3], static fn (Item $a, Item $b): int => [Band::rank(Facts::usageCount($b)), $a->id] <=> [Band::rank(Facts::usageCount($a)), $b->id]);

        return array_slice(array_merge(...$ranks), 0, self::MAX_EDIT_LINES);
    }

    private static function editHint(Item $item): string
    {
        return match ($item->kind) {
            'post_type'     => Band::of(Facts::postCount($item)) . ' published',
            'template_part' => 'area ' . (string) ($item->facts['area'] ?? ''),
            'pattern'       => 'synced, used ' . Band::of(Facts::usageCount($item)) . ' times, one edit changes all',
            'binding'       => implode('; ', array_filter([Facts::readsPart($item), self::usedInHint($item)])),
            'options_page', 'menu_location' => Facts::describe($item, false),
            default         => self::usedInHint($item),
        };
    }

    private static function usedInHint(Item $item): string
    {
        $parts = Facts::usedInParts($item);

        return $parts === [] ? '' : 'in ' . implode(', ', $parts);
    }

    /**
     * @param NotesByItem         $notes
     * @param array<string, Item> $items
     */
    private static function notesLine(array $notes, array $items): string
    {
        $total = 0;
        $review = 0;

        foreach ($notes as $itemNotes) {
            foreach ($itemNotes as $note) {
                $total++;

                if ($note['status'] === AgencyNotes::NEEDS_REVIEW) {
                    $review++;
                }
            }
        }

        if ($total === 0) {
            return 'notes: none';
        }

        $onListed = count(array_intersect_key($notes, $items));

        return Compact::keyLine('notes', [
            $total . ' agency note' . ($total === 1 ? '' : 's') . ' on ' . $onListed . ' item' . ($onListed === 1 ? '' : 's') . ', shown in the index',
            $review > 0 ? $review . ' needing review' : null,
        ]);
    }

    /**
     * @param array<string, Item> $items
     */
    private static function optionValue(array $items, string $name): ?string
    {
        $value = $items['opt:' . $name]->facts['value'] ?? null;

        return is_string($value) ? $value : null;
    }
}
