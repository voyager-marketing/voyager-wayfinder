<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Human;

use Closure;
use Voyager\Wayfinder\Abilities\Abilities;
use Voyager\Wayfinder\Admin\ManualPage;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Notes\Note;
use Voyager\Wayfinder\Notes\NoteStatus;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Render\Ai\Visibility;
use Voyager\Wayfinder\Render\Export\HtmlExporter;
use Voyager\Wayfinder\Render\Export\MarkdownExporter;
use Voyager\Wayfinder\Scanners\Builders;

/**
 * The Wayfinder page as HTML: a header, the section navigation, one card per
 * manifest item with its agency notes, the notes that need review, and the
 * settings.
 *
 * Core admin markup and classes, plus the `vwf-` classes in
 * assets/manual.css. Every value is escaped here, so the caller echoes the
 * result as it is. Nothing but WordPress's escaping and translation functions
 * is called, so the unit suite renders it from a fixture manifest.
 *
 * Every item gets a card, `data-vwf-item="<id>"` on the card and nowhere
 * else. Items the AI tiers fold (parent theme files and registered patterns
 * nothing on the site uses) sit in a closed `<details>` at the end of their
 * section, so they are still there to search and to link to.
 */
final class ManualView
{
    /** Sections in page order. A section a filter adds follows them, by name. */
    public const ORDER = ['site', 'content_model', 'layout', 'routes', 'code', 'integrations'];

    /** A card's HTML id is this plus the item id. */
    public const ANCHOR_PREFIX = 'vwf-item-';

    public const NOTES_ANCHOR = 'vwf-notes';

    public const SETTINGS_ANCHOR = 'vwf-settings';

    /** @var Closure(string): string */
    private Closure $formatTime;

    /** @var Closure(string): bool */
    private Closure $canOpen;

    private ?ClientAccess $client;

    /**
     * @param (callable(string): string)|null $formatTime Prints a note's UTC `Y-m-d H:i:s` time. Defaults to the value as stored.
     * @param (callable(string): bool)|null   $canOpen    Whether the reader can open an edit path. A path they cannot open is shown, not linked. Defaults to yes.
     * @param ClientAccess|null               $client     Adds "Client can edit" to each card for this client role. Defaults to no line.
     */
    public function __construct(?callable $formatTime = null, ?callable $canOpen = null, ?ClientAccess $client = null)
    {
        $this->client = $client;
        $this->formatTime = $formatTime !== null
            ? Closure::fromCallable($formatTime)
            : static fn (string $time): string => $time;
        $this->canOpen = $canOpen !== null
            ? Closure::fromCallable($canOpen)
            : static fn (string $edit): bool => true;
    }

