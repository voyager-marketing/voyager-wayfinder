<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Render\Ai\SectionRenderer;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;

final class SectionRendererTest extends TestCase
{
    public function testASectionNameReturnsTheWholeSectionUnfolded(): void
    {
        $manifest = FixtureManifest::manifest();
        $json = (new SectionRenderer())->render($manifest, 'layout');

        $this->assertIsString($json);

        $data = json_decode($json, true);
        $this->assertSame('layout', $data['name']);
        $this->assertSame($manifest->section('layout')?->hash, $data['hash']);

        // The section tier keeps what the compact tiers fold.
        $this->assertContains('tpl:index', array_column($data['items'], 'id'));
        $this->assertContains('pat:twentytwentyfive/post-meta', array_column($data['items'], 'id'));

        // Exact stats sit beside the items, by id, outside the section hash.
        $this->assertSame(5, $data['stats']['nav:primary']['item_count']);
        $this->assertArrayNotHasKey('stats', $data['items'][0]);
    }

    public function testExactCountsReachTheSectionTierAndItemsWithoutStatsCarryNone(): void
    {
        $renderer = new SectionRenderer();
        $service = json_decode((string) $renderer->render(FixtureManifest::manifest(services: 7), 'pt:service'), true);
        $blogname = json_decode((string) $renderer->render(FixtureManifest::manifest(), 'opt:blogname'), true);

        $this->assertSame(['draft' => 0, 'private' => 0, 'publish' => 7], $service['stats']['counts']);
        $this->assertArrayNotHasKey('stats', $blogname);
        $this->assertArrayNotHasKey('stats', json_decode((string) $renderer->render(FixtureManifest::manifest(), 'site'), true));
    }

    public function testAnItemIdReturnsTheItemWithItsSectionAndNotes(): void
    {
        $json = (new SectionRenderer())->render(FixtureManifest::manifest(), 'pat:cta-banner', [
            'pat:cta-banner' => [['body' => 'Edit once, changes 3 pages', 'status' => 'ok']],
        ]);

        $this->assertIsString($json);

        $data = json_decode($json, true);
        $this->assertSame('pat:cta-banner', $data['id']);
        $this->assertSame('layout', $data['section']);
        $this->assertSame(['usage_count' => 3, 'used_in' => ['tpl:single-service', 'page:about', 'page:home']], $data['stats']);
        $this->assertArrayNotHasKey('usage_count', $data['facts']);
        $this->assertSame([['body' => 'Edit once, changes 3 pages', 'status' => 'ok']], $data['notes']);
        $this->assertStringNotContainsString("\n", $json);
    }

    public function testAnUnknownIdReturnsNull(): void
    {
        $this->assertNull((new SectionRenderer())->render(FixtureManifest::manifest(), 'pat:nope'));
    }

    public function testASectionPagesByIdWithItsHashAndOnlyItsPagesStats(): void
    {
        // 60 options beside the fixture's 8 site items: 68 items, three pages of up to 25.
        $manifest = FixtureManifest::manifest(['site' => array_map(
            static fn (int $i): Item => new Item(sprintf('opt:extra_%02d', $i), 'option', 'Extra ' . $i, ['value' => 'x'], 'options-general.php', [], $i === 30 ? ['reads' => 1] : []),
            range(1, 60)
        )]);
        $renderer = new SectionRenderer();
        $whole = json_decode((string) $renderer->render($manifest, 'site'), true);
        $pages = array_map(static fn (int $page): array => json_decode((string) $renderer->render($manifest, 'site', [], $page), true), [1, 2, 3]);

        $this->assertCount(68, $whole['items']);
        $this->assertSame([25, 25, 18], array_map(static fn (array $page): int => count($page['items']), $pages));
        $this->assertSame($whole['items'], array_merge(...array_column($pages, 'items')), 'The pages, in order, are the section.');
        $this->assertSame([1, 2, 3], array_column($pages, 'page'));
        $this->assertSame([3, 3, 3], array_column($pages, 'pages'));
        $this->assertSame([$whole['hash'], $whole['hash'], $whole['hash']], array_column($pages, 'hash'), 'Each page carries the whole section\'s hash.');
        $this->assertArrayNotHasKey('page', $whole, 'Without a page, the section is whole and says nothing of pages.');

        // Stats travel with the page that holds their item.
        $this->assertSame(['opt:extra_30' => ['reads' => 1]], $pages[1]['stats']);
        $this->assertArrayNotHasKey('stats', $pages[0]);
        $this->assertSame(SectionRenderer::pageOf(array_search('opt:extra_30', array_column($whole['items'], 'id'), true)), 2);
        $this->assertSame(3, SectionRenderer::pageCount(68));
        $this->assertSame(1, SectionRenderer::pageCount(0));
    }

    public function testAPageTheSectionDoesNotHaveIsRefusedAndAnItemIgnoresThePage(): void
    {
        $renderer = new SectionRenderer();
        $manifest = FixtureManifest::manifest();

        foreach ([0, 2] as $page) {
            try {
                $renderer->render($manifest, 'layout', [], $page);
                $this->fail(sprintf('Page %d of a one-page section rendered.', $page));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Section layout has 1 page of up to 25 items; there is no page ' . $page, $e->getMessage());
            }
        }

        $this->assertSame($renderer->render($manifest, 'pt:service'), $renderer->render($manifest, 'pt:service', [], 4));
    }
}
