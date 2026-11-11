<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Inspector;

use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Scanners\RouteScanner;
use Voyager\Wayfinder\Scanners\WordPressLayoutSource;
use WP_Admin_Bar;
use WP_Block;

/**
 * "Where is this edited?" on the front end.
 *
 * An admin bar node, for users who can edit the theme, reloads the page
 * with `?vwf_inspect=1` and a nonce. When both check out, and only then,
 * the page is rendered through Annotator and the inspector script and
 * style load: hover a part of the page to see what it is and where it is
 * edited, click to keep the label open, Esc to leave.
 *
 * Nothing is added for anyone else. A visitor who adds `?vwf_inspect=1`
 * gets the same bytes as without it: the nonce is per user, so it never
 * verifies for them, and no hook is added before it does.
 */
final class Inspector
{
    public const QUERY_ARG = 'vwf_inspect';

    public const NONCE_ARG = '_vwfnonce';

    public const NONCE_ACTION = 'vwf_inspect';

    public const CAPABILITY = 'edit_theme_options';

    public const NODE_ID = 'vwf-inspect';

    private const ASSET_HANDLE = 'vwf-inspector';

    /** After other filters change a block's markup, so the mark lands on what is sent. */
    private const RENDER_PRIORITY = 999;

    private ?Annotator $annotator = null;

    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'addNode'], 90);
        add_action('template_redirect', [$this, 'maybeStart']);
    }

    public function addNode(WP_Admin_Bar $bar): void
    {
        if (is_admin() || !current_user_can(self::CAPABILITY)) {
            return;
        }

        $active = $this->annotator !== null;

        $bar->add_node([
            'id'    => self::NODE_ID,
            'title' => $active
                ? esc_html__('Stop inspecting', 'voyager-wayfinder')
                : esc_html__('Where is this edited?', 'voyager-wayfinder'),
            'href'  => $active ? self::exitUrl() : wp_nonce_url(add_query_arg(self::QUERY_ARG, '1', self::exitUrl()), self::NONCE_ACTION, self::NONCE_ARG),
            'meta'  => ['class' => $active ? 'vwf-inspect-on' : ''],
        ]);
    }

    /**
     * Starts the inspector for this request when the switch, the nonce and
     * the capability all check out.
     */
    public function maybeStart(): void
    {
        if (!self::requested()) {
            return;
        }

        $path = wp_parse_url(self::exitUrl(), PHP_URL_PATH);
        $result = (new EditLocator())->find(is_string($path) && $path !== '' ? $path : '/');
        $targets = Annotator::targets($result, static fn (string $edit): string => admin_url($edit));
        $postId = get_queried_object_id();

        $this->annotator = new Annotator(
            $targets,
            RouteScanner::builder(new WordPressLayoutSource()),
            new TagProcessorWriter(),
            $postId > 0 ? $postId : null
        );

        add_filter('pre_render_block', [$this, 'enterBlock'], PHP_INT_MAX, 3);
        add_filter('render_block', [$this, 'leaveBlock'], self::RENDER_PRIORITY, 3);
        add_action('wp_enqueue_scripts', fn () => $this->enqueue($targets));
    }

    /**
     * @param string|null             $preRender
     * @param array<array-key, mixed> $block
     */
    public function enterBlock(?string $preRender, array $block, ?WP_Block $parent = null): ?string
    {
        // Another filter rendered the block itself, so render_block will not run for it.
        if ($preRender === null && $this->annotator !== null) {
            $this->annotator->enter($block, self::context($parent->context ?? []));
        }

        return $preRender;
    }

    /**
     * @param array<array-key, mixed> $block
     */
    public function leaveBlock(string $content, array $block, ?WP_Block $instance = null): string
    {
        if ($this->annotator === null) {
            return $content;
        }

        return $this->annotator->leave($content, $block, self::context($instance->context ?? []));
    }

    /**
     * @param array<string, array{label: string, edit: string|null, client_can: 'yes'|'partial'|'no', client_can_why: string}> $targets
     */
    private function enqueue(array $targets): void
    {
        $root = dirname(__DIR__, 2);
        $main = $root . '/voyager-wayfinder.php';

        wp_enqueue_style(self::ASSET_HANDLE, plugins_url('assets/inspector.css', $main), [], self::assetVersion($root . '/assets/inspector.css'));
        wp_enqueue_script(self::ASSET_HANDLE, plugins_url('assets/inspector.js', $main), [], self::assetVersion($root . '/assets/inspector.js'), ['in_footer' => true]);

        wp_localize_script(self::ASSET_HANDLE, 'vwfInspector', [
            'targets' => $targets,
            'exitUrl' => esc_url_raw(self::exitUrl()),
            'strings' => [
                'banner'    => __('Where is this edited? Hover a part of the page, click to keep its label open, press Esc to stop.', 'voyager-wayfinder'),
                'stop'      => __('Stop inspecting', 'voyager-wayfinder'),
                'edit'      => __('Edit', 'voyager-wayfinder'),
                'noEdit'    => __('No edit screen in wp-admin. It is changed in code.', 'voyager-wayfinder'),
                /* translators: 1: yes, content only or no. 2: the reason. */
                'clientCan' => __('Client can edit: %1$s (%2$s)', 'voyager-wayfinder'),
                'yes'       => __('yes', 'voyager-wayfinder'),
                'partial'   => __('content only', 'voyager-wayfinder'),
                'no'        => __('no', 'voyager-wayfinder'),
            ],
        ]);
    }

    /**
     * Whether this request asks for the inspector and may have it.
     */
    private static function requested(): bool
    {
        // The nonce is verified right here, so reading the switch first is safe.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (is_admin() || !isset($_GET[self::QUERY_ARG], $_GET[self::NONCE_ARG]) || sanitize_key(wp_unslash($_GET[self::QUERY_ARG])) !== '1') {
            return false;
        }

        $nonce = sanitize_text_field(wp_unslash($_GET[self::NONCE_ARG]));

        return current_user_can(self::CAPABILITY) && wp_verify_nonce($nonce, self::NONCE_ACTION) !== false;
    }

    /**
     * This page's URL without the inspector's arguments.
     */
    private static function exitUrl(): string
    {
        return remove_query_arg([self::QUERY_ARG, self::NONCE_ARG]);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array{postId?: mixed, postType?: mixed}
     */
    private static function context(array $context): array
    {
        $type = $context['postType'] ?? get_post_type();

        return [
            'postId'   => $context['postId'] ?? null,
            'postType' => is_string($type) ? $type : null,
        ];
    }

    private static function assetVersion(string $file): string
    {
        $modified = is_readable($file) ? filemtime($file) : false;

        return Plugin::VERSION . ($modified !== false ? '.' . $modified : '');
    }
}
