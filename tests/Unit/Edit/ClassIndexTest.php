<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Edit;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\BlockTreeBuilder;
use Voyager\Wayfinder\Blocks\ClassIndex;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * The class index: className tokens, block names and style variations,
 * counted in the existing block walk, stored as facts, and searchable
 * through find's `class` input.
 *
 * @phpstan-import-type Result from EditLocator
 */
final class ClassIndexTest extends TestCase
{
    public function testClassNameSplitsIntoLowercasedUniqueTokens(): void
    {
        $this->assertSame(
            ['hide-mobile', 'vwf-probe', 'is-style-outline'],
            ClassIndex::tokens(['className' => "  Hide-Mobile\tvwf-probe  hide-mobile IS-STYLE-OUTLINE "])
        );
        $this->assertSame([], ClassIndex::tokens([]));
        $this->assertSame([], ClassIndex::tokens(['className' => '   ']));
        $this->assertSame([], ClassIndex::tokens(['className' => ['not', 'a', 'string']]));
    }

    public function testStyleVariationsKeepTheirSlugAndAreNotHidden(): void
    {
        $this->assertSame('outline', ClassIndex::style('is-style-outline'));
        $this->assertNull(ClassIndex::style('is-style-'));
        $this->assertNull(ClassIndex::style('hide-mobile'));

        $onlyStyles = ClassIndex::facts(['is-style-outline' => 2], []);
        $this->assertSame([['class' => 'is-style-outline', 'count' => 2, 'style' => 'outline']], $onlyStyles['classes']);
        $this->assertFalse($onlyStyles['hidden_classes'], 'A style variation shows in the editor\'s Styles panel.');

        $this->assertTrue(ClassIndex::facts(['is-style-outline' => 1, 'hide-mobile' => 1], [])['hidden_classes']);
        $this->assertSame(['classes' => [], 'block_names' => [], 'hidden_classes' => false], ClassIndex::facts([], []));
    }

    public function testTheWalkCountsEachClassAndBlockNameOncePerBlock(): void
    {
        $markup = '<!-- wp:group {"className":"hide-mobile band","metadata":{"name":"Promo"}} --><div class="wp-block-group">'
            . '<!-- wp:paragraph {"className":"hide-mobile hide-mobile"} --><p>One</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph {"className":"Band","metadata":{"name":"Promo"}} --><p>Two</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","className":"hide-print"} /-->';

        $summary = (new BlockTreeBuilder())->build(parse_blocks($markup))['summary'];

        $this->assertSame(['band' => 2, 'hide-mobile' => 2, 'hide-print' => 1], $summary['classes']);
        $this->assertSame(['Promo' => 2], $summary['names']);
        $this->assertSame(
            [
                'classes'        => [['class' => 'band', 'count' => 2], ['class' => 'hide-mobile', 'count' => 2], ['class' => 'hide-print', 'count' => 1]],
                'block_names'    => [['name' => 'Promo', 'count' => 2]],
                'hidden_classes' => true,
            ],
            ClassIndex::facts($summary['classes'], $summary['names'])
        );
    }

    public function testAtMostFiftyClassesAreListedAndTheRestCounted(): void
    {
        $classes = [];
        for ($i = 1; $i <= 53; $i++) {
            $classes[sprintf('c-%02d', $i)] = 1;
        }

        $facts = ClassIndex::facts($classes, ['Hero' => 1]);

        $this->assertCount(ClassIndex::CAP, $facts['classes']);
        $this->assertSame('c-01', $facts['classes'][0]['class']);
        $this->assertSame('c-50', $facts['classes'][49]['class']);
        $this->assertSame(3, $facts['classes_more'] ?? null);
        $this->assertSame([['name' => 'Hero', 'count' => 1]], $facts['block_names']);
    }

