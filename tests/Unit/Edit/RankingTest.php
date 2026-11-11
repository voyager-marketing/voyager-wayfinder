<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Edit;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Edit\Ranking;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * Find returns every home of a match, ranked by a fixed tuple, with an
 * ambiguous flag, while the chain keeps following the top hit.
 *
 * @phpstan-import-type Result from EditLocator
 * @phpstan-import-type Found from Ranking
 */
final class RankingTest extends TestCase
{
    private const PHONE_COPY = 'Call us at (540) 555-0142 any time.';

    public function testTwoCopiesOnThePageRankThePageCopyFirstAndAreAmbiguous(): void
    {
        $result = self::twoCopies()->find('/phone-copy/', '555-0142');

        $this->assertTrue($result['matched']);
        $this->assertTrue($result['ambiguous']);
        $this->assertSame(['pt:page', 'bind:vwf-fixture/business-info.phone'], array_column($result['hits'], 'id'));
        $this->assertSame([1, 2], array_column($result['hits'], 'rank'));
        $this->assertSame([true, true], array_column($result['hits'], 'on_route'));
        $this->assertSame([0, 3], array_column($result['hits'], 'distance'), 'Content 0; template 1, footer part 2, its binding 3.');
    }

    public function testAQueryNamingTheFooterPutsTheFooterCopyFirst(): void
    {
        $result = self::twoCopies()->find('/phone-copy/', 'footer 555-0142');

        $this->assertSame('bind:vwf-fixture/business-info.phone', $result['hits'][0]['id']);
        $this->assertContains('the query names the footer', $result['hits'][0]['why']);
        $this->assertTrue($result['ambiguous'], 'A hint orders two copies; both are still places to edit.');
        $this->assertSame('bind:vwf-fixture/business-info.phone', $result['chain'][0]['id']);
    }

    public function testOnTheHomeUrlTheFooterBindingLeadsAndThePageCopyIsListedOffRoute(): void
    {
        $result = self::twoCopies()->find('/', '555-0142');

        $this->assertSame(['bind:vwf-fixture/business-info.phone', 'page:phone-copy'], array_column($result['hits'], 'id'));
        $this->assertSame([true, false], array_column($result['hits'], 'on_route'));
        $this->assertNull($result['hits'][1]['distance']);
        $this->assertContains('not on this URL', $result['hits'][1]['why']);
        $this->assertFalse($result['ambiguous'], 'Only one copy is on this URL.');
    }

    public function testAPlainPageOffTheRouteIsFoundEvenWhenTheSourceListsOnlyReferencingContent(): void
    {
        $routes = FakeRouteSource::fixture();
        $layout = FakeRouteSource::layout();
        $layout->content[] = ['type' => 'page', 'slug' => 'phone-copy', 'content' => FakeLayoutSource::paragraph(self::PHONE_COPY)];
        $layout->contentReferencesOnly = true;

        $this->assertNotContains('phone-copy', array_column($layout->content(), 'slug'), 'The real source leaves plain content out of content().');

        $result = self::locator($routes, $layout)->find('/', '555-0142');

        $this->assertSame(['bind:vwf-fixture/business-info.phone', 'page:phone-copy'], array_column($result['hits'], 'id'));
    }

    public function testTheChainIsTheTopHitsChain(): void
    {
        $result = self::twoCopies()->find('/phone-copy/', '555-0142');

        $this->assertSame(['pt:page', 'tpl:page', 'route:single-page'], array_column($result['chain'], 'id'));
        $this->assertTrue($result['chain'][0]['match']);
        $this->assertNotContains('bind:vwf-fixture/business-info.phone', array_column($result['chain'], 'id'));
    }

    public function testExactTextBeatsLabelBeatsAttributeBeatsAllWords(): void
    {
        $routes = FakeRouteSource::fixture();
        $routes->add(90070, 'page', 'tiers', 'Tiers', '/tiers/', '', implode('', [
            FakeLayoutSource::paragraph('We give free and fast estimates.'),
            '<!-- wp:image {"alt":"Free estimates here"} /-->',
            '<!-- wp:navigation-link {"label":"Free estimates today","url":"/contact/"} /-->',
            FakeLayoutSource::paragraph('Free  estimates'),
        ]));

        $result = self::locator($routes)->find('/tiers/', 'free estimates');

        $this->assertSame(['3', '2', '1', '0'], array_column($result['hits'], 'block_path'));
        $this->assertSame(
            ['exact text', 'label', 'text attribute', 'all words present'],
            array_map(static fn (array $hit): string => $hit['why'][0], $result['hits'])
        );
    }

    public function testDigitHeavyQueriesMatchTheSameBoundValueWhateverTheSeparators(): void
    {
        foreach (['540.555.0142', '(540) 555-0142', '5405550142', '555-0142'] as $query) {
            $result = self::locator()->find('/', $query);

            $this->assertTrue($result['matched'], $query);
            $this->assertSame('bind:vwf-fixture/business-info.phone', $result['hits'][0]['id'], $query);
        }

        $this->assertSame('exact text', self::locator()->find('/', '540.555.0142')['hits'][0]['why'][0]);
        $this->assertFalse(self::locator()->find('/', '540 555 9999')['matched']);
    }

