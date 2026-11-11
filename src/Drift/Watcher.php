<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Drift;

use Throwable;
use Voyager\Wayfinder\Scanners\Builders;
use WP_Post;

/**
 * Keeps the stored manifest current without anyone asking.
 *
 * On `admin_init`, at most once per five minutes, the fingerprint is compared
 * with the one stored at the last refresh, and a change triggers a rescan.
 * Theme switches, plugin (de)activation, layout post saves and ACF field
 * group saves mark the manifest stale instead of scanning on the spot: in the
 * request that activates a plugin or switches a theme, the new code has not
 * registered anything yet, so a scan there would store the old site. The
 * next admin request (often the redirect that follows) rescans.
 */
final class Watcher
{
    public const CHECK_TRANSIENT = 'vwf_fingerprint_checked';

    /** Five minutes, in seconds. */
    public const CHECK_INTERVAL = 300;

    private ?Refresher $refresher;

    public function __construct(?Refresher $refresher = null)
    {
        $this->refresher = $refresher;
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'check']);
        add_action('switch_theme', [$this, 'markStale']);
        add_action('activated_plugin', [$this, 'markStale']);
        add_action('deactivated_plugin', [$this, 'markStale']);
        add_action('save_post', [$this, 'onSavePost'], 10, 2);
        add_action('acf/update_field_group', [$this, 'markStale']);
    }

    public function check(): void
    {
        if (get_transient(self::CHECK_TRANSIENT) !== false) {
            return;
        }

        set_transient(self::CHECK_TRANSIENT, 1, self::CHECK_INTERVAL);

        if ((new Fingerprint())->hash() === get_option(Fingerprint::OPTION)) {
            return;
        }

        try {
            ($this->refresher ??= new Refresher())->refresh();
        } catch (Throwable $e) {
            // A scan that fails must not take wp-admin down with it. The
            // fingerprint stays unstored, so the next check tries again.
            error_log('Wayfinder rescan failed: ' . $e->getMessage());
        }
    }

    /**
     * Forgets the stored fingerprint, so the next check rescans whatever the
     * fingerprint says.
     */
    public function markStale(): void
    {
        delete_option(Fingerprint::OPTION);
        delete_transient(self::CHECK_TRANSIENT);
    }

    public function onSavePost(int $postId, mixed $post): void
    {
        // A page builder's template is a layout item too (`btpl:`, see Builders).
        if (
            $post instanceof WP_Post
            && (in_array($post->post_type, Fingerprint::LAYOUT_POST_TYPES, true) || isset(Builders::bundled()->templateTypes()[$post->post_type]))
        ) {
            $this->markStale();
        }
    }
}
