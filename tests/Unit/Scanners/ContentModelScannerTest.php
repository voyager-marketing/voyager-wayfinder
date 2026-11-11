<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Scanners\ContentModelScanner;
use Voyager\Wayfinder\Tests\Support\FixedSite;

/**
 * The lookups in FixedSite mirror what WordPress 7.1 with ACF returns on the
 * seeded fixture site (fixtures/mu-plugins/vwf-fixture-core.php), trimmed to
 * the types and taxonomies that exercise a rule.
 */
final class ContentModelScannerTest extends TestCase
{
    public function testTheFixtureContentModelProducesItsIds(): void
    {
        $ids = array_map(static fn (Item $item): string => $item->id, $this->scan()->items);

        $this->assertSame([
            'field:page.footnotes',
            'field:post.footnotes',
            'field:service.duration',
            'field:service.internal_sku',
            'field:service.price_from',
            'field:team_member.linkedin',
            'field:team_member.photo',
            'field:team_member.role',
            'pt:attachment',
            'pt:page',
            'pt:post',
            'pt:service',
            'pt:team_member',
            'tax:category',
            'tax:post_format',
            'tax:service_area',
        ], $ids);
        $this->assertSame('content_model', $this->scan()->name);
    }

    public function testInternalAndPluginPostTypesAndTheirTaxonomiesAreLeftOut(): void
    {
        $postTypes = [
            FixedSite::postType('wp_block', ['public' => false, 'show_ui' => true]),
            FixedSite::postType('wp_navigation', ['public' => false, 'show_ui' => true]),
            FixedSite::postType('acf-field-group', ['public' => false, 'show_ui' => true]),
            FixedSite::postType('hidden_log', ['public' => false, 'show_ui' => false]),
            FixedSite::postType('book'),
        ];
        $taxonomies = [
            FixedSite::taxonomy('wp_pattern_category', ['wp_block'], ['public' => false, 'show_ui' => true]),
            FixedSite::taxonomy('link_category', ['link'], ['public' => false, 'show_ui' => true]),
            FixedSite::taxonomy('genre', ['book', 'wp_block']),
        ];

        $section = (new ContentModelScanner(
            static fn (): array => $postTypes,
            static fn (): array => $taxonomies,
            static fn (string $type): array => [],
            static fn (): ?array => null
        ))->scan();

        $this->assertSame(['pt:book', 'tax:genre'], array_map(static fn (Item $item): string => $item->id, $section->items));
        $this->assertSame(['pt:book'], $section->item('tax:genre')?->refs);
    }

    public function testPostTypeFactsEditPathsAndRefs(): void
    {
        $section = $this->scan();

        $service = $section->item('pt:service');
        $this->assertNotNull($service);
        $this->assertSame('post_type', $service->kind);
        $this->assertSame('Services', $service->label);
        $this->assertSame('edit.php?post_type=service', $service->edit);
        $this->assertSame(['counts' => ['draft' => 1, 'private' => 0, 'publish' => 6]], $service->stats);
        $this->assertArrayNotHasKey('counts', $service->facts);
        $this->assertTrue($service->facts['public']);
        $this->assertTrue($service->facts['has_archive']);
        $this->assertSame('services', $service->facts['rewrite_slug']);
        $this->assertSame(['custom-fields', 'editor', 'excerpt', 'thumbnail', 'title'], $service->facts['supports']);
        $this->assertFalse($service->facts['hierarchical']);
        $this->assertTrue($service->facts['show_in_rest']);
        $this->assertSame([
            'field:service.duration',
            'field:service.internal_sku',
            'field:service.price_from',
            'tax:service_area',
        ], $service->refs);

        $team = $section->item('pt:team_member');
        $this->assertNotNull($team);
        $this->assertFalse($team->facts['public']);
        $this->assertTrue($team->facts['show_ui']);
        $this->assertNull($team->facts['rewrite_slug']);
        $this->assertSame('Team', $team->facts['menu_name']);
        $this->assertSame('edit.php?post_type=team_member', $team->edit);

        $this->assertSame('edit.php', $section->item('pt:post')?->edit);
        $this->assertSame('upload.php', $section->item('pt:attachment')?->edit);
        $this->assertSame(['draft' => 0, 'inherit' => 3, 'private' => 0, 'publish' => 0], $section->item('pt:attachment')?->stats['counts']);
    }

    public function testRegisteredMetaSkipsProtectedKeysAndKnowsWhatReachesRest(): void
    {
        $section = $this->scan();

        $sku = $section->item('field:service.internal_sku');
        $this->assertNotNull($sku);
        $this->assertSame('field', $sku->kind);
        $this->assertSame('Internal SKU', $sku->label);
        $this->assertSame('edit.php?post_type=service', $sku->edit);
        $this->assertSame(['pt:service'], $sku->refs);
        $this->assertSame([
            'in_rest'      => false,
            'show_in_rest' => false,
            'single'       => true,
            'source'       => 'registered_meta',
            'type'         => 'string',
        ], $sku->facts);

        $this->assertSame('number', $section->item('field:service.price_from')?->facts['type']);
        $this->assertTrue($section->item('field:service.price_from')?->facts['in_rest']);
        $this->assertNull($section->item('field:service._edit_lock'));
        $this->assertSame('footnotes', $section->item('field:post.footnotes')?->label);
    }

