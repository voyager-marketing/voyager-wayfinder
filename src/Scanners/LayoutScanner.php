<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Voyager\Wayfinder\Blocks\BlockNode;
use Voyager\Wayfinder\Blocks\BlockRefs;
use Voyager\Wayfinder\Blocks\BlockTreeBuilder;
use Voyager\Wayfinder\Blocks\ClassIndex;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Blocks\ReferenceResolver;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;

/**
 * The `layout` section: templates, template parts, patterns, menus and block
 * bindings, and which of them points at which.
 *
 * Each template, part, pattern and menu is a container: its block markup is
 * walked once and every pointer block in it becomes a ref (`core/template-part`
 * to `part:`, `core/block` and `core/pattern` to `pat:`, `core/navigation`
 * with a `ref` to `nav:`, and each `metadata.bindings` entry to `bind:`).
 * Refs are direct only: a template that uses the footer refs `part:footer`,
 * not the footer's menu. Published content is walked the same way but is not
 * an item here; it only shows up in `used_in`. Usage lists and menu item
 * counts are stats, outside the item hash.
 *
 * Registered (code) patterns become items only when something references
 * them, so a theme that registers a hundred patterns does not flood the
 * index. One the active theme builds from a PHP file carries that `file`
 * and `reads`, what including the file reads (see ReadTracer::traceFile()),
 * so a pattern that prints an option is not mistaken for typed text. Where
 * an option it reads is edited is `read_screens`, for the options
 * OptionScreens can name, and the ones empty now are `stats.empty_reads`. A
 * ref to a template, part, pattern or menu that does not exist is moved
 * from `refs` to the `missing_refs` fact.
 *
 * Block templates, parts and synced patterns carry a `lock` fact: the
 * strictest lock any of their blocks is under at the item's own edit path
 * and how many are locked (see Locks), and for a part on WordPress 7.0 or
 * later `in_template`, what a template that places it makes it. Beside it,
 * `locked_blocks` lists each locked block by name or path with its lock.
 * Both are read in the same walk that finds the refs. They also carry
 * `classes`, `block_names` and `hidden_classes` from the counts that walk
 * keeps (see ClassIndex).
 *
 * Every item with an edit path has `stats.client`: whether the client role
 * can open that path and the capability it needs (see ClientAccess). It is a
 * stat, outside the hash, because it follows the site's role settings, not
 * its structure, so changing the client role moves no item hash.
 *
 * Binding keys are known only from blocks that use them. A source may serve
 * keys no block binds, and those have no item. When a binding reads an
 * option registered with register_setting(), the other options in that
 * settings group that no binding and no theme file reads are listed on it
 * as `unread_settings`: set up beside it and shown nowhere Wayfinder looks.
 *
 * A classic theme's templates and parts are PHP files (see ClassicTheme).
 * Their refs are the parts they include, they have no Site Editor path, and
 * the section adds the theme's menu locations (`loc:`) and sidebars
 * (`sidebar:`). A block theme has none of these. A hybrid theme's block
 * templates and parts sit beside its PHP files under `<theme>//<slug>` ids
 * (see LayoutSource::templates()).
 *
 * Templates an active page builder keeps in its own post type (an Elementor
 * header, a Divi layout, a Bricks template) are `btpl:` items with the
 * builder's edit URL and `coverage: partial` (see Builders). Their content is
 * never parsed, so they hold no refs.
 *
 * @phpstan-import-type TemplateRow from LayoutSource
 * @phpstan-import-type PostRow from LayoutSource
 * @phpstan-import-type PatternRow from LayoutSource
 * @phpstan-import-type State from Locks
 * @phpstan-import-type Lock from Locks
 * @phpstan-import-type Summary from Locks
 * @phpstan-import-type LockedBlock from Locks
 * @phpstan-import-type ClassFacts from ClassIndex
 * @phpstan-type Bindings array<string, array{source: string, key: string|null, bound: array<string, true>, args: array<string, array<array-key, mixed>>}>
 */
