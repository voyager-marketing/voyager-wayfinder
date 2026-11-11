<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Scanners\OptionScreens;
use Voyager\Wayfinder\Scanners\ReadTracer;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;
use Voyager\Wayfinder\Tests\Support\WpCalls;

/**
 * A registered pattern that prints an option is traced to the
 * option and the screen that edits it, instead of reading as text typed
 * into code. On the real-site snapshot the footer phone comes from a Site
 * Data option, saved on the Site Data tab, and the manual sent every reader
 * to the code.
 *
 * @phpstan-import-type Result from EditLocator
 */
final class PatternReadsTest extends TestCase
{
    private const PATTERNS = __DIR__ . '/../../fixtures/patterns/';

    private const SITE_DATA = ['voyager_site_hours' => 'Business Hours', 'voyager_site_phone' => 'Phone Number'];

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testAPatternFileThatReadsAnOptionRecordsTheRead(): void
    {
        WpCalls::$options = ['vwf_phone' => '(540) 555-0142'];

        $this->assertSame(['option:vwf_phone'], ReadTracer::traceFile(self::PATTERNS . 'reads-option.php'));

        WpCalls::$options = [];

        $this->assertSame(['option:vwf_phone'], ReadTracer::traceFile(self::PATTERNS . 'reads-option.php'), 'An unset option is still read, though nothing prints.');
        $this->assertSame([], ReadTracer::traceFile(self::PATTERNS . 'reads-nothing.php'));
        $this->assertNull(ReadTracer::traceFile(self::PATTERNS . 'no-such-file.php'));
    }

    public function testAPatternFileThatDeclaresAFunctionIsNeverIncluded(): void
    {
        $this->assertNull(ReadTracer::traceFile(self::PATTERNS . 'declares-function.php'));
        $this->assertFalse(function_exists('vwf_fixture_hours'), 'Tracing it would have declared the function.');

        $this->assertTrue(ReadTracer::declares('<?php function acme_hours() {}'));
        $this->assertTrue(ReadTracer::declares('<?php function &acme_hours() {}'));
        $this->assertTrue(ReadTracer::declares('<?php final class Acme {}'));
        $this->assertTrue(ReadTracer::declares('<?php enum Acme {}'));
        $this->assertFalse(ReadTracer::declares('<?php $f = function () { return 1; }; $g = static fn () => 2;'));
        $this->assertFalse(ReadTracer::declares('<?php $o = new class {}; echo Acme::class; // function acme() {}'));
    }

    public function testAReadBelowThePatternIncludeCountsOnEverySurface(): void
    {
        $roots = [
            ['', '/var/www/html/wp-content'],
            ['core', '/var/www/html/wp-includes'],
            ['core', '/var/www/html/wp-admin'],
        ];
        $tracer = '/var/www/html/wp-content/plugins/voyager-wayfinder/src/Scanners/ReadTracer.php';
        $above = [
            ['function' => '{closure}', 'class' => ReadTracer::class, 'file' => $tracer],
            ['function' => 'trace', 'class' => ReadTracer::class, 'file' => $tracer],
            ['function' => 'traceFile', 'class' => ReadTracer::class, 'file' => '/var/www/html/wp-content/plugins/voyager-wayfinder/src/Scanners/WordPressLayoutSource.php'],
            // wp-admin runs the scan on admin_init, so a core frame sits above it.
            ['function' => 'do_action', 'file' => '/var/www/html/wp-admin/admin.php'],
        ];
        $theme = [
            ['function' => 'apply_filters', 'file' => '/var/www/html/wp-includes/option.php'],
            ['function' => 'get_option', 'file' => '/var/www/html/wp-content/themes/acme/inc/site-data.php'],
            ['function' => 'acme_site_phone', 'file' => '/var/www/html/wp-content/themes/acme/patterns/site-phone.php'],
            ['function' => 'include', 'file' => $tracer],
        ];
        $include = ['function' => 'includeFile', 'class' => ReadTracer::class, 'file' => $tracer];

        $this->assertTrue(ReadTracer::readOutsideCore([...$theme, $include, ...$above], ['get_option'], $roots));
        $this->assertFalse(ReadTracer::readOutsideCore([...$theme, ...$above], ['get_option'], $roots), 'Without the include frame the first core frame decides, and it is not a binding source.');

        $homeUrl = [
            ['function' => 'apply_filters', 'file' => '/var/www/html/wp-includes/option.php'],
            ['function' => 'get_option', 'file' => '/var/www/html/wp-includes/link-template.php'],
            ['function' => 'get_home_url', 'file' => '/var/www/html/wp-includes/link-template.php'],
            ['function' => 'home_url', 'file' => '/var/www/html/wp-content/themes/acme/patterns/site-phone.php'],
            ['function' => 'include', 'file' => $tracer],
            $include,
            ...$above,
        ];

        $this->assertFalse(ReadTracer::readOutsideCore($homeUrl, ['get_option'], $roots), 'Core reads `home` for itself.');
    }

