<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Render\Human\ManualView;
use Voyager\Wayfinder\Render\Human\PageState;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\OptionScreens;
use Voyager\Wayfinder\Scanners\RefreshPlan;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;
use Voyager\Wayfinder\Tests\Support\OpenBlocksStub;
use Voyager\Wayfinder\Tests\Support\WpCalls;

/**
 * A `voyager/site-data` binding's `used_in` is direct: a phone bound in the
 * footer reads `part:footer`, not the pages that show the footer. With the
 * voyager-open-blocks pack active, the binding also says what a change to
 * the phone refreshes, from the pack's own plan, as a stat outside every
 * hash. Without the pack, or when its plan fails, nothing changes.
 */
final class SiteDataRefreshesTest extends TestCase
{
    private const PHONE = 'bind:voyager/site-data.phone';

    /** Post ID => slug of the six pages the plan says show the phone. */
    private const SIX = [12 => 'service-areas', 4 => 'about', 9 => 'contact', 30 => 'emergency', 7 => 'book', 15 => 'pricing'];

    private const SEVEN = [
        'all_pages' => false,
        'count'     => 7,
        'pages'     => ['page:about', 'page:book', 'page:contact', 'page:emergency', 'page:pricing', 'page:service-areas'],
        'templates' => [],
    ];

