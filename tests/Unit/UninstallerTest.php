<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Voyager\Wayfinder\Drift\Fingerprint;
use Voyager\Wayfinder\Drift\Watcher;
use Voyager\Wayfinder\Manifest\SnapshotStore;
use Voyager\Wayfinder\Tests\Support\WpCalls;
use Voyager\Wayfinder\Uninstaller;

final class UninstallerTest extends TestCase
{
    /** Calls that store an option under the name in their first argument. */
    private const OPTION_WRITERS = ['update_option', 'add_option', 'update_site_option', 'add_site_option'];

    /** Calls that store a transient under the name in their first argument. */
    private const TRANSIENT_WRITERS = ['set_transient', 'set_site_transient'];

    /**
     * Writes whose name is a variable, by file, with the class whose `vwf_`
     * constants are the names that reach it. SnapshotStore writes through a
     * closure, `update_option($name, ...)`, called with its own two constants.
     */
    private const INDIRECT_WRITES = [
        'src/Manifest/SnapshotStore.php' => SnapshotStore::class,
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        WpCalls::reset();
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @var list<string> */
            public array $queries = [];

            public function prepare(string $query, string ...$args): string
            {
                return str_replace('%i', '`' . ($args[0] ?? '') . '`', $query);
            }

            public function query(string $query): int
            {
                $this->queries[] = $query;

                return 1;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testEveryOptionAndTransientTheCodeWritesIsRemovedOnUninstall(): void
    {
        ['options' => $options, 'transients' => $transients] = self::writes();

        // The scan has to find the writes we know about, or a broken pattern would pass.
        $this->assertContains(Fingerprint::OPTION, $options);
        $this->assertContains(SnapshotStore::SNAPSHOT_OPTION, $options);
        $this->assertContains(Watcher::CHECK_TRANSIENT, $transients);

        $missing = [
            ...array_map(static fn (string $name): string => "option {$name}", array_diff($options, Uninstaller::OPTIONS)),
            ...array_map(static fn (string $name): string => "transient {$name}", array_diff($transients, Uninstaller::TRANSIENTS)),
        ];

        $this->assertSame([], array_values($missing), 'src/ stores these but Uninstaller does not remove them. Add each to Uninstaller::OPTIONS or ::TRANSIENTS.');
    }

    public function testEveryRemovedNameIsAWayfinderNameListedOnce(): void
    {
        foreach ([...Uninstaller::OPTIONS, ...Uninstaller::TRANSIENTS] as $name) {
            $this->assertStringStartsWith('vwf_', $name);
        }

        $this->assertSame(array_values(array_unique(Uninstaller::OPTIONS)), Uninstaller::OPTIONS);
        $this->assertSame(array_values(array_unique(Uninstaller::TRANSIENTS)), Uninstaller::TRANSIENTS);
    }

    public function testASingleSiteLosesEveryOptionTransientAndTheNotesTable(): void
    {
        WpCalls::$options = array_fill_keys(Uninstaller::OPTIONS, 'x') + ['blogname' => 'Riverbend Plumbing'];

        Uninstaller::run();

        $this->assertSame(
            [
                ...array_map(static fn (string $name): string => 'option:' . $name, Uninstaller::OPTIONS),
                ...array_map(static fn (string $name): string => 'transient:' . $name, Uninstaller::TRANSIENTS),
            ],
            WpCalls::$deleted
        );
        $this->assertSame(['blogname' => 'Riverbend Plumbing'], WpCalls::$options, 'Only Wayfinder options go.');
        $this->assertSame(['DROP TABLE IF EXISTS `wp_vwf_notes`'], $GLOBALS['wpdb']->queries);
        $this->assertSame([], WpCalls::$blogSwitches);
    }

    public function testANetworkIsCleanedSiteBySite(): void
    {
        WpCalls::$multisite = true;
        WpCalls::$sites = [1, 3];

        Uninstaller::run();

        $this->assertSame(['switch:1', 'restore', 'switch:3', 'restore'], WpCalls::$blogSwitches);
        $this->assertSame(
            ['DROP TABLE IF EXISTS `wp_vwf_notes`', 'DROP TABLE IF EXISTS `wp_3_vwf_notes`'],
            $GLOBALS['wpdb']->queries
        );
        $this->assertCount(2 * (count(Uninstaller::OPTIONS) + count(Uninstaller::TRANSIENTS)), WpCalls::$deleted);
    }

    public function testUninstallFileRunsOnlyWhenWordPressUninstallsThePlugin(): void
    {
        $code = (string) file_get_contents(self::root() . '/uninstall.php');

        $guard = strpos($code, "if (!defined('WP_UNINSTALL_PLUGIN'))");
        $run = strpos($code, 'Voyager\\Wayfinder\\Uninstaller::run();');

        $this->assertNotFalse($guard);
        $this->assertNotFalse($run);
        $this->assertLessThan($run, $guard, 'The guard comes before the uninstaller runs.');
    }

    public function testTheZipKeepsUninstallPhp(): void
    {
        $lines = file(self::root() . '/.distignore', FILE_IGNORE_NEW_LINES) ?: [];
        $rules = array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== '' && $line[0] !== '#');

        // The control: the same rules do drop a file we know is left out.
        $this->assertTrue(self::ignored('/AGENTS.md', $rules));
        $this->assertFalse(self::ignored('/uninstall.php', $rules), '.distignore must not drop uninstall.php.');
    }

    /**
     * Whether an rsync-style rule list drops a root-level path. Enough of
     * rsync for .distignore: anchored `/name`, bare `name`, and `*` globs.
     *
     * @param array<string> $rules
     */
    private static function ignored(string $path, array $rules): bool
    {
        foreach ($rules as $rule) {
            $pattern = str_starts_with($rule, '/') ? $rule : '/' . $rule;

            if (fnmatch($pattern, $path) || fnmatch('/' . ltrim($rule, '/'), '/' . basename($path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The option and transient names src/ writes, read from the source: the
     * first argument of each write (the second of register_setting()), as a
     * literal or a class constant. A write whose name is anything else fails
     * the test unless INDIRECT_WRITES says where its names come from.
     *
     * @return array{options: list<string>, transients: list<string>}
     */
    private static function writes(): array
    {
        $options = [];
        $transients = [];
        $writers = implode('|', [...self::OPTION_WRITERS, ...self::TRANSIENT_WRITERS]);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/src', RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'src/' . str_replace('\\', '/', substr($file->getPathname(), strlen(self::root() . '/src/')));
            $code = self::withoutComments((string) file_get_contents($file->getPathname()));

            $calls = [];
            preg_match_all('/\b(' . $writers . ')\s*\(\s*([^,()]+?)\s*,/', $code, $found, PREG_SET_ORDER);
            foreach ($found as [, $function, $argument]) {
                $calls[] = [$function, $argument];
            }
            preg_match_all('/\bregister_setting\s*\(\s*[^,()]+?\s*,\s*([^,()]+?)\s*,/', $code, $found, PREG_SET_ORDER);
            foreach ($found as [, $argument]) {
                $calls[] = ['register_setting', $argument];
            }

            foreach ($calls as [$function, $argument]) {
                $name = self::resolve($argument, $code);

                if ($name !== null) {
                    $names = [$name];
                } elseif (isset(self::INDIRECT_WRITES[$relative])) {
                    $constants = (new ReflectionClass(self::INDIRECT_WRITES[$relative]))->getConstants();
                    $names = array_values(array_filter($constants, static fn (mixed $value): bool => is_string($value) && str_starts_with($value, 'vwf_')));
                } else {
                    self::fail("{$relative}: {$function}({$argument}, ...) stores a name this test cannot read. Use a literal or a class constant, or add the file to INDIRECT_WRITES.");
                }

                foreach ($names as $stored) {
                    if (in_array($function, self::TRANSIENT_WRITERS, true)) {
                        $transients[] = $stored;
                    } else {
                        $options[] = $stored;
                    }
                }
            }
        }

        return ['options' => array_values(array_unique($options)), 'transients' => array_values(array_unique($transients))];
    }

    /** The name a write's argument stands for, or null when it is not a literal or a constant. */
    private static function resolve(string $argument, string $code): ?string
    {
        if (preg_match('/^\'([^\'\\\\]*)\'$/', $argument, $m) === 1 || preg_match('/^"([^"$\\\\]*)"$/', $argument, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^(\\\\?[A-Za-z_][\w\\\\]*)::([A-Z_][A-Z0-9_]*)$/', $argument, $m) !== 1) {
            return null;
        }

        preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $code, $namespace);
        preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $class);
        $namespace = $namespace[1] ?? '';

        $uses = [];
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $imports, PREG_SET_ORDER);
        foreach ($imports as $import) {
            $alias = ($import[2] ?? '') !== '' ? $import[2] : substr((string) strrchr('\\' . $import[1], '\\'), 1);
            $uses[$alias] = $import[1];
        }

        [$head] = explode('\\', $m[1], 2);
        $fqcn = match (true) {
            $m[1] === 'self', $m[1] === 'static' => $namespace . '\\' . ($class[1] ?? ''),
            str_starts_with($m[1], '\\')        => ltrim($m[1], '\\'),
            isset($uses[$head])                  => $uses[$head] . substr($m[1], strlen($head)),
            default                              => $namespace . '\\' . $m[1],
        };

        $value = constant($fqcn . '::' . $m[2]);

        return is_string($value) ? $value : null;
    }

    /** PHP source with comments blanked, keeping line breaks. */
    private static function withoutComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
