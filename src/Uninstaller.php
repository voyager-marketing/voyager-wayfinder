<?php

declare(strict_types=1);

namespace Voyager\Wayfinder;

use Voyager\Wayfinder\Abilities\Abilities;
use Voyager\Wayfinder\Drift\Fingerprint;
use Voyager\Wayfinder\Drift\Refresher;
use Voyager\Wayfinder\Drift\Watcher;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Manifest\SnapshotStore;
use Voyager\Wayfinder\Notes\Schema;

/**
 * What uninstall.php removes when the plugin is deleted: the notes table and
 * every option and transient Wayfinder stores, on each site of a network.
 * Deactivating removes nothing.
 *
 * The lists name the constants the code writes with, so this is the one
 * place they are collected. UninstallerTest reads every option and transient
 * write in src/ and fails when a `vwf_` name is missing here.
 */
final class Uninstaller
{
    /** Every option Wayfinder stores. */
    public const OPTIONS = [
        Abilities::MCP_OPTION,
        ClientAccess::OPTION,
        Fingerprint::OPTION,
        Refresher::LAST_SCAN_OPTION,
        Schema::VERSION_OPTION,
        SnapshotStore::HISTORY_OPTION,
        SnapshotStore::SNAPSHOT_OPTION,
    ];

    /** Every transient Wayfinder stores. */
    public const TRANSIENTS = [
        Watcher::CHECK_TRANSIENT,
    ];

    /**
     * Cleans the current site, or every site when this is a network: WordPress
     * runs uninstall.php once, in the main site's context.
     */
    public static function run(): void
    {
        if (!is_multisite()) {
            self::cleanSite();

            return;
        }

        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            self::cleanSite();
            restore_current_blog();
        }
    }

    /**
     * Deletes the options and transients and drops the notes table of the
     * current site. `$wpdb->prefix` follows switch_to_blog(), so the table is
     * the one this site's notes live in.
     */
    public static function cleanSite(): void
    {
        global $wpdb;

        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }

        foreach (self::TRANSIENTS as $transient) {
            delete_transient($transient);
        }

        // The table is the plugin's own, so nothing but a direct query removes it.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', Schema::table()));
    }
}
