<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support;

/**
 * What the stubbed WordPress functions in tests/wp-stubs.php were called with.
 */
final class WpCalls
{
    /** @var list<array{hook: string, callback: callable, priority: int, args: int}> */
    public static array $actions = [];

    /** @var list<array{page_title: string, menu_title: string, capability: string, slug: string, callback: callable|null, icon: string}> */
    public static array $menuPages = [];

    /** @var list<array{hook: string, value: mixed}> */
    public static array $filters = [];

    /** @var array<string, callable(mixed): mixed> What apply_filters() runs the value through, by hook. */
    public static array $filterCallbacks = [];

    /** @var list<array{function: string, message: string, version: string}> _doing_it_wrong() calls, in order. */
    public static array $doingItWrong = [];

    /** @var array<string, array<string, mixed>> wp_register_ability_category() calls, by slug. */
    public static array $abilityCategories = [];

    /** @var array<string, array<string, mixed>> wp_register_ability() calls, by name. */
    public static array $abilities = [];

    /** @var array<string, mixed> What get_option() returns, by option name. Missing names get the default. */
    public static array $options = [];

    /** @var list<string> Capabilities current_user_can() grants. */
    public static array $capabilities = [];

    /** @var array<string, array<int, array{callback: callable, priority: int}>> add_filter() calls, by hook, minus remove_filter(). */
    public static array $hooks = [];

    /** @var list<string> delete_option() and delete_transient() calls, as `option:<name>` and `transient:<name>`, in order. */
    public static array $deleted = [];

    /** rest_get_server() calls, which read the REST server and so fire rest_api_init on a real site. */
    public static int $restServerReads = 0;

    /** What is_multisite() returns. */
    public static bool $multisite = false;

    /** @var list<int> Site ids get_sites() returns. */
    public static array $sites = [];

    /** @var list<string> switch_to_blog() and restore_current_blog() calls, as `switch:<id>` and `restore`, in order. */
    public static array $blogSwitches = [];

    public static function reset(): void
    {
        self::$restServerReads = 0;
        self::$deleted = [];
        self::$multisite = false;
        self::$sites = [];
        self::$blogSwitches = [];
        self::$actions = [];
        self::$menuPages = [];
        self::$filters = [];
        self::$filterCallbacks = [];
        self::$doingItWrong = [];
        self::$abilityCategories = [];
        self::$abilities = [];
        self::$options = [];
        self::$capabilities = [];
        self::$hooks = [];
    }
}
