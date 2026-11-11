<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * blueprint.json, the README's "Try it in Playground" link. It installs the
 * plugin from this repository's main branch, which has no vendor/, so it
 * writes an autoloader before it activates the plugin. Its demo site must be
 * the one the WordPress.org blueprint builds.
 */
final class PlaygroundBlueprintTest extends TestCase
{
    private const AUTOLOAD = '/wordpress/wp-content/plugins/voyager-wayfinder/vendor/autoload.php';

    private string $dir = '';

    /**
     * @return array<string, mixed>
     */
    private static function read(string $file): array
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    protected function tearDown(): void
    {
        if ('' === $this->dir) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->dir);
        $this->dir = '';
    }

    public function testItInstallsFromMainWritesAnAutoloaderThenActivates(): void
    {
        $steps = self::read('blueprint.json')['steps'];

        self::assertSame([
            'step' => 'installPlugin',
            'pluginData' => [
                'resource' => 'git:directory',
                'url' => 'https://github.com/voyager-marketing/voyager-wayfinder',
                'ref' => 'main',
                'refType' => 'branch',
            ],
            'options' => ['activate' => false, 'targetFolderName' => 'voyager-wayfinder'],
        ], $steps[0]);
        self::assertSame(['step' => 'mkdir', 'path' => dirname(self::AUTOLOAD)], $steps[1]);
        self::assertSame('writeFile', $steps[2]['step']);
        self::assertSame(self::AUTOLOAD, $steps[2]['path']);
        self::assertSame(['step' => 'activatePlugin', 'pluginPath' => 'voyager-wayfinder/voyager-wayfinder.php'], $steps[3]);
    }

    public function testTheAutoloaderItWritesLoadsThePluginNamespaceFromSrc(): void
    {
        $this->dir = sys_get_temp_dir() . '/vwf-blueprint-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/vendor', 0777, true);
        mkdir($this->dir . '/src/BlueprintProbe', 0777, true);
        file_put_contents($this->dir . '/vendor/autoload.php', self::read('blueprint.json')['steps'][2]['data']);
        // A class only this folder's src/ has, so Composer's own autoloader cannot be what loads it.
        $class = 'Loaded' . bin2hex(random_bytes(4));
        file_put_contents($this->dir . "/src/BlueprintProbe/{$class}.php", "<?php\nnamespace Voyager\\Wayfinder\\BlueprintProbe;\nfinal class {$class} {}\n");
        file_put_contents($this->dir . '/src/Outside.php', "<?php\nfinal class VwfBlueprintOutside {}\n");

        $before = spl_autoload_functions();
        require $this->dir . '/vendor/autoload.php';
        $added = array_values(array_filter(spl_autoload_functions(), static fn (callable $f): bool => !in_array($f, $before, true)));

        try {
            self::assertCount(1, $added);
            self::assertTrue(class_exists("Voyager\\Wayfinder\\BlueprintProbe\\{$class}"));
            // Outside the plugin's namespace it loads nothing, so it cannot shadow another plugin's classes.
            self::assertFalse(class_exists('VwfBlueprintOutside'));
            self::assertFalse(class_exists('Voyager\\Wayfinder\\BlueprintProbe\\Missing'));
        } finally {
            foreach ($added as $f) {
                spl_autoload_unregister($f);
            }
        }
    }

    public function testItBuildsTheSameDemoSiteAsTheWordPressOrgBlueprint(): void
    {
        $github = self::read('blueprint.json');
        $org = self::read('.wordpress-org/blueprints/blueprint.json');

        self::assertSame(array_slice($org['steps'], 1), array_slice($github['steps'], 4));
        foreach (['landingPage', 'preferredVersions', 'login'] as $key) {
            self::assertSame($org[$key], $github[$key], $key);
        }
        self::assertSame($org['meta']['title'], $github['meta']['title']);
        self::assertSame($org['meta']['description'], $github['meta']['description']);
    }
}
