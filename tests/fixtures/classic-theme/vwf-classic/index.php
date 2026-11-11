<?php

/**
 * Fallback for every URL. Includes a part the theme does not ship.
 */

get_header();
get_template_part('template-parts/content');
get_template_part('template-parts/missing');
get_footer();
