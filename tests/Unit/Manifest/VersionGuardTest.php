<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Scanners\CodeScanner;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\PhpSinks;
use Voyager\Wayfinder\Scanners\RouteScanner;
use Voyager\Wayfinder\Scanners\ScannerRegistry;
use Voyager\Wayfinder\Tests\Support\FakeCodeSource;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;
use Voyager\Wayfinder\Tests\Support\FixedSite;
use Voyager\Wayfinder\Tests\Support\WpCalls;

/**
 * The version bump rule, enforced: a release that changes what the scanners
 * emit for the same site must carry a new Plugin::VERSION.
 *
 * Refresher treats a snapshot stored under another version as an upgrade and
 * moves every note still pinned to its item's stored hash onto the new hash,
 * so new facts do not flag notes. Without a bump, every note on an item that
 * gained a fact reads needs_review after the next rescan.
 *
 * tests/fixtures/manifest-versions.json maps each version to the hash of the
 * manifest scanned from the unit fixture: every section ScannerRegistry
 * registers, built from FakeRouteSource and FixedSite. When this test fails
 * because the hash moved, bump the version in voyager-wayfinder.php and
 * Plugin::VERSION and append the new pair. Never change, remove or reorder a
 * recorded pair: CI compares the parsed file with the base branch.
 */
final class VersionGuardTest extends TestCase
{
    private const VERSIONS_FILE = __DIR__ . '/../../fixtures/manifest-versions.json';

    /**
     * Registered sections the guard does not hash, each with the reason. A
     * section is listed here only when its scanner cannot run from fixed
     * inputs without WordPress. Empty: every section is hashed.
     *
     * @var array<string, string>
     */
    private const UNGUARDED = [];

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testTheHeaderVersionMatchesThePluginConstant(): void
    {
        $header = (string) file_get_contents(dirname(__DIR__, 3) . '/voyager-wayfinder.php');

        $this->assertSame(1, preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $header, $found));
        $this->assertSame(Plugin::VERSION, $found[1], 'Bump the Version header and Plugin::VERSION together.');
    }

    public function testVersionsAreRecordedOnceInAscendingOrderEndingWithTheCurrentOne(): void
    {
        $versions = array_keys(self::versions());

        $sorted = $versions;
        usort($sorted, 'version_compare');

        $this->assertSame($sorted, $versions, 'Append new versions at the end, in order.');
        $this->assertSame(Plugin::VERSION, end($versions), 'Record the current version as the last entry.');
    }

    public function testTheFixtureScanMatchesTheHashRecordedForThisVersion(): void
    {
        $hash = self::fixtureHash();
        $recorded = self::versions()[Plugin::VERSION] ?? null;

        $this->assertSame(
            $recorded,
            $hash,
            sprintf(
                "The scanners' output for the unit fixture changed under version %s. Bump the version in voyager-wayfinder.php and Plugin::VERSION, then append \"<new version>\": \"%s\" to tests/fixtures/manifest-versions.json.",
                Plugin::VERSION,
                $hash
            )
        );
    }

    public function testEveryRegisteredSectionIsHashedOrListedWithAReason(): void
    {
        $registered = (new ScannerRegistry(ScannerRegistry::defaults()))->sections();
        $hashed = array_map(static fn (Section $section): string => $section->name, self::fixtureManifest()->sections);

        foreach ($registered as $section) {
            $this->assertTrue(
                in_array($section, $hashed, true) || isset(self::UNGUARDED[$section]),
                sprintf('The "%s" section is registered but the version guard does not hash it. Build its scanner from fixed inputs in fixtureManifest(), or list it in UNGUARDED with the reason.', $section)
            );
        }

        foreach (array_keys(self::UNGUARDED) as $section) {
            $this->assertContains($section, $registered, sprintf('UNGUARDED lists "%s", which no scanner registers.', $section));
            $this->assertNotContains($section, $hashed, sprintf('UNGUARDED lists "%s", which the guard hashes.', $section));
        }

        $this->assertSame([], array_diff($hashed, $registered), 'The guard hashes a section no scanner registers.');
    }

    private static function fixtureHash(): string
    {
        return self::fixtureManifest()->hash;
    }

    /**
     * Every section scanned from the unit fixture. Layout runs on WordPress
     * 7.1.2 so the version-gated lock defaults are covered; site carries the
     * theme gates from FixedSite's settings layers. The fixture site has no
     * registered pattern, so one is added: a theme file that prints an
     * allowlisted option and one nobody traced, placed by a page, so a
     * pattern's `file`, `reads` and `read_screens` are hashed too.
     * The code section comes from FakeCodeSource's fixture: theme REST routes
     * with traced effects, a plugin route and head filters.
     */
    private static function fixtureManifest(): Manifest
    {
        $layout = FakeRouteSource::layout();
        $layout->content[] = ['type' => 'page', 'slug' => 'guard', 'content' => '<!-- wp:pattern {"slug":"vwf-fixture/tagline"} /-->'];
        $layout->registered['vwf-fixture/tagline'] = ['title' => 'Tagline', 'content' => FakeLayoutSource::paragraph('Plumbers since 1998')];
        $layout->patternFiles['vwf-fixture/tagline'] = ['file' => 'themes/vwf-fixture/patterns/tagline.php', 'reads' => ['option:blogdescription', 'option:vwf_tagline_note']];

        return new Manifest([
            (new CodeScanner(FakeCodeSource::fixture(), PhpSinks::bundled()))->scan(),
            FixedSite::contentModelScanner()->scan(),
            FixedSite::integrationsScanner()->scan(),
            (new LayoutScanner($layout, new Locks(FixedSite::WORDPRESS_VERSION)))->scan(),
            (new RouteScanner(FakeRouteSource::fixture(), $layout))->scan(),
            FixedSite::siteScanner()->scan(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function versions(): array
    {
        $decoded = json_decode((string) file_get_contents(self::VERSIONS_FILE), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
