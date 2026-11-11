<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;

/**
 * A binding names the settings saved beside it that nothing reads, so "which
 * value is set up but shown nowhere" is answered from the manual. In run 4
 * five agents hit the turn cap crawling for it and two named a value the
 * site does not have.
 */
final class UnusedBindingKeysTest extends TestCase
{
    private const UNREAD = 'the same settings also hold vwf_hours (Hours), which no block or theme file reads, so it shows nowhere';

    public function testEachBindingOfTheSourceNamesTheSettingNothingReads(): void
    {
        $section = self::scan(FakeLayoutSource::fixture());

        foreach (['address', 'phone'] as $key) {
            $binding = $section->item('bind:vwf-fixture/business-info.' . $key);
            $this->assertNotNull($binding, $key);
            $this->assertSame([['label' => 'Hours', 'option' => 'vwf_hours']], $binding->facts['unread_settings'] ?? null, $key);
            $this->assertStringContainsString(self::UNREAD, Facts::describe($binding), $key);
        }
    }

    public function testTheBriefNamesThemOnceOnTheSourcesFirstBindingLine(): void
    {
        $brief = (new BriefRenderer())->render(new Manifest([self::scan(FakeLayoutSource::fixture())]));

        $this->assertSame(1, substr_count($brief, self::UNREAD));
        $this->assertMatchesRegularExpression('/^edit: bind:vwf-fixture\/business-info\.address \| [^\n]*' . preg_quote(self::UNREAD, '/') . '/m', $brief);
    }

    public function testTheIndexNamesThemOnceAndItsClosingLineSaysWhere(): void
    {
        $index = (new IndexRenderer())->render(new Manifest([self::scan(FakeLayoutSource::fixture())]));

        $this->assertSame(1, substr_count($index, self::UNREAD));
        $this->assertMatchesRegularExpression('/^bind:vwf-fixture\/business-info\.address \| [^\n]*' . preg_quote(self::UNREAD, '/') . '/m', $index);
        $this->assertStringContainsString("\n" . IndexRenderer::BINDING_KEYS_LINE . "\n", $index);
        $this->assertStringContainsString("named on the source's first binding line", IndexRenderer::BINDING_KEYS_LINE);
    }

    public function testASettingAnotherBindingReadsIsNotUnread(): void
    {
        $source = FakeLayoutSource::fixture();
        $source->parts[] = FakeLayoutSource::row(
            'hours-bar',
            '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"vwf-fixture/business-info","args":{"key":"hours"}}}}} --><p></p><!-- /wp:paragraph -->',
            'Hours bar',
            'header'
        );

        $phone = self::scan($source)->item('bind:vwf-fixture/business-info.phone');

        $this->assertNotNull($phone);
        $this->assertArrayNotHasKey('unread_settings', $phone->facts, 'Every option in the group is read now.');
    }

    public function testASettingAThemeFileReadsIsNotUnread(): void
    {
        $source = FakeLayoutSource::fixture();
        $source->parts[] = ['includes' => [], 'reads' => ['option:vwf_hours'], 'links' => []] + FakeLayoutSource::row('opening-hours', '', 'opening-hours.php', 'uncategorized');

        $phone = self::scan($source)->item('bind:vwf-fixture/business-info.phone');

        $this->assertNotNull($phone);
        $this->assertArrayNotHasKey('unread_settings', $phone->facts);
    }

    public function testABindingWhoseOptionIsInNoSettingsGroupHasNoList(): void
    {
        $source = FakeLayoutSource::fixture();
        $source->settingsGroups = [];

        foreach (self::scan($source)->items as $item) {
            $this->assertArrayNotHasKey('unread_settings', $item->facts, $item->id);
        }
    }

    public function testUnreadSettingsAreSortedAndKeepANullLabel(): void
    {
        $source = FakeLayoutSource::fixture();
        $source->settingsGroups = ['vwf-business-info' => ['vwf_phone' => 'Phone', 'vwf_zip' => null, 'vwf_address' => 'Address', 'vwf_fax' => 'Fax']];

        $phone = self::scan($source)->item('bind:vwf-fixture/business-info.phone');

        $this->assertSame([['label' => 'Fax', 'option' => 'vwf_fax'], ['label' => null, 'option' => 'vwf_zip']], $phone?->facts['unread_settings']);
        $this->assertStringContainsString('the same settings also hold vwf_fax (Fax), vwf_zip, which no block or theme file reads, so they show nowhere', Facts::describe($phone));
    }

    private static function scan(FakeLayoutSource $source): Section
    {
        return (new LayoutScanner($source, new Locks('7.1.2'), new ClientAccess('editor', static fn (string $cap): bool => false, static fn (string $edit): string => 'manage_options')))->scan();
    }
}
