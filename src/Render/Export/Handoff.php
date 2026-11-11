<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Export;

use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Notes\NoteStatus;
use Voyager\Wayfinder\Render\Ai\AgencyNotes;
use Voyager\Wayfinder\Render\Ai\Visibility;
use Voyager\Wayfinder\Render\Human\ManualView;

/**
 * What both handoff exports say, worked out once so the Markdown and the
 * HTML cannot disagree: the sections in the Wayfinder page's order, each
 * item's plain-language summary and full edit URL, the items it relates to,
 * and the agency notes under it.
 *
 * It reads what the AI tiers read and leaves out what they leave out. Notes
 * arrive as AgencyNotes shapes them, text and status only, never the author
 * or a time. Parent theme files and registered patterns that nothing on the
 * site uses, which the tiers fold, become one count line per section. An
 * item that carries a note is always listed, so a handoff never drops one.
 *
 * Calls nothing but WordPress's translation functions, so the unit suite
 * builds one from a fixture manifest.
 *
 * @phpstan-import-type NotesByItem from AgencyNotes
 * @phpstan-import-type TierNote from AgencyNotes
 * @phpstan-type Link array{id: string, label: string, shown: bool}
 * @phpstan-type Entry array{id: string, label: string, kind: string, summary: string, edit_url: string|null, edit_text: string, related: list<Link>, used_by: list<Link>, notes: list<TierNote>}
 * @phpstan-type ExportSection array{name: string, title: string, description: string, entries: list<Entry>, left_out: int}
 */
final class Handoff
{
    /** Sections that get a heading even when the scan found nothing in them. Others appear only when they hold items. */
    public const ALWAYS = ['content_model', 'layout', 'routes'];

    public readonly string $siteName;

    /** The site's home URL from the manifest header, or empty. */
    public readonly string $siteUrl;

    /** Language tag for the HTML export. */
    public readonly string $language;

    /** When the manifest was scanned, as `Y-m-d H:i UTC`. Empty when the header has no time. */
    public readonly string $generated;

    /** First 6 hex of the manifest hash, as the Wayfinder page shows it. */
    public readonly string $shortHash;

    /** @var list<ExportSection> */
    public readonly array $sections;

    /** @var list<array{id: string, label: string, body: string}> Notes on items that changed since the note was saved. */
    public readonly array $review;

    /** @var list<array{id: string, body: string}> Notes on items that are no longer on the site. */
    public readonly array $orphans;

    public readonly int $noteCount;

    /**
     * @param NotesByItem $notes    The notes the AI tiers read.
     * @param string      $adminUrl wp-admin URL ending in a slash. Edit paths are joined to it.
     * @param string      $language The site's language tag.
     */
    public function __construct(Manifest $manifest, array $notes, string $siteName, string $adminUrl, string $language = 'en')
    {
        $this->siteName = $siteName;
        $this->siteUrl = (string) ($manifest->header['site_url'] ?? '');
        $this->language = $language !== '' ? $language : 'en';
        $this->generated = self::time((string) ($manifest->header['generated_at'] ?? ''));
        $this->shortHash = substr($manifest->hash, 0, 6);

        ksort($notes, SORT_STRING);

        /** @var array<string, Item> $items */
        $items = [];

        /** @var array<string, Section> $byName */
        $byName = [];

        foreach ($manifest->sections as $section) {
            $byName[$section->name] = $section;

            foreach ($section->items as $item) {
                $items[$item->id] = $item;
            }
        }

        /** @var array<string, true> $shown */
        $shown = [];
        foreach (Visibility::of($manifest)->listed as ['item' => $item]) {
            $shown[$item->id] = true;
        }
        foreach (array_keys($notes) as $id) {
            if (isset($items[$id])) {
                $shown[$id] = true;
            }
        }

        /** @var array<string, list<string>> $usedBy The inverse of refs, between items that exist. */
        $usedBy = [];
        foreach ($items as $item) {
            foreach ($item->refs as $ref) {
                if (isset($items[$ref])) {
                    $usedBy[$ref][] = $item->id;
                }
            }
        }

        $sections = [];

        foreach (self::sectionNames($byName) as $name) {
            [$title, $description] = ManualView::sectionText($name);
            $entries = [];
            $leftOut = 0;

            foreach (isset($byName[$name]) ? $byName[$name]->items : [] as $item) {
                if (!isset($shown[$item->id])) {
                    $leftOut++;
                    continue;
                }

                $entries[] = self::entry($item, $items, $shown, $usedBy[$item->id] ?? [], $notes[$item->id] ?? [], $adminUrl);
            }

            $sections[] = ['name' => $name, 'title' => $title, 'description' => $description, 'entries' => $entries, 'left_out' => $leftOut];
        }

        $review = [];
        $orphans = [];
        $count = 0;

        foreach ($notes as $id => $itemNotes) {
            $id = (string) $id;

            foreach ($itemNotes as $note) {
                $count++;

                if (!isset($items[$id])) {
                    $orphans[] = ['id' => $id, 'body' => $note['body']];
                } elseif ($note['status'] === NoteStatus::NEEDS_REVIEW) {
                    $review[] = ['id' => $id, 'label' => self::label($items[$id]), 'body' => $note['body']];
                }
            }
        }

        $this->sections = $sections;
        $this->review = $review;
        $this->orphans = $orphans;
        $this->noteCount = $count;
    }

