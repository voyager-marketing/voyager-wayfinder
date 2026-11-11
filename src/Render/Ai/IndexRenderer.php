<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;

/**
 * The index: one compact line per item, sorted by id, with agency notes
 * indented under their item. About 4,000 tokens on the fixture.
 *
 * It must fit 16,000 characters. On overflow it gives up detail in a fixed
 * order, keeping as much as fits at each step: first the refs column, then
 * post type field lists (collapsed to counts), then whole items from the end,
 * each dropped run summarised as `+N more in section <name>`.
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
        $header = Compact::header(self::TIER, $manifest);

        $listed = [];
        $kinds = [];
        foreach ($visibility->listed as ['item' => $item]) {
            $listed[$item->id] = true;
            $kinds[$item->kind] = true;
        }

        $tail = isset($kinds['binding']) ? [...$visibility->folds, self::BINDING_KEYS_LINE] : $visibility->folds;

        $blocks = [];
        foreach ([[true, true], [false, true], [false, false]] as [$refs, $fieldLists]) {
            $blocks = [];
            $sources = [];
            foreach ($visibility->listed as ['item' => $item]) {
                // A source's unread settings are named once, on its first binding's line.
                $source = $item->kind === 'binding' && is_string($item->facts['source'] ?? null) ? $item->facts['source'] : null;
                $unread = $source === null || !isset($sources[$source]);
                if ($source !== null) {
                    $sources[$source] = true;
                }

                $blocks[] = self::block($item, $notes[$item->id] ?? [], $refs, $fieldLists, $listed, $unread);
            }

            $text = self::join($header, $blocks, $tail);

            if (Compact::length($text) <= $this->budget) {
                return $text;
            }
        }

        return $this->truncate($header, $blocks, $visibility, $tail);
    }

    /**
     * Keeps the most items that fit with the closing lines and one
     * `+N more in section <name>` line per section that lost items.
     *
     * @param list<string> $blocks  One per listed item, refs and field lists already dropped.
     * @param list<string> $closing Fold lines and the binding keys line.
     */
    private function truncate(string $header, array $blocks, Visibility $visibility, array $closing): string
    {
        $listed = $visibility->listed;

        // Characters used by the header and the first $keep blocks, each with its newline.
        $used = [Compact::length($header) + 1];
        foreach ($blocks as $i => $block) {
            $used[$i + 1] = $used[$i] + Compact::length($block) + 1;
        }

        $keep = count($blocks);
        $dropped = [];

        // Drop items from the end until the rest fits. With none left, the
        // header and the summary lines go out whatever their size.
        while (true) {
            $tail = $closing;
            $sections = $dropped;
            ksort($sections, SORT_STRING);
            foreach ($sections as $section => $count) {
                $tail[] = sprintf('+%d more in section %s', $count, $section);
            }

            $total = $used[$keep];
            foreach ($tail as $line) {
                $total += Compact::length($line) + 1;
            }

            if ($total <= $this->budget || $keep === 0) {
                return self::join($header, array_slice($blocks, 0, $keep), $tail);
            }

            $keep--;
            $section = $listed[$keep]['section'];
            $dropped[$section] = ($dropped[$section] ?? 0) + 1;
        }
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
