<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Scanners\ClassicTheme;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\LayoutSource;
use Voyager\Wayfinder\Scanners\RouteScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * A hybrid theme: the classic theme in tests/fixtures/classic-theme with
 * block template support, so Site Editor templates sit beside its PHP files.
 *
 * @phpstan-import-type TemplateRow from LayoutSource
 */
final class HybridThemeTest extends TestCase
{
    private const CHILD = 'vwf-classic-child';

    private const PARENT = 'vwf-classic';

    /** The Site Editor `page` template's id slug. */
    private const PAGE = self::CHILD . '//page';

    public function testTheLayoutListsThePhpTemplatesAndTheSiteEditorTemplatesEachWithItsSource(): void
    {
        $layout = self::layout([self::block('page', 'Pages')]);

        $this->assertSame(
            ['tpl:archive', 'tpl:index', 'tpl:page', 'tpl:page-contact', 'tpl:single', 'tpl:templates/full-width', 'tpl:' . self::PAGE],
            self::ids($layout, 'template')
        );

        $php = $layout->item('tpl:page');
        $block = $layout->item('tpl:' . self::PAGE);

        $this->assertNotNull($php);
        $this->assertNotNull($block);
        $this->assertSame('theme', $php->facts['source']);
        $this->assertSame('themes/vwf-classic/page.php', $php->facts['file']);
        $this->assertNull($php->edit);

        $this->assertSame('custom', $block->facts['source'], 'Made in the Site Editor.');
        $this->assertSame('page', $block->facts['slug']);
        $this->assertNull($block->facts['file']);
        $this->assertSame('Pages', $block->label);
        $this->assertSame('site-editor.php?p=/wp_template/' . self::PAGE . '&canvas=edit', $block->edit);
        $this->assertArrayNotHasKey('reads', $block->facts, 'Only PHP files carry reads and links.');
        $this->assertArrayHasKey('lock', $block->facts, 'Block markup carries its lock fact.');
    }

    public function testABlockTemplatesPartBlocksPointAtTheThemesBlockPartsNotItsPhpFiles(): void
    {
        $layout = self::layout([self::block('page')]);
        $block = $layout->item('tpl:' . self::PAGE);

        $this->assertNotNull($block);
        $this->assertSame([], $block->refs);
        $this->assertSame(
            ['part:' . self::CHILD . '//footer', 'part:' . self::CHILD . '//header'],
            $block->facts['missing_refs'],
            'A template part block never renders header.php, so with no block part it is missing.'
        );

        $withParts = self::layout([self::block('page')], [self::block('header', 'Header', 'header'), self::block('footer', 'Footer', 'footer')]);

        $this->assertSame(['part:' . self::CHILD . '//footer', 'part:' . self::CHILD . '//header'], $withParts->item('tpl:' . self::PAGE)?->refs);
        $this->assertSame(['part:footer', 'part:header', 'part:template-parts/content-page'], $withParts->item('tpl:page')?->refs);
        $this->assertSame('header', $withParts->item('part:' . self::CHILD . '//header')?->facts['area']);
    }

    public function testABlockTemplateAsSpecificAsThePhpFileOrMoreWinsTheRoute(): void
    {
        $routes = self::routes([self::PAGE, self::CHILD . '//single']);
        $page = $routes->item('route:single-page');
        $post = $routes->item('route:single-post');

        $this->assertNotNull($page);
        $this->assertSame(self::PAGE, $page->facts['template'], 'page beats page.php at the same place in the hierarchy.');
        $this->assertContains('tpl:' . self::PAGE, $page->refs);
        $this->assertNotContains('tpl:page', $page->refs);
        $this->assertNotContains('part:header', $page->refs, 'The PHP header is not on a page the block template serves.');

        $this->assertSame(self::CHILD . '//single', $post?->facts['template']);
    }

