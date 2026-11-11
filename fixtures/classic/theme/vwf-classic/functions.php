<?php

/**
 * Wayfinder classic fixture theme: a child of Twenty Twenty-One with its own
 * header and footer, project templates, a landing page template, one menu
 * location and one sidebar. Twenty Twenty-One loads its own stylesheet.
 * Test fixture only, never ships.
 *
 * @package Voyager\Wayfinder
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', 'vwf_classic_setup');

/**
 * The header's menu location. The parent registers `primary` and `footer`
 * too; the footer menu uses the parent's `footer` location.
 */
function vwf_classic_setup(): void
{
    register_nav_menus(['main-menu' => 'Main menu']);
}

add_action('widgets_init', 'vwf_classic_widgets_init');

/**
 * The sidebar beside each project. The parent registers `sidebar-1`, which
 * this theme's footer does not show.
 */
function vwf_classic_widgets_init(): void
{
    register_sidebar([
        'name' => 'Project sidebar',
        'id' => 'project-sidebar',
        'description' => 'Shown beside each project.',
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget' => '</section>',
        'before_title' => '<h2 class="widget-title">',
        'after_title' => '</h2>',
    ]);
}
