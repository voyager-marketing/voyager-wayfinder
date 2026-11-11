<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Render\Ai\Changes;

/**
 * The chain is the shape SnapshotStore::store() writes.
 */
final class ChangesTest extends TestCase
{
    private const HISTORY = [
        ['previous' => null, 'hash' => 'aaa', 'added' => ['pt:service'], 'removed' => [], 'changed' => [], 'generated_at' => ''],
        ['previous' => 'aaa', 'hash' => 'bbb', 'added' => ['field:service.warranty_years'], 'removed' => [], 'changed' => ['pt:service'], 'generated_at' => ''],
        ['previous' => 'bbb', 'hash' => 'ccc', 'added' => [], 'removed' => ['nav:old'], 'changed' => ['part:footer', 'pt:service'], 'generated_at' => ''],
    ];

    public function testTheCurrentHashHasNothingChanged(): void
    {
        $this->assertSame(['changed' => [], 'full' => false, 'hash' => 'ccc'], Changes::since('ccc', 'ccc', []));
    }

    public function testAHashInTheChainGetsEveryIdAddedRemovedOrChangedSinceSortedAndUnique(): void
    {
        $this->assertSame(
            ['changed' => ['field:service.warranty_years', 'nav:old', 'part:footer', 'pt:service'], 'full' => false, 'hash' => 'ccc'],
            Changes::since('aaa', 'ccc', self::HISTORY)
        );
        $this->assertSame(
            ['changed' => ['nav:old', 'part:footer', 'pt:service'], 'full' => false, 'hash' => 'ccc'],
            Changes::since('bbb', 'ccc', self::HISTORY)
        );
    }

    public function testAnUnknownHashOrNoHistoryMeansFetchEverything(): void
    {
        $this->assertSame(['full' => true, 'hash' => 'ccc'], Changes::since('zzz', 'ccc', self::HISTORY));
        $this->assertSame(['full' => true, 'hash' => 'ccc'], Changes::since('aaa', 'ccc', false));
    }

    public function testAChainThatDoesNotReachTheCurrentSiteMeansFetchEverything(): void
    {
        // The site changed after the last store.
        $this->assertSame(['full' => true, 'hash' => 'ddd'], Changes::since('aaa', 'ddd', self::HISTORY));

        // A broken link.
        $broken = self::HISTORY;
        $broken[2]['previous'] = 'xxx';
        $this->assertSame(['full' => true, 'hash' => 'ccc'], Changes::since('aaa', 'ccc', $broken));
    }
}
