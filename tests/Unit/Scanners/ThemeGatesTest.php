<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\Compact;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Render\Human\ManualView;
use Voyager\Wayfinder\Render\Human\PageState;
use Voyager\Wayfinder\Scanners\SiteScanner;
use Voyager\Wayfinder\Scanners\ThemeGates;
use Voyager\Wayfinder\Tests\Support\FixedSite;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;
use Voyager\Wayfinder\Tests\Support\WpCalls;

/**
 * The `gates:theme` item from injected settings arrays: no WordPress.
 */
final class ThemeGatesTest extends TestCase
{
    /** A theme that turns everything on: what appearanceTools expands to, sizes and fonts. */
    private const ALL_ON = [
        'appearanceTools' => true,
        'border'          => ['color' => true, 'radius' => true, 'style' => true, 'width' => true],
        'layout'          => ['contentSize' => '645px', 'wideSize' => '1340px'],
        'spacing'         => ['blockGap' => true, 'margin' => true, 'padding' => true],
        'typography'      => ['fontFamilies' => ['theme' => [['slug' => 'manrope', 'name' => 'Manrope']]]],
    ];

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testAnAllOnThemeEmitsNoGatesAndNoBriefLine(): void
    {
        $item = self::gates(self::ALL_ON);

        $this->assertSame('gates:theme', $item->id);
        $this->assertSame([], $item->facts['gates']);
        $this->assertSame([], $item->facts['block_overrides']);
        $this->assertTrue($item->facts['settings']['appearanceTools']);
        $this->assertTrue($item->facts['settings']['color.custom']);
        $this->assertTrue($item->facts['settings']['typography.customFontSize']);
        $this->assertNull($item->edit);
        $this->assertSame([], Facts::gateWords($item));

        $brief = (new BriefRenderer())->render(FixtureManifest::manifest(['site' => [$item]]));
        $this->assertStringNotContainsString("\ngates:", $brief);
    }

    public function testAppearanceToolsReadsOnWhenCoreHasExpandedAndUnsetIt(): void
    {
        // What wp_get_global_settings() returns for a theme declaring appearanceTools: true.
        $expanded = self::ALL_ON;
        unset($expanded['appearanceTools']);
        $merged = array_replace_recursive(FixedSite::CORE_SETTINGS, $expanded);
        $item = ThemeGates::item($merged, $merged, $expanded);

        $this->assertFalse($merged['appearanceTools']);
        $this->assertTrue($item->facts['settings']['appearanceTools']);
        $this->assertSame([], $item->facts['gates']);

        // A theme turning off one control it expands into reads appearanceTools off, from the theme.
        $expanded['spacing']['padding'] = false;
        $merged = array_replace_recursive(FixedSite::CORE_SETTINGS, $expanded);
        $item = ThemeGates::item($merged, $merged, $expanded);
        $this->assertSame(['appearanceTools' => 'theme', 'spacing.padding' => 'theme'], $item->facts['gates']);
    }

    public function testCustomColorsAndAppearanceToolsOffAreBothGatesFromTheTheme(): void
    {
        $theme = ['appearanceTools' => false, 'color' => ['custom' => false]];
        $item = self::gates($theme);

        $this->assertSame('theme', $item->facts['gates']['appearanceTools']);
        $this->assertSame('theme', $item->facts['gates']['color.custom']);
        $this->assertFalse($item->facts['settings']['color.custom']);
        // Core turns padding off; the theme left it alone.
        $this->assertSame('default', $item->facts['gates']['spacing.padding']);
        $this->assertArrayNotHasKey('typography.customFontSize', $item->facts['gates']);
        $this->assertNull($item->edit, 'A theme gate is changed in the theme files.');

        $brief = (new BriefRenderer())->render(FixtureManifest::manifest(['site' => [$item]]));
        $this->assertStringContainsString("\ngates: border color off, border radius off, border style off, border width off, custom colors off, no content width, no wide width, block spacing off, margin off, padding off, no font families, appearanceTools off\n", $brief);
        $this->assertLessThanOrEqual(BriefRenderer::BUDGET, Compact::length($brief));
    }

    public function testAUserOverrideFlipsTheOriginToUserAndPointsAtStyles(): void
    {
        $theme = self::ALL_ON;
        $user = array_replace_recursive($theme, ['color' => ['custom' => false]]);
        $item = ThemeGates::item(array_replace_recursive(FixedSite::CORE_SETTINGS, $user), array_replace_recursive(FixedSite::CORE_SETTINGS, $theme), $theme);

        $this->assertSame(['color.custom' => 'user'], $item->facts['gates']);
        $this->assertSame(ThemeGates::STYLES_EDIT, $item->edit);
        $this->assertSame(['custom colors off', 'appearanceTools on'], Facts::gateWords($item));

        // Deleting the override returns the setting to the theme's value.
        $this->assertSame([], self::gates($theme)->facts['gates']);
    }

    public function testAPerBlockOverrideListsOnlyTheBlockThatDiffers(): void
    {
        $theme = self::ALL_ON + ['blocks' => [
            'core/button'    => ['color' => ['custom' => false]],
            'core/paragraph' => ['color' => ['custom' => true]],
            'core/heading'   => ['typography' => ['lineHeight' => true]],
        ]];
        $item = self::gates($theme);

        $this->assertSame(
            [['block' => 'core/button', 'origin' => 'theme', 'setting' => 'color.custom', 'value' => false]],
            $item->facts['block_overrides']
        );
        $this->assertSame([], $item->facts['gates']);
        $this->assertSame(['appearanceTools on', '1 block override'], Facts::gateWords($item));
        $this->assertStringContainsString('block overrides core/button: color.custom: off', Facts::describe($item));
    }

