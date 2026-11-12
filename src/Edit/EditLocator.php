<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Edit;

use Closure;
use InvalidArgumentException;
use Voyager\Wayfinder\Blocks\BlockFingerprint;
use Voyager\Wayfinder\Blocks\BlockNode;
use Voyager\Wayfinder\Blocks\BlockRefs;
use Voyager\Wayfinder\Blocks\BlockTreeBuilder;
use Voyager\Wayfinder\Blocks\ClassIndex;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\SnapshotStore;
use Voyager\Wayfinder\Scanners\Builders;
use Voyager\Wayfinder\Scanners\CodeScanner;
use Voyager\Wayfinder\Scanners\HeadFilters;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\OptionAllowlist;
use Voyager\Wayfinder\Scanners\OptionScreens;
use Voyager\Wayfinder\Scanners\LayoutSource;
use Voyager\Wayfinder\Scanners\RouteScanner;
use Voyager\Wayfinder\Scanners\RouteSource;
use Voyager\Wayfinder\Scanners\WordPressLayoutSource;
use Voyager\Wayfinder\Scanners\WordPressRouteSource;

/**
 * Answers "where do I change this thing I see on the page?" from a URL and,
 * optionally, some text visible on it.
 *
 * The URL resolves to a route (RouteScanner::routeFor), and so to the
 * template WordPress serves it with and the post whose content it shows.
 * From there the locator walks what the page is built from, breadth first:
 * the template, the post content it places, then the parts, patterns, menus
 * and bindings they point at. Pointer blocks are never inlined, so text that
 * lives in a synced pattern is found in the pattern, not in each page that
 * uses it.
 *
 * Without text the chain is everything the page is built from: the post
 * content, the template and what they reach, then the route. With text, a
 * block whose own text or text attributes match it is a hit, a bound
 * attribute whose source returns a matching value is a hit on the binding,
 * and the post title counts as content (see Ranking for the tiers and the
 * normalization). Every home of a match is a hit, on this URL's route or
 * not: other content, templates, parts, patterns and menus are searched
 * too. `hits` lists them ranked, and `ambiguous` says when the top two are
 * equally good answers. The chain is the top hit on this route, followed
 * by what it sits in, up to the route; with no hit on the route it is the
 * whole page, `matched` is false and `hits` still names the other homes.
 *
 * With a class, a block that carries that className token or is named it
 * (`metadata.name`) is a hit, and each hit entry counts its blocks in
 * `class_count`. Text and class together narrow to blocks matching both;
 * a binding is then a hit only when its block carries the class. The
 * post content entry carries the content's class facts (see ClassIndex).
 *
 * Nothing is rendered over HTTP, so text that only exists in PHP output (a
 * shortcode, a dynamic block's markup) is not found, and the result says so.
 * Bound values are compared and never returned.
 *
 * A registered pattern built from a theme file is searched in the content
 * that file builds. When a matching block's text is an option the file
 * reads, the hit is on the option (`option:<name>`), whose entry names the
 * screen that edits it, and without text the chain lists those options
 * after their pattern. Option values are compared and never returned.
 *
 * Every entry carries `lock` (see Locks) and `client_can` with
 * `client_can_why` (see ClientAccess). A hit's lock is the lock of the
 * first block in it that matched, read in the same walk that searched it;
 * any other entry's lock is its container's as a whole.
 *
 * When the URL's own post is a page builder's document (see Builders), its
 * content entry edits in the builder, says the content is not read, and the
 * result carries `coverage: partial` with `reason: builder_document`. A miss
 * on such a page says the text is most likely inside the builder document.
 *
 * When a theme, plugin or mu-plugin filter changes this URL's head (its
 * title, description, og:title, ...), the chain says so just before the
 * route: one entry per filter from the stored code section (HeadFilters)
 * whose conditional tags name this route, or that checks none and so runs
 * on every page. The entry names the callback and its file, and the setting
 * it reads when there is one. A plugin's filter is named, not read.
 *
 * Every hit on a block carries an `anchor` (see Anchors), and so does the
 * chain entry of the top hit. `verify` says how a writer checks its edit
 * afterwards: the stored manifest hash to ask `get-changes-since` from, the
 * ids a text edit should move there (none: item hashes cover structure,
 * never text), and the anchor to look up again, whose fingerprint must
 * have moved.
 *
 * @phpstan-import-type Route from RouteScanner
 * @phpstan-import-type RoutePost from RouteSource
 * @phpstan-import-type BuilderDoc from Builders
 * @phpstan-import-type Lock from Locks
 * @phpstan-import-type State from Locks
 * @phpstan-type Base array{id: string, label: string, edit: string|null, instruction: string, match: bool, used_in?: list<string>, usage_count?: int, builder?: string}
 * @phpstan-type Entry array{id: string, label: string, edit: string|null, instruction: string, match: bool, used_in?: list<string>, usage_count?: int, builder?: string, lock: Lock, client_can: 'yes'|'partial'|'no', client_can_why: string, anchor?: Anchor, classes?: list<array{class: string, count: int, style?: string}>, block_names?: list<array{name: string, count: int}>, hidden_classes?: bool, classes_more?: int, class_count?: int}
 * @phpstan-import-type Query from Ranking
 * @phpstan-import-type Found from Ranking
 * @phpstan-import-type Anchor from Anchors
 * @phpstan-type Hit array{rank: int, id: string, block_path: string|null, snippet: string, why: list<string>, on_route: bool, distance: int|null, routes: int|null, anchor: Anchor|null}
 * @phpstan-type Verify array{changes_since: string|null, expect_changed: list<string>, recheck: Anchor|null}
 * @phpstan-type Result array{url: string, route: string|null, template: string|null, text: string|null, class: string|null, matched: bool|null, ambiguous: bool, message: string|null, chain: list<Entry>, hits: list<Hit>, verify: Verify, coverage?: 'partial', reason?: 'builder_document'}
 * @phpstan-type Scope array{on_route: bool, depth: array<string, int>, post: RoutePost|null, routes: array<string, int>|null, hints: array<string, string>, code: array<string, true>, reads: array<string, list<string>>, content_type: string|null, post_id: int|null}
 */
final class EditLocator
{
    /** Bound attributes that replace the block's own text when it renders. */
    private const TEXT_BINDINGS = ['content', 'text'];

    /** How many usages a synced pattern's instruction names before "and N more". */
    private const USAGES_NAMED = 5;

    /** How a miss's message ends: the chain is then the whole page. FindPayload rewords it when it cuts the chain. */
    public const WHOLE_PAGE = 'The chain lists everything the page is built from.';

    private RouteSource $routes;

    private LayoutSource $layout;

    private Locks $locks;

    private ClientAccess $access;

    private Anchors $anchors;

    /** @var array<string, int> Container id => blocks in it on this route that carry the class, filled by collect(). */
    private array $classCounts = [];

