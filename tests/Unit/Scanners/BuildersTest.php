<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Admin\EditAccess;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Render\Human\ManualView;
use Voyager\Wayfinder\Scanners\Builders;
use Voyager\Wayfinder\Scanners\ContentModelScanner;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\PluginRoles;
use Voyager\Wayfinder\Scanners\RouteScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * Page builder documents: detection from injected plugin lists, themes and
 * post meta, `btpl:` items, the `builder_docs` stat, route facts, and find
 * on a builder page. No WordPress.
 */
final class BuildersTest extends TestCase
{
    private const ADMIN = 'https://example.test/wp-admin/';

    /** The About page in FakeRouteSource::fixture(). */
    private const ABOUT = 13;

    public function testEachBuilderIsDetectedByItsMarkerWithItsOwnEditUrl(): void
    {
        $cases = [
            'beaver-builder' => [['bb-plugin'], [], ['_fl_builder_enabled' => '1'], '/about/?fl_builder'],
            'breakdance'     => [['breakdance'], [], ['_breakdance_data' => '{"tree":{}}'], '/?breakdance=builder&id=13'],
            'bricks'         => [[], ['bricks-child', 'bricks'], ['_bricks_editor_mode' => 'bricks'], '/about/?bricks=run'],
            'divi'           => [[], ['Divi'], ['_et_pb_use_builder' => 'on'], '/about/?et_fb=1'],
            'elementor'      => [['elementor'], [], ['_elementor_data' => '[{"elType":"section"}]'], 'post.php?post=13&action=elementor'],
        ];

        foreach ($cases as $slug => [$plugins, $themes, $meta, $edit]) {
            $doc = self::doc(Builders::bundled()->active($plugins, $themes), $meta);

            $this->assertSame($slug, $doc['builder'] ?? null, $slug);
            $this->assertSame($edit, $doc['edit'] ?? null, $slug);
        }

        // Divi as a plugin on another theme, and Beaver's lite build.
        $this->assertSame('divi', self::doc(Builders::bundled()->active(['divi-builder'], ['twentytwentyfive']), ['_et_pb_use_builder' => 'on'])['builder'] ?? null);
        $this->assertSame('beaver-builder', self::doc(Builders::bundled()->active(['beaver-builder-lite-version'], []), ['_fl_builder_enabled' => '1'])['builder'] ?? null);

        // Plain permalinks keep the query string that names the post, so the builder's argument joins with `&`.
        $this->assertSame('/?page_id=13&bricks=run', Builders::bundled()->editUrl('bricks', 13, '/?page_id=13'));
        $this->assertSame('Elementor', Builders::bundled()->label('elementor'));
    }

    public function testAnInactiveBuilderAndAnEmptyOrWrongMarkerAreIgnored(): void
    {
        $none = Builders::bundled()->active(['akismet', 'wordpress-seo'], ['twentytwentyfive']);
        $elementor = Builders::bundled()->active(['elementor'], ['twentytwentyfive']);

        $this->assertTrue($none->isEmpty());
        $this->assertNull(self::doc($none, ['_elementor_data' => '[{"elType":"section"}]']), 'Elementor is not active');
        $this->assertSame(['elementor'], array_keys($elementor->all()));
        $this->assertNull(self::doc($elementor, ['_et_pb_use_builder' => 'on']), 'Divi is not active');
        $this->assertNull(self::doc($elementor, ['_elementor_data' => '']));
        $this->assertNull(self::doc($elementor, ['_elementor_data' => '0']));
        $this->assertNull(self::doc($elementor, []));
        $this->assertNull(self::doc(Builders::bundled()->active([], ['Divi']), ['_et_pb_use_builder' => 'off']), 'Divi marks a builder page with "on" only');
        $this->assertNull(self::doc(Builders::bundled()->active([], ['bricks']), ['_bricks_editor_mode' => 'wordpress']));

        $this->expectException(InvalidArgumentException::class);
        Builders::fromData(['builders' => ['elementor' => ['label' => 'Elementor', 'edit' => 'post.php?post={id}']]]);
    }

    public function testEveryBundledBuilderPluginHasTheBuilderRole(): void
    {
        $roles = PluginRoles::bundled();

        foreach (Builders::bundled()->all() as $slug => $builder) {
            foreach ($builder['plugins'] as $plugin) {
                $this->assertSame('builder', $roles->role($plugin), $slug . ': ' . $plugin);
            }
        }

        $this->assertSame(
            ['bricks_template' => 'bricks', 'elementor_library' => 'elementor', 'et_pb_layout' => 'divi'],
            Builders::bundled()->active(['elementor'], ['Divi', 'bricks'])->templateTypes()
        );
    }

