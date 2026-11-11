<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Scanners\IntegrationsScanner;
use Voyager\Wayfinder\Tests\Support\FixedSite;

final class IntegrationsScannerTest extends TestCase
{
    public function testAcfIsAFieldsPlugin(): void
    {
        $section = $this->scan(FixedSite::ACTIVE_PLUGINS);

        $this->assertSame('integrations', $section->name);
        $this->assertSame(['plugin:advanced-custom-fields', 'plugin:voyager-wayfinder'], $this->ids($section->items));

        $acf = $section->item('plugin:advanced-custom-fields');
        $this->assertNotNull($acf);
        $this->assertSame('plugin', $acf->kind);
        $this->assertSame('Advanced Custom Fields', $acf->label);
        $this->assertSame('plugins.php', $acf->edit);

        // Name, version and role. No other header (author, description, URIs) reaches the manifest.
        $this->assertSame(['role' => 'fields', 'version' => '6.6.1'], $acf->facts);
    }

    public function testAPluginTheRoleListDoesNotNameIsOther(): void
    {
        $this->assertSame('other', $this->scan(FixedSite::ACTIVE_PLUGINS)->item('plugin:voyager-wayfinder')?->facts['role']);
    }

    public function testASingleFilePluginIsNamedByItsFile(): void
    {
        $section = $this->scan(['hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7.2']]);

        $this->assertSame(['plugin:hello'], $this->ids($section->items));
    }

    public function testTwoActivePluginFilesInOneFolderAreNamedByFile(): void
    {
        $section = $this->scan([
            'suite/one.php' => ['Name' => 'Suite one'],
            'suite/two.php' => ['Name' => 'Suite two'],
            'wordfence/wordfence.php' => ['Name' => 'Wordfence Security'],
        ]);

        $this->assertSame(['plugin:suite/one', 'plugin:suite/two', 'plugin:wordfence'], $this->ids($section->items));
        $this->assertSame('security', $section->item('plugin:wordfence')?->facts['role']);
    }

    public function testMissingHeadersFallBackToTheSlug(): void
    {
        $plugin = $this->scan(['bare/bare.php' => []])->item('plugin:bare');

        $this->assertNotNull($plugin);
        $this->assertSame('bare', $plugin->label);
        $this->assertSame(['role' => 'other', 'version' => null], $plugin->facts);
    }

    public function testAFolderNameThatIsNotAValidIdIsMadeOne(): void
    {
        $this->assertSame(['plugin:My-Plugin'], $this->ids($this->scan(['My Plugin/main.php' => ['Name' => 'Mine']])->items));
        $this->assertSame('advanced-custom-fields', IntegrationsScanner::slug('advanced-custom-fields/acf.php'));
    }

    public function testPluginOrderDoesNotChangeTheHash(): void
    {
        $this->assertSame(
            $this->scan(FixedSite::ACTIVE_PLUGINS)->hash,
            $this->scan(array_reverse(FixedSite::ACTIVE_PLUGINS, true))->hash
        );
    }

    public function testNoActivePluginsGiveAnEmptySection(): void
    {
        $this->assertSame([], $this->scan([])->items);
    }

    /**
     * @param array<string, array<string, mixed>> $plugins
     */
    private function scan(array $plugins): Section
    {
        return (new IntegrationsScanner(static fn (): array => $plugins))->scan();
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
