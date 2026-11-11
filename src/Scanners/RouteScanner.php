<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Voyager\Wayfinder\Blocks\BlockNode;
use Voyager\Wayfinder\Blocks\BlockRefs;
use Voyager\Wayfinder\Blocks\BlockTreeBuilder;
use Voyager\Wayfinder\Blocks\ReferenceResolver;
use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;

/**
 * The `routes` section: which template and content WordPress serves for each
 * kind of URL, and everything that template pulls in.
 *
 * Routes are sampled, not listed: the front page, the posts page, one
 * published item per public post type (the one whose slug sorts first), each
 * post type archive, and each page assigned a template other than the
 * default.
 *
 * Template resolution follows a real request without making one. For each
 * route the scanner builds the candidate slugs core's template loader would
 * try (get_front_page_template, get_home_template, get_archive_template,
 * get_single_template, get_page_template, get_singular_template, then index,
 * as they apply) and passes them, as one list in that order, to
 * resolve_block_template(), the function locate_block_template() calls.
 * resolve_block_template() returns the first candidate that exists, so one
 * flattened list picks the same template as the loader trying each getter in
 * turn. On a classic theme the same list goes to locate_template() as PHP
 * file names (see ClassicTheme), which also returns the first one found.
 * Not covered: the `{$type}_template_hierarchy` filters, the Site Editor
 * templates of a classic theme that supports block templates, and paged,
 * search, taxonomy, author and date URLs.
 *
 * Refs are transitive, unlike the layout section's: the template, the parts,
 * patterns, menus and bindings reachable from it and from the route's
 * content, and the post type.
 *
 * A `single-<type>` route describes every post of the type, so only what
 * holds for all of them is hashed: the template the type is served with
 * (core's candidates without the per-post ones), what that template reaches,
 * and the URL `pattern`. The sampled post is volatile content, the same as a
 * count: its path, its content source and whatever only its content reaches
 * sit in `stats.example`, so publishing a post that sorts first never moves a
 * hash.
 *
 * A route whose own page is a page builder's document (see Builders) says
 * so: a fixed route carries `builder` and `coverage: partial`, and a sampled
 * route's `stats.example` carries `builder`. The template stays the one
 * WordPress resolves.
 *
 * @phpstan-import-type RoutePost from RouteSource
 * @phpstan-import-type RoutePostType from RouteSource
 * @phpstan-type Route array{id: string, label: string, type: string, path: string, candidates: list<string>, template: string|null, post: RoutePost|null, post_type: string|null, content_source: string, edit: string|null, pattern: string|null, post_template: string|null}
 */
final class RouteScanner implements Scanner
{
    public const SECTION = 'routes';

    /** Where the front page and the posts page are chosen. */
    public const READING_SETTINGS = 'options-reading.php';

    /** Graph node for a route's post content. Never a manifest id. */
    public const CONTENT = 'content';

    private RouteSource $routes;

    private LayoutSource $layout;

