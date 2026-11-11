<?php

/**
 * The WP_CLI methods src/ calls, for PHPStan only. Never loaded at runtime.
 *
 * A local stub rather than php-stubs/wp-cli-stubs, to keep the dev
 * dependency list unchanged.
 *
 * @package Voyager\Wayfinder
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- mirrors the real WP_CLI class

class WP_CLI
{
    /**
     * @param string                $name
     * @param callable|class-string $callable
     * @param array<string, mixed>  $args
     */
    public static function add_command($name, $callable, $args = []): bool
    {
        return true;
    }

    /**
     * @param string $message
     */
    public static function line($message = ''): void
    {
    }

    /**
     * @param string $message
     */
    public static function log($message): void
    {
    }

    /**
     * @param string $message
     */
    public static function success($message): void
    {
    }

    /**
     * @param string $message
     */
    public static function warning($message): void
    {
    }

    /**
     * @param string $message
     * @param bool   $exit
     * @return never
     */
    public static function error($message, $exit = true)
    {
        exit(1);
    }
}