    private const ADMIN = 'https://example.test/wp-admin/';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        WpCalls::reset();
        OpenBlocksStub::load();
        $this->logged = [];
    }

    public function testThePhoneBindingCarriesWhatAChangeRefreshesAndItsHashStaysTheSame(): void
    {
        $source = self::source();
        $without = self::scan($source);

        $source->refreshPlan = $this->plan();
        OpenBlocksStub::$plans = ['phone' => OpenBlocksStub::plan('phone', self::permalinks(self::SIX))];
        $with = self::scan($source);

        $item = $with->item(self::PHONE);
        $plain = $without->item(self::PHONE);

        $this->assertNotNull($item);
        $this->assertNotNull($plain);
        $this->assertSame(self::SEVEN, $item->stats['refreshes'] ?? null, 'six pages and the front page');
        $this->assertArrayNotHasKey('refreshes', $plain->stats);
        $this->assertSame($plain->hash, $item->hash, 'the item hash does not cover the stat');
        $this->assertSame($without->hash, $with->hash, 'nor does the section hash');

        $before = new Manifest([$without]);
        $after = new Manifest([$with]);

        $this->assertSame($before->hash, $after->hash, 'nor does the manifest hash');
        $this->assertNotSame($before->statsHash, $after->statsHash, 'it travels with the stats');
    }

    public function testOnlySiteDataKeysAreAskedAbout(): void
    {
        $source = self::source();
        $source->refreshPlan = $this->plan();

        $section = self::scan($source);
        $calls = $source->refreshCalls;
        sort($calls);

        $this->assertSame(['email', 'phone'], $calls);
        $this->assertArrayNotHasKey('refreshes', $section->item('bind:vwf-fixture/business-info.hours')?->stats ?? []);
        $this->assertSame(
            ['all_pages' => false, 'count' => 0, 'pages' => [], 'templates' => []],
            $section->item('bind:voyager/site-data.email')?->stats['refreshes'] ?? null,
            'the pack says nothing published shows the email'
        );
    }

    public function testATemplateOrPartIsNamedByItsItemIdAndRefreshesEveryPage(): void
    {
        $source = self::source();
        $source->refreshPlan = $this->plan();
        OpenBlocksStub::$plans = ['phone' => OpenBlocksStub::plan('phone', self::permalinks([4 => 'about']), ['vwf-fixture//footer', 'other-theme//header'])];

        $refreshes = self::scan($source)->item(self::PHONE)?->stats['refreshes'] ?? null;

        $this->assertIsArray($refreshes);
        $this->assertTrue($refreshes['all_pages']);
        $this->assertSame(['other-theme//header', 'part:footer'], $refreshes['templates'], 'one this scan has no item for keeps the id WordPress gives it');
    }

    public function testWithoutThePackOrWhenItsPlanThrowsTheStatsAreAsBefore(): void
    {
        $source = self::source();
        $baseline = self::scan($source)->stats();

        $source->refreshPlan = new RefreshPlan(RefreshPlan::planFunction(), self::pages(...));
        $this->assertSame($baseline, self::scan($source)->stats(), 'the pack is not loaded');

        $source->refreshPlan = $this->plan(static function (array $keys): never {
            throw new RuntimeException('usage index table missing');
        });
        $this->assertSame($baseline, self::scan($source)->stats(), 'the plan throws');
        $this->assertCount(2, $this->logged, 'each failed read is logged, phone and email');
    }

    public function testTheIndexAndTheBriefSayItAsABandAndTheManualNamesThePages(): void
    {
        $source = self::source();
        $source->refreshPlan = $this->plan();
        OpenBlocksStub::$plans = ['phone' => OpenBlocksStub::plan('phone', self::permalinks(self::SIX))];

        $phone = self::scan($source)->item(self::PHONE);
        $this->assertNotNull($phone);

        $manifest = FixtureManifest::manifest(['layout' => [$phone]]);
        $index = (new IndexRenderer())->render($manifest);
        $brief = (new BriefRenderer())->render($manifest);
        $manual = self::manual($manifest);

        $this->assertMatchesRegularExpression('/\n' . preg_quote(self::PHONE, '/') . ' \| [^\n]*; a change refreshes 6-20 pages \| ' . preg_quote(OptionScreens::PACK_SCREEN, '/') . '\n/', $index);
        $this->assertStringContainsString('edit: ' . self::PHONE . ' | Site Data: phone | in part:footer, content 1-5; a change refreshes 6-20 pages; editor cannot open: needs manage_options | ' . OptionScreens::PACK_SCREEN . "\n", $brief);
        $this->assertStringContainsString(
            '<dt>A change refreshes</dt><dd>7 pages: page:about, page:book, page:contact, page:emergency, page:pricing, page:service-areas, the front page</dd>',
            $manual
        );
    }

    public function testEveryPageNoPageAndNoStatReadAsSuch(): void
    {
        $all = self::binding(['all_pages' => true, 'count' => 2, 'pages' => ['page:about'], 'templates' => ['part:footer']]);
        $none = self::binding(['all_pages' => false, 'count' => 0, 'pages' => [], 'templates' => []]);
        $front = self::binding(['all_pages' => false, 'count' => 2, 'pages' => ['page:about', 'page:home'], 'templates' => []]);

        $this->assertStringContainsString('a change refreshes every page', Facts::describe($all));
        $this->assertStringContainsString('a change refreshes no pages', Facts::describe($none));
        $this->assertStringNotContainsString('refreshes', Facts::describe(self::binding(null)));

        $manual = self::manual(FixtureManifest::manifest(['layout' => [$all]]));
        $this->assertStringContainsString('<dd>Every page, because a template or part shows it: part:footer. Pages that show it: page:about</dd>', $manual);
        $this->assertStringContainsString('<dd>No pages</dd>', self::manual(FixtureManifest::manifest(['layout' => [$none]])));
        $this->assertStringContainsString('<dd>2 pages: page:about, page:home</dd>', self::manual(FixtureManifest::manifest(['layout' => [$front]])), 'the front page is one of them');
    }

    /**
     * A footer part that binds the phone and the email to Site Data and the
     * hours to the fixture's own source, and two pages that bind the phone
     * themselves. Site Data is owned by the pack.
     */
    private static function source(): FakeLayoutSource
    {
        $source = FakeLayoutSource::fixture();
        $source->parts = [FakeLayoutSource::row('footer', implode("\n", [
            self::bound('voyager/site-data', 'phone'),
            self::bound('voyager/site-data', 'email'),
            self::bound('vwf-fixture/business-info', 'hours'),
        ]), 'Footer', 'footer')];
        $source->content = [
            ['type' => 'page', 'slug' => 'about', 'content' => self::bound('voyager/site-data', 'phone')],
            ['type' => 'page', 'slug' => 'contact', 'content' => self::bound('voyager/site-data', 'phone')],
        ];
        $source->sourceLabels['voyager/site-data'] = 'Site Data';
        $source->siteData = ['voyager_site_email' => 'Email', 'voyager_site_phone' => 'Phone'];
        $source->siteDataScreen = OptionScreens::PACK_SCREEN;

        return $source;
    }

    private static function bound(string $source, string $key): string
    {
        return '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"' . $source . '","args":{"key":"' . $key . '"}}}}} -->'
            . "\n<p></p>\n<!-- /wp:paragraph -->";
    }

    private static function scan(FakeLayoutSource $source): Section
    {
        return (new LayoutScanner($source))->scan();
    }

    /**
     * A plan read through the stub site_data_plan(), or through `$function`.
     *
     * @param (callable(list<string>): mixed)|null $function
     */
    private function plan(?callable $function = null): RefreshPlan
    {
        return new RefreshPlan(
            $function ?? RefreshPlan::planFunction(OpenBlocksStub::FUNCTION),
            self::pages(...),
            function (string $message): void {
                $this->logged[] = $message;
            }
        );
    }

    private static function pages(int $id): ?string
    {
        return isset(self::SIX[$id]) ? 'page:' . self::SIX[$id] : null;
    }

    /**
     * @param array<int, string> $slugs Post ID => slug.
     * @return array<int, string> Post ID => permalink.
     */
    private static function permalinks(array $slugs): array
    {
        return array_map(static fn (string $slug): string => OpenBlocksStub::HOME . $slug . '/', $slugs);
    }

    /**
     * A Site Data phone binding with these refreshes, or none.
     *
     * @param array<string, mixed>|null $refreshes
     */
    private static function binding(?array $refreshes): Item
    {
        return new Item(
            self::PHONE,
            'binding',
            'Site Data: phone',
            ['bound' => ['core/paragraph.content'], 'defined_in' => null, 'key' => 'phone', 'reads' => null, 'source' => 'voyager/site-data', 'source_label' => 'Site Data'],
            OptionScreens::PACK_SCREEN,
            [],
            ['usage_count' => 1, 'used_in' => ['part:footer']] + ($refreshes !== null ? ['refreshes' => $refreshes] : [])
        );
    }

    private static function manual(Manifest $manifest): string
    {
        return (new ManualView())->render($manifest, [], new PageState(
            siteName: 'Riverbend Plumbing',
            adminUrl: self::ADMIN,
            lastScan: '',
            brief: '',
            canWrite: false,
            mcpEnabled: false,
            rescanNonce: '',
            settingsNonce: '',
            referer: '/wp-admin/admin.php?page=voyager-wayfinder',
            exportNonce: ''
        ));
    }
}
