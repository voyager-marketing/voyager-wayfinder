<?php

/**
 * Runs when the plugin is deleted from Plugins, never on deactivation.
 * Removes the notes table and every option and transient Wayfinder stored,
 * on each site of a network. See Voyager\Wayfinder\Uninstaller.
 *
 * @package Voyager\Wayfinder
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// A checkout without `composer install` has no autoloader and nothing to load the list from.
if (!is_readable(__DIR__ . '/vendor/autoload.php')) {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

Voyager\Wayfinder\Uninstaller::run();
