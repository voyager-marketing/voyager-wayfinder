<?php

/**
 * Plugin Name: Wayfinder fixture core
 * Description: Content model, Business Info settings and the business-info block bindings source for the Wayfinder fixture site. Test fixture only, never ships.
 * Version:     0.1.0
 * License:     GPL-2.0-or-later
 *
 * Loaded as an mu-plugin through the `.wp-env.json` mappings. `vwf_fixture_model()`
 * is the single description of what this file registers; fixtures/seed.php reads
 * the same array when it writes fixtures/truth.json, so the site and its ground
 * truth come from one set of constants.
 *
 * The fixture site also hosts the classic fixture (vwf-classic-core.php beside
 * this file), so everything here registers only while the vwf-fixture theme is
 * active. Each seed switches the theme and then reruns itself in a fresh WP-CLI
 * process, where `init` registers the right model.
 *
 * @package Voyager\Wayfinder
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// fixtures/seed.php and fixtures/classic/seed.php delete every page and post,
// and refuse to run unless this constant says they are on the fixture site.
define('VWF_FIXTURE', true);

/**
 * Everything this mu-plugin registers, as data.
 *
 * @return array<string, mixed>
 */
function vwf_fixture_model(): array
{
    return [
        // Everything below registers only while this theme is active.
        'theme' => 'vwf-fixture',
        'post_types' => [
            'service' => [
                'args' => [
                    'labels' => [
                        'name' => 'Services',
                        'singular_name' => 'Service',
                    ],
                    'public' => true,
                    'has_archive' => true,
                    'rewrite' => ['slug' => 'services'],
                    // custom-fields is what puts registered meta into the REST
                    // response; without it price_from is as hidden as internal_sku.
                    'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
                    'show_in_rest' => true,
                    'menu_icon' => 'dashicons-hammer',
                ],
                'meta' => [
                    'price_from' => [
                        'type' => 'number',
                        'label' => 'Price from',
                        'single' => true,
                        'show_in_rest' => true,
                    ],
                    'duration' => [
                        'type' => 'string',
                        'label' => 'Duration',
                        'single' => true,
                        'show_in_rest' => true,
                    ],
                    // Deliberately invisible to REST: a fact only an inside scan can see.
                    'internal_sku' => [
                        'type' => 'string',
                        'label' => 'Internal SKU',
                        'single' => true,
                        'show_in_rest' => false,
                    ],
                ],
            ],
            'team_member' => [
                'args' => [
                    'labels' => [
                        'name' => 'Team members',
                        'singular_name' => 'Team member',
                        'menu_name' => 'Team',
                    ],
                    'public' => false,
                    'show_ui' => true,
                    'show_in_rest' => true,
                    'rewrite' => false,
                    'query_var' => false,
                    'supports' => ['title', 'editor', 'thumbnail'],
                    'menu_icon' => 'dashicons-groups',
                ],
                'meta' => [],
            ],
        ],
        'taxonomies' => [
            'service_area' => [
                'object_types' => ['service'],
                'args' => [
                    'labels' => [
                        'name' => 'Service areas',
                        'singular_name' => 'Service area',
                    ],
                    'public' => true,
                    'hierarchical' => false,
                    'show_in_rest' => true,
                    'show_admin_column' => true,
                ],
            ],
        ],
        // Registered only while the option is truthy. Flipping it is how later
        // issues simulate content model drift.
        'drift' => [
            'option' => 'vwf_fixture_extra_field',
            'post_type' => 'service',
            'meta' => [
                'warranty_years' => [
                    'type' => 'integer',
                    'label' => 'Warranty years',
                    'single' => true,
                    'show_in_rest' => true,
                ],
            ],
        ],
        'acf_group' => [
            'key' => 'group_vwf_team_member',
            'title' => 'Team member details',
            'fields' => [
                [
                    'key' => 'field_vwf_team_role',
                    'label' => 'Role',
                    'name' => 'role',
                    'type' => 'text',
                ],
                [
                    'key' => 'field_vwf_team_photo',
                    'label' => 'Photo',
                    'name' => 'photo',
                    'type' => 'image',
                    'return_format' => 'id',
                ],
                [
                    'key' => 'field_vwf_team_linkedin',
                    'label' => 'LinkedIn',
                    'name' => 'linkedin',
                    'type' => 'url',
                ],
            ],
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'team_member',
                    ],
                ],
            ],
            'show_in_rest' => 0,
        ],
        'business_info' => [
            'title' => 'Business Info',
            'option_group' => 'vwf-business-info',
            'page_slug' => 'vwf-business-info',
            'options' => [
                'address' => ['option' => 'vwf_address', 'label' => 'Address'],
                'hours' => ['option' => 'vwf_hours', 'label' => 'Hours'],
                'phone' => ['option' => 'vwf_phone', 'label' => 'Phone'],
            ],
        ],
        'binding_source' => [
            'name' => 'vwf-fixture/business-info',
            'label' => 'Business info',
            'arg' => 'key',
            'edit_path' => 'options-general.php?page=vwf-business-info',
        ],
    ];
}

