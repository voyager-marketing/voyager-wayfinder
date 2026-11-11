<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Export;

use Voyager\Wayfinder\Notes\NoteStatus;
use Voyager\Wayfinder\Render\Human\ManualView;

/**
 * The handoff manual as one self-contained HTML file, made to be printed or
 * saved as a PDF from the browser. The same content as the Markdown export,
 * in the same order.
 *
 * No scripts, no external stylesheets, fonts or images: the CSS is inline.
 * Colors and fonts are CSS custom properties on `:root` with neutral
 * defaults, so an agency can brand a copy by overriding a handful of
 * variables. Every value is escaped here.
 *
 * Item cards carry the Wayfinder page's anchors (`vwf-item-<id>`), so related
 * items link within the file. An item the file does not list is named, not
 * linked.
 *
 * @phpstan-import-type Entry from Handoff
 * @phpstan-import-type Link from Handoff
 */
final class HtmlExporter
{
    public const FORMAT = 'html';

    public const CSS = <<<'CSS'
:root {
	--vwf-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	--vwf-font-heading: var(--vwf-font);
	--vwf-font-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
	--vwf-color-text: #1e1e1e;
	--vwf-color-muted: #5c5c5c;
	--vwf-color-accent: #2f5d8a;
	--vwf-color-border: #d9d9d9;
	--vwf-color-surface: #f4f4f4;
	--vwf-color-review: #8a5300;
	--vwf-color-page: #ffffff;
}

* {
	box-sizing: border-box;
}

body {
	margin: 0;
	background: var(--vwf-color-page);
	color: var(--vwf-color-text);
	font: 16px/1.55 var(--vwf-font);
}

.vwf-doc {
	max-width: 52rem;
	margin: 0 auto;
	padding: 2.5rem 1.25rem 4rem;
}

h1, h2, h3 {
	font-family: var(--vwf-font-heading);
	line-height: 1.25;
}

h1 {
	font-size: 2rem;
	margin: 0 0 0.75rem;
}

h2 {
	font-size: 1.4rem;
	margin: 2.5rem 0 0.5rem;
	padding-bottom: 0.3rem;
	border-bottom: 2px solid var(--vwf-color-text);
}

h3 {
	font-size: 1.05rem;
	margin: 0 0 0.2rem;
}

p {
	margin: 0.35rem 0;
}

a {
	color: var(--vwf-color-accent);
	overflow-wrap: anywhere;
}

code {
	font-family: var(--vwf-font-mono);
	font-size: 0.85em;
	background: var(--vwf-color-surface);
	padding: 0.05em 0.3em;
	border-radius: 3px;
	overflow-wrap: anywhere;
}

.vwf-meta,
.vwf-description,
.vwf-left-out,
.vwf-id,
.vwf-empty {
	color: var(--vwf-color-muted);
}

.vwf-toc ol {
	columns: 2;
	margin: 0.5rem 0 0;
	padding-left: 1.25rem;
}

.vwf-toc h2 {
	font-size: 1rem;
	margin-top: 1.5rem;
	border: 0;
}

.vwf-item {
	padding: 0.9rem 0;
	border-bottom: 1px solid var(--vwf-color-border);
}

.vwf-kind {
	margin-left: 0.4em;
}

.vwf-edit-label,
.vwf-links-label {
	font-weight: 600;
}

.vwf-notes {
	list-style: none;
	margin: 0.6rem 0 0;
	padding: 0.5rem 0.75rem;
	border-left: 3px solid var(--vwf-color-accent);
	background: var(--vwf-color-surface);
}

.vwf-notes li + li {
	margin-top: 0.5rem;
}

.vwf-badge {
	display: inline-block;
	margin-right: 0.4em;
	padding: 0 0.35em;
	border: 1px solid currentColor;
	border-radius: 3px;
	color: var(--vwf-color-review);
	font-size: 0.75rem;
	font-weight: 600;
}

.vwf-left-out {
	margin-top: 0.75rem;
	font-style: italic;
}

@media print {
	@page {
		margin: 16mm 14mm;
	}

	body {
		font-size: 10.5pt;
	}

	.vwf-doc {
		max-width: none;
		padding: 0;
	}

	a {
		color: inherit;
		text-decoration: none;
	}

	.vwf-edit a {
		text-decoration: underline;
	}

	h2,
	h3 {
		break-after: avoid;
		page-break-after: avoid;
	}

	.vwf-item,
	.vwf-notes li {
		break-inside: avoid;
		page-break-inside: avoid;
	}

	code,
	.vwf-notes {
		background: none;
	}

	code {
		padding: 0;
	}
}
CSS;