    /** @var Closure(): (array<string, list<string>>|null) */
    private Closure $routeRefs;

    /** @var Closure(): (string|null) */
    private Closure $storedHash;

    /** @var Closure(): list<Item> */
    private Closure $headFilters;

    /**
     * @param (callable(): (array<string, list<string>>|null))|null $routeRefs Each route item's refs, from the routes
     *                                                                       section of the stored manifest. Defaults
     *                                                                       to the snapshot; null when none is stored.
     * @param (callable(): (string|null))|null                      $storedHash The stored manifest hash, which
     *                                                                       `get-changes-since` counts from.
     *                                                                       Defaults to the snapshot's.
     * @param (callable(): list<Item>)|null                         $headFilters The code section's head filter items.
     *                                                                       Defaults to the stored snapshot's; none
     *                                                                       when nothing is stored.
     */
    public function __construct(?RouteSource $routes = null, ?LayoutSource $layout = null, ?Locks $locks = null, ?ClientAccess $access = null, ?callable $routeRefs = null, ?callable $storedHash = null, ?callable $headFilters = null)
    {
        $this->routes = $routes ?? new WordPressRouteSource();
        $this->layout = $layout ?? new WordPressLayoutSource();
        $this->locks = $locks ?? Locks::forSite();
        $this->access = $access ?? ClientAccess::forSite();
        $this->routeRefs = $routeRefs !== null
            ? Closure::fromCallable($routeRefs)
            : static fn (): ?array => self::storedRouteRefs();
        $this->storedHash = $storedHash !== null
            ? Closure::fromCallable($storedHash)
            : static fn (): ?string => (new SnapshotStore())->snapshot()['hash'] ?? null;
        $this->headFilters = $headFilters !== null
            ? Closure::fromCallable($headFilters)
            : static fn (): array => self::storedHeadFilters();
        $this->anchors = new Anchors($this->layout, $this->locks);
    }

    /**
     * The head filter items of the stored snapshot's code section. Empty
     * when nothing is stored, or the snapshot predates the section.
     *
     * @return list<Item>
     */
    public static function storedHeadFilters(): array
    {
        $snapshot = (new SnapshotStore())->snapshot();
        $sections = $snapshot !== null && is_array($snapshot['body']['sections'] ?? null) ? $snapshot['body']['sections'] : [];
        $filters = [];

        foreach ($sections as $section) {
            if (!is_array($section) || ($section['name'] ?? null) !== CodeScanner::SECTION || !is_array($section['items'] ?? null)) {
                continue;
            }

            foreach ($section['items'] as $row) {
                if (!is_array($row) || ($row['kind'] ?? null) !== HeadFilters::KIND || !is_string($row['id'] ?? null)) {
                    continue;
                }

                try {
                    $filters[] = new Item(
                        $row['id'],
                        HeadFilters::KIND,
                        is_string($row['label'] ?? null) ? $row['label'] : '',
                        is_array($row['facts'] ?? null) ? $row['facts'] : [],
                        null,
                        is_array($row['refs'] ?? null) ? array_values(array_filter($row['refs'], 'is_string')) : []
                    );
                } catch (InvalidArgumentException $e) {
                    continue;
                }
            }
        }

        return $filters;
    }

    /**
     * Route id => refs, from the stored snapshot's routes section, so find
     * counts routes without scanning them. Null when nothing is stored.
     *
     * @return array<string, list<string>>|null
     */
    public static function storedRouteRefs(): ?array
    {
        $snapshot = (new SnapshotStore())->snapshot();
        $sections = $snapshot !== null && is_array($snapshot['body']['sections'] ?? null) ? $snapshot['body']['sections'] : null;

        if ($sections === null) {
            return null;
        }

        $refs = [];

        foreach ($sections as $section) {
            if (!is_array($section) || ($section['name'] ?? null) !== RouteScanner::SECTION || !is_array($section['items'] ?? null)) {
                continue;
            }

            foreach ($section['items'] as $item) {
                if (is_array($item) && is_string($item['id'] ?? null) && is_array($item['refs'] ?? null)) {
                    $refs[$item['id']] = array_values(array_filter($item['refs'], 'is_string'));
                }
            }
        }

        return $refs;
    }

