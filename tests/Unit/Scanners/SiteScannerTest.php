<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Scanners\OptionAllowlist;
use Voyager\Wayfinder\Scanners\SiteScanner;
use Voyager\Wayfinder\Tests\Support\FixedSite;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class SiteScannerTest extends TestCase
{
    /** @var list<string> Option names the scanner asked for, in order. */
    private array $read = [];

    protected function setUp(): void
    {
        WpCalls::reset();
        $this->read = [];
    }

    public function testEmitsEveryAllowlistedOptionAndTheWordPressVersion(): void
    {
        $section = $this->scanner()->scan();

        $expected = array_map(static fn (string $name): string => 'opt:' . $name, array_keys(OptionAllowlist::OPTIONS));
        $expected[] = 'core:wordpress';
        sort($expected, SORT_STRING);

        $this->assertSame('site', $section->name);
        $this->assertSame($expected, $this->ids($section->items));

        $blogname = $section->item('opt:blogname');
        $this->assertNotNull($blogname);
        $this->assertSame('option', $blogname->kind);
        $this->assertSame('Site title', $blogname->label);
        $this->assertSame(['value' => 'Riverbend Plumbing'], $blogname->facts);
        $this->assertSame('options-general.php', $blogname->edit);

        $wordpress = $section->item('core:wordpress');
        $this->assertNotNull($wordpress);
        $this->assertSame(['version' => '7.1.2'], $wordpress->facts);
        $this->assertSame('update-core.php', $wordpress->edit);
    }

    public function testAnOptionOutsideTheAllowlistIsNeverReadOrEmitted(): void
    {
        // A filter may only remove names. Try to sneak three in.
        WpCalls::$filterCallbacks[OptionAllowlist::FILTER] = static fn (mixed $names): array => [
            ...(array) $names,
            'admin_email',
            'vwf_phone',
            'mailserver_pass',
        ];

        $section = $this->scanner()->scan();
        $json = CanonicalJson::encode($section->body());

        $read = $this->read;
        sort($read, SORT_STRING);
        $allowed = array_keys(OptionAllowlist::OPTIONS);
        sort($allowed, SORT_STRING);
        $this->assertSame($allowed, $read, 'Only allowlisted options are read, each once.');

        foreach ($section->items as $item) {
            if (str_starts_with($item->id, 'opt:')) {
                $this->assertArrayHasKey(substr($item->id, 4), OptionAllowlist::OPTIONS);
            }
        }

        // Positive control: an allowlisted value does reach the output.
        $this->assertStringContainsString('Riverbend Plumbing', $json);
        $this->assertStringNotContainsString('owner@example.com', $json);
        $this->assertStringNotContainsString('555-0142', $json);
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertNull($section->item('opt:admin_email'));
    }

    public function testTheFilterCanRemoveAnOption(): void
    {
        WpCalls::$filterCallbacks[OptionAllowlist::FILTER] = static fn (mixed $names): array => array_values(
            array_diff((array) $names, ['blogdescription'])
        );

        $section = $this->scanner()->scan();

        $this->assertNull($section->item('opt:blogdescription'));
        $this->assertNotContains('blogdescription', $this->read);
        $this->assertNotNull($section->item('opt:blogname'));
    }

    public function testThePageOptionsCarryPageSlugs(): void
    {
        $section = $this->scanner()->scan();

        $this->assertSame(['value' => 'home'], $section->item('opt:page_on_front')?->facts);
        $this->assertSame(['value' => 'blog'], $section->item('opt:page_for_posts')?->facts);
        $this->assertSame('options-reading.php', $section->item('opt:page_on_front')?->edit);
    }

    public function testAPageWithoutASlugFallsBackToItsRowIdAndAnUnsetOrMissingPageIsNull(): void
    {
        $section = $this->scanner(['page_on_front' => 56, 'page_for_posts' => '0'] + FixedSite::OPTIONS)->scan();

        $this->assertSame(['value' => '#56'], $section->item('opt:page_on_front')?->facts);
        $this->assertSame(['value' => null], $section->item('opt:page_for_posts')?->facts);

        $section = $this->scanner(['page_on_front' => 99] + FixedSite::OPTIONS)->scan();

        $this->assertSame(['value' => null], $section->item('opt:page_on_front')?->facts);
    }

    public function testTheActiveThemePointsAtItsParent(): void
    {
        $section = $this->scanner()->scan();

        $stylesheet = $section->item('opt:stylesheet');
        $this->assertNotNull($stylesheet);
        $this->assertSame('Active theme', $stylesheet->label);
        $this->assertSame(['value' => 'vwf-fixture', 'name' => 'Wayfinder Fixture', 'version' => '0.1.0'], $stylesheet->facts);
        $this->assertSame(['opt:template'], $stylesheet->refs);

        $template = $section->item('opt:template');
        $this->assertNotNull($template);
        $this->assertSame(['value' => 'twentytwentyfive', 'name' => 'Twenty Twenty-Five', 'version' => '1.3'], $template->facts);
        $this->assertSame([], $template->refs);
    }

    public function testAThemeWithoutAParentPointsAtNothing(): void
    {
        $section = $this->scanner(['stylesheet' => 'twentytwentyfive'] + FixedSite::OPTIONS)->scan();

        $this->assertSame([], $section->item('opt:stylesheet')?->refs);
    }

    public function testAMissingOptionIsRecordedAsNull(): void
    {
        $section = $this->scanner([])->scan();

        $this->assertSame(['value' => null], $section->item('opt:blogname')?->facts);
        $this->assertSame(['value' => null], $section->item('opt:stylesheet')?->facts);
        $this->assertSame(['value' => null], $section->item('opt:page_on_front')?->facts);
    }

    public function testAnEmptyStringIsKeptAsIs(): void
    {
        // WPLANG is '' on an en_US site. That is a value, not a missing option.
        $this->assertSame(['value' => ''], $this->scanner()->scan()->item('opt:WPLANG')?->facts);
    }

    public function testTheSameSiteGivesTheSameHash(): void
    {
        $this->assertSame($this->scanner()->scan()->hash, $this->scanner()->scan()->hash);
        $this->assertNotSame(
            $this->scanner()->scan()->hash,
            $this->scanner(['blogname' => 'Other Co'] + FixedSite::OPTIONS)->scan()->hash
        );
    }

    public function testTheFixedSiteCarriesThemeGatesFromEveryOrigin(): void
    {
        $gates = FixedSite::siteScanner()->scan()->item('gates:theme');

        $this->assertNotNull($gates);
        $this->assertSame(['color.customGradient' => 'user', 'color.defaultPalette' => 'theme'], $gates->facts['gates']);
        $this->assertSame([['block' => 'core/button', 'origin' => 'theme', 'setting' => 'color.custom', 'value' => false]], $gates->facts['block_overrides']);
        $this->assertSame(['page' => 'all', 'post' => 'all', 'service' => 3], $gates->facts['allowed_blocks']);
        $this->assertTrue($gates->facts['settings']['appearanceTools']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function scanner(array $options = FixedSite::OPTIONS): SiteScanner
    {
        return new SiteScanner(
            function (string $name) use ($options): mixed {
                $this->read[] = $name;

                return $options[$name] ?? false;
            },
            static fn (int $id): ?string => FixedSite::PAGES[$id] ?? null,
            static fn (string $slug): ?array => FixedSite::THEMES[$slug] ?? null,
            static fn (): string => FixedSite::WORDPRESS_VERSION
        );
    }

    /**
     * @param list<Item> $items
     *
     * @return list<string>
     */
    private function ids(array $items): array
    {
        return array_map(static fn (Item $item): string => $item->id, $items);
    }
}
