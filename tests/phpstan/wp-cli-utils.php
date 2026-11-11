<?php

/**
 * The WP_CLI\Utils functions src/ calls, for PHPStan only. Never loaded at
 * runtime. Kept apart from wp-cli.php because a namespaced function cannot
 * share a file with the unbracketed global WP_CLI class.
 *
 * @package Voyager\Wayfinder
 */

namespace WP_CLI\Utils;

/**
 * @param string                                     $format
 * @param array<int, array<string, mixed>|object>    $items
 * @param array<int, string>|string                  $fields
 */
function format_items($format, $items, $fields): void
{
}
