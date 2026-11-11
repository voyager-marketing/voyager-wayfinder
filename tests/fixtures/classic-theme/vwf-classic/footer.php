<?php

/**
 * Site footer, with the company details from the theme settings page.
 */

wp_nav_menu(['theme_location' => 'footer']);
echo esc_html(get_field('company_phone', 'option'));
the_field('company_address', 'options');
echo esc_html(get_option('blogname'));
wp_footer();
