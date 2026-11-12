<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\Compact;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Render\Ai\SectionRenderer;
use Voyager\Wayfinder\Render\Ai\Visibility;

/**
 * The index on a site the size of a real one: 34 templates, 33 routes, 84
 * patterns in use, 11 post types (six with no posts and 75 fields between
 * them), 15 plugins and 163 REST routes. Its untruncated index is several
 * times the budget, so it orders lines by what answers edit questions,
 * collapses the fields nobody can fill yet, and says where to fetch
 * everything it leaves out.
 */
final class IndexOrderingTest extends TestCase
{
    private const CHILD = 'acme-child';

    private const PARENT = 'acme-parent';

    /** Post types: slug => [published, fields]. The six with no posts carry 75 fields. */
    private const POST_TYPES = [
        'post'          => [319, 2],
        'page'          => [35, 3],
        'case_study'    => [8, 5],
        'service'       => [84, 3],
        'team_member'   => [6, 10],
        'location'      => [0, 15],
        'faq'           => [0, 12],
        'offer'         => [0, 7],
        'service_area'  => [0, 33],
        'testimonial'   => [0, 4],
        'event'         => [0, 4],
    ];

    /** `+12 more patterns (section layout, pages 3-4)`. */
    private const DROPPED = '/^\+(\d+) more ([a-zA-Z ]+) \(section ([a-z_]+)(?:, pages? ([\d, -]+))?\)$/';

    /** `field: +33 of pt:service_area, which has no posts (section content_model, pages 3-4)`. */
    private const NO_POSTS = '/^field: \+(\d+) of (pt:[a-z_]+), which has no posts \(section ([a-z_]+)(?:, pages? ([\d, -]+))?\)$/';

    public function testARealSizedIndexFitsItsBudgetOnlyByLeavingItemsOut(): void
    {
        $manifest = self::realSized();
        $index = (new IndexRenderer())->render($manifest);
        $full = (new IndexRenderer(PHP_INT_MAX))->render($manifest);

        $this->assertLessThanOrEqual(IndexRenderer::BUDGET, Compact::length($index));
        $this->assertGreaterThan(2 * IndexRenderer::BUDGET, Compact::length($full), 'The fixture must be big enough to overflow.');
        $this->assertNotSame([], self::dropped($index), 'It leaves items out, and says so.');
        $this->assertSame([], self::dropped($full), 'With no budget nothing is left out.');
    }

    public function testEveryTemplateAndRouteIsALineOrInsideANamedPointer(): void
    {
        $manifest = self::realSized();

        foreach ([IndexRenderer::BUDGET, 6000, 2500] as $budget) {
            $index = (new IndexRenderer($budget))->render($manifest);
            $covered = self::covered($manifest, $index);

            foreach ($manifest->ids() as $id) {
                if (str_starts_with($id, 'tpl:') || str_starts_with($id, 'route:')) {
                    $this->assertArrayHasKey($id, $covered, sprintf('%s is neither a line nor inside a pointer at a %d budget.', $id, $budget));
                }
            }
        }

        // At the full budget every template the site serves, and every route, is a line of its own. The
        // parent's index template, which nothing serves, is folded.
        $index = (new IndexRenderer())->render($manifest);
        $lines = self::lineIds($index);
        $this->assertCount(33, array_filter($lines, static fn (string $id): bool => str_starts_with($id, 'tpl:')));
        $this->assertStringContainsString("\ntpl: +1 unused from acme-parent (section layout)\n", $index);
        $this->assertCount(33, array_filter($lines, static fn (string $id): bool => str_starts_with($id, 'route:')));
    }