    public function testThePhpFileWinsOverALessSpecificBlockTemplateAndServesWhenThereIsNone(): void
    {
        $theme = self::theme();
        $seen = [];
        $block = static function (array $remaining, string $file) use (&$seen): ?string {
            $seen[] = [$remaining, basename($file)];

            return in_array('singular', $remaining, true) ? self::CHILD . '//singular' : null;
        };

        // single.php sits before singular, so a block `singular` template is never asked about.
        $this->assertSame('single', $theme->resolveWithBlockTemplates(['single-post-hello-world', 'single-post', 'single', 'singular', 'index'], $block));
        $this->assertSame([['single-post-hello-world', 'single-post', 'single'], 'single.php'], $seen[0]);

        // No PHP file at all: every candidate is open to the block templates.
        $this->assertSame(self::CHILD . '//singular', $theme->resolveWithBlockTemplates(['front-page', 'home', 'singular'], $block));
        $this->assertSame([['front-page', 'home', 'singular'], ''], $seen[1]);

        $none = static fn (): ?string => null;
        $this->assertSame('page-contact', $theme->resolveWithBlockTemplates(['page-contact', 'page-12', 'page', 'singular', 'index'], $none));
        $this->assertNull($theme->resolveWithBlockTemplates(['front-page', 'home'], $none));

        $fallback = self::routes([]);
        $this->assertSame('page', $fallback->item('route:single-page')?->facts['template']);
        $this->assertSame('single', $fallback->item('route:single-post')?->facts['template']);
    }

    public function testFindPutsTheSiteEditorTemplateInTheChain(): void
    {
        $result = self::locator([self::PAGE])->find('/about/', 'About us');

        $this->assertSame('tpl:' . self::PAGE, $result['template']);
        $this->assertTrue($result['matched']);
        $this->assertSame(['pt:page', 'tpl:' . self::PAGE, 'route:single-page'], array_column($result['chain'], 'id'));

        $classic = self::locator(null)->find('/about/', 'About us');

        $this->assertSame('tpl:page', $classic['template']);
    }

    public function testAPureClassicThemeIsUnchanged(): void
    {
        $classic = self::layout(null);
        $hybrid = self::layout([self::block('page')]);

        $this->assertSame(['tpl:archive', 'tpl:index', 'tpl:page', 'tpl:page-contact', 'tpl:single', 'tpl:templates/full-width'], self::ids($classic, 'template'));
        $this->assertStringNotContainsString('//', implode(',', array_map(static fn (Item $item): string => $item->id, $classic->items)));

        foreach ($classic->items as $item) {
            $this->assertSame($item->hash, $hybrid->item($item->id)?->hash, $item->id . ' reads the same beside a block template.');
        }

        $this->assertEquals(self::routes(null)->items, self::routes([])->items, 'Block template support with no block templates routes as classic.');
    }

    public function testAPureBlockThemeIsUnchanged(): void
    {
        $source = FakeLayoutSource::fixture();
        $layout = (new LayoutScanner($source))->scan();

        foreach ([...$source->templates, ...$source->parts] as $row) {
            $this->assertSame($row['slug'], LayoutScanner::key($row));
        }

        $this->assertStringNotContainsString('//', implode(',', array_map(static fn (Item $item): string => $item->id, $layout->items)));
        $this->assertContains('part:header', $layout->item('tpl:page-contact')?->refs ?? [], 'Block theme part refs keep their plain slug.');
        $this->assertSame('vwf-fixture//page-contact', substr((string) $layout->item('tpl:page-contact')?->edit, strlen('site-editor.php?p=/wp_template/'), -strlen('&canvas=edit')));
    }