    /**
     * @return Result
     */
    public function find(string $url, ?string $text = null, ?string $class = null): array
    {
        $text = $text !== null && Ranking::normalize($text) !== '' ? $text : null;
        $class = $class !== null && ClassIndex::normalize($class) !== '' ? ClassIndex::normalize($class) : null;
        $route = (new RouteScanner($this->routes, $this->layout))->routeFor($url);

        $result = [
            'url'      => RouteScanner::normalizePath($url),
            'route'    => null,
            'template' => null,
            'text'     => $text,
            'class'    => $class,
            'matched'   => null,
            'ambiguous' => false,
            'message'   => null,
            'chain'     => [],
            'hits'      => [],
            'verify'    => ['changes_since' => ($this->storedHash)(), 'expect_changed' => [], 'recheck' => null],
        ];

        if ($route === null) {
            $result['message'] = 'No route matches this URL. Wayfinder resolves the front page, the posts page, published posts and pages, and post type archives.';

            return $result;
        }

        $this->anchors = new Anchors($this->layout, $this->locks);
        $this->classCounts = [];
        $layout = (new LayoutScanner($this->layout, $this->locks, $this->access))->scan();
        $builder = RouteScanner::builder($this->layout);
        $graph = RouteScanner::graph($layout);
        $post = $route['post'];

        $items = [];

        foreach ($layout->items as $item) {
            $items[$item->id] = $item;
        }

        /** @var array<string, list<BlockNode>> $trees */
        $trees = [];
        $contentClasses = [];
        $contentLocked = [];

        if ($post !== null) {
            $built = $builder->build(parse_blocks($post['content']));
            $trees[RouteScanner::CONTENT] = $built['tree'];
            $contentClasses = ClassIndex::facts($built['summary']['classes'], $built['summary']['names']);
            $contentLocked = $this->locks->lockedBlocks($built['tree'], $this->rootState(RouteScanner::CONTENT, $items, $post));
            $graph[RouteScanner::CONTENT] = array_values(array_filter(
                RouteScanner::refsIn($trees[RouteScanner::CONTENT]),
                static fn (string $ref): bool => isset($graph[$ref])
            ));
        }

        $template = $route['template'] !== null && isset($items['tpl:' . $route['template']]) ? 'tpl:' . $route['template'] : null;

        // The template places the content (core/post-content), so the content hangs off it.
        if ($template !== null && $post !== null) {
            array_unshift($graph[$template], RouteScanner::CONTENT);
        }

        $root = $template ?? ($post !== null ? RouteScanner::CONTENT : null);
        $walk = RouteScanner::walk($graph, $root !== null ? [$root] : []);
        $searching = $text !== null || $class !== null;
        $found = [];

        if ($searching) {
            $query = $text !== null ? Ranking::query($text, self::placeWords($items)) : null;
            $ranked = Ranking::rank(
                $this->collect($walk, $trees, $builder, $items, $post, $query, $class),
                $query !== null && $this->namesPage($query)
            );
            $found = $ranked['order'];
            $result['ambiguous'] = $ranked['ambiguous'];

            // The post content is `pt:<post_type>` in the chain, and so in the hits.
            foreach ($ranked['hits'] as $i => $hit) {
                if ($hit['id'] === RouteScanner::CONTENT && $post !== null) {
                    $hit['id'] = 'pt:' . $post['type'];
                }

                $result['hits'][] = $hit + ['anchor' => self::anchorOf($found[$i], $items, $post)];
            }
        }

        // The chain follows the best hit this URL shows. Ranking puts on-route hits first.
        $top = $found[0] ?? null;
        $top = $top !== null && $top['on_route'] ? $top : null;
        $topAnchor = $top !== null ? self::anchorOf($top, $items, $post) : null;
        $result['verify']['recheck'] = $topAnchor;

        $where = $walk['parent'];
        $matched = [];

        if ($top !== null) {
            $ids = [$top['id']];
            $matched[$top['id']] = true;

            if ($top['id'] !== $top['in']) {
                $where[$top['id']] = $top['in'];
            }

            $at = $top['id'] !== $top['in'] ? $top['in'] : ($walk['parent'][$top['in']] ?? null);

            while ($at !== null) {
                $ids[] = $at;
                $at = $walk['parent'][$at] ?? null;
            }
        } else {
            $ids = $walk['order'];

            // The content is what this URL alone shows, so it leads.
            if (in_array(RouteScanner::CONTENT, $ids, true)) {
                $ids = [RouteScanner::CONTENT, ...array_diff($ids, [RouteScanner::CONTENT])];
            }

            $ids = self::withOptionReads($ids, $items, $where);
        }

        $ids[] = $route['id'];

        $result['route'] = $route['id'];
        $result['template'] = $template;

        $builderDoc = $post['builder'] ?? null;

        if ($searching) {
            $result['matched'] = $top !== null;

            if ($top === null) {
                $result['message'] = $builderDoc !== null
                    ? self::builderMessage($builderDoc, $post)
                    : self::noMatchMessage($text, $class);
            }
        }

        foreach (array_values(array_unique($ids)) as $id) {
            $entry = $this->entry($id, $route, $items, $where, isset($matched[$id]));
            $lock = $top !== null && $top['id'] === $id ? $top['lock'] : $this->containerLock($id, $route, $items);
            $entry = $entry + ['lock' => $lock] + $this->access->verdict($entry['edit'], $lock);

            if ($id === RouteScanner::CONTENT && $post !== null) {
                $entry += $contentClasses + ['locked_blocks' => $contentLocked];
            }

            if ($top !== null && $topAnchor !== null && $top['id'] === $id) {
                $entry['anchor'] = $topAnchor;
            }

            $classCount = $this->classCount($id);

            if ($class !== null && isset($matched[$id]) && $classCount !== null) {
                $entry['class_count'] = $classCount;
            }

            $result['chain'][] = $entry;
        }

        // Filters that change this URL's head go just before the route, the last entry.
        $head = $this->headEntries($route);

        if ($head !== []) {
            $routeEntry = array_pop($result['chain']);
            $result['chain'] = [...$result['chain'], ...$head, $routeEntry];
        }

        // The page's own content is a builder document Wayfinder cannot read, so a miss is not an answer.
        if ($builderDoc !== null) {
            $result['coverage'] = Builders::PARTIAL;
            $result['reason'] = Builders::REASON;
        }

        return $result;
    }

    /**
     * Every home of a match: first the containers this URL's route walks,
     * then every other template, part, pattern and menu, then every other
     * published post. Each block that matches is one hit; so is a bound
     * attribute whose value matches (on the binding) and a post title.
     *
     * @param array{order: list<string>, parent: array<string, string>} $walk
     * @param array<string, list<BlockNode>>                            $trees
     * @param array<string, Item>                                        $items
     * @param RoutePost|null                                             $post
     * @param Query|null                                                 $query
     * @param string|null                                                $class Normalized.
     * @return list<Found>
     */
    private function collect(array $walk, array $trees, BlockTreeBuilder $builder, array $items, ?array $post, ?array $query, ?string $class): array
    {
        $routes = self::routeCounts(($this->routeRefs)());
        $hints = self::hintsFor($items, $query);
        $code = self::codeOnly($items);
        $reads = array_filter(array_map(self::optionReads(...), $items));
        $found = [];

        $depth = [];
        foreach ($walk['order'] as $id) {
            $depth[$id] = self::depth($id, $walk['parent']);
        }

        foreach ($walk['order'] as $container) {
            $tree = $trees[$container] ?? $this->tree($container, $builder);

            if ($tree === null) {
                continue;
            }

            $scope = [
                'on_route'     => true,
                'depth'        => $depth,
                'post'         => $post,
                'routes'       => $routes,
                'hints'        => $hints,
                'code'         => $code,
                'reads'        => $reads,
                'content_type' => $container === RouteScanner::CONTENT && $post !== null ? $post['type'] : null,
                'post_id'      => $container === RouteScanner::CONTENT && $post !== null ? $post['id'] : null,
            ];
            $this->search($tree, $container, $query, $class, $scope, $this->rootState($container, $items, $post), $found);

            if ($container === RouteScanner::CONTENT && $post !== null && $query !== null && $class === null) {
                $tier = Ranking::textTier($post['title'], $query);

                if ($tier !== null) {
                    $found[] = self::hit($container, $container, null, $post['title'], $tier, $scope, Locks::ofState($this->rootState($container, $items, $post)));
                }
            }
        }

        $offRoute = ['on_route' => false, 'depth' => [], 'post' => null, 'routes' => $routes, 'hints' => $hints, 'code' => $code, 'reads' => $reads, 'content_type' => null, 'post_id' => null];

        foreach ($items as $id => $item) {
            if (isset($depth[$id]) || !in_array($item->kind, ['template', 'template_part', 'pattern', 'navigation'], true)) {
                continue;
            }

            $tree = $this->tree($id, $builder);

            if ($tree !== null) {
                $this->search($tree, $id, $query, $class, $offRoute, $this->rootState($id, $items, null), $found);
            }
        }

        foreach ($this->layout->contentMatching(self::needles($query, $class)) as $row) {
            if ($post !== null && $row['slug'] === $post['slug'] && $row['type'] === $post['type']) {
                continue;
            }

            $tree = $builder->build(parse_blocks($row['content']))['tree'];
            $scope = ['content_type' => $row['type'], 'post_id' => $row['id'] ?? null] + $offRoute;
            $this->search($tree, $row['type'] . ':' . $row['slug'], $query, $class, $scope, $this->locks->root(Locks::CONTENT, $row['type']), $found);
        }

        return $found;
    }

