<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Scanners\RouteScanner;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * The routes section and URL resolution, on the fixture site, with no
 * database.
 */
final class RouteScannerTest extends TestCase
{
    public function testTheFixtureHasOneRoutePerKindOfUrl(): void
    {
        $this->assertSame(
            [
                'route:archive-service',
                'route:front-page',
                'route:page-contact',
                'route:posts-page',
                'route:single-page',
                'route:single-post',
                'route:single-service',
            ],
            array_map(static fn (Item $item): string => $item->id, self::scan()->items)
        );
    }

    public function testASingleServiceResolvesItsTemplateAndReachesEverythingInIt(): void
    {
        $route = self::scan()->item('route:single-service');

        $this->assertNotNull($route);
        $this->assertSame('route', $route->kind);
        $this->assertSame('Single service', $route->label);
        $this->assertSame(['pattern' => '/services/{slug}/', 'template' => 'single-service'], $route->facts);
        $this->assertSame(
            ['example' => ['content_source' => 'service:drain-cleaning', 'path' => '/services/drain-cleaning/', 'refs' => []]],
            $route->stats
        );
        $this->assertSame(
            [
                'bind:vwf-fixture/business-info.address',
                'bind:vwf-fixture/business-info.phone',
                'nav:footer',
                'nav:primary',
                'part:footer',
                'part:header',
                'pat:cta-banner',
                'pt:service',
                'tpl:single-service',
            ],
            $route->refs
        );
    }

    public function testCandidatesFollowCoreTemplateHierarchyOrder(): void
    {
        $source = FakeRouteSource::fixture();
        (new RouteScanner($source, FakeRouteSource::layout()))->routes();

        $calls = [];

        foreach ($source->resolved as $call) {
            $calls[$call['candidates'][0]] = $call;
        }

        $this->assertSame(
            ['type' => 'single', 'candidates' => ['single-service-drain-cleaning', 'single-service', 'single', 'singular', 'index']],
            $calls['single-service-drain-cleaning']
        );
        // The route's own template: the type's candidates without the sample's.
        $this->assertSame(['type' => 'single', 'candidates' => ['single-service', 'single', 'singular', 'index']], $calls['single-service']);
        $this->assertSame(['type' => 'page', 'candidates' => ['page', 'singular', 'index']], $calls['page']);
        $this->assertSame(
            ['type' => 'front-page', 'candidates' => ['front-page', 'page-home', 'page-10', 'page', 'singular', 'index']],
            $calls['front-page']
        );
        $this->assertSame(
            ['type' => 'page', 'candidates' => ['page-contact', 'page-12', 'page', 'singular', 'index']],
            $calls['page-contact']
        );
        $this->assertSame(['type' => 'home', 'candidates' => ['home', 'index']], $calls['home']);
        $this->assertSame(['type' => 'archive', 'candidates' => ['archive-service', 'archive', 'index']], $calls['archive-service']);
    }

    public function testTheFrontPageServesItsPageAndReachesThePatternInItsContent(): void
    {
        $front = self::scan()->item('route:front-page');

        $this->assertNotNull($front);
        $this->assertSame(['content_source' => 'page:home', 'path' => '/', 'template' => 'page'], $front->facts);
        $this->assertSame('options-reading.php', $front->edit);
        $this->assertContains('pat:cta-banner', $front->refs);
        $this->assertContains('tpl:page', $front->refs);
    }

    public function testASiteShowingLatestPostsHasAHomeFrontPageAndNoPostsPage(): void
    {
        $source = FakeRouteSource::fixture();
        $source->frontPath = null;
        $source->postsPath = null;

        $section = (new RouteScanner($source, FakeRouteSource::layout()))->scan();
        $front = $section->item('route:front-page');

        $this->assertNotNull($front);
        $this->assertNull($section->item('route:posts-page'));
        $this->assertSame(['content_source' => 'query:post', 'path' => '/', 'template' => 'home'], $front->facts);
        $this->assertNotContains('pat:cta-banner', $front->refs);
    }

    public function testThePageSampleSkipsTheFrontPostsAndTemplatedPages(): void
    {
        $section = self::scan();

        $this->assertSame('page:about', $section->item('route:single-page')?->stats['example']['content_source']);
        $this->assertSame('/contact/', $section->item('route:page-contact')?->facts['path']);
        $this->assertSame('page-contact', $section->item('route:page-contact')?->facts['template']);
        $this->assertSame('post.php?post=12&action=edit', $section->item('route:page-contact')?->edit);
    }