/**
 * True while the block fixture's theme is active. The classic fixture runs on
 * the same site, and neither carries the other's content model.
 */
function vwf_fixture_is_active(): bool
{
    return vwf_fixture_model()['theme'] === get_stylesheet();
}

add_action('init', 'vwf_fixture_register');

function vwf_fixture_register(): void
{
    if (!vwf_fixture_is_active()) {
        return;
    }

    $model = vwf_fixture_model();

    foreach ($model['post_types'] as $postType => $definition) {
        register_post_type($postType, $definition['args']);

        foreach ($definition['meta'] as $key => $args) {
            register_post_meta($postType, $key, $args);
        }
    }

    foreach ($model['taxonomies'] as $taxonomy => $definition) {
        register_taxonomy($taxonomy, $definition['object_types'], $definition['args']);
    }

    if (get_option($model['drift']['option'])) {
        foreach ($model['drift']['meta'] as $key => $args) {
            register_post_meta($model['drift']['post_type'], $key, $args);
        }
    }

    $info = $model['business_info'];
    foreach ($info['options'] as $option) {
        register_setting($info['option_group'], $option['option'], [
            'type' => 'string',
            'label' => $option['label'],
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
            'show_in_rest' => false,
        ]);
    }

    $source = $model['binding_source'];
    register_block_bindings_source($source['name'], [
        'label' => $source['label'],
        'get_value_callback' => 'vwf_fixture_binding_value',
    ]);
}

/**
 * Value for `vwf-fixture/business-info`, keyed by `args.key`. Unknown keys get
 * null, so a typo renders nothing rather than another option's value.
 *
 * @param array<string, mixed> $sourceArgs
 */
function vwf_fixture_binding_value(array $sourceArgs): ?string
{
    $options = vwf_fixture_model()['business_info']['options'];
    $key = is_string($sourceArgs['key'] ?? null) ? $sourceArgs['key'] : '';

    if (!isset($options[$key])) {
        return null;
    }

    return (string) get_option($options[$key]['option'], '');
}

add_filter('vwf_binding_edit_path', 'vwf_fixture_binding_edit_path', 10, 3);

/**
 * Tells Wayfinder where the business-info values are edited.
 *
 * @param mixed $path Edit path found so far.
 * @return mixed
 */
function vwf_fixture_binding_edit_path($path, $source = '', $key = '')
{
    $binding = vwf_fixture_model()['binding_source'];

    return $binding['name'] === $source ? $binding['edit_path'] : $path;
}

add_action('admin_menu', 'vwf_fixture_admin_menu');

function vwf_fixture_admin_menu(): void
{
    if (!vwf_fixture_is_active()) {
        return;
    }

    $info = vwf_fixture_model()['business_info'];

    add_options_page(
        $info['title'],
        $info['title'],
        'manage_options',
        $info['page_slug'],
        'vwf_fixture_render_business_info'
    );
}

function vwf_fixture_render_business_info(): void
{
    $info = vwf_fixture_model()['business_info'];

    echo '<div class="wrap"><h1>' . esc_html($info['title']) . '</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields($info['option_group']);
    echo '<table class="form-table" role="presentation">';

    foreach ($info['options'] as $option) {
        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="%1$s" name="%1$s" value="%3$s"></td></tr>',
            esc_attr($option['option']),
            esc_html($option['label']),
            esc_attr((string) get_option($option['option'], ''))
        );
    }

    echo '</table>';
    submit_button();
    echo '</form></div>';
}

add_action('acf/include_fields', 'vwf_fixture_register_acf');

function vwf_fixture_register_acf(): void
{
    if (!function_exists('acf_add_local_field_group') || !vwf_fixture_is_active()) {
        return;
    }

    acf_add_local_field_group(vwf_fixture_model()['acf_group']);
}
