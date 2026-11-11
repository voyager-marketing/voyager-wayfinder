<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;

final class ManifestTest extends TestCase
{
    private const HEADER = [
        'generated_at'   => '2026-10-01T12:00:00Z',
        'plugin_version' => '0.1.0',
        'site_url'       => 'https://example.test',
    ];

    public function testIdenticalInputGivesByteIdenticalJson(): void
    {
        $first = $this->manifest();
        $second = $this->manifest();

        $this->assertNotSame($first, $second);
        $this->assertSame($first->toJson(), $second->toJson());
        $this->assertSame($first->hash, $second->hash);
    }

    public function testShuffledItemAndSectionOrderGivesTheSameBytesAndHash(): void
    {
        $ordered = $this->manifest();

        $shuffled = new Manifest([
            new Section('site', [
                new Item('opt:stylesheet', 'option', 'Active theme', ['value' => 'twentytwentyfive'], 'themes.php'),
                new Item('opt:blogname', 'option', 'Site title', ['value' => 'Fixture'], 'options-general.php'),
            ]),
            new Section('content_model', [
                new Item('tax:category', 'taxonomy', 'Categories', ['public' => true, 'hierarchical' => true], 'edit-tags.php?taxonomy=category', ['pt:post'], ['terms' => 1]),
                new Item('pt:service', 'post_type', 'Services', ['public' => true], 'edit.php?post_type=service', ['tax:service_area', 'tpl:single-service'], ['counts' => ['publish' => 6]]),
                new Item('pt:post', 'post_type', 'Posts', ['public' => true], 'edit.php', [], ['counts' => ['publish' => 4]]),
            ]),
        ], self::HEADER);

        $this->assertSame($ordered->toJson(), $shuffled->toJson());
        $this->assertSame($ordered->hash, $shuffled->hash);
        $this->assertSame(['content_model', 'site'], array_map(static fn (Section $s): string => $s->name, $shuffled->sections));
        $this->assertSame(
            ['pt:post', 'pt:service', 'tax:category'],
            array_map(static fn (Item $i): string => $i->id, $shuffled->sections[0]->items)
        );
    }

    public function testChangingOneItemChangesThatItemItsSectionAndTheManifestHashOnly(): void
    {
        $before = $this->manifest();
        $after = $this->manifest(blogname: 'Renamed');

        $this->assertNotSame($before->hash, $after->hash);

        $siteBefore = $before->section('site');
        $siteAfter = $after->section('site');
        $this->assertNotNull($siteBefore);
        $this->assertNotNull($siteAfter);
        $this->assertNotSame($siteBefore->hash, $siteAfter->hash);
        $this->assertNotSame($siteBefore->item('opt:blogname')?->hash, $siteAfter->item('opt:blogname')?->hash);
        $this->assertSame($siteBefore->item('opt:stylesheet')?->hash, $siteAfter->item('opt:stylesheet')?->hash);

        $modelBefore = $before->section('content_model');
        $modelAfter = $after->section('content_model');
        $this->assertNotNull($modelBefore);
        $this->assertNotNull($modelAfter);
        $this->assertSame($modelBefore->hash, $modelAfter->hash);
        $this->assertSame(CanonicalJson::encode($modelBefore->toArray()), CanonicalJson::encode($modelAfter->toArray()));

        $this->assertSame($before->header, $after->header);
    }

    public function testGeneratedAtIsExcludedFromTheHash(): void
    {
        $morning = $this->manifest();
        $evening = $this->manifest(header: ['generated_at' => '2026-10-01T18:30:00Z'] + self::HEADER);

        $this->assertNotSame($morning->toJson(), $evening->toJson());
        $this->assertSame($morning->hash, $evening->hash);
        $this->assertSame(CanonicalJson::encode($morning->body()), CanonicalJson::encode($evening->body()));
        $this->assertStringNotContainsString('generated_at', CanonicalJson::encode($morning->body()));
    }

