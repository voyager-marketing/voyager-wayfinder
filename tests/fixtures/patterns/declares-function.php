<?php

/**
 * Title: Hours
 * Slug: vwf-fixture/hours
 *
 * A registered pattern file that declares a function. Core includes a
 * pattern file itself, so a second include would redeclare it: Wayfinder
 * leaves it untraced and never includes it. No test may include it.
 *
 * phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
 */

if (!function_exists('vwf_fixture_hours')) {
    /**
     * The opening hours.
     */
    function vwf_fixture_hours(): string
    {
        return (string) get_option('vwf_hours', '');
    }
}
?>
<!-- wp:paragraph -->
<p><?php echo esc_html(vwf_fixture_hours()); ?></p>
<!-- /wp:paragraph -->
