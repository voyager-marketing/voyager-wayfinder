<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support;

use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;

/**
 * A manifest shaped like the seeded fixture site: the custom post types and
 * their fields, the child theme's templates and parts, one parent template
 * and part, a registered pattern only the parent template uses, the CTA
 * banner, both menus and both business-info bindings. Built from items
 * directly, so renderer tests need no scanner.
 */
final class FixtureManifest
{
    public const BUSINESS_INFO = 'options-general.php?page=vwf-business-info';

    /** The fixture mu-plugin, which registers the custom types, the taxonomy and the bindings source. */
    public const MU_PLUGIN = 'mu-plugins/vwf-fixture-core.php';

    /**
     * @param array<string, list<Item>> $extra    Items to add, by section.
     * @param int                       $services Published services. Publishing one more on the fixture makes it 7.
     */
    public static function manifest(array $extra = [], bool $shuffle = false, int $services = 6): Manifest
    {
        $sections = [];

        foreach (array_merge_recursive(self::items($services), $extra) as $name => $items) {
            if ($shuffle) {
                // A fixed reordering, not a random one: tests must be repeatable.
                $items = array_reverse($items);
                $items = [...array_slice($items, 1), $items[0]];
            }

            $sections[] = new Section($name, $items);
        }

        return new Manifest($shuffle ? array_reverse($sections) : $sections);
    }

    /**
     * @return array<string, list<Item>>
     */
    public static function items(int $services = 6): array
    {
        return [
            'content_model' => [
                self::postType('service', 'Services', $services, true, ['duration', 'internal_sku', 'price_from'], ['service_area'], self::MU_PLUGIN),
                self::postType('team_member', 'Team members', 4, false, ['linkedin', 'photo', 'role'], [], self::MU_PLUGIN),
                self::postType('page', 'Pages', 4, true, [], [], 'core'),
                self::postType('post', 'Posts', 4, true, [], [], 'core'),
                new Item('field:service.duration', 'field', 'Duration', ['in_rest' => true, 'source' => 'registered_meta', 'type' => 'string'], 'edit.php?post_type=service', ['pt:service']),
                new Item('field:service.internal_sku', 'field', 'Internal SKU', ['in_rest' => false, 'source' => 'registered_meta', 'type' => 'string'], 'edit.php?post_type=service', ['pt:service']),
                new Item('field:service.price_from', 'field', 'Price from', ['in_rest' => true, 'source' => 'registered_meta', 'type' => 'number'], 'edit.php?post_type=service', ['pt:service']),
                new Item('field:team_member.linkedin', 'field', 'LinkedIn', ['group' => 'Team details', 'in_rest' => false, 'required' => false, 'source' => 'acf', 'type' => 'url'], 'edit.php?post_type=team_member', ['pt:team_member']),
                new Item('field:team_member.photo', 'field', 'Photo', ['group' => 'Team details', 'in_rest' => false, 'required' => false, 'source' => 'acf', 'type' => 'image'], 'edit.php?post_type=team_member', ['pt:team_member']),
                new Item('field:team_member.role', 'field', 'Role', ['group' => 'Team details', 'in_rest' => false, 'required' => true, 'source' => 'acf', 'type' => 'text'], 'edit.php?post_type=team_member', ['pt:team_member']),
                new Item('tax:service_area', 'taxonomy', 'Service areas', ['defined_in' => self::MU_PLUGIN, 'hierarchical' => false, 'object_types' => ['service'], 'public' => true, 'show_ui' => true], 'edit-tags.php?taxonomy=service_area&post_type=service', ['pt:service'], ['terms' => 5]),
            ],
            'integrations' => [
                new Item('plugin:advanced-custom-fields', 'plugin', 'Advanced Custom Fields', ['role' => 'fields', 'version' => '6.8.10'], 'plugins.php'),
            ],
            'layout' => [
                self::template('tpl:single-service', 'single-service', 'vwf-fixture', ['pat:cta-banner', 'part:footer', 'part:header']),
                self::template('tpl:page-contact', 'Contact page', 'vwf-fixture', ['part:footer', 'part:header']),
                self::template('tpl:index', 'Index', 'twentytwentyfive', ['part:footer', 'part:header', 'part:sidebar']),
                self::template('tpl:single', 'Single Posts', 'twentytwentyfive', ['part:footer', 'part:header', 'pat:twentytwentyfive/post-meta']),
                self::part('part:header', 'header', 'twentytwentyfive', ['nav:primary'], ['tpl:index', 'tpl:page-contact', 'tpl:single', 'tpl:single-service']),
                self::part('part:footer', 'footer', 'twentytwentyfive', ['bind:vwf-fixture/business-info.address', 'bind:vwf-fixture/business-info.phone', 'nav:footer'], ['tpl:index', 'tpl:page-contact', 'tpl:single', 'tpl:single-service']),
                new Item('part:sidebar', 'template_part', 'Sidebar', ['area' => 'uncategorized', 'file' => 'themes/twentytwentyfive/parts/sidebar.html', 'file_theme' => 'twentytwentyfive', 'overrides' => null, 'slug' => 'sidebar', 'source' => 'theme', 'user_modified' => false], 'site-editor.php?p=/wp_template_part/vwf-fixture//sidebar&canvas=edit', [], ['usage_count' => 1, 'used_in' => ['tpl:index']]),
                new Item('pat:cta-banner', 'pattern', 'CTA banner', ['registered' => false, 'synced' => true], 'post.php?post=90003&action=edit', [], ['usage_count' => 3, 'used_in' => ['tpl:single-service', 'page:about', 'page:home']]),
                new Item('pat:twentytwentyfive/post-meta', 'pattern', 'Post meta', ['registered' => true, 'synced' => false], null, [], ['usage_count' => 1, 'used_in' => ['tpl:single']]),
                new Item('nav:primary', 'navigation', 'Primary', [], 'site-editor.php?p=/wp_navigation/90001', [], ['item_count' => 5, 'usage_count' => 1, 'used_in' => ['part:header']]),
                new Item('nav:footer', 'navigation', 'Footer', [], 'site-editor.php?p=/wp_navigation/90002', [], ['item_count' => 2, 'usage_count' => 1, 'used_in' => ['part:footer']]),
                self::binding('address'),
                self::binding('phone'),
            ],
            'site' => [
                new Item('core:wordpress', 'core', 'WordPress', ['version' => '7.1.2'], 'update-core.php'),
                new Item('opt:blogname', 'option', 'Site title', ['value' => 'Riverbend Plumbing'], 'options-general.php'),
                new Item('opt:page_for_posts', 'option', 'Posts page', ['value' => 'blog'], 'options-reading.php'),
                new Item('opt:page_on_front', 'option', 'Homepage', ['value' => 'home'], 'options-reading.php'),
                new Item('opt:permalink_structure', 'option', 'Permalink structure', ['value' => '/%postname%/'], 'options-permalink.php'),
                new Item('opt:show_on_front', 'option', 'Your homepage displays', ['value' => 'page'], 'options-reading.php'),
                new Item('opt:stylesheet', 'option', 'Active theme', ['name' => 'Wayfinder Fixture', 'value' => 'vwf-fixture', 'version' => '1.0.0'], 'themes.php', ['opt:template']),
                new Item('opt:template', 'option', 'Parent theme', ['name' => 'Twenty Twenty-Five', 'value' => 'twentytwentyfive', 'version' => '1.5'], 'themes.php'),
            ],
        ];
    }

