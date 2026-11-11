<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Blocks;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\BlockFingerprint;
use Voyager\Wayfinder\Blocks\BlockNode;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\Anchors;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\RouteScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * Block fingerprints, the anchors find hands a writer, and the verify recipe
 * that tells it how to check its edit.
 */
final class FingerprintTest extends TestCase
{
    private const PHONE_COPY = 'Call us at (540) 555-0142 any time.';

    private const PAGE_ID = 90071;

    public function testTheSameBlockInTwoPositionsHasOneFingerprint(): void
    {
        $tree = self::tree(FakeLayoutSource::paragraph('Intro') . FakeLayoutSource::paragraph(self::PHONE_COPY) . '<!-- wp:group --><div class="wp-block-group">' . FakeLayoutSource::paragraph(self::PHONE_COPY) . '</div><!-- /wp:group -->');

        $fp = BlockFingerprint::of($tree[1]);

        $this->assertSame($fp, BlockFingerprint::of($tree[2]->children[0]));
        $this->assertSame(['1', '2.0'], BlockFingerprint::paths($tree, $fp));
        $this->assertTrue(BlockFingerprint::isValid($fp));
        $this->assertSame(BlockFingerprint::LENGTH, strlen($fp));
    }

    public function testClassNameLockAndWhitespaceDoNotMoveTheFingerprint(): void
    {
        $plain = self::node('core/paragraph', [], self::PHONE_COPY);

        $this->assertSame(BlockFingerprint::of($plain), BlockFingerprint::of(self::node('core/paragraph', ['className' => 'phone is-style-bold'], self::PHONE_COPY)));
        $this->assertSame(BlockFingerprint::of($plain), BlockFingerprint::of(self::node('core/paragraph', ['lock' => ['move' => true, 'remove' => true]], self::PHONE_COPY)));
        $this->assertSame(BlockFingerprint::of($plain), BlockFingerprint::of(self::node('core/paragraph', [], "  Call us at (540)\u{00A0}555-0142\n any time. ")));
    }

    public function testTextAttributesAndTheBlockNameMoveTheFingerprint(): void
    {
        $plain = BlockFingerprint::of(self::node('core/paragraph', [], self::PHONE_COPY));

        $this->assertNotSame($plain, BlockFingerprint::of(self::node('core/paragraph', [], 'Call us at (540) 555-0199 any time.')));
        $this->assertNotSame($plain, BlockFingerprint::of(self::node('core/paragraph', ['align' => 'center'], self::PHONE_COPY)));
        $this->assertNotSame($plain, BlockFingerprint::of(self::node('core/heading', [], self::PHONE_COPY)));
        $this->assertNotSame(
            BlockFingerprint::of(self::node('core/image', ['alt' => 'Our van'], '')),
            BlockFingerprint::of(self::node('core/image', ['alt' => 'Our truck'], ''))
        );
    }

    public function testLocateFindsTheBlockAfterASiblingIsInsertedAndNothingOnceItsTextChanges(): void
    {
        $layout = FakeRouteSource::layout();
        $layout->content[] = self::page(FakeLayoutSource::paragraph('Intro') . FakeLayoutSource::paragraph(self::PHONE_COPY));
        $row = array_key_last($layout->content);

        $before = (new Anchors($layout, new Locks('7.1.2')))->anchor('pt:page', '1', self::PAGE_ID);
        $this->assertNotNull($before);

        $layout->content[$row] = self::page(FakeLayoutSource::paragraph('New') . FakeLayoutSource::paragraph('Intro') . FakeLayoutSource::paragraph(self::PHONE_COPY));
        $anchors = new Anchors($layout, new Locks('7.1.2'));

        $this->assertSame([['post' => self::PAGE_ID, 'path' => '2']], $anchors->locate('pt:page', $before['fp']));
        $this->assertSame($before['fp'], $anchors->anchor('pt:page', '2', self::PAGE_ID)['fp'] ?? null);

        $layout->content[$row] = self::page(FakeLayoutSource::paragraph('New') . FakeLayoutSource::paragraph('Intro') . FakeLayoutSource::paragraph('Call us at (540) 555-0199 any time.'));
        $anchors = new Anchors($layout, new Locks('7.1.2'));

        $this->assertSame([], $anchors->locate('pt:page', $before['fp']), 'The CLI exits 1 on an empty list.');
        $this->assertNotSame($before['fp'], $anchors->anchor('pt:page', '2', self::PAGE_ID)['fp'] ?? null);
        $this->assertNull($anchors->anchor('pt:page', '2'), 'A post content anchor needs its post.');
        $this->assertNull($anchors->anchor('pt:page', '9', self::PAGE_ID));
    }

    public function testExpectHashIsTheOwningItemsHashInTheLayoutScan(): void
    {
        $layout = FakeRouteSource::layout();
        $result = self::locator(FakeRouteSource::fixture(), $layout)->find('/', '555-0142');
        $hashes = self::itemHashes($layout);

        $anchor = $result['hits'][0]['anchor'];
        $this->assertNotNull($anchor);
        $this->assertSame('bind:vwf-fixture/business-info.phone', $result['hits'][0]['id']);
        $this->assertSame('part:footer', $anchor['entity']);
        $this->assertNull($anchor['post']);
        $this->assertSame($hashes['part:footer'], $anchor['expect_hash']);
        $this->assertSame($anchor, (new Anchors($layout, new Locks('7.1.2')))->anchor('part:footer', $anchor['path']), 'The anchor command reads the same anchor.');
    }