    public function testAPatternFileThatReadsAnOptionRecordsTheReadAndItsSourceFile(): void
    {
        WpCalls::$options = ['vwf_phone' => '(540) 555-0142'];

        $pattern = self::scanPattern(['file' => 'themes/vwf-fixture/patterns/site-phone.php', 'reads' => ReadTracer::traceFile(self::PATTERNS . 'reads-option.php')]);

        $this->assertSame('themes/vwf-fixture/patterns/site-phone.php', $pattern->facts['file']);
        $this->assertSame(['option:vwf_phone'], $pattern->facts['reads']);
        $this->assertArrayNotHasKey('read_screens', $pattern->facts, 'Nothing says where vwf_phone is edited.');
        $this->assertTrue($pattern->facts['registered']);
        $this->assertNull($pattern->edit);
    }

    public function testAnAllowlistedOptionNamesItsScreen(): void
    {
        $this->assertSame(['label' => 'Tagline', 'edit' => 'options-general.php'], OptionScreens::screen('blogdescription', []));

        $pattern = self::scanPattern(['file' => 'themes/acme/patterns/tagline.php', 'reads' => ['option:blogdescription']], [], ['blogdescription' => 'Plumbers for the Fredericksburg region']);

        $this->assertSame([['read' => 'option:blogdescription', 'label' => 'Tagline', 'edit' => 'options-general.php']], $pattern->facts['read_screens']);
        $this->assertStringContainsString('reads option:blogdescription (Tagline), edited at options-general.php', Facts::describe($pattern));
    }

    public function testASiteDataOptionNamesTheSiteDataTabAndIsNotSentToTheCode(): void
    {
        $this->assertSame(
            ['label' => 'Phone Number', 'edit' => 'options-general.php?page=voyager-blocks&tab=site-data'],
            OptionScreens::screen('voyager_site_phone', self::SITE_DATA, OptionScreens::BLOCKS_SCREEN)
        );
        $this->assertNull(OptionScreens::screen('voyager_site_phone', []), 'With neither plugin nothing says where it is edited.');
        $this->assertSame([], OptionScreens::siteData(self::owners(false, false)));
        $this->assertNull(OptionScreens::siteDataScreen(self::owners(false, false)));
        $this->assertSame([], OptionScreens::siteData(), 'No plugin is loaded in the unit suite.');

        $pattern = self::scanPattern(['file' => 'themes/acme/patterns/site-phone.php', 'reads' => ['option:voyager_site_phone']], self::SITE_DATA, ['voyager_site_phone' => '(540) 227-0214']);
        $line = Facts::describe($pattern);
        $chain = self::find('/', null, '(540) 227-0214')['chain'];
        $entry = array_values(array_filter($chain, static fn (array $e): bool => $e['id'] === 'pat:acme/site-phone'))[0] ?? null;

        $this->assertSame('registered; file themes/acme/patterns/site-phone.php; reads option:voyager_site_phone (Phone Number), edited at options-general.php?page=voyager-blocks&tab=site-data; used in tpl:home', $line);
        $this->assertNotNull($entry);
        $this->assertSame(
            'Pattern "Site phone" is registered in code, built by the theme file themes/acme/patterns/site-phone.php, so it has no edit screen. '
            . 'It prints the option voyager_site_phone ("Phone Number"), edited here: options-general.php?page=voyager-blocks&tab=site-data. Change that value there, not in the file. '
            . 'Change the rest of the pattern in that file, or replace it with a synced pattern.',
            $entry['instruction']
        );
        $this->assertStringNotContainsString('hard', $entry['instruction'] . $line);
    }

