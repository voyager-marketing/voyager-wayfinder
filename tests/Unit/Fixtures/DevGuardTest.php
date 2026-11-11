<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decision in fixtures/mu-plugins/vwf-dev-guard.php: which plugin and
 * theme folders wp-env must not delete, because they are the checkout or a
 * fixture mounted from the host.
 */
final class DevGuardTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/fixtures/mu-plugins/vwf-dev-guard.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vwf-dev-guard-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->dir);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function markers(): array
    {
        return ['git dir or worktree file' => ['.git'], 'composer.json' => ['composer.json'], 'AGENTS.md' => ['AGENTS.md']];
    }

    #[DataProvider('markers')]
    public function testAPluginFolderHoldingADevFileIsTheCheckoutAndIsRefused(string $marker): void
    {
        touch($this->dir . '/voyager-wayfinder.php');
        touch($this->dir . '/' . $marker);

        $refusal = vwf_dev_guard_refusal('plugin', 'voyager-wayfinder', $this->dir);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString("Not deleting wp-content/plugins/voyager-wayfinder: it holds {$marker}", $refusal);
        $this->assertStringContainsString('wp plugin uninstall voyager-wayfinder --skip-delete', $refusal);
    }

    public function testABuildCopyMayBeDeleted(): void
    {
        // What the ZIP ships, and nothing else.
        foreach (['voyager-wayfinder.php', 'uninstall.php', 'readme.txt', 'LICENSE'] as $file) {
            touch($this->dir . '/' . $file);
        }
        mkdir($this->dir . '/src');
        mkdir($this->dir . '/vendor');

        $this->assertNull(vwf_dev_guard_refusal('plugin', 'voyager-wayfinder', $this->dir));
    }

    public function testTheFixtureThemesAreRefusedEvenWithoutADevFile(): void
    {
        foreach (VWF_DEV_GUARD_THEMES as $theme) {
            $refusal = vwf_dev_guard_refusal('theme', $theme, $this->dir);

            $this->assertNotNull($refusal, $theme);
            $this->assertStringContainsString("Not deleting wp-content/themes/{$theme}: it is a fixture theme", $refusal);
        }
    }

    public function testAThemeCheckoutIsRefusedAndAnyOtherThemeMayGo(): void
    {
        touch($this->dir . '/style.css');

        $this->assertNull(vwf_dev_guard_refusal('theme', 'twentytwentyfive', $this->dir));

        touch($this->dir . '/composer.json');

        $this->assertNotNull(vwf_dev_guard_refusal('theme', 'twentytwentyfive', $this->dir));
    }

    public function testTheGuardHooksBothDeletionsBeforeWordPressRemovesAnything(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/mu-plugins/vwf-dev-guard.php');

        $this->assertStringContainsString("add_action('delete_plugin', 'vwf_dev_guard_check_plugin', 0);", $code);
        $this->assertStringContainsString("add_action('delete_theme', 'vwf_dev_guard_check_theme', 0);", $code);
    }

    public function testTheMarkersAreFilesTheZipNeverShips(): void
    {
        $rules = file(dirname(__DIR__, 3) . '/.distignore', FILE_IGNORE_NEW_LINES) ?: [];

        foreach (VWF_DEV_GUARD_MARKERS as $marker) {
            $this->assertContains('/' . $marker, $rules, "{$marker} must stay out of the ZIP, or a real install would look like a checkout.");
        }
    }
}
