<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;

final class SectionTest extends TestCase
{
    public function testTwoItemsWithOneIdThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('two items with id "opt:blogname"');

        new Section('site', [
            new Item('opt:blogname', 'option', 'Site title'),
            new Item('opt:blogname', 'option', 'Site title, again'),
        ]);
    }

    public function testAnInvalidSectionNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Section('Content Model', []);
    }

    /**
     * Every prefix in docs/ai-context-format.md is accepted.
     *
     * @return array<string, array{string}>
     */
    public static function documentedIds(): array
    {
        return [
            'pt:'      => ['pt:service'],
            'tax:'     => ['tax:service_area'],
            'field:'   => ['field:service.price_from'],
            'tpl:'     => ['tpl:single-service'],
            'part:'    => ['part:footer'],
            'pat:'     => ['pat:cta-banner'],
            'nav:'     => ['nav:primary'],
            'loc:'     => ['loc:primary'],
            'sidebar:' => ['sidebar:sidebar-1'],
            'bind:'    => ['bind:vwf-fixture/business-info.phone'],
            'route:'   => ['route:single-service'],
            'plugin:'  => ['plugin:advanced-custom-fields'],
            'opt:'     => ['opt:show_on_front'],
            'optpage:' => ['optpage:theme-settings'],
            'core:'    => ['core:wordpress'],
            'row id'   => ['nav:#42'],
        ];
    }

    #[DataProvider('documentedIds')]
    public function testEveryDocumentedPrefixIsAccepted(string $id): void
    {
        $this->assertTrue(IdFormat::isValid($id));
        $this->assertSame($id, (new Section('any', [new Item($id, 'thing', 'Thing')]))->items[0]->id);
    }

    public function testThePrefixListMatchesTheFormatDoc(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/ai-context-format.md');
        preg_match_all('/^\| `([a-z]+):` \|/m', $doc, $matches);

        $this->assertNotEmpty($matches[1]);
        $this->assertSame($matches[1], IdFormat::PREFIXES);
    }
}
