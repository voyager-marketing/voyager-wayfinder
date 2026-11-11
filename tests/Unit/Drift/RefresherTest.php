<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Drift;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Drift\Refresher;

final class RefresherTest extends TestCase
{
    public function testTheSamePluginVersionIsNotAnUpgrade(): void
    {
        $this->assertFalse(Refresher::isUpgrade(['plugin_version' => '0.2.0'], '0.2.0'));
    }

    public function testADifferentPluginVersionIsAnUpgrade(): void
    {
        $this->assertTrue(Refresher::isUpgrade(['plugin_version' => '0.1.0'], '0.2.0'));
    }

    public function testASnapshotFromBeforeVersionsWereStoredCountsAsAnUpgrade(): void
    {
        $this->assertTrue(Refresher::isUpgrade(['plugin_version' => null], '0.2.0'));
    }

    public function testNoStoredSnapshotIsNotAnUpgrade(): void
    {
        $this->assertFalse(Refresher::isUpgrade(null, '0.2.0'));
    }
}