    /**
     * A block row the way WordPressLayoutSource gives one on a hybrid theme:
     * a Site Editor (`custom`) row keyed `<theme>//<slug>`, with a header
     * and footer part around the post content for a template.
     *
     * @return TemplateRow
     */
    private static function block(string $slug, string $title = '', string $area = ''): array
    {
        $content = $area !== ''
            ? FakeLayoutSource::paragraph(ucfirst($area))
            : '<!-- wp:template-part {"slug":"header"} /-->' . "\n" . '<!-- wp:post-content /-->' . "\n" . '<!-- wp:template-part {"slug":"footer"} /-->';

        return ['key' => ClassicTheme::blockKey(self::CHILD, $slug)] + [
            'slug'          => $slug,
            'title'         => $title,
            'theme'         => self::CHILD,
            'source'        => 'custom',
            'user_modified' => false,
            'area'          => $area,
            'content'       => $content,
            'file'          => null,
            'file_theme'    => null,
            'overrides'     => null,
        ];
    }

    /**
     * @return list<string>
     */
    private static function ids(Section $section, string $kind): array
    {
        return array_values(array_map(
            static fn (Item $item): string => $item->id,
            array_filter($section->items, static fn (Item $item): bool => $item->kind === $kind)
        ));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/classic-theme';
    }

    private static function theme(): ClassicTheme
    {
        return new ClassicTheme(
            [
                ['slug' => self::CHILD, 'dir' => self::root() . '/' . self::CHILD],
                ['slug' => self::PARENT, 'dir' => self::root() . '/' . self::PARENT],
            ],
            null,
            [['themes', self::root()]]
        );
    }

    /**
     * The classic theme's rows, then the block rows when the theme supports
     * block templates (`$templates` not null).
     *
     * @param list<TemplateRow>|null $templates
     * @param list<TemplateRow>      $parts
     */
    private static function layoutSource(?array $templates, array $parts = []): FakeLayoutSource
    {
        $theme = self::theme();
        $source = new FakeLayoutSource();
        $source->templates = [...$theme->templates(), ...($templates ?? [])];
        $source->parts = [...$theme->parts(), ...($templates !== null ? $parts : [])];

        return $source;
    }

    /**
     * @param list<TemplateRow>|null $templates
     * @param list<TemplateRow>      $parts
     */
    private static function layout(?array $templates, array $parts = []): Section
    {
        return (new LayoutScanner(self::layoutSource($templates, $parts)))->scan();
    }

    /**
     * A site showing its latest posts, with a post and an about page.
     *
     * @param list<string>|null $hybrid Keys of the block templates that exist, null for a pure classic theme.
     */
    private static function routeSource(?array $hybrid): FakeRouteSource
    {
        $source = new FakeRouteSource();
        $source->classic = self::theme();
        $source->hybrid = $hybrid;
        $source->add(20, 'post', 'hello-world', 'Hello world', '/hello-world/');
        $source->add(11, 'page', 'about', 'About', '/about/', '', FakeLayoutSource::paragraph('About us'));
        $source->postTypes = [
            ['name' => 'page', 'archive_path' => null, 'rewrite' => null, 'hierarchical' => true, 'query_var' => null],
            ['name' => 'post', 'archive_path' => null, 'rewrite' => null, 'hierarchical' => false, 'query_var' => null],
        ];

        return $source;
    }

    /**
     * @param list<string>|null $hybrid
     */
    private static function routes(?array $hybrid): Section
    {
        $templates = $hybrid !== null
            ? array_map(static fn (string $key): array => self::block(substr($key, strlen(self::CHILD) + 2)), $hybrid)
            : null;

        return (new RouteScanner(self::routeSource($hybrid), self::layoutSource($templates)))->scan();
    }

    /**
     * @param list<string>|null $hybrid
     */
    private static function locator(?array $hybrid): EditLocator
    {
        $templates = $hybrid !== null
            ? array_map(static fn (string $key): array => self::block(substr($key, strlen(self::CHILD) + 2)), $hybrid)
            : null;

        return new EditLocator(
            self::routeSource($hybrid),
            self::layoutSource($templates),
            new Locks(),
            new ClientAccess('editor', static fn (string $cap): bool => true, static fn (string $edit): string => 'read'),
            static fn (): ?array => null,
            static fn (): ?string => null
        );
    }
}