    public function testBuilderTemplatesBecomeBtplItemsWithTheBuildersEditUrl(): void
    {
        $plain = (new LayoutScanner(FakeLayoutSource::fixture()))->scan();
        $layout = FakeLayoutSource::fixture();
        $active = Builders::bundled()->active(['elementor'], ['bricks']);
        $layout->builderTemplates = [
            self::template($active, 'elementor', 501, 'site-header', 'Site header', 'elementor_library', 'header'),
            self::template($active, 'elementor', 502, 'cta', 'CTA', 'elementor_library', 'section'),
            self::template($active, 'bricks', 601, 'cta', 'CTA', 'bricks_template', null),
        ];

        $section = (new LayoutScanner($layout))->scan();
        $header = $section->item('btpl:site-header');

        $this->assertTrue(IdFormat::isValid('btpl:site-header'));
        $this->assertNotNull($header);
        $this->assertSame('builder_template', $header->kind);
        $this->assertSame('Site header', $header->label);
        $this->assertSame('post.php?post=501&action=elementor', $header->edit);
        $this->assertSame(['builder' => 'elementor', 'coverage' => 'partial', 'post_type' => 'elementor_library', 'template_type' => 'header'], $header->facts);
        $this->assertSame([], $header->refs);

        // Two templates share a slug, so both are named by post type.
        $this->assertNull($section->item('btpl:cta'));
        $this->assertSame('/bricks_template/cta/?bricks=run', $section->item('btpl:bricks_template.cta')?->edit);
        $this->assertSame('post.php?post=502&action=elementor', $section->item('btpl:elementor_library.cta')?->edit);

        // Everything else in the section is as it was.
        $this->assertSame(
            array_map(static fn ($item): string => $item->hash, $plain->items),
            array_map(static fn ($item): string => $item->hash, array_values(array_filter($section->items, static fn ($item): bool => $item->kind !== 'builder_template')))
        );

        $index = (new IndexRenderer())->render(new Manifest([$section]));
        $this->assertStringContainsString("btpl:site-header | Site header | Elementor template; type header; content not read | post.php?post=501&action=elementor\n", $index);
    }

    public function testBuilderDocsIsAStatOutsideTheHash(): void
    {
        $plain = self::contentModel([]);
        $marked = self::contentModel(['elementor' => 9, 'divi' => 0]);
        $more = self::contentModel(['elementor' => 10]);
        $page = $marked->item('pt:page');

        $this->assertNotNull($page);
        $this->assertSame(['counts' => ['draft' => 0, 'private' => 0, 'publish' => 12], 'builder_docs' => ['elementor' => 9]], $page->stats);
        $this->assertSame($plain->item('pt:page')?->stats, ['counts' => ['draft' => 0, 'private' => 0, 'publish' => 12]], 'no stat without documents');
        $this->assertSame($plain->hash, $marked->hash);
        $this->assertSame($marked->hash, $more->hash);
        $this->assertNotSame((new Manifest([$marked]))->statsHash, (new Manifest([$more]))->statsHash);

        $brief = (new BriefRenderer())->render(new Manifest([$marked]));
        $this->assertStringContainsString('content: Pages 6-20, Elementor 6-20', $brief);
        $this->assertStringContainsString("\nbuilders: Elementor 6-20 documents | content not read; edit in the builder\n", $brief);
        $this->assertStringNotContainsString('builders:', (new BriefRenderer())->render(new Manifest([$plain])));
    }

    public function testFindOnABuilderPageEditsInTheBuilderWithPartialCoverage(): void
    {
        $routes = FakeRouteSource::fixture();
        $routes->posts['/about/']['builder'] = self::doc(Builders::bundled()->active(['elementor'], []), ['_elementor_data' => '[{"elType":"section"}]']);

        $result = (new EditLocator($routes, FakeRouteSource::layout()))->find('/about/', 'anything at all');
        $content = $result['chain'][0];

        $this->assertFalse($result['matched']);
        $this->assertSame('partial', $result['coverage'] ?? null);
        $this->assertSame('builder_document', $result['reason'] ?? null);
        $this->assertStringContainsString('is an Elementor document', (string) $result['message']);
        $this->assertStringContainsString('post.php?post=13&action=elementor', (string) $result['message']);

        $this->assertSame('pt:page', $content['id']);
        $this->assertSame('post.php?post=' . self::ABOUT . '&action=elementor', $content['edit']);
        $this->assertSame('elementor', $content['builder'] ?? null);
        $this->assertStringContainsString('"About", is an Elementor document', $content['instruction']);
        $this->assertStringContainsString('Wayfinder does not read', $content['instruction']);

        // Without text, the page still says how much of it is seen.
        $this->assertSame('partial', (new EditLocator($routes, FakeRouteSource::layout()))->find('/about/')['coverage'] ?? null);
    }