    public function testAnUnmatchedTemplateIsNullAndWhatOnlyTheSampleReachesIsAStat(): void
    {
        $source = FakeRouteSource::fixture();
        $source->available = [];

        $route = (new RouteScanner($source, FakeRouteSource::layout()))->scan()->item('route:single-page');

        $this->assertNotNull($route);
        $this->assertNull($route->facts['template']);
        $this->assertSame(['pt:page'], $route->refs);
        $this->assertSame(
            ['content_source' => 'page:about', 'path' => '/about/', 'refs' => ['pat:cta-banner']],
            $route->stats['example']
        );
    }

    public function testRoutesWithAFixedPageKeepTheirPathAndContentHashed(): void
    {
        $section = self::scan();

        foreach (['route:archive-service', 'route:front-page', 'route:page-contact', 'route:posts-page'] as $id) {
            $this->assertSame(['content_source', 'path', 'template'], array_keys($section->item($id)?->facts ?? []), $id);
            $this->assertSame([], $section->item($id)?->stats, $id);
        }
    }

    public function testPublishingAPostThatSortsFirstMovesOnlyTheExample(): void
    {
        $before = self::scan();

        $source = FakeRouteSource::fixture();
        $source->add(32, 'service', 'aardvark-removal', 'Aardvark removal', '/services/aardvark-removal/', '', FakeRouteSource::CTA);
        $after = (new RouteScanner($source, FakeRouteSource::layout()))->scan();

        $this->assertSame($before->hash, $after->hash);
        $this->assertSame($before->item('route:single-service')?->hash, $after->item('route:single-service')?->hash);
        $this->assertNotSame($before->stats(), $after->stats());
        $this->assertSame(
            // The CTA pattern is already reached through the template, so it is not the example's own.
            ['content_source' => 'service:aardvark-removal', 'path' => '/services/aardvark-removal/', 'refs' => []],
            $after->item('route:single-service')?->stats['example']
        );
    }

    public function testASampleWithItsOwnTemplateLeavesTheRouteTemplateAlone(): void
    {
        $source = FakeRouteSource::fixture();
        $source->available[] = 'single-service-drain-cleaning';

        $route = (new RouteScanner($source, FakeRouteSource::layout()))->scan()->item('route:single-service');

        $this->assertNotNull($route);
        $this->assertSame(self::scan()->item('route:single-service')?->hash, $route->hash);
        $this->assertSame('single-service', $route->facts['template']);
        $this->assertSame('single-service-drain-cleaning', $route->stats['example']['template']);

        // The URL itself is still served with the post's own template.
        $this->assertSame('single-service-drain-cleaning', (new RouteScanner($source, FakeRouteSource::layout()))->routeFor('/services/drain-cleaning/')['template'] ?? null);
    }

    public function testThePatternUsesACustomRewriteSlug(): void
    {
        // register_post_type('service', ['rewrite' => ['slug' => 'services']]), as the fixture does.
        $this->assertSame('/services/{slug}/', RouteScanner::pattern(self::type('service', ['slug' => 'services', 'with_front' => true]), '/%postname%/'));
        $this->assertSame('/services/{slug}/', self::scan()->item('route:single-service')?->facts['pattern']);
    }

    public function testThePatternUsesThePostTypeNameByDefault(): void
    {
        // With no rewrite argument WordPress fills in the post type name as the slug.
        $this->assertSame('/faq/{slug}/', RouteScanner::pattern(self::type('faq', ['slug' => 'faq', 'with_front' => true]), '/%postname%/'));

        $source = FakeRouteSource::fixture();
        $source->postTypes[] = self::type('faq', ['slug' => 'faq', 'with_front' => true]);
        $source->add(40, 'faq', 'do-you-work-weekends', 'Do you work weekends?', '/faq/do-you-work-weekends/');

        $route = (new RouteScanner($source, FakeRouteSource::layout()))->scan()->item('route:single-faq');

        $this->assertNotNull($route);
        $this->assertSame(['pattern' => '/faq/{slug}/', 'template' => 'single'], $route->facts);
        $this->assertSame('/faq/do-you-work-weekends/', $route->stats['example']['path']);
    }

    public function testThePatternFollowsThePermalinkFront(): void
    {
        $structure = '/blog/%postname%/';

        $this->assertSame('/blog/services/{slug}/', RouteScanner::pattern(self::type('service', ['slug' => 'services', 'with_front' => true]), $structure));
        $this->assertSame('/services/{slug}/', RouteScanner::pattern(self::type('service', ['slug' => 'services', 'with_front' => false]), $structure));
        $this->assertSame('/blog/{slug}/', RouteScanner::pattern(self::type('post'), $structure));
        $this->assertSame('/{path}/', RouteScanner::pattern(self::type('page', null, true), $structure));
    }