    public function export(Handoff $handoff): string
    {
        $title = $handoff->title();

        $html = "<!DOCTYPE html>\n"
            . '<html lang="' . esc_attr($handoff->language) . "\">\n<head>\n"
            . "<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . '<title>' . esc_html($title) . "</title>\n"
            . "<style>\n" . self::CSS . "\n</style>\n</head>\n<body>\n<div class=\"vwf-doc\">\n";

        $html .= "<header>\n<h1>" . esc_html($title) . "</h1>\n"
            . '<p class="vwf-meta">' . esc_html($handoff->generatedLine()) . "</p>\n";

        if (preg_match('#^https?://#', $handoff->siteUrl) === 1) {
            $html .= '<p class="vwf-meta">' . esc_html__('Site:', 'voyager-wayfinder') . ' <a href="' . esc_url($handoff->siteUrl) . '">'
                . esc_html($handoff->siteUrl) . "</a></p>\n";
        }

        $html .= '<p>' . esc_html(Handoff::intro()) . "</p>\n</header>\n";
        $html .= self::toc($handoff);

        foreach ($handoff->sections as $section) {
            $anchor = 'vwf-section-' . $section['name'];

            $html .= "\n" . '<section id="' . esc_attr($anchor) . '">' . "\n"
                . '<h2>' . esc_html($section['title']) . "</h2>\n";

            if ($section['description'] !== '') {
                $html .= '<p class="vwf-description">' . esc_html($section['description']) . "</p>\n";
            }

            if ($section['entries'] === [] && $section['left_out'] === 0) {
                $html .= '<p class="vwf-empty">' . esc_html(Handoff::emptyLine()) . "</p>\n";
            }

            foreach ($section['entries'] as $entry) {
                $html .= self::entry($entry);
            }

            if ($section['left_out'] > 0) {
                $html .= '<p class="vwf-left-out">' . esc_html(Handoff::leftOutLine($section['left_out'])) . "</p>\n";
            }

            $html .= "</section>\n";
        }

        $html .= self::notes($handoff);

        return $html . "</div>\n</body>\n</html>\n";
    }

    private static function toc(Handoff $handoff): string
    {
        $html = '<nav class="vwf-toc" aria-labelledby="vwf-toc-title"><h2 id="vwf-toc-title">' . esc_html__('Contents', 'voyager-wayfinder') . "</h2>\n<ol>\n";

        foreach ($handoff->sections as $section) {
            $html .= '<li><a href="#' . esc_attr('vwf-section-' . $section['name']) . '">' . esc_html($section['title']) . "</a></li>\n";
        }

        $html .= '<li><a href="#' . esc_attr(ManualView::NOTES_ANCHOR) . '">' . esc_html(Handoff::notesTitle()) . "</a></li>\n";

        return $html . "</ol>\n</nav>\n";
    }

