<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;

/**
 * The index: one compact line per item, with agency notes indented under
 * their item. About 4,000 tokens on the fixture.
 *
 * Lines are ordered by what answers edit questions, not by id: templates,
 * routes, parts, post types, plugins and patterns in use first, then every
 * other kind, then unused patterns, fields and options (see RANKS). Within a
 * rank, by id. Post types and plugins go before patterns: they are small,
 * fixed sets behind most "where is this edited?" questions, and a site can
 * carry a hundred patterns.
 *
 * The fields of a post type with no posts are not listed one by one. They
 * become one closing line per post type, `field: +33 of pt:service_area,
 * which has no posts (section content_model, pages 3-4)`, and the post
 * type's own line counts them.
 *
 * It must fit 16,000 characters. On overflow it gives up detail in a fixed
 * order, keeping as much as fits at each step: first the refs column, then
 * post type field lists (collapsed to counts), then whole items from the end.
 * Nothing goes silently: each kind of item dropped from a section is
 * summarised as `+12 more patterns in use (section layout, pages 3-4)`, which
 * names the section tier call, and its pages, that returns them. Patterns in
 * use and unused patterns rank apart, so each gets its own line.
 *
 * When it lists a binding, a closing line says binding keys are known only
 * from blocks that use them, so an agent does not read the list as every key
 * a source serves, and that settings saved beside them that nothing reads
 * are named once, on the source's first binding line.
 *
 * @phpstan-import-type NotesByItem from AgencyNotes
 */
final class IndexRenderer
{
    public const TIER = 'index';

    public const BUDGET = 16000;

    /** Longest note text shown before it is cut. The section tier has the rest. */
    public const NOTE_CHARS = 200;

    /** Closing line when any binding is listed. */
    public const BINDING_KEYS_LINE = 'bind: keys are known only from blocks that use them, so a source may serve more; settings saved beside them that nothing reads are named on the source\'s first binding line';

    /**
     * Where a kind's lines sort, lowest first. A kind not named here sorts at
     * OTHER_RANK, and a pattern nothing uses at UNUSED_PATTERN_RANK.
     */
    public const RANKS = [
        'template'      => 0,
        'route'         => 1,
        'template_part' => 2,
        'post_type'     => 3,
        'plugin'        => 4,
        'pattern'       => 5,
        'field'         => 8,
        'option'        => 9,
    ];

    public const OTHER_RANK = 6;

    public const UNUSED_PATTERN_RANK = 7;

    /** `+N more <noun> (<where>)`: what a section lost to the budget, and the call that returns it. */
    public const DROPPED_LINE = '+%d more %s (%s)';

    /** `field: +N of <post type> ...`: the fields of a post type with no posts. */
    public const NO_POSTS_LINE = 'field: +%d of %s, which has no posts (%s)';

    /** Singular and plural for a dropped line of unused patterns. NOUNS names the patterns in use. */
    private const UNUSED_PATTERN_NOUNS = ['unused pattern', 'unused patterns'];

    /** Singular and plural, for the dropped lines. A kind not named here is an item. */
    private const NOUNS = [
        'binding'          => ['binding', 'bindings'],
        'builder_template' => ['builder template', 'builder templates'],
        'core'             => ['core item', 'core items'],
        'field'            => ['field', 'fields'],
        'gates'            => ['gates item', 'gates items'],
        'head_filter'      => ['head filter', 'head filters'],
        'menu_location'    => ['menu location', 'menu locations'],
        'navigation'       => ['menu', 'menus'],
        'option'           => ['option', 'options'],
        'options_page'     => ['options page', 'options pages'],
        'pattern'          => ['pattern in use', 'patterns in use'],
        'plugin'           => ['plugin', 'plugins'],
        'post_type'        => ['post type', 'post types'],
        'rest_route'       => ['REST route', 'REST routes'],
        'route'            => ['route', 'routes'],
        'sidebar'          => ['sidebar', 'sidebars'],
        'taxonomy'         => ['taxonomy', 'taxonomies'],
        'template'         => ['template', 'templates'],
        'template_part'    => ['part', 'parts'],
    ];

    private int $budget;

    /**
     * @param int $budget Characters. PHP_INT_MAX renders the untruncated index.
     */
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
        $header = Compact::header(self::TIER, $manifest);
        $where = self::positions($manifest);

        $listed = [];
        $kinds = [];
        foreach ($visibility->listed as ['item' => $item]) {
            $listed[$item->id] = true;
            $kinds[$item->kind] = true;
        }

        [$collapsed, $noPosts] = self::noPosts($visibility, $listed, $where);
        $rows = self::ranked($visibility, $collapsed);