    public function testACodeOnlyHomeSortsLastButIsListed(): void
    {
        $layout = FakeRouteSource::layout();
        $layout->registered['vwf/offer'] = ['title' => 'Offer', 'content' => FakeLayoutSource::paragraph('Seasonal offer')];
        $layout->content[] = ['type' => 'page', 'slug' => 'offers', 'content' => '<!-- wp:pattern {"slug":"vwf/offer"} /-->' . FakeLayoutSource::paragraph('Seasonal offer')];

        $result = self::locator(FakeRouteSource::fixture(), $layout)->find('/about/', 'Seasonal offer');

        $this->assertSame(['page:offers', 'pat:vwf/offer'], array_column($result['hits'], 'id'));
        $this->assertContains('edited in code only', $result['hits'][1]['why']);
        $this->assertFalse($result['matched'], 'Neither home is on /about/.');
    }

    public function testASingleHitIsNotAmbiguousAndAMissHasNoHits(): void
    {
        $one = self::locator()->find('/', '555-0142');
        $this->assertCount(1, $one['hits']);
        $this->assertFalse($one['ambiguous']);

        $none = self::locator()->find('/about/', 'free estimates');
        $this->assertSame([], $none['hits']);
        $this->assertFalse($none['ambiguous']);
        $this->assertFalse($none['matched']);
    }

    public function testHitsCountRoutesFromTheStoredRoutesSectionAndNeverLeakBoundValues(): void
    {
        $routeRefs = static fn (): array => [
            'route:front-page'  => ['part:footer', 'bind:vwf-fixture/business-info.phone', 'pt:page', 'tpl:page'],
            'route:single-page' => ['part:footer', 'bind:vwf-fixture/business-info.phone', 'pt:page', 'tpl:page'],
            'route:archive'     => ['part:footer', 'tpl:archive'],
        ];

        $hit = self::twoCopies($routeRefs)->find('/phone-copy/', '555-0142')['hits'];

        $this->assertSame([2, 2], array_column($hit, 'routes'));
        $this->assertSame('bound: bind:vwf-fixture/business-info.phone', $hit[1]['snippet']);
        $this->assertStringNotContainsString('555', $hit[1]['snippet']);
        $this->assertSame(self::PHONE_COPY, $hit[0]['snippet']);
        $this->assertNull(self::locator()->find('/', '555-0142')['hits'][0]['routes'], 'No stored manifest, no count.');
    }

    public function testTheQuerySplitsLocationHintsFromTheTextToMatch(): void
    {
        $query = Ranking::query('  Footer   (540)&nbsp;555-0142 ', ['trust bar']);

        $this->assertSame('(540) 555-0142', $query['text']);
        $this->assertSame(['footer'], $query['hints']);
        $this->assertSame('5405550142', $query['digits']);

        $onlyPlace = Ranking::query('Footer');
        $this->assertSame('footer', $onlyPlace['text'], 'A query that is only a place word still matches as text.');
        $this->assertSame([], $onlyPlace['hints']);
        $this->assertNull(Ranking::query('free estimates')['digits']);
    }

    public function testRoutesBreakTiesFewerFirstWhenThePageIsNamed(): void
    {
        $wide = self::found('pat:cta', 9);
        $narrow = self::found('pat:hero', 1);

        $this->assertSame(['pat:cta', 'pat:hero'], array_column(Ranking::rank([$narrow, $wide], false)['hits'], 'id'));
        $this->assertSame(['pat:hero', 'pat:cta'], array_column(Ranking::rank([$wide, $narrow], true)['hits'], 'id'));
        $this->assertTrue(Ranking::rank([$wide, $narrow], true)['ambiguous']);
        $this->assertFalse(Ranking::rank([$wide, ['tier' => 3] + $narrow], true)['ambiguous'], 'A worse tier is not a tie.');
    }

    public function testSnippetsStopAtEightyCharacters(): void
    {
        $snippet = Ranking::snippet(str_repeat('word ', 40));

        $this->assertSame(80, mb_strlen($snippet));
        $this->assertStringEndsWith('…', $snippet);
        $this->assertSame('Short & sweet', Ranking::snippet('<b>Short</b> &amp;   sweet'));
    }

    /**
     * The fixture plus a page at /phone-copy/ whose body repeats the phone
     * number the footer binds.
     *
     * @param (callable(): (array<string, list<string>>|null))|null $routeRefs
     */
    private static function twoCopies(?callable $routeRefs = null): EditLocator
    {
        $routes = FakeRouteSource::fixture();
        $routes->add(90071, 'page', 'phone-copy', 'Phone copy', '/phone-copy/', '', FakeLayoutSource::paragraph(self::PHONE_COPY));

        $layout = FakeRouteSource::layout();
        $layout->content[] = ['type' => 'page', 'slug' => 'phone-copy', 'content' => FakeLayoutSource::paragraph(self::PHONE_COPY)];

        return self::locator($routes, $layout, $routeRefs);
    }

    /**
     * @param (callable(): (array<string, list<string>>|null))|null $routeRefs
     */
    private static function locator(?FakeRouteSource $routes = null, ?FakeLayoutSource $layout = null, ?callable $routeRefs = null): EditLocator
    {
        return new EditLocator(
            $routes ?? FakeRouteSource::fixture(),
            $layout ?? FakeRouteSource::layout(),
            new Locks('7.1.2'),
            new ClientAccess('editor', static fn (string $cap): bool => true, static fn (string $edit): string => 'read'),
            $routeRefs ?? static fn (): ?array => null
        );
    }

    /**
     * @return Found
     */
    private static function found(string $id, int $routes): array
    {
        return [
            'id'        => $id,
            'in'        => $id,
            'path'      => '0',
            'snippet'   => 'x',
            'tier'      => Ranking::TIER_EXACT,
            'on_route'  => false,
            'distance'  => null,
            'routes'    => $routes,
            'hint'      => null,
            'code_only' => false,
            'lock'      => ['effective' => 'none', 'src' => 'none', 'text_editable' => true],
        ];
    }
}
