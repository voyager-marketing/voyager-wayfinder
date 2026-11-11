<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Scanners\Scanner;
use Voyager\Wayfinder\Scanners\ScannerRegistry;
use Voyager\Wayfinder\Scanners\SiteScanner;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class ScannerRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testFromFiltersPassesTheDefaultsThroughVwfScanners(): void
    {
        $registry = ScannerRegistry::fromFilters();

        $this->assertCount(1, WpCalls::$filters);
        $this->assertSame('vwf_scanners', WpCalls::$filters[0]['hook']);
        $this->assertSame(['code', 'content_model', 'integrations', 'layout', 'routes', 'site'], $registry->sections());
    }

    public function testAFilterCanAddAScanner(): void
    {
        WpCalls::$filterCallbacks['vwf_scanners'] = fn (mixed $scanners): array => [
            ...(array) $scanners,
            $this->scanner('extra', 'extra', 'opt:extra'),
        ];

        $this->assertSame(['code', 'content_model', 'extra', 'integrations', 'layout', 'routes', 'site'], ScannerRegistry::fromFilters()->sections());
    }

    public function testAFilterReturningANonScannerFailsLoudly(): void
    {
        WpCalls::$filterCallbacks['vwf_scanners'] = static fn (mixed $scanners): array => [...(array) $scanners, 'not a scanner'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('vwf_scanners must hold only');

        ScannerRegistry::fromFilters();
    }

    public function testTwoScannersForOneSectionThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ScannerRegistry([new SiteScanner(static fn (): string => 'x'), $this->scanner('site', 'site', 'opt:x')]);
    }

    public function testScanRunsOneSectionOrAllSortedByName(): void
    {
        $registry = new ScannerRegistry([
            $this->scanner('site', 'site', 'opt:blogname'),
            $this->scanner('content_model', 'content_model', 'pt:post'),
        ]);

        $this->assertSame(['content_model', 'site'], array_map(static fn (Section $s): string => $s->name, $registry->scan()));
        $this->assertSame(['site'], array_map(static fn (Section $s): string => $s->name, $registry->scan('site')));
    }

    public function testAnUnknownSectionNamesTheKnownOnes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown section "layout". Known sections: site.');

        (new ScannerRegistry([$this->scanner('site', 'site', 'opt:blogname')]))->scan('layout');
    }

    public function testAScannerReturningTheWrongSectionThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new ScannerRegistry([$this->scanner('site', 'layout', 'tpl:index')]))->scan();
    }

    private function scanner(string $declares, string $returns, string $id): Scanner
    {
        return new class ($declares, $returns, $id) implements Scanner {
            public function __construct(
                private readonly string $declares,
                private readonly string $returns,
                private readonly string $id
            ) {
            }

            public function section(): string
            {
                return $this->declares;
            }

            public function scan(): Section
            {
                return new Section($this->returns, [new Item($this->id, 'thing', 'Thing')]);
            }
        };
    }
}