    /**
     * What to ask the database for when looking for content off the route:
     * the class, the query's longest word, and for a digit-heavy query its
     * last four digits, which survive any separator. The real match is made
     * in PHP; these only narrow the rows read.
     *
     * @param Query|null  $query
     * @param string|null $class Normalized.
     * @return list<string>
     */
    private static function needles(?array $query, ?string $class): array
    {
        $needles = $class !== null ? [$class] : [];

        if ($query === null) {
            return $needles;
        }

        if ($query['digits'] !== null) {
            $needles[] = substr($query['digits'], -4);

            return $needles;
        }

        $words = $query['words'];
        usort($words, static fn (string $a, string $b): int => [mb_strlen($b), $a] <=> [mb_strlen($a), $b]);
        $needles[] = $words[0] ?? $query['text'];

        return array_values(array_unique(array_filter($needles, static fn (string $needle): bool => $needle !== '')));
    }

    /**
     * Adds a hit for each block in the tree that matches. With text and a
     * class, a block must match both, and a bound value counts only from a
     * block that carries the class. Pointer blocks are not searched for
     * text, which lives in the container they point at, but their own class
     * sits in this markup and counts here. On the route, blocks carrying the
     * class are counted per container.
     *
     * @param list<BlockNode> $nodes
     * @param Query|null      $query
     * @param string|null     $class Normalized.
     * @param Scope           $scope
     * @param State           $state
     * @param list<Found>     $found
     */
    private function search(array $nodes, string $container, ?array $query, ?string $class, array $scope, array $state, array &$found): void
    {
        foreach ($nodes as $node) {
            $classOk = $class === null || ClassIndex::matches($node, $class);
            $lock = $this->locks->block($node, $state);

            if ($node->reference !== null) {
                if ($class !== null && $query === null && $classOk) {
                    $found[] = self::hit($container, $container, $node, $node->label, Ranking::TIER_EXACT, $scope, $lock);
                    $this->countClass($container, $scope);
                }

                continue;
            }

            $bound = BlockRefs::bindingsOf($node);

            if ($query !== null && $classOk) {
                foreach ($bound as $attribute => $binding) {
                    $value = $this->routes->bindingValue($binding['source'], $binding['args'], $node->name, $attribute, $scope['post']);
                    $tier = $value !== null ? Ranking::textTier($value, $query) : null;

                    // The value is compared, never returned: the snippet names the binding.
                    if ($tier !== null) {
                        $found[] = self::hit($binding['id'], $container, $node, 'bound: ' . $binding['id'], $tier, $scope, $lock);
                    }
                }
            }

            $tier = $classOk ? $this->blockTier($node, array_keys($bound), $query) : null;
            $shown = $tier !== null && $query !== null ? $this->optionsShowing($scope['reads'][$container] ?? [], $tier[1], $query) : [];

            // The text is an option the pattern prints, so the option is its home, edited on a screen, not in code.
            foreach ($shown as $read) {
                $found[] = ['code_only' => false] + self::hit($read, $container, $node, $tier[1], $tier[0], $scope, $lock);
            }

            if ($tier !== null && $shown === []) {
                $found[] = self::hit($container, $container, $node, $tier[1], $tier[0], $scope, $lock);
            }

            if ($tier !== null && $class !== null) {
                $this->countClass($container, $scope);
            }

            $this->search($node->children, $container, $query, $class, $scope, $this->locks->inner($node, $state), $found);
        }
    }

    /**
     * The best tier a block's own text or text attributes match at, with
     * the text that matched, or null. With no text asked, a block that
     * carries the class matches at the top tier.
     *
     * @param list<string> $bound Attributes a binding replaces.
     * @param Query|null   $query
     * @return array{0: int, 1: string}|null
     */
    private function blockTier(BlockNode $node, array $bound, ?array $query): ?array
    {
        if ($query === null) {
            return [Ranking::TIER_EXACT, $node->text !== '' ? $node->text : $node->label];
        }

        $best = null;
        $ownText = array_intersect($bound, self::TEXT_BINDINGS) === [] ? $node->text : '';
        $tier = Ranking::textTier($ownText, $query);

        if ($tier !== null) {
            $best = [$tier, $ownText];
        }

        foreach ($node->attrs as $name => $value) {
            if (!is_string($value) || in_array($name, $bound, true)) {
                continue;
            }

            $tier = Ranking::attributeTier((string) $name, $value, $query);

            if ($tier !== null && ($best === null || $tier < $best[0])) {
                $best = [$tier, $value];
            }
        }

        return $best;
    }

    /**
     * One hit. Its distance counts the binding as a step below the
     * container it sits in; its routes count how many route items reach it,
     * the post content through its post type. A hit on a block carries the
     * block's path and fingerprint; the post title has neither.
     *
     * @param Scope $scope
     * @param Lock  $lock
     * @return Found
     */
    private static function hit(string $id, string $container, ?BlockNode $node, string $text, int $tier, array $scope, array $lock): array
    {
        $distance = $scope['on_route'] ? ($scope['depth'][$container] ?? 0) + ($id !== $container ? 1 : 0) : null;
        $routeKey = $scope['content_type'] !== null && $id === $container ? 'pt:' . $scope['content_type'] : $id;

        return [
            'id'        => $id,
            'in'        => $container,
            'path'      => $node?->indexPath,
            'snippet'   => Ranking::snippet($text),
            'tier'      => $tier,
            'on_route'  => $scope['on_route'],
            'distance'  => $distance,
            'routes'    => $scope['routes'] !== null ? ($scope['routes'][$routeKey] ?? 0) : null,
            'hint'      => $scope['hints'][$container] ?? null,
            'code_only' => isset($scope['code'][$container]),
            'lock'      => $lock,
            'fp'        => $node !== null ? BlockFingerprint::of($node) : null,
            'post'      => $scope['post_id'],
            'type'      => $scope['content_type'],
        ];
    }

    /**
     * The anchor of a hit on a block: the item that holds the block, or the
     * post's content as `pt:<post_type>` with its id. Null for the post
     * title, which is not a block.
     *
     * @param Found               $hit
     * @param array<string, Item> $items
     * @param RoutePost|null      $post
     * @return Anchor|null
     */
    private static function anchorOf(array $hit, array $items, ?array $post): ?array
    {
        $fp = $hit['fp'] ?? null;

        if ($hit['path'] === null || $fp === null) {
            return null;
        }

        $type = $hit['type'] ?? null;

        if ($type !== null) {
            return Anchors::make('pt:' . $type, $hit['post'] ?? null, $hit['path'], $fp, null);
        }

        $entity = $hit['in'] === RouteScanner::CONTENT && $post !== null ? 'pt:' . $post['type'] : $hit['in'];

        return Anchors::make($entity, null, $hit['path'], $fp, isset($items[$entity]) ? $items[$entity]->hash : null);
    }