    public function testNothingIsDroppedWithoutAPointerThatFetchesIt(): void
    {
        $manifest = self::realSized();

        foreach ([IndexRenderer::BUDGET, 6000, 2500] as $budget) {
            $index = (new IndexRenderer($budget))->render($manifest);
            $lines = array_flip(self::lineIds($index));
            $collapsed = array_flip(self::collapsedFields($manifest, $index));

            // Every listed item is a line, a collapsed field, or counted by a pointer.
            $missing = [];
            foreach (Visibility::of($manifest)->listed as ['item' => $item, 'section' => $section]) {
                if (!isset($lines[$item->id]) && !isset($collapsed[$item->id])) {
                    $missing[$item->kind . ' ' . $section][] = $item->id;
                }
            }

            ksort($missing);
            $pointers = self::dropped($index);
            $this->assertSame(
                array_map('count', $missing),
                array_map(static fn (array $pointer): int => $pointer['count'], $pointers),
                sprintf('At a %d budget, each kind left out of a section has one pointer with its count.', $budget)
            );

            // Following a pointer returns every item it counts.
            foreach ($pointers as $key => $pointer) {
                $fetched = self::fetch($manifest, $pointer['section'], $pointer['pages']);

                foreach ($missing[$key] as $id) {
                    $this->assertContains($id, $fetched, sprintf('%s is not on the pages its pointer names.', $id));
                }
            }
        }
    }

    public function testFieldsOfAPostTypeWithNoPostsCollapseIntoOneLine(): void
    {
        $manifest = self::realSized();
        $index = (new IndexRenderer(PHP_INT_MAX))->render($manifest);
        $lines = self::lineIds($index);

        preg_match_all(self::NO_POSTS . 'm', $index, $matches, PREG_SET_ORDER);
        $collapsed = [];
        foreach ($matches as $match) {
            $collapsed[$match[2]] = (int) $match[1];
        }

        $empty = array_filter(self::POST_TYPES, static fn (array $type): bool => $type[0] === 0);
        $expected = array_combine(array_map(static fn (string $slug): string => 'pt:' . $slug, array_keys($empty)), array_column($empty, 1));
        ksort($expected);
        $this->assertSame($expected, $collapsed, 'One line per post type with no posts, by id, with its field count.');
        $this->assertSame(75, array_sum($collapsed));

        foreach (array_keys(self::POST_TYPES) as $slug) {
            $fields = array_filter($lines, static fn (string $id): bool => str_starts_with($id, 'field:' . $slug . '.'));
            $this->assertCount(isset($empty[$slug]) ? 0 : self::POST_TYPES[$slug][1], $fields, $slug);
        }

        // The post type keeps its own line, which counts its fields instead of naming them.
        $this->assertMatchesRegularExpression('/\npt:service_area \| Service area \| 0 published; 33 fields; defined in themes\/acme-child\/inc\/types\.php \|/', $index);
        $this->assertMatchesRegularExpression('/\npt:team_member \| Team member \| 6-20 published; fields team_member_f01, /', $index);

        // The collapse line names the pages that hold the fields, and they do.
        $fields = array_values(array_filter(
            $manifest->ids(),
            static fn (string $id): bool => (bool) preg_match('/^field:(location|faq|offer|service_area|testimonial|event)\./', $id)
        ));
        sort($fields, SORT_STRING);
        $this->assertCount(75, $fields);
        $this->assertSame($fields, self::collapsedFields($manifest, $index));
    }

