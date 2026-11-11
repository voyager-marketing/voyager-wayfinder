<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Voyager\Wayfinder\Scanners\ReadTracer;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class ReadTracerTest extends TestCase
{
    private const ROOTS = [
        ['', '/var/www/html/wp-content'],
        ['core', '/var/www/html/wp-includes'],
        ['core', '/var/www/html/wp-admin'],
    ];

    /** How core calls a binding source's callback, outermost last. */
    private const ENTRY = [
        ['function' => 'call_user_func_array', 'file' => '/var/www/html/wp-includes/class-wp-block-bindings-source.php'],
        ['function' => 'get_value', 'class' => 'WP_Block_Bindings_Source', 'file' => '/var/www/html/wp-content/plugins/voyager-wayfinder/src/Scanners/WordPressLayoutSource.php'],
    ];

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testAReadInTheSourcesOwnCodeCounts(): void
    {
        $trace = [
            ['function' => 'onOption', 'class' => ReadTracer::class, 'file' => '/var/www/html/wp-includes/class-wp-hook.php'],
            ['function' => 'apply_filters', 'class' => 'WP_Hook', 'file' => '/var/www/html/wp-includes/plugin.php'],
            ['function' => 'apply_filters', 'file' => '/var/www/html/wp-includes/option.php'],
            ['function' => 'get_option', 'file' => '/var/www/html/wp-content/mu-plugins/vwf-fixture-core.php'],
            ['function' => 'vwf_fixture_binding_value'],
            ...self::ENTRY,
        ];

        $this->assertTrue(ReadTracer::readOutsideCore($trace, ['get_option'], self::ROOTS));
    }

    public function testAReadCoreMakesForItselfDoesNotCount(): void
    {
        // The source calls home_url(), and core reads `home`.
        $trace = [
            ['function' => 'apply_filters', 'file' => '/var/www/html/wp-includes/option.php'],
            ['function' => 'get_option', 'file' => '/var/www/html/wp-includes/link-template.php'],
            ['function' => 'get_home_url', 'file' => '/var/www/html/wp-includes/link-template.php'],
            ['function' => 'home_url', 'file' => '/var/www/html/wp-content/mu-plugins/acme.php'],
            ['function' => 'acme_value'],
            ...self::ENTRY,
        ];

        $this->assertFalse(ReadTracer::readOutsideCore($trace, ['get_option'], self::ROOTS));
    }

    public function testAReadAFilterMakesInsideACoreFunctionDoesNotCount(): void
    {
        // WP-CLI filters home_url and reads `home` itself; REST does not, so counting it would split the hash.
        $trace = [
            ['function' => 'apply_filters', 'file' => '/var/www/html/wp-includes/option.php'],
            ['function' => 'get_option', 'file' => 'phar:///usr/local/bin/wp/vendor/wp-cli/wp-cli/php/WP_CLI/Runner.php'],
            ['function' => '{closure}', 'class' => 'WP_CLI\\Runner', 'file' => '/var/www/html/wp-includes/class-wp-hook.php'],
            ['function' => 'apply_filters', 'class' => 'WP_Hook', 'file' => '/var/www/html/wp-includes/plugin.php'],
            ['function' => 'apply_filters', 'file' => '/var/www/html/wp-includes/link-template.php'],
            ['function' => 'get_home_url', 'file' => '/var/www/html/wp-includes/link-template.php'],
            ['function' => 'home_url', 'file' => '/var/www/html/wp-content/mu-plugins/acme.php'],
            ['function' => 'acme_value'],
            ...self::ENTRY,
        ];

        $this->assertFalse(ReadTracer::readOutsideCore($trace, ['get_option'], self::ROOTS));
    }

    public function testAMetaReadIsJudgedFromTheOutermostReaderCall(): void
    {
        $direct = [
            ['function' => 'apply_filters', 'file' => '/var/www/html/wp-includes/meta.php'],
            ['function' => 'get_metadata_raw', 'file' => '/var/www/html/wp-includes/meta.php'],
            ['function' => 'get_metadata', 'file' => '/var/www/html/wp-includes/post.php'],
            ['function' => 'get_post_meta', 'file' => '/var/www/html/wp-content/plugins/acme/bindings.php'],
            ['function' => 'acme_value'],
            ...self::ENTRY,
        ];
        $throughCore = [
            ['function' => 'apply_filters', 'file' => '/var/www/html/wp-includes/meta.php'],
            ['function' => 'get_metadata_raw', 'file' => '/var/www/html/wp-includes/meta.php'],
            ['function' => 'get_metadata', 'file' => '/var/www/html/wp-includes/post.php'],
            ['function' => 'get_post_meta', 'file' => '/var/www/html/wp-includes/post-thumbnail-template.php'],
            ['function' => 'get_post_thumbnail_id', 'file' => '/var/www/html/wp-content/plugins/acme/bindings.php'],
            ['function' => 'acme_value'],
            ...self::ENTRY,
        ];

        $readers = ['get_metadata_raw', 'get_metadata', 'get_post_meta', 'metadata_exists'];

        $this->assertTrue(ReadTracer::readOutsideCore($direct, $readers, self::ROOTS));
        $this->assertFalse(ReadTracer::readOutsideCore($throughCore, $readers, self::ROOTS));
    }

    public function testAReaderPhpCallsItselfCountsAndAStackWithNoReaderDoesNot(): void
    {
        $viaArrayMap = [
            ['function' => 'get_option'],
            ['function' => 'array_map', 'file' => '/var/www/html/wp-content/mu-plugins/acme.php'],
            ['function' => 'acme_value'],
            ...self::ENTRY,
        ];

        $this->assertTrue(ReadTracer::readOutsideCore($viaArrayMap, ['get_option'], self::ROOTS));
        $this->assertFalse(ReadTracer::readOutsideCore(self::ENTRY, ['get_option'], self::ROOTS));
    }

    public function testTraceRecordsOptionAndMetaReadsSorted(): void
    {
        $reads = ReadTracer::trace(static function (): void {
            get_option('vwf_phone');
            get_post_meta(90020, 'price_from', true);
            get_option('vwf_address');
            get_option('vwf_phone');
            get_post_meta(90020);
        });

        $this->assertSame(['meta:price_from', 'option:vwf_address', 'option:vwf_phone'], $reads);
    }

    public function testATracedValueIsPassedThroughUntouched(): void
    {
        WpCalls::$options = ['vwf_phone' => '555-0142'];
        $seen = null;

        ReadTracer::trace(static function () use (&$seen): void {
            $seen = get_option('vwf_phone');
        });

        $this->assertSame('555-0142', $seen);
    }

    public function testAThrowingCallbackLosesOnlyItsReadsAndLeavesNoFilterBehind(): void
    {
        $reads = ReadTracer::trace(static function (): void {
            get_option('vwf_phone');
            throw new RuntimeException('boom');
        });

        $this->assertNull($reads);
        $this->assertSame([], WpCalls::$hooks['pre_option'] ?? []);
        $this->assertSame([], WpCalls::$hooks['get_post_metadata'] ?? []);
        $this->assertSame(['option:vwf_phone'], ReadTracer::trace(static fn () => get_option('vwf_phone')), 'The next trace starts clean.');
    }

    public function testOutputIsDiscarded(): void
    {
        $level = ob_get_level();

        ob_start();
        ReadTracer::trace(static function (): void {
            echo 'from the callback';
            ob_start();
            echo 'from a buffer it left open';
        });
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
        $this->assertSame($level, ob_get_level());
    }

    public function testStatedIdsAreValidatedSortedAndUnique(): void
    {
        $this->assertSame(
            ['meta:price_from', 'option:vwf_phone'],
            ReadTracer::ids(['option:vwf_phone', 'meta:price_from', 'option:vwf_phone', 'vwf_phone', 'option:', 'option:a b', 'term:x', 'option:a|b', 42, null])
        );
    }
}
