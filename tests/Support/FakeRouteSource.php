<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support;

use Voyager\Wayfinder\Scanners\ClassicTheme;
use Voyager\Wayfinder\Scanners\RouteSource;

/**
 * In-memory RouteSource. fixture() mirrors what fixtures/seed.php leaves:
 * a static front page, a posts page, the contact page on its own template,
 * services with an archive, and the business-info binding values.
 *
 * Template resolution picks the first candidate in `$available`, which is
 * what core's resolve_block_template() does over the templates that exist.
 *
 * @phpstan-import-type RoutePost from RouteSource
 * @phpstan-import-type RoutePostType from RouteSource
 */
final class FakeRouteSource implements RouteSource
{
    public const CTA = '<!-- wp:block {"ref":90003} /-->';

    public string $home = '/';

    public string $permalinks = '/%postname%/';

    /** @var array<string, RoutePost> By path. */
    public array $posts = [];

    public ?string $frontPath = null;

    public ?string $postsPath = null;

    /** @var list<RoutePostType> */
    public array $postTypes = [];

    /** @var list<string> Template slugs that exist: the parent theme's and the child theme's. */
    public array $available = [];

    /** @var array<string, array<string, string>> Source => key => value. */
    public array $values = [];

    /** @var array<int, array<string, string>> Post id => meta key => value, for core/post-meta. */
    public array $meta = [];

    /** @var list<array{type: string, candidates: list<string>}> Every resolveTemplate() call. */
    public array $resolved = [];

    /** A classic theme resolves templates from its PHP files instead of `$available`. */
    public ?ClassicTheme $classic = null;

    /**
     * Keys (`<theme>//<slug>`) of a hybrid classic theme's block templates.
     * Null for a theme without block template support.
     *
     * @var list<string>|null
     */
    public ?array $hybrid = null;

    public static function fixture(): self
    {
        $source = new self();

        $source->add(10, 'page', 'home', 'Home', '/', '', FakeLayoutSource::paragraph('Licensed plumbers for homes across the Fredericksburg region.') . "\n\n" . self::CTA);
        $source->add(11, 'page', 'blog', 'Blog', '/blog/');
        $source->add(12, 'page', 'contact', 'Contact', '/contact/', 'page-contact', FakeLayoutSource::paragraph('Call us or send a message and we will get back to you.'));
        $source->add(13, 'page', 'about', 'About', '/about/', '', FakeLayoutSource::paragraph('Riverbend Plumbing has served the Fredericksburg region since 2009.') . "\n\n" . self::CTA);
        $source->add(20, 'post', 'how-to-find-your-main-water-shut-off', 'How to find your main water shut-off', '/how-to-find-your-main-water-shut-off/');
        $source->add(30, 'service', 'drain-cleaning', 'Drain cleaning', '/services/drain-cleaning/', '', FakeLayoutSource::paragraph('We clear slow and blocked drains.'));
        $source->add(31, 'service', 'emergency-plumbing', 'Emergency plumbing', '/services/emergency-plumbing/');

        $source->frontPath = '/';
        $source->postsPath = '/blog/';

        // Built-in post and page register no rewrite of their own; their URLs come from the permalink structure.
        $source->postTypes = [
            ['name' => 'page', 'archive_path' => null, 'rewrite' => null, 'hierarchical' => true, 'query_var' => null],
            ['name' => 'post', 'archive_path' => null, 'rewrite' => null, 'hierarchical' => false, 'query_var' => null],
            ['name' => 'service', 'archive_path' => '/services/', 'rewrite' => ['slug' => 'services', 'with_front' => true], 'hierarchical' => false, 'query_var' => 'service'],
        ];

        $source->available = ['archive', 'home', 'index', 'page', 'page-contact', 'single', 'single-service'];

        $source->values = [
            'vwf-fixture/business-info' => [
                'address' => '120 Mill Race Rd, Fredericksburg, VA',
                'hours'   => 'Mon to Fri 7am to 6pm',
                'phone'   => '(540) 555-0142',
            ],
        ];

        return $source;
    }

    /**
     * FakeLayoutSource::fixture() plus the parent theme templates the
     * fixture's routes resolve to, each built the way twentytwentyfive's
     * are: header, main content, footer.
     */
    public static function layout(): FakeLayoutSource
    {
        $layout = FakeLayoutSource::fixture();
        $page = '<!-- wp:template-part {"slug":"header"} /-->' . "\n\n"
            . '<!-- wp:post-title {"level":1} /-->' . "\n\n"
            . '<!-- wp:post-content {"layout":{"type":"constrained"}} /-->' . "\n\n"
            . '<!-- wp:template-part {"slug":"footer"} /-->';

        foreach (['archive' => 'All archives', 'home' => 'Blog home', 'index' => 'Index', 'page' => 'Pages', 'single' => 'Single posts'] as $slug => $title) {
            $layout->templates[] = FakeLayoutSource::row($slug, $page, $title);
        }

        return $layout;
    }

    public function add(int $id, string $type, string $slug, string $title, string $path, string $template = '', string $content = ''): void
    {
        $this->posts[$path] = [
            'id'       => $id,
            'type'     => $type,
            'slug'     => $slug,
            'title'    => $title,
            'path'     => $path,
            'template' => $template,
            'content'  => $content,
        ];
    }

    public function homePath(): string
    {
        return $this->home;
    }

    public function frontPage(): ?array
    {
        return $this->frontPath !== null ? ($this->posts[$this->frontPath] ?? null) : null;
    }

    public function postsPage(): ?array
    {
        return $this->postsPath !== null ? ($this->posts[$this->postsPath] ?? null) : null;
    }

    public function permalinkStructure(): string
    {
        return $this->permalinks;
    }

    public function postTypes(): array
    {
        return $this->postTypes;
    }

    public function firstPost(string $postType, array $exclude = []): ?array
    {
        $first = null;

        foreach ($this->posts as $post) {
            if ($post['type'] === $postType && !in_array($post['id'], $exclude, true) && ($first === null || strcmp($post['slug'], $first['slug']) < 0)) {
                $first = $post;
            }
        }

        return $first;
    }

    public function templatedPages(): array
    {
        $pages = array_values(array_filter(
            $this->posts,
            static fn (array $post): bool => $post['type'] === 'page' && $post['template'] !== ''
        ));

        usort($pages, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return $pages;
    }

    public function postAt(string $path): ?array
    {
        return $this->posts[$path] ?? null;
    }

    public function resolveTemplate(string $type, array $candidates): ?string
    {
        $this->resolved[] = ['type' => $type, 'candidates' => $candidates];

        if ($this->classic !== null && $this->hybrid !== null) {
            $hybrid = $this->hybrid;

            return $this->classic->resolveWithBlockTemplates(
                $candidates,
                static function (array $remaining) use ($hybrid): ?string {
                    foreach ($remaining as $candidate) {
                        foreach ($hybrid as $key) {
                            if (str_ends_with($key, '//' . $candidate)) {
                                return $key;
                            }
                        }
                    }

                    return null;
                }
            );
        }

        if ($this->classic !== null) {
            return $this->classic->resolve($candidates);
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $this->available, true)) {
                return $candidate;
            }
        }

        return null;
    }

    public function bindingValue(string $source, array $args, string $block, string $attribute, ?array $post): ?string
    {
        $key = is_string($args['key'] ?? null) ? $args['key'] : '';

        if ($source === 'core/post-meta') {
            return $post !== null ? ($this->meta[$post['id']][$key] ?? null) : null;
        }

        return $this->values[$source][$key] ?? null;
    }
}
