<?php

/**
 * The contact page, only in the child theme.
 */

get_header();
the_content();
?>
<a href="<?php echo esc_url(home_url('/thank-you/')); ?>">Thanks</a>
<?php
get_footer();
