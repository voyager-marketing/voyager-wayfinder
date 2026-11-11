<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support;

use Voyager\Wayfinder\Scanners\ContentModelScanner;
use Voyager\Wayfinder\Scanners\IntegrationsScanner;
use Voyager\Wayfinder\Scanners\SiteScanner;

/**
 * Fixed lookups for the scanners that read WordPress through closures: site,
 * content_model and integrations. They mirror the seeded block fixture
 * (fixtures/seed.php, fixtures/mu-plugins/vwf-fixture-core.php), trimmed to
 * what exercises a rule.
 *
 * VersionGuardTest hashes what these scanners emit from these values, so the
 * scanner tests and the guard read the same site. Changing a value here moves
 * the guard's hash like a scanner change does, and recorded hashes never
 * change, so it needs a version bump too.
 */
final class FixedSite
{
    public const WORDPRESS_VERSION = '7.1.2';

    /** Every option on the site, allowlisted or not. */
    public const OPTIONS = [
        'blogname'            => 'Riverbend Plumbing',
        'blogdescription'     => 'Plumbing done right',
        'show_on_front'       => 'page',
        'page_on_front'       => '12',
        'page_for_posts'      => 34,
        'permalink_structure' => '/%postname%/',
        'timezone_string'     => 'America/New_York',
        'WPLANG'              => '',
        'stylesheet'          => 'vwf-fixture',
        'template'            => 'twentytwentyfive',
        'admin_email'         => 'owner@example.com',
        'vwf_phone'           => '(540) 555-0142',
        'mailserver_pass'     => 'hunter2',
    ];

    /** Post id => post_name. */
    public const PAGES = [12 => 'home', 34 => 'blog', 56 => ''];

    /** Installed themes: Name and Version headers by slug. */
    public const THEMES = [
        'twentytwentyfive' => ['name' => 'Twenty Twenty-Five', 'version' => '1.3'],
        'vwf-fixture'      => ['name' => 'Wayfinder Fixture', 'version' => '0.1.0'],
    ];

    /** Core's defaults as merged settings carry them. */
    public const CORE_SETTINGS = [
        'appearanceTools' => false,
        'border'          => ['color' => false, 'radius' => false, 'style' => false, 'width' => false],
        'color'           => ['custom' => true, 'customGradient' => true, 'defaultPalette' => true],
        'spacing'         => ['blockGap' => null, 'margin' => false, 'padding' => false],
        'typography'      => ['customFontSize' => true],
    ];

    /**
     * What the theme's theme.json declares: appearanceTools, its own palette
     * in place of core's, sizes, a font, and custom colors off on buttons.
     */
    public const THEME_SETTINGS = [
        'appearanceTools' => true,
        'color'           => ['defaultPalette' => false],
        'layout'          => ['contentSize' => '645px', 'wideSize' => '1340px'],
        'typography'      => ['fontFamilies' => ['theme' => [['slug' => 'manrope', 'name' => 'Manrope']]]],
        'blocks'          => ['core/button' => ['color' => ['custom' => false]]],
    ];

    /** What the user's global styles change: custom gradients off. */
    public const USER_SETTINGS = ['color' => ['customGradient' => false]];

    /** Post type => what allowed_block_types_all returns for its editor. */
    public const ALLOWED_BLOCKS = [
        'page'    => true,
        'post'    => true,
        'service' => ['core/paragraph', 'core/heading', 'core/list'],
    ];

    /** Active plugins, as get_plugins() returns them. */
    public const ACTIVE_PLUGINS = [
        'advanced-custom-fields/acf.php' => [
            'Name'        => 'Advanced Custom Fields',
            'Version'     => '6.6.1',
            'Author'      => 'WP Engine',
            'Description' => 'Customize WordPress with powerful, professional and intuitive fields.',
        ],
        'voyager-wayfinder/voyager-wayfinder.php' => [
            'Name'    => 'Voyager Wayfinder',
            'Version' => '0.1.0',
        ],
    ];

    public static function siteScanner(): SiteScanner
    {
        return new SiteScanner(
            static fn (string $name): mixed => self::OPTIONS[$name] ?? false,
            static fn (int $id): ?string => self::PAGES[$id] ?? null,
            static fn (string $slug): ?array => self::THEMES[$slug] ?? null,
            static fn (): string => self::WORDPRESS_VERSION,
            static fn (string $layer): ?array => self::globalSettings()[$layer] ?? null,
            static fn (): array => self::ALLOWED_BLOCKS
        );
    }

    public static function contentModelScanner(): ContentModelScanner
    {
        return new ContentModelScanner(
            self::postTypes(...),
            self::taxonomies(...),
            self::registeredMeta(...),
            self::acfGroups(...)
        );
    }

    public static function integrationsScanner(): IntegrationsScanner
    {
        return new IntegrationsScanner(static fn (): array => self::ACTIVE_PLUGINS);
    }