    public function testTheTopHitsChainEntryAndRecheckCarryItsAnchor(): void
    {
        $result = self::twoCopies()->find('/phone-copy/', '555-0142');

        $this->assertSame(['pt:page', 'part:footer'], array_map(static fn (array $hit): ?string => $hit['anchor']['entity'] ?? null, $result['hits']));
        $this->assertSame(self::PAGE_ID, $result['hits'][0]['anchor']['post'] ?? null);
        $this->assertNotNull($result['hits'][0]['anchor']);
        $this->assertNull($result['hits'][0]['anchor']['expect_hash'], 'Post content is not a manifest item.');
        $this->assertSame($result['hits'][0]['anchor'], $result['chain'][0]['anchor'] ?? null);
        $this->assertArrayNotHasKey('anchor', $result['chain'][1]);
        $this->assertSame($result['hits'][0]['anchor'], $result['verify']['recheck']);

        $offRoute = self::twoCopies()->find('/', '555-0142')['hits'][1];
        $this->assertSame('page:phone-copy', $offRoute['id']);
        $this->assertSame(['entity' => 'pt:page', 'post' => self::PAGE_ID, 'path' => '0'], array_intersect_key((array) $offRoute['anchor'], ['entity' => 1, 'post' => 1, 'path' => 1]));
    }

    public function testVerifyCountsFromTheStoredManifestHashAndExpectsNoStructuralChange(): void
    {
        $layout = FakeRouteSource::layout();
        $routes = FakeRouteSource::fixture();
        $manifest = new Manifest([
            (new LayoutScanner($layout, new Locks('7.1.2')))->scan(),
            (new RouteScanner($routes, $layout))->scan(),
        ]);

        $result = self::locator($routes, $layout, static fn (): string => $manifest->hash)->find('/', '555-0142');

        $this->assertSame($manifest->hash, $result['verify']['changes_since']);
        $this->assertSame([], $result['verify']['expect_changed']);
        $this->assertNull(self::locator()->find('/', '555-0142')['verify']['changes_since'], 'Nothing stored, nothing to count from.');
        $this->assertNull(self::locator()->find('/about/')['verify']['recheck'], 'No text, no anchor to check.');
    }

    public function testATextEditMovesTheFingerprintButNoItemHash(): void
    {
        $layout = FakeRouteSource::layout();
        self::editFooter($layout, static fn (string $content): string => $content . FakeLayoutSource::paragraph('Licensed and insured.'));

        $anchor = self::locator(FakeRouteSource::fixture(), $layout)->find('/', 'Licensed and insured')['hits'][0]['anchor'] ?? null;
        $this->assertNotNull($anchor);
        $this->assertSame('part:footer', $anchor['entity']);

        $before = self::itemHashes($layout);
        self::editFooter($layout, static fn (string $content): string => str_replace('Licensed and insured.', 'Licensed, bonded and insured.', $content));

        $this->assertSame($before, self::itemHashes($layout), 'expect_changed is empty: item hashes cover structure, never text.');
        $this->assertNotSame($anchor['fp'], (new Anchors($layout, new Locks('7.1.2')))->anchor('part:footer', $anchor['path'])['fp'] ?? null);
    }

    public function testThePostTitleHasNoAnchor(): void
    {
        $result = self::locator()->find('/about/', 'About');

        $titles = array_values(array_filter($result['hits'], static fn (array $hit): bool => $hit['block_path'] === null));
        $this->assertNotSame([], $titles);
        $this->assertNull($titles[0]['anchor']);
    }

    private static function twoCopies(): EditLocator
    {
        $routes = FakeRouteSource::fixture();
        $routes->add(self::PAGE_ID, 'page', 'phone-copy', 'Phone copy', '/phone-copy/', '', FakeLayoutSource::paragraph(self::PHONE_COPY));

        $layout = FakeRouteSource::layout();
        $layout->content[] = self::page(FakeLayoutSource::paragraph(self::PHONE_COPY));

        return self::locator($routes, $layout);
    }

    /**
     * @param (callable(): (string|null))|null $storedHash
     */
    private static function locator(?FakeRouteSource $routes = null, ?FakeLayoutSource $layout = null, ?callable $storedHash = null): EditLocator
    {
        return new EditLocator(
            $routes ?? FakeRouteSource::fixture(),
            $layout ?? FakeRouteSource::layout(),
            new Locks('7.1.2'),
            new ClientAccess('editor', static fn (string $cap): bool => true, static fn (string $edit): string => 'read'),
            static fn (): ?array => null,
            $storedHash ?? static fn (): ?string => null
        );
    }

    /**
     * @return array{type: string, slug: string, content: string, id: int}
     */
    private static function page(string $content): array
    {
        return ['type' => 'page', 'slug' => 'phone-copy', 'content' => $content, 'id' => self::PAGE_ID];
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

    /**
     * @return array<string, string>
     */
    private static function itemHashes(FakeLayoutSource $layout): array
    {
        $hashes = [];

        foreach ((new LayoutScanner($layout, new Locks('7.1.2')))->scan()->items as $item) {
            $hashes[$item->id] = $item->hash;
        }

        return $hashes;
    }

    /**
     * @return list<BlockNode>
     */
    private static function tree(string $markup): array
    {
        return RouteScanner::builder(FakeRouteSource::layout())->build(parse_blocks($markup))['tree'];
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function node(string $name, array $attrs, string $text): BlockNode
    {
        return new BlockNode($name, 0, '0', $attrs, 0, [], '', null, [], $text);
    }
}
