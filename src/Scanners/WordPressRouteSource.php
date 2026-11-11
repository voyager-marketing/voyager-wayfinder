<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Throwable;
use WP_Block;
use WP_Block_Template;
use WP_Post;

/**
 * RouteSource over a live WordPress site.
 *
 * Every call reads at call time, never at construction, so building the
 * default scanners stays free when only another section is scanned.
 *
 * @phpstan-import-type RoutePost from RouteSource
 * @phpstan-import-type RoutePostType from RouteSource
 */
final class WordPressRouteSource implements RouteSource
{
    /** The active builders, read on the first row and kept for this source's life. */
    private ?Builders $builders = null;

    public function homePath(): string
    {
        return self::relative(home_url('/'));
    }

    public function frontPage(): ?array
    {
        return get_option('show_on_front') === 'page' ? $this->published((int) get_option('page_on_front')) : null;
    }

    public function postsPage(): ?array
    {
        return get_option('show_on_front') === 'page' ? $this->published((int) get_option('page_for_posts')) : null;
    }

    public function permalinkStructure(): string
    {
        $structure = get_option('permalink_structure', '');

        return is_string($structure) ? $structure : '';
    }

    /**
     * @return list<RoutePostType>
     */
    public function postTypes(): array
    {
        $rows = [];

        foreach (get_post_types(['public' => true], 'objects') as $name => $type) {
            if ($name === 'attachment') {
                continue;
            }

            $link = $type->has_archive ? get_post_type_archive_link($name) : false;
            $rewrite = $type->rewrite;

            $rows[] = [
                'name'         => (string) $name,
                'archive_path' => is_string($link) && $link !== '' ? self::relative($link) : null,
                'rewrite'      => is_array($rewrite) && is_string($rewrite['slug'] ?? null)
                    ? ['slug' => $rewrite['slug'], 'with_front' => (bool) ($rewrite['with_front'] ?? true)]
                    : null,
                'hierarchical' => (bool) $type->hierarchical,
                'query_var'    => is_string($type->query_var) && $type->query_var !== '' ? $type->query_var : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    public function firstPost(string $postType, array $exclude = []): ?array
    {
        $posts = get_posts([
            'post_type'    => $postType,
            'post_status'  => 'publish',
            'numberposts'  => 1,
            'orderby'      => 'name',
            'order'        => 'ASC',
            'post__not_in' => $exclude,
        ]);

        return $posts !== [] ? $this->row($posts[0]) : null;
    }

    /**
     * @return list<RoutePost>
     */
    public function templatedPages(): array
    {
        $posts = get_posts([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'name',
            'order'       => 'ASC',
            // A meta query is the only way to ask for "has a template"; the set is small.
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'meta_query'  => [[
                'key'     => '_wp_page_template',
                'value'   => ['', 'default'],
                'compare' => 'NOT IN',
            ]],
        ]);

        return array_map([$this, 'row'], $posts);
    }

    public function postAt(string $path): ?array
    {
        $home = wp_parse_url(home_url());

        if (!is_array($home) || !isset($home['scheme'], $home['host'])) {
            return null;
        }

        $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');

        return $this->published(url_to_postid($origin . $path));
    }

    /**
     * On a block theme, calls core's resolve_block_template() with the
     * candidates, the same call locate_block_template() makes during a real
     * request, minus the PHP fallback template. On a classic theme, finds the
     * PHP template with core's locate_template(), child theme first. On a
     * hybrid theme, a block template as specific as that file or more wins
     * over it, as in locate_block_template(), and comes back as its
     * `<theme>//<slug>` key.
     */
    public function resolveTemplate(string $type, array $candidates): ?string
    {
        if (ClassicTheme::isActive()) {
            if (!ClassicTheme::isHybrid() || !function_exists('resolve_block_template')) {
                return ClassicTheme::active()->resolve($candidates);
            }

            return ClassicTheme::active()->resolveWithBlockTemplates(
                $candidates,
                static function (array $remaining, string $file) use ($type): ?string {
                    $template = resolve_block_template($type, $remaining, $file);

                    return $template instanceof WP_Block_Template ? ClassicTheme::blockKey((string) $template->theme, (string) $template->slug) : null;
                }
            );
        }

        if (!function_exists('resolve_block_template') || !(wp_is_block_theme() || current_theme_supports('block-templates'))) {
            return null;
        }

        $template = resolve_block_template($type, $candidates, '');

        return $template instanceof WP_Block_Template ? (string) $template->slug : null;
    }

    /**
     * Asks the registered source for its value the way core does while
     * rendering, through a WP_Block carrying the post as context. A source
     * that throws counts as no value. The value is only compared, never
     * returned to a caller of the locator.
     */
    public function bindingValue(string $source, array $args, string $block, string $attribute, ?array $post): ?string
    {
        if (!function_exists('get_block_bindings_source')) {
            return null;
        }

        $registered = get_block_bindings_source($source);

        if ($registered === null) {
            return null;
        }

        $context = $post !== null ? ['postId' => $post['id'], 'postType' => $post['type']] : [];

        try {
            $instance = new WP_Block(
                ['blockName' => $block, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []],
                $context
            );
            $value = $registered->get_value($args, $instance, $attribute);
        } catch (Throwable $e) {
            return null;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @return RoutePost|null
     */
    private function published(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $post = get_post($id);

        return $post instanceof WP_Post && $post->post_status === 'publish' ? $this->row($post) : null;
    }

    /**
     * @return RoutePost
     */
    private function row(WP_Post $post): array
    {
        $row = [
            'id'       => (int) $post->ID,
            'type'     => (string) $post->post_type,
            'slug'     => (string) $post->post_name,
            'title'    => (string) $post->post_title,
            'path'     => self::relative(get_permalink($post)),
            'template' => get_page_template_slug($post),
            'content'  => (string) $post->post_content,
        ];

        $this->builders ??= WordPressBuilders::active();
        $builder = WordPressBuilders::document($post, $this->builders);

        return $builder !== null ? $row + ['builder' => $builder] : $row;
    }

    private static function relative(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
