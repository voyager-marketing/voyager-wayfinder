<?php

/**
 * The footer: the parent's footer menu location and the company details from
 * the Company Settings options page. Replaces Twenty Twenty-One's footer.php.
 *
 * @package Voyager\Wayfinder
 */

?>
            </main>
        </div>
    </div>

    <footer id="colophon" class="site-footer">
        <?php
        wp_nav_menu([
            'theme_location' => 'footer',
            'container' => 'nav',
            'container_class' => 'footer-navigation',
            'depth' => 1,
            'fallback_cb' => false,
        ]);
        ?>
        <div class="company-details">
            <p class="company-phone"><?php echo esc_html((string) get_field('company_phone', 'option')); ?></p>
            <p class="company-address"><?php echo esc_html((string) get_field('company_address', 'option')); ?></p>
            <ul class="company-social">
                <li><a href="<?php echo esc_url((string) get_field('facebook_url', 'option')); ?>">Facebook</a></li>
                <li><a href="<?php echo esc_url((string) get_field('instagram_url', 'option')); ?>">Instagram</a></li>
            </ul>
        </div>
    </footer>
</div>

<?php wp_footer(); ?>

</body>
</html>
