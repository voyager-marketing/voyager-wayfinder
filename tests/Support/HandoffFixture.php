<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support;

use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Render\Export\Handoff;

/**
 * The fixture manifest with a header and two routes, ready for the export
 * tests. The single post route uses the parent theme's single template, so
 * that template and the registered pattern it uses are listed; the parent
 * index template and the sidebar part stay folded.
 */
final class HandoffFixture
{
    public const ADMIN = 'https://example.test/wp-admin/';

    public const GENERATED_AT = '2026-10-03T22:15:07Z';

    /**
     * @param array<string, list<Item>>                                  $extra Items to add, by section.
     * @param array<string, list<array{body: string, status: string}>> $notes By item id, as AgencyNotes shapes them.
     */
    public static function handoff(array $extra = [], array $notes = [], bool $shuffle = false, string $siteName = 'Riverbend Plumbing'): Handoff
    {
        return new Handoff(self::manifest($extra, $shuffle), $notes, $siteName, self::ADMIN);
    }

    /**
     * @param array<string, list<Item>> $extra
     */
    public static function manifest(array $extra = [], bool $shuffle = false): Manifest
    {
        $routes = ['routes' => [
            new Item('route:single-post', 'route', 'Single post', ['path' => '/hello-world/'], null, ['pt:post', 'tpl:single']),
            new Item('route:front-page', 'route', 'Front page', ['path' => '/'], 'options-reading.php', ['pat:cta-banner', 'pt:page']),
        ]];

        $fixture = FixtureManifest::manifest(array_merge_recursive($routes, $extra), $shuffle);

        return new Manifest($fixture->sections, [
            'generated_at'   => self::GENERATED_AT,
            'plugin_version' => '0.2.0',
            'site_url'       => 'https://example.test',
        ]);
    }
}