    /**
     * @param list<Note> $notes Every note, whatever its item.
     */
    public function render(Manifest $manifest, array $notes, PageState $state): string
    {
        /** @var array<string, Item> $items */
        $items = [];
        foreach ($manifest->sections as $section) {
            foreach ($section->items as $item) {
                $items[$item->id] = $item;
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

        /** @var array<string, list<Note>> $notesByItem */
        $notesByItem = [];
        $review = [];
        $orphans = [];
        foreach ($notes as $note) {
            if (!isset($items[$note->itemId])) {
                $orphans[] = $note;
                continue;
            }

            $notesByItem[$note->itemId][] = $note;

            if ($note->status === NoteStatus::NEEDS_REVIEW) {
                $review[] = $note;
            }
        }

        $listed = [];
        foreach (Visibility::of($manifest)->listed as ['item' => $item]) {
            $listed[$item->id] = true;
        }

        $sections = self::ordered($manifest);

        $html = '<div class="wrap vwf-manual">';
        $html .= '<h1>' . esc_html__('Voyager Wayfinder', 'voyager-wayfinder') . '</h1>';
        $html .= self::notices($state);
        $html .= self::header($manifest, $state, count($notes), count($review), count($orphans));
        $html .= self::nav($sections, count($review) + count($orphans), $state->canWrite);
        $html .= self::search();

        foreach ($sections as $section) {
            $html .= $this->section($section, $listed, $items, $usedBy, $notesByItem, $state);
        }

        $html .= $this->reviewSection($review, $orphans, $items, $state);

        if ($state->canWrite) {
            $html .= self::settings($state);
        }

        return $html . '</div>';
    }

    /**
     * The HTML id of an item's card.
     */
    public static function anchor(string $id): string
    {
        return self::ANCHOR_PREFIX . $id;
    }

    /**
     * A link to an item's card. Ids carry `:`, `/` and `.`, so the fragment
     * is percent-encoded; browsers decode it before matching the id.
     */
    public static function href(string $id): string
    {
        return '#' . rawurlencode(self::anchor($id));
    }

    /**
     * An edit path as a link target: relative paths join the wp-admin URL,
     * and a path from the site root (a page builder's front-end editor,
     * `/about/?bricks=run`) joins the wp-admin URL's scheme and host. A Site
     * Editor trail (words and `>`) is not a URL, so it has none.
     */
    public static function editUrl(?string $edit, string $adminUrl): ?string
    {
        if ($edit === null || $edit === '' || preg_match('/[\s>]/', $edit) === 1) {
            return null;
        }

        if (preg_match('#^https?://#', $edit) === 1) {
            return $edit;
        }

        if (Builders::isSitePath($edit) && preg_match('#^(https?://[^/]+)#', $adminUrl, $origin) === 1) {
            return $origin[1] . $edit;
        }

        return rtrim($adminUrl, '/') . '/' . ltrim($edit, '/');
    }

    /**
     * Sections in page order, empty ones left out.
     *
     * @return list<Section>
     */
    private static function ordered(Manifest $manifest): array
    {
        $byName = [];
        foreach ($manifest->sections as $section) {
            if ($section->items !== []) {
                $byName[$section->name] = $section;
            }
        }

        $ordered = [];
        foreach (self::ORDER as $name) {
            if (isset($byName[$name])) {
                $ordered[] = $byName[$name];
                unset($byName[$name]);
            }
        }

        ksort($byName, SORT_STRING);

        return [...$ordered, ...array_values($byName)];
    }

    /**
     * The handoff exports print the same titles and lines.
     *
     * @return array{0: string, 1: string} Title and one line on what the section holds.
     */
    public static function sectionText(string $name): array
    {
        return match ($name) {
            'site'          => [__('Site', 'voyager-wayfinder'), __('Site settings, the theme, the editor controls it turns off and the WordPress version.', 'voyager-wayfinder')],
            'content_model' => [__('Content', 'voyager-wayfinder'), __('Post types, taxonomies and the fields each one carries.', 'voyager-wayfinder')],
            'layout'        => [__('Layout', 'voyager-wayfinder'), __('Templates, template parts, patterns, menus and block bindings.', 'voyager-wayfinder')],
            'routes'        => [__('Routes', 'voyager-wayfinder'), __('Which template renders each kind of URL, and what it pulls in.', 'voyager-wayfinder')],
            'code'          => [__('Code', 'voyager-wayfinder'), __('REST routes and head filters the theme and plugins add, with the file that holds each one. Read from the code, never run.', 'voyager-wayfinder')],
            'integrations'  => [__('Integrations', 'voyager-wayfinder'), __('Active plugins, grouped by the job they do.', 'voyager-wayfinder')],
            default         => [ucfirst(str_replace('_', ' ', $name)), ''],
        };
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'post_type'     => __('Post type', 'voyager-wayfinder'),
            'taxonomy'      => __('Taxonomy', 'voyager-wayfinder'),
            'field'         => __('Field', 'voyager-wayfinder'),
            'template'      => __('Template', 'voyager-wayfinder'),
            'template_part' => __('Template part', 'voyager-wayfinder'),
            'pattern'       => __('Pattern', 'voyager-wayfinder'),
            'navigation'    => __('Menu', 'voyager-wayfinder'),
            'menu_location' => __('Menu location', 'voyager-wayfinder'),
            'sidebar'       => __('Sidebar', 'voyager-wayfinder'),
            'builder_template' => __('Builder template', 'voyager-wayfinder'),
            'options_page'  => __('Options page', 'voyager-wayfinder'),
            'binding'       => __('Block binding', 'voyager-wayfinder'),
            'route'         => __('Route', 'voyager-wayfinder'),
            'rest_route'    => __('REST route', 'voyager-wayfinder'),
            'head_filter'   => __('Head filter', 'voyager-wayfinder'),
            'plugin'        => __('Plugin', 'voyager-wayfinder'),
            'option'        => __('Setting', 'voyager-wayfinder'),
            'core'          => __('WordPress', 'voyager-wayfinder'),
            'gates'         => __('Theme gates', 'voyager-wayfinder'),
            default         => ucfirst(str_replace('_', ' ', $kind)),
        };
    }

    private static function notices(PageState $state): string
    {
        $html = '';

        foreach ($state->notices as $notice) {
            $type = in_array($notice['type'], ['success', 'info', 'warning', 'error'], true) ? $notice['type'] : 'info';
            $html .= '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($notice['text']) . '</p></div>';
        }

        return $html;
    }

    private static function header(Manifest $manifest, PageState $state, int $notes, int $review, int $orphaned): string
    {
        $html = '<div class="vwf-header">';
        $html .= '<dl class="vwf-meta">';
        $html .= '<div><dt>' . esc_html__('Site', 'voyager-wayfinder') . '</dt><dd>' . esc_html($state->siteName) . '</dd></div>';
        $html .= '<div><dt>' . esc_html__('Last scan', 'voyager-wayfinder') . '</dt><dd>'
            . esc_html($state->lastScan !== '' ? $state->lastScan : __('Not scanned yet', 'voyager-wayfinder')) . '</dd></div>';
        $html .= '<div><dt>' . esc_html__('Manifest', 'voyager-wayfinder') . '</dt><dd><code title="' . esc_attr($manifest->hash) . '">'
            . esc_html(substr($manifest->hash, 0, 6)) . '</code></dd></div>';
        $html .= '<div><dt>' . esc_html__('Notes', 'voyager-wayfinder') . '</dt><dd>' . esc_html((string) $notes)
            . self::noteBadges($review, $orphaned) . '</dd></div>';
        $html .= '</dl>';

        $html .= '<div class="vwf-actions">';

        if ($state->canWrite) {
            $html .= '<form method="post" action="' . esc_url(self::editUrl('admin-post.php', $state->adminUrl) ?? '') . '" class="vwf-rescan">'
                . '<input type="hidden" name="action" value="' . esc_attr(ManualPage::RESCAN_ACTION) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . esc_attr($state->rescanNonce) . '">'
                . '<button type="submit" class="button button-primary">' . esc_html__('Rescan', 'voyager-wayfinder') . '</button>'
                . '</form>';
            $html .= self::export($state);
        }

        $html .= '<button type="button" class="button" data-vwf-copy-brief aria-describedby="vwf-copy-status">'
            . esc_html__('Copy AI brief', 'voyager-wayfinder') . '</button>';
        $html .= '<span id="vwf-copy-status" class="vwf-status" role="status" aria-live="polite"></span>';
        $html .= '<label class="screen-reader-text" for="vwf-brief">' . esc_html__('AI brief', 'voyager-wayfinder') . '</label>';
        $html .= '<textarea id="vwf-brief" class="vwf-brief large-text code" rows="12" readonly hidden>' . esc_textarea($state->brief) . '</textarea>';
        $html .= '</div>';

        return $html . '</div>';
    }

    /**
     * The handoff export: a format and a button that downloads the file
     * `wp wayfinder export` prints.
     */
    private static function export(PageState $state): string
    {
        return '<form method="post" action="' . esc_url(self::editUrl('admin-post.php', $state->adminUrl) ?? '') . '" class="vwf-export">'
            . '<input type="hidden" name="action" value="' . esc_attr(ManualPage::EXPORT_ACTION) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr($state->exportNonce) . '">'
            . '<label for="vwf-export-format">' . esc_html__('Export as', 'voyager-wayfinder') . '</label> '
            . '<select id="vwf-export-format" name="format">'
            . '<option value="' . esc_attr(MarkdownExporter::FORMAT) . '">' . esc_html__('Markdown', 'voyager-wayfinder') . '</option>'
            . '<option value="' . esc_attr(HtmlExporter::FORMAT) . '">' . esc_html__('HTML to print', 'voyager-wayfinder') . '</option>'
            . '</select> '
            . '<button type="submit" class="button">' . esc_html__('Export', 'voyager-wayfinder') . '</button>'
            . '</form>';
    }

    /**
     * Badges for the notes that need attention, linking to the notes section.
     */
    private static function noteBadges(int $review, int $orphaned): string
    {
        $html = '';

        if ($review > 0) {
            $html .= ' <a class="vwf-badge vwf-badge--review" href="#' . esc_attr(self::NOTES_ANCHOR) . '">' . esc_html(sprintf(
                /* translators: %d: number of notes. */
                _n('%d needs review', '%d need review', $review, 'voyager-wayfinder'),
                $review
            )) . '</a>';
        }

        if ($orphaned > 0) {
            $html .= ' <a class="vwf-badge vwf-badge--orphaned" href="#' . esc_attr(self::NOTES_ANCHOR) . '">' . esc_html(sprintf(
                /* translators: %d: number of notes. */
                _n('%d orphaned', '%d orphaned', $orphaned, 'voyager-wayfinder'),
                $orphaned
            )) . '</a>';
        }

        return $html;
    }

    /**
     * @param list<Section> $sections
     */
    private static function nav(array $sections, int $attention, bool $canWrite): string
    {
        $html = '<nav class="vwf-nav" aria-label="' . esc_attr__('Manual sections', 'voyager-wayfinder') . '"><ul>';

        foreach ($sections as $section) {
            $html .= '<li><a href="#' . esc_attr(self::sectionAnchor($section->name)) . '">'
                . esc_html(self::sectionText($section->name)[0])
                . ' <span class="count">(' . esc_html((string) count($section->items)) . ')</span></a></li>';
        }

        $html .= '<li><a href="#' . esc_attr(self::NOTES_ANCHOR) . '">' . esc_html__('Notes needing review', 'voyager-wayfinder');
        if ($attention > 0) {
            $html .= ' <span class="vwf-badge vwf-badge--review">' . esc_html((string) $attention) . '</span>';
        }
        $html .= '</a></li>';

        if ($canWrite) {
            $html .= '<li><a href="#' . esc_attr(self::SETTINGS_ANCHOR) . '">' . esc_html__('Settings', 'voyager-wayfinder') . '</a></li>';
        }

        return $html . '</ul></nav>';
    }

    /**
     * Hidden until the script runs, since only the script can filter.
     */
    private static function search(): string
    {
        return '<div class="vwf-search" role="search" data-vwf-search-box hidden>'
            . '<label for="vwf-search">' . esc_html__('Search the manual', 'voyager-wayfinder') . '</label> '
            . '<input type="search" id="vwf-search" class="regular-text" autocomplete="off" aria-describedby="vwf-search-status"'
            . ' placeholder="' . esc_attr__('Label, id or summary', 'voyager-wayfinder') . '">'
            . '<p id="vwf-search-status" class="vwf-status" role="status" aria-live="polite"></p>'
            . '</div>';
    }

    private static function sectionAnchor(string $name): string
    {
        return 'vwf-section-' . $name;
    }

    /**
     * @param array<string, true>        $listed      Ids the AI tiers list. The rest fold.
     * @param array<string, Item>        $items
     * @param array<string, list<string>> $usedBy
     * @param array<string, list<Note>>  $notesByItem
     */
    private function section(Section $section, array $listed, array $items, array $usedBy, array $notesByItem, PageState $state): string
    {
        [$title, $description] = self::sectionText($section->name);
        $anchor = self::sectionAnchor($section->name);

        $html = '<section class="vwf-section" id="' . esc_attr($anchor) . '" aria-labelledby="' . esc_attr($anchor . '-title') . '" data-vwf-section>';
        $html .= '<h2 id="' . esc_attr($anchor . '-title') . '">' . esc_html($title) . '</h2>';

        if ($description !== '') {
            $html .= '<p class="description">' . esc_html($description) . '</p>';
        }

        $shown = '';
        $folded = '';
        $foldedCount = 0;

        foreach ($section->items as $item) {
            $card = $this->card($item, $items, $usedBy[$item->id] ?? [], $notesByItem[$item->id] ?? [], $state);

            if (isset($listed[$item->id])) {
                $shown .= $card;
            } else {
                $folded .= $card;
                $foldedCount++;
            }
        }

        $html .= '<div class="vwf-cards">' . $shown . '</div>';

        if ($foldedCount > 0) {
            $html .= '<details class="vwf-folded"><summary>' . esc_html(sprintf(
                /* translators: %d: number of items. */
                _n('%d more item that nothing on this site uses', '%d more items that nothing on this site uses', $foldedCount, 'voyager-wayfinder'),
                $foldedCount
            )) . '</summary>';
            $html .= '<p class="description">' . esc_html__('Parent theme files and registered patterns that no listed template, part or published content points at.', 'voyager-wayfinder') . '</p>';
            $html .= '<div class="vwf-cards">' . $folded . '</div></details>';
        }

        $html .= '<p class="vwf-no-match description" data-vwf-no-match hidden>' . esc_html__('Nothing in this section matches the search.', 'voyager-wayfinder') . '</p>';

        return $html . '</section>';
    }

    /**
     * @param array<string, Item> $items
     * @param list<string>        $usedBy
     * @param list<Note>          $notes
     */
    private function card(Item $item, array $items, array $usedBy, array $notes, PageState $state): string
    {
        $summary = self::summary($item);
        $search = mb_strtolower($item->label . ' ' . $item->id . ' ' . $summary, 'UTF-8');

        $html = '<article class="vwf-card" id="' . esc_attr(self::anchor($item->id)) . '" data-vwf-item="' . esc_attr($item->id) . '"'
            . ' data-vwf-search="' . esc_attr($search) . '">';
        $html .= '<h3 class="vwf-card-title">' . esc_html($item->label !== '' ? $item->label : $item->id) . '</h3>';
        $html .= '<p class="vwf-card-id"><code>' . esc_html($item->id) . '</code> <span class="vwf-kind">' . esc_html(self::kindLabel($item->kind)) . '</span></p>';

        if ($summary !== '') {
            $html .= '<p class="vwf-summary">' . esc_html($summary) . '</p>';
        }

        $html .= self::facts($item, $items);
        $html .= $this->edit($item, $state);
        $html .= $this->clientCan($item);
        $html .= self::related($item, $items, $usedBy);
        $html .= $this->cardNotes($item, $notes, $state);

        return $html . '</article>';
    }

    /**
     * The scanner's own summary when it wrote one, else the key facts line
     * the index prints.
     */
    public static function summary(Item $item): string
    {
        $summary = $item->facts['summary'] ?? null;

        if (is_string($summary) && trim($summary) !== '') {
            return trim($summary);
        }

        return Facts::describe($item);
    }

    /**
     * Every fact but the summary, then the exact stats.
     *
     * @param array<string, Item> $items
     */
    private static function facts(Item $item, array $items): string
    {
        $rows = [];

        $facts = $item->facts;
        unset($facts['summary']);
        ksort($facts, SORT_STRING);

        foreach ($facts as $name => $value) {
            $rows[] = [self::factLabel((string) $name), esc_html(self::factValue($value))];
        }

        foreach (self::stats($item, $items) as $row) {
            $rows[] = $row;
        }

        if ($rows === []) {
            return '';
        }

        // Closed by default: the summary line above already says the part most readers need.
        $html = '<details class="vwf-details"><summary>' . esc_html__('Details', 'voyager-wayfinder')
            . '<span class="screen-reader-text"> ' . esc_html($item->label) . '</span></summary><dl class="vwf-facts">';
        foreach ($rows as [$label, $value]) {
            $html .= '<dt>' . esc_html($label) . '</dt><dd>' . $value . '</dd>';
        }

        return $html . '</dl></details>';
    }

    /**
     * Stats as label and escaped HTML rows. Counts are exact here: unlike
     * the AI tiers, this page is not cached by its bytes.
     *
     * @param array<string, Item> $items
     * @return list<array{0: string, 1: string}>
     */
    private static function stats(Item $item, array $items): array
    {
        $rows = [];
        $stats = $item->stats;
        ksort($stats, SORT_STRING);

        foreach ($stats as $name => $value) {
            $name = (string) $name;

            if ($name === 'counts' && is_array($value)) {
                $counts = [];
                foreach ($value as $status => $count) {
                    if (is_int($count) && $count > 0) {
                        $counts[] = $count . ' ' . $status;
                    }
                }
                $rows[] = [__('Content', 'voyager-wayfinder'), esc_html($counts === [] ? __('None', 'voyager-wayfinder') : implode(', ', $counts))];
            } elseif ($name === 'used_in' && is_array($value)) {
                // Layout containers are listed under "Used by". Content is not an item, so it is named here.
                $content = array_values(array_filter(
                    array_map('strval', $value),
                    static fn (string $use): bool => !IdFormat::isValid($use) || !isset($items[$use])
                ));
                if ($content !== []) {
                    $rows[] = [__('Used in content', 'voyager-wayfinder'), esc_html(implode(', ', $content))];
                }
            } elseif ($name === 'example' && is_array($value)) {
                $path = is_string($value['path'] ?? null) ? $value['path'] : '';
                $source = is_string($value['content_source'] ?? null) ? $value['content_source'] : '';
                $rows[] = [__('Example', 'voyager-wayfinder'), esc_html(trim($path . ($source !== '' ? ' (' . $source . ')' : '')))];
            } else {
                $rows[] = [self::factLabel($name), esc_html(self::factValue($value))];
            }
        }

        return $rows;
    }

    private static function factLabel(string $name): string
    {
        $words = explode(' ', str_replace('_', ' ', $name));
        $words = array_map(
            static fn (string $word): string => in_array($word, ['rest', 'acf', 'url', 'ui', 'id', 'sku'], true) ? strtoupper($word) : $word,
            $words
        );

        return ucfirst(implode(' ', $words));
    }

    /**
     * A fact as plain words: Yes and No, Not set, Empty, lists joined.
     */
    private static function factValue(mixed $value): string
    {
        if ($value === null) {
            return __('Not set', 'voyager-wayfinder');
        }

        if (is_bool($value)) {
            return $value ? __('Yes', 'voyager-wayfinder') : __('No', 'voyager-wayfinder');
        }

        if (is_scalar($value)) {
            $text = (string) $value;

            return $text === '' ? __('Empty', 'voyager-wayfinder') : $text;
        }

        if (is_array($value)) {
            if ($value === []) {
                return __('None', 'voyager-wayfinder');
            }

            if (array_is_list($value)) {
                return implode(', ', array_map(self::factValue(...), $value));
            }

            $pairs = [];
            foreach ($value as $key => $inner) {
                $pairs[] = $key . ': ' . self::factValue($inner);
            }

            return implode('; ', $pairs);
        }

        return (string) wp_json_encode($value);
    }

    private function edit(Item $item, PageState $state): string
    {
        $url = self::editUrl($item->edit, $state->adminUrl);

        if ($url !== null && ($this->canOpen)((string) $item->edit)) {
            return '<p class="vwf-edit"><a class="button button-small" href="' . esc_url($url) . '">'
                . esc_html__('Edit', 'voyager-wayfinder')
                . '<span class="screen-reader-text"> ' . esc_html($item->label) . '</span></a> '
                . '<code class="vwf-edit-path">' . esc_html((string) $item->edit) . '</code></p>';
        }

        if ($url !== null) {
            return '<p class="vwf-edit"><code class="vwf-edit-path">' . esc_html((string) $item->edit) . '</code> '
                . '<span class="description">' . esc_html__('Your account cannot open this screen.', 'voyager-wayfinder') . '</span></p>';
        }

        if ($item->edit !== null) {
            return '<p class="vwf-edit">' . esc_html(sprintf(
                /* translators: %s: where to edit, such as "Site Editor > Patterns > Footer". */
                __('Edit in %s', 'voyager-wayfinder'),
                $item->edit
            )) . '</p>';
        }

        return '<p class="vwf-edit vwf-edit--none description">' . esc_html(
            $item->kind === 'route'
                ? __('No edit screen of its own. Edit the template or the content it uses.', 'voyager-wayfinder')
                : __('No edit screen in wp-admin.', 'voyager-wayfinder')
        ) . '</p>';
    }

    /**
     * "Client can edit: yes / content only / no" for the configured client
     * role, from the edit path's capability and the item's `lock` fact.
     */
    private function clientCan(Item $item): string
    {
        if ($this->client === null) {
            return '';
        }

        $fact = is_array($item->facts['lock'] ?? null) ? $item->facts['lock'] : [];
        $effective = $fact['effective'] ?? 'none';
        $src = $fact['src'] ?? 'none';
        $lock = [
            'effective'     => in_array($effective, Locks::EFFECTIVE, true) ? $effective : 'none',
            'src'           => in_array($src, ['none', 'block', 'template_lock', 'post_type', 'wp7_default'], true) ? $src : 'none',
            'text_editable' => true,
        ];
        $verdict = $this->client->verdict($item->edit, $lock);

        return '<p class="vwf-client" data-vwf-client-can="' . esc_attr($verdict['client_can']) . '">'
            . esc_html(sprintf(
                /* translators: 1: yes, content only or no. 2: the reason. */
                __('Client can edit: %1$s (%2$s)', 'voyager-wayfinder'),
                match ($verdict['client_can']) {
                    'yes'     => __('yes', 'voyager-wayfinder'),
                    'partial' => __('content only', 'voyager-wayfinder'),
                    default   => __('no', 'voyager-wayfinder'),
                },
                $verdict['client_can_why']
            )) . '</p>';
    }

    /**
     * The item's refs, then the items that ref it and are not among them
     * (a post type and its fields point at each other, and one list says
     * it), each linking to its card. A ref to an item the scan does not
     * have is shown as its id.
     *
     * @param array<string, Item> $items
     * @param list<string>        $usedBy
     */
    private static function related(Item $item, array $items, array $usedBy): string
    {
        $html = '';
        $usedBy = array_values(array_diff($usedBy, $item->refs));

        foreach ([[__('Related', 'voyager-wayfinder'), $item->refs], [__('Used by', 'voyager-wayfinder'), $usedBy]] as [$title, $ids]) {
            if ($ids === []) {
                continue;
            }

            $html .= '<h4>' . esc_html($title) . '</h4><ul class="vwf-links">';

            foreach ($ids as $id) {
                $html .= '<li>' . (isset($items[$id])
                    ? '<a href="' . esc_attr(self::href($id)) . '">' . esc_html($items[$id]->label !== '' ? $items[$id]->label : $id) . '</a> <code>' . esc_html($id) . '</code>'
                    : '<code>' . esc_html($id) . '</code>') . '</li>';
            }

            $html .= '</ul>';
        }

        return $html === '' ? '' : '<div class="vwf-related">' . $html . '</div>';
    }

    /**
     * Notes under a card. Editors read them. Admins also get edit and delete
     * on each note and a form to add one.
     *
     * @param list<Note> $notes
     */
    private function cardNotes(Item $item, array $notes, PageState $state): string
    {
        if ($notes === [] && !$state->canWrite) {
            return '';
        }

        $html = '<div class="vwf-notes">';

        if ($notes !== []) {
            $html .= '<h4>' . esc_html__('Notes', 'voyager-wayfinder') . '</h4><ul class="vwf-note-list">';
            foreach ($notes as $note) {
                $html .= $this->note($note, $item->label, $state);
            }
            $html .= '</ul>';
        }

        if ($state->canWrite) {
            $field = 'vwf-new-note-' . md5($item->id);

            $html .= '<details class="vwf-note-add"><summary>' . esc_html__('Add a note', 'voyager-wayfinder')
                . '<span class="screen-reader-text"> ' . esc_html(sprintf(
                    /* translators: %s: item label. */
                    __('to %s', 'voyager-wayfinder'),
                    $item->label
                )) . '</span></summary>'
                . '<form class="vwf-note-form" data-vwf-note-add="' . esc_attr($item->id) . '">'
                . '<label for="' . esc_attr($field) . '">' . esc_html__('Note text', 'voyager-wayfinder') . '</label>'
                . '<textarea id="' . esc_attr($field) . '" name="body" class="large-text" rows="3" required></textarea>'
                . '<button type="submit" class="button button-primary">' . esc_html__('Add note', 'voyager-wayfinder') . '</button>'
                . '<span class="vwf-status" role="status" aria-live="polite"></span>'
                . '</form></details>';
        }

        return $html . '</div>';
    }

    /**
     * One note. For admins, an edit form and a delete confirmation sit
     * hidden beside it, and the script shows them.
     *
     * @param bool $orphan The item is gone: say so whatever the stored status, and name the item id.
     */
    private function note(Note $note, string $label, PageState $state, bool $orphan = false): string
    {
        $status = $orphan ? NoteStatus::ORPHANED : $note->status;

        $html = '<li class="vwf-note vwf-note--' . esc_attr($status) . '" data-vwf-note="' . esc_attr((string) $note->id) . '">';
        $html .= '<p class="vwf-note-meta">' . ($orphan ? '<code>' . esc_html($note->itemId) . '</code> ' : '') . self::statusBadge($status);

        if ($note->updatedAt !== '') {
            $html .= '<span class="vwf-note-time">' . esc_html(sprintf(
                /* translators: %s: date and time. */
                __('Updated %s', 'voyager-wayfinder'),
                ($this->formatTime)($note->updatedAt)
            )) . '</span>';
        }

        $html .= '</p><div class="vwf-note-body">' . nl2br(esc_html($note->body), false) . '</div>';

        if (!$state->canWrite) {
            return $html . '</li>';
        }

        $about = '<span class="screen-reader-text"> ' . esc_html(sprintf(
            /* translators: %s: item label. */
            __('on %s', 'voyager-wayfinder'),
            $label
        )) . '</span>';
        $form = 'vwf-note-' . $note->id . '-form';
        $field = 'vwf-note-' . $note->id . '-text';

        $html .= '<p class="vwf-note-actions">'
            . '<button type="button" class="button-link" data-vwf-action="edit" aria-expanded="false" aria-controls="' . esc_attr($form) . '">'
            . esc_html__('Edit note', 'voyager-wayfinder') . $about . '</button> '
            . '<button type="button" class="button-link button-link-delete" data-vwf-action="delete">'
            . esc_html__('Delete note', 'voyager-wayfinder') . $about . '</button></p>';

        $html .= '<form class="vwf-note-form" id="' . esc_attr($form) . '" data-vwf-note-edit="' . esc_attr((string) $note->id) . '" hidden>'
            . '<label for="' . esc_attr($field) . '">' . esc_html__('Note text', 'voyager-wayfinder') . '</label>'
            . '<textarea id="' . esc_attr($field) . '" name="body" class="large-text" rows="3" required>' . esc_textarea($note->body) . '</textarea>'
            . '<button type="submit" class="button button-primary">' . esc_html__('Save note', 'voyager-wayfinder') . '</button> '
            . '<button type="button" class="button" data-vwf-action="cancel">' . esc_html__('Cancel', 'voyager-wayfinder') . '</button>'
            . '<span class="vwf-status" role="status" aria-live="polite"></span>'
            . '</form>';

        $html .= '<div class="vwf-note-confirm" data-vwf-note-delete="' . esc_attr((string) $note->id) . '" hidden>'
            . '<p>' . esc_html__('Delete this note? This cannot be undone.', 'voyager-wayfinder') . '</p>'
            . '<button type="button" class="button button-link-delete" data-vwf-action="confirm-delete">' . esc_html__('Delete note', 'voyager-wayfinder') . '</button> '
            . '<button type="button" class="button" data-vwf-action="keep">' . esc_html__('Keep note', 'voyager-wayfinder') . '</button>'
            . '<span class="vwf-status" role="status" aria-live="polite"></span>'
            . '</div>';

        return $html . '</li>';
    }

    private static function statusBadge(string $status): string
    {
        return match ($status) {
            NoteStatus::NEEDS_REVIEW => '<span class="vwf-badge vwf-badge--review">' . esc_html__('Needs review', 'voyager-wayfinder') . '</span> ',
            NoteStatus::ORPHANED     => '<span class="vwf-badge vwf-badge--orphaned">' . esc_html__('Orphaned', 'voyager-wayfinder') . '</span> ',
            default                  => '',
        };
    }

    /**
     * Notes whose item changed since they were saved, linked to their cards,
     * and orphaned notes in full, since they have no card to sit under.
     *
     * @param list<Note>          $review
     * @param list<Note>          $orphans
     * @param array<string, Item> $items
     */
    private function reviewSection(array $review, array $orphans, array $items, PageState $state): string
    {
        $html = '<section class="vwf-section vwf-review" id="' . esc_attr(self::NOTES_ANCHOR) . '" aria-labelledby="' . esc_attr(self::NOTES_ANCHOR . '-title') . '">';
        $html .= '<h2 id="' . esc_attr(self::NOTES_ANCHOR . '-title') . '">' . esc_html__('Notes needing review', 'voyager-wayfinder') . '</h2>';
        $html .= '<p class="description">' . esc_html__('A note needs review when the item it describes has changed since the note was saved. Save the note again to mark it current. A note is orphaned when its item is no longer on the site.', 'voyager-wayfinder') . '</p>';

        if ($review === [] && $orphans === []) {
            return $html . '<p>' . esc_html__('No notes need review.', 'voyager-wayfinder') . '</p></section>';
        }

        if ($review !== []) {
            $html .= '<h3>' . esc_html__('Needs review', 'voyager-wayfinder') . '</h3><ul class="vwf-links">';
            foreach ($review as $note) {
                $item = $items[$note->itemId];
                $html .= '<li><a href="' . esc_attr(self::href($note->itemId)) . '">' . esc_html($item->label !== '' ? $item->label : $item->id) . '</a> '
                    . '<code>' . esc_html($note->itemId) . '</code>: ' . esc_html(self::excerpt($note->body)) . '</li>';
            }
            $html .= '</ul>';
        }

        if ($orphans !== []) {
            $html .= '<h3>' . esc_html__('Orphaned', 'voyager-wayfinder') . '</h3><ul class="vwf-note-list">';
            foreach ($orphans as $note) {
                $html .= $this->note($note, $note->itemId, $state, true);
            }
            $html .= '</ul>';
        }

        return $html . '</section>';
    }

    private static function excerpt(string $body): string
    {
        $body = trim((string) preg_replace('/\s+/u', ' ', $body));

        return mb_strlen($body, 'UTF-8') > 120 ? mb_substr($body, 0, 117, 'UTF-8') . '...' : $body;
    }

    /**
     * One checkbox, posted to options.php. The hidden 0 comes first, so an
     * unticked box saves 0 instead of leaving the old value.
     */
    private static function settings(PageState $state): string
    {
        $checked = $state->mcpEnabled ? ' checked' : '';

        return '<section class="vwf-section vwf-settings" id="' . esc_attr(self::SETTINGS_ANCHOR) . '" aria-labelledby="' . esc_attr(self::SETTINGS_ANCHOR . '-title') . '">'
            . '<h2 id="' . esc_attr(self::SETTINGS_ANCHOR . '-title') . '">' . esc_html__('Settings', 'voyager-wayfinder') . '</h2>'
            . '<form method="post" action="' . esc_url(self::editUrl('options.php', $state->adminUrl) ?? '') . '">'
            . '<input type="hidden" name="option_page" value="' . esc_attr(ManualPage::SETTINGS_GROUP) . '">'
            . '<input type="hidden" name="action" value="update">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr($state->settingsNonce) . '">'
            . '<input type="hidden" name="_wp_http_referer" value="' . esc_attr($state->referer) . '">'
            . '<input type="hidden" name="' . esc_attr(Abilities::MCP_OPTION) . '" value="0">'
            . '<p><label for="vwf-mcp-enabled"><input type="checkbox" id="vwf-mcp-enabled" name="' . esc_attr(Abilities::MCP_OPTION) . '" value="1"' . $checked
            . ' aria-describedby="vwf-mcp-enabled-description"> '
            . esc_html__('Share the manual with AI tools over MCP', 'voyager-wayfinder') . '</label></p>'
            . '<p class="description" id="vwf-mcp-enabled-description">' . esc_html__('When on, AI tools connected through the WordPress MCP adapter can read the brief, the index, sections and edit locations. They still sign in as a user who can edit pages. Off by default.', 'voyager-wayfinder') . '</p>'
            . '<p><button type="submit" class="button">' . esc_html__('Save settings', 'voyager-wayfinder') . '</button></p>'
            . '</form></section>';
    }
}