    public function title(): string
    {
        return sprintf(
            /* translators: %s: site name. */
            __('%s site manual', 'voyager-wayfinder'),
            $this->siteName
        );
    }

    public function generatedLine(): string
    {
        if ($this->generated === '') {
            return sprintf(
                /* translators: %s: short manifest hash. */
                __('Generated from manifest %s.', 'voyager-wayfinder'),
                $this->shortHash
            );
        }

        return sprintf(
            /* translators: 1: date and time in UTC, 2: short manifest hash. */
            __('Generated %1$s from manifest %2$s.', 'voyager-wayfinder'),
            $this->generated,
            $this->shortHash
        );
    }

    public static function intro(): string
    {
        return __('How this site is built and where each thing is edited. Edit links open wp-admin and need an account that can use that screen.', 'voyager-wayfinder');
    }

    public static function emptyLine(): string
    {
        return __('Nothing found on this site.', 'voyager-wayfinder');
    }

    public static function leftOutLine(int $count): string
    {
        return sprintf(
            /* translators: %d: number of items. */
            _n(
                'Not listed: %d item that nothing on this site uses (a parent theme file or a registered pattern). The Wayfinder page in wp-admin lists it.',
                'Not listed: %d items that nothing on this site uses (parent theme files and registered patterns). The Wayfinder page in wp-admin lists them.',
                $count,
                'voyager-wayfinder'
            ),
            $count
        );
    }

    public static function notesTitle(): string
    {
        return __('Notes', 'voyager-wayfinder');
    }

    /**
     * How many notes there are and how many need attention.
     */
    public function notesLine(): string
    {
        if ($this->noteCount === 0) {
            return __('No agency notes yet.', 'voyager-wayfinder');
        }

        $line = sprintf(
            /* translators: %d: number of notes. */
            _n('%d agency note.', '%d agency notes.', $this->noteCount, 'voyager-wayfinder'),
            $this->noteCount
        );

        if ($this->review !== []) {
            $line .= ' ' . sprintf(
                /* translators: %d: number of notes. */
                _n('%d needs review.', '%d need review.', count($this->review), 'voyager-wayfinder'),
                count($this->review)
            );
        }

        if ($this->orphans !== []) {
            $line .= ' ' . sprintf(
                /* translators: %d: number of notes. */
                _n('%d is orphaned.', '%d are orphaned.', count($this->orphans), 'voyager-wayfinder'),
                count($this->orphans)
            );
        }

        return $line;
    }

