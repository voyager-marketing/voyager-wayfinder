<?php

/**
 * Title: Site phone
 * Slug: vwf-fixture/site-phone
 *
 * A registered pattern file as a theme writes one: the number is not typed
 * here, it is read from an option through a helper, and nothing is printed
 * while the option is unset. PatternReadsTest includes it.
 */

$vwfPhone = (static fn (): string => trim((string) get_option('vwf_phone', '')))();

if ($vwfPhone === '') {
    return;
}
?>
<!-- wp:paragraph -->
<p class="vwf-phone"><?php echo esc_html($vwfPhone); ?></p>
<!-- /wp:paragraph -->