    public function testOnlyThePackNamesItsSiteDataScreen(): void
    {
        $owners = self::owners(false, true);
        $fields = OptionScreens::siteData($owners);
        $screen = OptionScreens::siteDataScreen($owners);

        $this->assertSame('options-general.php?page=voyager-site-data', $screen);
        $this->assertArrayNotHasKey('company', $fields, 'A field derived from core is not saved in an option.');
        $this->assertSame(['voyager_site_hours' => 'Business hours', 'voyager_site_phone' => 'Phone number'], $fields);
        $this->assertSame(
            ['label' => 'Phone number', 'edit' => 'options-general.php?page=voyager-site-data'],
            OptionScreens::screen('voyager_site_phone', $fields, $screen)
        );

        $pattern = self::scanPattern(['file' => 'themes/acme/patterns/site-phone.php', 'reads' => ['option:voyager_site_phone']], $fields, ['voyager_site_phone' => '(540) 227-0214'], $screen);

        $this->assertSame([['read' => 'option:voyager_site_phone', 'label' => 'Phone number', 'edit' => 'options-general.php?page=voyager-site-data']], $pattern->facts['read_screens']);
        $this->assertSame(
            'registered; file themes/acme/patterns/site-phone.php; reads option:voyager_site_phone (Phone number), edited at options-general.php?page=voyager-site-data; used in tpl:home',
            Facts::describe($pattern)
        );

        $result = self::find('/', '(540) 227-0214', '+1 540 227 0214', $fields, $screen);

        $this->assertSame('option:voyager_site_phone', $result['hits'][0]['id']);
        $this->assertSame('options-general.php?page=voyager-site-data', $result['chain'][0]['edit']);
        $this->assertSame('Phone number', $result['chain'][0]['label']);
    }

    public function testVoyagerBlocksOwnsTheSourceWhenBothPluginsAreActive(): void
    {
        $owners = self::owners(true, true);

        $this->assertSame(OptionScreens::BLOCKS_SCREEN, OptionScreens::siteDataScreen($owners));
        $this->assertSame(['voyager_site_phone' => 'Phone Number'], OptionScreens::siteData($owners), 'The pack stands aside once voyager-blocks registered the source.');
    }

    public function testAnUnknownOptionSaysItsScreenIsNotTraced(): void
    {
        $pattern = self::scanPattern(
            ['file' => 'themes/acme/patterns/promo.php', 'reads' => ['option:acme_promo', 'option:voyager_site_phone']],
            self::SITE_DATA,
            ['acme_promo' => 'Spring special', 'voyager_site_phone' => '(540) 227-0214']
        );

        $this->assertSame([['read' => 'option:voyager_site_phone', 'label' => 'Phone Number', 'edit' => 'options-general.php?page=voyager-blocks&tab=site-data']], $pattern->facts['read_screens']);
        $this->assertStringContainsString(
            'reads option:voyager_site_phone (Phone Number), edited at options-general.php?page=voyager-blocks&tab=site-data; reads option:acme_promo, edit screen not traced',
            Facts::describe($pattern),
            'The traced screen comes first; the untraced read is named, never guessed.'
        );
    }

    public function testAnEmptyOptionIsFlaggedWithoutItsValue(): void
    {
        $pattern = self::scanPattern(
            ['file' => 'themes/acme/patterns/virginia.php', 'reads' => ['option:voyager_site_hours', 'option:voyager_site_phone']],
            self::SITE_DATA,
            ['voyager_site_hours' => '', 'voyager_site_phone' => '(540) 227-0214']
        );
        $line = Facts::describe($pattern);

        $this->assertSame(['option:voyager_site_hours'], $pattern->stats['empty_reads']);
        $this->assertArrayNotHasKey('empty_reads', $pattern->facts, 'Setting an option is content: it must not move the hash.');
        $this->assertStringContainsString('reads option:voyager_site_hours (Business Hours, empty now) and option:voyager_site_phone (Phone Number), all edited at options-general.php?page=voyager-blocks&tab=site-data', $line);
        $this->assertStringNotContainsString('227', $line, 'No value is printed.');
    }

    public function testFindSendsAPrintedOptionToItsScreen(): void
    {
        $result = self::find('/', '(540) 227-0214', '+1 540 227 0214');

        $this->assertTrue($result['matched']);
        $this->assertSame('option:voyager_site_phone', $result['hits'][0]['id']);
        $this->assertSame(['option:voyager_site_phone', 'pat:acme/site-phone', 'part:footer', 'tpl:page', 'route:front-page'], array_column($result['chain'], 'id'));
        $this->assertSame('options-general.php?page=voyager-blocks&tab=site-data', $result['chain'][0]['edit']);
        $this->assertSame('Phone Number', $result['chain'][0]['label']);
        $this->assertSame(
            'The option voyager_site_phone ("Phone Number"), which the "Site phone" pattern prints. Edit it here: options-general.php?page=voyager-blocks&tab=site-data',
            $result['chain'][0]['instruction']
        );
        $this->assertStringNotContainsString('227', (string) json_encode($result['hits'][0]['why']), 'The value is compared, never returned.');
    }