    /**
     * @param Scope $scope
     */
    private function countClass(string $container, array $scope): void
    {
        if ($scope['on_route']) {
            $this->classCounts[$container] = ($this->classCounts[$container] ?? 0) + 1;
        }
    }

    /**
     * A container's block tree, from its markup, or null for one with none.
     *
     * @return list<BlockNode>|null
     */
    private function tree(string $id, BlockTreeBuilder $builder): ?array
    {
        $markup = $this->anchors->markup($id);

        return $markup !== null ? $builder->build(parse_blocks($markup))['tree'] : null;
    }

    /**
     * Steps from the content: the content is 0, the template that places
     * it 1, and each container one more than what holds it.
     *
     * @param array<string, string> $parent
     */
    private static function depth(string $id, array $parent): int
    {
        if ($id === RouteScanner::CONTENT) {
            return 0;
        }

        $up = $parent[$id] ?? null;

        if ($up === null) {
            return 1;
        }

        return $up === RouteScanner::CONTENT ? 1 : self::depth($up, $parent) + 1;
    }

    /**
     * For each id, how many route items reach it.
     *
     * @param array<string, list<string>>|null $routeRefs
     * @return array<string, int>|null
     */
    private static function routeCounts(?array $routeRefs): ?array
    {
        if ($routeRefs === null) {
            return null;
        }

        $counts = [];

        foreach ($routeRefs as $refs) {
            foreach (array_unique($refs) as $ref) {
                $counts[$ref] = ($counts[$ref] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Words in the query that may name a place: every template part's area,
     * slug and title words.
     *
     * @param array<string, Item> $items
     * @return list<string>
     */
    private static function placeWords(array $items): array
    {
        $words = [];

        foreach ($items as $item) {
            if ($item->kind !== 'template_part') {
                continue;
            }

            foreach ([$item->facts['area'] ?? null, $item->facts['slug'] ?? null, $item->label] as $value) {
                if (is_string($value) && $value !== '') {
                    array_push($words, ...explode(' ', Ranking::normalize(str_replace(['-', '_'], ' ', $value))));
                }
            }
        }

        return array_values(array_unique(array_filter($words, static fn (string $word): bool => strlen($word) > 2)));
    }

    /**
     * Container id => the hint word it answers, for the hints in the query.
     * A part answers to its area, slug and title words; a menu to "menu" and
     * "navigation"; a sidebar to "sidebar".
     *
     * @param array<string, Item> $items
     * @param Query|null          $query
     * @return array<string, string>
     */
    private static function hintsFor(array $items, ?array $query): array
    {
        $hints = [];
        $asked = $query['hints'] ?? [];

        foreach ($items as $id => $item) {
            $names = match ($item->kind) {
                'template_part' => array_merge(
                    ...array_map(
                        static fn (mixed $value): array => is_string($value) ? explode(' ', Ranking::normalize(str_replace(['-', '_'], ' ', $value))) : [],
                        [$item->facts['area'] ?? null, $item->facts['slug'] ?? null, $item->label]
                    )
                ),
                'navigation', 'menu_location' => ['menu', 'navigation'],
                'sidebar'       => ['sidebar'],
                default         => [],
            };

            $hit = array_values(array_intersect($asked, $names));

            if ($hit !== []) {
                $hints[$id] = $hit[0];
            }
        }

        return $hints;
    }

    /**
     * Homes edited in code only: a template, part or pattern with no edit
     * screen (a classic theme's PHP file, a registered pattern).
     *
     * @param array<string, Item> $items
     * @return array<string, true>
     */
    private static function codeOnly(array $items): array
    {
        $code = [];

        foreach ($items as $id => $item) {
            if ($item->edit === null && in_array($item->kind, ['template', 'template_part', 'pattern'], true)) {
                $code[$id] = true;
            }
        }

        return $code;
    }

    /**
     * Whether the query names a published page by its slug words, which
     * asks for the home few routes reach.
     *
     * @param Query $query
     */
    private function namesPage(array $query): bool
    {
        foreach ($this->layout->content() as $row) {
            $name = Ranking::normalize(str_replace(['-', '_'], ' ', $row['slug']));

            if ($row['type'] === 'page' && strlen($name) > 2 && str_contains(' ' . $query['raw'] . ' ', ' ' . $name . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why nothing matched, in the shape the text search has always used.
     */
    private static function noMatchMessage(?string $text, ?string $class): string
    {
        $what = match (true) {
            $text !== null && $class !== null => 'No block on this page contains that text and carries that class or block name.',
            $class !== null                   => 'No block on this page carries that class or block name.',
            default                           => 'No block on this page contains that text.',
        };

        return $what . ' It may come from PHP, a plugin or a dynamic block. ' . self::WHOLE_PAGE;
    }

    /**
     * Why nothing matched on a page builder's document: Wayfinder never reads
     * the builder's own data, so a miss there means "not seen", not "absent".
     *
     * @param BuilderDoc $builder
     * @param RoutePost  $post
     */
    private static function builderMessage(array $builder, array $post): string
    {
        return sprintf(
            'Nothing Wayfinder reads on this page matched. The %s is %s document, and Wayfinder does not read inside builder documents, so the text is most likely in it. Edit it in %s: %s',
            self::words($post['type']),
            self::withArticle($builder['label']),
            $builder['label'],
            $builder['edit']
        );
    }

    /**
     * The state at the top of a container as its edit path opens it: the
     * post type's lock for the post content, content only on 7.0 for a
     * registered pattern, and isolated (unlocked) for a part or a synced
     * pattern, which the editor opens on its own.
     *
     * @param array<string, Item> $items
     * @param RoutePost|null      $post
     * @return State
     */
    private function rootState(string $container, array $items, ?array $post): array
    {
        if ($container === RouteScanner::CONTENT) {
            return $this->locks->root(Locks::CONTENT, $post['type'] ?? null);
        }

        $item = $items[$container] ?? null;
        $synced = ($item?->facts['synced'] ?? false) === true;

        return match (true) {
            $item?->kind === 'template_part' && $item->edit !== null => $this->locks->root(Locks::TEMPLATE_PART),
            $item?->kind === 'pattern' && $synced                    => $this->locks->root(Locks::SYNCED_PATTERN),
            $item?->kind === 'pattern'                               => $this->locks->root(Locks::UNSYNCED_PATTERN),
            default                                                  => Locks::unlocked(),
        };
    }

    /**
     * How many blocks in a hit carry the class, as collect() counted them on this route.
     */
    private function classCount(string $id): ?int
    {
        return $this->classCounts[$id] ?? null;
    }

    /**
     * An entry's lock when no block in it matched: its container's state
     * as a whole. A route, a binding, and a classic PHP file hold no blocks
     * of their own and are not locked.
     *
     * @param Route               $route
     * @param array<string, Item> $items
     * @return Lock
     */
    private function containerLock(string $id, array $route, array $items): array
    {
        $kind = $id === RouteScanner::CONTENT ? 'content' : ($items[$id]->kind ?? null);
        $classic = $id !== RouteScanner::CONTENT && ($items[$id]->edit ?? null) === null && $kind !== 'pattern';

        if ($id === $route['id'] || $kind === null || $kind === 'binding' || $classic) {
            return ['effective' => 'none', 'src' => 'none', 'text_editable' => false];
        }

        return Locks::ofState($this->rootState($id, $items, $route['post']));
    }

    /**
     * @param Route                 $route
     * @param array<string, Item>   $items
     * @param array<string, string> $where Id => the container it sits in.
     * @return Base
     */
    private function entry(string $id, array $route, array $items, array $where, bool $match): array
    {
        $post = $route['post'];

        if ($id === RouteScanner::CONTENT && $post !== null && isset($post['builder'])) {
            $builder = $post['builder'];

            return [
                'id'          => 'pt:' . $post['type'],
                'label'       => ucfirst(self::words($post['type'])) . ' content: ' . $post['title'],
                'edit'        => $builder['edit'],
                'instruction' => sprintf(
                    'This %s, "%s", is %s document. Its content is built in %s, which Wayfinder does not read, so text not found here may still be on it. Edit it in %s: %s',
                    self::words($post['type']),
                    $post['title'],
                    self::withArticle($builder['label']),
                    $builder['label'],
                    $builder['label'],
                    $builder['edit']
                ),
                'match'       => $match,
                'builder'     => $builder['builder'],
            ];
        }

        if ($id === RouteScanner::CONTENT && $post !== null) {
            $edit = self::postEdit($post);
            $template = $route['template'] !== null ? ($items['tpl:' . $route['template']] ?? null) : null;

            return [
                'id'          => 'pt:' . $post['type'],
                'label'       => ucfirst(self::words($post['type'])) . ' content: ' . $post['title'],
                'edit'        => $edit,
                'instruction' => sprintf('The title and content of this %s, "%s". Edit them here: %s', self::words($post['type']), $post['title'], $edit)
                    . ($template !== null ? self::unshown($template, self::words($post['type'])) : ''),
                'match'       => $match,
            ];
        }

        if ($id === $route['id']) {
            return [
                'id'          => $id,
                'label'       => $route['label'],
                'edit'        => $route['edit'],
                'instruction' => self::routeInstruction($route),
                'match'       => $match,
            ];
        }

        if (str_starts_with($id, 'option:')) {
            return self::optionEntry($id, $items[$where[$id] ?? ''] ?? null, $match);
        }

        $item = $items[$id] ?? null;

        if ($item === null) {
            return ['id' => $id, 'label' => $id, 'edit' => null, 'instruction' => 'Not in the manifest.', 'match' => $match];
        }

        $entry = ['id' => $id, 'label' => $item->label, 'edit' => $item->edit, 'instruction' => '', 'match' => $match];

        switch ($item->kind) {
            case 'template':
                $entry['instruction'] = sprintf('Template "%s", used for this URL and every other one it matches. %s', $item->label, self::templateEdit($item));
                break;

            case 'template_part':
                $entry['instruction'] = sprintf('The %s template part, shared by every template that includes it. %s', mb_strtolower($item->label), self::templateEdit($item));
                break;

            case 'navigation':
                $entry['instruction'] = sprintf('The %s menu. Edit it here: %s', $item->label, $item->edit);
                break;

            case 'pattern':
                $entry = self::patternEntry($entry, $item);
                break;

            case 'binding':
                $entry = $this->bindingEntry($entry, $item, $route, $items, $where[$id] ?? null);
                break;

            default:
                $entry['instruction'] = $item->edit !== null ? 'Edit it here: ' . $item->edit : 'No edit screen known.';
        }

        return $entry;
    }

    /**
     * One chain entry per head filter that runs on this route: its
     * conditional tags name the route, or it checks none. A filter tied to
     * another route, or to a condition no route stands for, is left out.
     *
     * @param Route $route
     * @return list<Entry>
     */
    private function headEntries(array $route): array
    {
        $entries = [];
        $lock = ['effective' => 'none', 'src' => 'none', 'text_editable' => false];

        foreach (($this->headFilters)() as $filter) {
            $facts = $filter->facts;
            $traced = ($facts['traced'] ?? false) === true;
            $conditions = is_array($facts['conditions'] ?? null) ? $facts['conditions'] : [];
            $here = in_array($route['id'], $filter->refs, true);

            if (!$here && !(!$traced || $conditions === [])) {
                continue;
            }

            $entry = self::headEntry($filter, $here, $traced);
            $entries[] = $entry + ['lock' => $lock] + $this->access->verdict($entry['edit'], $lock);
        }

        return $entries;
    }

    /**
     * @return Base
     */
    private static function headEntry(Item $filter, bool $here, bool $traced): array
    {
        $facts = $filter->facts;
        $changes = is_string($facts['changes'] ?? null) ? $facts['changes'] : 'head';
        $callback = is_string($facts['callback'] ?? null) ? $facts['callback'] : $filter->id;
        $file = is_string($facts['file'] ?? null) ? $facts['file'] : 'a file Wayfinder cannot place';
        $hook = is_string($facts['hook'] ?? null) ? $facts['hook'] : '';
        $appliedBy = is_string($facts['applied_by'] ?? null) ? ', which plugin:' . $facts['applied_by'] . ' applies' : '';
        $owner = is_array($facts['owner'] ?? null) ? ($facts['owner']['kind'] ?? '') : '';
        $where = $here ? 'on this page' : 'on every page it runs on';
        $edit = null;

        if (!$traced) {
            $instruction = sprintf(
                'The %s %s may be changed by the %s filter %s in %s (hook %s%s). Wayfinder does not read plugin code, so what it sets is not traced.',
                $changes,
                $where,
                $owner !== '' ? $owner : 'code',
                $callback,
                $file,
                $hook,
                $appliedBy
            );
        } else {
            $reads = is_array($facts['reads'] ?? null) ? array_values(array_filter($facts['reads'], 'is_string')) : [];
            $option = count($reads) === 1 && str_starts_with($reads[0], 'option:') ? substr($reads[0], 7) : null;
            $screen = $option !== null ? (OptionAllowlist::OPTIONS[$option] ?? null) : null;

            $instruction = sprintf('The %s %s is set by the %s filter %s in %s (hook %s%s).', $changes, $where, $owner !== '' ? $owner : 'code', $callback, $file, $hook, $appliedBy);

            if (($facts['returns_fixed_text'] ?? false) === true) {
                $instruction .= ' It returns fixed text typed into that file, so change it there; no settings screen changes it.';
            } elseif ($screen !== null) {
                $edit = $screen[1];
                $instruction .= sprintf(' It reads the option %s ("%s"). Change that setting, not the file: %s', $option, $screen[0], $edit);
            } elseif ($reads !== []) {
                $instruction .= ' It reads ' . implode(', ', $reads) . '. Where that is edited is not traced.';
            } else {
                $instruction .= ' What it returns is worked out in that file.';
            }
        }

        return ['id' => $filter->id, 'label' => $filter->label, 'edit' => $edit, 'instruction' => $instruction, 'match' => false];
    }

    /**
     * A block template or part is edited in the Site Editor. A classic
     * theme's PHP file has no edit screen, only its file, and the links it
     * hard codes are changed there too.
     */
    private static function templateEdit(Item $item): string
    {
        if ($item->edit !== null) {
            return 'Edit it in the Site Editor: ' . $item->edit;
        }

        $file = $item->facts['file'] ?? null;
        $text = is_string($file) ? 'It is the theme file ' . $file . ', edited in code.' : 'It is a theme file, edited in code.';
        $links = is_array($item->facts['links'] ?? null) ? array_values(array_filter($item->facts['links'], 'is_string')) : [];

        if ($links !== []) {
            $text .= sprintf(' It hard codes links to %s in the file; no field or setting changes them.', implode(', ', $links));
        }

        return $text;
    }

    /**
     * For a classic template that never prints the post's title or content
     * (`shows_post`, see ClassicTheme::showsPost()): a sentence saying so,
     * and naming the fields and options the template reads instead. Empty
     * when it prints both, or the template is not a classic file.
     */
    private static function unshown(Item $template, string $noun): string
    {
        $shows = $template->facts['shows_post'] ?? null;

        if (!is_array($shows)) {
            return '';
        }

        $missing = array_values(array_diff(['title', 'content'], $shows));

        if ($missing === []) {
            return '';
        }

        $reads = is_array($template->facts['reads'] ?? null) ? array_values(array_filter($template->facts['reads'], 'is_string')) : [];
        $file = is_string($template->facts['file'] ?? null) ? $template->facts['file'] : $template->id;

        return sprintf(
            '. Its template, %s, never shows this %s\'s %s',
            $file,
            $noun,
            implode(' or ', $missing)
        ) . ($reads !== [] ? sprintf(': what the %s shows comes from %s', $noun, implode(', ', $reads)) : '') . '.';
    }

    /**
     * @param Base $entry
     * @return Base
     */
    private static function patternEntry(array $entry, Item $item): array
    {
        if (($item->facts['registered'] ?? false) === true) {
            $entry['instruction'] = self::registeredInstruction($item);

            return $entry;
        }

        if (($item->facts['synced'] ?? false) !== true) {
            $entry['instruction'] = sprintf('Pattern "%s" is registered in code by the theme or a plugin, so it has no edit screen. Change it in code, or replace it with a synced pattern.', $item->label);

            return $entry;
        }

        /** @var list<string> $usedIn */
        $usedIn = is_array($item->stats['used_in'] ?? null) ? $item->stats['used_in'] : [];
        $count = is_int($item->stats['usage_count'] ?? null) ? $item->stats['usage_count'] : count($usedIn);

        $named = implode(', ', array_slice($usedIn, 0, self::USAGES_NAMED));

        if ($count > self::USAGES_NAMED) {
            $named .= sprintf(' and %d more', $count - self::USAGES_NAMED);
        }

        $entry['instruction'] = sprintf('Synced pattern "%s". One edit here changes every usage (%d): %s. Edit it here: %s', $item->label, $count, $named, $item->edit);
        $entry['used_in'] = $usedIn;
        $entry['usage_count'] = $count;

        return $entry;
    }

    /**
     * A registered pattern has no edit screen. It names its theme file, and
     * for each option the file prints, where that option is edited (or that
     * nobody traced it) and whether it is empty now, so a value it reads is
     * never sent to the code.
     */
    private static function registeredInstruction(Item $item): string
    {
        $file = is_string($item->facts['file'] ?? null) ? $item->facts['file'] : null;
        $text = $file !== null
            ? sprintf('Pattern "%s" is registered in code, built by the theme file %s, so it has no edit screen.', $item->label, $file)
            : sprintf('Pattern "%s" is registered in code by the theme or a plugin, so it has no edit screen.', $item->label);

        /** @var array<string, list<string>> $groups Edit path ('' when not traced) => option names. */
        $groups = [];

        foreach (self::optionReads($item) as $read) {
            $screen = self::screenOf($item, $read);
            $label = $screen['label'] ?? null;
            $groups[$screen['edit'] ?? ''][] = substr($read, 7) . ($label !== null ? ' ("' . $label . '")' : '');
        }

        if ($groups === []) {
            return $text . sprintf(' Change it in %s, or replace it with a synced pattern.', $file !== null ? 'that file' : 'code');
        }

        foreach ($groups as $edit => $names) {
            $text .= $edit !== ''
                ? sprintf(' It prints the option%s %s, edited here: %s. Change %s there, not in %s.', count($names) === 1 ? '' : 's', self::andList($names), $edit, count($names) === 1 ? 'that value' : 'those values', $file !== null ? 'the file' : 'code')
                : sprintf(' It prints the option%s %s; where %s edited is not traced.', count($names) === 1 ? '' : 's', self::andList($names), count($names) === 1 ? 'it is' : 'they are');
        }

        $empty = array_values(array_intersect(self::optionReads($item), is_array($item->stats['empty_reads'] ?? null) ? $item->stats['empty_reads'] : []));

        if ($empty !== []) {
            $text .= sprintf(' Empty now, so not shown until set: %s.', self::andList(array_map(static fn (string $read): string => substr($read, 7), $empty)));
        }

        return $text . sprintf(' Change the rest of the pattern in %s, or replace it with a synced pattern.', $file !== null ? 'that file' : 'code');
    }

    /**
     * The chain entry of an option a registered pattern prints: the option's
     * screen when Wayfinder can name it (see OptionScreens), else no edit
     * path and an instruction saying so.
     *
     * @return Base
     */
    private static function optionEntry(string $read, ?Item $pattern, bool $match): array
    {
        $name = substr($read, 7);
        $screen = $pattern !== null ? self::screenOf($pattern, $read) : null;
        $label = $screen['label'] ?? null;
        $empty = $pattern !== null && is_array($pattern->stats['empty_reads'] ?? null) && in_array($read, $pattern->stats['empty_reads'], true);

        $what = sprintf('The option %s%s', $name, $label !== null ? ' ("' . $label . '")' : '');
        $what .= $pattern !== null ? sprintf(', which the "%s" pattern prints', $pattern->label) : '';

        $instruction = $screen !== null
            ? sprintf('%s. Edit it here: %s', $what, $screen['edit'])
            : sprintf('%s. Where it is edited is not traced: no settings screen Wayfinder knows saves it', $what);

        return [
            'id'          => $read,
            'label'       => $label ?? $name,
            'edit'        => $screen['edit'] ?? null,
            'instruction' => $instruction . ($empty ? '. It is empty now, so nothing from it shows until it is set.' : ($screen === null ? '.' : '')),
            'match'       => $match,
        ];
    }

    /**
     * The `option:<name>` ids a registered pattern's file reads, in order.
     *
     * @return list<string>
     */
    private static function optionReads(?Item $item): array
    {
        if ($item === null || $item->kind !== 'pattern' || ($item->facts['registered'] ?? false) !== true || !is_array($item->facts['reads'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $item->facts['reads'],
            static fn (mixed $read): bool => is_string($read) && str_starts_with($read, 'option:')
        ));
    }

    /**
     * Where one read of a pattern is edited, from its `read_screens`.
     *
     * @return array{label: string|null, edit: string}|null
     */
    private static function screenOf(Item $pattern, string $read): ?array
    {
        foreach (is_array($pattern->facts['read_screens'] ?? null) ? $pattern->facts['read_screens'] : [] as $screen) {
            if (is_array($screen) && ($screen['read'] ?? null) === $read && is_string($screen['edit'] ?? null)) {
                return ['label' => is_string($screen['label'] ?? null) ? $screen['label'] : null, 'edit' => $screen['edit']];
            }
        }

        return null;
    }

    /**
     * Each option a registered pattern on the page reads, right after the
     * first pattern that reads it, so the page's chain names where its
     * settings are edited. `$where` records that pattern for each.
     *
     * @param list<string>          $ids
     * @param array<string, Item>   $items
     * @param array<string, string> $where
     * @return list<string>
     */
    private static function withOptionReads(array $ids, array $items, array &$where): array
    {
        $out = [];

        foreach ($ids as $id) {
            $out[] = $id;

            foreach (self::optionReads($items[$id] ?? null) as $read) {
                if (!isset($where[$read])) {
                    $where[$read] = $id;
                    $out[] = $read;
                }
            }
        }

        return $out;
    }

    /**
     * The options a registered pattern reads that a matched block shows and
     * the query asks about: the query is in the option's value, and the
     * whole value is in the block's text, or for a digit-heavy query (a
     * phone number, which the pattern may print in another format) the
     * value's digits hold the query's. Values are compared, never returned.
     *
     * @param list<string> $reads `option:<name>` ids.
     * @param Query        $query
     * @return list<string>
     */
    private function optionsShowing(array $reads, string $text, array $query): array
    {
        $shown = [];
        $block = Ranking::normalize($text);

        foreach ($reads as $read) {
            $value = $this->layout->optionText(substr($read, 7));
            $value = $value !== null ? Ranking::normalize($value) : '';

            if ($value !== '' && Ranking::contains($value, $query) && ($query['digits'] !== null || str_contains($block, $value))) {
                $shown[] = $read;
            }
        }

        return $shown;
    }

    /**
     * Post meta (core's and `voyager/post-meta-text`) is edited on the post.
     * `voyager/site-data` takes the edit path of the plugin that owns it.
     * Other sources say where through the
     * `vwf_binding_edit_path` filter, already applied to the item's edit path
     * by LayoutScanner; with no answer the instruction asks for a note.
     *
     * @param Base                $entry
     * @param Route               $route
     * @param array<string, Item> $items
     * @return Base
     */
    private function bindingEntry(array $entry, Item $item, array $route, array $items, ?string $container): array
    {
        $key = is_string($item->facts['key'] ?? null) ? $item->facts['key'] : null;
        $source = is_string($item->facts['source'] ?? null) ? $item->facts['source'] : '';
        $sourceLabel = is_string($item->facts['source_label'] ?? null) ? $item->facts['source_label'] : $source;
        $what = ($key !== null ? ucfirst(self::words($key)) : 'Value') . self::place($container, $route, $items);
        $post = $route['post'];

        if (in_array($source, OptionScreens::POST_META_SOURCES, true)) {
            if ($post === null) {
                $entry['edit'] = null;
                $entry['instruction'] = sprintf('%s. It comes from the "%s" field of each post, edited on that post\'s screen.', $what, (string) $key);

                return $entry;
            }

            $entry['edit'] = self::postEdit($post);
            $entry['instruction'] = sprintf('%s. It comes from the "%s" field of this %s: %s', $what, (string) $key, self::words($post['type']), $entry['edit']);

            return $entry;
        }

        $entry['instruction'] = $item->edit !== null
            ? sprintf('%s. It comes from %s: %s', $what, $sourceLabel, $item->edit)
            : sprintf('%s, set by %s, edit location unknown, add an agency note.', $what, $sourceLabel);

        return $entry;
    }

    /**
     * " in the footer", " on this page" and so on, for the container a
     * binding sits in.
     *
     * @param Route               $route
     * @param array<string, Item> $items
     */
    private static function place(?string $container, array $route, array $items): string
    {
        if ($container === RouteScanner::CONTENT && $route['post'] !== null) {
            return ' on this ' . self::words($route['post']['type']);
        }

        $item = $container !== null ? ($items[$container] ?? null) : null;

        if ($item === null) {
            return '';
        }

        return match ($item->kind) {
            'template_part' => ' in the ' . mb_strtolower($item->label),
            'pattern'       => ' in the "' . $item->label . '" pattern',
            'navigation'    => ' in the ' . $item->label . ' menu',
            'template'      => ' in the "' . $item->label . '" template',
            default         => '',
        };
    }

    /**
     * @param Route $route
     */
    private static function routeInstruction(array $route): string
    {
        $post = $route['post'];

        if ($route['id'] === 'route:front-page') {
            return $post !== null
                ? sprintf('The home URL shows the page "%s", chosen in Settings > Reading: %s', $post['title'], (string) $route['edit'])
                : sprintf('The home URL lists the latest posts, as set in Settings > Reading: %s', (string) $route['edit']);
        }

        if ($route['id'] === 'route:posts-page') {
            return sprintf('This URL lists the latest posts. Settings > Reading picks the page: %s', (string) $route['edit']);
        }

        $served = $route['template'] !== null
            ? sprintf('WordPress serves %s with the %s template.', $route['path'], $route['template'])
            : sprintf('No block template matched %s.', $route['path']);

        return $route['edit'] !== null
            ? $served . ' The template is assigned in the page settings: ' . $route['edit']
            : $served;
    }

    /**
     * @param RoutePost $post
     */
    private static function postEdit(array $post): string
    {
        return 'post.php?post=' . $post['id'] . '&action=edit';
    }

    /**
     * `a`, `a and b`, `a, b and c`.
     *
     * @param non-empty-list<string> $names
     */
    private static function andList(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . ' and ' . $last;
    }

    /**
     * `an Elementor`, `a Divi`.
     */
    private static function withArticle(string $label): string
    {
        return (preg_match('/^[aeiou]/i', $label) === 1 ? 'an ' : 'a ') . $label;
    }

    private static function words(string $name): string
    {
        return str_replace(['_', '-'], ' ', $name);
    }
}
