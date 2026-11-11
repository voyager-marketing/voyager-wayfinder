<?php

/**
 * Template Name: Landing page
 *
 * A landing page is built from its Page sections field (ACF flexible
 * content) and its Featured projects field (ACF relationship). The page's own
 * content is never shown. The call to action button always links to the
 * contact page.
 *
 * @package Voyager\Wayfinder
 */

get_header();

while (have_posts()) {
    the_post();

    while (have_rows('page_sections')) {
        the_row();
        $vwf_layout = get_row_layout();

        if ('hero' === $vwf_layout) {
            echo '<section class="landing-hero"><h1>' . esc_html((string) get_sub_field('heading')) . '</h1>';
            echo '<p>' . esc_html((string) get_sub_field('intro')) . '</p></section>';
        } elseif ('cta_band' === $vwf_layout) {
            echo '<section class="landing-cta"><h2>' . esc_html((string) get_sub_field('heading')) . '</h2>';
            echo '<a class="button" href="' . esc_url(home_url('/contact/')) . '">' . esc_html((string) get_sub_field('button_label')) . '</a></section>';
        }
    }

    $vwf_featured = get_field('featured_projects');
    if (is_array($vwf_featured) && $vwf_featured) {
        echo '<section class="featured-projects"><h2>Featured projects</h2><ul>';
        foreach ($vwf_featured as $vwf_project) {
            echo '<li><a href="' . esc_url((string) get_permalink($vwf_project)) . '">' . esc_html(get_the_title($vwf_project)) . '</a></li>';
        }
        echo '</ul></section>';
    }
}

get_footer();
