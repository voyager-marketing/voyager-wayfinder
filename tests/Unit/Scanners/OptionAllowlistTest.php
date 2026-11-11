<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Scanners\OptionAllowlist;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class OptionAllowlistTest extends TestCase
{
    protected function setUp(): void
    {
        WpCalls::reset();
    }

    /**
     * Growing this list is a security decision. This test makes it a visible one.
     */
    public function testTheAllowlistIsExactlyTheTenReviewedOptions(): void
    {
        $this->assertSame([
            'blogname',
            'blogdescription',
            'show_on_front',
            'page_on_front',
            'page_for_posts',
            'permalink_structure',
            'timezone_string',
            'WPLANG',
            'stylesheet',
            'template',
        ], OptionAllowlist::names());

        $this->assertSame(OptionAllowlist::FILTER, WpCalls::$filters[0]['hook']);
    }

    public function testEveryOptionHasALabelAndAnEditPath(): void
    {
        foreach (OptionAllowlist::OPTIONS as $name => [$label, $edit]) {
            $this->assertNotSame('', $label, $name);
            $this->assertMatchesRegularExpression('/^[a-z-]+\.php$/', $edit, $name);
        }

        foreach (OptionAllowlist::PAGE_OPTIONS as $name) {
            $this->assertArrayHasKey($name, OptionAllowlist::OPTIONS);
        }
    }

    public function testTheFilterCanRemoveNames(): void
    {
        WpCalls::$filterCallbacks[OptionAllowlist::FILTER] = static fn (mixed $names): array => array_values(
            array_diff((array) $names, ['blogdescription', 'timezone_string'])
        );

        $names = OptionAllowlist::names();

        $this->assertNotContains('blogdescription', $names);
        $this->assertNotContains('timezone_string', $names);
        $this->assertCount(8, $names);
    }

    public function testTheFilterCannotAddNames(): void
    {
        WpCalls::$filterCallbacks[OptionAllowlist::FILTER] = static fn (mixed $names): array => [
            'admin_email',
            ...(array) $names,
            'mailserver_pass',
            ['blogname'],
            null,
        ];

        $this->assertSame(array_keys(OptionAllowlist::OPTIONS), OptionAllowlist::names());
    }

    public function testAFilterThatEmptiesTheListLeavesNothing(): void
    {
        WpCalls::$filterCallbacks[OptionAllowlist::FILTER] = static fn (mixed $names): array => [];

        $this->assertSame([], OptionAllowlist::names());
    }

    public function testAFilterReturningANonArrayFailsLoudly(): void
    {
        WpCalls::$filterCallbacks[OptionAllowlist::FILTER] = static fn (mixed $names): string => 'blogname';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('vwf_option_allowlist must return an array.');

        OptionAllowlist::names();
    }
}