    public function testManifestHashIsSha256OfTheCanonicalBody(): void
    {
        $manifest = $this->manifest();
        $decoded = json_decode($manifest->toJson(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(hash('sha256', CanonicalJson::encode($decoded['body'])), $manifest->hash);
        $this->assertSame($manifest->hash, $decoded['manifest_hash']);
        $this->assertSame(Manifest::SCHEMA_VERSION, $decoded['body']['schema_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $manifest->hash);
    }

    public function testIdsAreEveryItemIdAcrossSectionsSorted(): void
    {
        $this->assertSame(
            ['opt:blogname', 'opt:stylesheet', 'pt:post', 'pt:service', 'tax:category'],
            $this->manifest()->ids()
        );
    }

    public function testTwoSectionsWithOneNameThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Manifest([new Section('site', []), new Section('site', [])]);
    }

    public function testAnEmptyHeaderStillEncodesAsAnObject(): void
    {
        $this->assertStringContainsString('"header":{}', (new Manifest([]))->toJson());
    }

    public function testStatsMoveTheStatsHashAndNoOtherHash(): void
    {
        $six = $this->manifest();
        $seven = $this->manifest(services: 7);

        $this->assertSame($six->hash, $seven->hash);
        $this->assertSame($six->section('content_model')?->hash, $seven->section('content_model')?->hash);
        $this->assertSame($six->section('content_model')?->item('pt:service')?->hash, $seven->section('content_model')?->item('pt:service')?->hash);
        $this->assertSame(CanonicalJson::encode($six->body()), CanonicalJson::encode($seven->body()));
        $this->assertNotSame($six->statsHash, $seven->statsHash);
    }

    public function testTheEnvelopeCarriesStatsByIdBesideTheBodyWithTheirOwnHash(): void
    {
        $manifest = $this->manifest();
        $decoded = json_decode($manifest->toJson(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['pt:post', 'pt:service', 'tax:category'], array_keys($decoded['stats']));
        $this->assertSame(['counts' => ['publish' => 6]], $decoded['stats']['pt:service']);
        $this->assertSame(hash('sha256', CanonicalJson::encode($decoded['stats'])), $decoded['stats_hash']);
        $this->assertSame($manifest->statsHash, $decoded['stats_hash']);
        $this->assertStringNotContainsString('counts', CanonicalJson::encode($decoded['body']));

        // The body alone still reproduces manifest_hash.
        $this->assertSame(hash('sha256', CanonicalJson::encode($decoded['body'])), $decoded['manifest_hash']);
    }

    public function testNoStatsEncodeAsAnObject(): void
    {
        $empty = new Manifest([new Section('site', [new Item('opt:blogname', 'option', 'Site title')])]);

        $this->assertStringContainsString('"stats":{},"stats_hash":"' . hash('sha256', '{}') . '"', $empty->toJson());
    }

    /**
     * @param array<string, string> $header
     */
    private function manifest(string $blogname = 'Fixture', array $header = self::HEADER, int $services = 6): Manifest
    {
        return new Manifest([
            new Section('content_model', [
                new Item('pt:post', 'post_type', 'Posts', ['public' => true], 'edit.php', [], ['counts' => ['publish' => 4]]),
                new Item('pt:service', 'post_type', 'Services', ['public' => true], 'edit.php?post_type=service', ['tpl:single-service', 'tax:service_area'], ['counts' => ['publish' => $services]]),
                new Item('tax:category', 'taxonomy', 'Categories', ['hierarchical' => true, 'public' => true], 'edit-tags.php?taxonomy=category', ['pt:post'], ['terms' => 1]),
            ]),
            new Section('site', [
                new Item('opt:blogname', 'option', 'Site title', ['value' => $blogname], 'options-general.php'),
                new Item('opt:stylesheet', 'option', 'Active theme', ['value' => 'twentytwentyfive'], 'themes.php'),
            ]),
        ], $header);
    }
}