    public function testLinesFollowTheRanksThenTheirIds(): void
    {
        $manifest = self::realSized();
        $ids = self::lineIds((new IndexRenderer(PHP_INT_MAX))->render($manifest));
        $items = [];
        foreach ($manifest->sections as $section) {
            foreach ($section->items as $item) {
                $items[$item->id] = $item;
            }
        }

        $keys = array_map(static fn (string $id): array => [IndexRenderer::rank($items[$id]), $id], $ids);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);
        $this->assertSame(
            ['tpl', 'route', 'part', 'pat', 'pt', 'plugin', 'bind', 'core', 'filter', 'gates', 'nav', 'rest', 'tax', 'field', 'opt'],
            array_values(array_unique(array_map(static fn (string $id): string => strstr($id, ':', true), $ids)))
        );
    }

    public function testAPatternNothingUsesSortsAfterEveryOtherKindButFieldsAndOptions(): void
    {
        $unused = new Item('pat:old-promo', 'pattern', 'Old promo', ['registered' => false, 'synced' => true], 'post.php?post=7&action=edit', [], ['usage_count' => 0, 'used_in' => []]);
        $used = new Item('pat:promo', 'pattern', 'Promo', ['registered' => false, 'synced' => true], 'post.php?post=8&action=edit', [], ['usage_count' => 1, 'used_in' => ['page:home']]);

        $this->assertSame(IndexRenderer::RANKS['pattern'], IndexRenderer::rank($used));
        $this->assertSame(IndexRenderer::UNUSED_PATTERN_RANK, IndexRenderer::rank($unused));
        $this->assertGreaterThan(IndexRenderer::OTHER_RANK, IndexRenderer::rank($unused));
        $this->assertLessThan(IndexRenderer::RANKS['field'], IndexRenderer::rank($unused));
    }

    public function testTheOrderAndThePointersDoNotDependOnScanOrder(): void
    {
        $ordered = self::realSized();
        $shuffled = new Manifest(array_reverse(array_map(
            static fn (Section $section): Section => new Section($section->name, array_reverse($section->items)),
            $ordered->sections
        )));

        foreach ([IndexRenderer::BUDGET, 2500] as $budget) {
            $this->assertSame((new IndexRenderer($budget))->render($ordered), (new IndexRenderer($budget))->render($shuffled));
        }
    }

    public function testPagesAreNamedAsRuns(): void
    {
        $this->assertSame('section layout', IndexRenderer::fetch('layout', [1], 1));
        $this->assertSame('section layout, page 3', IndexRenderer::fetch('layout', [3], 6));
        $this->assertSame('section layout, pages 2-4, 6', IndexRenderer::fetch('layout', [6, 2, 3, 4, 3], 6));
        $this->assertSame('section code, pages 1, 3, 5-6', IndexRenderer::fetch('code', [5, 1, 3, 6], 7));
    }

    /**
     * Ids of the item lines, in order.
     *
     * @return list<string>
     */
    private static function lineIds(string $index): array
    {
        preg_match_all('/^([a-z]+:[^ |]+) \| /m', $index, $matches);

        return $matches[1];
    }

    /**
     * The pointer lines, by kind noun and section, as the index orders them.
     *
     * @return array<string, array{count: int, section: string, pages: list<int>}>
     */
    private static function dropped(string $index): array
    {
        $nouns = [
            'bindings' => 'binding', 'binding' => 'binding', 'core item' => 'core', 'core items' => 'core',
            'fields' => 'field', 'field' => 'field', 'head filters' => 'head_filter', 'head filter' => 'head_filter',
            'menus' => 'navigation', 'menu' => 'navigation', 'options' => 'option', 'option' => 'option',
            'patterns' => 'pattern', 'pattern' => 'pattern', 'plugins' => 'plugin', 'plugin' => 'plugin',
            'post types' => 'post_type', 'post type' => 'post_type', 'REST routes' => 'rest_route', 'REST route' => 'rest_route',
            'routes' => 'route', 'route' => 'route', 'taxonomies' => 'taxonomy', 'taxonomy' => 'taxonomy',
            'templates' => 'template', 'template' => 'template', 'parts' => 'template_part', 'part' => 'template_part',
            'gates item' => 'gates', 'gates items' => 'gates',
        ];
        $pointers = [];

        foreach (explode("\n", $index) as $line) {
            if (preg_match(self::DROPPED, $line, $match) === 1) {
                $pointers[$nouns[$match[2]] . ' ' . $match[3]] = ['count' => (int) $match[1], 'section' => $match[3], 'pages' => self::pages($match[4] ?? '')];
            }
        }

        ksort($pointers);

        return $pointers;
    }

    /**
     * The fields the collapse lines stand for, fetched through the pages they name.
     *
     * @return list<string>
     */
    private static function collapsedFields(Manifest $manifest, string $index): array
    {
        $fields = [];

        foreach (explode("\n", $index) as $line) {
            if (preg_match(self::NO_POSTS, $line, $match) === 1) {
                $prefix = 'field:' . substr($match[2], 3) . '.';
                $onPages = array_values(array_filter(self::fetch($manifest, $match[3], self::pages($match[4] ?? '')), static fn (string $id): bool => str_starts_with($id, $prefix)));

                // The pages hold every one of them, and the count says how many.
                TestCase::assertCount((int) $match[1], $onPages, $line);
                array_push($fields, ...$onPages);
            }
        }

        sort($fields, SORT_STRING);

        return $fields;
    }

    /**
     * Ids that are a line, folded, collapsed, or counted by a pointer whose
     * pages hold them.
     *
     * @return array<string, true>
     */
    private static function covered(Manifest $manifest, string $index): array
    {
        $covered = array_fill_keys(self::lineIds($index), true);

        foreach (self::collapsedFields($manifest, $index) as $id) {
            $covered[$id] = true;
        }

        $visibility = Visibility::of($manifest);
        $listed = [];
        foreach ($visibility->listed as ['item' => $item]) {
            $listed[$item->id] = true;
        }

        // Folded items: their prefix's fold line is in the index.
        foreach ($manifest->ids() as $id) {
            if (!isset($listed[$id]) && preg_match('/^' . preg_quote(strstr($id, ':', true), '/') . ': \+\d+ .+ \(section [a-z_]+\)$/m', $index) === 1) {
                $covered[$id] = true;
            }
        }

        foreach (self::dropped($index) as $key => $pointer) {
            $kind = strstr($key, ' ', true);

            foreach (self::fetch($manifest, $pointer['section'], $pointer['pages']) as $id) {
                $item = self::item($manifest, $id);

                if ($item !== null && $item->kind === $kind && isset($listed[$id]) && !isset($covered[$id])) {
                    $covered[$id] = true;
                }
            }
        }

        return $covered;
    }

    /**
     * Item ids on these pages of a section, through the section tier. No pages: the whole section.
     *
     * @param list<int> $pages
     * @return list<string>
     */
    private static function fetch(Manifest $manifest, string $section, array $pages): array
    {
        $renderer = new SectionRenderer();
        $ids = [];

        foreach ($pages === [] ? [null] : $pages as $page) {
            $data = json_decode((string) $renderer->render($manifest, $section, [], $page), true);
            array_push($ids, ...array_column($data['items'], 'id'));
        }

        return $ids;
    }

    /**
     * `2-4, 6` as [2, 3, 4, 6].
     *
     * @return list<int>
     */
    private static function pages(string $runs): array
    {
        $pages = [];

        foreach (array_filter(explode(', ', $runs)) as $run) {
            [$from, $to] = str_contains($run, '-') ? explode('-', $run) : [$run, $run];
            array_push($pages, ...range((int) $from, (int) $to));
        }

        return $pages;
    }

    private static function item(Manifest $manifest, string $id): ?Item
    {
        foreach ($manifest->sections as $section) {
            $item = $section->item($id);

            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }

    /**
     * A made-up site shaped like a real one: a child theme of a block theme
     * with its own templates, parts, patterns, post types, fields, bindings,
     * routes, plugins and REST routes. No real site's content.
     */
    private static function realSized(): Manifest
    {
        $types = 'themes/' . self::CHILD . '/inc/types.php';
        $content = [];
        $taxonomies = [];

        for ($t = 1; $t <= 12; $t++) {
            $taxonomies[] = new Item(sprintf('tax:topic_%02d', $t), 'taxonomy', sprintf('Topic %02d', $t), ['defined_in' => $types, 'hierarchical' => $t % 2 === 0, 'object_types' => ['post', 'service'], 'public' => true, 'show_ui' => true], sprintf('edit-tags.php?taxonomy=topic_%02d', $t), ['pt:post'], ['terms' => $t * 3]);
        }

        foreach (self::POST_TYPES as $slug => [$published, $fieldCount]) {
            $keys = array_map(static fn (int $f): string => sprintf('%s_f%02d', $slug, $f), range(1, $fieldCount));
            $edit = $slug === 'post' ? 'edit.php' : 'edit.php?post_type=' . $slug;
            $refs = [...array_map(static fn (string $key): string => 'field:' . $slug . '.' . $key, $keys), 'tax:topic_01'];

            $content[] = new Item('pt:' . $slug, 'post_type', ucfirst(str_replace('_', ' ', $slug)), ['defined_in' => in_array($slug, ['post', 'page'], true) ? 'core' : $types, 'has_archive' => false, 'public' => true, 'show_in_rest' => true, 'show_ui' => true], $edit, $refs, ['counts' => ['draft' => 0, 'private' => 0, 'publish' => $published]]);

            foreach ($keys as $i => $key) {
                $content[] = new Item('field:' . $slug . '.' . $key, 'field', ucfirst(str_replace('_', ' ', $key)), ['group' => ucfirst($slug) . ' details', 'in_rest' => false, 'required' => $i === 0, 'source' => 'acf', 'type' => $i % 3 === 0 ? 'text' : 'textarea'], $edit, ['pt:' . $slug]);
            }
        }

        $layout = [];
        $routes = [];
        $patterns = array_map(static fn (int $p): string => sprintf('pat:%s/section-%02d', self::CHILD, $p), range(1, 84));

        for ($t = 1; $t <= 30; $t++) {
            $slug = sprintf('page-%02d', $t);
            $layout[] = new Item('tpl:' . $slug, 'template', 'Page ' . $t, ['file' => 'themes/' . self::CHILD . '/templates/' . $slug . '.html', 'file_theme' => self::CHILD, 'overrides' => null, 'slug' => $slug, 'source' => 'theme', 'user_modified' => false], 'site-editor.php?p=/wp_template/' . self::CHILD . '//' . $slug . '&canvas=edit', ['part:footer', 'part:header', $patterns[$t - 1], $patterns[$t + 29]]);
        }

        foreach (['archive', 'index', 'search', 'single'] as $slug) {
            $layout[] = new Item('tpl:' . $slug, 'template', ucfirst($slug), ['file' => 'themes/' . self::PARENT . '/templates/' . $slug . '.html', 'file_theme' => self::PARENT, 'overrides' => null, 'slug' => $slug, 'source' => 'theme', 'user_modified' => false], 'site-editor.php?p=/wp_template/' . self::CHILD . '//' . $slug . '&canvas=edit', ['part:footer', 'part:header']);
        }

        foreach (['header' => 'header', 'footer' => 'footer'] as $slug => $area) {
            $layout[] = new Item('part:' . $slug, 'template_part', ucfirst($slug), ['area' => $area, 'file' => 'themes/' . self::CHILD . '/parts/' . $slug . '.html', 'file_theme' => self::CHILD, 'overrides' => self::PARENT, 'slug' => $slug, 'source' => 'theme', 'user_modified' => false], 'site-editor.php?p=/wp_template_part/' . self::CHILD . '//' . $slug . '&canvas=edit', $slug === 'header' ? ['nav:main'] : array_map(static fn (int $b): string => sprintf('bind:acme/site.key_%d', $b), range(1, 8)), ['usage_count' => 34, 'used_in' => array_map(static fn (int $t): string => sprintf('tpl:page-%02d', $t), range(1, 30))]);
        }

        $layout[] = new Item('part:sidebar', 'template_part', 'Sidebar', ['area' => 'uncategorized', 'file' => 'themes/' . self::PARENT . '/parts/sidebar.html', 'file_theme' => self::PARENT, 'overrides' => null, 'slug' => 'sidebar', 'source' => 'theme', 'user_modified' => false], 'site-editor.php?p=/wp_template_part/' . self::CHILD . '//sidebar&canvas=edit');

        foreach ($patterns as $p => $id) {
            $slug = substr($id, strlen('pat:' . self::CHILD . '/'));
            $layout[] = new Item($id, 'pattern', 'Section ' . ($p + 1), ['file' => 'themes/' . self::CHILD . '/patterns/' . $slug . '.php', 'reads' => ['option:acme_phone'], 'read_screens' => [['edit' => 'options-general.php?page=acme-site', 'label' => 'Phone', 'read' => 'option:acme_phone']], 'registered' => true, 'synced' => false], null, [], ['usage_count' => 2, 'used_in' => [$p < 60 ? sprintf('tpl:page-%02d', $p % 30 + 1) : 'page:home', 'page:about']]);
        }

        for ($b = 1; $b <= 8; $b++) {
            $layout[] = new Item(sprintf('bind:acme/site.key_%d', $b), 'binding', 'Site data: key ' . $b, ['bound' => ['core/paragraph.content'], 'defined_in' => 'plugins/acme-blocks/src/bindings.php', 'key' => 'key_' . $b, 'reads' => ['option:acme_key_' . $b], 'source' => 'acme/site', 'source_label' => 'Site data'], 'options-general.php?page=acme-site', [], ['usage_count' => 1, 'used_in' => ['part:footer']]);
        }

        $layout[] = new Item('nav:main', 'navigation', 'Main', [], 'site-editor.php?p=/wp_navigation/12', [], ['item_count' => 7, 'usage_count' => 1, 'used_in' => ['part:header']]);
        $layout[] = new Item('gates:theme', 'gates', 'Theme gates', ['gates' => ['color.custom' => 'theme'], 'settings' => ['appearanceTools' => true]], 'site-editor.php?p=/styles');

        foreach (array_slice(array_map(static fn (int $t): string => sprintf('page-%02d', $t), range(1, 30)), 0, 30) as $i => $slug) {
            $routes[] = new Item('route:' . $slug, 'route', 'Page ' . ($i + 1), ['path' => '/' . $slug . '/', 'template' => $slug], 'post.php?post=' . (100 + $i) . '&action=edit', ['tpl:' . $slug]);
        }

        foreach (['archive', 'search', 'single'] as $slug) {
            $routes[] = new Item('route:' . $slug, 'route', ucfirst($slug), ['pattern' => '/' . $slug . '/{slug}/', 'template' => $slug], null, ['tpl:' . $slug]);
        }

        $plugins = array_map(
            static fn (int $p): Item => new Item(sprintf('plugin:acme-plugin-%02d', $p), 'plugin', sprintf('Acme plugin %02d', $p), ['role' => $p % 4 === 0 ? 'seo' : 'other', 'version' => '1.' . $p . '.0'], 'plugins.php'),
            range(1, 15)
        );

        $code = [];
        for ($r = 1; $r <= 163; $r++) {
            $theme = $r <= 4;
            $code[] = new Item(sprintf('rest:acme/v1/route-%03d', $r), 'rest_route', sprintf('Route %03d', $r), ['endpoints' => [['file' => $theme ? 'themes/' . self::CHILD . '/inc/rest.php' : null, 'handler' => sprintf('acme_route_%03d', $r), 'methods' => ['POST'], 'permission' => 'public', 'traced' => $theme, 'mails' => [], 'records' => []]], 'owner' => ['kind' => $theme ? 'theme' : 'plugin', 'slug' => $theme ? self::CHILD : 'acme-plugin-01']], null);
        }

        for ($f = 1; $f <= 6; $f++) {
            $code[] = new Item(sprintf('filter:wp_head@acme_head_%d', $f), 'head_filter', 'Head filter ' . $f, ['callback' => 'acme_head_' . $f, 'changes' => 'meta description', 'file' => 'themes/' . self::CHILD . '/functions.php', 'traced' => true, 'returns_fixed_text' => $f % 2 === 0], null, ['route:page-01']);
        }

        $site = [
            new Item('core:wordpress', 'core', 'WordPress', ['version' => '7.1'], 'update-core.php'),
            new Item('opt:stylesheet', 'option', 'Active theme', ['name' => 'Acme Child', 'value' => self::CHILD, 'version' => '2.0.0'], 'themes.php', ['opt:template']),
            new Item('opt:template', 'option', 'Parent theme', ['name' => 'Acme Parent', 'value' => self::PARENT, 'version' => '1.0.0'], 'themes.php'),
        ];
        foreach (['blogname', 'blogdescription', 'page_on_front', 'page_for_posts', 'permalink_structure', 'show_on_front', 'timezone_string', 'date_format'] as $name) {
            $site[] = new Item('opt:' . $name, 'option', ucfirst(str_replace('_', ' ', $name)), ['value' => 'acme-' . $name], 'options-general.php');
        }

        return new Manifest([
            new Section('site', $site),
            new Section('content_model', [...$content, ...$taxonomies]),
            new Section('layout', $layout),
            new Section('routes', $routes),
            new Section('code', $code),
            new Section('integrations', $plugins),
        ]);
    }
}
