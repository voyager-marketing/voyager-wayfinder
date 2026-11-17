<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\ManifestCache;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\Context;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;
use Voyager\Wayfinder\Tests\Support\HandoffFixture;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class ManifestCacheTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    private int $now = 1_000_000;

    private int $ttl = ManifestCache::DEFAULT_TTL;

    /** @var array{fingerprint: string, generation: int, theme_time: int, version: string} */
    private array $site = ['fingerprint' => 'f1', 'generation' => 0, 'theme_time' => 100, 'version' => '0.11.0'];

    private int $scans = 0;

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testASecondReadOfTheBriefWithNoSiteChangeDoesNotScan(): void
    {
        $cache = $this->cache();

        foreach ([1, 2] as $read) {
            // A fresh Context per read, as each REST request builds one.
            $context = new Context(fn (): Manifest => $cache->remember($this->scan(...)), static fn (): array => []);
            $this->assertNotSame('', (string) $context->render(BriefRenderer::TIER), "read {$read}");
        }

        $this->assertSame(1, $this->scans);
    }

    public function testTheCachedManifestIsTheScannedOneByteForByte(): void
    {
        $cache = $this->cache();
        $scanned = $cache->remember($this->scan(...));
        $cached = $cache->remember($this->scan(...));

        $this->assertSame(1, $this->scans);
        $this->assertSame($scanned->toJson(), $cached->toJson());
    }

    /**
     * @dataProvider siteChanges
     */
    public function testEachPartOfTheKeyMakesTheNextReadRescan(string $part, int|string $value): void
    {
        $cache = $this->cache();
        $cache->remember($this->scan(...));

        $this->site[$part] = $value;
        $cache->remember($this->scan(...));

        $this->assertSame(2, $this->scans, "a change to {$part} must rescan");
    }

    /**
     * @return array<string, array{string, int|string}>
     */
    public static function siteChanges(): array
    {
        return [
            'fingerprint'        => ['fingerprint', 'f2'],
            'content generation' => ['generation', 1],
            'theme file time'    => ['theme_time', 101],
            'plugin version'     => ['version', '0.12.0'],
        ];
    }

    public function testAnEntryAsOldAsTheCapIsAMiss(): void
    {
        $cache = $this->cache();
        $cache->remember($this->scan(...));

        $this->now += ManifestCache::DEFAULT_TTL - 1;
        $cache->remember($this->scan(...));
        $this->assertSame(1, $this->scans, 'one second under the cap still hits');

        $this->now += 1;
        $cache->remember($this->scan(...));
        $this->assertSame(2, $this->scans, 'at the cap it rescans');
    }

    public function testAnEntryFromTheFutureIsAMiss(): void
    {
        $cache = $this->cache();
        $cache->remember($this->scan(...));

        $this->now -= 10;
        $cache->remember($this->scan(...));

        $this->assertSame(2, $this->scans);
    }

    public function testACapOfZeroScansEveryTimeAndStoresNothing(): void
    {
        $this->ttl = 0;
        $cache = $this->cache();

        $cache->remember($this->scan(...));
        $cache->remember($this->scan(...));

        $this->assertSame(2, $this->scans);
        $this->assertNull($this->stored);
    }

    public function testAnEntryThatFailsTheHashCheckIsReplacedByAFreshScan(): void
    {
        $cache = $this->cache();
        $scanned = $cache->remember($this->scan(...));

        $this->assertIsArray($this->stored);
        // One label changed in place: the stored manifest_hash no longer matches its body.
        $this->stored['manifest'] = str_replace('"label":"Services"', '"label":"Servicez"', $this->stored['manifest'], $count);
        $this->assertSame(1, $count, 'the fixture has one Services label to tamper with');

        $this->assertNull($cache->get());
        $again = $cache->remember($this->scan(...));

        $this->assertSame(2, $this->scans);
        $this->assertSame($scanned->hash, $again->hash);
        $this->assertStringNotContainsString('Servicez', $this->stored['manifest']);
    }

    /**
     * @dataProvider badEntries
     */
    public function testAnEntryInTheWrongShapeIsAMiss(mixed $entry): void
    {
        $this->stored = null;
        $cache = new ManifestCache(
            fn (): string => $this->key(),
            static fn (): mixed => $entry,
            static function (array $entry): void {
            },
            fn (): int => $this->now,
            fn (): int => $this->ttl
        );

        $this->assertNull($cache->get());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badEntries(): array
    {
        return [
            'nothing stored' => [null],
            'a string'       => ['{"key":"x"}'],
            'no manifest'    => [['key' => 'x', 'stored_at' => 1]],
            'string time'    => [['key' => 'x', 'stored_at' => '1', 'manifest' => '{}']],
        ];
    }

    public function testTheKeyMovesWithEachPart(): void
    {
        $base = ManifestCache::key('f', 0, 100, '1.0.0');

        $this->assertSame($base, ManifestCache::key('f', 0, 100, '1.0.0'));
        $this->assertNotSame($base, ManifestCache::key('g', 0, 100, '1.0.0'));
        $this->assertNotSame($base, ManifestCache::key('f', 1, 100, '1.0.0'));
        $this->assertNotSame($base, ManifestCache::key('f', 0, 101, '1.0.0'));
        $this->assertNotSame($base, ManifestCache::key('f', 0, 100, '1.0.1'));
    }

    public function testThemeTimeSeesTemplatesAndPatternsButNotDeeperFolders(): void
    {
        $root = sys_get_temp_dir() . '/vwf-theme-' . bin2hex(random_bytes(4));
        mkdir($root . '/templates', 0777, true);
        mkdir($root . '/patterns', 0777, true);
        mkdir($root . '/assets/js', 0777, true);
        file_put_contents($root . '/style.css', '/* Theme Name: Test */');
        file_put_contents($root . '/templates/index.html', '<!-- wp:post-content /-->');
        file_put_contents($root . '/patterns/hero.php', '<?php // hero');
        file_put_contents($root . '/assets/js/app.js', '');

        foreach ([$root . '/style.css', $root . '/templates/index.html', $root . '/patterns/hero.php', $root . '/assets/js/app.js', $root . '/templates', $root . '/patterns', $root . '/assets', $root . '/assets/js', $root] as $path) {
            touch($path, 1000);
        }

        try {
            $this->assertSame(1000, ManifestCache::themeTime([$root]));

            touch($root . '/assets/js/app.js', 5000);
            $this->assertSame(1000, ManifestCache::themeTime([$root]), 'a file two levels down in assets does not count');

            touch($root . '/templates/index.html', 2000);
            $this->assertSame(2000, ManifestCache::themeTime([$root]), 'an edited template counts');

            touch($root . '/patterns/hero.php', 3000);
            $this->assertSame(3000, ManifestCache::themeTime([$root]), 'an edited pattern counts');

            $this->assertSame(3000, ManifestCache::themeTime([$root, $root . '/missing']), 'a missing root is skipped');
        } finally {
            foreach (['/style.css', '/templates/index.html', '/patterns/hero.php', '/assets/js/app.js'] as $file) {
                unlink($root . $file);
            }

            foreach (['/assets/js', '/assets', '/templates', '/patterns', ''] as $dir) {
                rmdir($root . $dir);
            }
        }
    }

    public function testAContentChangeBumpsTheGeneration(): void
    {
        ManifestCache::onContentChange(12);
        ManifestCache::onContentChange(12);

        $this->assertSame(2, WpCalls::$options[ManifestCache::GENERATION_OPTION]);
    }

    public function testRegisterHooksEveryContentEvent(): void
    {
        ManifestCache::register();

        $hooked = array_column(WpCalls::$actions, 'hook');

        foreach (ManifestCache::CONTENT_HOOKS as $hook) {
            $this->assertContains($hook, $hooked);
        }
    }

    public function testFromJsonRebuildsBothFixturesWithTheSameHashes(): void
    {
        foreach ([FixtureManifest::manifest(), HandoffFixture::manifest()] as $manifest) {
            $rebuilt = Manifest::fromJson($manifest->toJson());

            $this->assertNotNull($rebuilt);
            $this->assertSame($manifest->hash, $rebuilt->hash);
            $this->assertSame($manifest->statsHash, $rebuilt->statsHash);
            $this->assertSame($manifest->toJson(), $rebuilt->toJson());
            $this->assertSame($manifest->header, $rebuilt->header);
        }
    }

    public function testFromJsonRefusesAStoredHashThatDoesNotMatch(): void
    {
        $data = json_decode(FixtureManifest::manifest()->toJson(), true);
        $data['stats_hash'] = str_repeat('0', 64);

        $this->assertNull(Manifest::fromJson((string) json_encode($data)));
    }

    public function testFromJsonRefusesJunk(): void
    {
        $this->assertNull(Manifest::fromJson('not json'));
        $this->assertNull(Manifest::fromJson('[]'));
        $this->assertNull(Manifest::fromJson('{"body":{"schema_version":99,"sections":[]},"manifest_hash":"a","stats_hash":"b"}'));
    }

    private function cache(): ManifestCache
    {
        return new ManifestCache(
            fn (): string => $this->key(),
            fn (): mixed => $this->stored,
            function (array $entry): void {
                $this->stored = $entry;
            },
            fn (): int => $this->now,
            fn (): int => $this->ttl
        );
    }

    private function key(): string
    {
        return ManifestCache::key($this->site['fingerprint'], $this->site['generation'], $this->site['theme_time'], $this->site['version']);
    }

    private function scan(): Manifest
    {
        $this->scans++;

        return FixtureManifest::manifest();
    }
}