final class LayoutScanner implements Scanner
{
    public const SECTION = 'layout';

    /** `used_in` lists at most this many containers; `usage_count` has the total. */
    public const USED_IN_CAP = 50;

    /** Id prefixes this section owns. A ref under any other prefix belongs to another section. */
    private const OWN_PREFIXES = ['tpl', 'part', 'pat', 'nav', 'bind'];

    private LayoutSource $source;

    private BlockTreeBuilder $builder;

    private Locks $locks;

    private ClientAccess $access;

    /** @var array<string, array<string, mixed>> Container id => its lock and class facts, filled as each block container is walked. */
    private array $containerFacts = [];

    public function __construct(?LayoutSource $source = null, ?Locks $locks = null, ?ClientAccess $access = null)
    {
        $this->source = $source ?? new WordPressLayoutSource();
        $this->builder = new BlockTreeBuilder();
        $this->locks = $locks ?? Locks::forSite();
        $this->access = $access ?? ClientAccess::forSite();
    }


    public function section(): string
    {
        return self::SECTION;
    }

    public function scan(): Section
    {
        $templates = self::withValidIds('tpl:', $this->source->templates('wp_template'));
        $parts = self::withValidIds('part:', $this->source->templates('wp_template_part'));
        $patterns = self::byId($this->source->syncedPatterns());
        $navigations = self::byId($this->source->navigations());

        // Pointer blocks resolve against the rows just read, never a second query.
        // Registered patterns resolve later, by slug, only when something uses them.
        $this->builder = new BlockTreeBuilder(new ReferenceResolver(
            static fn (int $id): ?array => $patterns[$id] ?? null,
            static fn (string $slug): ?array => null,
            static fn (int $id): ?array => $navigations[$id] ?? null
        ));

        /** @var array<string, list<string>> $refs Container id => refs, for every item that holds blocks. */
        $refs = [];

        /** @var array<string, list<string>> $contentRefs `<post_type>:<slug>` => refs, for published content. */
        $contentRefs = [];

        /** @var Bindings $bindings Every binding found anywhere, filled in by walk(). */
        $bindings = [];

        $items = [];
        $this->containerFacts = [];

        foreach ($templates as $row) {
            $refs['tpl:' . self::key($row)] = $this->containerRefs('tpl:' . self::key($row), $row, $bindings, Locks::unlocked());
        }

        foreach ($parts as $row) {
            $id = 'part:' . self::key($row);
            $refs[$id] = $this->containerRefs($id, $row, $bindings, $this->locks->root(Locks::TEMPLATE_PART), $this->locks->inTemplate(Locks::TEMPLATE_PART));
        }

        $syncedIds = [];

        foreach ($patterns as $row) {
            $id = 'pat:' . self::slug($row);
            $syncedIds[$id] = true;
            $walked = $this->walk($row['content'], $bindings, $this->locks->root(Locks::SYNCED_PATTERN));
            $refs[$id] = $walked['refs'];
            $this->containerFacts[$id] = ['lock' => $walked['lock'], 'locked_blocks' => $walked['locked_blocks']] + $walked['classes'];
        }

        $itemCounts = [];

        foreach ($navigations as $row) {
            $id = 'nav:' . self::slug($row);
            ['refs' => $refs[$id], 'total' => $itemCounts[$id]] = $this->walk($row['content'], $bindings);
        }

        foreach ($this->source->content() as $row) {
            $key = $row['type'] . ':' . $row['slug'];
            $contentRefs[$key] = array_values(array_unique(array_merge(
                $contentRefs[$key] ?? [],
                $this->walk($row['content'], $bindings)['refs']
            )));
        }

        $registered = $this->registeredPatterns(
            array_merge(array_values($refs), array_values($contentRefs)),
            $syncedIds,
            $refs,
            $bindings
        );

        $usedIn = self::usedIn($refs, $contentRefs);

        // Every id this section will hold, so a ref can be checked against it.
        $ids = array_fill_keys(array_keys($refs), true) + array_fill_keys(array_keys($bindings), true);

        foreach ($templates as $row) {
            $id = 'tpl:' . self::key($row);
            $items[] = $this->item(
                $id,
                'template',
                $row['title'] !== '' ? $row['title'] : $row['slug'],
                [
                    'file'          => $row['file'] ?? null,
                    'file_theme'    => $row['file_theme'] ?? null,
                    'overrides'     => $row['overrides'] ?? null,
                    'slug'          => $row['slug'],
                    'source'        => $row['source'],
                    'user_modified' => $row['user_modified'],
                ] + self::classicFacts($row) + $this->containerFact($id),
                self::templateEditPath('wp_template', $row),
                $refs[$id],
                $ids
            );
        }

        foreach ($parts as $row) {
            $id = 'part:' . self::key($row);
            $items[] = $this->item(
                $id,
                'template_part',
                $row['title'] !== '' ? $row['title'] : $row['slug'],
                [
                    'area'          => $row['area'],
                    'file'          => $row['file'] ?? null,
                    'file_theme'    => $row['file_theme'] ?? null,
                    'overrides'     => $row['overrides'] ?? null,
                    'slug'          => $row['slug'],
                    'source'        => $row['source'],
                    'user_modified' => $row['user_modified'],
                ] + self::classicFacts($row) + $this->containerFact($id),
                self::templateEditPath('wp_template_part', $row),
                $refs[$id],
                $ids,
                self::usage($usedIn[$id] ?? [])
            );
        }

        foreach ($patterns as $row) {
            $id = 'pat:' . self::slug($row);
            $items[] = $this->item(
                $id,
                'pattern',
                $row['title'] !== '' ? $row['title'] : self::slug($row),
                ['registered' => false, 'synced' => $row['synced']] + $this->containerFact($id),
                'post.php?post=' . $row['id'] . '&action=edit',
                $refs[$id],
                $ids,
                self::usage($usedIn[$id] ?? [])
            );
        }

        foreach ($registered as $id => $title) {
            $file = $this->source->patternFile(substr($id, 4));
            $reads = $file['reads'] ?? null;

            $items[] = $this->item(
                $id,
                'pattern',
                $title !== '' ? $title : substr($id, 4),
                ['file' => $file['file'] ?? null, 'reads' => $reads, 'registered' => true, 'synced' => false] + $this->readScreens($reads),
                null,
                $refs[$id],
                $ids,
                self::usage($usedIn[$id] ?? []) + $this->emptyReads($reads)
            );
        }

        foreach ($navigations as $row) {
            $id = 'nav:' . self::slug($row);
            $items[] = $this->item(
                $id,
                'navigation',
                $row['title'] !== '' ? $row['title'] : self::slug($row),
                [],
                self::siteEditorPath('wp_navigation', (string) $row['id']),
                $refs[$id],
                $ids,
                ['item_count' => $itemCounts[$id]] + self::usage($usedIn[$id] ?? [])
            );
        }

        foreach ($bindings as $id => $binding) {
            $items[] = $this->bindingItem($id, $binding, $usedIn[$id] ?? []);
        }

        $items = $this->withUnreadSettings([...$items, ...$this->classicItems(), ...$this->builderTemplateItems()]);

        return new Section(self::SECTION, array_map($this->withClient(...), $items));
    }

