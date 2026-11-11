<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use WP_Post;
use WP_Query;

/**
 * Reads the site for Builders: which builders are active, which builder owns
 * a post, how many published posts of a type each one owns, and the
 * templates it keeps in its own post types.
 *
 * Active plugins come from the list IntegrationsScanner reads, so there is no
 * second plugin scan. Builder code is never loaded or called: only plugin and
 * theme slugs and post meta are read, and the meta's value only to compare
 * it with a marker.
 *
 * @phpstan-import-type BuilderDoc from Builders
 * @phpstan-import-type BuilderTemplateRow from LayoutSource
 */
final class WordPressBuilders
{
    /**
     * The builders active on this site.
     */
    public static function active(): Builders
    {
        $plugins = array_map(
            static fn (int|string $file): string => IntegrationsScanner::slug((string) $file),
            array_keys(IntegrationsScanner::lookupActivePlugins())
        );

        return Builders::bundled()->active(
            array_values(array_unique($plugins)),
            array_values(array_unique([(string) get_stylesheet(), (string) get_template()]))
        );
    }

    /**
     * The active builder that owns a post, with its edit URL, or null.
     *
     * @return BuilderDoc|null
     */
    public static function document(WP_Post $post, ?Builders $active = null): ?array
    {
        $active ??= self::active();

        if ($active->isEmpty()) {
            return null;
        }

        $id = (int) $post->ID;

        return $active->document(
            static fn (string $key): mixed => get_post_meta($id, $key, true),
            $id,
            self::permalinkPath($post),
            self::homePath()
        );
    }

    /**
     * Published posts of a type each active builder owns, by builder slug,
     * sorted. Builders that own none are left out.
     *
     * @return array<string, int>
     */
    public static function publishedCounts(string $postType, ?Builders $active = null): array
    {
        $active ??= self::active();
        $counts = [];

        foreach ($active->all() as $slug => $builder) {
            $clause = $builder['marker_value'] !== null
                ? ['key' => $builder['marker_key'], 'value' => $builder['marker_value'], 'compare' => '=']
                : ['key' => $builder['marker_key'], 'value' => ['', '0'], 'compare' => 'NOT IN'];

            $query = new WP_Query([
                'post_type'              => $postType,
                'post_status'            => 'publish',
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                // One indexed meta key per builder, counted once per scan.
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'meta_query'             => [$clause],
            ]);

            if ($query->found_posts > 0) {
                $counts[$slug] = (int) $query->found_posts;
            }
        }

        return $counts;
    }

    /**
     * Published posts in the active builders' template post types, at most
     * LayoutSource::MATCHING_CAP per type, by slug.
     *
     * @return list<BuilderTemplateRow>
     */
    public static function templates(?Builders $active = null): array
    {
        $active ??= self::active();
        $rows = [];

        foreach ($active->templateTypes() as $type => $slug) {
            $typeMeta = $active->typeMeta($slug);
            $posts = get_posts([
                'post_type'   => $type,
                'post_status' => 'publish',
                'numberposts' => LayoutSource::MATCHING_CAP,
                'orderby'     => 'name',
                'order'       => 'ASC',
            ]);

            foreach ($posts as $post) {
                $templateType = $typeMeta !== null ? get_post_meta((int) $post->ID, $typeMeta, true) : '';

                $rows[] = [
                    'builder'       => $slug,
                    'id'            => (int) $post->ID,
                    'slug'          => (string) $post->post_name,
                    'title'         => (string) $post->post_title,
                    'type'          => $type,
                    'template_type' => is_string($templateType) && $templateType !== '' ? $templateType : null,
                    'edit'          => $active->editUrl($slug, (int) $post->ID, self::permalinkPath($post), self::homePath()),
                ];
            }
        }

        return $rows;
    }

    /**
     * The post's permalink relative to the site root, with its query string,
     * so plain permalinks (`/?page_id=12`) keep the part that names the post.
     */
    private static function permalinkPath(WP_Post $post): string
    {
        $link = get_permalink($post);

        return $link !== '' ? wp_make_link_relative($link) : '/';
    }

    private static function homePath(): string
    {
        $path = wp_parse_url(home_url('/'), PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
