<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Export;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Notes\NoteStatus;
use Voyager\Wayfinder\Render\Ai\Context;
use Voyager\Wayfinder\Render\Export\SiteExport;
use Voyager\Wayfinder\Tests\Support\HandoffFixture;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class SiteExportTest extends TestCase
{
    /** @var list<string> What the context did, in order. */
    private array $log = [];

    private ?Manifest $stored = null;

    protected function setUp(): void
    {
        WpCalls::reset();
        WpCalls::$options['blogname'] = 'Riverbend Plumbing';
        $this->log = [];
        $this->stored = null;
    }

    public function testByDefaultTheScanIsStoredBeforeTheNotesAreRead(): void
    {
        $manifest = HandoffFixture::manifest();

        $markdown = SiteExport::render('md', $this->context($manifest));

        $this->assertSame(['scan', 'store', 'notes'], $this->log, 'notes are read after the store, so their status matches the scan');
        $this->assertSame($manifest, $this->stored);
        $this->assertStringStartsWith("# Riverbend Plumbing site manual\n", $markdown);
        $this->assertStringContainsString("  - Note (needs review): Prices show on the service cards.\n", $markdown);
        $this->assertStringContainsString('Edit: <https://example.test/wp-admin/options-general.php?page=vwf-business-info>', $markdown);
    }

    public function testNoRefreshScansButStoresNothing(): void
    {
        $manifest = HandoffFixture::manifest();

        $markdown = SiteExport::render('md', $this->context($manifest, false));

        $this->assertSame(['scan', 'notes'], $this->log);
        $this->assertNull($this->stored);
        $this->assertStringContainsString("  - Note (needs review): Prices show on the service cards.\n", $markdown, 'notes print with the status last stored');
    }

    /**
     * SiteExport::context() with every WordPress call replaced by one that
     * logs. $refresh is passed only when given, so the first test runs the
     * default.
     */
    private function context(Manifest $manifest, ?bool $refresh = null): Context
    {
        $scan = function () use ($manifest): Manifest {
            $this->log[] = 'scan';

            return $manifest;
        };
        $store = function (Manifest $scanned): void {
            $this->log[] = 'store';
            $this->stored = $scanned;
        };
        $notes = function (): array {
            $this->log[] = 'notes';

            return ['pt:service' => [['body' => 'Prices show on the service cards.', 'status' => NoteStatus::NEEDS_REVIEW]]];
        };

        return $refresh === null
            ? SiteExport::context(scan: $scan, store: $store, notes: $notes)
            : SiteExport::context($refresh, $scan, $store, $notes);
    }
}
