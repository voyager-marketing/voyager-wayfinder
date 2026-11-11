<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Scanners\ClassicTheme;
use Voyager\Wayfinder\Scanners\ContentModelScanner;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\RouteScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * Classic (PHP) theme support, on the child and parent theme in
 * tests/fixtures/classic-theme, with no database and no theme code run.
 */
final class ClassicTemplateTest extends TestCase
{
    private const CHILD = 'vwf-classic-child';

    private const PARENT = 'vwf-classic';

    public function testTheSinglePostRouteResolvesToTheChildFileWithItsHeaderAndFooter(): void
    {
        $route = self::routes()->item('route:single-post');

        $this->assertNotNull($route);
        $this->assertSame('single', $route->facts['template']);
        $this->assertSame(
            [
                'part:footer',
                'part:header',
                'part:template-parts/content-single',
                'part:template-parts/entry-meta',
                'pt:post',
                'tpl:single',
            ],
            $route->refs
        );

        $layout = self::layout();
        $single = $layout->item('tpl:single');
        $header = $layout->item('part:header');
        $footer = $layout->item('part:footer');

        $this->assertNotNull($single);
        $this->assertSame('themes/vwf-classic-child/single.php', $single->facts['file']);
        $this->assertSame(self::CHILD, $single->facts['file_theme']);
        $this->assertSame(self::PARENT, $single->facts['overrides']);
        $this->assertNull($single->edit, 'A PHP template has no Site Editor path.');
        $this->assertSame(['part:footer', 'part:header', 'part:template-parts/content-single'], $single->refs);

        $this->assertSame(['area' => 'header', 'file' => 'themes/vwf-classic-child/header.php', 'file_theme' => self::CHILD, 'overrides' => self::PARENT], self::fileFacts($header));
        $this->assertSame(['area' => 'footer', 'file' => 'themes/vwf-classic/footer.php', 'file_theme' => self::PARENT, 'overrides' => null], self::fileFacts($footer));
    }

    public function testCandidatesTryTheChildThenTheParentForEachNameInTurn(): void
    {
        $theme = self::theme();

        $this->assertSame('single', $theme->resolve(['single-post-hello-world', 'single-post', 'single', 'singular', 'index']));
        $this->assertSame('page-contact', $theme->resolve(['page-contact', 'page-12', 'page', 'singular', 'index']));
        $this->assertSame('archive', $theme->resolve(['archive-service', 'archive', 'index']));
        $this->assertSame('templates/full-width', $theme->resolve(['templates/full-width', 'page-wide', 'page']));
        $this->assertNull($theme->resolve(['front-page', 'home']));

        $this->assertStringEndsWith('/vwf-classic-child/single.php', (string) $theme->locateFile(['single.php']));
        $this->assertStringEndsWith('/vwf-classic/page.php', (string) $theme->locateFile(['page.php', 'single.php']));
    }

    public function testTemplatesAreHierarchyFilesAndNamedTemplatesNotFunctionsOrParts(): void
    {
        $templates = array_values(array_filter(self::layout()->items, static fn (Item $item): bool => $item->kind === 'template'));

        $this->assertSame(
            ['tpl:archive', 'tpl:index', 'tpl:page', 'tpl:page-contact', 'tpl:single', 'tpl:templates/full-width'],
            array_map(static fn (Item $item): string => $item->id, $templates)
        );
        $this->assertSame('Full width', self::layout()->item('tpl:templates/full-width')?->label);
        $this->assertSame('single.php', self::layout()->item('tpl:single')?->label);
        $this->assertSame(['part:template-parts/missing'], self::layout()->item('tpl:index')?->facts['missing_refs']);
    }

    public function testIncludesAreReadFromCallsWithLiteralArgumentsOnly(): void
    {
        $php = <<<'PHP'
            <?php
            // get_header('commented');
            $text = 'get_footer()';
            $theme->get_template_part('method');
            Theme::get_header('static');
            function get_footer() {}
            get_header( 'shop' );
            \get_footer();
            get_template_part( 'template-parts/content', get_post_type() );
            get_template_part( $slug );
            GET_TEMPLATE_PART('loud', null);
            PHP;

        $calls = ClassicTheme::calls($php);

        $this->assertSame(
            [
                ['function' => 'get_header', 'args' => ['shop']],
                ['function' => 'get_footer', 'args' => []],
                ['function' => 'get_template_part', 'args' => ['template-parts/content', null]],
                ['function' => 'get_template_part', 'args' => [null]],
                ['function' => 'get_template_part', 'args' => ['loud', '']],
            ],
            $calls
        );
        $this->assertSame(
            [['header-shop.php', 'header.php'], ['footer.php'], ['template-parts/content.php'], [], ['loud.php']],
            array_map([ClassicTheme::class, 'candidates'], $calls)
        );
    }

