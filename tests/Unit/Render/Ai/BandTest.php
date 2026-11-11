<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Render\Ai\Band;

final class BandTest extends TestCase
{
    /**
     * @return array<string, array{int, string, int}>
     */
    public static function edges(): array
    {
        return [
            'nothing'           => [0, '0', 0],
            'first in 1-5'      => [1, '1-5', 1],
            'last in 1-5'       => [5, '1-5', 1],
            'first in 6-20'     => [6, '6-20', 2],
            'last in 6-20'      => [20, '6-20', 2],
            'first in 21-100'   => [21, '21-100', 3],
            'last in 21-100'    => [100, '21-100', 3],
            'first past 100'    => [101, '100+', 4],
            'far past 100'      => [250000, '100+', 4],
            'a negative is 0'   => [-3, '0', 0],
        ];
    }

    #[DataProvider('edges')]
    public function testEachCountFallsInItsBand(int $count, string $band, int $rank): void
    {
        $this->assertSame($band, Band::of($count));
        $this->assertSame($rank, Band::rank($count));
    }

    public function testRanksSortTheBandsInOrder(): void
    {
        $ranks = array_map([Band::class, 'rank'], [0, 3, 12, 50, 500]);

        $this->assertSame([0, 1, 2, 3, 4], $ranks);
        $this->assertSame(Band::LABELS, array_map([Band::class, 'of'], [0, 3, 12, 50, 500]));
    }
}
