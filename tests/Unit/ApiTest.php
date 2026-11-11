<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Api;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\Anchors;
use Voyager\Wayfinder\Scanners\LayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * The public PHP API: `vwf_anchor`, `vwf_anchor_check` and `vwf_api_version`
 * delegate to Api, which other plugins (Orbit) call in the same request as a
 * block write. Each check builds a fresh Api, as the functions do, because an
 * Anchors instance keeps the markup it read.
 */
final class ApiTest extends TestCase
{
    private const LICENSED = 'Licensed and insured.';

    private const PAGE_ID = 90071;

    private const STALE_HASH = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    public function testAnUnchangedBlockMatches(): void
    {
        $layout = self::footerWithLicence();
        $anchor = self::api($layout)->anchor('part:footer', '1');
        $this->assertNotNull($anchor);
        $this->assertNotNull($anchor['expect_hash']);

        $this->assertSame(['ok' => true, 'current' => $anchor, 'reason' => 'match'], self::api($layout)->check($anchor));
    }

    public function testEditedTextIsFpChangedWithTheBlockNowAtThePath(): void
    {
        $layout = self::footerWithLicence();
        $anchor = self::api($layout)->anchor('part:footer', '1');
        $this->assertNotNull($anchor);

        self::editFooter($layout, static fn (string $content): string => str_replace(self::LICENSED, 'Licensed, bonded and insured.', $content));
        $check = self::api($layout)->check($anchor);

        $this->assertFalse($check['ok']);
        $this->assertSame('fp_changed', $check['reason']);
        $this->assertNotNull($check['current']);
        $this->assertSame('1', $check['current']['path']);
        $this->assertNotSame($anchor['fp'], $check['current']['fp']);
        $this->assertSame($anchor['expect_hash'], $check['current']['expect_hash'], 'A text edit moves no item hash.');
        $this->assertSame($check['current'], self::api($layout)->anchor('part:footer', '1'));
    }

    public function testABlockMovedByAnInsertedSiblingMatchesAtItsNewPath(): void
    {
        $layout = self::withPage(FakeLayoutSource::paragraph('Intro') . FakeLayoutSource::paragraph(self::LICENSED));
        $anchor = self::api($layout)->anchor('pt:page', '1', self::PAGE_ID);
        $this->assertNotNull($anchor);

        self::setPage($layout, FakeLayoutSource::paragraph('New') . FakeLayoutSource::paragraph('Intro') . FakeLayoutSource::paragraph(self::LICENSED));
        $check = self::api($layout)->check($anchor);

        $this->assertTrue($check['ok']);
        $this->assertSame('match', $check['reason']);
        $this->assertSame(['entity' => 'pt:page', 'post' => self::PAGE_ID, 'path' => '2', 'fp' => $anchor['fp'], 'expect_hash' => null], $check['current']);
    }

    public function testADeletedBlockIsGone(): void
    {
        $layout = self::withPage(FakeLayoutSource::paragraph('Intro') . FakeLayoutSource::paragraph(self::LICENSED));
        $anchor = self::api($layout)->anchor('pt:page', '1', self::PAGE_ID);
        $this->assertNotNull($anchor);

        self::setPage($layout, FakeLayoutSource::paragraph('Intro'));

        $this->assertSame(['ok' => false, 'current' => null, 'reason' => 'gone'], self::api($layout)->check($anchor));
    }

    public function testARestructuredTemplatePartIsContainerRestructured(): void
    {
        $layout = self::footerWithLicence();
        $anchor = self::api($layout)->anchor('part:footer', '1');
        $this->assertNotNull($anchor);

        // A site title added inside the group above it: a new block in the part, not the anchored block.
        // It carries a class, because item hashes cover refs, locks and classes, never a block count.
        self::editFooter($layout, static fn (string $content): string => preg_replace('/(<!-- wp:navigation )/', "<!-- wp:site-title {\"className\":\"footer-title\"} /-->\n\n\t$1", $content, 1) ?? $content);
        $check = self::api($layout)->check($anchor);

        $this->assertFalse($check['ok']);
        $this->assertSame('container_restructured', $check['reason']);
        $this->assertNotNull($check['current']);
        $this->assertSame($anchor['fp'], $check['current']['fp'], 'The block itself is unchanged.');
        $this->assertSame($anchor['path'], $check['current']['path']);
        $this->assertNotSame($anchor['expect_hash'], $check['current']['expect_hash']);
        $this->assertSame(['ok' => true, 'current' => $check['current'], 'reason' => 'match'], self::api($layout)->check($check['current']), 'Re-anchoring on current matches.');
    }

