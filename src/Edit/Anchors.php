<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Edit;

use Voyager\Wayfinder\Blocks\BlockFingerprint;
use Voyager\Wayfinder\Blocks\BlockNode;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\LayoutSource;
use Voyager\Wayfinder\Scanners\RouteScanner;

/**
 * Anchors: what a writer holds on to between reading the map and writing.
 *
 * An anchor is `{entity, post, path, fp, expect_hash}`. `entity` is the item
 * that holds the block (`part:footer`, `tpl:page`, `pat:cta`, `nav:main`), or
 * `pt:<post_type>` with `post` set to the post id for a post's content.
 * `path` is the block's parse-order path, `fp` its BlockFingerprint and
 * `expect_hash` the entity's item hash from the layout scan. Post content is
 * not a manifest item, so a `pt:` anchor carries no expect_hash.
 *
 * The item hash covers structure (refs, locks, classes, edit path), never
 * text, so it tells a writer the container was restructured; the
 * fingerprint tells it the block itself changed. Read-only: nothing here
 * writes to the site.
 *
 * @phpstan-type Anchor array{entity: string, post: int|null, path: string, fp: string, expect_hash: string|null}
 * @phpstan-type Located array{post: int|null, path: string}
 */
final class Anchors
{
    private LayoutSource $layout;

    private Locks $locks;

    /** @var array<string, string>|null Container id => block markup, filled on first use. */
    private ?array $markup = null;

    /** @var array<string, string>|null Item id => hash, from one layout scan. */
    private ?array $hashes = null;

    public function __construct(LayoutSource $layout, Locks $locks)
    {
        $this->layout = $layout;
        $this->locks = $locks;
    }

    /**
     * The current anchor of the block at `$path` in an entity, or null when
     * the entity or the path holds no block. A `pt:` entity needs `$post`.
     *
     * @return Anchor|null
     */
    public function anchor(string $entity, string $path, ?int $post = null): ?array
    {
        if (self::isContent($entity) && $post === null) {
            return null;
        }

        $trees = $this->trees($entity, $post);

        if (count($trees) !== 1) {
            return null;
        }

        $key = array_key_first($trees);
        $node = BlockFingerprint::at($trees[$key], $path);

        if ($node === null) {
            return null;
        }

        return self::make($entity, self::isContent($entity) ? (int) $key : null, $path, BlockFingerprint::of($node), $this->itemHash($entity));
    }

    /**
     * Where the block with fingerprint `$fp` sits now inside an entity:
     * every path that carries it, in parse order. For a `pt:` entity with
     * no post, every published post of that type is searched (up to
     * LayoutSource::MATCHING_CAP). Empty when the block is gone.
     *
     * @return list<Located>
     */
    public function locate(string $entity, string $fp, ?int $post = null): array
    {
        $found = [];

        foreach ($this->trees($entity, $post) as $id => $tree) {
            foreach (BlockFingerprint::paths($tree, $fp) as $path) {
                $found[] = ['post' => self::isContent($entity) ? (int) $id : null, 'path' => $path];
            }
        }

        return $found;
    }

    /**
     * An anchor, in key order.
     *
     * @return Anchor
     */
    public static function make(string $entity, ?int $post, string $path, string $fp, ?string $expectHash): array
    {
        return ['entity' => $entity, 'post' => $post, 'path' => $path, 'fp' => $fp, 'expect_hash' => $expectHash];
    }

    /**
     * The item hash of a layout entity, from the same scan find reads, or
     * null for post content and for an id the scan does not hold.
     */
    public function itemHash(string $entity): ?string
    {
        if (self::isContent($entity)) {
            return null;
        }

        if ($this->hashes === null) {
            $this->hashes = [];

            foreach ((new LayoutScanner($this->layout, $this->locks))->scan()->items as $item) {
                $this->hashes[$item->id] = $item->hash;
            }
        }

        return $this->hashes[$entity] ?? null;
    }

    /**
     * Block markup of a template, part, pattern or menu, from the layout
     * source. Null for bindings and for anything not found.
     */
    public function markup(string $id): ?string
    {
        if ($this->markup === null) {
            $this->markup = [];

            foreach ($this->layout->templates('wp_template') as $row) {
                $this->markup['tpl:' . LayoutScanner::key($row)] = $row['content'];
            }

            foreach ($this->layout->templates('wp_template_part') as $row) {
                $this->markup['part:' . LayoutScanner::key($row)] = $row['content'];
            }

            foreach ($this->layout->syncedPatterns() as $row) {
                $this->markup['pat:' . ($row['slug'] !== '' ? $row['slug'] : '#' . $row['id'])] = $row['content'];
            }

            foreach ($this->layout->navigations() as $row) {
                $this->markup['nav:' . ($row['slug'] !== '' ? $row['slug'] : '#' . $row['id'])] = $row['content'];
            }
        }

        if (isset($this->markup[$id])) {
            return $this->markup[$id];
        }

        if (str_starts_with($id, 'pat:') && !str_starts_with($id, 'pat:#')) {
            return $this->layout->registeredPattern(substr($id, 4))['content'] ?? null;
        }

        return null;
    }

    public static function isContent(string $entity): bool
    {
        return str_starts_with($entity, 'pt:');
    }

    /**
     * The block trees an entity holds: one for a layout entity, keyed by
     * its id; one per matching post for `pt:<type>`, keyed by post id.
     *
     * @return array<int|string, list<BlockNode>>
     */
    private function trees(string $entity, ?int $post): array
    {
        $builder = RouteScanner::builder($this->layout);

        if (!self::isContent($entity)) {
            $markup = $this->markup($entity);

            return $markup !== null ? [$entity => $builder->build(parse_blocks($markup))['tree']] : [];
        }

        $trees = [];

        // With a post, read that post alone: the type's list stops at MATCHING_CAP, oldest first.
        foreach ($this->layout->contentOfType(substr($entity, 3), $post) as $row) {
            $id = $row['id'] ?? null;

            if ($id === null || ($post !== null && $id !== $post)) {
                continue;
            }

            $trees[$id] = $builder->build(parse_blocks($row['content']))['tree'];
        }

        return $trees;
    }
}
