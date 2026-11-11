<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Blocks;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\BlockNode;
use Voyager\Wayfinder\Blocks\BlockTreeBuilder;
use Voyager\Wayfinder\Blocks\ReferenceResolver;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;

/**
 * The port from voyager-orbit and its two additions: synced pattern slugs and
 * core/navigation as a pointer block.
 */
final class BlockTreeBuilderTest extends TestCase
{
    public function testTheHeaderPartIsWalkedWithZeroBasedDepthAndIndexPaths(): void
    {
        ['tree' => $tree, 'summary' => $summary] = self::builder()->build(parse_blocks(FakeLayoutSource::themeFile('parts/header.html')));

        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['depth']);
        $this->assertCount(1, $tree);

        [$title, $navigation] = $tree[0]->children[0]->children;
        $this->assertSame('core/site-title', $title->name);
        $this->assertSame('0.0.0', $title->indexPath);
        $this->assertSame('core/navigation', $navigation->name);
        $this->assertSame(2, $navigation->depth);
        $this->assertSame('0.0.1', $navigation->indexPath);
    }

    public function testANavigationRefResolvesToItsMenuAndALinkListIsNotAPointer(): void
    {
        $tree = self::builder()->build(parse_blocks(implode("\n", [
            '<!-- wp:navigation {"ref":90001} /-->',
            '<!-- wp:navigation {"ref":404} /-->',
            '<!-- wp:navigation -->',
            '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
            '<!-- /wp:navigation -->',
        ])))['tree'];

        $this->assertSame(
            ['type' => 'navigation', 'id' => 90001, 'slug' => 'primary', 'title' => 'Primary', 'found' => true],
            $tree[0]->reference
        );
        $this->assertSame('menu #90001 Primary', $tree[0]->label);
        $this->assertFalse($tree[1]->reference['found'] ?? null);
        $this->assertSame('menu #404 missing', $tree[1]->label);
        $this->assertNull($tree[2]->reference, 'Inline links are stored in the block itself, not elsewhere.');
        $this->assertSame(['core/navigation-link'], array_map(static fn (BlockNode $n): string => $n->name, $tree[2]->children));
    }

    public function testASyncedPatternRefCarriesTheSlugAndPartsAndPatternsAreNotInlined(): void
    {
        $tree = self::builder()->build(parse_blocks(FakeLayoutSource::themeFile('templates/single-service.html')))['tree'];

        $part = $tree[0];
        $this->assertSame('core/template-part', $part->name);
        $this->assertSame(['type' => 'template_part', 'slug' => 'header', 'theme' => null, 'area' => null], $part->reference);
        $this->assertSame([], $part->children);

        $block = $tree[1]->children[3];
        $this->assertSame('core/block', $block->name);
        $this->assertSame(
            ['type' => 'synced_pattern', 'id' => 90003, 'slug' => 'cta-banner', 'title' => 'CTA banner', 'found' => true],
            $block->reference
        );
        $this->assertSame('ref #90003 CTA banner', $block->label);
    }

    public function testWhitespaceFreeformIsSkippedAndBindingsAreFlagged(): void
    {
        $tree = self::builder()->build([
            ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n\n"],
            ['blockName' => 'core/paragraph', 'attrs' => ['metadata' => ['bindings' => ['content' => ['source' => 'core/post-meta']]]], 'innerBlocks' => [], 'innerHTML' => '<p>Hi</p>'],
        ])['tree'];

        $this->assertCount(1, $tree);
        $this->assertSame('0', $tree[0]->indexPath);
        $this->assertTrue($tree[0]->flags['bindings']);
        $this->assertSame('Hi', $tree[0]->label);
    }

    private static function builder(): BlockTreeBuilder
    {
        return new BlockTreeBuilder(new ReferenceResolver(
            static fn (int $id): ?array => $id === 90003 ? ['id' => 90003, 'title' => 'CTA banner', 'slug' => 'cta-banner'] : null,
            static fn (string $slug): ?array => null,
            static fn (int $id): ?array => $id === 90001 ? ['id' => 90001, 'title' => 'Primary', 'slug' => 'primary'] : null
        ));
    }
}