    /**
     * @param list<string> $fields
     * @param list<string> $taxonomies
     */
    public static function postType(string $slug, string $label, int $published, bool $public, array $fields = [], array $taxonomies = [], ?string $definedIn = null): Item
    {
        $refs = array_map(static fn (string $tax): string => 'tax:' . $tax, $taxonomies);
        foreach ($fields as $field) {
            $refs[] = 'field:' . $slug . '.' . $field;
        }

        return new Item(
            'pt:' . $slug,
            'post_type',
            $label,
            [
                'defined_in'   => $definedIn,
                'has_archive'  => $slug === 'service',
                'public'       => $public,
                'rewrite_slug' => $slug === 'service' ? 'services' : null,
                'show_in_rest' => $public,
                'show_ui'      => true,
            ],
            $slug === 'post' ? 'edit.php' : 'edit.php?post_type=' . $slug,
            $refs,
            ['counts' => ['draft' => 0, 'private' => 0, 'publish' => $published]]
        );
    }

    /**
     * @param list<string> $refs
     */
    private static function template(string $id, string $label, string $fileTheme, array $refs): Item
    {
        $slug = substr($id, 4);

        return new Item(
            $id,
            'template',
            $label,
            ['file' => 'themes/' . $fileTheme . '/templates/' . $slug . '.html', 'file_theme' => $fileTheme, 'overrides' => null, 'slug' => $slug, 'source' => 'theme', 'user_modified' => false],
            'site-editor.php?p=/wp_template/vwf-fixture//' . $slug . '&canvas=edit',
            $refs
        );
    }

    /**
     * @param list<string> $refs
     * @param list<string> $usedIn
     */
    private static function part(string $id, string $area, string $overrides, array $refs, array $usedIn): Item
    {
        $slug = substr($id, 5);

        return new Item(
            $id,
            'template_part',
            ucfirst($slug),
            ['area' => $area, 'file' => 'themes/vwf-fixture/parts/' . $slug . '.html', 'file_theme' => 'vwf-fixture', 'overrides' => $overrides, 'slug' => $slug, 'source' => 'theme', 'user_modified' => false],
            'site-editor.php?p=/wp_template_part/vwf-fixture//' . $slug . '&canvas=edit',
            $refs,
            ['usage_count' => count($usedIn), 'used_in' => $usedIn]
        );
    }

    private static function binding(string $key): Item
    {
        return new Item(
            'bind:vwf-fixture/business-info.' . $key,
            'binding',
            'Business info: ' . $key,
            ['bound' => ['core/paragraph.content'], 'defined_in' => self::MU_PLUGIN, 'key' => $key, 'reads' => ['option:vwf_' . $key], 'source' => 'vwf-fixture/business-info', 'source_label' => 'Business info'],
            self::BUSINESS_INFO,
            [],
            ['usage_count' => 1, 'used_in' => ['part:footer']]
        );
    }
}
