<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Scanners\ContentModelScanner;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\Registrations;
use Voyager\Wayfinder\Scanners\SourceFile;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;

/**
 * AGENTS.md rule 3: no absolute server path in the manifest. Every path the
 * manifest carries (`file`, `defined_in`) starts life as an absolute one, so
 * this builds the content model and layout sections from absolute inputs, a
 * Linux site and a Windows site, and checks every string in the result.
 */
final class NoAbsolutePathTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function sites(): array
    {
        return [
            'linux'   => ['/var/www/html/'],
            'windows' => ['C:\\sites\\riverbend\\'],
        ];
    }

    /**
     * @dataProvider sites
     */
    public function testNoStringInTheManifestIsAnAbsolutePath(string $abspath): void
    {
        $manifest = self::manifest($abspath);
        $strings = self::strings($manifest->toArray());

        // The paths made it in, relative.
        $this->assertContains('mu-plugins/vwf-fixture-core.php', $strings);
        $this->assertContains('themes/vwf-fixture/templates/single-service.html', $strings);
        $this->assertContains('core', $strings);

        foreach ($strings as $value) {
            $this->assertFalse(self::looksAbsolute($value, $abspath), sprintf('Absolute path in the manifest: %s', $value));
        }
    }

    /**
     * @dataProvider sites
     */
    public function testTheCheckCatchesAnAbsolutePath(string $abspath): void
    {
        $leaky = new Manifest([new Section('layout', [
            new Item('tpl:leak', 'template', 'Leak', ['file' => $abspath . 'wp-content/themes/vwf-fixture/templates/leak.html']),
        ])]);

        $caught = array_filter(
            self::strings($leaky->toArray()),
            static fn (string $value): bool => self::looksAbsolute($value, $abspath)
        );

        $this->assertCount(1, $caught);
    }

    private static function manifest(string $abspath): Manifest
    {
        $roots = [
            ['', $abspath . 'wp-content'],
            ['mu-plugins', $abspath . 'wp-content/mu-plugins'],
            ['plugins', $abspath . 'wp-content/plugins'],
            ['themes', $abspath . 'wp-content/themes'],
            ['core', $abspath . 'wp-includes'],
            ['core', $abspath . 'wp-admin'],
        ];

        $muPlugin = $abspath . 'wp-content/mu-plugins/vwf-fixture-core.php';
        $registeredBy = static fn (string $file): ?string => Registrations::caller([
            ['function' => 'do_action', 'file' => $abspath . 'wp-includes/post.php'],
            ['function' => 'register_post_type', 'file' => $file],
        ], $roots);

        $postTypes = static fn (): array => [
            self::postType('service', $registeredBy($muPlugin)),
            self::postType('legacy', $registeredBy('/opt/shared/legacy-types.php')),
            self::postType('post', SourceFile::CORE),
        ];
        $taxonomies = static fn (): array => [[
            'slug'         => 'service_area',
            'label'        => 'Service areas',
            'object_types' => ['service'],
            'public'       => true,
            'show_ui'      => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'terms'        => 5,
            'defined_in'   => $registeredBy($muPlugin),
        ]];

        $layout = FakeLayoutSource::fixture();
        foreach ([&$layout->templates, &$layout->parts] as &$rows) {
            foreach ($rows as &$row) {
                $folder = $row['area'] === '' ? 'templates' : 'parts';
                $row['file'] = SourceFile::relative($abspath . 'wp-content/themes/vwf-fixture/' . $folder . '/' . $row['slug'] . '.html', $roots);
            }
            unset($row);
        }
        unset($rows);
        $layout->sourceFiles = ['vwf-fixture/business-info' => SourceFile::relative($muPlugin, $roots)];

        return new Manifest([
            (new ContentModelScanner($postTypes, $taxonomies, static fn (): array => [], static fn (): ?array => null))->scan(),
            (new LayoutScanner($layout))->scan(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function postType(string $slug, ?string $definedIn): array
    {
        return [
            'slug'         => $slug,
            'label'        => ucfirst($slug),
            'menu_name'    => ucfirst($slug),
            'public'       => true,
            'show_ui'      => true,
            'has_archive'  => false,
            'rewrite_slug' => null,
            'supports'     => ['title'],
            'hierarchical' => false,
            'show_in_rest' => true,
            'counts'       => ['publish' => 1],
            'defined_in'   => $definedIn,
        ];
    }

    private static function looksAbsolute(string $value, string $abspath): bool
    {
        $normalized = str_replace('\\', '/', $value);

        return str_starts_with($normalized, '/')
            || preg_match('#^[A-Za-z]:/#', $normalized) === 1
            || str_contains($normalized, rtrim(str_replace('\\', '/', $abspath), '/'));
    }

    /**
     * Every string in a nested array, keys included.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $strings[] = $key;
            }

            array_push($strings, ...self::strings($child));
        }

        return $strings;
    }
}
