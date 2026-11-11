<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Item;

final class ItemTest extends TestCase
{
    public function testRefsAreSortedAndDeDuplicated(): void
    {
        $item = new Item('pt:service', 'post_type', 'Services', [], null, [
            'tpl:single-service',
            'tax:service_area',
            'field:service.price_from',
            'tpl:single-service',
            'tax:service_area',
        ]);

        $this->assertSame(['field:service.price_from', 'tax:service_area', 'tpl:single-service'], $item->refs);
        $this->assertSame(
            (new Item('pt:service', 'post_type', 'Services', [], null, ['field:service.price_from', 'tax:service_area', 'tpl:single-service']))->hash,
            $item->hash
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIds(): array
    {
        return [
            'unknown prefix'        => ['theme:twentytwentyfive'],
            'no prefix'             => ['blogname'],
            'empty slug'            => ['opt:'],
            'uppercase prefix'      => ['OPT:blogname'],
            'whitespace in slug'    => ['pt:my service'],
            'column separator'      => ['pt:a|b'],
            'ref separator'         => ['pt:a,b'],
            'empty'                 => [''],
        ];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidPrefixThrows(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid item id');

        new Item($id, 'option', 'Label');
    }

    public function testAnInvalidRefThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"post:hello"');

        new Item('pt:post', 'post_type', 'Posts', [], null, ['post:hello']);
    }

    public function testAnEmptyKindThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Item('pt:post', '', 'Posts');
    }

    public function testFactKeyOrderDoesNotChangeTheHash(): void
    {
        $this->assertSame(
            (new Item('pt:post', 'post_type', 'Posts', ['public' => true, 'supports' => ['title', 'editor']]))->hash,
            (new Item('pt:post', 'post_type', 'Posts', ['supports' => ['title', 'editor'], 'public' => true]))->hash
        );
    }

    public function testEveryFieldIsCoveredByTheHash(): void
    {
        $base = new Item('pt:post', 'post_type', 'Posts', ['public' => true], 'edit.php', ['tax:category']);

        $variants = [
            new Item('pt:page', 'post_type', 'Posts', ['public' => true], 'edit.php', ['tax:category']),
            new Item('pt:post', 'type', 'Posts', ['public' => true], 'edit.php', ['tax:category']),
            new Item('pt:post', 'post_type', 'Articles', ['public' => true], 'edit.php', ['tax:category']),
            new Item('pt:post', 'post_type', 'Posts', ['public' => false], 'edit.php', ['tax:category']),
            new Item('pt:post', 'post_type', 'Posts', ['public' => true], null, ['tax:category']),
            new Item('pt:post', 'post_type', 'Posts', ['public' => true], 'edit.php', []),
        ];

        foreach ($variants as $variant) {
            $this->assertNotSame($base->hash, $variant->hash);
        }
    }

    public function testStatsAreLeftOutOfTheHashAndTheHashedFields(): void
    {
        $base = new Item('pt:service', 'post_type', 'Services', ['public' => true], 'edit.php?post_type=service', [], ['counts' => ['publish' => 6]]);
        $published = new Item('pt:service', 'post_type', 'Services', ['public' => true], 'edit.php?post_type=service', [], ['counts' => ['publish' => 7]]);
        $none = new Item('pt:service', 'post_type', 'Services', ['public' => true], 'edit.php?post_type=service');

        $this->assertSame($base->hash, $published->hash);
        $this->assertSame($base->hash, $none->hash);
        $this->assertSame(['counts' => ['publish' => 7]], $published->stats);
        $this->assertArrayNotHasKey('stats', $published->toArray());
        $this->assertSame(CanonicalJson::encode($base->toArray()), CanonicalJson::encode($published->toArray()));
    }

    public function testEmptyEditIsNullAndEmptyFactsEncodeAsAnObject(): void
    {
        $item = new Item('opt:blogname', 'option', 'Site title', [], '');

        $this->assertNull($item->edit);
        $this->assertSame(
            '{"edit":null,"facts":{},"hash":"' . $item->hash . '","id":"opt:blogname","kind":"option","label":"Site title","refs":[]}',
            CanonicalJson::encode($item->toArray())
        );
    }
}
