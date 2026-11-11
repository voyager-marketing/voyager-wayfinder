<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Render\Ai\AgencyNotes;
use Voyager\Wayfinder\Render\Ai\Compact;
use Voyager\Wayfinder\Render\Ai\Context;
use Voyager\Wayfinder\Rest\ContextController;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;

final class ContextTest extends TestCase
{
    public function testItScansOnceAndRendersEachTier(): void
    {
        $scans = 0;
        $context = new Context(static function () use (&$scans) {
            $scans++;

            return FixtureManifest::manifest();
        }, static fn (): array => []);

        $this->assertStringStartsWith('# wayfinder v1 brief ', (string) $context->render('brief'));
        $this->assertStringStartsWith('# wayfinder v1 index ', (string) $context->render('index'));
        $this->assertStringStartsWith('{"edit":', (string) $context->render('section', 'pt:service'));
        $this->assertSame(1, $scans);
    }

    public function testTheSectionTierNeedsAnIdAndUnknownTiersAreRefused(): void
    {
        $context = new Context(static fn () => FixtureManifest::manifest(), static fn (): array => []);

        try {
            $context->render('section');
            $this->fail('The section tier rendered without an id.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs an id', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $context->render('everything');
    }

    public function testTheEtagIsTheManifestHashAndANoteChangesIt(): void
    {
        $manifest = FixtureManifest::manifest();
        $bare = new Context(static fn () => $manifest, static fn (): array => []);
        $noted = new Context(static fn () => $manifest, static fn (): array => [
            'pt:service' => [['body' => 'Prices show on the cards', 'status' => 'ok']],
        ]);

        $this->assertSame($manifest->hash, $bare->etag());
        $this->assertStringStartsWith($manifest->hash . '-', $noted->etag());
        $this->assertNotSame($bare->etag(), $noted->etag());
    }

    public function testStatsReportCharsAndAnEstimateForTheBriefAndTheIndex(): void
    {
        $context = new Context(static fn () => FixtureManifest::manifest(), static fn (): array => []);
        $stats = $context->stats();

        $this->assertSame(['brief', 'index'], array_column($stats, 'tier'));
        $this->assertSame(Compact::length((string) $context->render('brief')), $stats[0]['chars']);
        $this->assertSame(intdiv($stats[1]['chars'] + 3, 4), $stats[1]['est_tokens']);
    }

    public function testIfNoneMatchAcceptsTheEtagWeakFormsListsAndAStar(): void
    {
        $this->assertTrue(ContextController::matches('"abc"', '"abc"'));
        $this->assertTrue(ContextController::matches('W/"abc"', '"abc"'));
        $this->assertTrue(ContextController::matches('"x", "abc"', '"abc"'));
        $this->assertTrue(ContextController::matches('*', '"abc"'));
        $this->assertFalse(ContextController::matches('"abd"', '"abc"'));
        $this->assertFalse(ContextController::matches(null, '"abc"'));
        $this->assertFalse(ContextController::matches('', '"abc"'));
    }

    public function testNotesGroupByItemInAStableOrderAndSkipBadRows(): void
    {
        $this->assertSame(
            [
                'part:footer' => [['body' => 'Phone comes from settings', 'status' => 'ok']],
                'pt:service'  => [
                    ['body' => 'A note', 'status' => 'needs_review'],
                    ['body' => 'B note', 'status' => 'ok'],
                ],
            ],
            AgencyNotes::group([
                ['item_id' => 'pt:service', 'body' => 'B note', 'status' => 'ok'],
                ['item_id' => 'part:footer', 'body' => 'Phone comes from settings', 'status' => 'ok'],
                ['item_id' => 'pt:service', 'body' => 'A note', 'status' => 'needs_review'],
                ['item_id' => null, 'body' => 'no item'],
                'not a row',
            ])
        );
    }
}
