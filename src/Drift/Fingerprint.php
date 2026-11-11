<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Drift;

use Closure;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Scanners\OptionAllowlist;

/**
 * A cheap hash of the site state the manifest is built from.
 *
 * Cheap means a handful of in-memory lookups and one query, so it can run on
 * `admin_init`. When it differs from the stored one, a full scan is due. It
 * only has to change when the manifest might, never to match it.
 */
final class Fingerprint
{
    public const OPTION = 'vwf_manifest_fingerprint';

    /** Post types whose rows are layout, not content. */
    public const LAYOUT_POST_TYPES = ['wp_block', 'wp_navigation', 'wp_template', 'wp_template_part'];

    /** @var Closure(): array<string, mixed> */
    private Closure $parts;

    /**
     * @param (callable(): array<string, mixed>)|null $parts What the hash covers, by name. Defaults to siteParts().
     */
    public function __construct(?callable $parts = null)
    {
        $this->parts = $parts !== null
            ? Closure::fromCallable($parts)
            : Closure::fromCallable([self::class, 'siteParts']);
    }

    /**
     * sha256 over the parts. A part that is a list is sorted first, so the
     * order WordPress registered things in does not matter.
     */
    public function hash(): string
    {
        $parts = ($this->parts)();

        foreach ($parts as $name => $value) {
            if (is_array($value) && array_is_list($value)) {
                sort($value, SORT_STRING);
                $parts[$name] = $value;
            }
        }

        return CanonicalJson::hash($parts);
    }

    /**
     * The live parts: registered post types, taxonomies and post meta keys,
     * active plugins, themes, WordPress version, allowlisted option values,
     * the count and last modified time of layout posts, and ACF field groups.
     *
     * @return array<string, mixed>
     */
    public static function siteParts(): array
    {
        global $wpdb;

        $postTypes = array_values(get_post_types());
        $metaKeys = [];

        foreach (array_merge([''], $postTypes) as $postType) {
            foreach (array_keys(get_registered_meta_keys('post', $postType)) as $key) {
                $metaKeys[] = ($postType === '' ? '*' : $postType) . '.' . $key;
            }
        }

        $layout = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS posts, MAX(post_modified_gmt) AS modified FROM %i WHERE post_type IN (%s, %s, %s, %s)',
                $wpdb->posts,
                self::LAYOUT_POST_TYPES[0],
                self::LAYOUT_POST_TYPES[1],
                self::LAYOUT_POST_TYPES[2],
                self::LAYOUT_POST_TYPES[3]
            ),
            ARRAY_A
        );

        $acfGroups = [];

        if (function_exists('acf_get_field_groups')) {
            foreach (acf_get_field_groups() as $group) {
                $key = $group['key'] ?? '';
                $modified = $group['modified'] ?? '';
                $acfGroups[] = (is_scalar($key) ? (string) $key : '') . '@' . (is_scalar($modified) ? (string) $modified : '');
            }
        }

        $options = [];

        foreach (OptionAllowlist::names() as $name) {
            $value = get_option($name, null);
            $options[$name] = is_scalar($value) || $value === null ? $value : maybe_serialize($value);
        }

        $activePlugins = get_option('active_plugins', []);

        return [
            'acf_groups'     => $acfGroups,
            'active_plugins' => is_array($activePlugins) ? array_values(array_map('strval', $activePlugins)) : [],
            'layout_posts'   => [
                'count'    => (int) ($layout['posts'] ?? 0),
                'modified' => (string) ($layout['modified'] ?? ''),
            ],
            'meta_keys'      => $metaKeys,
            'options'        => $options,
            'post_types'     => $postTypes,
            'stylesheet'     => get_stylesheet(),
            'taxonomies'     => array_values(get_taxonomies()),
            'template'       => get_template(),
            'wordpress'      => (string) get_bloginfo('version'),
        ];
    }
}