    public function __construct(?RouteSource $routes = null, ?LayoutSource $layout = null)
    {
        $this->routes = $routes ?? new WordPressRouteSource();
        $this->layout = $layout ?? new WordPressLayoutSource();
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function scan(): Section
    {
        $layout = (new LayoutScanner($this->layout))->scan();
        $graph = self::graph($layout);
        $builder = self::builder($this->layout);
        $items = [];

        foreach ($this->routes() as $route) {
            $postType = $route['post_type'] !== null && IdFormat::isValid('pt:' . $route['post_type']) ? ['pt:' . $route['post_type']] : [];

            $owner = $route['post']['builder'] ?? null;

            if ($route['pattern'] === null) {
                $items[] = new Item(
                    $route['id'],
                    'route',
                    $route['label'],
                    [
                        'content_source' => $route['content_source'],
                        'path'           => $route['path'],
                        'template'       => $route['template'],
                    ] + ($owner !== null ? ['builder' => $owner['builder'], 'coverage' => Builders::PARTIAL] : []),
                    $route['edit'],
                    [...self::reach($graph, $builder, $route['template'], $route['post']), ...$postType]
                );

                continue;
            }

            $refs = self::reach($graph, $builder, $route['template'], null);
            $exampleRefs = array_values(array_diff(self::reach($graph, $builder, $route['post_template'], $route['post']), $refs));
            sort($exampleRefs, SORT_STRING);

            $example = [
                'content_source' => $route['content_source'],
                'path'           => $route['path'],
                'refs'           => $exampleRefs,
            ];

            if ($route['post_template'] !== $route['template']) {
                $example['template'] = $route['post_template'];
            }

            // The sample is volatile, so whether a builder owns it is too.
            if ($owner !== null) {
                $example['builder'] = $owner['builder'];
            }

            $items[] = new Item(
                $route['id'],
                'route',
                $route['label'],
                [
                    'pattern'  => $route['pattern'],
                    'template' => $route['template'],
                ],
                $route['edit'],
                [...$refs, ...$postType],
                ['example' => $example]
            );
        }

        return new Section(self::SECTION, $items);
    }

    /**
     * The URL shape of a post of this type, relative to the site root, with
     * what varies as a placeholder: `/services/{slug}/`, `/{path}/` for a
     * hierarchical type, `/{year}/{monthnum}/{slug}/`, `/?p={id}`.
     *
     * Built from the permalink structure and the post type's rewrite, never
     * from a post, so it does not depend on which post was sampled. Ported
     * from core: get_permalink() for posts, _get_page_link() for pages and
     * get_post_permalink() for the rest, with the permastruct
     * WP_Post_Type::add_rewrite_rules() registers, `<rewrite slug>/%<type>%`
     * behind the permalink front (or behind the root without with_front).
     * Filters such as post_type_link are not applied.
     *
     * @param RoutePostType $type
     * @param string        $structure The permalink_structure option.
     * @param string        $home      The home URL's path, `/` or `/sub/`.
     */
    public static function pattern(array $type, string $structure, string $home = '/'): string
    {
        $name = $type['name'];
        $item = $type['hierarchical'] ? '{path}' : '{slug}';
        $base = rtrim($home, '/');

        $struct = match (true) {
            $structure === ''         => null,
            $name === 'post'          => $structure,
            $name === 'page'          => self::root($structure) . '%pagename%',
            $type['rewrite'] === null => null,
            default                   => ($type['rewrite']['with_front'] ? self::front($structure) : self::root($structure)) . $type['rewrite']['slug'] . '/%' . $name . '%',
        };

        if ($struct === null) {
            return $base . '/?' . match (true) {
                $name === 'post'            => 'p={id}',
                $name === 'page'            => 'page_id={id}',
                $type['query_var'] !== null => $type['query_var'] . '=' . $item,
                default                     => 'post_type=' . $name . '&p={id}',
            };
        }

        $path = str_replace(['%postname%', '%pagename%', '%' . $name . '%', '%post_id%'], ['{slug}', '{path}', $item, '{id}'], $struct);
        $path = (string) preg_replace('/%([^%\/]+)%/', '{$1}', $path);
        $path = trim((string) preg_replace('#/+#', '/', $path), '/');

        // user_trailingslashit(): a trailing slash when the structure ends in one.
        return $base . '/' . $path . ($path !== '' && str_ends_with($structure, '/') ? '/' : '');
    }

    /**
     * Every sampled route with its template resolved, sorted by id.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        $front = $this->routes->frontPage();
        $postsPage = $this->routes->postsPage();
        $routes = [$this->frontRoute($front)];
        $exclude = [];

        if ($front !== null) {
            $exclude[] = $front['id'];
        }

        if ($postsPage !== null) {
            $routes[] = $this->postsRoute($postsPage);
            $exclude[] = $postsPage['id'];
        }

        foreach ($this->routes->templatedPages() as $page) {
            if (!in_array($page['id'], $exclude, true)) {
                $routes[] = $this->singularRoute($page);
                $exclude[] = $page['id'];
            }
        }

        foreach ($this->routes->postTypes() as $type) {
            $sample = $this->routes->firstPost($type['name'], $exclude);

            if ($sample !== null) {
                $routes[] = $this->sampledRoute($type, $sample);
            }

            if ($type['archive_path'] !== null) {
                $routes[] = $this->archiveRoute($type['name'], $type['archive_path']);
            }
        }

        $byId = [];

        foreach ($routes as $route) {
            if (IdFormat::isValid($route['id'])) {
                $byId[$route['id']] ??= $route;
            }
        }

        ksort($byId, SORT_STRING);

        return array_values($byId);
    }

    /**
     * The route one URL is served under, built for that URL's own post, or
     * null when it is not a front page, posts page, published post or page,
     * or post type archive.
     *
     * @return Route|null
     */
    public function routeFor(string $url): ?array
    {
        $path = self::normalizePath($url);
        $front = $this->routes->frontPage();

        if ($path === self::normalizePath($this->routes->homePath())) {
            return $this->frontRoute($front);
        }

        $post = $this->routes->postAt($path);

        if ($post !== null) {
            $postsPage = $this->routes->postsPage();

            return match (true) {
                $front !== null && $post['id'] === $front['id']         => $this->frontRoute($front),
                $postsPage !== null && $post['id'] === $postsPage['id'] => $this->postsRoute($postsPage),
                default                                                 => $this->singularRoute($post),
            };
        }

        foreach ($this->routes->postTypes() as $type) {
            if ($type['archive_path'] !== null && self::normalizePath($type['archive_path']) === $path) {
                return $this->archiveRoute($type['name'], $type['archive_path']);
            }
        }

        return null;
    }

    /**
     * A URL or path as `/a/b/`: path only, one leading and one trailing
     * slash, `/` for the root. `about`, `/about` and
     * `https://example.com/about/?x=1` all give `/about/`.
     */
    public static function normalizePath(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? trim($path, '/') : '';

        return $path === '' ? '/' : '/' . $path . '/';
    }

    /**
     * Direct refs between the layout section's items, limited to ids the
     * section holds.
     *
     * @return array<string, list<string>>
     */
    public static function graph(Section $layout): array
    {
        $ids = [];

        foreach ($layout->items as $item) {
            $ids[$item->id] = true;
        }

        $graph = [];

        foreach ($layout->items as $item) {
            $graph[$item->id] = self::known($item->refs, $ids);
        }

        return $graph;
    }

    /**
     * Breadth first from the roots: ids in the order reached, and for each id
     * but the roots, the id it was first reached from.
     *
     * @param array<string, list<string>> $graph
     * @param list<string>                $roots
     * @return array{order: list<string>, parent: array<string, string>}
     */
    public static function walk(array $graph, array $roots): array
    {
        $order = [];
        $parent = [];
        $seen = [];
        $queue = [];

        foreach ($roots as $root) {
            if (!isset($seen[$root])) {
                $seen[$root] = true;
                $queue[] = $root;
            }
        }

        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;

            foreach ($graph[$id] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $parent[$next] = $id;
                    $queue[] = $next;
                }
            }
        }

