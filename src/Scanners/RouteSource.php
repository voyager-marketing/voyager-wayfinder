<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

/**
 * Everything RouteScanner and the edit locator read from WordPress about
 * URLs, as plain rows.
 *
 * WordPressRouteSource is the real one. The unit suite passes an in-memory
 * source over the fixture site. Paths are relative to the site root, as
 * WordPress writes them (`/services/drain-cleaning/`).
 *
 * A post an active page builder owns (see Builders) carries `builder`: the
 * builder's slug, label and edit URL for that post. Other posts leave it out.
 *
 * @phpstan-import-type BuilderDoc from Builders
 * @phpstan-type RoutePost array{id: int, type: string, slug: string, title: string, path: string, template: string, content: string, builder?: BuilderDoc|null}
 * @phpstan-type RoutePostType array{name: string, archive_path: string|null, rewrite: array{slug: string, with_front: bool}|null, hierarchical: bool, query_var: string|null}
 */
interface RouteSource
{
    /**
     * The path of the home URL: `/`, or `/sub/` for a site in a subdirectory.
     */
    public function homePath(): string;

    /**
     * The page Settings > Reading shows at the home URL, or null when the
     * home URL lists the latest posts.
     *
     * @return RoutePost|null
     */
    public function frontPage(): ?array;

    /**
     * The page Settings > Reading lists the latest posts on, or null when
     * there is none.
     *
     * @return RoutePost|null
     */
    public function postsPage(): ?array;

    /**
     * The permalink structure option: `/%postname%/`, or empty for plain
     * permalinks.
     */
    public function permalinkStructure(): string;

    /**
     * Public post types other than attachment. `archive_path` is set only for
     * post types with an archive. `rewrite` is the registered rewrite with its
     * slug filled in (the post type name by default), null when rewrites are
     * off. `query_var` is null when the post type has none.
     *
     * @return list<RoutePostType>
     */
    public function postTypes(): array;

    /**
     * The published post of this type whose slug sorts first, skipping the
     * given ids. `template` is the assigned template slug, empty for the
     * default.
     *
     * @param list<int> $exclude
     * @return RoutePost|null
     */
    public function firstPost(string $postType, array $exclude = []): ?array;

    /**
     * Published pages that have a template other than the default assigned.
     *
     * @return list<RoutePost>
     */
    public function templatedPages(): array;

    /**
     * The published post a relative path resolves to, or null.
     *
     * @return RoutePost|null
     */
    public function postAt(string $path): ?array;

    /**
     * Slug of the block template WordPress serves for these candidates, most
     * specific first, or null when none of them exists.
     *
     * @param string       $type       Template type, e.g. `single` or `page`.
     * @param list<string> $candidates Template slugs without a file suffix.
     */
    public function resolveTemplate(string $type, array $candidates): ?string;

    /**
     * What a block bindings source returns for one bound attribute, with the
     * post as block context, or null when it returns nothing.
     *
     * @param array<array-key, mixed> $args
     * @param RoutePost|null          $post
     */
    public function bindingValue(string $source, array $args, string $block, string $attribute, ?array $post): ?string;
}
