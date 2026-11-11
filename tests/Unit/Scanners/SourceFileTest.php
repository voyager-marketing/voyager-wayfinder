<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Scanners\SourceFile;

final class SourceFileTest extends TestCase
{
    /** A site laid out the way wp-env lays out the fixture. */
    private const ROOTS = [
        ['', '/var/www/html/wp-content'],
        ['mu-plugins', '/var/www/html/wp-content/mu-plugins'],
        ['plugins', '/var/www/html/wp-content/plugins'],
        ['themes', '/var/www/html/wp-content/themes'],
        ['core', '/var/www/html/wp-includes'],
        ['core', '/var/www/html/wp-admin'],
    ];

    public function testPathsInsideWpContentComeBackRelativeToIt(): void
    {
        $this->assertSame('mu-plugins/vwf-fixture-core.php', SourceFile::relative('/var/www/html/wp-content/mu-plugins/vwf-fixture-core.php', self::ROOTS));
        $this->assertSame('plugins/acme/acme.php', SourceFile::relative('/var/www/html/wp-content/plugins/acme/acme.php', self::ROOTS));
        $this->assertSame('themes/vwf-fixture/templates/single-service.html', SourceFile::relative('/var/www/html/wp-content/themes/vwf-fixture/templates/single-service.html', self::ROOTS));
        $this->assertSame('uploads/acme/config.php', SourceFile::relative('/var/www/html/wp-content/uploads/acme/config.php', self::ROOTS));
    }

    public function testCoreFilesAreCore(): void
    {
        $this->assertSame('core', SourceFile::relative('/var/www/html/wp-includes/post.php', self::ROOTS));
        $this->assertSame('core', SourceFile::relative('/var/www/html/wp-admin/includes/plugin.php', self::ROOTS));
    }

    public function testAnythingElseIsNullNeverAnAbsolutePath(): void
    {
        $this->assertNull(SourceFile::relative('/var/www/html/wp-config.php', self::ROOTS));
        $this->assertNull(SourceFile::relative('/var/www/html/wp-settings.php', self::ROOTS));
        $this->assertNull(SourceFile::relative('/etc/php/conf.d/acme.php', self::ROOTS));
        $this->assertNull(SourceFile::relative('/var/www/html/wp-content-backup/x.php', self::ROOTS), 'A sibling folder with the same prefix is not inside.');
        $this->assertNull(SourceFile::relative('/var/www/html/wp-content', self::ROOTS), 'The root itself is not a file in it.');
        $this->assertNull(SourceFile::relative('/anything.php', []));
    }

    public function testTheMostSpecificRootWinsWhateverOrderTheyAreListedIn(): void
    {
        $this->assertSame('mu-plugins/a.php', SourceFile::relative('/var/www/html/wp-content/mu-plugins/a.php', array_reverse(self::ROOTS)));
    }

    public function testPluginsMovedOutOfWpContentKeepTheirPrefix(): void
    {
        $roots = [['', '/srv/site/content'], ['plugins', '/srv/shared/plugins']];

        $this->assertSame('plugins/acme/acme.php', SourceFile::relative('/srv/shared/plugins/acme/acme.php', $roots));
    }

    public function testWindowsPathsAreNormalized(): void
    {
        $roots = [['', 'C:\\sites\\riverbend\\wp-content'], ['core', 'c:/sites/riverbend/wp-includes']];

        $this->assertSame('mu-plugins/vwf-fixture-core.php', SourceFile::relative('c:\\sites\\riverbend\\wp-content\\mu-plugins\\vwf-fixture-core.php', $roots));
        $this->assertSame('core', SourceFile::relative('C:\\sites\\riverbend\\wp-includes\\post.php', $roots));
        $this->assertSame('themes/a/b.html', SourceFile::relative('C:/sites//riverbend/wp-content/themes/a/b.html', $roots));
    }

    public function testACallableResolvesToTheFileThatDefinesIt(): void
    {
        $roots = [['tests', dirname(__DIR__, 2)]];
        $here = 'tests/Unit/Scanners/SourceFileTest.php';

        $this->assertSame($here, SourceFile::ofCallable(static fn (): string => '', $roots), 'closure');
        $this->assertSame($here, SourceFile::ofCallable([$this, 'testACallableResolvesToTheFileThatDefinesIt'], $roots), 'object method');
        $this->assertSame($here, SourceFile::ofCallable([self::class, 'helper'], $roots), 'static method array');
        $this->assertSame($here, SourceFile::ofCallable(self::class . '::helper', $roots), 'static method string');
        $invokable = new class () {
            public function __invoke(): string
            {
                return '';
            }
        };
        $this->assertSame($here, SourceFile::ofCallable($invokable, $roots), 'invokable object');
        $this->assertNull(SourceFile::ofCallable('strlen', $roots), 'A PHP built-in has no file.');
        $this->assertNull(SourceFile::ofCallable('vwf_no_such_function', $roots));
        $this->assertNull(SourceFile::ofCallable([self::class, 'noSuchMethod'], $roots));
        $this->assertNull(SourceFile::ofCallable(null, $roots));
        $this->assertNull(SourceFile::ofCallable(static fn (): string => '', []), 'Outside every root.');
    }

    public static function helper(): string
    {
        return '';
    }
}
