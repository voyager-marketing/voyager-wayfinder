<?php

/**
 * Plugin Name: Voyager Wayfinder
 * Description: Scans the site and keeps a living manual of how it is built and where each thing is edited.
 * Version: 0.11.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Voyager Marketing
 * Author URI: https://voyagermark.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: voyager-wayfinder
 *
 * @package Voyager\Wayfinder
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- the bootstrap loads the plugin and declares its public PHP API functions.

if (!defined('ABSPATH')) {
    exit;
}

// A checkout without `composer install` has no autoloader. Say so in wp-admin
// instead of failing on the first class reference.
if (!is_readable(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Wayfinder could not load. Run composer install in the plugin folder.', 'voyager-wayfinder')
            . '</p></div>';
    });

    return;
}

require_once __DIR__ . '/vendor/autoload.php';

// The public PHP API (docs/ai-context-format.md, "PHP API"). Each call reads
// the site afresh, so a check after a write sees the write. Another plugin
// tests `function_exists('vwf_api_version')` when it calls, not when it
// loads: plugins load in folder order, and voyager-orbit comes first.

/**
 * The anchor of the block at `$path` in an entity now, or null when there is
 * no block there. A `pt:<post_type>` entity needs `$post`.
 *
 * @return array{entity: string, post: int|null, path: string, fp: string, expect_hash: string|null}|null
 */
function vwf_anchor(string $entity, string $path, ?int $post = null): ?array
{
    return (new Voyager\Wayfinder\Api())->anchor($entity, $path, $post);
}

/**
 * Whether the block an anchor names is still there and unchanged: `ok`, the
 * block's anchor now in `current` (write at its path), and a `reason`.
 *
 * @param array<mixed> $anchor `{entity, post, path, fp, expect_hash}`.
 * @return array{ok: bool, current: array{entity: string, post: int|null, path: string, fp: string, expect_hash: string|null}|null, reason: 'match'|'fp_changed'|'container_restructured'|'gone'|'ambiguous'}
 * @throws InvalidArgumentException When the anchor is not shaped like one.
 */
function vwf_anchor_check(array $anchor): array
{
    return (new Voyager\Wayfinder\Api())->check($anchor);
}

/**
 * The PHP API's version. A breaking change bumps it.
 */
function vwf_api_version(): string
{
    return Voyager\Wayfinder\Api::VERSION;
}

Voyager\Wayfinder\Plugin::instance()->register();
