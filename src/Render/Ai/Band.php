<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

/**
 * How the brief and the index print a count: `0`, `1-5`, `6-20`, `21-100`,
 * `100+`. A site that publishes every day keeps the same brief and index
 * bytes, and so the same prompt cache, until a count leaves its band. The
 * section tier prints exact numbers.
 */
final class Band
{
    /** The top of each band but the last. */
    public const TOPS = [0, 5, 20, 100];

    public const LABELS = ['0', '1-5', '6-20', '21-100', '100+'];

    public static function of(int $count): string
    {
        return self::LABELS[self::rank($count)];
    }

    /**
     * 0 for the `0` band up to 4 for `100+`, so bands sort.
     */
    public static function rank(int $count): int
    {
        foreach (self::TOPS as $rank => $top) {
            if ($count <= $top) {
                return $rank;
            }
        }

        return count(self::TOPS);
    }
}
