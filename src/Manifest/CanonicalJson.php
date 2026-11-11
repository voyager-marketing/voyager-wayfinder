<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Manifest;

use InvalidArgumentException;
use stdClass;

/**
 * The one JSON encoding every hash is taken over.
 *
 * Object keys are sorted lexically at every depth. Lists keep their order,
 * because order inside a list is the caller's to decide (sections and items
 * sort themselves by id before they get here). Slashes and unicode are not
 * escaped, and the hashed form is never pretty printed.
 */
final class CanonicalJson
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = $pretty ? self::FLAGS | JSON_PRETTY_PRINT : self::FLAGS;

        return json_encode(self::normalize($value), $flags);
    }

    /**
     * sha256 of the compact canonical encoding, as lowercase hex.
     */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /**
     * Sorts map keys at every depth. Maps come back as stdClass so that a map
     * whose sorted keys happen to read 0..n-1 still encodes as an object.
     *
     * @throws InvalidArgumentException On a value JSON cannot carry deterministically.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof stdClass) {
            return self::normalizeMap(get_object_vars($value));
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? array_map([self::class, 'normalize'], $value)
                : self::normalizeMap($value);
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot encode a value of type %s canonically.',
            esc_html(get_debug_type($value))
        ));
    }

    /**
     * @param array<array-key, mixed> $map
     */
    private static function normalizeMap(array $map): stdClass
    {
        ksort($map, SORT_STRING);

        return (object) array_map([self::class, 'normalize'], $map);
    }
}
