<?php

/**
 * Plugin Name: Wayfinder classic fixture core
 * Description: Content model, Company Settings options page and ACF field groups for the Wayfinder classic fixture site. Test fixture only, never ships.
 * Version:     0.1.0
 * License:     GPL-2.0-or-later
 *
 * The classic fixture (Ashford Remodeling) is a classic child theme with most of
 * its structure in ACF, the kind of site agencies inherit. It shares the fixture
 * site with the block fixture, so this file sits beside vwf-fixture-core.php in
 * the folder `.wp-env.json` maps to wp-content/mu-plugins, and everything here
 * registers only while the vwf-classic theme is active.
 *
 * `vwf_classic_model()` is the single description of what this file registers;
 * fixtures/classic/seed.php reads the same array when it writes
 * fixtures/classic/truth.json.
 *
 * The repeater, the flexible content field and the options page need Secure
 * Custom Fields, which the classic seed activates in place of ACF free.
 *
 * @package Voyager\Wayfinder
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything this mu-plugin registers, as data.
 *
 * @return array<string, mixed>
 */
function vwf_classic_model(): array
{
    return [
        // Everything below registers only while this theme is active.
        'theme' => 'vwf-classic',
        'post_types' => [
            'project' => [
                'args' => [
                    'labels' => [
                        'name' => 'Projects',
                        'singular_name' => 'Project',
                    ],
                    'public' => true,
                    'has_archive' => true,
                    'rewrite' => ['slug' => 'projects'],
                    'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
                    'show_in_rest' => true,
                    'menu_icon' => 'dashicons-portfolio',
                ],
            ],
        ],
        'taxonomies' => [
            'project_type' => [
                'object_types' => ['project'],
                'args' => [
                    'labels' => [
                        'name' => 'Project types',
                        'singular_name' => 'Project type',
                    ],
                    'public' => true,
                    'hierarchical' => true,
                    'show_in_rest' => true,
                    'show_admin_column' => true,
                    'rewrite' => ['slug' => 'project-type'],
                ],
            ],
        ],
        // A top-level options page, so it opens at admin.php?page=company-settings.
        'options_page' => [
            'page_title' => 'Company Settings',
            'menu_title' => 'Company Settings',
            'menu_slug' => 'company-settings',
            'capability' => 'manage_options',
            'redirect' => false,
        ],
        'field_groups' => [
            [
                'key' => 'group_vwf_company_details',
                'title' => 'Company details',
                'fields' => [
                    [
                        'key' => 'field_vwf_company_phone',
                        'label' => 'Phone',
                        'name' => 'company_phone',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'field_vwf_company_address',
                        'label' => 'Address',
                        'name' => 'company_address',
                        'type' => 'textarea',
                        'new_lines' => '',
                    ],
                    [
                        'key' => 'field_vwf_company_email',
                        'label' => 'Email',
                        'name' => 'company_email',
                        'type' => 'email',
                    ],
                    [
                        'key' => 'field_vwf_facebook_url',
                        'label' => 'Facebook',
                        'name' => 'facebook_url',
                        'type' => 'url',
                    ],
                    [
                        'key' => 'field_vwf_instagram_url',
                        'label' => 'Instagram',
                        'name' => 'instagram_url',
                        'type' => 'url',
                    ],
                ],
                'location' => [
                    [
                        [
                            'param' => 'options_page',
                            'operator' => '==',
                            'value' => 'company-settings',
                        ],
                    ],
                ],
                'show_in_rest' => 0,
            ],
            [
                'key' => 'group_vwf_project_details',
                'title' => 'Project details',
                'fields' => [
                    [
                        'key' => 'field_vwf_client_name',
                        'label' => 'Client',
                        'name' => 'client_name',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'field_vwf_completion_year',
                        'label' => 'Completion year',
                        'name' => 'completion_year',
                        'type' => 'number',
                    ],
                    [
                        'key' => 'field_vwf_project_phases',
                        'label' => 'Phases',
                        'name' => 'project_phases',
                        'type' => 'repeater',
                        'layout' => 'table',
                        'button_label' => 'Add phase',
                        'sub_fields' => [
                            [
                                'key' => 'field_vwf_phase_name',
                                'label' => 'Phase',
                                'name' => 'phase_name',
                                'type' => 'text',
                            ],
                            [
                                'key' => 'field_vwf_phase_weeks',
                                'label' => 'Weeks',
                                'name' => 'phase_weeks',
                                'type' => 'number',
                            ],
                        ],
                    ],
                    // Office use only: no template reads it.
                    [
                        'key' => 'field_vwf_internal_job_code',
                        'label' => 'Internal job code',
                        'name' => 'internal_job_code',
                        'type' => 'text',
                    ],
                ],
                'location' => [
                    [
                        [
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => 'project',
                        ],
                    ],
                ],
                'show_in_rest' => 1,
            ],
            [
                'key' => 'group_vwf_landing_page',
                'title' => 'Landing page',
                'fields' => [
                    [
                        'key' => 'field_vwf_page_sections',
                        'label' => 'Page sections',
                        'name' => 'page_sections',
                        'type' => 'flexible_content',
                        'button_label' => 'Add section',
                        'layouts' => [
                            'layout_vwf_hero' => [
                                'key' => 'layout_vwf_hero',
                                'name' => 'hero',
                                'label' => 'Hero',
                                'display' => 'block',
                                'sub_fields' => [
                                    [
                                        'key' => 'field_vwf_hero_heading',
                                        'label' => 'Heading',
                                        'name' => 'heading',
                                        'type' => 'text',
                                    ],
                                    [
                                        'key' => 'field_vwf_hero_intro',
                                        'label' => 'Intro',
                                        'name' => 'intro',
                                        'type' => 'textarea',
                                        'new_lines' => '',
                                    ],
                                ],
                            ],
                            'layout_vwf_cta_band' => [
                                'key' => 'layout_vwf_cta_band',
                                'name' => 'cta_band',
                                'label' => 'Call to action band',
                                'display' => 'block',
                                'sub_fields' => [
                                    [
                                        'key' => 'field_vwf_cta_heading',
                                        'label' => 'Heading',
                                        'name' => 'heading',
                                        'type' => 'text',
                                    ],
                                    [
                                        'key' => 'field_vwf_cta_button_label',
                                        'label' => 'Button label',
                                        'name' => 'button_label',
                                        'type' => 'text',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'key' => 'field_vwf_featured_projects',
                        'label' => 'Featured projects',
                        'name' => 'featured_projects',
                        'type' => 'relationship',
                        'post_type' => ['project'],
                        'filters' => ['search'],
                        'return_format' => 'id',
                    ],
                ],
                'location' => [
                    [
                        [
                            'param' => 'page_template',
                            'operator' => '==',
                            'value' => 'page-templates/landing.php',
                        ],
                    ],
                ],
                'show_in_rest' => 0,
            ],
        ],
    ];
}

/**
 * True while the classic fixture's theme is active. The block fixture runs on
 * the same site, and neither carries the other's content model.
 */
function vwf_classic_is_active(): bool
{
    return vwf_classic_model()['theme'] === get_stylesheet();
}

add_action('init', 'vwf_classic_register');

function vwf_classic_register(): void
{
    if (!vwf_classic_is_active()) {
        return;
    }

    $model = vwf_classic_model();

    foreach ($model['post_types'] as $postType => $definition) {
        register_post_type($postType, $definition['args']);
    }

    foreach ($model['taxonomies'] as $taxonomy => $definition) {
        register_taxonomy($taxonomy, $definition['object_types'], $definition['args']);
    }
}

add_action('acf/init', 'vwf_classic_register_options_page');

function vwf_classic_register_options_page(): void
{
    if (!function_exists('acf_add_options_page') || !vwf_classic_is_active()) {
        return;
    }

    acf_add_options_page(vwf_classic_model()['options_page']);
}

add_action('acf/include_fields', 'vwf_classic_register_acf');

function vwf_classic_register_acf(): void
{
    if (!function_exists('acf_add_local_field_group') || !vwf_classic_is_active()) {
        return;
    }

    foreach (vwf_classic_model()['field_groups'] as $group) {
        acf_add_local_field_group($group);
    }
}
