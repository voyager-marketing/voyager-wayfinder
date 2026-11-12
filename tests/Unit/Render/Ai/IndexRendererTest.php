<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\Compact;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;

final class IndexRendererTest extends TestCase
{
    public function testOneLinePerListedItemByRankThenIdUnderTheHeader(): void
    {
        $manifest = FixtureManifest::manifest();
        $lines = explode("\n", rtrim((new IndexRenderer())->render($manifest), "\n"));

        $this->assertSame('# wayfinder v1 index h:' . substr($manifest->hash, 0, 6), array_shift($lines));

        $ids = array_values(array_map(static fn (string $line): string => explode(' | ', $line)[0], array_filter(
            $lines,
            static fn (string $line): bool => str_contains($line, ' | ')
        )));

        // Templates, parts, post types, plugins and patterns in use lead; the other kinds by id; fields and options last.
        $this->assertSame([
            'tpl:page-contact', 'tpl:single-service',
            'part:footer', 'part:header',
            'pt:page', 'pt:post', 'pt:service', 'pt:team_member',
            'plugin:advanced-custom-fields',
            'pat:cta-banner',
            'bind:vwf-fixture/business-info.address', 'bind:vwf-fixture/business-info.phone', 'core:wordpress', 'nav:footer', 'nav:primary', 'tax:service_area',
            'field:service.duration', 'field:service.internal_sku', 'field:service.price_from', 'field:team_member.linkedin', 'field:team_member.photo', 'field:team_member.role',
            'opt:blogname', 'opt:page_for_posts', 'opt:page_on_front', 'opt:permalink_structure', 'opt:show_on_front', 'opt:stylesheet', 'opt:template',
        ], $ids);
    }

    public function testLinesFollowTheCompactGrammar(): void
    {
        $index = (new IndexRenderer())->render(FixtureManifest::manifest());

        $this->assertStringContainsString(
            "\npt:service | Services | 6-20 published; archive /services/; fields duration, internal_sku, price_from; defined in mu-plugins/vwf-fixture-core.php | edit.php?post_type=service | field:service.duration,field:service.internal_sku,field:service.price_from,tax:service_area\n",
            $index
        );
        $this->assertStringContainsString(
            "\npart:footer | Footer | area footer; file themes/vwf-fixture/parts/footer.html; overrides twentytwentyfive; used in tpl:page-contact, tpl:single-service +2 | site-editor.php?p=/wp_template_part/vwf-fixture//footer&canvas=edit | bind:vwf-fixture/business-info.address,bind:vwf-fixture/business-info.phone,nav:footer\n",
            $index
        );
        $this->assertStringContainsString(
            "\nbind:vwf-fixture/business-info.phone | Business info: phone | binds core/paragraph.content; reads option:vwf_phone; defined in mu-plugins/vwf-fixture-core.php; used in part:footer | " . FixtureManifest::BUSINESS_INFO . "\n",
            $index
        );
        $this->assertStringContainsString(
            "\ntpl:single-service | single-service | file themes/vwf-fixture/templates/single-service.html | site-editor.php?p=/wp_template/vwf-fixture//single-service&canvas=edit | part:footer,part:header,pat:cta-banner\n",
            $index
        );
        $this->assertStringContainsString("\ntax:service_area | Service areas | 1-5 terms; on service; defined in mu-plugins/vwf-fixture-core.php | ", $index);
        $this->assertStringContainsString("\npt:page | Pages | 1-5 published; defined in core | ", $index);
        $this->assertStringContainsString(
            "\nfield:service.internal_sku | Internal SKU | string; registered meta; not in REST | edit.php?post_type=service | pt:service\n",
            $index
        );
        $this->assertStringContainsString("\nopt:page_on_front | Homepage | home | options-reading.php\n", $index);
    }

    public function testAnEmptyMiddleColumnIsADashAndEmptyTrailingColumnsAreDropped(): void
    {
        $manifest = FixtureManifest::manifest(['layout' => [
            new Item('nav:orphan', 'navigation', 'Orphan', [], null, ['bind:vwf-fixture/business-info.phone'], ['item_count' => 0, 'usage_count' => 0, 'used_in' => []]),
        ]]);
        $index = (new IndexRenderer())->render($manifest);

        $this->assertStringContainsString("\nnav:orphan | Orphan | 0 items; unused | - | bind:vwf-fixture/business-info.phone\n", $index);
        $this->assertStringContainsString("\nopt:blogname | Site title | Riverbend Plumbing | options-general.php\n", $index);
    }