        return ['order' => $order, 'parent' => $parent];
    }

    /**
     * A tree builder whose pointer blocks resolve against the layout source's
     * rows, the same way LayoutScanner resolves them.
     */
    public static function builder(LayoutSource $layout): BlockTreeBuilder
    {
        $patterns = [];
        $navigations = [];

        foreach ($layout->syncedPatterns() as $row) {
            $patterns[$row['id']] = $row;
        }

        foreach ($layout->navigations() as $row) {
            $navigations[$row['id']] = $row;
        }

        return new BlockTreeBuilder(new ReferenceResolver(
            static fn (int $id): ?array => $patterns[$id] ?? null,
            static fn (string $slug): ?array => null,
            static fn (int $id): ?array => $navigations[$id] ?? null
        ));
    }

    /**
     * Ids the blocks in a tree point at directly: parts, patterns, menus and
     * bindings, in document order.
     *
     * @param list<BlockNode> $nodes
     * @return list<string>
     */
    public static function refsIn(array $nodes): array
    {
        $refs = [];

        foreach ($nodes as $node) {
            $ref = BlockRefs::pointer($node->reference);

            if ($ref !== null && IdFormat::isValid($ref)) {
                $refs[] = $ref;
            }

            foreach (BlockRefs::bindingsOf($node) as $binding) {
                $refs[] = $binding['id'];
            }

            $refs = [...$refs, ...self::refsIn($node->children)];
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param list<string>        $refs
     * @param array<string, mixed> $ids
     * @return list<string>
     */
    private static function known(array $refs, array $ids): array
    {
        return array_values(array_filter($refs, static fn (string $ref): bool => isset($ids[$ref])));
    }

    /**
     * Layout ids reachable from a template and from a post's content, in
     * walk order.
     *
     * @param array<string, list<string>> $graph Taken by value: the content node never leaks out.
     * @param RoutePost|null              $post
     * @return list<string>
     */
    private static function reach(array $graph, BlockTreeBuilder $builder, ?string $template, ?array $post): array
    {
        $roots = [];

        if ($template !== null && isset($graph['tpl:' . $template])) {
            $roots[] = 'tpl:' . $template;
        }

        if ($post !== null) {
            $graph[self::CONTENT] = self::known(self::refsIn($builder->build(parse_blocks($post['content']))['tree']), $graph);
            $roots[] = self::CONTENT;
        }

        return array_values(array_diff(self::walk($graph, $roots)['order'], [self::CONTENT]));
    }

    /**
     * The permalink front, as WP_Rewrite::init() sets it: the structure up
     * to its first tag (`/` for `/%postname%/`, `/blog/` for
     * `/blog/%postname%/`).
     */
    private static function front(string $structure): string
    {
        $tag = strpos($structure, '%');

        return $tag === false ? '' : substr($structure, 0, $tag);
    }

    /**
     * The permalink root, as WP_Rewrite::init() sets it: `index.php/` for
     * PATHINFO permalinks, else empty.
     */
    private static function root(string $structure): string
    {
        return preg_match('#^/*index\.php#', $structure) === 1 ? 'index.php/' : '';
    }

    /**
     * @param RoutePost|null $front
     * @return Route
     */
    private function frontRoute(?array $front): array
    {
        if ($front === null) {
            return $this->route('route:front-page', 'Front page', 'front-page', $this->routes->homePath(), ['front-page', 'home', 'index'], null, 'post', 'query:post', self::READING_SETTINGS);
        }

        return $this->route('route:front-page', 'Front page', 'front-page', $front['path'], ['front-page', ...self::pageCandidates($front)], $front, 'page', 'page:' . $front['slug'], self::READING_SETTINGS);
    }

    /**
     * The posts page shows the latest posts, not its own content.
     *
     * @param RoutePost $page
     * @return Route
     */
    private function postsRoute(array $page): array
    {
        return $this->route('route:posts-page', 'Posts page', 'home', $page['path'], ['home', 'index'], null, 'post', 'query:post', self::READING_SETTINGS);
    }

    /**
     * A page with an assigned template is its own route; anything else is
     * the single route of its post type.
     *
     * @param RoutePost $post
     * @return Route
     */
    private function singularRoute(array $post): array
    {
        $type = $post['type'];
        $content = $type . ':' . $post['slug'];

        if ($type === 'page' && $post['template'] !== '') {
            $title = $post['title'] !== '' ? $post['title'] : $post['slug'];

            return $this->route('route:page-' . $post['slug'], $title . ' page', 'page', $post['path'], self::pageCandidates($post), $post, 'page', $content, 'post.php?post=' . $post['id'] . '&action=edit');
        }

        if ($type === 'page') {
            return $this->route('route:single-page', 'Single page', 'page', $post['path'], self::pageCandidates($post), $post, 'page', $content, null);
        }

        return $this->route('route:single-' . $type, 'Single ' . self::words($type), 'single', $post['path'], self::singleCandidates($post), $post, $type, $content, null);
    }

    /**
     * The single route of a post type, built from one sampled post. Its
     * template comes from the type's candidates alone (get_single_template()
     * or get_page_template() without the per-post slugs, then singular and
     * index), so it holds for every post of the type. `post_template` is the
     * one the sample itself is served with.
     *
     * @param RoutePostType $type
     * @param RoutePost     $sample
     * @return Route
     */
    private function sampledRoute(array $type, array $sample): array
    {
        $route = $this->singularRoute($sample);
        $candidates = $sample['type'] === 'page'
            ? ['page', 'singular', 'index']
            : ['single-' . $sample['type'], 'single', 'singular', 'index'];

        return [
            'candidates'    => $candidates,
            'template'      => $this->routes->resolveTemplate($route['type'], $candidates),
            'pattern'       => self::pattern($type, $this->routes->permalinkStructure(), $this->routes->homePath()),
            'post_template' => $route['template'],
        ] + $route;
    }

    /**
     * @return Route
     */
    private function archiveRoute(string $postType, string $path): array
    {
        return $this->route('route:archive-' . $postType, ucfirst(self::words($postType)) . ' archive', 'archive', $path, ['archive-' . $postType, 'archive', 'index'], null, $postType, 'query:' . $postType, null);
    }

    /**
     * @param list<string>   $candidates
     * @param RoutePost|null $post
     * @return Route
     */
    private function route(string $id, string $label, string $type, string $path, array $candidates, ?array $post, ?string $postType, string $contentSource, ?string $edit): array
    {
        $candidates = array_values(array_unique($candidates));

        return [
            'id'             => $id,
            'label'          => $label,
            'type'           => $type,
            'path'           => $path,
            'candidates'     => $candidates,
            'template'       => $this->routes->resolveTemplate($type, $candidates),
            'post'           => $post,
            'post_type'      => $postType,
            'content_source' => $contentSource,
            'edit'           => $edit,
            'pattern'        => null,
            'post_template'  => null,
        ];
    }

    /**
     * get_page_template(), then get_singular_template(), then index.
     *
     * @param RoutePost $page
     * @return list<string>
     */
    private static function pageCandidates(array $page): array
    {
        $candidates = [];

        if ($page['template'] !== '') {
            $candidates[] = self::slug($page['template']);
        }

        $decoded = urldecode($page['slug']);

        if ($decoded !== $page['slug']) {
            $candidates[] = 'page-' . $decoded;
        }

        return [...$candidates, 'page-' . $page['slug'], 'page-' . $page['id'], 'page', 'singular', 'index'];
    }

    /**
     * get_single_template(), then get_singular_template(), then index.
     *
     * @param RoutePost $post
     * @return list<string>
     */
    private static function singleCandidates(array $post): array
    {
        $type = $post['type'];
        $candidates = [];

        if ($post['template'] !== '') {
            $candidates[] = self::slug($post['template']);
        }

        $decoded = urldecode($post['slug']);

        if ($decoded !== $post['slug']) {
            $candidates[] = 'single-' . $type . '-' . $decoded;
        }

        return [...$candidates, 'single-' . $type . '-' . $post['slug'], 'single-' . $type, 'single', 'singular', 'index'];
    }

    /**
     * A template file name as a slug, the way core's
     * _strip_template_file_suffix() does it.
     */
    private static function slug(string $template): string
    {
        return (string) preg_replace('/\.(php|html)$/', '', $template);
    }

    private static function words(string $name): string
    {
        return str_replace(['_', '-'], ' ', $name);
    }
}