    /**
     * Adds `stats.client` to an item with an edit path: whether the client
     * role can open it, and the capability that takes.
     */
    private function withClient(Item $item): Item
    {
        if ($item->edit === null) {
            return $item;
        }

        return new Item($item->id, $item->kind, $item->label, $item->facts, $item->edit, $item->refs, $item->stats + ['client' => $this->access->opens($item->edit)]);
    }

    /**
     * Adds `unread_settings` to each binding that reads an option registered
     * in a settings group: the group's other options that no binding and no
     * theme file (a classic template's or part's `reads`) reads, sorted by
     * option, each with the label it was registered with.
     *
     * @param list<Item> $items
     * @return list<Item>
     */
    private function withUnreadSettings(array $items): array
    {
        $read = [];

        foreach ($items as $item) {
            foreach (is_array($item->facts['reads'] ?? null) ? $item->facts['reads'] : [] as $id) {
                if (is_string($id) && str_starts_with($id, 'option:')) {
                    $read[substr($id, 7)] = true;
                }
            }
        }

        foreach ($items as $i => $item) {
            if ($item->kind !== 'binding' || !is_array($item->facts['reads'] ?? null)) {
                continue;
            }

            $unread = [];

            foreach ($item->facts['reads'] as $id) {
                if (!is_string($id) || !str_starts_with($id, 'option:')) {
                    continue;
                }

                foreach ($this->source->settingsBeside(substr($id, 7)) as $option => $label) {
                    if (!isset($read[$option])) {
                        $unread[$option] = ['label' => $label, 'option' => $option];
                    }
                }
            }

            if ($unread !== []) {
                ksort($unread, SORT_STRING);
                $items[$i] = new Item($item->id, $item->kind, $item->label, $item->facts + ['unread_settings' => array_values($unread)], $item->edit, $item->refs, $item->stats);
            }
        }

        return $items;
    }

