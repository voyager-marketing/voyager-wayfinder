<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Manifest;

use InvalidArgumentException;

/**
 * Item ids: `<prefix>:<slug>`, with the prefixes from docs/ai-context-format.md.
 *
 * The slug may not contain whitespace, `|` or `,`: the compact text tiers use
 * `|` between columns and `,` between refs, so either would break a line.
 */
final class IdFormat
{
    /** Keep in step with the "Item ids" table in docs/ai-context-format.md. */
    public const PREFIXES = [
        'pt',
        'tax',
        'field',
        'tpl',
        'part',
        'pat',
        'nav',
        'loc',
        'sidebar',
        'bind',
        'route',
        'plugin',
        'opt',
        'optpage',
        'core',
        'gates',
        'btpl',
        'rest',
        'filter',
    ];

    public static function isValid(string $id): bool
    {
        $colon = strpos($id, ':');

        if ($colon === false) {
            return false;
        }

        $prefix = substr($id, 0, $colon);
        $slug = substr($id, $colon + 1);

        return in_array($prefix, self::PREFIXES, true)
            && preg_match('/^[^\s|,]+$/', $slug) === 1;
    }

    /**
     * @throws InvalidArgumentException When the id has an unknown prefix or an empty or unsafe slug.
     */
    public static function assertValid(string $id): void
    {
        if (!self::isValid($id)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid item id "%s". Expected <prefix>:<slug> with a prefix from: %s.',
                esc_html($id),
                esc_html(implode(', ', self::PREFIXES))
            ));
        }
    }
}
