<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Admin;

use Throwable;
use Voyager\Wayfinder\Abilities\Abilities;
use Voyager\Wayfinder\Drift\Refresher;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\SnapshotStore;
use Voyager\Wayfinder\Notes\Note;
use Voyager\Wayfinder\Notes\NoteService;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Render\Ai\AgencyNotes;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Export\MarkdownExporter;
use Voyager\Wayfinder\Render\Export\SiteExport;
use Voyager\Wayfinder\Render\Human\ManualView;
use Voyager\Wayfinder\Render\Human\PageState;
use Voyager\Wayfinder\Rest\NotesController;

/**
 * The Wayfinder page in wp-admin: the manual for people.
 *
 * Each view scans the site, as every AI tier does, so the page never shows a
 * stale manual. When that scan differs from the stored manifest, or nothing
 * is stored yet, the view refreshes the store on the spot: the notes on the
 * page are then checked against the manual the reader sees, and a note on an
 * item that changed reads "Needs review" at once. The Watcher does the same
 * on `admin_init` when its fingerprint moves; this covers the five minutes it
 * waits between checks and anything the fingerprint does not see. Rescan
 * forces a refresh and says how many items changed.
 *
 * Reading needs `edit_pages`, the menu's capability. Rescan, Export, the
 * settings panel and note writes need `manage_options`. Notes are written by
 * the script through `wayfinder/v1/notes`, which checks the same capability.
 */
final class ManualPage
{
    public const RESCAN_ACTION = 'vwf_rescan';

    public const EXPORT_ACTION = 'vwf_export';

    /** The options.php group for the settings panel. Its nonce action is `vwf_settings-options`. */
    public const SETTINGS_GROUP = 'vwf_settings';

    public const WRITE_CAPABILITY = 'manage_options';

    /** What add_menu_page() returns for a top-level page with this slug. */
    public const HOOK_SUFFIX = 'toplevel_page_' . Plugin::MENU_SLUG;

