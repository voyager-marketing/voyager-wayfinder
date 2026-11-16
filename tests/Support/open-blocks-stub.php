<?php

/**
 * The stub site_data_plan() OpenBlocksStub names. Loaded by
 * OpenBlocksStub::load() only, so it exists only for the tests that ask.
 *
 * @package Voyager\Wayfinder
 */

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support\OpenBlocks;

use Voyager\Wayfinder\Tests\Support\OpenBlocksStub;

/**
 * @param list<string> $keys
 * @return array<array-key, mixed>|null
 */
function site_data_plan(array $keys): ?array
{
    return OpenBlocksStub::answer($keys);
}
