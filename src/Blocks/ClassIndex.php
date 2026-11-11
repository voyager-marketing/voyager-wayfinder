<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Blocks;

/**
 * The selector-like attributes of a block that a client never sees in the
 * editor: `className` tokens and the `metadata.name` label. Block style
 * variations are className tokens too (`is-style-<slug>`).
 *
 * BlockTreeBuilder counts them in the walk it already does, and the layout
 * scanner turns those counts into facts with fact(). The edit locator asks
 * matches() whether a block answers a `class` query.
 *
 * A class token is compared lowercased and whole: `hide-mobile` does not
 * match `hide-mobile-nav`. A query may carry the selector's leading dot.
 * A `metadata.name` matches the query case-insensitively, whole.
 *
 * @phpstan-type ClassFacts array{classes: list<array{class: string, count: int, style?: string}>, block_names: list<array{name: string, count: int}>, hidden_classes: bool, classes_more?: int}
 */
final class ClassIndex
{
    /** Classes, and block names, listed per item. The rest are counted in `classes_more`. */
    public const CAP = 50;

    /** The prefix core gives a block style variation's class. */
    public const STYLE_PREFIX = 'is-style-';

    /**
     * A block's className tokens, lowercased, de-duplicated, in order of
     * first appearance.
     *
     * @param array<array-key, mixed> $attrs
     * @return list<string>
     */
    public static function tokens(array $attrs): array
    {
        $className = $attrs['className'] ?? null;

        if (!is_string($className)) {
            return [];
        }

        $tokens = preg_split('/\s+/', mb_strtolower(trim($className), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($tokens === false ? [] : $tokens));
    }

    /**
     * A block's `metadata.name`, trimmed, or null when it has none.
     *
     * @param array<array-key, mixed> $attrs
     */
    public static function name(array $attrs): ?string
    {
        $metadata = $attrs['metadata'] ?? null;
        $name = is_array($metadata) ? ($metadata['name'] ?? null) : null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * The variation slug of an `is-style-<slug>` token, else null.
     */
    public static function style(string $token): ?string
    {
        return str_starts_with($token, self::STYLE_PREFIX) && strlen($token) > strlen(self::STYLE_PREFIX)
            ? substr($token, strlen(self::STYLE_PREFIX))
            : null;
    }

    /**
     * A query as compared: trimmed, lowercased, without a selector's leading dot.
     */
    public static function normalize(string $query): string
    {
        return mb_strtolower(ltrim(trim($query), '.'), 'UTF-8');
    }

    /**
     * Whether the block carries the class, or is named it. `$query` must
     * already be normalized.
     */
    public static function matches(BlockNode $node, string $query): bool
    {
        if ($query === '') {
            return false;
        }

        if (in_array($query, self::tokens($node->attrs), true)) {
            return true;
        }

        $name = self::name($node->attrs);

        return $name !== null && mb_strtolower($name, 'UTF-8') === $query;
    }

    /**
     * Facts from the counts BlockTreeBuilder keeps: `classes` (each class
     * with how many blocks carry it, and the variation slug of a style
     * class), `block_names` (each `metadata.name` with its count),
     * `hidden_classes` (true when any class is not a style variation, so
     * nothing in the editor shows it) and, past CAP entries, `classes_more`.
     * Lists sort by class or name, so the bytes do not depend on walk order.
     *
     * @param array<string, int> $classes Token => blocks carrying it.
     * @param array<string, int> $names   Name => blocks carrying it.
     * @return ClassFacts
     */
    public static function facts(array $classes, array $names): array
    {
        ksort($classes, SORT_STRING);
        ksort($names, SORT_STRING);

        $listed = [];
        $hidden = false;

        foreach ($classes as $class => $count) {
            $class = (string) $class;
            $style = self::style($class);

            if ($style === null) {
                $hidden = true;
            }

            if (count($listed) < self::CAP) {
                $listed[] = ['class' => $class, 'count' => $count] + ($style !== null ? ['style' => $style] : []);
            }
        }

        $named = [];

        foreach (array_slice($names, 0, self::CAP, true) as $name => $count) {
            $named[] = ['name' => (string) $name, 'count' => $count];
        }

        $facts = ['classes' => $listed, 'block_names' => $named, 'hidden_classes' => $hidden];
        $more = max(0, count($classes) - self::CAP) + max(0, count($names) - self::CAP);

        if ($more > 0) {
            $facts['classes_more'] = $more;
        }

        return $facts;
    }
}
