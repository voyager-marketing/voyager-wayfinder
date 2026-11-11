<?php

/**
 * One project: title, client, year, project types, description and phases.
 * Used by single-project.php and archive-project.php.
 *
 * @package Voyager\Wayfinder
 */

$vwf_types = get_the_term_list(get_the_ID(), 'project_type', '', ', ');
?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <header class="entry-header">
        <?php if (is_singular()) : ?>
            <h1 class="entry-title"><?php the_title(); ?></h1>
        <?php else : ?>
            <h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
        <?php endif; ?>
    </header>

    <ul class="project-facts">
        <li>Client: <?php echo esc_html((string) get_field('client_name')); ?></li>
        <li>Completed: <?php echo esc_html((string) get_field('completion_year')); ?></li>
        <?php if (is_string($vwf_types)) : ?>
            <li>Type: <?php echo wp_kses_post($vwf_types); ?></li>
        <?php endif; ?>
    </ul>

    <div class="entry-content">
        <?php the_content(); ?>
    </div>

    <?php if (have_rows('project_phases')) : ?>
        <h3>Phases</h3>
        <ol class="project-phases">
            <?php
            while (have_rows('project_phases')) :
                the_row();
                ?>
                <li><?php echo esc_html((string) get_sub_field('phase_name')); ?>, <?php echo esc_html((string) get_sub_field('phase_weeks')); ?> weeks</li>
            <?php endwhile; ?>
        </ol>
    <?php endif; ?>
</article>
