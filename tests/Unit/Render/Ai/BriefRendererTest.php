<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\Compact;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Scanners\ThemeGates;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;

final class BriefRendererTest extends TestCase
{
    public function testTheBriefOpensWithTheHeaderLineAndTheManifestHash(): void
    {
        $manifest = FixtureManifest::manifest();
        $lines = explode("\n", (new BriefRenderer())->render($manifest));

        $this->assertSame('# wayfinder v1 brief h:' . substr($manifest->hash, 0, 6), $lines[0]);
        $this->assertSame(
            'site: Riverbend Plumbing | WordPress 7.1.2 | theme vwf-fixture, child of twentytwentyfive | front page home | posts page blog | permalinks /%postname%/',
            $lines[1]
        );
    }

    public function testTheFixtureBriefFitsItsBudgetAndHasThePhoneAndCtaEditLines(): void
    {
        $brief = (new BriefRenderer())->render(FixtureManifest::manifest());

        $this->assertLessThanOrEqual(BriefRenderer::BUDGET, Compact::length($brief));
        $this->assertStringContainsString(
            "\nedit: bind:vwf-fixture/business-info.phone | Business info: phone | reads option:vwf_phone; in part:footer | " . FixtureManifest::BUSINESS_INFO . "\n",
            $brief
        );
        $this->assertStringContainsString(
            "\nedit: pat:cta-banner | CTA banner | synced, used 1-5 times, one edit changes all | post.php?post=90003&action=edit\n",
            $brief
        );
    }

    public function testTheContentLineCountsPostTypesNamesFieldsAndMarksAdminOnlyTypes(): void
    {
        $brief = (new BriefRenderer())->render(FixtureManifest::manifest());

        $this->assertStringContainsString(
            "\ncontent: Services 6-20 (duration, internal_sku, price_from) | Team members 1-5 admin-only (linkedin, photo, role) | Pages 1-5 | Posts 1-5\n",
            $brief
        );
        $this->assertStringContainsString("\nterms: Service areas 1-5 (on service)\n", $brief);
    }

    public function testTheTermsLinePutsCustomTaxonomiesFirstAndSkipsEmptyBuiltIns(): void
    {
        $manifest = FixtureManifest::manifest(['content_model' => [
            new Item('tax:category', 'taxonomy', 'Categories', ['object_types' => ['post']], 'edit-tags.php?taxonomy=category', [], ['terms' => 1]),
            new Item('tax:post_tag', 'taxonomy', 'Tags', ['object_types' => ['post']], 'edit-tags.php?taxonomy=post_tag', [], ['terms' => 0]),
        ]]);

        $this->assertStringContainsString(
            "\nterms: Service areas 1-5 (on service) | Categories 1-5 (on post)\n",
            (new BriefRenderer())->render($manifest)
        );
    }

    public function testTheContentLineNamesAtMostThreeFieldsPerPostType(): void
    {
        $manifest = FixtureManifest::manifest(['content_model' => [
            FixtureManifest::postType('job', 'Jobs', 2, true, ['a', 'b', 'c', 'd', 'e']),
        ]]);

        $this->assertStringContainsString('Jobs 1-5 (a, b, c +2)', (new BriefRenderer())->render($manifest));
    }

    public function testEditLinesAreRankedAndCappedAtEight(): void
    {
        $brief = (new BriefRenderer())->render(FixtureManifest::manifest());
        preg_match_all('/^edit: (\S+) /m', $brief, $matches);

        // Custom post types, header and footer parts, bindings, shared synced
        // patterns, then menus: the ninth candidate (nav:primary) is cut.
        $this->assertSame([
            'pt:service',
            'pt:team_member',
            'part:footer',
            'part:header',
            'bind:vwf-fixture/business-info.address',
            'bind:vwf-fixture/business-info.phone',
            'pat:cta-banner',
            'nav:footer',
        ], $matches[1]);
    }

    public function testEditLinesSkipEmptyPostTypesAndLeaveRoomForPartsAndBindings(): void
    {
        // Shaped like Voyager's own site: eight custom post types, six of them empty.
        $brief = (new BriefRenderer())->render(self::siteWith([
            'industry_page' => 0, 'neighborhood' => 0, 'notion_page' => 0, 'portfolio' => 8,
            'service_area'  => 0, 'services' => 12, 'team' => 0, 'testimonials' => 0,
        ]));

        $this->assertSame([
            'pt:portfolio',
            'pt:services',
            'part:footer',
            'part:header',
            'bind:voyager/site.address',
            'bind:voyager/site.email',
            'bind:voyager/site.phone',
            'pat:cta',
        ], self::editIds($brief));
    }

