<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use Voyager\Wayfinder\Manifest\Manifest;

/**
 * The compact text grammar the brief and the index share
 * (docs/ai-context-format.md, "Compact text grammar"):
 *
 *     # wayfinder v1 <tier> h:<first 6 hex of manifest hash>
 *     <key>: <value> | <value> | ...
 *     <id> | <label> | <key facts> | <edit path> | <refs, comma separated>
 */
final class Compact
{
    public const FORMAT_VERSION = 'v1';

    /** Stands in for an empty column that has a filled column after it. */
    public const EMPTY_COLUMN = '-';

    public static function header(string $tier, Manifest $manifest): string
    {
        return sprintf('# wayfinder %s %s h:%s', self::FORMAT_VERSION, $tier, substr($manifest->hash, 0, 6));
    }

    /**
     * Columns joined by ` | `. Empty columns are dropped from the end and
     * shown as `-` in the middle, so a column always keeps its position.
     *
     * @param list<string|null> $columns
     */
    public static function line(array $columns): string
    {
        $cells = array_map(static fn (?string $column): string => self::clean((string) $column), $columns);

        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }

        return implode(' | ', array_map(
            static fn (string $cell): string => $cell === '' ? self::EMPTY_COLUMN : $cell,
            $cells
        ));
    }

    /**
     * `<key>: <value> | <value> | ...`
     *
     * @param list<string|null> $values
     */
    public static function keyLine(string $key, array $values): string
    {
        return $key . ': ' . self::line($values);
    }

    /**
     * Text that fits in one column: one line, no column separator.
     */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim(str_replace('|', '/', $text));
    }

    /**
     * Characters, not bytes: the budgets in the format doc are in characters.
     */
    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * The token figure the stats report: characters divided by four, rounded
     * up. An estimate, and labeled as one wherever it is shown.
     */
    public static function estimateTokens(string $text): int
    {
        return intdiv(self::length($text) + 3, 4);
    }
}
