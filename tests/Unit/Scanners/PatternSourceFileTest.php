<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Render\Ai\SectionRenderer;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * A registered pattern names the theme file it is built from on its index
 * line, in the section tier and in find's instruction, as template parts do.
 * On the real-site benchmark set, 22 misses named the right pattern but no
 * file (el-06, el-07, el-08, el-11, el-12).
 */
final class PatternSourceFileTest extends TestCase
{
    private const FILE = 'themes/acme/patterns/pricing.php';

    public function testARegisteredPatternNamesItsFileOnTheIndexLine(): void
    {
        $line = Facts::describe(self::pricing());

        $this->assertSame('registered; file ' . self::FILE . '; used in part:footer', $line);
        $this->assertStringContainsString(
            'pat:acme/pricing | Pricing | registered; file ' . self::FILE,
            (new IndexRenderer())->render(self::manifest())
        );
    }

    public function testTheSectionTierCarriesTheFile(): void
    {
        $json = (string) (new SectionRenderer())->render(self::manifest(), 'pat:acme/pricing');

        $this->assertStringContainsString('"file":"' . self::FILE . '"', $json);
        $this->assertStringContainsString('"reads":[]', $json);
    }

    public function testAPatternThatReadsNothingKeepsACodeLineWithItsFile(): void
    {
        $result = self::find('Drain cleaning from $149.');

        $this->assertSame('pat:acme/pricing', $result['chain'][0]['id']);
        $this->assertNull($result['chain'][0]['edit']);
        $this->assertSame(
            'Pattern "Pricing" is registered in code, built by the theme file ' . self::FILE . ', so it has no edit screen. Change it in that file, or replace it with a synced pattern.',
            $result['chain'][0]['instruction']
        );
    }

    public function testAPatternNoThemeFileRegistersKeepsTheCodeLine(): void
    {
        $result = self::find('Drain cleaning from $149.', false);

        $this->assertSame(
            'Pattern "Pricing" is registered in code by the theme or a plugin, so it has no edit screen. Change it in code, or replace it with a synced pattern.',
            $result['chain'][0]['instruction']
        );
        $this->assertSame('registered; used in part:footer', Facts::describe(self::pricing(false)));
    }

    /**
     * The fixture site with the registered pattern `acme/pricing` in its
     * footer, built from a theme file that reads nothing (or registered by
     * no theme file).
     */
    private static function layout(bool $file = true): FakeLayoutSource
    {
        $layout = FakeRouteSource::layout();

        foreach ($layout->parts as $i => $row) {
            if ($row['slug'] === 'footer') {
                $layout->parts[$i]['content'] .= "\n" . '<!-- wp:pattern {"slug":"acme/pricing"} /-->';
            }
        }

        $layout->registered['acme/pricing'] = ['title' => 'Pricing', 'content' => FakeLayoutSource::paragraph('Drain cleaning from $149.')];

        if ($file) {
            $layout->patternFiles['acme/pricing'] = ['file' => self::FILE, 'reads' => []];
        }

        return $layout;
    }

    private static function pricing(bool $file = true): Item
    {
        $item = (new LayoutScanner(self::layout($file), new Locks('7.1.2')))->scan()->item('pat:acme/pricing');
        self::assertNotNull($item);

        return $item;
    }

    private static function manifest(): Manifest
    {
        return new Manifest([(new LayoutScanner(self::layout(), new Locks('7.1.2')))->scan()]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function find(string $text, bool $file = true): array
    {
        return (new EditLocator(FakeRouteSource::fixture(), self::layout($file)))->find('/', $text);
    }
}