    public function testAllowedBlocksCountPerPostType(): void
    {
        $item = self::gates(self::ALL_ON, [
            'service' => ['core/paragraph', 'core/heading', 'core/paragraph'],
            'page'    => true,
            'post'    => false,
        ]);

        $this->assertSame(['page' => 'all', 'post' => 0, 'service' => 2], $item->facts['allowed_blocks']);
        $this->assertSame(['appearanceTools on', 'allowed blocks limited on post, service'], Facts::gateWords($item));
        $this->assertStringContainsString('allowed blocks post 0, service 2', Facts::describe($item));
    }

    public function testTheItemHashIsStableAcrossKeyOrder(): void
    {
        $theme = ['color' => ['custom' => false, 'defaultPalette' => false], 'appearanceTools' => true, 'blocks' => ['core/quote' => ['color' => ['custom' => true]], 'core/button' => ['color' => ['customGradient' => false]]]];
        $reversed = self::reverse($theme);

        $a = self::gates($theme, ['post' => true, 'page' => ['core/paragraph']]);
        $b = self::gates($reversed, ['page' => ['core/paragraph'], 'post' => true]);

        $this->assertSame($a->hash, $b->hash);
        $this->assertSame($a->facts, $b->facts);
    }

    public function testTheSiteScannerEmitsTheItemFromItsInjectedReaders(): void
    {
        $layers = [
            'merged' => array_replace_recursive(FixedSite::CORE_SETTINGS, ['color' => ['custom' => false]]),
            'base'   => FixedSite::CORE_SETTINGS,
            'theme'  => [],
        ];
        $scanner = new SiteScanner(
            static fn (string $name): mixed => false,
            static fn (int $id): ?string => null,
            static fn (string $slug): ?array => null,
            static fn (): string => '7.1.2',
            static fn (string $layer): ?array => $layers[$layer],
            static fn (): array => ['page' => true]
        );

        $item = $scanner->scan()->item('gates:theme');

        $this->assertNotNull($item);
        $this->assertSame('gates', $item->kind);
        $this->assertSame('Theme gates', $item->label);
        $this->assertSame('user', $item->facts['gates']['color.custom']);
        $this->assertSame(['page' => 'all'], $item->facts['allowed_blocks']);
    }

    public function testWithoutGlobalSettingsTheSiteScannerEmitsNoGatesItem(): void
    {
        $scanner = new SiteScanner(
            static fn (string $name): mixed => false,
            static fn (int $id): ?string => null,
            static fn (string $slug): ?array => null,
            static fn (): string => '7.1.2',
            static fn (string $layer): ?array => null
        );

        $this->assertNull($scanner->scan()->item('gates:theme'));
    }

    public function testTheGatesLineSitsAfterPluginsAndGivesWayBeforeEditLines(): void
    {
        $item = self::gates(['appearanceTools' => false, 'color' => ['custom' => false]]);
        $manifest = FixtureManifest::manifest(['site' => [$item]]);

        $full = (new BriefRenderer())->render($manifest);
        $lines = explode("\n", $full);
        $plugins = array_key_first(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'plugins:')));
        $this->assertNotNull($plugins);
        $this->assertStringStartsWith('gates: ', $lines[$plugins + 1]);

        // The first budget that shortens the gates line has already shortened
        // the plugins line, and still keeps every edit line.
        $edits = substr_count($full, "\nedit: ");
        $tight = $full;
        for ($budget = Compact::length($full); $budget > 0 && preg_match('/\ngates: .*\+\d+ more\n/', $tight) !== 1; $budget--) {
            $tight = (new BriefRenderer($budget))->render($manifest);
        }
        $this->assertMatchesRegularExpression('/\ngates: .*\+\d+ more\n/', $tight);
        $this->assertStringContainsString("\nplugins: +1 more\n", $tight);
        $this->assertSame($edits, substr_count($tight, "\nedit: "));
    }

    public function testTheIndexLineNamesEachGateWithItsOrigin(): void
    {
        $item = self::gates(['color' => ['custom' => false]] + self::ALL_ON);
        $index = (new IndexRenderer())->render(FixtureManifest::manifest(['site' => [$item]]));

        $this->assertStringContainsString("\ngates:theme | Theme gates | appearanceTools on; custom colors off (theme)\n", $index);
    }

    public function testTheWayfinderPageShowsAThemeGatesCard(): void
    {
        $item = self::gates(['color' => ['custom' => false]] + self::ALL_ON);
        $html = (new ManualView())->render(FixtureManifest::manifest(['site' => [$item]]), [], new PageState('Riverbend Plumbing', 'https://example.test/wp-admin/'));

        $this->assertStringContainsString('data-vwf-item="gates:theme"', $html);
        $this->assertStringContainsString('<h3 class="vwf-card-title">Theme gates</h3>', $html);
        $this->assertStringContainsString('custom colors off (theme)', $html);
    }

    /**
     * The gates item for a theme declaring `$theme`, with no user layer.
     *
     * @param array<string, mixed> $theme
     * @param array<string, mixed> $allowed
     */
    private static function gates(array $theme, array $allowed = []): Item
    {
        $merged = array_replace_recursive(FixedSite::CORE_SETTINGS, $theme);

        return ThemeGates::item($merged, $merged, $theme, $allowed);
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function reverse(array $value): array
    {
        $reversed = array_reverse($value, true);

        foreach ($reversed as $key => $inner) {
            if (is_array($inner) && !array_is_list($inner)) {
                $reversed[$key] = self::reverse($inner);
            }
        }

        return $reversed;
    }
}
