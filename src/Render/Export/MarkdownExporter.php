<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Export;

/**
 * The handoff manual as Markdown, for a client, a new hire or the next
 * agency. It pastes into Notion as it is.
 *
 * `# <site> site manual`, the generated line, then one `##` heading per
 * section in the Wayfinder page's order (Content, Layout and Routes always,
 * Integrations when the site has any) and `## Notes` last. Each item is one
 * list line: label, kind, id, summary and the full edit URL. Its related
 * items and its notes are nested under it.
 *
 * Text is escaped where Markdown would read it as formatting. Ids print in
 * code spans, which ids can always sit in: they never carry a backtick.
 *
 * @phpstan-import-type Entry from Handoff
 * @phpstan-import-type Link from Handoff
 */
final class MarkdownExporter
{
    public const FORMAT = 'md';

    public function export(Handoff $handoff): string
    {
        $out = ['# ' . self::text($handoff->title()), '', self::text($handoff->generatedLine())];

        if (self::isAutolink($handoff->siteUrl)) {
            $out[] = '';
            $out[] = sprintf(
                /* translators: %s: site address. */
                __('Site: %s', 'voyager-wayfinder'),
                '<' . $handoff->siteUrl . '>'
            );
        }

        $out[] = '';
        $out[] = self::text(Handoff::intro());

        foreach ($handoff->sections as $section) {
            $out[] = '';
            $out[] = '## ' . self::text($section['title']);

            if ($section['description'] !== '') {
                $out[] = '';
                $out[] = self::text($section['description']);
            }

            $out[] = '';

            if ($section['entries'] === [] && $section['left_out'] === 0) {
                $out[] = self::text(Handoff::emptyLine());
                continue;
            }

            foreach ($section['entries'] as $entry) {
                array_push($out, ...self::entry($entry));
            }

            if ($section['left_out'] > 0) {
                if ($section['entries'] !== []) {
                    $out[] = '';
                }
                $out[] = self::text(Handoff::leftOutLine($section['left_out']));
            }
        }

        array_push($out, ...self::notes($handoff));

        return implode("\n", $out) . "\n";
    }

    /**
     * Escapes the characters Markdown reads as formatting: backslash,
     * backtick, `*`, brackets and `<` anywhere, `_` at the edge of a word
     * (inside one, as in vwf_phone, it is never emphasis), and `#` or `>`
     * opening a line.
     */
    public static function text(string $text): string
    {
        $text = str_replace(['\\', '`', '*', '[', ']', '<'], ['\\\\', '\\`', '\\*', '\\[', '\\]', '\\<'], $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\\\_', $text);

        return (string) preg_replace('/^([ \t]*)([#>])/m', '$1\\\\$2', $text);
    }

    /**
     * @param Entry $entry
     * @return list<string>
     */
    private static function entry(array $entry): array
    {
        $line = '- **' . self::text($entry['label']) . '** (' . self::text($entry['kind']) . ', `' . $entry['id'] . '`)';
        $line .= $entry['summary'] !== '' ? ': ' . self::text(Handoff::sentence($entry['summary'])) : '.';

        if ($entry['edit_url'] !== null) {
            $line .= ' ' . sprintf(
                /* translators: %s: edit URL. */
                __('Edit: %s', 'voyager-wayfinder'),
                self::isAutolink($entry['edit_url']) ? '<' . $entry['edit_url'] . '>' : self::text($entry['edit_url'])
            );
        } else {
            $line .= ' ' . self::text($entry['edit_text']);
        }

        $lines = [$line];

        foreach ([[__('Related', 'voyager-wayfinder'), $entry['related']], [__('Used by', 'voyager-wayfinder'), $entry['used_by']]] as [$title, $links]) {
            if ($links !== []) {
                $lines[] = '  - ' . self::text($title) . ': ' . implode(', ', array_map(self::link(...), $links)) . '.';
            }
        }

        foreach ($entry['notes'] as $note) {
            $lines[] = '  - ' . self::text(Handoff::noteLabel($note['status'])) . ': ' . self::block($note['body'], '    ');
        }

        return $lines;
    }

    /**
     * @param Link $link
     */
    private static function link(array $link): string
    {
        return $link['label'] === $link['id']
            ? '`' . $link['id'] . '`'
            : self::text($link['label']) . ' (`' . $link['id'] . '`)';
    }

    /**
     * @return list<string>
     */
    private static function notes(Handoff $handoff): array
    {
        $out = ['', '## ' . self::text(Handoff::notesTitle()), '', self::text($handoff->notesLine())];

        if ($handoff->review !== []) {
            [$title, $description] = Handoff::reviewText();
            array_push($out, '', '### ' . self::text($title), '', self::text($description), '');

            foreach ($handoff->review as $note) {
                $out[] = '- **' . self::text($note['label']) . '** (`' . $note['id'] . '`): ' . self::block($note['body'], '  ');
            }
        }

        if ($handoff->orphans !== []) {
            [$title, $description] = Handoff::orphansText();
            array_push($out, '', '### ' . self::text($title), '', self::text($description), '');

            foreach ($handoff->orphans as $note) {
                $out[] = '- `' . $note['id'] . '`: ' . self::block($note['body'], '  ');
            }
        }

        return $out;
    }

    /**
     * A note body inside a list item: escaped, its later lines indented to
     * stay in the item, runs of blank lines kept to one. Each line is
     * trimmed first, since a line indented past the item would turn into a
     * code block.
     */
    private static function block(string $body, string $indent): string
    {
        $lines = preg_split('/\R/u', trim($body)) ?: [];
        $out = [];
        $blank = false;

        foreach ($lines as $line) {
            $line = self::text(trim($line));

            if ($line === '') {
                if (!$blank && $out !== []) {
                    $out[] = '';
                }
                $blank = true;
                continue;
            }

            $out[] = ($out === [] ? '' : $indent) . $line;
            $blank = false;
        }

        return implode("\n", $out);
    }

    /**
     * Whether a URL can sit in `<...>` as a Markdown autolink.
     */
    private static function isAutolink(string $url): bool
    {
        return preg_match('#^https?://[^\s<>]+$#', $url) === 1;
    }
}
