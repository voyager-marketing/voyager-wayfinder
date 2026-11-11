<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Differ;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;

final class DifferTest extends TestCase
{
    public function testIdenticalManifestsHaveNoChanges(): void
    {
        $this->assertSame(
            ['added' => [], 'removed' => [], 'changed' => []],
            Differ::between($this->manifest(), $this->manifest())
        );
    }

    public function testAnItemOnlyInTheNewManifestIsAdded(): void
    {
        $after = $this->manifest(extraFields: [new Item('field:service.warranty_years', 'field', 'Warranty years', ['type' => 'integer'])]);

        $this->assertSame(
            ['added' => ['field:service.warranty_years'], 'removed' => [], 'changed' => []],
            Differ::between($this->manifest(), $after)
        );
    }

    public function testAnItemOnlyInTheOldManifestIsRemoved(): void
    {
        $after = $this->manifest(withTeam: false);

        $this->assertSame(
            ['added' => [], 'removed' => ['pt:team_member'], 'changed' => []],
            Differ::between($this->manifest(), $after)
        );
    }

    public function testAnItemWhoseFactsChangedIsChangedAndItsNeighboursAreNot(): void
    {
        $after = $this->manifest(servicePublic: false);

        $this->assertSame(
            ['added' => [], 'removed' => [], 'changed' => ['pt:service']],
            Differ::between($this->manifest(), $after)
        );
    }

    public function testPublishingChangesOnlyStatsSoNothingReadsAsChanged(): void
    {
        $after = $this->manifest(serviceCount: 7);

        $this->assertSame(['added' => [], 'removed' => [], 'changed' => []], Differ::between($this->manifest(), $after));
        $this->assertSame($this->manifest()->hash, $after->hash);
        $this->assertNotSame($this->manifest()->statsHash, $after->statsHash);
    }

    public function testANewRefChangesTheItemThatRefsIt(): void
    {
        $after = $this->manifest(
            serviceRefs: ['field:service.price_from', 'field:service.warranty_years'],
            extraFields: [new Item('field:service.warranty_years', 'field', 'Warranty years', ['type' => 'integer'])]
        );

        $this->assertSame(
            ['added' => ['field:service.warranty_years'], 'removed' => [], 'changed' => ['pt:service']],
            Differ::between($this->manifest(), $after)
        );
    }

    public function testEachListIsSortedById(): void
    {
        $diff = Differ::diff(
            ['pt:b' => '1', 'pt:z' => '1', 'pt:a' => '1', 'tax:y' => '1', 'tax:c' => '1'],
            ['pt:z' => '2', 'pt:a' => '2', 'opt:x' => '1', 'opt:d' => '1', 'tax:c' => '1']
        );

        $this->assertSame(['opt:d', 'opt:x'], $diff['added']);
        $this->assertSame(['pt:b', 'tax:y'], $diff['removed']);
        $this->assertSame(['pt:a', 'pt:z'], $diff['changed']);
    }

    public function testAnEmptyBeforeAddsEveryItem(): void
    {
        $diff = Differ::diff([], Differ::itemHashes($this->manifest()->body()));

        $this->assertSame($this->manifest()->ids(), $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([], $diff['changed']);
    }

    public function testABodyDecodedFromJsonGivesTheSameItemHashesAsTheLiveManifest(): void
    {
        $manifest = $this->manifest();
        $decoded = json_decode(CanonicalJson::encode($manifest->body()), true);

        $this->assertIsArray($decoded);
        $this->assertSame(Differ::itemHashes($manifest->body()), Differ::itemHashes($decoded));
        $this->assertSame($manifest->ids(), array_keys(Differ::itemHashes($decoded)));
    }

    public function testItemHashesRejectsSomethingThatIsNotAManifestBody(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Differ::itemHashes(['sections' => [['name' => 'site', 'items' => [['id' => 'opt:blogname']]]]]);
    }

    /**
     * @param list<string> $serviceRefs
     * @param list<Item>   $extraFields
     */
    private function manifest(
        int $serviceCount = 6,
        bool $withTeam = true,
        array $serviceRefs = ['field:service.price_from'],
        array $extraFields = [],
        bool $servicePublic = true
    ): Manifest {
        $model = [
            new Item('pt:service', 'post_type', 'Services', ['public' => $servicePublic], 'edit.php?post_type=service', $serviceRefs, ['counts' => ['publish' => $serviceCount]]),
            new Item('field:service.price_from', 'field', 'Price from', ['type' => 'number']),
            ...$extraFields,
        ];

        if ($withTeam) {
            $model[] = new Item('pt:team_member', 'post_type', 'Team members', ['public' => false], 'edit.php?post_type=team_member');
        }

        return new Manifest([
            new Section('content_model', $model),
            new Section('site', [new Item('opt:blogname', 'option', 'Site title', ['value' => 'Riverbend Plumbing'], 'options-general.php')]),
        ]);
    }
}
