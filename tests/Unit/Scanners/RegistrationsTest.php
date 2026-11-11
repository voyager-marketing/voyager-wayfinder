<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Scanners\Registrations;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class RegistrationsTest extends TestCase
{
    private const ROOTS = [
        ['', '/var/www/html/wp-content'],
        ['mu-plugins', '/var/www/html/wp-content/mu-plugins'],
        ['core', '/var/www/html/wp-includes'],
        ['core', '/var/www/html/wp-admin'],
    ];

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testListenHooksBothRegistrationActions(): void
    {
        Registrations::listen();

        $this->assertSame(
            [
                ['registered_post_type', [Registrations::class, 'postTypeRegistered']],
                ['registered_taxonomy', [Registrations::class, 'taxonomyRegistered']],
            ],
            array_map(static fn (array $action): array => [$action['hook'], $action['callback']], WpCalls::$actions)
        );
    }

    public function testTheCallerIsTheFirstFrameOutsideCore(): void
    {
        // The stack as it stands inside the `registered_post_type` action on the fixture site.
        $trace = [
            ['function' => 'postTypeRegistered', 'class' => Registrations::class, 'file' => '/var/www/html/wp-includes/class-wp-hook.php'],
            ['function' => 'apply_filters', 'class' => 'WP_Hook', 'file' => '/var/www/html/wp-includes/class-wp-hook.php'],
            ['function' => 'do_action', 'class' => 'WP_Hook', 'file' => '/var/www/html/wp-includes/plugin.php'],
            ['function' => 'do_action', 'file' => '/var/www/html/wp-includes/post.php'],
            ['function' => 'register_post_type', 'file' => '/var/www/html/wp-content/mu-plugins/vwf-fixture-core.php'],
            ['function' => 'vwf_fixture_register', 'file' => '/var/www/html/wp-includes/class-wp-hook.php'],
            ['function' => 'do_action', 'file' => '/var/www/html/wp-settings.php'],
        ];

        $this->assertSame('mu-plugins/vwf-fixture-core.php', Registrations::caller($trace, self::ROOTS));
    }

    public function testFramesWithoutAFileAndWayfindersOwnAreSkipped(): void
    {
        $trace = [
            ['function' => 'call_user_func'],
            ['function' => 'helper', 'file' => dirname(__DIR__, 3) . '/src/Scanners/Registrations.php'],
            ['function' => 'register_taxonomy', 'file' => '/var/www/html/wp-content/mu-plugins/acme.php'],
        ];

        $this->assertSame('mu-plugins/acme.php', Registrations::caller($trace, self::ROOTS));
    }

    public function testNoGuessWhenTheCallerIsOutsideWpContentOrEveryFrameIsCore(): void
    {
        $outside = [
            ['function' => 'register_post_type', 'file' => '/var/www/html/wp-includes/post.php'],
            ['function' => 'create_initial_post_types', 'file' => '/var/www/html/wp-settings.php'],
        ];
        $allCore = [
            ['function' => 'register_post_type', 'file' => '/var/www/html/wp-includes/post.php'],
        ];

        $this->assertNull(Registrations::caller($outside, self::ROOTS));
        $this->assertNull(Registrations::caller($allCore, self::ROOTS));
        $this->assertNull(Registrations::caller([], self::ROOTS));
    }

    public function testATypeNobodySawRegisterHasNoFile(): void
    {
        $this->assertNull(Registrations::postType('vwf_never_registered'));
        $this->assertNull(Registrations::taxonomy('vwf_never_registered'));
    }
}
