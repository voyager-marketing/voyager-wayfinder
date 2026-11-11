<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Blocks;

use Voyager\Wayfinder\Manifest\CanonicalJson;

/**
 * A block's identity that survives its siblings moving: the first 8 bytes,
 * as 16 hex characters, of sha256 over the canonical JSON of the block's
 * name, its attrs without `className` and `lock`, and its own text with
 * whitespace collapsed.
 *
 * The parse-order path ("0.2.1") says where a block is; the fingerprint
 * says which block it is. Inserting a paragraph before it moves the path
 * and keeps the fingerprint. Editing its text or an attribute moves the
 * fingerprint, so a writer holding an old one can tell the block changed.
 * A class or a lock does not move it: those are the selector and the
 * permission, not the content. Two identical blocks share a fingerprint,
 * which is why a lookup returns every path that carries it.
 *
 * Children are not part of it: a group's fingerprint does not move when
 * something inside it is edited.
 */
final class BlockFingerprint
{
    /** Hex characters kept: 8 bytes. */
    public const LENGTH = 16;

    /** Attrs left out: they change who may edit, or how it is styled, not what it says. */
    private const IGNORED_ATTRS = ['className', 'lock'];

    public static function of(BlockNode $node): string
    {
        $attrs = array_diff_key($node->attrs, array_flip(self::IGNORED_ATTRS));

        return substr(CanonicalJson::hash([
            'attrs' => $attrs === [] ? new \stdClass() : $attrs,
            'name'  => $node->name,
            'text'  => self::text($node->text),
        ]), 0, self::LENGTH);
    }

    /**
     * The node at a parse-order path, or null.
     *
     * @param list<BlockNode> $tree
     */
    public static function at(array $tree, string $path): ?BlockNode
    {
        foreach ($tree as $node) {
            if ($node->indexPath === $path) {
                return $node;
            }

            if (str_starts_with($path, $node->indexPath . '.')) {
                return self::at($node->children, $path);
            }
        }

        return null;
    }

    /**
     * Every path in the tree whose block carries the fingerprint, in parse order.
     *
     * @param list<BlockNode> $tree
     * @return list<string>
     */
    public static function paths(array $tree, string $fingerprint): array
    {
        $paths = [];

        foreach ($tree as $node) {
            if (self::of($node) === $fingerprint) {
                $paths[] = $node->indexPath;
            }

            array_push($paths, ...self::paths($node->children, $fingerprint));
        }

        return $paths;
    }

    /**
     * Whether a string is shaped like a fingerprint.
     */
    public static function isValid(string $fingerprint): bool
    {
        return preg_match('/^[0-9a-f]{' . self::LENGTH . '}$/', $fingerprint) === 1;
    }

    private static function text(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
