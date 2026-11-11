<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use InvalidArgumentException;

/**
 * The only options a scanner may read by name, and so the only option values
 * the manual can ever emit. This list is the guard against the manual leaking
 * settings.
 *
 * The `vwf_option_allowlist` filter may remove names. It cannot add any: a
 * name the filter returns that is not in OPTIONS is dropped. Adding an option
 * is a code change and a review, on purpose.
 */
final class OptionAllowlist
{
    public const FILTER = 'vwf_option_allowlist';

    /**
     * Option name => [label, edit path]. Labels stay untranslated: the
     * manifest body may not depend on the locale.
     */
    public const OPTIONS = [
        'blogname'            => ['Site title', 'options-general.php'],
        'blogdescription'     => ['Tagline', 'options-general.php'],
        'show_on_front'       => ['Homepage displays', 'options-reading.php'],
        'page_on_front'       => ['Homepage', 'options-reading.php'],
        'page_for_posts'      => ['Posts page', 'options-reading.php'],
        'permalink_structure' => ['Permalink structure', 'options-permalink.php'],
        'timezone_string'     => ['Timezone', 'options-general.php'],
        'WPLANG'              => ['Site language', 'options-general.php'],
        'stylesheet'          => ['Active theme', 'themes.php'],
        'template'            => ['Template theme', 'themes.php'],
    ];

    /** Options that store a page id. The manual emits the page slug instead. */
    public const PAGE_OPTIONS = ['page_on_front', 'page_for_posts'];

    /**
     * The names the manual may read after the filter, in OPTIONS order.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException When the filter returns something other than an array.
     */
    public static function names(): array
    {
        $filtered = apply_filters(self::FILTER, array_keys(self::OPTIONS));

        if (!is_array($filtered)) {
            throw new InvalidArgumentException(sprintf('%s must return an array.', esc_html(self::FILTER)));
        }

        // Walk our own list, not the filter's: only a name already here survives.
        return array_values(array_filter(
            array_keys(self::OPTIONS),
            static fn (string $name): bool => in_array($name, $filtered, true)
        ));
    }
}