    public function testMetaOnATypeWithoutCustomFieldsSupportIsNotInRest(): void
    {
        $section = (new ContentModelScanner(
            fn (): array => [FixedSite::postType('book', ['supports' => ['title', 'editor']])],
            static fn (): array => [],
            static fn (string $type): array => ['isbn' => ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'label' => 'ISBN']],
            static fn (): ?array => null
        ))->scan();

        $isbn = $section->item('field:book.isbn');
        $this->assertNotNull($isbn);
        $this->assertTrue($isbn->facts['show_in_rest']);
        $this->assertFalse($isbn->facts['in_rest']);
    }

    public function testAcfFieldsResolveToTheirPostTypeAndSayWhereTheGroupLives(): void
    {
        $section = $this->scan();

        $role = $section->item('field:team_member.role');
        $this->assertNotNull($role);
        $this->assertSame('Role', $role->label);
        $this->assertSame(['pt:team_member'], $role->refs);
        $this->assertSame([
            'group'           => 'Team member details',
            'group_edit_path' => 'defined in code',
            'in_rest'         => false,
            'required'        => false,
            'show_in_rest'    => false,
            'source'          => 'acf',
            'type'            => 'text',
        ], $role->facts);
        $this->assertSame('image', $section->item('field:team_member.photo')?->facts['type']);
        $this->assertSame('acf', $section->item('field:team_member.linkedin')?->facts['source']);

        $database = $this->scanWithAcf([
            FixedSite::acfGroup('group_db', [[['post_type', '==', 'service']]], [['name' => 'warranty', 'type' => 'number', 'required' => true]], ['id' => 41, 'show_in_rest' => true]),
        ]);
        $warranty = $database->item('field:service.warranty');
        $this->assertNotNull($warranty);
        $this->assertSame('post.php?post=41&action=edit', $warranty->facts['group_edit_path']);
        $this->assertTrue($warranty->facts['required']);
        $this->assertTrue($warranty->facts['in_rest']);
    }

    public function testAcfLocationRulesBeyondPostTypeAreKeptRaw(): void
    {
        $section = $this->scanWithAcf([
            FixedSite::acfGroup('group_contact', [
                [['post_type', '==', 'page'], ['page_template', '==', 'page-contact']],
                [['post_type', '==', 'service']],
            ], [['name' => 'map_embed', 'type' => 'textarea']]),
            FixedSite::acfGroup('group_options', [[['options_page', '==', 'site-settings']]], [['name' => 'footer_note']]),
            FixedSite::acfGroup('group_not_posts', [[['post_type', '!=', 'post']]], [['name' => 'teaser']]),
            FixedSite::acfGroup('group_layout', [[['post_type', '==', 'service']]], [['name' => '', 'type' => 'tab'], ['name' => 'details_tab', 'type' => 'tab']]),
            FixedSite::acfGroup('group_off', [[['post_type', '==', 'service']]], [['name' => 'retired']], ['active' => false]),
        ]);

        $expected = ['post_type == page and page_template == page-contact', 'post_type == service'];
        $this->assertSame($expected, $section->item('field:page.map_embed')?->facts['location']);
        $this->assertSame($expected, $section->item('field:service.map_embed')?->facts['location']);
        $this->assertArrayNotHasKey('location', $section->item('field:team_member.role')?->facts ?? []);

        $ids = array_map(static fn (Item $item): string => $item->id, $section->items);
        $this->assertSame([], array_values(array_filter($ids, static fn (string $id): bool => (bool) preg_match('/footer_note|teaser|details_tab|retired/', $id))));
    }

    public function testRegisteredMetaWinsWhenAcfDescribesTheSameKey(): void
    {
        $section = $this->scanWithAcf([
            FixedSite::acfGroup('group_dupe', [[['post_type', '==', 'service']]], [['name' => 'price_from', 'type' => 'text']]),
        ]);

        $this->assertSame('registered_meta', $section->item('field:service.price_from')?->facts['source']);
    }

    public function testWithoutAcfOnlyRegisteredMetaIsScanned(): void
    {
        $section = (new ContentModelScanner(
            FixedSite::postTypes(...),
            FixedSite::taxonomies(...),
            FixedSite::registeredMeta(...),
            static fn (): ?array => null
        ))->scan();

        $this->assertNull($section->item('field:team_member.role'));
        $this->assertNotNull($section->item('field:service.price_from'));
        $this->assertSame([], $section->item('pt:team_member')?->refs);
        $this->assertSame('Team members.', $section->item('pt:team_member')?->facts['summary']);
    }

    public function testTaxonomyFactsEditPathAndRefs(): void
    {
        $section = $this->scan();

        $area = $section->item('tax:service_area');
        $this->assertNotNull($area);
        $this->assertSame('taxonomy', $area->kind);
        $this->assertSame('Service areas', $area->label);
        $this->assertSame('edit-tags.php?taxonomy=service_area&post_type=service', $area->edit);
        $this->assertSame(['pt:service'], $area->refs);
        $this->assertSame([
            'defined_in'   => 'mu-plugins/vwf-fixture-core.php',
            'hierarchical' => false,
            'object_types' => ['service'],
            'public'       => true,
            'show_in_rest' => true,
            'show_ui'      => true,
        ], $area->facts);
        $this->assertSame(['terms' => 5], $area->stats);

        // post_format is public but has no admin screen.
        $this->assertNull($section->item('tax:post_format')?->edit);
    }

    public function testPostTypesAndTaxonomiesSayWhichFileRegisteredThem(): void
    {
        $section = $this->scan();

        $this->assertSame('mu-plugins/vwf-fixture-core.php', $section->item('pt:service')?->facts['defined_in']);
        $this->assertSame('mu-plugins/vwf-fixture-core.php', $section->item('pt:team_member')?->facts['defined_in']);
        $this->assertSame('core', $section->item('pt:post')?->facts['defined_in']);
        $this->assertSame('core', $section->item('tax:category')?->facts['defined_in']);
    }

    public function testATypeRegisteredBeforeWayfinderLoadedHasNoDefinedIn(): void
    {
        $section = (new ContentModelScanner(
            fn (): array => [FixedSite::postType('early')],
            fn (): array => [FixedSite::taxonomy('early_tag', ['early'])],
            static fn (): array => [],
            static fn (): ?array => null
        ))->scan();

        $this->assertArrayHasKey('defined_in', $section->item('pt:early')?->facts ?? []);
        $this->assertNull($section->item('pt:early')?->facts['defined_in']);
        $this->assertNull($section->item('tax:early_tag')?->facts['defined_in']);
    }

    public function testEachPostTypeCarriesAPlainSummary(): void
    {
        $section = $this->scan();

        $this->assertSame(
            'Services. Fields: duration (text), internal sku (text, not in REST), price from (number). Grouped by service area.',
            $section->item('pt:service')?->facts['summary']
        );
        $this->assertSame(
            'Team members. Fields: linkedin (url, not in REST), photo (image, not in REST), role (text, not in REST).',
            $section->item('pt:team_member')?->facts['summary']
        );
        $this->assertSame(
            'Posts. Fields: footnotes (text). Grouped by category and post format.',
            $section->item('pt:post')?->facts['summary']
        );
        $this->assertSame('Media.', $section->item('pt:attachment')?->facts['summary']);
    }

    public function testASummaryCarriesNoNumbersSoPublishingLeavesTheHashAlone(): void
    {
        $before = $this->scan();
        $after = (new ContentModelScanner(
            fn (): array => array_map(
                static fn (array $row): array => $row['slug'] === 'service'
                    ? ['counts' => ['publish' => 7, 'draft' => 1]] + $row
                    : $row,
                FixedSite::postTypes()
            ),
            fn (): array => array_map(
                static fn (array $row): array => $row['slug'] === 'service_area' ? ['terms' => 6] + $row : $row,
                FixedSite::taxonomies()
            ),
            FixedSite::registeredMeta(...),
            FixedSite::acfGroups(...)
        ))->scan();

        foreach ($before->items as $item) {
            if ($item->kind === 'post_type') {
                $this->assertDoesNotMatchRegularExpression('/\d/', (string) $item->facts['summary'], $item->id);
            }
        }

        $this->assertSame(6, $before->item('pt:service')?->stats['counts']['publish']);
        $this->assertSame(7, $after->item('pt:service')?->stats['counts']['publish']);
        $this->assertSame(6, $after->item('tax:service_area')?->stats['terms']);
        $this->assertSame($before->item('pt:service')?->hash, $after->item('pt:service')?->hash);
        $this->assertSame($before->item('tax:service_area')?->hash, $after->item('tax:service_area')?->hash);
        $this->assertSame($before->hash, $after->hash);
    }

    public function testLookupOrderDoesNotChangeTheBytes(): void
    {
        $shuffled = new ContentModelScanner(
            fn (): array => array_reverse(FixedSite::postTypes()),
            fn (): array => array_reverse(FixedSite::taxonomies()),
            fn (string $type): array => array_reverse(FixedSite::registeredMeta($type), true),
            fn (): array => array_reverse(FixedSite::acfGroups())
        );

        $this->assertSame($this->scan()->hash, $shuffled->scan()->hash);
    }

    private function scan(): Section
    {
        return $this->scanWithAcf(FixedSite::acfGroups());
    }

    /**
     * @param list<array<string, mixed>> $groups
     */
    private function scanWithAcf(array $groups): Section
    {
        return (new ContentModelScanner(
            FixedSite::postTypes(...),
            FixedSite::taxonomies(...),
            FixedSite::registeredMeta(...),
            static fn (): array => $groups
        ))->scan();
    }
}