    /**
     * The settings layers SiteScanner reads, as wp_get_global_settings()
     * returns them: core expands appearanceTools into the controls it turns
     * on and unsets it.
     *
     * @return array{merged: array<string, mixed>, base: array<string, mixed>, theme: array<string, mixed>}
     */
    public static function globalSettings(): array
    {
        $theme = array_replace_recursive(self::THEME_SETTINGS, [
            'border'  => ['color' => true, 'radius' => true, 'style' => true, 'width' => true],
            'spacing' => ['blockGap' => true, 'margin' => true, 'padding' => true],
        ]);
        unset($theme['appearanceTools']);
        $base = array_replace_recursive(self::CORE_SETTINGS, $theme);

        return [
            'merged' => array_replace_recursive($base, self::USER_SETTINGS),
            'base'   => $base,
            'theme'  => $theme,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function postTypes(): array
    {
        return [
            self::postType('post', ['label' => 'Posts', 'menu_name' => 'Posts', 'counts' => ['publish' => 4], 'supports' => ['title', 'editor', 'custom-fields'], 'defined_in' => 'core']),
            self::postType('page', ['label' => 'Pages', 'menu_name' => 'Pages', 'hierarchical' => true, 'counts' => ['publish' => 4], 'supports' => ['title', 'editor', 'custom-fields'], 'defined_in' => 'core']),
            self::postType('attachment', ['label' => 'Media', 'menu_name' => 'Media', 'counts' => ['inherit' => 3], 'defined_in' => 'core']),
            self::postType('revision', ['public' => false, 'show_ui' => false]),
            self::postType('wp_block', ['public' => false, 'show_ui' => true]),
            self::postType('wp_navigation', ['public' => false, 'show_ui' => true]),
            self::postType('acf-field-group', ['public' => false, 'show_ui' => true]),
            self::postType('service', [
                'label'        => 'Services',
                'menu_name'    => 'Services',
                'has_archive'  => true,
                'rewrite_slug' => 'services',
                'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
                'counts'       => ['publish' => 6, 'draft' => 1, 'auto-draft' => 2],
                'defined_in'   => 'mu-plugins/vwf-fixture-core.php',
            ]),
            self::postType('team_member', [
                'label'        => 'Team members',
                'menu_name'    => 'Team',
                'public'       => false,
                'rewrite_slug' => null,
                'supports'     => ['title', 'editor', 'thumbnail'],
                'counts'       => ['publish' => 4],
                'defined_in'   => 'mu-plugins/vwf-fixture-core.php',
            ]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function taxonomies(): array
    {
        return [
            self::taxonomy('category', ['post'], ['label' => 'Categories', 'hierarchical' => true, 'terms' => 1, 'defined_in' => 'core']),
            self::taxonomy('post_format', ['post'], ['label' => 'Formats', 'show_ui' => false, 'defined_in' => 'core']),
            self::taxonomy('nav_menu', ['nav_menu_item'], ['public' => false, 'show_ui' => false]),
            self::taxonomy('wp_pattern_category', ['wp_block'], ['public' => false, 'show_ui' => true]),
            self::taxonomy('service_area', ['service'], ['label' => 'Service areas', 'terms' => 5, 'defined_in' => 'mu-plugins/vwf-fixture-core.php']),
        ];
    }

    /**
     * @return array<string, array{type: string, single: bool, show_in_rest: bool, label: string}>
     */
    public static function registeredMeta(string $postType): array
    {
        $footnotes = ['footnotes' => ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'label' => '']];

        return match ($postType) {
            'post', 'page' => $footnotes,
            'service'      => [
                'price_from'   => ['type' => 'number', 'single' => true, 'show_in_rest' => true, 'label' => 'Price from'],
                'duration'     => ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'label' => 'Duration'],
                'internal_sku' => ['type' => 'string', 'single' => true, 'show_in_rest' => false, 'label' => 'Internal SKU'],
                '_edit_lock'   => ['type' => 'string', 'single' => true, 'show_in_rest' => false, 'label' => ''],
            ],
            default        => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function acfGroups(): array
    {
        return [
            self::acfGroup('group_vwf_team_member', [[['post_type', '==', 'team_member']]], [
                ['name' => 'role', 'label' => 'Role', 'type' => 'text'],
                ['name' => 'photo', 'label' => 'Photo', 'type' => 'image'],
                ['name' => 'linkedin', 'label' => 'LinkedIn', 'type' => 'url'],
            ], ['title' => 'Team member details']),
        ];
    }

    /**
     * A post type row as ContentModelScanner's lookup returns it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function postType(string $slug, array $overrides = []): array
    {
        return $overrides + [
            'slug'         => $slug,
            'label'        => ucfirst($slug),
            'menu_name'    => ucfirst($slug),
            'public'       => true,
            'show_ui'      => true,
            'has_archive'  => false,
            'rewrite_slug' => null,
            'supports'     => ['title', 'editor', 'custom-fields'],
            'hierarchical' => false,
            'show_in_rest' => true,
            'counts'       => [],
        ];
    }

    /**
     * A taxonomy row as ContentModelScanner's lookup returns it.
     *
     * @param list<string>         $objectTypes
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function taxonomy(string $slug, array $objectTypes, array $overrides = []): array
    {
        return $overrides + [
            'slug'         => $slug,
            'label'        => ucfirst($slug),
            'object_types' => $objectTypes,
            'public'       => true,
            'show_ui'      => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'terms'        => 0,
        ];
    }

    /**
     * An ACF field group as acf_get_field_groups() and acf_get_fields() return it.
     *
     * @param list<list<array{0: string, 1: string, 2: string}>> $location
     * @param list<array<string, mixed>>                         $fields
     * @param array<string, mixed>                               $overrides
     * @return array<string, mixed>
     */
    public static function acfGroup(string $key, array $location, array $fields, array $overrides = []): array
    {
        return $overrides + [
            'key'          => $key,
            'title'        => $key,
            'id'           => 0,
            'active'       => true,
            'show_in_rest' => false,
            'location'     => array_map(
                static fn (array $rules): array => array_map(
                    static fn (array $rule): array => ['param' => $rule[0], 'operator' => $rule[1], 'value' => $rule[2]],
                    $rules
                ),
                $location
            ),
            'fields'       => array_map(
                static fn (array $field): array => $field + ['name' => '', 'label' => '', 'type' => 'text', 'required' => false],
                $fields
            ),
        ];
    }
}
