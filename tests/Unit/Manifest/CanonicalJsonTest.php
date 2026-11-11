<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\CanonicalJson;

final class CanonicalJsonTest extends TestCase
{
    public function testSortsKeysAtEveryDepthAndKeepsListOrder(): void
    {
        $this->assertSame(
            '{"a":1,"b":{"x":[3,1,2],"y":{"m":true,"n":null}}}',
            CanonicalJson::encode(['b' => ['y' => ['n' => null, 'm' => true], 'x' => [3, 1, 2]], 'a' => 1])
        );
    }

    public function testDoesNotEscapeSlashesOrUnicodeAndDoesNotPrettyPrint(): void
    {
        $json = CanonicalJson::encode(['edit' => 'edit.php?post_type=service', 'label' => 'Café menu']);

        $this->assertSame('{"edit":"edit.php?post_type=service","label":"Café menu"}', $json);
        $this->assertStringNotContainsString("\n", $json);
    }

    public function testAMapWhoseSortedKeysLookLikeAListStaysAnObject(): void
    {
        $this->assertSame('{"0":"b","1":"a"}', CanonicalJson::encode([1 => 'a', 0 => 'b']));
    }

    public function testHashIsSha256OfTheCompactEncoding(): void
    {
        $value = ['b' => 2, 'a' => 1];

        $this->assertSame(hash('sha256', '{"a":1,"b":2}'), CanonicalJson::hash($value));
        $this->assertSame(CanonicalJson::hash($value), CanonicalJson::hash(['a' => 1, 'b' => 2]));
    }

    public function testRejectsObjectsItCannotOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode(['when' => new \DateTimeImmutable('2026-10-01')]);
    }
}