        $closing = [...$visibility->folds, ...$noPosts];
        if (isset($kinds['binding'])) {
            $closing[] = self::BINDING_KEYS_LINE;
        }

        $blocks = [];
        foreach ([[true, true], [false, true], [false, false]] as [$refs, $fieldLists]) {
            $blocks = [];
            $sources = [];
            foreach ($rows as ['item' => $item]) {
                // A source's unread settings are named once, on its first binding's line.
                $source = $item->kind === 'binding' && is_string($item->facts['source'] ?? null) ? $item->facts['source'] : null;
                $unread = $source === null || !isset($sources[$source]);
                if ($source !== null) {
                    $sources[$source] = true;
                }

                // A post type with no posts has its fields on a closing line, so its own line only counts them.
                $blocks[] = self::block($item, $notes[$item->id] ?? [], $refs, $fieldLists && !isset($collapsed[$item->id]), $listed, $unread);
            }

            $text = self::join($header, $blocks, $closing);

            if (Compact::length($text) <= $this->budget) {
                return $text;
            }
        }

        return $this->truncate($header, $blocks, $rows, $closing, $where);
    }

    /**
     * Keeps the most items that fit with the closing lines and one
     * `+N more <noun> (<where>)` line per rank, kind and section that lost
     * items, so patterns in use and unused patterns are counted apart.
     *
     * @param list<string>                                            $blocks  One per row, refs and field lists already dropped.
     * @param list<array{item: Item, section: string, rank: int}>     $rows    In index order.
     * @param list<string>                                            $closing Fold lines, the no-posts lines and the binding keys line.
     * @param array<string, array{section: string, page: int, pages: int}> $where
     */
    private function truncate(string $header, array $blocks, array $rows, array $closing, array $where): string
    {
        // Characters used by the header and the first $keep blocks, each with its newline.
        $used = [Compact::length($header) + 1];
        foreach ($blocks as $i => $block) {
            $used[$i + 1] = $used[$i] + Compact::length($block) + 1;
        }

        $keep = count($blocks);

        /** @var array<string, array{rank: int, kind: string, section: string, count: int, pages: array<int, true>, of: int}> $dropped */
        $dropped = [];

        // Drop items from the end until the rest fits. With none left, the
        // header and the closing lines go out whatever their size.
        while (true) {
            $tail = [...$closing, ...self::droppedLines($dropped)];

            $total = $used[$keep];
            foreach ($tail as $line) {
                $total += Compact::length($line) + 1;
            }

            if ($total <= $this->budget || $keep === 0) {
                return self::join($header, array_slice($blocks, 0, $keep), $tail);
            }

            $keep--;
            ['item' => $item, 'section' => $section, 'rank' => $rank] = $rows[$keep];
            $key = $rank . ' ' . $item->kind . ' ' . $section;
            $dropped[$key] ??= ['rank' => $rank, 'kind' => $item->kind, 'section' => $section, 'count' => 0, 'pages' => [], 'of' => $where[$item->id]['pages'] ?? 1];
            $dropped[$key]['count']++;
            $dropped[$key]['pages'][$where[$item->id]['page'] ?? 1] = true;
        }
    }

    /**
     * One line per rank, kind and section that lost items, in index order.
     *
     * @param array<string, array{rank: int, kind: string, section: string, count: int, pages: array<int, true>, of: int}> $dropped
     * @return list<string>
     */
    private static function droppedLines(array $dropped): array
    {
        usort($dropped, static fn (array $a, array $b): int => [$a['rank'], $a['kind'], $a['section']] <=> [$b['rank'], $b['kind'], $b['section']]);

        $lines = [];
        foreach ($dropped as $group) {
            $nouns = $group['kind'] === 'pattern' && $group['rank'] === self::UNUSED_PATTERN_RANK
                ? self::UNUSED_PATTERN_NOUNS
                : (self::NOUNS[$group['kind']] ?? ['item', 'items']);
            $lines[] = sprintf(self::DROPPED_LINE, $group['count'], $nouns[$group['count'] === 1 ? 0 : 1], self::fetch($group['section'], array_keys($group['pages']), $group['of']));
        }

        return $lines;
    }

    /**
     * The section tier call that returns items: `section layout` when the
     * section is one page, else `section layout, pages 2-4, 6`.
     *
     * @param list<int> $pages
     */
    public static function fetch(string $section, array $pages, int $of): string
    {
        if ($of <= 1 || $pages === []) {
            return 'section ' . $section;
        }

        $pages = array_values(array_unique($pages));
        sort($pages, SORT_NUMERIC);

        $runs = [];
        $start = $pages[0];
        $previous = $start;
        foreach (array_slice($pages, 1) as $page) {
            if ($page !== $previous + 1) {
                $runs[] = $start === $previous ? (string) $start : $start . '-' . $previous;
                $start = $page;
            }

            $previous = $page;
        }
        $runs[] = $start === $previous ? (string) $start : $start . '-' . $previous;

        return 'section ' . $section . ', ' . (count($pages) === 1 ? 'page ' : 'pages ') . implode(', ', $runs);
    }

    /**
     * Every item's section and the section tier page that holds it.
     *
     * @return array<string, array{section: string, page: int, pages: int}>
     */
    private static function positions(Manifest $manifest): array
    {
        $where = [];

        foreach ($manifest->sections as $section) {
            $pages = SectionRenderer::pageCount(count($section->items));

            foreach ($section->items as $position => $item) {
                $where[$item->id] = ['section' => $section->name, 'page' => SectionRenderer::pageOf($position), 'pages' => $pages];
            }
        }

        return $where;
    }

    /**
     * The fields of listed post types with no posts, which the index does not
     * list one by one, and the closing line for each such post type, by id.
     *
     * @param array<string, true>                                         $listed
     * @param array<string, array{section: string, page: int, pages: int}> $where
     * @return array{0: array<string, true>, 1: list<string>} Collapsed field and post type ids, and the lines.
     */
    private static function noPosts(Visibility $visibility, array $listed, array $where): array
    {
        $collapsed = [];
        $lines = [];

        foreach ($visibility->listed as ['item' => $item]) {
            if ($item->kind !== 'post_type' || !self::hasNoPosts($item)) {
                continue;
            }

            $fields = [];
            foreach (Facts::fieldKeys($item) as $key) {
                $id = 'field:' . substr($item->id, 3) . '.' . $key;

                if (isset($listed[$id], $where[$id])) {
                    $fields[] = $id;
                }
            }

            if ($fields === []) {
                continue;
            }

            $collapsed[$item->id] = true;
            $pages = [];
            foreach ($fields as $id) {
                $collapsed[$id] = true;
                $pages[$where[$id]['page']] = true;
            }

            $lines[] = sprintf(self::NO_POSTS_LINE, count($fields), $item->id, self::fetch($where[$fields[0]]['section'], array_keys($pages), $where[$fields[0]]['pages']));
        }

        return [$collapsed, $lines];
    }

    /**
     * No published, draft or private posts (uploads too, for media). A post
     * type with no counts at all is not known to be empty.
     */
    private static function hasNoPosts(Item $item): bool
    {
        $counts = $item->stats['counts'] ?? null;

        return is_array($counts) && $counts !== [] && array_sum(array_filter($counts, 'is_int')) === 0;
    }

    /**
     * The listed items the index has a line for, in index order: by rank,
     * then by id. A collapsed post type keeps its line; its fields do not.
     *
     * @param array<string, true> $collapsed
     * @return list<array{item: Item, section: string, rank: int}>
     */
    private static function ranked(Visibility $visibility, array $collapsed): array
    {
        $rows = [];

        foreach ($visibility->listed as ['item' => $item, 'section' => $section]) {
            if ($item->kind === 'field' && isset($collapsed[$item->id])) {
                continue;
            }

            $rows[] = ['item' => $item, 'section' => $section, 'rank' => self::rank($item)];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['rank'], $a['item']->id] <=> [$b['rank'], $b['item']->id]);

        return $rows;
    }

    public static function rank(Item $item): int
    {
        if ($item->kind === 'pattern' && Facts::usedInParts($item) === []) {
            return self::UNUSED_PATTERN_RANK;
        }

        return self::RANKS[$item->kind] ?? self::OTHER_RANK;
    }

    /**
     * One item line and its notes.
     *
     * @param list<array{body: string, status: string}> $notes
     * @param array<string, true>                       $listed Every id the index has a line for.
     */
    private static function block(Item $item, array $notes, bool $refs, bool $fieldLists, array $listed, bool $unread = true): string
    {
        $lines = [Compact::line([
            $item->id,
            $item->label,
            Facts::describe($item, $fieldLists, $listed, $unread),
            $item->edit,
            $refs ? implode(',', $item->refs) : null,
        ])];

        foreach ($notes as $note) {
            $body = Compact::clean($note['body']);

            if (mb_strlen($body, 'UTF-8') > self::NOTE_CHARS) {
                $body = mb_substr($body, 0, self::NOTE_CHARS - 3, 'UTF-8') . '...';
            }

            $lines[] = '  note: ' . $body . ($note['status'] === AgencyNotes::NEEDS_REVIEW ? ' (needs review)' : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $blocks
     * @param list<string> $tail
     */
    private static function join(string $header, array $blocks, array $tail): string
    {
        return implode("\n", [$header, ...$blocks, ...$tail]) . "\n";
    }
}
