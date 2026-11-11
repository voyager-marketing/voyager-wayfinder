<?php

/**
 * The header: site title and the Main menu location. Replaces Twenty
 * Twenty-One's header.php.
 *
 * @package Voyager\Wayfinder
 */

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="page" class="site">
    <header id="masthead" class="site-header">
        <p class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a></p>
        <?php
        wp_nav_menu([
            'theme_location' => 'main-menu',
            'container' => 'nav',
            'container_class' => 'primary-navigation',
            'fallback_cb' => false,
        ]);
        ?>
    </header>

    <div id="content" class="site-content">
        <div id="primary" class="content-area">
            <main id="main" class="site-main">
