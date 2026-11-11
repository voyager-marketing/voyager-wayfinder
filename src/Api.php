<?php

declare(strict_types=1);

namespace Voyager\Wayfinder;

use InvalidArgumentException;
use Voyager\Wayfinder\Blocks\BlockFingerprint;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\Anchors;
use Voyager\Wayfinder\Scanners\WordPressLayoutSource;

/**
 * The public PHP API, for a plugin that writes blocks (Orbit) to check an
 * anchor in the same request as its write. `vwf_anchor()`,
 * `vwf_anchor_check()` and `vwf_api_version()` in voyager-wayfinder.php
 * delegate here; docs/ai-context-format.md ("PHP API") is the contract.
 *
 * Callers construct nothing: `new Api()` builds Anchors on the production
 * layout source and locks. An Api reads the site once and keeps what it
 * read, so build a new one after a write; the functions do, on every call.
 * Read-only: nothing here writes to the site.
 *
 * @phpstan-import-type Anchor from Anchors
 * @phpstan-type Check array{ok: bool, current: Anchor|null, reason: 'match'|'fp_changed'|'container_restructured'|'gone'|'ambiguous'}
 */
final class Api
{
    /** A breaking change to a function, its arguments or a return shape bumps this. */
    public const VERSION = '1';

    private Anchors $anchors;

    public function __construct(?Anchors $anchors = null)
    {
        $this->anchors = $anchors ?? new Anchors(new WordPressLayoutSource(), Locks::forSite());
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * The anchor of the block at `$path` in an entity now, or null when the
     * entity or the path holds no block. A `pt:` entity needs `$post`.
     *
     * @return Anchor|null
     */
    public function anchor(string $entity, string $path, ?int $post = null): ?array
    {
        return $this->anchors->anchor($entity, $path, $post);
    }

    /**
     * Whether the block an anchor names is still there, unchanged, and
     * where. `current` is the block's anchor now; write at `current.path`,
     * never at the path you passed.
     *
     * - `match` (ok): the block at the anchor's path still has its
     *   fingerprint, or exactly one block in the entity has it now (it moved;
     *   `current` has the new path). A layout entity's hash is still
     *   `expect_hash`.
     * - `container_restructured`: the block was found, but the layout
     *   entity's item hash is no longer `expect_hash`. `current` carries the
     *   new hash. Ignored for `pt:` entities, which have no item hash.
     * - `fp_changed`: a block sits at the path with another fingerprint and
     *   none in the entity has the old one. Either the block was edited or
     *   it was removed and a sibling took its path; `current` is whatever is
     *   at the path now.
     * - `ambiguous`: the path no longer holds the fingerprint and several
     *   blocks do. An anchor cannot tell identical blocks apart.
     * - `gone`: the entity, the post or the path holds no block, and no
     *   block carries the fingerprint.
     *
     * @param array<mixed> $anchor `{entity, post, path, fp, expect_hash}`, as find and `anchor()` return it.
     * @return Check
     * @throws InvalidArgumentException When the anchor is not shaped like one.
     */
    public function check(array $anchor): array
    {
        [$entity, $post, $path, $fp, $expect] = self::parse($anchor);

        $current = $this->anchors->anchor($entity, $path, $post);

        if ($current === null || $current['fp'] !== $fp) {
            $located = $this->anchors->locate($entity, $fp, $post);

            if (count($located) > 1) {
                return self::result(null, 'ambiguous');
            }

            if ($located === []) {
                return $current !== null ? self::result($current, 'fp_changed') : self::result(null, 'gone');
            }

            $current = $this->anchors->anchor($entity, $located[0]['path'], $post);

            if ($current === null) {
                return self::result(null, 'gone');
            }
        }

        if (!Anchors::isContent($entity) && $expect !== null && $current['expect_hash'] !== $expect) {
            return self::result($current, 'container_restructured');
        }

        return self::result($current, 'match');
    }

    /**
     * @param array<mixed> $anchor
     * @return array{string, int|null, string, string, string|null}
     */
    private static function parse(array $anchor): array
    {
        $entity = $anchor['entity'] ?? null;
        $path = $anchor['path'] ?? null;
        $fp = $anchor['fp'] ?? null;
        $post = $anchor['post'] ?? null;
        $expect = $anchor['expect_hash'] ?? null;

        if (!is_string($entity) || $entity === '') {
            throw new InvalidArgumentException('An anchor needs its entity, such as part:footer or pt:page.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message never reaches HTML.
        }

        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('An anchor needs its block path, such as 0.1.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message never reaches HTML.
        }

        if (!is_string($fp) || !BlockFingerprint::isValid($fp)) {
            throw new InvalidArgumentException(sprintf('An anchor fp is %d lowercase hex characters.', BlockFingerprint::LENGTH)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message never reaches HTML.
        }

        if ($post !== null && !is_int($post)) {
            throw new InvalidArgumentException('An anchor post is an integer post id or null.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message never reaches HTML.
        }

        if ($expect !== null && !is_string($expect)) {
            throw new InvalidArgumentException('An anchor expect_hash is a string or null.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message never reaches HTML.
        }

        if (Anchors::isContent($entity) && $post === null) {
            throw new InvalidArgumentException('A pt: anchor needs its post: the path is inside one post.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message never reaches HTML.
        }

        return [$entity, Anchors::isContent($entity) ? $post : null, $path, $fp, $expect];
    }

    /**
     * @param Anchor|null $current
     * @param 'match'|'fp_changed'|'container_restructured'|'gone'|'ambiguous' $reason
     * @return Check
     */
    private static function result(?array $current, string $reason): array
    {
        return ['ok' => $reason === 'match', 'current' => $current, 'reason' => $reason];
    }
}