    public function testStaticTextInAPatternThatReadsAnOptionStaysWithThePattern(): void
    {
        $result = self::find('/', 'Call us', '(540) 227-0214');

        $this->assertSame('pat:acme/site-phone', $result['hits'][0]['id']);
        $this->assertNull($result['chain'][0]['edit']);
    }

    public function testThePagesChainNamesTheOptionsItsPatternsRead(): void
    {
        $result = self::find('/', null, '');
        $ids = array_column($result['chain'], 'id');
        $at = array_search('pat:acme/site-phone', $ids, true);

        $this->assertIsInt($at);
        $this->assertSame('option:voyager_site_phone', $ids[$at + 1]);
        $this->assertSame('options-general.php?page=voyager-blocks&tab=site-data', $result['chain'][$at + 1]['edit']);
        $this->assertStringEndsWith('Edit it here: options-general.php?page=voyager-blocks&tab=site-data. It is empty now, so nothing from it shows until it is set.', $result['chain'][$at + 1]['instruction']);
    }

    public function testASiteDataBindingIsEditedWhereItsOwnerSavesIt(): void
    {
        $source = new FakeLayoutSource();
        $source->siteData = OptionScreens::siteData(self::owners(false, true));
        $source->siteDataScreen = OptionScreens::siteDataScreen(self::owners(false, true));
        $source->templates = [FakeLayoutSource::row('home', implode("\n", [
            '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"phone"}}}}} -->',
            '<p></p>',
            '<!-- /wp:paragraph -->',
        ]))];

        $item = (new LayoutScanner($source, new Locks('7.1.2')))->scan()->item('bind:voyager/site-data.phone');

        $this->assertNotNull($item);
        $this->assertSame('voyager/site-data', $item->facts['source']);
        $this->assertSame('options-general.php?page=voyager-site-data', $item->edit);

        $source->siteData = [];
        $source->siteDataScreen = null;

        $item = (new LayoutScanner($source, new Locks('7.1.2')))->scan()->item('bind:voyager/site-data.phone');

        $this->assertNotNull($item);
        $this->assertNull($item->edit, 'With no plugin owning the source nothing says where its values are edited.');
    }

    public function testAPostMetaTextBindingRefsItsFieldAndPointsAtThePost(): void
    {
        $source = new FakeLayoutSource();
        $source->meta = ['price_from' => ['service'], 'stats' => ['service']];
        $source->templates = [FakeLayoutSource::row('single-service', implode("\n", [
            '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/post-meta-text","args":{"key":"price_from"}}}}} -->',
            '<p></p>',
            '<!-- /wp:paragraph -->',
            '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/post-meta-text","args":{"key":"stats.0.value"}}}}} -->',
            '<p></p>',
            '<!-- /wp:paragraph -->',
        ]))];

        $layout = (new LayoutScanner($source, new Locks('7.1.2')))->scan();
        $plain = $layout->item('bind:voyager/post-meta-text.price_from');
        $path = $layout->item('bind:voyager/post-meta-text.stats.0.value');

        $this->assertNotNull($plain);
        $this->assertNotNull($path);
        $this->assertSame(['field:service.price_from'], $plain->refs);
        $this->assertSame(['field:service.stats'], $path->refs, 'A dot path belongs to the field named by its first part.');
        $this->assertNull($plain->facts['reads']);
        $this->assertSame([], $source->readCalls, 'Post meta needs a post, so the source is not asked for its value.');

        $routes = FakeRouteSource::fixture();
        $routes->values = ['voyager/post-meta-text' => ['price_from' => '149']];
        $layout = FakeRouteSource::layout();

        foreach ($layout->templates as $i => $row) {
            if ($row['slug'] === 'single-service') {
                $layout->templates[$i]['content'] .= "\n" . '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/post-meta-text","args":{"key":"price_from"}}}}} -->' . "\n<p></p>\n<!-- /wp:paragraph -->";
            }
        }

        $result = (new EditLocator($routes, $layout))->find('/services/drain-cleaning/', '149');

        $this->assertSame('bind:voyager/post-meta-text.price_from', $result['chain'][0]['id']);
        $this->assertSame('post.php?post=30&action=edit', $result['chain'][0]['edit']);
        $this->assertStringContainsString('It comes from the "price_from" field of this service: post.php?post=30&action=edit', $result['chain'][0]['instruction']);
    }

