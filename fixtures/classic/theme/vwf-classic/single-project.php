<?php

/**
 * A single project: the project part, then the Project sidebar.
 *
 * @package Voyager\Wayfinder
 */

get_header();

while (have_posts()) {
    the_post();
    get_template_part('template-parts/content', 'project');
}

if (is_active_sidebar('project-sidebar')) {
    echo '<aside class="widget-area project-sidebar">';
    dynamic_sidebar('project-sidebar');
    echo '</aside>';
}

get_footer();
