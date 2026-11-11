<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Differ;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Manifest\SnapshotStore;

final class SnapshotStoreTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> Option names, in write order. */
    private array $writes = [];

    protected function setUp(): void
    {
        $this->options = [];
        $this->writes = [];
    }

    public function testTheFirstStoreHasNoPreviousHashAndAddsEveryItem(): void
    {
        $manifest = $this->manifest('Riverbend Plumbing');
        $change = $this->store()->store($manifest);

        $this->assertNull($change['previous']);
        $this->assertSame($manifest->hash, $change['hash']);
        $this->assertSame($manifest->ids(), $change['added']);
        $this->assertSame([], $change['removed']);
        $this->assertSame([], $change['changed']);
    }

    public function testTheStoredBodyHashesToTheStoredHash(): void
    {
        $manifest = $this->manifest('Riverbend Plumbing');
        $this->store()->store($manifest);

        $stored = $this->options[SnapshotStore::SNAPSHOT_OPTION];

        $this->assertIsArray($stored);
        $this->assertIsString($stored['body']);
        $this->assertSame($manifest->hash, $stored['hash']);
        $this->assertSame($manifest->hash, hash('sha256', $stored['body']));
        $this->assertSame(Differ::itemHashes($manifest->body()), $this->store()->itemHashes());
    }

    public function testStoringAnUnchangedManifestWritesNothing(): void
    {
        $this->store()->store($this->manifest('Riverbend Plumbing'));
        $writes = $this->writes;

        $change = $this->store()->store($this->manifest('Riverbend Plumbing'));

        $this->assertSame($writes, $this->writes);
        $this->assertSame($change['hash'], $change['previous']);
        $this->assertSame([], $change['added']);
        $this->assertCount(1, $this->store()->history());
    }

    public function testAChangeIsStoredWithItsIdsAndThePreviousHash(): void
    {
        $first = $this->manifest('Riverbend Plumbing');
        $second = $this->manifest('Riverbend Plumbing Co', withTagline: true);

        $this->store()->store($first);
        $change = $this->store()->store($second);

        $this->assertSame($first->hash, $change['previous']);
        $this->assertSame(['opt:blogdescription'], $change['added']);
        $this->assertSame(['opt:blogname'], $change['changed']);
        $this->assertSame($second->hash, $this->store()->snapshot()['hash'] ?? null);

        $history = $this->store()->history();
        $this->assertCount(2, $history);
        $this->assertSame($first->hash, $history[1]['previous']);
        $this->assertSame($second->hash, $history[1]['hash']);
        $this->assertSame('2026-10-01T12:00:00Z', $history[1]['generated_at']);
    }

    public function testHistoryKeepsTheLastFiveChangesOldestFirst(): void
    {
        $hashes = [];

        for ($i = 1; $i <= 7; $i++) {
            $manifest = $this->manifest('Site ' . $i);
            $hashes[] = $manifest->hash;
            $this->store()->store($manifest);
        }

        $history = $this->store()->history();

        $this->assertCount(SnapshotStore::HISTORY_LIMIT, $history);
        $this->assertSame(array_slice($hashes, 2), array_column($history, 'hash'));
        $this->assertSame(array_slice($hashes, 1, 5), array_column($history, 'previous'));
    }

    public function testTheSnapshotRecordsThePluginVersionThatMadeIt(): void
    {
        $this->store()->store($this->manifest('Riverbend Plumbing', false, '0.1.0'));

        $this->assertSame('0.1.0', $this->store()->snapshot()['plugin_version'] ?? null);
    }

    public function testANewPluginVersionIsStoredEvenWhenTheManifestIsUnchanged(): void
    {
        $this->store()->store($this->manifest('Riverbend Plumbing', false, '0.1.0'));
        $this->writes = [];

        $change = $this->store()->store($this->manifest('Riverbend Plumbing', false, '0.2.0'));

        $this->assertSame([SnapshotStore::SNAPSHOT_OPTION], $this->writes, 'the version is recorded, and no change is added to the history');
        $this->assertSame([], $change['changed']);
        $this->assertSame('0.2.0', $this->store()->snapshot()['plugin_version'] ?? null);
    }

    public function testASnapshotWithoutAVersionReadsAsNull(): void
    {
        $manifest = $this->manifest('Riverbend Plumbing');
        $this->options[SnapshotStore::SNAPSHOT_OPTION] = ['hash' => $manifest->hash, 'body' => json_encode($manifest->body())];

        $snapshot = $this->store()->snapshot();

        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot['plugin_version']);
    }

    public function testAnUnreadableSnapshotCountsAsNone(): void
    {
        $this->options[SnapshotStore::SNAPSHOT_OPTION] = ['hash' => 'abc', 'body' => '{not json'];

        $this->assertNull($this->store()->snapshot());
        $this->assertSame([], $this->store()->itemHashes());
        $this->assertNull($this->store()->store($this->manifest('Riverbend Plumbing'))['previous']);
    }

    private function store(): SnapshotStore
    {
        return new SnapshotStore(
            fn (string $name): mixed => $this->options[$name] ?? null,
            function (string $name, mixed $value): void {
                $this->options[$name] = $value;
                $this->writes[] = $name;
            }
        );
    }

    private function manifest(string $blogname, bool $withTagline = false, ?string $pluginVersion = null): Manifest
    {
        $items = [new Item('opt:blogname', 'option', 'Site title', ['value' => $blogname], 'options-general.php')];

        if ($withTagline) {
            $items[] = new Item('opt:blogdescription', 'option', 'Tagline', ['value' => ''], 'options-general.php');
        }

        $header = ['generated_at' => '2026-10-01T12:00:00Z'];
        if ($pluginVersion !== null) {
            $header['plugin_version'] = $pluginVersion;
        }

        return new Manifest([new Section('site', $items)], $header);
    }
}