    public function testFactsWithoutAFileOrADefiningFileFallBack(): void
    {
        $manifest = FixtureManifest::manifest(['layout' => [
            new Item('tpl:legacy', 'template', 'Legacy', ['file' => null, 'file_theme' => 'vwf-fixture', 'overrides' => null, 'slug' => 'legacy', 'source' => 'theme', 'user_modified' => false], null),
            new Item('bind:acme/data.x', 'binding', 'acme/data: x', ['bound' => ['core/paragraph.content'], 'defined_in' => null, 'key' => 'x', 'reads' => null, 'source' => 'acme/data', 'source_label' => null], null, [], ['usage_count' => 1, 'used_in' => ['tpl:legacy']]),
        ]]);
        $index = (new IndexRenderer())->render($manifest);

        $this->assertStringContainsString("\ntpl:legacy | Legacy | file from vwf-fixture\n", $index);
        $this->assertStringContainsString("\nbind:acme/data.x | acme/data: x | binds core/paragraph.content; used in tpl:legacy\n", $index);
    }

    public function testTheBindingKeysLineAppearsOnlyWhenABindingIsListed(): void
    {
        $withBindings = (new IndexRenderer())->render(FixtureManifest::manifest());
        $without = (new IndexRenderer())->render(new Manifest([new Section('site', [
            new Item('opt:blogname', 'option', 'Site title', ['value' => 'Riverbend Plumbing'], 'options-general.php'),
        ])]));

        $this->assertStringEndsWith("\n" . IndexRenderer::BINDING_KEYS_LINE . "\n", $withBindings);
        $this->assertStringNotContainsString(IndexRenderer::BINDING_KEYS_LINE, $without);
    }

    public function testParentThemeTemplatesAndPatternsOnlyTheyUseFoldIntoCountLines(): void
    {
        $index = (new IndexRenderer())->render(FixtureManifest::manifest());

        $this->assertStringNotContainsString("\ntpl:index |", $index);
        $this->assertStringNotContainsString("\ntpl:single |", $index);
        $this->assertStringNotContainsString("\npart:sidebar |", $index);
        $this->assertStringNotContainsString("\npat:twentytwentyfive/post-meta |", $index);
        $this->assertStringEndsWith(
            "\npart: +1 unused from twentytwentyfive (section layout)"
            . "\npat: +1 unused registered (section layout)"
            . "\ntpl: +2 unused from twentytwentyfive (section layout)"
            . "\n" . IndexRenderer::BINDING_KEYS_LINE . "\n",
            $index
        );

        // The child theme's own templates and parts always stay.
        $this->assertStringContainsString("\ntpl:single-service |", $index);
        $this->assertStringContainsString("\npart:header |", $index);

        // `used in` names what the reader can see and only counts what was folded.
        $this->assertStringContainsString('; used in tpl:page-contact, tpl:single-service +2 |', $index);
    }

    public function testUsedInCountsAnItemUsedOnlyByFoldedItems(): void
    {
        $manifest = FixtureManifest::manifest(['layout' => [
            new Item('nav:sidebar', 'navigation', 'Sidebar menu', [], 'site-editor.php', [], ['item_count' => 3, 'usage_count' => 2, 'used_in' => ['tpl:index', 'tpl:single']]),
        ]]);

        $this->assertStringContainsString(
            "\nnav:sidebar | Sidebar menu | 1-5 items; used in 2 folded items | site-editor.php\n",
            (new IndexRenderer())->render($manifest)
        );
    }

    public function testUsedInNamesLayoutContainersAndOnlyBandsTheContent(): void
    {
        $index = (new IndexRenderer())->render(FixtureManifest::manifest());

        $this->assertStringContainsString(
            "\npat:cta-banner | CTA banner | synced; used in tpl:single-service, content 1-5 | post.php?post=90003&action=edit\n",
            $index
        );
        $this->assertStringNotContainsString('page:about', $index, 'Content that uses an item is a stat, never named in the index.');
    }

    public function testCountsMovingWithinTheirBandLeaveTheIndexBytesAlone(): void
    {
        $withPages = static fn (int $pages): array => ['layout' => [
            new Item('pat:promo', 'pattern', 'Promo', ['registered' => false, 'synced' => true], 'post.php?post=90004&action=edit', [], [
                'usage_count' => $pages + 1,
                'used_in'     => ['tpl:single-service', ...array_map(static fn (int $i): string => 'page:p' . $i, range(1, $pages))],
            ]),
        ]];

        $before = FixtureManifest::manifest($withPages(1));
        $after = FixtureManifest::manifest($withPages(5), false, 7);

        $this->assertSame($before->hash, $after->hash);
        $this->assertSame((new IndexRenderer())->render($before), (new IndexRenderer())->render($after));

        $sixth = (new IndexRenderer())->render(FixtureManifest::manifest($withPages(6)));
        $this->assertStringContainsString('| Promo | synced; used in tpl:single-service, content 6-20 |', $sixth);
    }

