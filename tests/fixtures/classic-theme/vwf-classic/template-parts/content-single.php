<?php

/**
 * A single post's content, with its meta line.
 */

the_title();
get_template_part('template-parts/entry-meta');
the_content();
