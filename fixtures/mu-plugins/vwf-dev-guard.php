<?php

/**
 * Plugin Name: Wayfinder dev guard
 * Description: Refuses to delete a checkout or a fixture theme that wp-env mounts from the host. Development only, never ships.
 * Version:     0.1.0
 * License:     GPL-2.0-or-later
 *
 * wp-env mounts the checkout as wp-content/plugins/voyager-wayfinder and the
 * fixture themes from fixtures/. WordPress and WP-CLI delete a plugin or a
 * theme by removing its folder, so `wp plugin uninstall voyager-wayfinder`,
 * `wp plugin delete`, `wp theme delete vwf-classic` or Delete in wp-admin
 * would remove the files on the host, `.git` included. Both fire
 * `delete_plugin` or `delete_theme` before they remove anything, and this
 * stops the request there. The plugin cannot guard itself: it has to be
 * deactivated before it can be deleted, so none of its code is loaded.
 *
 * WordPress has already run the uninstall procedure when `delete_plugin`
 * fires, so the database side of a refused uninstall is done. To test
 * uninstall.php, run `wp plugin uninstall voyager-wayfinder --skip-delete`.
 *
 * This file only defines functions until WordPress loads it, so the unit
 * suite can require it to test the decision.
 *
 * @package Voyager\Wayfinder
 */

declare(strict_types=1);

/** Files the release ZIP never ships. A plugin or theme folder holding one is a checkout. */
const VWF_DEV_GUARD_MARKERS = ['.git', 'composer.json', 'AGENTS.md'];

/** Themes wp-env mounts from fixtures/. */
const VWF_DEV_GUARD_THEMES = ['vwf-classic', 'vwf-fixture'];

/**
 * Why deleting this plugin or theme folder is refused, or null when it may go.
 *
 * @param string $kind `plugin` or `theme`.
 * @param string $slug The plugin's folder or the theme's stylesheet.
 * @param string $dir  The folder's absolute path.
 */
function vwf_dev_guard_refusal(string $kind, string $slug, string $dir): ?string
{
    $where = ($kind === 'theme' ? 'wp-content/themes/' : 'wp-content/plugins/') . $slug;
    $marker = null;

    foreach (VWF_DEV_GUARD_MARKERS as $candidate) {
        if (file_exists($dir . '/' . $candidate)) {
            $marker = $candidate;
            break;
        }
    }

    if ($marker === null && !($kind === 'theme' && in_array($slug, VWF_DEV_GUARD_THEMES, true))) {
        return null;
    }

    $why = $marker !== null
        ? "it holds {$marker}, so it is a checkout"
        : 'it is a fixture theme from fixtures/';

    $message = "Not deleting {$where}: {$why} that wp-env mounts from your disk, and deleting it would delete those files.";

    return $kind === 'plugin'
        ? $message . " The uninstall procedure has already run. To test uninstall.php, use `wp plugin uninstall {$slug} --skip-delete`."
        : $message;
}

/** Stops the request: an error under WP-CLI, wp_die() anywhere else. */
function vwf_dev_guard_refuse(string $message): never
{
    if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    wp_die(esc_html($message), esc_html__('Not deleted', 'voyager-wayfinder'), ['response' => 403, 'back_link' => true]);
}

/** `delete_plugin` passes the plugin's main file, `folder/file.php` or `file.php`. */
function vwf_dev_guard_check_plugin(string $pluginFile): void
{
    $folder = dirname($pluginFile) === '.' ? $pluginFile : dirname($pluginFile);
    $refusal = vwf_dev_guard_refusal('plugin', $folder, WP_PLUGIN_DIR . '/' . $folder);

    if ($refusal !== null) {
        vwf_dev_guard_refuse($refusal);
    }
}

function vwf_dev_guard_check_theme(string $stylesheet): void
{
    $refusal = vwf_dev_guard_refusal('theme', $stylesheet, get_theme_root($stylesheet) . '/' . $stylesheet);

    if ($refusal !== null) {
        vwf_dev_guard_refuse($refusal);
    }
}

if (defined('ABSPATH')) {
    add_action('delete_plugin', 'vwf_dev_guard_check_plugin', 0);
    add_action('delete_theme', 'vwf_dev_guard_check_theme', 0);
}
