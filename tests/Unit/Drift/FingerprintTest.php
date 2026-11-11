<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Drift;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Drift\Fingerprint;

final class FingerprintTest extends TestCase
{
    public function testRegistrationOrderDoesNotChangeTheHash(): void
    {
        $first = new Fingerprint(fn (): array => $this->parts());
        $second = new Fingerprint(fn (): array => [
            'stylesheet'     => 'vwf-fixture',
            'post_types'     => ['service', 'page', 'post'],
            'meta_keys'      => ['service.duration', 'service.price_from'],
            'active_plugins' => ['voyager-wayfinder/voyager-wayfinder.php', 'advanced-custom-fields/acf.php'],
            'layout_posts'   => ['modified' => '2026-10-01 12:00:00', 'count' => 3],
        ]);

        $this->assertSame($first->hash(), $second->hash());
    }

    public function testANewMetaKeyChangesTheHash(): void
    {
        $before = new Fingerprint(fn (): array => $this->parts());
        $after = new Fingerprint(fn (): array => ['meta_keys' => ['service.duration', 'service.price_from', 'service.warranty_years']] + $this->parts());

        $this->assertNotSame($before->hash(), $after->hash());
    }

    public function testAnEditedTemplateChangesTheHash(): void
    {
        $before = new Fingerprint(fn (): array => $this->parts());
        $after = new Fingerprint(fn (): array => ['layout_posts' => ['count' => 3, 'modified' => '2026-10-01 12:05:00']] + $this->parts());

        $this->assertNotSame($before->hash(), $after->hash());
    }

    /**
     * @return array<string, mixed>
     */
    private function parts(): array
    {
        return [
            'active_plugins' => ['advanced-custom-fields/acf.php', 'voyager-wayfinder/voyager-wayfinder.php'],
            'layout_posts'   => ['count' => 3, 'modified' => '2026-10-01 12:00:00'],
            'meta_keys'      => ['service.duration', 'service.price_from'],
            'post_types'     => ['page', 'post', 'service'],
            'stylesheet'     => 'vwf-fixture',
        ];
    }
}