    /**
     * One `btpl:<slug>` item per template an active page builder keeps in its
     * own post type, so a header or footer built in the builder has a home.
     * Wayfinder cannot read inside it, so its coverage is partial. Two
     * templates sharing a slug (in two builders' post types) are both named
     * `<post type>.<slug>` instead, so neither replaces the other.
     *
     * @return list<Item>
     */
    private function builderTemplateItems(): array
    {
        $rows = $this->source->builderTemplates();
        $perSlug = array_count_values(array_map(static fn (array $row): string => self::builderSlug($row), $rows));
        $items = [];

        foreach ($rows as $row) {
            $slug = self::builderSlug($row);
            $id = 'btpl:' . ($perSlug[$slug] > 1 ? $row['type'] . '.' . $slug : $slug);

            if (!IdFormat::isValid($id) || isset($items[$id])) {
                continue;
            }

            $items[$id] = new Item(
                $id,
                'builder_template',
                $row['title'] !== '' ? $row['title'] : $slug,
                [
                    'builder'       => $row['builder'],
                    'coverage'      => Builders::PARTIAL,
                    'post_type'     => $row['type'],
                    'template_type' => $row['template_type'],
                ],
                $row['edit']
            );
        }

        return array_values($items);
    }

    /**
     * A builder template's slug, or `#<id>` when it has none.
     *
     * @param array{slug: string, id: int} $row
     */
    private static function builderSlug(array $row): string
    {
        return $row['slug'] !== '' ? $row['slug'] : '#' . $row['id'];
    }

    /**
     * Refs of a template or part: the parts a classic PHP file includes, or
     * what its block markup points at.
     *
     * A block one also records its lock facts, with `in_template` when a
     * template that places it makes it stricter (a part on 7.0 or later).
     *
     * @param TemplateRow $row
     * @param Bindings    $bindings
     * @param State       $root
     * @param State|null  $inTemplate
     * @return list<string>
     */
    private function containerRefs(string $id, array $row, array &$bindings, array $root, ?array $inTemplate = null): array
    {
        if (!isset($row['includes'])) {
            $walked = $this->walk($row['content'], $bindings, $root);
            $lock = $walked['lock'] + ($inTemplate !== null ? ['in_template' => $inTemplate['mode']] : []);
            $this->containerFacts[$id] = ['lock' => $lock, 'locked_blocks' => $walked['locked_blocks']] + $walked['classes'];

            return isset($row['key']) ? self::blockPartRefs($walked['refs'], $row['theme']) : $walked['refs'];
        }

        $refs = array_values(array_filter(
            array_map(static fn (string $slug): string => 'part:' . $slug, $row['includes']),
            [IdFormat::class, 'isValid']
        ));
        sort($refs, SORT_STRING);

        return $refs;
    }

