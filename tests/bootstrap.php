<?php

/**
 * PHPUnit bootstrap for the unit suite. No WordPress is loaded: the few
 * WordPress functions the code under test calls are stubbed and recorded.
 *
 * @package Voyager\Wayfinder
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-stubs.php';
