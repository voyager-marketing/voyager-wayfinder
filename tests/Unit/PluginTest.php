<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Abilities\Abilities;
use Voyager\Wayfinder\Admin\ManualPage;
use Voyager\Wayfinder\Notes\Schema;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Rest\ContextController;
use Voyager\Wayfinder\Rest\FindController;
use Voyager\Wayfinder\Rest\NotesController;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testInstanceReturnsTheSameObject(): void
    {
        $this->assertSame(Plugin::instance(), Plugin::instance());
    }

    public function testRegisterHooksTheMenuOntoAdminMenu(): void
    {
        $plugin = Plugin::instance();
        $plugin->register();

        $this->assertSame('admin_menu', WpCalls::$actions[0]['hook']);
        $this->assertSame([$plugin, 'registerMenu'], WpCalls::$actions[0]['callback']);
    }

    public function testRegisterHooksEveryRestControllerOntoRestApiInit(): void
    {
        Plugin::instance()->register();

        $owners = [];
        foreach (WpCalls::$actions as $action) {
            if ($action['hook'] === 'rest_api_init' && is_array($action['callback'])) {
                $owners[] = get_class($action['callback'][0]) . '::' . $action['callback'][1];
            }
        }

        $this->assertSame([
            NotesController::class . '::registerRoutes',
            ContextController::class . '::registerRoutes',
            FindController::class . '::registerRoutes',
        ], $owners);
    }

    public function testRegisterAddsTheFindRoute(): void
    {
        Plugin::instance()->register();

        $find = array_values(array_filter(
            WpCalls::$actions,
            static fn (array $action): bool => is_array($action['callback']) && $action['callback'][0] instanceof FindController
        ));

        $this->assertCount(1, $find);
        $this->assertSame('rest_api_init', $find[0]['hook']);
        $this->assertSame('registerRoutes', $find[0]['callback'][1]);
    }

    public function testRegisterAddsTheAbilities(): void
    {
        Plugin::instance()->register();

        $abilities = array_values(array_filter(
            WpCalls::$actions,
            static fn (array $action): bool => is_array($action['callback']) && $action['callback'][0] instanceof Abilities
        ));

        $this->assertSame(
            [['wp_abilities_api_categories_init', 'registerCategory'], ['wp_abilities_api_init', 'registerAbilities']],
            array_map(static fn (array $action): array => [$action['hook'], $action['callback'][1]], $abilities)
        );
    }

    public function testRegisterInstallsTheNotesTableAndWatchesForDrift(): void
    {
        Plugin::instance()->register();

        $hooks = array_column(WpCalls::$actions, 'hook');
        sort($hooks);

        $this->assertSame([
            'acf/update_field_group',
            'activated_plugin',
            'admin_bar_menu',
            'admin_enqueue_scripts',
            'admin_init',
            'admin_init',
            'admin_menu',
            'admin_post_vwf_export',
            'admin_post_vwf_rescan',
            'deactivated_plugin',
            'init',
            'registered_post_type',
            'registered_taxonomy',
            'rest_api_init',
            'rest_api_init',
            'rest_api_init',
            'save_post',
            'switch_theme',
            'template_redirect',
            'wp_abilities_api_categories_init',
            'wp_abilities_api_init',
            'wp_abilities_api_init',
        ], $hooks);

        $init = WpCalls::$actions[array_search('init', array_column(WpCalls::$actions, 'hook'), true)];
        $this->assertSame([Schema::class, 'maybeInstall'], $init['callback']);
    }

    public function testMenuIsGatedOnEditPagesSoEditorsSeeItAndAuthorsDoNot(): void
    {
        Plugin::instance()->registerMenu();

        $this->assertCount(1, WpCalls::$menuPages);

        $page = WpCalls::$menuPages[0];

        $this->assertSame('Wayfinder', $page['menu_title']);
        $this->assertSame('edit_pages', $page['capability']);
        $this->assertSame('voyager-wayfinder', $page['slug']);
        $this->assertSame('dashicons-book-alt', $page['icon']);
        $this->assertIsArray($page['callback']);
        $this->assertInstanceOf(ManualPage::class, $page['callback'][0]);
        $this->assertSame('render', $page['callback'][1]);
    }

    public function testRegisterAddsTheManualPageHandlers(): void
    {
        Plugin::instance()->register();

        $page = array_values(array_filter(
            WpCalls::$actions,
            static fn (array $action): bool => is_array($action['callback']) && $action['callback'][0] instanceof ManualPage
        ));

        $this->assertSame(
            [['admin_init', 'registerSetting'], ['admin_post_vwf_rescan', 'rescan'], ['admin_post_vwf_export', 'export'], ['admin_enqueue_scripts', 'enqueueAssets']],
            array_map(static fn (array $action): array => [$action['hook'], $action['callback'][1]], $page)
        );
    }

    public function testManualPageReadsNothingForAUserWhoCannotEditPages(): void
    {
        WpCalls::$capabilities = [];

        ob_start();
        (new ManualPage())->render();

        $this->assertSame('', ob_get_clean());
    }

    public function testManualPageAssetsLoadOnlyOnItsOwnScreen(): void
    {
        (new ManualPage())->enqueueAssets('index.php');

        $this->assertSame('toplevel_page_voyager-wayfinder', ManualPage::HOOK_SUFFIX);
        $this->assertSame([], WpCalls::$actions, 'another screen enqueues nothing and fails on no missing WordPress function');
    }
}
