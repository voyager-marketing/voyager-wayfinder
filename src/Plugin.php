<?php

declare(strict_types=1);

namespace Voyager\Wayfinder;

use Voyager\Wayfinder\Abilities\Abilities;
use Voyager\Wayfinder\Abilities\McpResources;
use Voyager\Wayfinder\Admin\ManualPage;
use Voyager\Wayfinder\Cli\Command;
use Voyager\Wayfinder\Cli\ContextCommand;
use Voyager\Wayfinder\Cli\ExportCommand;
use Voyager\Wayfinder\Cli\NotesCommand;
use Voyager\Wayfinder\Drift\Watcher;
use Voyager\Wayfinder\Inspector\Inspector;
use Voyager\Wayfinder\Notes\Schema;
use Voyager\Wayfinder\Rest\ContextController;
use Voyager\Wayfinder\Rest\FindController;
use Voyager\Wayfinder\Rest\NotesController;
use Voyager\Wayfinder\Scanners\Registrations;
use WP_CLI;

/**
 * Plugin root: owns the Wayfinder admin menu and the `wp wayfinder` command,
 * and boots each module: the notes table, the manual page's handlers, the
 * drift watcher, the notes, find and AI context REST routes, the read-only
 * abilities, the front-end inspector, and the listener that records which file registers each post
 * type and taxonomy.
 *
 * Singleton in the Orbit Module pattern, ported from voyager-orbit
 * src/Plugin.php @ abe7d26 (private constructor, `instance()`, `register()`
 * adds the hooks). Registering twice is harmless: WordPress keys a hook by
 * its callback, so the same [object, method] pair is stored once.
 */
final class Plugin
{
    /** Keep in step with the Version header in voyager-wayfinder.php. */
    public const VERSION = '0.11.0';

    public const CLI_COMMAND = 'wayfinder';

    public const MENU_SLUG = 'voyager-wayfinder';

    public const CAPABILITY = 'edit_pages';

    public const MENU_ICON = 'dashicons-book-alt';

    private static ?self $instance = null;

    private ManualPage $manualPage;

    private Watcher $watcher;

    private NotesController $notesController;

    private ContextController $contextController;

    private FindController $findController;

    private Abilities $abilities;

    private McpResources $mcpResources;

    private Inspector $inspector;

    private function __construct()
    {
        $this->manualPage = new ManualPage();
        $this->watcher = new Watcher();
        $this->notesController = new NotesController();
        $this->contextController = new ContextController();
        $this->findController = new FindController();
        $this->abilities = new Abilities();
        $this->mcpResources = new McpResources();
        $this->inspector = new Inspector();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('init', [Schema::class, 'maybeInstall']);

        $this->manualPage->register();
        $this->watcher->register();
        $this->notesController->register();
        $this->contextController->register();
        $this->findController->register();
        $this->abilities->register();
        $this->mcpResources->register();
        $this->inspector->register();

        // While the plugin file loads, before `init`, so the content model can say which file registered each type.
        Registrations::listen();

        if (defined('WP_CLI') && WP_CLI) {
            $context = new ContextCommand();

            WP_CLI::add_command(self::CLI_COMMAND, Command::class);
            WP_CLI::add_command(self::CLI_COMMAND . ' notes', NotesCommand::class);
            WP_CLI::add_command(self::CLI_COMMAND . ' context', [$context, 'context']);
            WP_CLI::add_command(self::CLI_COMMAND . ' stats', [$context, 'stats']);
            WP_CLI::add_command(self::CLI_COMMAND . ' export', [new ExportCommand(), 'export']);
        }
    }

    /**
     * Editors and up. Authors cannot edit pages, so they do not see the menu.
     */
    public function registerMenu(): void
    {
        add_menu_page(
            __('Voyager Wayfinder', 'voyager-wayfinder'),
            __('Wayfinder', 'voyager-wayfinder'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this->manualPage, 'render'],
            self::MENU_ICON
        );
    }
}
