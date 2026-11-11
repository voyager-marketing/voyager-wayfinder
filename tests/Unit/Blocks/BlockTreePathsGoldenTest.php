<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Blocks;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\BlockNode;
use Voyager\Wayfinder\Blocks\BlockTreeBuilder;

/**
 * The hero fixture's tree as one row per node, against voyager-orbit's golden.
 *
 * `hero-nested.html`, `hero-nested.paths.json` and `hero-nested.sha256` are
 * byte-identical copies of voyager-orbit tests/fixtures/blocks/.
 * Orbit's `BlockTreePathsGoldenTest` checks its walker against the same rows,
 * so a change to either walker that moves a path, a name, a depth or a child
 * count fails one of the two suites. Change the fixture in both repos in the
 * same change. Only `index_path`, `blockName`, `depth` and `innerBlockCount`
 * are compared: Wayfinder's own `text`, `classes` and `names` are not part of
 * the contract, and neither is the golden's `bindings` column.
 *
 * @covers \Voyager\Wayfinder\Blocks\BlockTreeBuilder
 */
final class BlockTreePathsGoldenTest extends TestCase
{
    public function testTheHeroFixtureHashMatchesThePinnedSha256(): void
    {
        $pinned = trim((string) file_get_contents(self::path('hero-nested.sha256')));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $pinned, 'Sanity: the file holds one bare hex digest.');
        $this->assertSame($pinned, hash_file('sha256', self::path('hero-nested.html')));
    }

    public function testPathsForTheHeroFixtureMatchTheCommittedGolden(): void
    {
        $golden = json_decode((string) file_get_contents(self::path('hero-nested.paths.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($golden);
        $this->assertCount(9, $golden, 'Sanity: the golden was read and carries one row per block.');

        $built = (new BlockTreeBuilder())->build(parse_blocks((string) file_get_contents(self::path('hero-nested.html'))));
        $rows  = self::rows($built['tree']);

        $this->assertCount(count($golden), $rows, 'The walker built a different number of nodes than the golden has rows.');

        foreach ($golden as $i => $expected) {
            $this->assertSame(
                [
                    'index_path'      => $expected['index_path'],
                    'blockName'       => $expected['blockName'],
                    'depth'           => $expected['depth'],
                    'innerBlockCount' => $expected['innerBlockCount'],
                ],
                $rows[$i],
                sprintf('Row %d (index_path %s) differs from the golden.', $i, $expected['index_path'])
            );
        }
    }

    private static function path(string $file): string
    {
        return dirname(__DIR__, 2) . '/fixtures/blocks/' . $file;
    }

    /**
     * One row per node in build order (parents before children), with the
     * dotted index path joined by slashes as the golden writes it.
     *
     * @param list<BlockNode> $nodes
     * @return list<array{index_path: string, blockName: string, depth: int, innerBlockCount: int}>
     */
    private static function rows(array $nodes): array
    {
        $rows = [];

        foreach ($nodes as $node) {
            $rows[] = [
                'index_path'      => str_replace('.', '/', $node->indexPath),
                'blockName'       => $node->name,
                'depth'           => $node->depth,
                'innerBlockCount' => $node->innerBlockCount,
            ];

            array_push($rows, ...self::rows($node->children));
        }

        return $rows;
    }
}