    /**
     * The owners of the Site Data source with the plugins that are "active"
     * answered by the fake field lists below: the real owner table, so the
     * screens and keys are the real ones.
     *
     * @return array<string, array{screen: string, keyed_by: 'option'|'binding'}>
     */
    private static function owners(bool $blocks, bool $pack): array
    {
        $active = [OptionScreens::BLOCKS_FIELDS => $blocks, OptionScreens::PACK_FIELDS => $pack];
        $fake = [OptionScreens::BLOCKS_FIELDS => self::class . '::blocksFields', OptionScreens::PACK_FIELDS => self::class . '::packFields'];
        $owners = [];

        // In the real table's order, so the order it asks in is the one tested.
        foreach (OptionScreens::OWNERS as $function => $owner) {
            if ($active[$function]) {
                $owners[$fake[$function]] = $owner;
            }
        }

        return $owners;
    }

    /**
     * What VoyagerBlocks\SiteDataBindings\get_settings_fields() returns:
     * option name => field.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function blocksFields(): array
    {
        return ['voyager_site_phone' => ['label' => 'Phone Number', 'section' => 'voyager_site_data_contact']];
    }

    /**
     * What VoyagerOpenBlocks\SiteData\fields() returns: binding key => field,
     * with `option` on the fields stored in an option.
     *
     * @return array<string, array<string, string>>
     */
    public static function packFields(): array
    {
        return [
            'company' => ['label' => 'Company name', 'type' => 'text'],
            'phone'   => ['label' => 'Phone number', 'type' => 'text', 'option' => 'voyager_site_phone'],
            'hours'   => ['label' => 'Business hours', 'type' => 'text', 'option' => 'voyager_site_hours'],
            'year'    => ['label' => 'Current year', 'type' => 'text'],
        ];
    }

    /**
     * The registered pattern `acme/<name>` placed by a home template, with
     * the theme file and reads given, scanned.
     *
     * @param array{file: string|null, reads: list<string>|null} $file
     * @param array<string, string|null>                         $siteData
     * @param array<string, string|null>                         $options
     */
    private static function scanPattern(array $file, array $siteData = [], array $options = [], ?string $siteDataScreen = OptionScreens::BLOCKS_SCREEN): Item
    {
        $slug = 'acme/' . basename((string) $file['file'], '.php');
        $source = FakeLayoutSource::fixture();
        $source->siteDataScreen = $siteDataScreen;
        $source->templates[] = FakeLayoutSource::row('home', '<!-- wp:pattern {"slug":"' . $slug . '"} /-->');
        $source->registered[$slug] = ['title' => 'Site phone', 'content' => FakeLayoutSource::paragraph('(540) 227-0214')];
        $source->patternFiles[$slug] = $file;
        $source->siteData = $siteData;
        $source->options = $options;

        $item = (new LayoutScanner($source, new Locks('7.1.2')))->scan()->item('pat:' . $slug);
        self::assertNotNull($item);

        return $item;
    }

    /**
     * Find on the fixture site with `acme/site-phone` in the footer: a
     * paragraph saying "Call us" and one printing the phone option, which
     * holds `$phone` (in any format).
     *
     * @param array<string, string|null> $siteData The Site Data fields, with the screen that saves them.
     * @return Result
     */
    private static function find(string $url, ?string $text, string $phone, array $siteData = self::SITE_DATA, ?string $siteDataScreen = OptionScreens::BLOCKS_SCREEN): array
    {
        $layout = FakeRouteSource::layout();

        foreach ($layout->parts as $i => $row) {
            if ($row['slug'] === 'footer') {
                $layout->parts[$i]['content'] .= "\n" . '<!-- wp:pattern {"slug":"acme/site-phone"} /-->';
            }
        }

        $layout->registered['acme/site-phone'] = [
            'title'   => 'Site phone',
            'content' => FakeLayoutSource::paragraph('Call us') . "\n\n" . FakeLayoutSource::paragraph('(540) 227-0214'),
        ];
        $layout->patternFiles['acme/site-phone'] = ['file' => 'themes/acme/patterns/site-phone.php', 'reads' => ['option:voyager_site_phone']];
        $layout->siteData = $siteData;
        $layout->siteDataScreen = $siteDataScreen;
        $layout->options = ['voyager_site_phone' => $phone];

        return (new EditLocator(FakeRouteSource::fixture(), $layout))->find($url, $text);
    }
}