    /**
     * On a hybrid theme a template part block renders the theme's block part,
     * never its PHP file, so a block row's `part:<slug>` refs become
     * `part:<theme>//<slug>`.
     *
     * @param list<string> $refs Sorted.
     * @return list<string>
     */
    private static function blockPartRefs(array $refs, string $theme): array
    {
        $refs = array_map(
            static fn (string $ref): string => str_starts_with($ref, 'part:') && !str_contains($ref, '//')
                ? 'part:' . ClassicTheme::blockKey($theme, substr($ref, 5))
                : $ref,
            $refs
        );
        sort($refs, SORT_STRING);

        return $refs;
    }

    /**
     * A classic theme's menu locations and sidebars. A location edits at its
     * assigned menu's screen, or at the locations screen when it has none.
     * Item and widget counts are content, so they are stats.
     *
     * @return list<Item>
     */
    private function classicItems(): array
    {
        $items = [];

        foreach ($this->source->menuLocations() as $row) {
            $id = 'loc:' . $row['slug'];
            $menu = $row['menu'];

            if (!IdFormat::isValid($id)) {
                continue;
            }

            $items[$id] = new Item(
                $id,
                'menu_location',
                $row['label'] !== '' ? $row['label'] : $row['slug'],
                ['menu' => $menu['name'] ?? null],
                $menu !== null ? 'nav-menus.php?action=edit&menu=' . $menu['id'] : 'nav-menus.php?action=locations',
                [],
                $menu !== null ? ['item_count' => $menu['items']] : []
            );
        }

        foreach ($this->source->sidebars() as $row) {
            $id = 'sidebar:' . $row['id'];

            if (!IdFormat::isValid($id)) {
                continue;
            }

            $items[$id] = new Item(
                $id,
                'sidebar',
                $row['name'] !== '' ? $row['name'] : $row['id'],
                [],
                'widgets.php',
                [],
                ['widget_count' => $row['widgets']]
            );
        }

        return array_values($items);
    }

    /**
     * Ids of the parts, patterns, menus and bindings a block of markup points
     * at directly, the number of blocks in it, nested included, the
     * strictest lock its blocks are under starting from `$root` and the
     * locked blocks themselves in parse order (at most LOCKED_BLOCKS_CAP),
     * and its class facts. Adds each binding it meets to `$bindings`.
     *
     * @param Bindings $bindings
     * @param State    $root
     * @return array{refs: list<string>, total: int, lock: Summary, locked_blocks: list<LockedBlock>, classes: ClassFacts}
     */
    private function walk(string $content, array &$bindings, array $root = ['mode' => 'none', 'src' => 'none']): array
    {
        $built = $this->builder->build(parse_blocks($content));

        $refs = [];
        $locks = [];
        $locked = [];
        $this->collect($built['tree'], $refs, $bindings, $root, $locks, $locked);

        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);