    public function testPostContentAnchorsIgnoreExpectHash(): void
    {
        $layout = self::withPage(FakeLayoutSource::paragraph(self::LICENSED));
        $anchor = self::api($layout)->anchor('pt:page', '0', self::PAGE_ID);
        $this->assertNotNull($anchor);
        $this->assertNull($anchor['expect_hash']);

        $check = self::api($layout)->check(['expect_hash' => self::STALE_HASH] + $anchor);

        $this->assertSame(['ok' => true, 'current' => $anchor, 'reason' => 'match'], $check);
    }

    public function testALayoutAnchorWithAStaleExpectHashIsContainerRestructured(): void
    {
        $layout = self::footerWithLicence();
        $anchor = self::api($layout)->anchor('part:footer', '1');
        $this->assertNotNull($anchor);

        $this->assertSame('container_restructured', self::api($layout)->check(['expect_hash' => self::STALE_HASH] + $anchor)['reason']);
        $this->assertSame('match', self::api($layout)->check(['expect_hash' => null] + $anchor)['reason'], 'No hash to compare, nothing restructured.');
    }

    public function testSeveralCopiesAndNoneAtThePathIsAmbiguous(): void
    {
        $layout = self::withPage(FakeLayoutSource::paragraph('Intro') . FakeLayoutSource::paragraph(self::LICENSED));
        $anchor = self::api($layout)->anchor('pt:page', '1', self::PAGE_ID);
        $this->assertNotNull($anchor);

        self::setPage($layout, FakeLayoutSource::paragraph(self::LICENSED) . FakeLayoutSource::paragraph('Intro') . FakeLayoutSource::paragraph('New') . FakeLayoutSource::paragraph(self::LICENSED));

        $this->assertSame(['ok' => false, 'current' => null, 'reason' => 'ambiguous'], self::api($layout)->check($anchor));
    }

    public function testACopyAtThePathMatchesThere(): void
    {
        $layout = self::withPage(FakeLayoutSource::paragraph(self::LICENSED) . FakeLayoutSource::paragraph(self::LICENSED));
        $anchor = self::api($layout)->anchor('pt:page', '1', self::PAGE_ID);
        $this->assertNotNull($anchor);

        $this->assertSame(['ok' => true, 'current' => $anchor, 'reason' => 'match'], self::api($layout)->check($anchor));
    }

    public function testABlockRemovedBeforeASiblingReadsAsFpChanged(): void
    {
        $layout = self::withPage(FakeLayoutSource::paragraph(self::LICENSED) . FakeLayoutSource::paragraph('Outro'));
        $anchor = self::api($layout)->anchor('pt:page', '0', self::PAGE_ID);
        $this->assertNotNull($anchor);

        self::setPage($layout, FakeLayoutSource::paragraph('Outro'));
        $check = self::api($layout)->check($anchor);

        $this->assertSame('fp_changed', $check['reason'], 'An anchor cannot tell an edited block from one whose path a sibling took.');
        $this->assertSame(self::api($layout)->anchor('pt:page', '0', self::PAGE_ID), $check['current']);
    }

    public function testAMissingPostOrContainerIsGone(): void
    {
        $layout = self::withPage(FakeLayoutSource::paragraph(self::LICENSED));
        $anchor = self::api($layout)->anchor('pt:page', '0', self::PAGE_ID);
        $this->assertNotNull($anchor);

        $this->assertSame('gone', self::api($layout)->check(['post' => self::PAGE_ID + 1] + $anchor)['reason']);
        $this->assertSame('gone', self::api($layout)->check(['entity' => 'part:no-such-part', 'expect_hash' => null] + $anchor)['reason']);
    }

