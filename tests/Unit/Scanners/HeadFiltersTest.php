<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Scanners\CodeScanner;
use Voyager\Wayfinder\Scanners\HeadFilters;
use Voyager\Wayfinder\Scanners\PhpSinks;
use Voyager\Wayfinder\Tests\Support\FakeCodeSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;
use Voyager\Wayfinder\Tests\Support\WpCalls;

/**
 * Head filters in the code section, from a fake hook registry
 * over PHP source strings: a theme og:title filter limited to the front
 * page, an archive description filter, one that reads a setting, a plugin's
 * callback whose file is outside the theme, and what is left out.
 */
final class HeadFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testFindSaysAThemeFilterSetsThisPagesOgTitleAndNamesTheFile(): void
    {
        $result = $this->find('/');
        $chain = array_column($result['chain'], null, 'id');
        $ids = array_keys($chain);

        $this->assertSame('route:front-page', end($ids), 'The route stays last.');
        $this->assertContains('filter:rank_math/opengraph/facebook/og_title@acme_og_title', $ids);
        $this->assertNotContains('filter:get_the_archive_description@acme_services_description', $ids, 'A filter tied to another route is not on this page.');

        $ogTitle = $chain['filter:rank_math/opengraph/facebook/og_title@acme_og_title'];
        $this->assertSame(
            'The og:title on this page is set by the theme filter acme_og_title in themes/acme/functions.php (hook rank_math/opengraph/facebook/og_title, which plugin:seo-by-rank-math applies). It returns fixed text typed into that file, so change it there; no settings screen changes it.',
            $ogTitle['instruction']
        );
        $this->assertNull($ogTitle['edit']);
        $this->assertSame('no', $ogTitle['client_can']);
        $this->assertFalse($ogTitle['match']);

        // A filter that checks no condition runs everywhere, and its setting has a screen.
        $siteName = $chain['filter:rank_math/opengraph/facebook/og_site_name@acme_og_site_name'];
        $this->assertSame('options-general.php', $siteName['edit']);
        $this->assertStringContainsString('reads the option blogname ("Site title"). Change that setting, not the file', $siteName['instruction']);

        $this->assertStringContainsString('Wayfinder does not read plugin code, so what it sets is not traced', $chain['filter:rank_math/frontend/title@Acme\Seo\Rest::title']['instruction']);
    }

    public function testFindOnTheArchiveNamesTheDescriptionFilter(): void
    {
        $ids = array_column($this->find('/services/')['chain'], 'id');

        $this->assertContains('filter:get_the_archive_description@acme_services_description', $ids);
        $this->assertNotContains('filter:rank_math/opengraph/facebook/og_title@acme_og_title', $ids);
    }

    public function testFindWithoutAStoredCodeSectionAddsNothing(): void
    {
        $plain = (new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout()))->find('/');

        $this->assertSame([], array_values(array_filter(array_column($plain['chain'], 'id'), static fn (string $id): bool => str_starts_with($id, 'filter:'))));
    }

    public function testAThemeOgTitleFilterNamesItsFileTheRouteItChangesAndThePluginThatAppliesIt(): void
    {
        $item = $this->item('filter:rank_math/opengraph/facebook/og_title@acme_og_title');

        $this->assertSame('head_filter', $item->kind);
        $this->assertSame('Filter on og:title', $item->label);
        $this->assertSame('rank_math/opengraph/facebook/og_title', $item->facts['hook']);
        $this->assertSame('og:title', $item->facts['changes']);
        $this->assertSame('acme_og_title', $item->facts['callback']);
        $this->assertSame('themes/acme/functions.php', $item->facts['file']);
        $this->assertSame(['kind' => 'theme', 'slug' => 'acme'], $item->facts['owner']);
        $this->assertSame('seo-by-rank-math', $item->facts['applied_by']);
        $this->assertTrue($item->facts['traced']);
        $this->assertSame(['is_front_page()'], $item->facts['conditions']);
        $this->assertTrue($item->facts['returns_fixed_text']);
        $this->assertSame([], $item->facts['reads']);
        $this->assertSame(['plugin:seo-by-rank-math', 'route:front-page'], $item->refs);
        $this->assertSame('themes/acme/functions.php:4-9', $item->stats['lines']['callback']);
    }

    public function testTheFixedTextItselfIsNeverKept(): void
    {
        $section = $this->scan();
        $json = CanonicalJson::encode([$section->toArray(), $section->stats()]);

        $this->assertStringContainsString('returns_fixed_text', $json);
        $this->assertStringNotContainsString('Plumbing done right', $json);
        $this->assertStringNotContainsString('Every service we offer', $json);
    }

    public function testAnArchiveDescriptionFilterInATernaryIsTiedToThatArchive(): void
    {
        $item = $this->item('filter:get_the_archive_description@acme_services_description');

        $this->assertSame('archive description', $item->facts['changes']);
        $this->assertNull($item->facts['applied_by'], 'A core hook has no SEO plugin behind it.');
        $this->assertSame(["is_post_type_archive('service')"], $item->facts['conditions']);
        $this->assertTrue($item->facts['returns_fixed_text']);
        $this->assertSame(['route:archive-service'], $item->refs);
    }

    public function testAFilterThatReadsASettingNamesTheOption(): void
    {
        $item = $this->item('filter:rank_math/opengraph/facebook/og_site_name@acme_og_site_name');

        $this->assertSame('og:site_name', $item->facts['changes']);
        $this->assertSame(['option:blogname'], $item->facts['reads']);
        $this->assertFalse($item->facts['returns_fixed_text']);
        $this->assertSame([], $item->facts['conditions']);
    }

    public function testAPluginCallbackOutsideTheThemeIsNamedButNotRead(): void
    {
        $item = $this->item('filter:rank_math/frontend/title@Acme\Seo\Rest::title');

        $this->assertSame('plugins/acme-seo/src/Rest.php', $item->facts['file']);
        $this->assertSame(['kind' => 'plugin', 'slug' => 'acme-seo'], $item->facts['owner']);
        $this->assertFalse($item->facts['traced']);
        $this->assertArrayNotHasKey('returns_fixed_text', $item->facts);
        $this->assertSame(['plugin:acme-seo', 'plugin:seo-by-rank-math'], $item->refs);
    }

    public function testBuiltInsAndCoreCallbacksAreLeftOut(): void
    {
        $source = FakeCodeSource::fixture();
        $source->hooks[] = ['hook' => 'document_title_parts', 'priority' => 10, 'callback' => FakeCodeSource::method('wp_title_core', 'wp-includes/general-template.php')];

        $ids = array_map(static fn (Item $item): string => $item->id, $this->scan($source)->items);

        $this->assertNotContains('filter:wp_title@trim', $ids);
        $this->assertNotContains('filter:document_title_parts@wp_title_core', $ids);
    }

    public function testTwoClosuresOnOneHookGetTwoIds(): void
    {
        $source = FakeCodeSource::fixture();
        $source->files['themes/acme/inc/titles.php'] = "<?php\nadd_filter( 'wp_title', function ( \$t ) {\n    return is_404() ? 'Not found' : \$t;\n} );\nadd_filter( 'wp_title', function ( \$t ) {\n    return \$t;\n} );\n";
        $source->hooks[] = ['hook' => 'wp_title', 'priority' => 10, 'callback' => ['name' => '{closure}', 'file' => 'themes/acme/inc/titles.php', 'start' => 2, 'end' => 4]];
        $source->hooks[] = ['hook' => 'wp_title', 'priority' => 20, 'callback' => ['name' => '{closure}', 'file' => 'themes/acme/inc/titles.php', 'start' => 5, 'end' => 7]];

        $section = $this->scan($source);
        $first = $section->item('filter:wp_title@{closure}');
        $second = $section->item('filter:wp_title@{closure}-2');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(['is_404()'], $first->facts['conditions']);
        $this->assertTrue($first->facts['returns_fixed_text']);
        $this->assertFalse($second->facts['returns_fixed_text']);
    }

    public function testHookNamesMapToWhatTheyChange(): void
    {
        $this->assertSame('title', HeadFilters::changes('pre_get_document_title'));
        $this->assertSame('description', HeadFilters::changes('rank_math/frontend/description'));
        $this->assertSame('og:title', HeadFilters::changes('rank_math/opengraph/facebook/og_title'));
        $this->assertSame('twitter:title', HeadFilters::changes('rank_math/opengraph/twitter/twitter_title'));
        $this->assertSame('og:image', HeadFilters::changes('rank_math/opengraph/facebook/image'));
        $this->assertSame('og:title', HeadFilters::changes('wpseo_opengraph_title'));
        $this->assertSame('twitter:description', HeadFilters::changes('wpseo_twitter_description'));
        $this->assertSame('archive title', HeadFilters::changes('get_the_archive_title'));
        $this->assertNull(HeadFilters::changes('rank_math/sitemap/entry'));
        $this->assertNull(HeadFilters::changes('the_content'));
        $this->assertFalse(HeadFilters::matches('rank_math/json_ld'));

        $this->assertSame('wordpress-seo', HeadFilters::appliedBy('wpseo_title'));
        $this->assertNull(HeadFilters::appliedBy('wp_title'));
    }

    public function testTheIndexLineSaysWhatChangesWhereAndInWhichFile(): void
    {
        $this->assertSame(
            "changes og:title on route:front-page (is_front_page()); acme_og_title in themes/acme/functions.php; returns fixed text typed into that file; applied by plugin:seo-by-rank-math",
            Facts::describe($this->item('filter:rank_math/opengraph/facebook/og_title@acme_og_title'))
        );
        $this->assertSame(
            'changes og:site_name; acme_og_site_name in themes/acme/functions.php; reads option:blogname; applied by plugin:seo-by-rank-math',
            Facts::describe($this->item('filter:rank_math/opengraph/facebook/og_site_name@acme_og_site_name'))
        );
        $this->assertStringContainsString('what it returns is not traced', Facts::describe($this->item('filter:rank_math/frontend/title@Acme\Seo\Rest::title')));
    }

    /**
     * @return array<string, mixed>
     */
    private function find(string $url): array
    {
        $filters = array_values(array_filter($this->scan()->items, static fn (Item $item): bool => $item->kind === HeadFilters::KIND));

        return (new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout(), null, null, null, null, static fn (): array => $filters))->find($url);
    }

    private function scan(?FakeCodeSource $source = null): Section
    {
        return (new CodeScanner($source ?? FakeCodeSource::fixture(), PhpSinks::bundled()))->scan();
    }

    private function item(string $id): Item
    {
        $item = $this->scan()->item($id);
        $this->assertNotNull($item, $id . ' is in the code section.');

        return $item;
    }
}
