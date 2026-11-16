<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\RefreshPlan;
use Voyager\Wayfinder\Tests\Support\OpenBlocksStub;

/**
 * What a Site Data change refreshes, read from a stub of the
 * voyager-open-blocks pack's site_data_plan(), and nothing at all when the
 * pack is missing or its plan fails.
 */
final class RefreshPlanTest extends TestCase
{
    /** Post ID => slug of the six pages that bind the phone, in no order. */
    private const PAGES = [12 => 'service-areas', 4 => 'about', 9 => 'contact', 30 => 'emergency', 7 => 'book', 15 => 'pricing'];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        OpenBlocksStub::load();
        $this->logged = [];
    }

    public function testThePackHelperIsFoundByNameAndAskedForOneKey(): void
    {
        OpenBlocksStub::$plans = ['phone' => OpenBlocksStub::plan('phone', self::permalinks(self::PAGES))];

        $function = RefreshPlan::planFunction(OpenBlocksStub::FUNCTION);

        $this->assertSame(OpenBlocksStub::FUNCTION, $function);
        $this->assertSame(
            [
                'all_pages' => false,
                'count'     => 7,
                'pages'     => ['page:about', 'page:book', 'page:contact', 'page:emergency', 'page:pricing', 'page:service-areas'],
                'templates' => [],
            ],
            $this->plan($function)->refreshes('phone'),
            'six pages and the front page, the pages sorted as used_in sorts'
        );
        $this->assertSame([['phone']], OpenBlocksStub::$calls);
        $this->assertSame([], $this->logged);
    }

    public function testWithoutThePackThereIsNoStatAndNothingIsAsked(): void
    {
        $missing = 'Voyager\\Wayfinder\\Tests\\Support\\NoSuchPack\\site_data_plan';

        $this->assertNull(RefreshPlan::planFunction($missing));
        $this->assertNull(RefreshPlan::planFunction(), 'the real pack is not loaded in the unit suite');
        $this->assertNull($this->plan(RefreshPlan::planFunction($missing))->refreshes('phone'));
        $this->assertNull(RefreshPlan::forSite($missing)->refreshes('phone'));
        $this->assertSame([], OpenBlocksStub::$calls);
        $this->assertSame([], $this->logged);
    }

    public function testAPlanThatThrowsGivesNoStatAndOnlyTheLogSaysWhy(): void
    {
        $plan = $this->plan(static function (array $keys): never {
            throw new RuntimeException('usage index table missing');
        });

        $this->assertNull($plan->refreshes('phone'));
        $this->assertSame(['Wayfinder could not read what a change to Site Data key "phone" refreshes: usage index table missing'], $this->logged);
    }

    public function testAPlanInAnotherShapeGivesNoStat(): void
    {
        foreach (['a string' => 'nope', 'no context' => ['urls' => []], 'no urls' => ['context' => []]] as $case => $answer) {
            $this->logged = [];

            $this->assertNull($this->plan(static fn (array $keys): mixed => $answer)->refreshes('phone'), $case);
            $this->assertCount(1, $this->logged, $case);
        }
    }

    public function testANullPlanIsAnAnswerThatAChangeRefreshesNothing(): void
    {
        $this->assertSame(
            ['all_pages' => false, 'count' => 0, 'pages' => [], 'templates' => []],
            $this->plan(OpenBlocksStub::FUNCTION)->refreshes('fax'),
            'the pack says nothing published shows the key'
        );
        $this->assertSame([], $this->logged);
    }

    public function testTheFrontPageCountsOnceWhenItIsOneOfThePages(): void
    {
        $posts = self::permalinks(self::PAGES);
        $posts[4] = OpenBlocksStub::HOME;
        OpenBlocksStub::$plans = ['phone' => OpenBlocksStub::plan('phone', $posts)];

        $refreshes = $this->plan(OpenBlocksStub::FUNCTION)->refreshes('phone');

        $this->assertNotNull($refreshes);
        $this->assertSame(6, $refreshes['count']);
        $this->assertCount(6, $refreshes['pages']);
    }

    public function testPagesAreCappedLikeUsedInAndTemplatesSorted(): void
    {
        $slugs = [];
        for ($id = 1; $id <= 60; $id++) {
            $slugs[$id] = sprintf('page-%02d', 61 - $id);
        }

        OpenBlocksStub::$plans = ['phone' => OpenBlocksStub::plan('phone', self::permalinks($slugs), ['vwf-fixture//header', 'vwf-fixture//footer', 'vwf-fixture//header'])];

        $refreshes = $this->plan(OpenBlocksStub::FUNCTION, $slugs)->refreshes('phone');

        $this->assertNotNull($refreshes);
        $this->assertTrue($refreshes['all_pages']);
        $this->assertSame(61, $refreshes['count']);
        $this->assertCount(LayoutScanner::USED_IN_CAP, $refreshes['pages']);
        $this->assertSame('page:page-01', $refreshes['pages'][0], 'sorted before the cap, as used_in is');
        $this->assertSame('page:page-50', $refreshes['pages'][49]);
        $this->assertSame(['vwf-fixture//footer', 'vwf-fixture//header'], $refreshes['templates']);
    }

    public function testAPostThatNoLongerExistsIsLeftOutOfThePages(): void
    {
        OpenBlocksStub::$plans = ['phone' => OpenBlocksStub::plan('phone', [4 => 'https://example.test/about/', 99 => 'https://example.test/gone/'])];

        $refreshes = $this->plan(OpenBlocksStub::FUNCTION)->refreshes('phone');

        $this->assertNotNull($refreshes);
        $this->assertSame(['page:about'], $refreshes['pages']);
        $this->assertSame(3, $refreshes['count'], 'the count is the URLs the plan lists, whatever the lookup finds');
    }

    /**
     * A plan whose posts are pages with these slugs, logging to this test.
     *
     * @param (callable(list<string>): mixed)|null $function
     * @param array<int, string>                   $slugs    Post ID => slug.
     */
    private function plan(?callable $function, array $slugs = self::PAGES): RefreshPlan
    {
        return new RefreshPlan(
            $function,
            static fn (int $id): ?string => isset($slugs[$id]) ? 'page:' . $slugs[$id] : null,
            function (string $message): void {
                $this->logged[] = $message;
            }
        );
    }

    /**
     * @param array<int, string> $slugs Post ID => slug.
     * @return array<int, string> Post ID => permalink.
     */
    private static function permalinks(array $slugs): array
    {
        return array_map(static fn (string $slug): string => OpenBlocksStub::HOME . $slug . '/', $slugs);
    }
}
