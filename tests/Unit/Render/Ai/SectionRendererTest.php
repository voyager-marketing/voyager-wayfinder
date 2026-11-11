<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use PHPUnit\Framework\TestCase;
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
}
