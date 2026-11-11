<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Admin;

use Voyager\Wayfinder\Scanners\Builders;

/**
 * Whether the current user can open an edit path, so the Wayfinder page
 * links only the screens its reader can use. An editor sees the Business
 * info and Site Editor paths as text instead of links that end in "Sorry,
 * you are not allowed to access this page."
 *
 * The check is the capability core asks for before it shows the screen. A
 * screen this class does not know keeps its link: the page would rather
 * offer a link that turns out to be closed than hide one that works.
 */
final class EditAccess
{
    /** Screens whose capability does not depend on the path's arguments. */
    private const SCREENS = [
        'site-editor.php'         => 'edit_theme_options',
        'themes.php'              => 'edit_theme_options',
        'nav-menus.php'           => 'edit_theme_options',
        'widgets.php'             => 'edit_theme_options',
        'customize.php'           => 'customize',
        'plugins.php'             => 'activate_plugins',
        'update-core.php'         => 'update_core',
        'upload.php'              => 'upload_files',
        'users.php'               => 'list_users',
        'options.php'             => 'manage_options',
        'options-general.php'     => 'manage_options',
        'options-writing.php'     => 'manage_options',
        'options-reading.php'     => 'manage_options',
        'options-discussion.php'  => 'manage_options',
        'options-media.php'       => 'manage_options',
        'options-permalink.php'   => 'manage_options',
        'options-privacy.php'     => 'manage_privacy_options',
    ];

    /**
     * The capability a screen needs when its arguments do not matter, or
     * null when they do or the screen is unknown.
     */
    public static function screenCapability(string $script): ?string
    {
        return self::SCREENS[$script] ?? null;
    }

    public static function canOpen(string $edit): bool
    {
        [$capability, $post] = self::capability($edit);

        return $post !== null ? current_user_can($capability, $post) : current_user_can($capability);
    }

    /**
     * The capability a role needs to open an edit path. Unlike canOpen()
     * there is no user, so a post's screen asks for its post type's
     * `edit_others_posts`: the client edits content someone else wrote.
     *
     * @param (callable(int): (string|null))|null $postTypeOf A post's type by id. Defaults to get_post_type().
     */
    public static function roleCapability(string $edit, ?callable $postTypeOf = null): string
    {
        [$capability, $post] = self::capability($edit);

        if ($post === null) {
            return $capability;
        }

        $postType = $postTypeOf !== null
            ? $postTypeOf($post)
            : (function_exists('get_post_type') ? get_post_type($post) : null);
        $object = is_string($postType) && $postType !== '' ? get_post_type_object($postType) : null;
        $others = $object !== null ? ($object->cap->edit_others_posts ?? null) : null;

        return is_string($others) ? $others : 'edit_others_posts';
    }

    /**
     * The capability core checks before it opens the path, and the post id
     * when that capability is the `edit_post` meta capability.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function capability(string $edit): array
    {
        $script = (string) strtok($edit, '?');
        $query = [];
        parse_str((string) wp_parse_url($edit, PHP_URL_QUERY), $query);

        // A leading slash is a page builder's front-end editor (`/about/?bricks=run`, see Builders).
        // It edits a post: that post when the URL names it (`?breakdance=builder&id=12`), else any post.
        if (Builders::isSitePath($edit)) {
            $id = is_string($query['id'] ?? null) ? (int) $query['id'] : 0;

            return $id > 0 ? ['edit_post', $id] : ['edit_posts', null];
        }

        $page = is_string($query['page'] ?? null) ? $query['page'] : '';

        // A plugin's settings page carries the capability it was registered with.
        if ($page !== '') {
            $capability = self::menuCapability($page);

            if ($capability !== null) {
                return [$capability, null];
            }
        }

        $postType = is_string($query['post_type'] ?? null) ? $query['post_type'] : 'post';
        $taxonomy = is_string($query['taxonomy'] ?? null) ? $query['taxonomy'] : '';

        return match ($script) {
            'edit.php', 'post-new.php'  => [self::postTypeCapability($postType), null],
            'post.php'                  => ['edit_post', (int) ($query['post'] ?? 0)],
            'edit-tags.php', 'term.php' => [self::taxonomyCapability($taxonomy), null],
            default                     => [self::screenCapability($script) ?? 'read', null],
        };
    }

    private static function postTypeCapability(string $postType): string
    {
        $object = get_post_type_object($postType);
        $capability = $object !== null ? ($object->cap->edit_posts ?? null) : null;

        return is_string($capability) ? $capability : 'edit_posts';
    }

    private static function taxonomyCapability(string $taxonomy): string
    {
        $object = get_taxonomy($taxonomy);
        $capability = $object !== false ? ($object->cap->manage_terms ?? null) : null;

        return is_string($capability) ? $capability : 'manage_categories';
    }

    /**
     * The capability an admin page was added with, from the menus core
     * builds before it renders any admin page. Null outside wp-admin.
     */
    private static function menuCapability(string $page): ?string
    {
        global $menu, $submenu;

        foreach (is_array($submenu) ? $submenu : [] as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_array($entry) && ($entry[2] ?? null) === $page && is_string($entry[1] ?? null)) {
                    return $entry[1];
                }
            }
        }

        foreach (is_array($menu) ? $menu : [] as $entry) {
            if (is_array($entry) && ($entry[2] ?? null) === $page && is_string($entry[1] ?? null)) {
                return $entry[1];
            }
        }

        return null;
    }
}
