<?php

/**
 * The ACF functions src/ calls, for PHPStan only. Never loaded at runtime.
 *
 * A local stub rather than a stubs package, to keep the dev dependency list
 * unchanged. Signatures follow ACF 6.
 *
 * @package Voyager\Wayfinder
 */

/**
 * @param array<string, mixed> $filter
 * @return list<array<string, mixed>>
 */
function acf_get_field_groups($filter = [])
{
    return [];
}

/**
 * @param array<string, mixed>|int|string $parent
 * @return list<array<string, mixed>>|false
 */
function acf_get_fields($parent)
{
    return [];
}

/**
 * ACF PRO only. Keyed by menu slug; false when there are none.
 *
 * @return array<string, array<string, mixed>>|false
 */
function acf_get_options_pages()
{
    return [];
}
