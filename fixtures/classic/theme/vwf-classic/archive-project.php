<?php

/**
 * The projects archive at /projects/: every project through the project part.
 *
 * @package Voyager\Wayfinder
 */

get_header();
?>
<header class="page-header alignwide">
    <h1 class="page-title"><?php post_type_archive_title(); ?></h1>
</header>
<?php
while (have_posts()) {
    the_post();
    get_template_part('template-parts/content', 'project');
}

get_footer();