    public function testTheFixtureFooterPartCarriesTheClassesInItsFile(): void
    {
        $footer = FakeLayoutSource::themeFile('parts/footer.html');
        preg_match_all('/"className":"([^"]*)"/', $footer, $found);
        $expected = [];
        foreach ($found[1] as $className) {
            foreach (ClassIndex::tokens(['className' => $className]) as $token) {
                $expected[$token] = ($expected[$token] ?? 0) + 1;
            }
        }

        $item = (new LayoutScanner(FakeLayoutSource::fixture(), new Locks()))->scan()->item('part:footer');

        $this->assertNotNull($item);
        $this->assertSame(
            array_map(static fn (string $class, int $count): array => ['class' => $class, 'count' => $count], array_keys($expected), array_values($expected)),
            $item->facts['classes']
        );
        $this->assertSame($expected !== [], $item->facts['hidden_classes']);
    }

    public function testAClassInsideATemplatePartFindsThePartAndCountsItsBlocks(): void
    {
        $layout = FakeRouteSource::layout();
        $layout->parts = [
            FakeLayoutSource::row('footer', '<!-- wp:paragraph {"className":"hide-mobile"} --><p>Open 7 days</p><!-- /wp:paragraph -->'
                . '<!-- wp:paragraph {"className":"Hide-Mobile small"} --><p>Licensed</p><!-- /wp:paragraph -->', 'Footer', 'footer'),
            FakeLayoutSource::row('header', FakeLayoutSource::paragraph('Riverbend'), 'Header', 'header'),
        ];

        $result = self::locator($layout)->find('/about/', null, '.hide-mobile');

        $this->assertSame('hide-mobile', $result['class']);
        $this->assertTrue($result['matched']);
        $this->assertSame(['part:footer', 'tpl:page', 'route:single-page'], self::ids($result));
        $this->assertTrue($result['chain'][0]['match']);
        $this->assertSame(2, $result['chain'][0]['class_count'] ?? null);
        $this->assertArrayNotHasKey('class_count', $result['chain'][1], 'Only hits count their blocks.');
    }

    public function testAClassInsideASyncedPatternFindsThePatternWithItsUsages(): void
    {
        $layout = FakeRouteSource::layout();
        $layout->patterns[0]['content'] = '<!-- wp:group {"className":"cta-band"} --><div class="wp-block-group cta-band">'
            . FakeLayoutSource::paragraph('Need a plumber today?') . '</div><!-- /wp:group -->';

        $result = self::locator($layout)->find('/about/', null, 'cta-band');

        $this->assertTrue($result['matched']);
        $this->assertSame('pat:cta-banner', $result['chain'][0]['id']);
        $this->assertSame(1, $result['chain'][0]['class_count'] ?? null);
        $this->assertSame(3, $result['chain'][0]['usage_count'] ?? null);
    }

    public function testAClassInPostContentCountsItsBlocksAndTheContentEntryCarriesClassFacts(): void
    {
        $routes = FakeRouteSource::fixture();
        $routes->add(90060, 'page', 'probe', 'Probe', '/probe/', '', '<!-- wp:paragraph {"className":"hide-mobile vwf-probe"} --><p class="hide-mobile vwf-probe">Probe</p><!-- /wp:paragraph -->');

        $result = (new EditLocator($routes, FakeRouteSource::layout(), new Locks(), self::client()))->find('/probe/', null, 'hide-mobile');

        $content = $result['chain'][0];
        $this->assertTrue($result['matched']);
        $this->assertSame('pt:page', $content['id']);
        $this->assertSame(1, $content['class_count'] ?? null);
        $this->assertSame([['class' => 'hide-mobile', 'count' => 1], ['class' => 'vwf-probe', 'count' => 1]], $content['classes'] ?? null);
        $this->assertTrue($content['hidden_classes'] ?? null);

        $none = (new EditLocator($routes, FakeRouteSource::layout(), new Locks(), self::client()))->find('/probe/', null, 'nope');
        $this->assertFalse($none['matched']);
    }