    public const ASSET_HANDLE = 'vwf-manual';

    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSetting']);
        add_action('admin_post_' . self::RESCAN_ACTION, [$this, 'rescan']);
        add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'export']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * The MCP checkbox saves through options.php, which only accepts options
     * registered to the group it is given and checks `manage_options` for it.
     */
    public function registerSetting(): void
    {
        register_setting(self::SETTINGS_GROUP, Abilities::MCP_OPTION, [
            'type'              => 'boolean',
            'description'       => 'Expose the Wayfinder abilities to MCP clients.',
            'sanitize_callback' => static fn (mixed $value): int => wp_validate_boolean($value) ? 1 : 0,
            'default'           => false,
            'show_in_rest'      => false,
        ]);
    }

    public function render(): void
    {
        if (!current_user_can(Plugin::CAPABILITY)) {
            return;
        }

        try {
            [$manifest, $notes] = self::load();
        } catch (Throwable $e) {
            echo '<div class="wrap"><h1>' . esc_html__('Voyager Wayfinder', 'voyager-wayfinder') . '</h1>'
                . '<div class="notice notice-error"><p>' . esc_html(sprintf(
                    /* translators: %s: error message. */
                    __('Wayfinder could not scan this site: %s', 'voyager-wayfinder'),
                    $e->getMessage()
                )) . '</p></div></div>';

            return;
        }

        $canWrite = current_user_can(self::WRITE_CAPABILITY);
        $lastScan = get_option(Refresher::LAST_SCAN_OPTION, '');

        $state = new PageState(
            siteName: (string) get_bloginfo('name'),
            adminUrl: admin_url(),
            lastScan: is_string($lastScan) && $lastScan !== '' ? self::formatTime($lastScan, true) : '',
            brief: (new BriefRenderer())->render($manifest, self::tierNotes($notes)),
            canWrite: $canWrite,
            mcpEnabled: wp_validate_boolean(get_option(Abilities::MCP_OPTION, 0)),
            rescanNonce: $canWrite ? wp_create_nonce(self::RESCAN_ACTION) : '',
            settingsNonce: $canWrite ? wp_create_nonce(self::SETTINGS_GROUP . '-options') : '',
            referer: wp_make_link_relative(admin_url('admin.php?page=' . Plugin::MENU_SLUG)),
            notices: self::notices(),
            exportNonce: $canWrite ? wp_create_nonce(self::EXPORT_ACTION) : ''
        );

        $view = new ManualView(static fn (string $time): string => self::formatTime($time), EditAccess::canOpen(...), ClientAccess::forSite());

        // ManualView escapes every value it prints.
        echo $view->render($manifest, $notes, $state); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * The Rescan button posts here, through admin-post.php.
     */
    public function rescan(): void
    {
        if (!current_user_can(self::WRITE_CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to rescan this site.', 'voyager-wayfinder'), '', ['response' => 403]);
        }

        check_admin_referer(self::RESCAN_ACTION);

        try {
            $change = (new Refresher())->refresh()['change'];
            $result = (string) (count($change['added']) + count($change['removed']) + count($change['changed']));
        } catch (Throwable $e) {
            // The notice on the page says to look here.
            error_log('Wayfinder rescan failed: ' . $e->getMessage());
            $result = 'failed';
        }

        wp_safe_redirect(add_query_arg(['page' => Plugin::MENU_SLUG, 'vwf_rescanned' => $result], admin_url('admin.php')));
        exit;
    }

    /**
     * The Export button posts here, through admin-post.php, and gets the
     * file `wp wayfinder export` prints, as a download.
     */
    public function export(): void
    {
        if (!current_user_can(self::WRITE_CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to export this site manual.', 'voyager-wayfinder'), '', ['response' => 403]);
        }

        check_admin_referer(self::EXPORT_ACTION);

        $format = isset($_POST['format']) ? sanitize_key(wp_unslash($_POST['format'])) : MarkdownExporter::FORMAT;

        if (!in_array($format, SiteExport::FORMATS, true)) {
            $format = MarkdownExporter::FORMAT;
        }

        try {
            $body = SiteExport::render($format);
        } catch (Throwable $e) {
            wp_die(esc_html(sprintf(
                /* translators: %s: error message. */
                __('Wayfinder could not export this site: %s', 'voyager-wayfinder'),
                $e->getMessage()
            )), '', ['response' => 500]);
        }

        nocache_headers();
        header('Content-Type: ' . SiteExport::contentType($format));
        header('Content-Disposition: attachment; filename="' . SiteExport::filename($format) . '"');
        header('X-Content-Type-Options: nosniff');

        // A download, not this page: each exporter escapes what it prints for its own format.
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== self::HOOK_SUFFIX) {
            return;
        }

        $root = dirname(__DIR__, 2);
        $main = $root . '/voyager-wayfinder.php';

        wp_enqueue_style(self::ASSET_HANDLE, plugins_url('assets/manual.css', $main), [], self::assetVersion($root . '/assets/manual.css'));
        wp_enqueue_script(self::ASSET_HANDLE, plugins_url('assets/manual.js', $main), [], self::assetVersion($root . '/assets/manual.js'), ['in_footer' => true]);

        wp_localize_script(self::ASSET_HANDLE, 'vwfManual', [
            'notesUrl' => rest_url(NotesController::NAMESPACE . '/notes'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'strings'  => [
                'copied'        => __('Copied the AI brief.', 'voyager-wayfinder'),
                'copyManually'  => __('Could not copy. The brief is selected below, so press Ctrl+C or Cmd+C.', 'voyager-wayfinder'),
                /* translators: 1: items that match, 2: all items. */
                'matches'       => __('%1$d of %2$d items match.', 'voyager-wayfinder'),
                'saving'        => __('Saving', 'voyager-wayfinder'),
                'deleting'      => __('Deleting', 'voyager-wayfinder'),
                /* translators: %s: error message. */
                'saveFailed'    => __('The note was not saved: %s', 'voyager-wayfinder'),
                /* translators: %s: error message. */
                'deleteFailed'  => __('The note was not deleted: %s', 'voyager-wayfinder'),
            ],
        ]);
    }

    /**
     * Scans, refreshes the store when the scan differs from it, and reads
     * the notes after that, so their status matches the scan.
     *
     * @return array{0: Manifest, 1: list<Note>}
     *
     * @throws Throwable When a scanner fails.
     */
    private static function load(): array
    {
        $snapshots = new SnapshotStore();
        $manifest = Refresher::scan();

        if (($snapshots->snapshot()['hash'] ?? null) !== $manifest->hash || get_option(Refresher::LAST_SCAN_OPTION) === false) {
            (new Refresher($snapshots))->refresh($manifest);
        }

        return [$manifest, (new NoteService(null, $snapshots))->all()];
    }

    /**
     * Notes in the shape the brief prints them.
     *
     * @param list<Note> $notes
     * @return array<string, list<array{body: string, status: string}>>
     */
    private static function tierNotes(array $notes): array
    {
        return AgencyNotes::group(array_map(
            static fn (Note $note): array => ['item_id' => $note->itemId, 'body' => $note->body, 'status' => $note->status],
            $notes
        ));
    }

    /**
     * A UTC time from the database or the scan header, in the site's date
     * and time format and timezone.
     *
     * @param bool $ago Add how long ago it was.
     */
    private static function formatTime(string $utc, bool $ago = false): string
    {
        $time = strtotime(str_replace(['T', 'Z'], [' ', ''], $utc) . ' UTC');

        if ($time === false) {
            return $utc;
        }

        $format = (string) get_option('date_format', 'Y-m-d') . ' ' . (string) get_option('time_format', 'H:i');
        $text = (string) wp_date($format, $time);

        return $ago
            ? sprintf(
                /* translators: 1: date and time, 2: how long ago, such as "5 mins". */
                __('%1$s (%2$s ago)', 'voyager-wayfinder'),
                $text,
                human_time_diff($time)
            )
            : $text;
    }

    /**
     * Notices for the redirects that land here: options.php after a save,
     * and the Rescan handler. Display only, so there is no nonce to check.
     *
     * @return list<array{type: string, text: string}>
     */
    private static function notices(): array
    {
        $notices = [];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['settings-updated']) && sanitize_key(wp_unslash($_GET['settings-updated'])) === 'true') {
            $notices[] = ['type' => 'success', 'text' => __('Settings saved.', 'voyager-wayfinder')];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rescanned = isset($_GET['vwf_rescanned']) ? sanitize_key(wp_unslash($_GET['vwf_rescanned'])) : null;

        if ($rescanned === 'failed') {
            $notices[] = ['type' => 'error', 'text' => __('The rescan failed. The PHP error log has the reason.', 'voyager-wayfinder')];
        } elseif ($rescanned !== null && ctype_digit($rescanned)) {
            $changed = (int) $rescanned;
            $notices[] = ['type' => 'success', 'text' => $changed === 0
                ? __('Rescan finished. Nothing changed since the last scan.', 'voyager-wayfinder')
                : sprintf(
                    /* translators: %d: number of items. */
                    _n('Rescan finished. %d item changed since the last scan.', 'Rescan finished. %d items changed since the last scan.', $changed, 'voyager-wayfinder'),
                    $changed
                ),
            ];
        }

        return $notices;
    }

    private static function assetVersion(string $file): string
    {
        $modified = is_readable($file) ? filemtime($file) : false;

        return Plugin::VERSION . ($modified !== false ? '.' . $modified : '');
    }
}