    public function testPartsFallBackLikeCoreAndTakeTheirAreaFromTheCall(): void
    {
        $layout = self::layout();
        $archive = $layout->item('tpl:archive');

        $this->assertNotNull($archive);
        // get_footer('archive') falls back to footer.php; a dynamic name falls back to content.php.
        $this->assertSame(['part:footer', 'part:header', 'part:template-parts/content'], $archive->refs);
        $this->assertSame(
            [
                'part:footer',
                'part:header',
                'part:template-parts/content',
                'part:template-parts/content-page',
                'part:template-parts/content-single',
                'part:template-parts/entry-meta',
            ],
            array_values(array_map(
                static fn (Item $item): string => $item->id,
                array_filter($layout->items, static fn (Item $item): bool => $item->kind === 'template_part')
            ))
        );
        $this->assertSame('uncategorized', $layout->item('part:template-parts/entry-meta')?->facts['area']);
        $this->assertSame(['part:template-parts/entry-meta'], $layout->item('part:template-parts/content-single')?->refs);
        $this->assertSame(
            ['tpl:archive', 'tpl:index', 'tpl:page', 'tpl:page-contact', 'tpl:single', 'tpl:templates/full-width'],
            $layout->item('part:footer')?->stats['used_in']
        );
    }

    public function testMenuLocationsAndSidebarsCarryTheirEditPaths(): void
    {
        $source = self::layoutSource();
        $source->menuLocations = [
            ['slug' => 'primary', 'label' => 'Primary menu', 'menu' => ['id' => 7, 'name' => 'Main', 'items' => 5]],
            ['slug' => 'footer', 'label' => 'Footer menu', 'menu' => null],
        ];
        $source->sidebars = [['id' => 'sidebar-1', 'name' => 'Blog sidebar', 'widgets' => 3]];

        $section = (new LayoutScanner($source))->scan();
        $primary = $section->item('loc:primary');
        $footer = $section->item('loc:footer');
        $sidebar = $section->item('sidebar:sidebar-1');

        $this->assertNotNull($primary);
        $this->assertSame('menu_location', $primary->kind);
        $this->assertSame(['menu' => 'Main'], $primary->facts);
        $this->assertSame('nav-menus.php?action=edit&menu=7', $primary->edit);
        $this->assertSame(['item_count' => 5], array_diff_key($primary->stats, ['client' => true]));
        $this->assertSame('nav-menus.php?action=locations', $footer?->edit);
        $this->assertSame(['menu' => null], $footer->facts);

        $this->assertNotNull($sidebar);
        $this->assertSame('Blog sidebar', $sidebar->label);
        $this->assertSame('widgets.php', $sidebar->edit);
        $this->assertSame(['widget_count' => 3], array_diff_key($sidebar->stats, ['client' => true]), 'A widget count is content, so it is a stat.');
        $this->assertSame('edit_theme_options', $sidebar->stats['client']['needs'] ?? null, 'Who can open it is a stat too.');

        // No get_role() in the unit suite, so the default client role can open nothing.
        $this->assertSame('menu Main; 1-5 items; editor cannot open: needs edit_theme_options', Facts::describe($primary));
        $this->assertSame('no menu assigned; editor cannot open: needs edit_theme_options', Facts::describe($footer));
        $this->assertSame('1-5 widgets; editor cannot open: needs edit_theme_options', Facts::describe($sidebar));
    }

