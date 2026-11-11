<?php

/**
 * Archives. footer-archive.php does not exist, so core falls back to
 * footer.php, and the dynamic name falls back to content.php.
 */

get_header();

while (have_posts()) {
    the_post();
    get_template_part('template-parts/content', get_post_type());
}

get_footer('archive');