    public function testAParentTemplateARouteUsesIsListedAlongWithWhatItPullsIn(): void
    {
        $manifest = FixtureManifest::manifest(['routes' => [
            new Item('route:single-post', 'route', 'Single post', ['path' => '/hello-world/'], null, ['tpl:single']),
        ]]);
        $index = (new IndexRenderer())->render($manifest);

        $this->assertStringContainsString("\ntpl:single | Single Posts |", $index);
        $this->assertStringContainsString("\npat:twentytwentyfive/post-meta | Post meta | registered; used in tpl:single\n", $index);
        $this->assertStringContainsString("\nroute:single-post | Single post | path /hello-world/ | - | tpl:single\n", $index);
        $this->assertStringContainsString("\ntpl: +1 unused from twentytwentyfive (section layout)\n", $index);
        $this->assertStringNotContainsString('pat: +', $index);
    }

    public function testASampledRoutePrintsItsPatternAndNeverItsExample(): void
    {
        $route = static fn (string $slug): Item => new Item(
            'route:single-post',
            'route',
            'Single post',
            ['pattern' => '/{slug}/', 'template' => 'single'],
            null,
            ['tpl:single'],
            ['example' => ['content_source' => 'post:' . $slug, 'path' => '/' . $slug . '/', 'refs' => []]]
        );

        $before = FixtureManifest::manifest(['routes' => [$route('hello-world')]]);
        $after = FixtureManifest::manifest(['routes' => [$route('aardvark')]]);
        $index = (new IndexRenderer())->render($before);

        $this->assertStringContainsString("\nroute:single-post | Single post | pattern /{slug}/; template single | - | tpl:single\n", $index);
        $this->assertStringNotContainsString('hello-world', $index);
        $this->assertSame($before->hash, $after->hash);
        $this->assertNotSame($before->statsHash, $after->statsHash);
        $this->assertSame($index, (new IndexRenderer())->render($after));
    }

    public function testAgencyNotesSitIndentedUnderTheirItem(): void
    {
        $index = (new IndexRenderer())->render(FixtureManifest::manifest(), ['pt:service' => [
            ['body' => 'Prices show on the service cards', 'status' => 'needs_review'],
            ['body' => "SKU is for the\nwarehouse", 'status' => 'ok'],
        ]]);

        $this->assertMatchesRegularExpression(
            '/\npt:service \| [^\n]*\n  note: Prices show on the service cards \(needs review\)\n  note: SKU is for the warehouse\npt:team_member /',
            $index
        );
    }

    public function testOverflowDropsRefsFirstThenFieldListsThenItems(): void
    {
        $manifest = FixtureManifest::manifest();
        $full = (new IndexRenderer())->render($manifest);

        // Just under the full size: the refs column goes, nothing else.
        $noRefs = (new IndexRenderer(Compact::length($full) - 1))->render($manifest);
        $this->assertStringContainsString("\npt:service | Services | 6-20 published; archive /services/; fields duration, internal_sku, price_from; defined in mu-plugins/vwf-fixture-core.php | edit.php?post_type=service\n", $noRefs);
        $this->assertStringNotContainsString(',tax:service_area', $noRefs);

        // Just under that: field lists collapse to counts.
        $counts = (new IndexRenderer(Compact::length($noRefs) - 1))->render($manifest);
        $this->assertStringContainsString("\npt:service | Services | 6-20 published; archive /services/; 3 fields; defined in mu-plugins/vwf-fixture-core.php | edit.php?post_type=service\n", $counts);

        // Far under: items go from the end, options and fields first, and each kind a section lost is
        // named with the section that returns it.
        $cut = (new IndexRenderer(1500))->render($manifest);
        $this->assertLessThanOrEqual(1500, Compact::length($cut));
        $this->assertStringContainsString("\ntpl:single-service |", $cut);
        $this->assertStringNotContainsString("\nbind:vwf-fixture/business-info.address |", $cut);
        $this->assertStringContainsString("\n+2 more bindings (section layout)\n", $cut);
        $this->assertStringContainsString("\n+6 more fields (section content_model)\n", $cut);
        $this->assertStringEndsWith("\n+7 more options (section site)\n", $cut);
    }

    public function testAPipeOrNewlineInALabelCannotBreakALine(): void
    {
        $manifest = FixtureManifest::manifest(['layout' => [
            new Item('nav:odd', 'navigation', "Top | bottom\nmenu", [], 'site-editor.php', [], ['item_count' => 1, 'usage_count' => 0, 'used_in' => []]),
        ]]);

        $this->assertStringContainsString(
            "\nnav:odd | Top / bottom menu | 1-5 items; unused | site-editor.php\n",
            (new IndexRenderer())->render($manifest)
        );
    }

    public function testTheFixtureIndexFitsItsBudget(): void
    {
        $index = (new IndexRenderer())->render(FixtureManifest::manifest());

        $this->assertLessThanOrEqual(IndexRenderer::BUDGET, Compact::length($index));
        $this->assertDoesNotMatchRegularExpression('/^\+\d+ more /m', $index, 'Nothing left out.');
        $this->assertStringNotContainsString('which has no posts', $index, 'Every fixture post type has posts.');
    }
}