    public function testTextAndClassTogetherNarrowToBlocksMatchingBoth(): void
    {
        $routes = FakeRouteSource::fixture();
        $routes->add(90061, 'page', 'twins', 'Twins', '/twins/', '', '<!-- wp:paragraph {"className":"promo"} --><p>Call today</p><!-- /wp:paragraph -->'
            . FakeLayoutSource::paragraph('Call today'));
        $locator = new EditLocator($routes, FakeRouteSource::layout(), new Locks(), self::client());

        $both = $locator->find('/twins/', 'Call today', 'promo');
        $this->assertTrue($both['matched']);
        $this->assertSame(1, $both['chain'][0]['class_count'] ?? null, 'Only the paragraph with the class counts.');

        $wrongClass = $locator->find('/twins/', 'Call today', 'hide-mobile');
        $this->assertFalse($wrongClass['matched']);
        $this->assertSame(
            'No block on this page contains that text and carries that class or block name. It may come from PHP, a plugin or a dynamic block. The chain lists everything the page is built from.',
            $wrongClass['message']
        );

        // A phone number that comes from a binding still matches by text, but not under a class its block lacks.
        $this->assertTrue($locator->find('/', '555-0142')['matched']);
        $this->assertFalse($locator->find('/', '555-0142', 'promo')['matched']);
    }

    public function testNoMatchKeepsTheMessageShapeAndTheWholeChain(): void
    {
        $result = self::locator(FakeRouteSource::layout())->find('/about/', null, 'not-on-this-page');

        $this->assertFalse($result['matched']);
        $this->assertSame(
            'No block on this page carries that class or block name. It may come from PHP, a plugin or a dynamic block. The chain lists everything the page is built from.',
            $result['message']
        );
        $this->assertSame(self::ids(self::locator(FakeRouteSource::layout())->find('/about/')), self::ids($result));
        $this->assertNull(self::locator(FakeRouteSource::layout())->find('/about/', null, '  .  ')['matched'], 'An empty class is no class.');
    }

    public function testABlockNameMatchesThroughTheClassInput(): void
    {
        $layout = FakeRouteSource::layout();
        $layout->parts[0] = FakeLayoutSource::row('footer', '<!-- wp:group {"metadata":{"name":"Trust badges"}} --><div class="wp-block-group">'
            . FakeLayoutSource::paragraph('Licensed and insured') . '</div><!-- /wp:group -->', 'Footer', 'footer');

        $result = self::locator($layout)->find('/', null, 'trust badges');

        $this->assertTrue($result['matched']);
        $this->assertSame('part:footer', $result['chain'][0]['id']);

        $item = (new LayoutScanner($layout, new Locks()))->scan()->item('part:footer');
        $this->assertSame([['name' => 'Trust badges', 'count' => 1]], $item?->facts['block_names']);
        $this->assertFalse($item?->facts['hidden_classes'], 'A block name is not a CSS class.');
    }

    public function testAClassOnAPointerBlockFindsTheContainerThatPlacesIt(): void
    {
        $layout = FakeRouteSource::layout();
        foreach ($layout->templates as $i => $row) {
            if ($row['slug'] === 'page') {
                $layout->templates[$i]['content'] = str_replace('{"slug":"footer"}', '{"slug":"footer","className":"hide-print"}', $row['content']);
            }
        }

        $result = self::locator($layout)->find('/about/', null, 'hide-print');

        $this->assertTrue($result['matched']);
        $this->assertSame('tpl:page', $result['chain'][0]['id']);
        $this->assertSame(1, $result['chain'][0]['class_count'] ?? null);
    }

    private static function locator(FakeLayoutSource $layout): EditLocator
    {
        return new EditLocator(FakeRouteSource::fixture(), $layout, new Locks(), self::client());
    }

    private static function client(): ClientAccess
    {
        return new ClientAccess('editor', static fn (string $cap): bool => true, static fn (string $edit): string => 'read');
    }

    /**
     * @param Result $result
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        return array_map(static fn (array $entry): string => $entry['id'], $result['chain']);
    }
}