    public function testThePatternTurnsStructureTagsIntoPlaceholders(): void
    {
        $this->assertSame('/{year}/{monthnum}/{slug}/', RouteScanner::pattern(self::type('post'), '/%year%/%monthnum%/%postname%/'));
        $this->assertSame('/{category}/{slug}/', RouteScanner::pattern(self::type('post'), '/%category%/%postname%/'));
        $this->assertSame('/archives/{id}', RouteScanner::pattern(self::type('post'), '/archives/%post_id%'));
        $this->assertSame('/archives/services/{slug}', RouteScanner::pattern(self::type('service', ['slug' => 'services', 'with_front' => true]), '/archives/%post_id%'));
        $this->assertSame('/areas/{path}/', RouteScanner::pattern(self::type('area', ['slug' => 'areas', 'with_front' => true], true), '/%postname%/'));
    }

    public function testPlainPermalinksAndTypesWithoutRewritesUseTheQueryString(): void
    {
        $this->assertSame('/?p={id}', RouteScanner::pattern(self::type('post'), ''));
        $this->assertSame('/?page_id={id}', RouteScanner::pattern(self::type('page', null, true), ''));
        $this->assertSame('/?service={slug}', RouteScanner::pattern(self::type('service', ['slug' => 'services', 'with_front' => true], false, 'service'), ''));
        $this->assertSame('/?post_type=faq&p={id}', RouteScanner::pattern(self::type('faq', ['slug' => 'faq', 'with_front' => true]), ''));
        $this->assertSame('/?faq={slug}', RouteScanner::pattern(self::type('faq', null, false, 'faq'), '/%postname%/'));
    }

    public function testThePatternKeepsTheIndexRootAndTheHomeSubdirectory(): void
    {
        $this->assertSame('/index.php/{path}/', RouteScanner::pattern(self::type('page', null, true), '/index.php/%postname%/'));
        $this->assertSame('/index.php/services/{slug}/', RouteScanner::pattern(self::type('service', ['slug' => 'services', 'with_front' => false]), '/index.php/%postname%/'));
        $this->assertSame('/sub/services/{slug}/', RouteScanner::pattern(self::type('service', ['slug' => 'services', 'with_front' => true]), '/%postname%/', '/sub/'));
    }

    public function testRouteForResolvesEachKindOfUrl(): void
    {
        $scanner = new RouteScanner(FakeRouteSource::fixture(), FakeRouteSource::layout());

        $this->assertSame('route:front-page', $scanner->routeFor('/')['id'] ?? null);
        $this->assertSame('route:posts-page', $scanner->routeFor('/blog/')['id'] ?? null);
        $this->assertSame('route:page-contact', $scanner->routeFor('/contact/')['id'] ?? null);
        $this->assertSame('route:single-page', $scanner->routeFor('about')['id'] ?? null);
        $this->assertSame('route:archive-service', $scanner->routeFor('/services')['id'] ?? null);
        $this->assertNull($scanner->routeFor('/no-such-page/'));

        $service = $scanner->routeFor('https://riverbend.test/services/emergency-plumbing/?ref=nav');
        $this->assertSame('route:single-service', $service['id'] ?? null);
        $this->assertSame('service:emergency-plumbing', $service['content_source'] ?? null);
    }

    public function testNormalizePath(): void
    {
        $this->assertSame('/', RouteScanner::normalizePath(''));
        $this->assertSame('/', RouteScanner::normalizePath('https://riverbend.test'));
        $this->assertSame('/about/', RouteScanner::normalizePath('/about'));
        $this->assertSame('/services/drain-cleaning/', RouteScanner::normalizePath('https://riverbend.test/services/drain-cleaning/?x=1#top'));
    }

    public function testScansAreDeterministic(): void
    {
        $this->assertSame(self::scan()->hash, self::scan()->hash);
    }

    private static function scan(): Section
    {
        return (new RouteScanner(FakeRouteSource::fixture(), FakeRouteSource::layout()))->scan();
    }

    /**
     * @param array{slug: string, with_front: bool}|null $rewrite
     * @return array{name: string, archive_path: null, rewrite: array{slug: string, with_front: bool}|null, hierarchical: bool, query_var: string|null}
     */
    private static function type(string $name, ?array $rewrite = null, bool $hierarchical = false, ?string $queryVar = null): array
    {
        return ['name' => $name, 'archive_path' => null, 'rewrite' => $rewrite, 'hierarchical' => $hierarchical, 'query_var' => $queryVar];
    }
}
