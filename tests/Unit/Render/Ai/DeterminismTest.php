<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Render\Ai\SectionRenderer;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;

/**
 * Same site state in, same bytes out: the property prompt caching and the
 * ETag both rest on.
 */
final class DeterminismTest extends TestCase
{
    public function testTheSameManifestRendersByteIdenticalTiersTwice(): void
    {
        $manifest = FixtureManifest::manifest();

        $this->assertSame((new BriefRenderer())->render($manifest), (new BriefRenderer())->render($manifest));
        $this->assertSame((new IndexRenderer())->render($manifest), (new IndexRenderer())->render($manifest));
    }

    public function testShuffledInputGivesTheSameBytes(): void
    {
        $ordered = FixtureManifest::manifest();
        $shuffled = FixtureManifest::manifest([], true);

        $this->assertSame($ordered->hash, $shuffled->hash);
        $this->assertSame((new BriefRenderer())->render($ordered), (new BriefRenderer())->render($shuffled));
        $this->assertSame((new IndexRenderer())->render($ordered), (new IndexRenderer())->render($shuffled));
        $this->assertSame(
            (new SectionRenderer())->render($ordered, 'layout'),
            (new SectionRenderer())->render($shuffled, 'layout')
        );
    }

    public function testTheGenerationTimeNeverReachesTheTiers(): void
    {
        $sections = FixtureManifest::manifest()->sections;
        $early = new \Voyager\Wayfinder\Manifest\Manifest($sections, ['generated_at' => '2026-10-01T00:00:00Z']);
        $late = new \Voyager\Wayfinder\Manifest\Manifest($sections, ['generated_at' => '2026-10-02T12:34:56Z']);

        $this->assertSame((new BriefRenderer())->render($early), (new BriefRenderer())->render($late));
        $this->assertSame((new IndexRenderer())->render($early), (new IndexRenderer())->render($late));
    }
}