        return [
            'refs'          => $refs,
            'total'         => (int) $built['summary']['total'],
            'lock'          => Locks::strictest($locks),
            'locked_blocks' => array_slice($locked, 0, Locks::LOCKED_BLOCKS_CAP),
            'classes'       => ClassIndex::facts($built['summary']['classes'], $built['summary']['names']),
        ];
    }

    /**
     * @param list<BlockNode>   $nodes
     * @param list<string>      $refs
     * @param Bindings          $bindings
     * @param State             $state
     * @param list<Lock>        $locks
     * @param list<LockedBlock> $locked
     */
    private function collect(array $nodes, array &$refs, array &$bindings, array $state, array &$locks, array &$locked): void
    {
        foreach ($nodes as $node) {
            $lock = $this->locks->block($node, $state);
            $locks[] = $lock;
            $entry = Locks::lockedBlock($node, $lock);

            if ($entry !== null) {
                $locked[] = $entry;
            }

            $ref = BlockRefs::pointer($node->reference);

            if ($ref !== null && IdFormat::isValid($ref)) {
                $refs[] = $ref;
            }

            foreach (BlockRefs::bindingsOf($node) as $attribute => $binding) {
                $id = $binding['id'];
                $bindings[$id] ??= ['source' => $binding['source'], 'key' => $binding['key'], 'bound' => [], 'args' => []];
                $bindings[$id]['bound'][$node->name . '.' . $attribute] = true;
                $bindings[$id]['args'][CanonicalJson::encode($binding['args'])] = $binding['args'];
                $refs[] = $id;
            }

            $this->collect($node->children, $refs, $bindings, $this->locks->inner($node, $state), $locks, $locked);
        }
    }

    /**
     * A classic PHP template's or part's `reads` and `links`, and a
     * template's `shows_post`. A block one has none, so its facts stay as
     * they were.
     *
     * @param TemplateRow $row
     * @return array{reads?: list<string>, links?: list<string>, shows_post?: list<string>}
     */
    private static function classicFacts(array $row): array
    {
        return array_intersect_key($row, ['reads' => true, 'links' => true, 'shows_post' => true]);
    }

    /**
     * The facts a block container gets from its walk: `lock` (`{effective,
     * locked, src}`), `classes`, `block_names`, `hidden_classes` and, past
     * the cap, `classes_more`. A classic PHP file has none.
     *
     * @return array<string, mixed>
     */
    private function containerFact(string $id): array
    {
        return $this->containerFacts[$id] ?? [];
    }

    /**
     * Registered patterns that templates, parts, patterns, menus or content
     * point at, followed through patterns that point at other patterns. Each
     * found one is walked like any other container and its refs added to
     * `$refs`.
     *
     * @param array<array-key, list<string>> $containers Refs of everything walked so far.
     * @param array<string, true>            $syncedIds  Pattern ids that are wp_block posts.
     * @param array<string, list<string>>    $refs       Container refs, extended in place.
     * @param Bindings                       $bindings   Extended in place.
     * @return array<string, string> Pattern id => title, for the ones that are registered.
     */
    private function registeredPatterns(array $containers, array $syncedIds, array &$refs, array &$bindings): array
    {
        $queue = [];

        foreach ($containers as $containerRefs) {
            foreach ($containerRefs as $ref) {
                $queue[] = $ref;
            }
        }

        $found = [];
        $seen = [];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($seen[$id]) || isset($syncedIds[$id]) || !str_starts_with($id, 'pat:') || str_starts_with($id, 'pat:#')) {
                continue;
            }

            $seen[$id] = true;
            $pattern = $this->source->registeredPattern(substr($id, 4));

            if ($pattern === null) {
                continue;
            }

            $found[$id] = $pattern['title'];
            $refs[$id] = $this->walk($pattern['content'], $bindings)['refs'];

            foreach ($refs[$id] as $ref) {
                $queue[] = $ref;
            }
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    /**
     * `read_screens` for a registered pattern: where each option it reads is
     * edited, for the options OptionScreens can name. Absent when none.
     *
     * @param list<string>|null $reads
     * @return array{read_screens?: non-empty-list<array{read: string, label: string|null, edit: string}>}
     */
    private function readScreens(?array $reads): array
    {
        $screens = $reads !== null ? OptionScreens::forReads($reads, $this->source->siteDataFields(), $this->source->siteDataScreen()) : [];

        return $screens !== [] ? ['read_screens' => $screens] : [];
    }

    /**
     * `empty_reads`, a stat: the options a registered pattern reads that are
     * unset or empty now, so what it prints from them shows nothing (or a
     * fallback) until they are set. A stat because setting an option is
     * content, not structure. The value itself is never kept.
     *
     * @param list<string>|null $reads
     * @return array{empty_reads?: non-empty-list<string>}
     */
    private function emptyReads(?array $reads): array
    {
        $empty = array_values(array_filter(
            $reads ?? [],
            fn (string $read): bool => str_starts_with($read, 'option:') && $this->source->optionText(substr($read, 7)) === ''
        ));

        return $empty !== [] ? ['empty_reads' => $empty] : [];
    }

    /**
     * Inverts the refs: for each id, the containers that point at it.
     *
     * @param array<string, list<string>> $refs        Layout items.
     * @param array<string, list<string>> $contentRefs Published content.
     * @return array<string, list<string>> Layout ids first, then content, each sorted.
     */
    private static function usedIn(array $refs, array $contentRefs): array
    {
        $layout = [];
        $content = [];

        foreach ($refs as $container => $targets) {
            foreach ($targets as $target) {
                $layout[$target][] = $container;
            }
        }

        foreach ($contentRefs as $container => $targets) {
            foreach ($targets as $target) {
                $content[$target][] = $container;
            }
        }

        $usedIn = [];

        foreach (array_keys($layout + $content) as $target) {
            $first = $layout[$target] ?? [];
            $then = $content[$target] ?? [];
            sort($first, SORT_STRING);
            sort($then, SORT_STRING);
            $usedIn[$target] = [...$first, ...$then];
        }

        return $usedIn;
    }

    /**
     * Stats, not facts: content that starts or stops using an item must not
     * change its hash.
     *
     * @param list<string> $usedIn
     * @return array{usage_count: int, used_in: list<string>}
     */
    private static function usage(array $usedIn): array
    {
        return [
            'usage_count' => count($usedIn),
            'used_in'     => array_slice($usedIn, 0, self::USED_IN_CAP),
        ];
    }

    /**
     * An item whose refs point at layout ids that do not exist carries those
     * in `missing_refs` instead.
     *
     * @param array<string, mixed> $facts
     * @param list<string>         $refs
     * @param array<string, true>  $ids
     * @param array<string, mixed> $stats
     */
    private function item(string $id, string $kind, string $label, array $facts, ?string $edit, array $refs, array $ids, array $stats = []): Item
    {
        $present = [];
        $missing = [];

        foreach ($refs as $ref) {
            $prefix = substr($ref, 0, (int) strpos($ref, ':'));

            if (in_array($prefix, self::OWN_PREFIXES, true) && !isset($ids[$ref])) {
                $missing[] = $ref;
            } else {
                $present[] = $ref;
            }
        }

        if ($missing !== []) {
            $facts['missing_refs'] = $missing;
        }

        return new Item($id, $kind, $label, $facts, $edit, $present, $stats);
    }

    /**
     * A binding names the file its source is defined in and, unless it reads
     * post meta (OptionScreens::POST_META_SOURCES, which ref their `field:`
     * items instead), what it reads. Its `edit` is where the value is changed:
     * the owner's Site Data screen for `voyager/site-data`, and for a source
     * that states it, the path it states.
     *
     * @param array{source: string, key: string|null, bound: array<string, true>, args: array<string, array<array-key, mixed>>} $binding
     * @param list<string>                                                                                                     $usedIn
     */
    private function bindingItem(string $id, array $binding, array $usedIn): Item
    {
        $source = $binding['source'];
        $key = $binding['key'];
        $sourceLabel = $this->source->bindingSourceLabel($source);

        $bound = array_keys($binding['bound']);
        sort($bound, SORT_STRING);

        $refs = [];
        $postMeta = in_array($source, OptionScreens::POST_META_SOURCES, true);

        if ($postMeta && $key !== null) {
            $metaKey = $source === 'core/post-meta' ? $key : explode('.', $key, 2)[0];

            foreach ($this->source->metaPostTypes($metaKey) as $postType) {
                $field = 'field:' . $postType . '.' . $metaKey;

                if (IdFormat::isValid($field)) {
                    $refs[] = $field;
                }
            }
        }

        return new Item(
            $id,
            'binding',
            ($sourceLabel ?? $source) . ($key !== null ? ': ' . $key : ''),
            [
                'bound'        => $bound,
                'defined_in'   => $this->source->bindingSourceFile($source),
                'key'          => $key,
                'reads'        => $postMeta ? null : $this->reads($binding, $bound),
                'source'       => $source,
                'source_label' => $sourceLabel,
            ],
            $this->source->bindingEditPath($source, $key),
            $refs,
            self::usage($usedIn)
        );
    }

    /**
     * What a binding reads, from one evaluation chosen so that it does not
     * depend on walk order: the args that sort first by their canonical JSON,
     * bound to the attribute that sorts first.
     *
     * @param array{source: string, key: string|null, bound: array<string, true>, args: array<string, array<array-key, mixed>>} $binding
     * @param list<string>                                                                                                     $bound   Sorted.
     * @return list<string>|null
     */
    private function reads(array $binding, array $bound): ?array
    {
        $args = $binding['args'];
        ksort($args, SORT_STRING);

        $attribute = $bound[0] ?? '';
        $dot = strrpos($attribute, '.');

        if ($dot === false) {
            return null;
        }

        return $this->source->bindingReads(
            $binding['source'],
            $binding['key'],
            $args === [] ? [] : reset($args),
            substr($attribute, 0, $dot),
            substr($attribute, $dot + 1)
        );
    }

    /**
     * Drops template rows whose slug cannot make an id, so one odd row cannot
     * fail the whole section.
     *
     * @param list<TemplateRow> $rows
     * @return list<TemplateRow>
     */
    private static function withValidIds(string $prefix, array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => IdFormat::isValid($prefix . self::key($row))
        ));
    }

    /**
     * The slug of a template's or part's id: its `key` on a hybrid theme's
     * block row (`acme//page`), else its slug.
     *
     * @param TemplateRow $row
     */
    public static function key(array $row): string
    {
        return $row['key'] ?? $row['slug'];
    }

    /**
     * Post rows keyed by id, so pointer blocks resolve without another query.
     *
     * @template T of array{id: int, slug: string, title: string, content: string}
     * @param list<T> $rows
     * @return array<int, T>
     */
    private static function byId(array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }

        ksort($byId);

        return $byId;
    }

    /**
     * The slug an id is built from: post_name, or `#<id>` when WordPress has none.
     *
     * @param array{id: int, slug: string} $row
     */
    private static function slug(array $row): string
    {
        return $row['slug'] !== '' ? $row['slug'] : '#' . $row['id'];
    }

    /**
     * A block template or part opens in the Site Editor. A classic theme's
     * PHP file has no edit screen: its `file` fact says where it is.
     *
     * @param TemplateRow $row
     */
    private static function templateEditPath(string $postType, array $row): ?string
    {
        return isset($row['includes']) ? null : self::siteEditorPath($postType, $row['theme'] . '//' . $row['slug']);
    }

    /**
     * The Site Editor route that opens one item: `site-editor.php?p=/<post type>/<id>`.
     * The older `postType=&postId=` form redirects in WordPress 7.1 to the
     * Templates or Patterns list rather than the item. Templates and parts
     * add `canvas=edit` to open in the editor. A menu opens in the sidebar
     * at its own route and needs nothing more.
     */
    private static function siteEditorPath(string $postType, string $id): string
    {
        return 'site-editor.php?p=/' . $postType . '/' . $id . ($postType === 'wp_navigation' ? '' : '&canvas=edit');
    }
}