    public function testAPlainPageIsUnaffected(): void
    {
        $plain = (new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout()))->find('/about/', 'anything at all');
        $routes = FakeRouteSource::fixture();
        // Elementor is active and marks another page, not this one.
        $routes->posts['/contact/']['builder'] = self::doc(Builders::bundled()->active(['elementor'], []), ['_elementor_data' => '[]'], 12, '/contact/');
        $result = (new EditLocator($routes, FakeRouteSource::layout()))->find('/about/', 'anything at all');

        $this->assertSame($plain, $result);
        $this->assertArrayNotHasKey('coverage', $result);
        $this->assertArrayNotHasKey('reason', $result);
        $this->assertSame('post.php?post=' . self::ABOUT . '&action=edit', $result['chain'][0]['edit']);
        $this->assertArrayNotHasKey('builder', $result['chain'][0]);
        $this->assertStringStartsWith('No block on this page contains that text.', (string) $result['message']);
    }

    public function testAFixedRouteCarriesTheBuilderAndASampledRouteKeepsItInItsExample(): void
    {
        $plain = (new RouteScanner(FakeRouteSource::fixture(), FakeRouteSource::layout()))->scan();
        $active = Builders::bundled()->active(['elementor'], []);
        $routes = FakeRouteSource::fixture();
        $routes->posts['/']['builder'] = self::doc($active, ['_elementor_data' => '[]'], 10, '/');
        $routes->posts['/about/']['builder'] = self::doc($active, ['_elementor_data' => '[]']);

        $section = (new RouteScanner($routes, FakeRouteSource::layout()))->scan();
        $front = $section->item('route:front-page');
        $single = $section->item('route:single-page');

        $this->assertSame('elementor', $front?->facts['builder'] ?? null);
        $this->assertSame('partial', $front?->facts['coverage'] ?? null);
        $this->assertSame($plain->item('route:front-page')?->facts['template'], $front?->facts['template'], 'the template stays as WordPress resolves it');

        // The single page route samples About: whether a builder owns the sample is volatile, so it is a stat.
        $this->assertSame('/about/', $single?->stats['example']['path'] ?? null);
        $this->assertSame('elementor', $single?->stats['example']['builder'] ?? null);
        $this->assertSame($plain->item('route:single-page')?->hash, $single?->hash);
        $this->assertNotSame($plain->item('route:front-page')?->hash, $front?->hash);
    }

    public function testAFrontEndBuilderPathOpensOnTheSiteAndNeedsAnEditCapability(): void
    {
        $this->assertTrue(Builders::isSitePath('/about/?bricks=run'));
        $this->assertTrue(Builders::isSitePath('/?breakdance=builder&id=13'));
        $this->assertTrue(Builders::isSitePath('/index.php/about/?fl_builder'));
        $this->assertFalse(Builders::isSitePath('/edit.php'));
        $this->assertFalse(Builders::isSitePath('post.php?post=13&action=elementor'));

        $this->assertSame('https://example.test/about/?bricks=run', ManualView::editUrl('/about/?bricks=run', self::ADMIN));
        $this->assertSame(self::ADMIN . 'edit.php', ManualView::editUrl('/edit.php', self::ADMIN));
        $this->assertSame('edit_posts', EditAccess::roleCapability('/about/?bricks=run'));
    }

    /**
     * The About page's builder document facts, from its meta.
     *
     * @param array<string, string> $meta
     * @return array{builder: string, label: string, edit: string}|null
     */
    private static function doc(Builders $active, array $meta, int $id = self::ABOUT, string $path = '/about/'): ?array
    {
        return $active->document(static fn (string $key): ?string => $meta[$key] ?? null, $id, $path);
    }

    /**
     * @return array{builder: string, id: int, slug: string, title: string, type: string, template_type: string|null, edit: string}
     */
    private static function template(Builders $active, string $builder, int $id, string $slug, string $title, string $type, ?string $templateType): array
    {
        return [
            'builder'       => $builder,
            'id'            => $id,
            'slug'          => $slug,
            'title'         => $title,
            'type'          => $type,
            'template_type' => $templateType,
            'edit'          => $active->editUrl($builder, $id, '/' . $type . '/' . $slug . '/'),
        ];
    }

    /**
     * @param array<string, int> $builderDocs
     */
    private static function contentModel(array $builderDocs): Section
    {
        $page = [
            'slug'         => 'page',
            'label'        => 'Pages',
            'menu_name'    => 'Pages',
            'public'       => true,
            'show_ui'      => true,
            'has_archive'  => false,
            'rewrite_slug' => null,
            'supports'     => ['title', 'editor'],
            'hierarchical' => true,
            'show_in_rest' => true,
            'counts'       => ['publish' => 12],
            'defined_in'   => 'core',
        ];

        return (new ContentModelScanner(
            static fn (): array => [$builderDocs !== [] ? $page + ['builder_docs' => $builderDocs] : $page],
            static fn (): array => [],
            static fn (string $type): array => [],
            static fn (): ?array => null,
            static fn (): ?array => null
        ))->scan();
    }
}
