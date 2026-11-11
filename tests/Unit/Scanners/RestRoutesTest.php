<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\Compact;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Scanners\CodeScanner;
use Voyager\Wayfinder\Scanners\CodeSource;
use Voyager\Wayfinder\Scanners\PhpSinks;
use Voyager\Wayfinder\Scanners\RestRoutes;
use Voyager\Wayfinder\Scanners\Scanner;
use Voyager\Wayfinder\Scanners\ScannerRegistry;
use Voyager\Wayfinder\Scanners\SiteScanner;
use Voyager\Wayfinder\Tests\Support\FakeCodeSource;
use Voyager\Wayfinder\Tests\Support\FixedSite;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;
use Voyager\Wayfinder\Tests\Support\WpCalls;

/**
 * REST routes in the code section, from a fake route registry
 * over PHP source strings: what a theme route's handler does, a plugin
 * route named but not read, core's left out, and the REST server read
 * before any scanner so the site section does not depend on scanner order.
 */
final class RestRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testAThemeRouteNamesItsHandlerFileAndWhatItDoes(): void
    {
        $item = $this->item('rest:acme/v1/contact');

        $this->assertSame('rest_route', $item->kind);
        $this->assertSame('/acme/v1/contact', $item->label);
        $this->assertSame('acme/v1', $item->facts['namespace']);
        $this->assertSame(['kind' => 'theme', 'slug' => 'acme'], $item->facts['owner']);

        $endpoint = $item->facts['endpoints'][0];
        $this->assertSame('acme_contact_handle', $endpoint['handler']);
        $this->assertSame('themes/acme/inc/contact.php', $endpoint['file']);
        $this->assertSame(['POST'], $endpoint['methods']);
        $this->assertSame(CodeSource::PUBLIC, $endpoint['permission']);
        $this->assertTrue($endpoint['traced']);
        $this->assertSame(['an Orbit lead'], $endpoint['records'], 'The lead is recorded in Orbit, two calls down, through Module::instance()->insertLead().');
        $this->assertSame([['declared_in' => 'themes/acme/inc/contact.php', 'name' => 'ACME_CONTACT_NOTIFY', 'to' => 'constant']], $endpoint['mails']);
        $this->assertSame('plugin:postmark-approved-wordpress-plugin', $endpoint['mail_through'], 'wp_mail() reflects to the plugin that replaces it.');
        $this->assertSame([['action' => 'acme_contact_submit', 'listeners' => []]], $endpoint['fires']);
        $this->assertContains('plugin:postmark-approved-wordpress-plugin', $item->refs);
    }

    public function testNoConstantValueOrAddressReachesTheItemOrItsStats(): void
    {
        $section = $this->scan();
        $json = CanonicalJson::encode([$section->toArray(), $section->stats()]);

        $this->assertStringContainsString('ACME_CONTACT_NOTIFY', $json);
        $this->assertStringNotContainsString('owner@example.com', $json);
        $this->assertStringNotContainsString('@example.com', $json, 'Not even the literal address core\'s own route mails.');
    }

    public function testLinesSitInStatsSoAnEditAboveTheHandlerMovesNoHash(): void
    {
        $item = $this->item('rest:acme/v1/contact');
        $lines = $item->stats['lines'][0];

        $this->assertSame('themes/acme/inc/contact.php:7-15', $lines['handler']);
        $this->assertSame(['ACME_CONTACT_NOTIFY' => 'themes/acme/inc/contact.php:5'], $lines['calls']['constants']);
        $this->assertSame(['themes/acme/inc/contact.php:23'], $lines['calls']['mail']);
        $this->assertStringNotContainsString(':7', CanonicalJson::encode($item->body()), 'Line numbers stay out of the hashed body.');

        $source = FakeCodeSource::fixture();
        $source->files['themes/acme/inc/contact.php'] = str_replace("<?php\n", "<?php\n\n\n// A comment added above.\n", $source->files['themes/acme/inc/contact.php']);
        $source->endpoints[1]['callback'] = $source->callback('acme_contact_handle');
        $moved = $this->item('rest:acme/v1/contact', $source);

        $this->assertSame('themes/acme/inc/contact.php:10-18', $moved->stats['lines'][0]['handler']);
        $this->assertSame($item->hash, $moved->hash);
    }

    public function testARouteThatRecordsALeadAndMailsNoOneSaysSoWithinWhatItFollows(): void
    {
        $endpoint = $this->item('rest:acme/v1/signup')->facts['endpoints'][0];

        $this->assertTrue($endpoint['traced']);
        $this->assertSame(['an Orbit lead'], $endpoint['records']);
        $this->assertSame([], $endpoint['mails']);
        $this->assertArrayNotHasKey('mail_through', $endpoint);
        $this->assertStringContainsString('sends no mail in the code it follows', Facts::describe($this->item('rest:acme/v1/signup')));
    }

    public function testAPluginRouteIsNamedButItsCodeIsNotRead(): void
    {
        $item = $this->item('rest:acme-seo/v1/settings');
        $endpoint = $item->facts['endpoints'][0];

        $this->assertSame(['kind' => 'plugin', 'slug' => 'acme-seo'], $item->facts['owner']);
        $this->assertSame('Acme\Seo\Rest::settings', $endpoint['handler']);
        $this->assertSame('plugins/acme-seo/src/Rest.php', $endpoint['file']);
        $this->assertSame(['GET', 'POST'], $endpoint['methods']);
        $this->assertSame(CodeSource::CHECKED, $endpoint['permission']);
        $this->assertFalse($endpoint['traced']);
        $this->assertArrayNotHasKey('mails', $endpoint, 'Its handler calls wp_mail(), but a plugin is not read, so nothing is claimed either way.');
        $this->assertSame(['plugin:acme-seo'], $item->refs);
    }

    public function testCoreRoutesAndCallbacksNoFileCanPlaceAreLeftOut(): void
    {
        $source = FakeCodeSource::fixture();
        $source->endpoints[] = FakeCodeSource::endpoint('/acme/v1/builtin', 'acme/v1', ['GET'], ['name' => 'phpinfo', 'file' => null, 'start' => null, 'end' => null], CodeSource::PUBLIC);

        $ids = array_map(static fn (Item $item): string => $item->id, $this->scan($source)->items);

        $this->assertNotContains('rest:wp/v2/posts', $ids);
        $this->assertNotContains('rest:acme/v1/builtin', $ids);
        $this->assertContains('rest:acme/v1/contact', $ids);
    }

    public function testAMuPluginRouteIsReadLikeTheTheme(): void
    {
        $source = FakeCodeSource::fixture();
        $source->files['mu-plugins/acme-forms.php'] = "<?php\nfunction acme_quote_handle( \$request ) {\n    \$to = apply_filters( 'acme_quote_to', get_option( 'admin_email' ) );\n    wp_mail( \$to, 'Quote', 'Body' );\n    wp_insert_post( array( 'post_type' => 'quote', 'post_status' => 'private' ) );\n    return wp_safe_redirect( home_url( '/thanks/' ) );\n}\n";
        $source->endpoints[] = FakeCodeSource::endpoint('/acme-forms/v1/quote', 'acme-forms/v1', ['POST'], $source->callback('acme_quote_handle'), CodeSource::MISSING);
        $source->listeners['acme_quote_sent'] = [];

        $item = $this->item('rest:acme-forms/v1/quote', $source);
        $endpoint = $item->facts['endpoints'][0];

        $this->assertSame(['kind' => 'mu-plugin', 'slug' => 'acme-forms.php'], $item->facts['owner']);
        $this->assertTrue($endpoint['traced']);
        $this->assertSame([['name' => 'acme_quote_to', 'to' => 'filter']], $endpoint['mails']);
        $this->assertSame(['quote'], $endpoint['creates_posts']);
        $this->assertTrue($endpoint['redirects']);
        $this->assertSame(CodeSource::MISSING, $endpoint['permission']);
        $this->assertStringContainsString('public (no permission callback)', Facts::describe($item));
    }

    public function testRecipientsAreNamedByWhereTheyComeFromNeverByValue(): void
    {
        $source = FakeCodeSource::fixture();
        $source->files['themes/acme/inc/quote.php'] = <<<'PHP'
            <?php
            function acme_mail_option() {
                $to = get_option( 'admin_email' );
                return wp_mail( $to, 'x', 'y' );
            }
            function acme_mail_literal() {
                return wp_mail( 'sales@example.com', 'x', 'y' );
            }
            function acme_mail_param( $to ) {
                return wp_mail( $to, 'x', 'y' );
            }
            function acme_mail_bloginfo() {
                return wp_mail( get_bloginfo( 'admin_email' ), 'x', 'y' );
            }
            PHP;

        $expected = [
            'acme_mail_option'   => [['name' => 'admin_email', 'to' => 'option']],
            'acme_mail_literal'  => [['to' => 'address in code']],
            'acme_mail_param'    => [['to' => 'not traced']],
            'acme_mail_bloginfo' => [['name' => 'admin_email', 'to' => 'option']],
        ];

        foreach (array_keys($expected) as $handler) {
            $source->endpoints[] = FakeCodeSource::endpoint('/acme/v1/' . $handler, 'acme/v1', ['POST'], $source->callback($handler), CodeSource::PUBLIC);
        }

        $section = $this->scan($source);

        foreach ($expected as $handler => $mails) {
            $this->assertSame($mails, $section->item('rest:acme/v1/' . $handler)?->facts['endpoints'][0]['mails'], $handler);
        }

        $this->assertStringNotContainsString('sales@example.com', CanonicalJson::encode([$section->toArray(), $section->stats()]));
    }

    public function testARouteWithTwoHandlersKeepsBoth(): void
    {
        $source = FakeCodeSource::fixture();
        $source->endpoints[] = FakeCodeSource::endpoint('/acme/v1/contact', 'acme/v1', ['GET'], $source->callback('acme_signup_handle'), CodeSource::CHECKED);

        $endpoints = $this->item('rest:acme/v1/contact', $source)->facts['endpoints'];

        $this->assertSame(['acme_contact_handle', 'acme_signup_handle'], array_column($endpoints, 'handler'));
    }

    public function testIdsAreSafeForTheCompactTiers(): void
    {
        $this->assertSame('rest:acme/v1/items/(?P<id>[\d]{1-3})', RestRoutes::id('/acme/v1/items/(?P<id>[\d]{1,3})'));
        $this->assertSame('rest:index', RestRoutes::id('/'));
    }

    public function testTheIndexLineNamesTheHandlerFileAndTheConstantNotItsValue(): void
    {
        $line = Facts::describe($this->item('rest:acme/v1/contact'));

        $this->assertSame(
            'POST acme_contact_handle in themes/acme/inc/contact.php; public; records an Orbit lead; mails the address in constant ACME_CONTACT_NOTIFY (declared in themes/acme/inc/contact.php) through plugin:postmark-approved-wordpress-plugin; fires acme_contact_submit (nothing listens)',
            $line
        );
        $this->assertStringContainsString('what it does is not traced (plugin code is not read)', Facts::describe($this->item('rest:acme-seo/v1/settings')));
    }

    public function testTheSiteSectionIsTheSameWhetherOrNotRoutesWereReadFirst(): void
    {
        // Reading routes fires rest_api_init, where core and plugins register settings.
        $restInit = false;
        $site = new SiteScanner(
            static function (string $name) use (&$restInit): mixed {
                return $name === 'WPLANG' ? ($restInit ? 'en_US' : '') : (FixedSite::OPTIONS[$name] ?? false);
            },
            static fn (int $id): ?string => FixedSite::PAGES[$id] ?? null,
            static fn (string $slug): ?array => FixedSite::THEMES[$slug] ?? null,
            static fn (): string => FixedSite::WORDPRESS_VERSION
        );
        $prime = static function () use (&$restInit): void {
            $restInit = true;
        };
        $code = static function () use (&$restInit): CodeScanner {
            $source = FakeCodeSource::fixture();
            $source->onRouteRead = static function () use (&$restInit): void {
                $restInit = true;
            };

            return new CodeScanner($source);
        };

        $scan = static function (array $scanners, ?callable $primer) use (&$restInit): Section {
            $restInit = false;

            foreach ((new ScannerRegistry($scanners, $primer))->scan() as $section) {
                if ($section->name === 'site') {
                    return $section;
                }
            }

            self::fail('No site section.');
        };

        // Without the prime, the code section reading routes first changes what site reads.
        $this->assertNotSame($scan([$site], null)->hash, $scan([$code(), $site], null)->hash, 'The test must be able to fail.');

        $alone = $scan([$site], $prime);
        $afterRoutes = $scan([$code(), $site], $prime);

        $this->assertSame($alone->hash, $afterRoutes->hash);
        $this->assertSame(CanonicalJson::encode($alone->toArray()), CanonicalJson::encode($afterRoutes->toArray()));
    }

    public function testTheRegistryReadsTheRestServerOnceBeforeAnyScanner(): void
    {
        $scanner = new class implements Scanner {
            /** @var list<int> rest_get_server() reads counted when each scan started. */
            public static array $seen = [];

            public function section(): string
            {
                return 'site';
            }

            public function scan(): Section
            {
                self::$seen[] = WpCalls::$restServerReads;

                return new Section('site', []);
            }
        };
        WpCalls::$filterCallbacks['vwf_scanners'] = static fn (mixed $scanners): array => [$scanner];

        ScannerRegistry::fromFilters()->scan();

        $this->assertSame([1], $scanner::$seen, 'The server was read once, before the scanner ran.');
        $this->assertContains('code', array_map(static fn (Scanner $each): string => $each->section(), ScannerRegistry::defaults()));
    }

    public function testPluginRoutesFoldIntoOneIndexLineAndTheTiersStayWithinBudget(): void
    {
        $source = FakeCodeSource::fixture();

        // A plugin with forty routes, as SEO and forms plugins have.
        for ($n = 1; $n <= 40; $n++) {
            $source->endpoints[] = FakeCodeSource::endpoint('/acme-seo/v1/thing-' . $n, 'acme-seo/v1', ['GET'], FakeCodeSource::method('Acme\Seo\Rest::settings', 'plugins/acme-seo/src/Rest.php'), CodeSource::CHECKED);
        }

        $manifest = FixtureManifest::manifest(['code' => $this->scan($source)->items]);
        $index = (new IndexRenderer())->render($manifest);
        $brief = (new BriefRenderer())->render($manifest);

        $this->assertStringContainsString("\nrest:acme/v1/contact | /acme/v1/contact | POST acme_contact_handle", $index, 'The theme\'s routes are listed.');
        $this->assertStringNotContainsString('rest:acme-seo/', $index);
        $this->assertStringContainsString("\nrest: +41 from plugins (section code)\n", $index);
        $this->assertLessThanOrEqual(IndexRenderer::BUDGET, Compact::length($index));
        $this->assertLessThanOrEqual(BriefRenderer::BUDGET, Compact::length($brief));
        $this->assertStringContainsString('sections code, content_model', $brief);
    }

    public function testTheBundledSinksNameOrbitLeadsAndMail(): void
    {
        $sinks = PhpSinks::bundled();

        $this->assertSame(['effect' => 'record', 'label' => 'an Orbit lead'], $sinks->sink('Voyager\Orbit\Modules\Leads\Module::insertLead'));
        $this->assertSame('mail', $sinks->sink('wp_mail')['effect'] ?? null);
        $this->assertNull($sinks->sink('acme_contact_notify'));
    }

    private function scan(?FakeCodeSource $source = null): Section
    {
        return (new CodeScanner($source ?? FakeCodeSource::fixture(), PhpSinks::bundled()))->scan();
    }

    private function item(string $id, ?FakeCodeSource $source = null): Item
    {
        $item = $this->scan($source)->item($id);
        $this->assertNotNull($item, $id . ' is in the code section.');

        return $item;
    }
}