    /**
     * @param Entry $entry
     */
    private static function entry(array $entry): string
    {
        $html = '<article class="vwf-item" id="' . esc_attr(ManualView::anchor($entry['id'])) . '">'
            . '<h3>' . esc_html($entry['label']) . '</h3>'
            . '<p class="vwf-id"><code>' . esc_html($entry['id']) . '</code><span class="vwf-kind">' . esc_html($entry['kind']) . '</span></p>';

        if ($entry['summary'] !== '') {
            $html .= '<p class="vwf-summary">' . esc_html(Handoff::sentence($entry['summary'])) . '</p>';
        }

        $url = $entry['edit_url'] !== null ? esc_url($entry['edit_url']) : '';

        $html .= $url !== ''
            ? '<p class="vwf-edit"><span class="vwf-edit-label">' . esc_html__('Edit:', 'voyager-wayfinder') . '</span> <a href="' . $url . '">' . esc_html((string) $entry['edit_url']) . '</a></p>'
            : '<p class="vwf-edit">' . esc_html($entry['edit_url'] ?? $entry['edit_text']) . '</p>';

        foreach ([[__('Related', 'voyager-wayfinder'), $entry['related']], [__('Used by', 'voyager-wayfinder'), $entry['used_by']]] as [$title, $links]) {
            if ($links !== []) {
                $html .= '<p class="vwf-links"><span class="vwf-links-label">' . esc_html($title) . ':</span> '
                    . implode(', ', array_map(self::link(...), $links)) . '</p>';
            }
        }

        if ($entry['notes'] !== []) {
            $html .= '<ul class="vwf-notes">';
            foreach ($entry['notes'] as $note) {
                $html .= '<li>' . self::badge($note['status']) . self::body($note['body']) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . "</article>\n";
    }

    /**
     * @param Link $link
     */
    private static function link(array $link): string
    {
        $code = '<code>' . esc_html($link['id']) . '</code>';

        if ($link['label'] === $link['id']) {
            return $link['shown'] ? '<a href="' . esc_attr(ManualView::href($link['id'])) . '">' . $code . '</a>' : $code;
        }

        $label = esc_html($link['label']);

        return ($link['shown'] ? '<a href="' . esc_attr(ManualView::href($link['id'])) . '">' . $label . '</a>' : $label) . ' ' . $code;
    }

    private static function notes(Handoff $handoff): string
    {
        $html = "\n" . '<section id="' . esc_attr(ManualView::NOTES_ANCHOR) . '">' . "\n"
            . '<h2>' . esc_html(Handoff::notesTitle()) . "</h2>\n"
            . '<p>' . esc_html($handoff->notesLine()) . "</p>\n";

        if ($handoff->review !== []) {
            [$title, $description] = Handoff::reviewText();
            $html .= '<h3>' . esc_html($title) . '</h3><p class="vwf-description">' . esc_html($description) . '</p><ul class="vwf-notes">';

            foreach ($handoff->review as $note) {
                $html .= '<li><a href="' . esc_attr(ManualView::href($note['id'])) . '">' . esc_html($note['label']) . '</a> <code>'
                    . esc_html($note['id']) . '</code>: ' . self::body($note['body']) . '</li>';
            }

            $html .= "</ul>\n";
        }

        if ($handoff->orphans !== []) {
            [$title, $description] = Handoff::orphansText();
            $html .= '<h3>' . esc_html($title) . '</h3><p class="vwf-description">' . esc_html($description) . '</p><ul class="vwf-notes">';

            foreach ($handoff->orphans as $note) {
                $html .= '<li><code>' . esc_html($note['id']) . '</code>: ' . self::body($note['body']) . '</li>';
            }

            $html .= "</ul>\n";
        }

        return $html . "</section>\n";
    }

    private static function badge(string $status): string
    {
        return match ($status) {
            NoteStatus::NEEDS_REVIEW => '<span class="vwf-badge">' . esc_html__('Needs review', 'voyager-wayfinder') . '</span>',
            NoteStatus::ORPHANED     => '<span class="vwf-badge">' . esc_html__('Orphaned', 'voyager-wayfinder') . '</span>',
            default                  => '',
        };
    }

    private static function body(string $body): string
    {
        return nl2br(esc_html(trim($body)), false);
    }
}