    public function testAPostPastTheMatchingCapIsFound(): void
    {
        $layout = FakeRouteSource::layout();

        for ($id = 1; $id <= LayoutSource::MATCHING_CAP; $id++) {
            $layout->content[] = ['type' => 'page', 'slug' => 'filler-' . $id, 'content' => FakeLayoutSource::paragraph('Filler'), 'id' => $id];
        }

        $layout->content[] = self::page(FakeLayoutSource::paragraph(self::LICENSED));

        $anchor = self::api($layout)->anchor('pt:page', '0', self::PAGE_ID);
        $this->assertNotNull($anchor, 'The anchored post is the 201st page, oldest first.');
        $this->assertSame('match', self::api($layout)->check($anchor)['reason']);
        $this->assertCount(LayoutSource::MATCHING_CAP, $layout->contentOfType('page'), 'Without a post, the type is still capped.');
    }

    /**
     * @param array<string, mixed> $anchor
     */
    #[DataProvider('malformedAnchors')]
    public function testAMalformedAnchorThrows(array $anchor, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::api(FakeRouteSource::layout())->check($anchor);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedAnchors(): array
    {
        $good = ['entity' => 'part:footer', 'post' => null, 'path' => '0.1', 'fp' => '3f9c0a1b2c3d4e5f', 'expect_hash' => null];

        return [
            'no entity'          => [array_diff_key($good, ['entity' => 1]), 'entity'],
            'empty path'         => [['path' => ''] + $good, 'path'],
            'fingerprint shape'  => [['fp' => '3F9C'] + $good, 'fp'],
            'post as a string'   => [['post' => '42'] + $good, 'post'],
            'hash as a number'   => [['expect_hash' => 12] + $good, 'expect_hash'],
            'content, no post'   => [['entity' => 'pt:page'] + $good, 'pt:'],
        ];
    }

    public function testTheApiVersionIsOne(): void
    {
        $this->assertSame('1', Api::version());
        $this->assertSame(Api::VERSION, Api::version());
    }

    public function testTheBootstrapDeclaresTheThreeFunctions(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/voyager-wayfinder.php');

        preg_match_all('/^function (\w+)\(([^)]*)\): (\??\w+)$/m', $source, $matches, PREG_SET_ORDER);
        $signatures = array_map(static fn (array $m): string => $m[1] . '(' . $m[2] . '): ' . $m[3], $matches);

        $this->assertSame([
            'vwf_anchor(string $entity, string $path, ?int $post = null): ?array',
            'vwf_anchor_check(array $anchor): array',
            'vwf_api_version(): string',
        ], $signatures);
    }

    private static function api(FakeLayoutSource $layout): Api
    {
        return new Api(new Anchors($layout, new Locks('7.1.2')));
    }

    private static function footerWithLicence(): FakeLayoutSource
    {
        $layout = FakeRouteSource::layout();
        self::editFooter($layout, static fn (string $content): string => $content . FakeLayoutSource::paragraph(self::LICENSED));

        return $layout;
    }

    private static function withPage(string $content): FakeLayoutSource
    {
        $layout = FakeRouteSource::layout();
        $layout->content[] = self::page($content);

        return $layout;
    }

    private static function setPage(FakeLayoutSource $layout, string $content): void
    {
        foreach ($layout->content as $i => $row) {
            if (($row['id'] ?? null) === self::PAGE_ID) {
                $layout->content[$i] = self::page($content);
            }
        }
    }

    /**
     * @return array{type: string, slug: string, content: string, id: int}
     */
    private static function page(string $content): array
    {
        return ['type' => 'page', 'slug' => 'licence', 'content' => $content, 'id' => self::PAGE_ID];
    }

    /**
     * @param callable(string): string $edit
     */
    private static function editFooter(FakeLayoutSource $layout, callable $edit): void
    {
        foreach ($layout->parts as $i => $row) {
            if ($row['slug'] === 'footer') {
                $layout->parts[$i]['content'] = $edit($row['content']);
            }
        }
    }
}