    /**
     * @return array{0: string, 1: string} Heading and one line for notes that need review.
     */
    public static function reviewText(): array
    {
        return [
            __('Needs review', 'voyager-wayfinder'),
            __('A note needs review when the item it describes has changed since the note was saved.', 'voyager-wayfinder'),
        ];
    }

    /**
     * @return array{0: string, 1: string} Heading and one line for orphaned notes.
     */
    public static function orphansText(): array
    {
        return [
            __('Orphaned', 'voyager-wayfinder'),
            __('These notes describe items that are no longer on the site.', 'voyager-wayfinder'),
        ];
    }

    /**
     * "Note" or "Note (needs review)", for a note under its item.
     */
    public static function noteLabel(string $status): string
    {
        return match ($status) {
            NoteStatus::NEEDS_REVIEW => __('Note (needs review)', 'voyager-wayfinder'),
            NoteStatus::ORPHANED     => __('Note (orphaned)', 'voyager-wayfinder'),
            default                  => __('Note', 'voyager-wayfinder'),
        };
    }

    /**
     * A summary as a sentence: a full stop unless it already ends in one.
     */
    public static function sentence(string $text): string
    {
        $text = trim($text);

        return $text === '' || preg_match('/[.!?:]$/u', $text) === 1 ? $text : $text . '.';
    }

    /**
     * Section names in the Wayfinder page's order. Content, layout and routes
     * always; the rest when they hold items, a section a filter adds last.
     *
     * @param array<string, Section> $byName
     * @return list<string>
     */
    private static function sectionNames(array $byName): array
    {
        $has = static fn (string $name): bool => isset($byName[$name]) && $byName[$name]->items !== [];

        $names = array_values(array_filter(
            ManualView::ORDER,
            static fn (string $name): bool => in_array($name, self::ALWAYS, true) || $has($name)
        ));

        $extra = array_keys(array_diff_key($byName, array_flip(ManualView::ORDER)));
        sort($extra, SORT_STRING);

        foreach ($extra as $name) {
            if ($has($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, Item> $items
     * @param array<string, true> $shown
     * @param list<string>        $usedBy
     * @param list<TierNote>      $notes
     * @return Entry
     */
    private static function entry(Item $item, array $items, array $shown, array $usedBy, array $notes, string $adminUrl): array
    {
        $url = ManualView::editUrl($item->edit, $adminUrl);

        $text = match (true) {
            $url !== null => '',
            $item->edit !== null && $item->edit !== '' => sprintf(
                /* translators: %s: where to edit, such as "Site Editor > Patterns > Footer". */
                __('Edit in %s', 'voyager-wayfinder'),
                $item->edit
            ),
            $item->kind === 'route' => __('No edit screen of its own. Edit the template or the content it uses.', 'voyager-wayfinder'),
            default => __('No edit screen in wp-admin.', 'voyager-wayfinder'),
        };

        $link = static fn (string $id): array => [
            'id'    => $id,
            'label' => isset($items[$id]) ? self::label($items[$id]) : $id,
            'shown' => isset($shown[$id]),
        ];

        // A post type and its fields point at each other; one list says it.
        $usedBy = array_values(array_diff($usedBy, $item->refs));
        sort($usedBy, SORT_STRING);

        return [
            'id'        => $item->id,
            'label'     => self::label($item),
            'kind'      => ManualView::kindLabel($item->kind),
            'summary'   => ManualView::summary($item),
            'edit_url'  => $url,
            'edit_text' => $text,
            'related'   => array_map($link, $item->refs),
            'used_by'   => array_map($link, $usedBy),
            'notes'     => $notes,
        ];
    }

    private static function label(Item $item): string
    {
        return $item->label !== '' ? $item->label : $item->id;
    }

    /**
     * `2026-10-03T22:15:07Z` as `2026-10-03 22:15 UTC`.
     */
    private static function time(string $generatedAt): string
    {
        $time = $generatedAt !== '' ? strtotime($generatedAt) : false;

        return $time === false ? '' : gmdate('Y-m-d H:i', $time) . ' UTC';
    }
}