    public function testAcfOptionsPagesLinkTheFieldGroupsLocatedOnThem(): void
    {
        $groups = [
            self::group('group_site', 'Site settings', [[['param' => 'options_page', 'operator' => '==', 'value' => 'theme-settings']]], ['phone', 'address']),
            self::group('group_social', 'Social links', [[['param' => 'options_page', 'operator' => '==', 'value' => 'theme-settings']]], ['facebook']),
            self::group('group_service', 'Service fields', [[['param' => 'post_type', 'operator' => '==', 'value' => 'service']]], ['price_from']),
        ];
        $pages = [
            ['slug' => 'theme-settings', 'title' => 'Theme settings', 'parent' => null],
            ['slug' => 'footer-settings', 'title' => 'Footer settings', 'parent' => 'theme-settings'],
            ['slug' => 'tracking', 'title' => 'Tracking', 'parent' => 'options-general.php'],
        ];

        $section = (new ContentModelScanner(
            static fn (): array => [],
            static fn (): array => [],
            static fn (string $postType): array => [],
            static fn (): array => $groups,
            static fn (): array => $pages
        ))->scan();

        $settings = $section->item('optpage:theme-settings');

        $this->assertNotNull($settings);
        $this->assertSame('options_page', $settings->kind);
        $this->assertSame(
            ['field_groups' => ['Site settings', 'Social links'], 'fields' => ['address', 'facebook', 'phone'], 'parent' => null],
            $settings->facts
        );
        $this->assertSame('admin.php?page=theme-settings', $settings->edit);
        $this->assertSame('admin.php?page=footer-settings', $section->item('optpage:footer-settings')?->edit);
        $this->assertSame('options-general.php?page=tracking', $section->item('optpage:tracking')?->edit);
        $this->assertSame('fields address, facebook, phone; ACF groups Site settings, Social links', Facts::describe($settings));
        $this->assertSame('3 fields; ACF groups Site settings, Social links', Facts::describe($settings, false));
    }

    public function testRowsCarryNoAbsolutePathsOrSource(): void
    {
        $theme = self::theme();
        $json = (string) json_encode([$theme->templates(), $theme->parts()], JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString(str_replace('\\', '/', self::root()), str_replace('\\\\', '/', $json));
        $this->assertStringNotContainsString('get_header', $json, 'Rows carry includes, never the PHP source.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function fileFacts(?Item $item): array
    {
        $facts = $item?->facts ?? [];

        return array_intersect_key($facts, array_flip(['area', 'file', 'file_theme', 'overrides']));
    }

    /**
     * @param list<list<array{param: string, operator: string, value: string}>> $location
     * @param list<string>                                                        $fields
     * @return array{key: string, title: string, id: int, active: bool, show_in_rest: bool, location: list<list<array{param: string, operator: string, value: string}>>, fields: list<array{name: string, label: string, type: string, required: bool}>}
     */
    private static function group(string $key, string $title, array $location, array $fields): array
    {
        return [
            'key'          => $key,
            'title'        => $title,
            'id'           => 0,
            'active'       => true,
            'show_in_rest' => false,
            'location'     => $location,
            'fields'       => array_map(
                static fn (string $name): array => ['name' => $name, 'label' => $name, 'type' => 'text', 'required' => false],
                $fields
            ),
        ];
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

    private static function layoutSource(): FakeLayoutSource
    {
        $theme = self::theme();
        $source = new FakeLayoutSource();
        $source->templates = $theme->templates();
        $source->parts = $theme->parts();

        return $source;
    }

    private static function layout(): Section
    {
        return (new LayoutScanner(self::layoutSource()))->scan();
    }

    /**
     * A site showing its latest posts, with a post, two plain pages and a
     * page on the full width template.
     */
    private static function routes(): Section
    {
        $source = new FakeRouteSource();
        $source->classic = self::theme();
        $source->add(20, 'post', 'hello-world', 'Hello world', '/hello-world/');
        $source->add(11, 'page', 'about', 'About', '/about/');
        $source->add(12, 'page', 'contact', 'Contact', '/contact/');
        $source->add(13, 'page', 'wide', 'Wide', '/wide/', 'templates/full-width.php');
        $source->postTypes = [
            ['name' => 'page', 'archive_path' => null, 'rewrite' => null, 'hierarchical' => true, 'query_var' => null],
            ['name' => 'post', 'archive_path' => null, 'rewrite' => null, 'hierarchical' => false, 'query_var' => null],
        ];

        return (new RouteScanner($source, self::layoutSource()))->scan();
    }
}