    public function testCustomPostTypesTakeAtMostThreeEditLinesMostPublishedFirst(): void
    {
        $brief = (new BriefRenderer())->render(self::siteWith([
            'alpha' => 3, 'bravo' => 30, 'charlie' => 12, 'delta' => 120, 'echo' => 2,
        ]));

        // Bands: delta 100+, bravo 21-100, charlie 6-20; alpha and echo (1-5) lose out.
        $this->assertSame(['pt:delta', 'pt:bravo', 'pt:charlie', 'part:footer', 'part:header'], array_slice(self::editIds($brief), 0, 5));
        $this->assertStringContainsString("\ncontent: Alpha 1-5", $brief, 'the content line still lists every type');
    }

    public function testBindingsKeepAnEditLineWhenManyHeaderAndFooterPartsAreListed(): void
    {
        $parts = [];
        foreach (['footer', 'footer-columns', 'footer-newsletter', 'header', 'header-large', 'vertical-header'] as $slug) {
            $parts[] = self::part($slug, str_contains($slug, 'header') ? 'header' : 'footer');
        }

        $ids = self::editIds((new BriefRenderer())->render(self::siteWith(['services' => 12], $parts)));

        $this->assertCount(2, array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'part:')));
        $this->assertContains('bind:voyager/site.address', $ids);
    }

    public function testBriefFitsItsBudgetOnALargeSite(): void
    {
        $brief = (new BriefRenderer())->render(self::largeSite());
        $lines = explode("\n", rtrim($brief, "\n"));

        $this->assertLessThanOrEqual(BriefRenderer::BUDGET, Compact::length($brief));
        $this->assertStringStartsWith('# wayfinder v1 brief h:', $lines[0]);
        $this->assertStringStartsWith('site: Voyager Marketing | WordPress 7.1 | theme voyagermark, child of voyager-block-theme', $lines[1]);
        $this->assertStringStartsWith('more: index (every item, one line each)', end($lines));
        $this->assertContains('locks: part:header, part:footer: 3 locked blocks at the edit path, strictest all (template_lock), content only inside a template (WordPress 7 default)', $lines);
    }

    public function testIndexFitsItsBudgetOnALargeSiteWithTheLockAndClientLines(): void
    {
        $index = (new IndexRenderer())->render(self::largeSite());

        $this->assertLessThanOrEqual(IndexRenderer::BUDGET, Compact::length($index));
        $this->assertStringContainsString('area footer; editor cannot open: needs edit_theme_options; locked blocks Company phone, Company address, social-links at 0.1.2 (all, template_lock: cannot be moved or removed); content only inside a template', $index);
        $this->assertSame(1, substr_count($index, 'the same settings also hold voyager_site_fax (Fax number), voyager_site_tax_id (Tax id)'), 'Named once per source.');
    }

    public function testOverflowNamesFewerFieldsFirst(): void
    {
        $full = (new BriefRenderer(PHP_INT_MAX))->render(self::largeSite());
        $budget = Compact::length($full) - 1;
        $brief = (new BriefRenderer($budget))->render(self::largeSite());

        $this->assertLessThanOrEqual($budget, Compact::length($brief));
        $this->assertStringContainsString('Services 6-20 (services_f1 +9)', $brief, 'one field per post type before anything else goes');
        $this->assertStringContainsString("\nterms: ", $brief);
        $this->assertSame(self::editIds($full), self::editIds($brief), 'every edit line kept');
    }

    public function testOverflowThenCutsContentTermsAndPluginsEntries(): void
    {
        $brief = (new BriefRenderer(2050))->render(self::largeSite());

        $this->assertLessThanOrEqual(2050, Compact::length($brief));
        $this->assertDoesNotMatchRegularExpression('/\(\w+_f\d+/', $brief, 'no field names left');
        $this->assertMatchesRegularExpression('/^content: .* \| \+\d+ more$/m', $brief);
        $this->assertCount(8, self::editIds($brief), 'edit lines are the last thing to go');
    }

    public function testOverflowDropsEditLinesLast(): void
    {
        $brief = (new BriefRenderer(900))->render(self::largeSite());

        $this->assertLessThanOrEqual(900, Compact::length($brief));
        $this->assertLessThan(8, count(self::editIds($brief)));
        $this->assertStringContainsString("\ncontent: +", $brief, 'every content entry is already cut');
    }

    public function testTheBriefCountsNotesAndThoseThatNeedReview(): void
    {
        $manifest = FixtureManifest::manifest();
        $renderer = new BriefRenderer();

        $this->assertStringContainsString("\nnotes: none\n", $renderer->render($manifest));
        $this->assertStringContainsString(
            "\nnotes: 2 agency notes on 1 item, shown in the index | 1 needing review\n",
            $renderer->render($manifest, ['pt:service' => [
                ['body' => 'Prices show on the service cards', 'status' => 'needs_review'],
                ['body' => 'SKU is for the warehouse', 'status' => 'ok'],
            ]])
        );
    }

    public function testPublishingAServiceWithinItsBandLeavesTheBriefBytesAlone(): void
    {
        $six = FixtureManifest::manifest();
        $seven = FixtureManifest::manifest(services: 7);

        $this->assertSame($six->hash, $seven->hash);
        $this->assertNotSame($six->statsHash, $seven->statsHash);
        $this->assertSame((new BriefRenderer())->render($six), (new BriefRenderer())->render($seven));
    }

    public function testACountLeavingItsBandChangesTheBrief(): void
    {
        $twenty = (new BriefRenderer())->render(FixtureManifest::manifest(services: 20));
        $twentyOne = (new BriefRenderer())->render(FixtureManifest::manifest(services: 21));

        $this->assertStringContainsString("\ncontent: Services 6-20 (", $twenty);
        $this->assertStringContainsString("\ncontent: Services 21-100 (", $twentyOne);
        $this->assertStringContainsString("\nedit: pt:service | Services | 21-100 published |", $twentyOne);
    }

    public function testASyncedPatternUsedOnceStillGetsItsEditLineAndUsesWithinABandRenderAlike(): void
    {
        $brief = static fn (int $uses): string => (new BriefRenderer())->render(FixtureManifest::manifest(['layout' => [
            new Item('pat:promo', 'pattern', 'Promo', ['registered' => false, 'synced' => true], 'post.php?post=90004&action=edit', [], [
                'usage_count' => $uses,
                'used_in'     => array_map(static fn (int $i): string => 'page:p' . $i, $uses > 0 ? range(1, $uses) : []),
            ]),
        ]]));

        $this->assertStringContainsString("\nedit: pat:promo | Promo | synced, used 1-5 times, one edit changes all |", $brief(1));
        $this->assertSame($brief(1), $brief(2));
        $this->assertSame($brief(2), $brief(5));
        $this->assertStringNotContainsString('pat:promo', $brief(0));
    }

    public function testTheLocksLineNamesTheHeaderAndFooterPartsLocksAndChangesNothingElse(): void
    {
        $lock = ['lock' => ['effective' => 'all', 'locked' => 2, 'src' => 'template_lock']];
        $plain = [self::part('footer', 'footer'), self::part('header', 'header')];
        $locked = [];
        foreach ($plain as $part) {
            $locked[] = new Item($part->id, $part->kind, $part->label, $part->facts + $lock, $part->edit);
        }
        $locked[1] = new Item('part:header', 'template_part', 'Header', ['area' => 'header', 'lock' => ['effective' => 'none', 'locked' => 0, 'src' => 'none']], $plain[1]->edit);

        $without = (new BriefRenderer())->render(self::siteWith([], $plain));
        $with = (new BriefRenderer())->render(self::siteWith([], $locked));
        $line = "locks: part:header: not locked at the edit path | part:footer: 2 locked blocks at the edit path, strictest all (template_lock)\n";

        $this->assertStringNotContainsString('locks:', $without, 'No lock fact, as on a classic theme: no line.');
        $this->assertStringContainsString("\nlayout: ", $with);
        $this->assertMatchesRegularExpression('/\nlayout: [^\n]*\n' . preg_quote($line, '/') . '/', $with, 'The line follows the layout line.');
        $this->assertSame(
            preg_replace('/ h:[0-9a-f]{6}\n/', "\n", $without),
            preg_replace('/ h:[0-9a-f]{6}\n/', "\n", str_replace($line, '', $with)),
            'Apart from the header hash, the lock line is the only change.'
        );
    }

    public function testTheLastLineNamesTheOtherTiersAndTheSections(): void
    {
        $lines = explode("\n", rtrim((new BriefRenderer())->render(FixtureManifest::manifest()), "\n"));

        $this->assertSame(
            'more: index (every item, one line each) | section <section or item id> (full JSON; sections content_model, integrations, layout, site) | changes since <hash> (changed ids)',
            end($lines)
        );
    }

    /**
     * @return list<string> The ids of the brief's edit lines, in order.
     */
    private static function editIds(string $brief): array
    {
        preg_match_all('/^edit: (\S+) /m', $brief, $matches);

        return $matches[1];
    }

    /**
     * A site with these custom post types (slug => published), Pages and
     * Posts, header and footer parts, three bindings, a synced pattern in
     * use and a menu: more edit candidates than the brief has lines for.
     *
     * @param array<string, int> $postTypes
     * @param list<Item>|null    $parts     Defaults to one header and one footer part.
     */
    public function testALargeSiteWithEveryGateSetStillFitsAndKeepsItsEditLines(): void
    {
        $gates = ThemeGates::item(
            ['appearanceTools' => false, 'blocks' => ['core/button' => ['color' => ['custom' => true]]]],
            ['appearanceTools' => false],
            ['appearanceTools' => false],
            ['industry_page' => ['core/paragraph'], 'page' => true, 'post' => true]
        );
        $without = (new BriefRenderer())->render(self::largeSite());
        $brief = (new BriefRenderer())->render(self::largeSite([$gates]));

        $this->assertLessThanOrEqual(BriefRenderer::BUDGET, Compact::length($brief));
        $this->assertSame(1, substr_count($brief, "\ngates: "));
        $this->assertSame(substr_count($without, "\nedit: "), substr_count($brief, "\nedit: "));
    }

    private static function siteWith(array $postTypes, ?array $parts = null): Manifest
    {
        $content = [
            FixtureManifest::postType('page', 'Pages', 30, true),
            FixtureManifest::postType('post', 'Posts', 40, true),
        ];
        foreach ($postTypes as $slug => $published) {
            $content[] = FixtureManifest::postType($slug, ucfirst(str_replace('_', ' ', $slug)), $published, true);
        }

        $layout = $parts ?? [self::part('footer', 'footer'), self::part('header', 'header')];
        foreach (['address', 'email', 'phone'] as $key) {
            $layout[] = new Item('bind:voyager/site.' . $key, 'binding', 'Site: ' . $key, ['key' => $key, 'source' => 'voyager/site'], 'options-general.php?page=voyager-site', [], ['usage_count' => 1, 'used_in' => ['part:footer']]);
        }
        $layout[] = new Item('pat:cta', 'pattern', 'CTA', ['registered' => false, 'synced' => true], 'post.php?post=7&action=edit', [], ['usage_count' => 2, 'used_in' => ['page:home', 'page:about']]);
        $layout[] = new Item('nav:primary', 'navigation', 'Primary', [], 'site-editor.php?p=/wp_navigation/5', [], ['item_count' => 4, 'usage_count' => 1, 'used_in' => ['part:header']]);

        return new Manifest([new Section('content_model', $content), new Section('layout', $layout)]);
    }

    /**
     * Sized like Voyager's own site: 11 post types with long field lists, 11
     * taxonomies, 15 plugins, 8 bindings. Over the budget before overflow.
     *
     * @param list<Item> $extraSite More items for the site section.
     */
    private static function largeSite(array $extraSite = []): Manifest
    {
        $site = [
            ...$extraSite,
            new Item('core:wordpress', 'core', 'WordPress', ['version' => '7.1'], 'update-core.php'),
            new Item('opt:blogname', 'option', 'Site title', ['value' => 'Voyager Marketing'], 'options-general.php'),
            new Item('opt:page_for_posts', 'option', 'Posts page', ['value' => 'blog'], 'options-reading.php'),
            new Item('opt:page_on_front', 'option', 'Homepage', ['value' => 'home'], 'options-reading.php'),
            new Item('opt:permalink_structure', 'option', 'Permalink structure', ['value' => '/%postname%/'], 'options-permalink.php'),
            new Item('opt:show_on_front', 'option', 'Your homepage displays', ['value' => 'page'], 'options-reading.php'),
            new Item('opt:stylesheet', 'option', 'Active theme', ['value' => 'voyagermark'], 'themes.php', ['opt:template']),
            new Item('opt:template', 'option', 'Parent theme', ['value' => 'voyager-block-theme'], 'themes.php'),
        ];

        $content = [];
        $postTypes = [
            'industry_page' => ['Industry Pages', 0, 15], 'neighborhood' => ['Neighborhoods', 0, 12],
            'notion_page'   => ['Notion Pages', 0, 7], 'portfolio' => ['Portfolio', 8, 5],
            'service_area'  => ['Service Areas', 0, 33], 'services' => ['Services', 12, 10],
            'team'          => ['Team Members', 0, 4], 'testimonials' => ['Testimonials', 0, 4],
            'attachment'    => ['Media', 150, 2], 'page' => ['Pages', 40, 3], 'post' => ['Posts', 50, 3],
        ];
        foreach ($postTypes as $slug => [$label, $published, $fields]) {
            $keys = array_map(static fn (int $i): string => str_replace('_', '', $slug) . '_f' . $i, range(1, $fields));
            $content[] = FixtureManifest::postType($slug, $label, $published, true, $keys === [] ? [] : $keys);
        }
        $taxonomies = [
            'department' => ['Departments', 'team'], 'industry_category' => ['Industry Categories', 'industry_page'],
            'notion_category' => ['Notion Categories', 'notion_page'], 'portfolio_category' => ['Portfolio Categories', 'portfolio'],
            'portfolio_tag' => ['Portfolio Tags', 'portfolio'], 'region' => ['Regions', 'service_area'],
            'service_category' => ['Service Categories', 'services'], 'testimonial_category' => ['Testimonial Categories', 'testimonials'],
            'topic_cluster' => ['Topic Clusters', 'notion_page'], 'category' => ['Categories', 'post'], 'post_tag' => ['Tags', 'post'],
        ];
        foreach ($taxonomies as $slug => [$label, $type]) {
            $content[] = new Item('tax:' . $slug, 'taxonomy', $label, ['object_types' => [$type], 'public' => true, 'show_ui' => true], 'edit-tags.php?taxonomy=' . $slug, [], ['terms' => 8]);
        }

        $plugins = [];
        foreach (['abilities-api', 'ai-provider-for-anthropic', 'create-block-theme', 'limit-login-attempts-reloaded', 'mcp-adapter', 'postmark-approved-wordpress-plugin', 'remote-data-blocks', 'seo-by-rank-math', 'spinupwp', 'voyager-blocks', 'voyager-core', 'voyager-orbit', 'voyager-wayfinder', 'wordpress-beta-tester', 'wp-ai-client'] as $slug) {
            $plugins[] = new Item('plugin:' . $slug, 'plugin', $slug, ['role' => 'other', 'version' => '1.0'], 'plugins.php');
        }

        // The parts and bindings carry the lock, client and unread settings facts, so the budget covers their lines.
        $cannot = static fn (string $cap): array => ['client' => ['can_open' => false, 'needs' => $cap, 'role' => 'editor']];
        $layout = [];
        foreach (['footer', 'header'] as $slug) {
            $part = self::part($slug, $slug);
            $layout[] = new Item(
                $part->id,
                $part->kind,
                $part->label,
                $part->facts + [
                    'lock'          => ['effective' => 'all', 'in_template' => 'content_only', 'locked' => 3, 'src' => 'template_lock'],
                    'locked_blocks' => [
                        ['block' => 'core/paragraph', 'effective' => 'all', 'name' => 'Company phone', 'path' => '0.1.0', 'src' => 'template_lock', 'text_editable' => false],
                        ['block' => 'core/paragraph', 'effective' => 'all', 'name' => 'Company address', 'path' => '0.1.1', 'src' => 'template_lock', 'text_editable' => false],
                        ['block' => 'core/social-links', 'effective' => 'all', 'name' => null, 'path' => '0.1.2', 'src' => 'template_lock', 'text_editable' => false],
                    ],
                ],
                $part->edit,
                [],
                $cannot('edit_theme_options')
            );
        }
        foreach (['address', 'email', 'phone', 'hours', 'linkedin', 'facebook', 'instagram', 'youtube'] as $key) {
            $unread = ['unread_settings' => [['label' => 'Fax number', 'option' => 'voyager_site_fax'], ['label' => 'Tax id', 'option' => 'voyager_site_tax_id']]];
            $layout[] = new Item('bind:voyager/site.' . $key, 'binding', 'Site: ' . $key, ['key' => $key, 'reads' => ['option:voyager_site_' . $key], 'source' => 'voyager/site'] + $unread, 'options-general.php?page=voyager-site', [], ['usage_count' => 1, 'used_in' => ['part:footer']] + $cannot('manage_options'));
        }

        return new Manifest([
            new Section('site', $site),
            new Section('content_model', $content),
            new Section('integrations', $plugins),
            new Section('layout', $layout),
        ]);
    }

    private static function part(string $slug, string $area): Item
    {
        return new Item('part:' . $slug, 'template_part', ucfirst($slug), ['area' => $area, 'slug' => $slug, 'source' => 'custom'], 'site-editor.php?p=/wp_template_part/voyagermark//' . $slug . '&canvas=edit');
    }
}
