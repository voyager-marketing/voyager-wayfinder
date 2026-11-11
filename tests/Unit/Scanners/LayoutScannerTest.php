<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;

/**
 * The layout section, run on the fixture theme's block markup and the rows
 * fixtures/seed.php creates, with no database.
 */
final class LayoutScannerTest extends TestCase
{
    public function testTheFixtureSectionHoldsTheNineLayoutIds(): void
    {
        $section = self::scan(FakeLayoutSource::fixture());

        $this->assertSame('layout', $section->name);
        $this->assertSame(
            [
                'bind:vwf-fixture/business-info.address',
                'bind:vwf-fixture/business-info.phone',
                'nav:footer',
                'nav:primary',
                'part:footer',
                'part:header',
                'pat:cta-banner',
                'tpl:page-contact',
                'tpl:single-service',
            ],
            array_map(static fn (Item $item): string => $item->id, $section->items)
        );
    }

    public function testTheCtaBannerReportsThreeUsagesWithTheTemplateFirst(): void
    {
        $pattern = self::item(FakeLayoutSource::fixture(), 'pat:cta-banner');

        $this->assertSame('pattern', $pattern->kind);
        $this->assertSame('CTA banner', $pattern->label);
        $this->assertSame(3, $pattern->stats['usage_count']);
        $this->assertSame(['tpl:single-service', 'page:about', 'page:home'], $pattern->stats['used_in']);
        $this->assertTrue($pattern->facts['synced']);
        $this->assertFalse($pattern->facts['registered']);
    }

    public function testTheFooterRefsItsMenuAndBothBusinessInfoBindings(): void
    {
        $footer = self::item(FakeLayoutSource::fixture(), 'part:footer');

        $this->assertSame('template_part', $footer->kind);
        $this->assertSame(
            ['bind:vwf-fixture/business-info.address', 'bind:vwf-fixture/business-info.phone', 'nav:footer'],
            $footer->refs
        );
        $this->assertSame('footer', $footer->facts['area']);
        $this->assertSame(['tpl:page-contact', 'tpl:single-service'], $footer->stats['used_in']);
    }

    public function testTemplateRefsAreDirectOnly(): void
    {
        $source = FakeLayoutSource::fixture();

        $this->assertSame(
            ['part:footer', 'part:header', 'pat:cta-banner'],
            self::item($source, 'tpl:single-service')->refs,
            'The footer menu and bindings belong to part:footer, not to the template that includes it.'
        );
        $this->assertSame(['part:footer', 'part:header'], self::item($source, 'tpl:page-contact')->refs);
    }

    public function testEditPathsPointAtTheSiteEditorOrThePostScreen(): void
    {
        $section = self::scan(FakeLayoutSource::fixture());

        $this->assertSame('site-editor.php?p=/wp_template/vwf-fixture//single-service&canvas=edit', $section->item('tpl:single-service')?->edit);
        $this->assertSame('site-editor.php?p=/wp_template_part/vwf-fixture//footer&canvas=edit', $section->item('part:footer')?->edit);
        $this->assertSame('site-editor.php?p=/wp_navigation/90002', $section->item('nav:footer')?->edit);
        $this->assertSame('post.php?post=90003&action=edit', $section->item('pat:cta-banner')?->edit);
        $this->assertSame('options-general.php?page=vwf-business-info', $section->item('bind:vwf-fixture/business-info.phone')?->edit);
    }

    public function testABindingIsGroupedBySourceAndKeyAndNamesWhatItBinds(): void
    {
        $phone = self::item(FakeLayoutSource::fixture(), 'bind:vwf-fixture/business-info.phone');

        $this->assertSame('binding', $phone->kind);
        $this->assertSame('Business info: phone', $phone->label);
        $this->assertSame(
            [
                'bound'        => ['core/paragraph.content'],
                'defined_in'   => 'mu-plugins/vwf-fixture-core.php',
                'key'          => 'phone',
                'reads'        => ['option:vwf_phone'],
                'source'       => 'vwf-fixture/business-info',
                'source_label' => 'Business info',
                'unread_settings' => [['label' => 'Hours', 'option' => 'vwf_hours']],
            ],
            $phone->facts
        );
        $this->assertSame(['usage_count' => 1, 'used_in' => ['part:footer']], array_diff_key($phone->stats, ['client' => true]));
        $this->assertSame([], $phone->refs);
    }

    public function testABindingWhoseSourceCannotBeEvaluatedStillScansWithoutReads(): void
    {
        // WordPressLayoutSource answers null when the source's callback throws.
        $source = FakeLayoutSource::fixture();
        $source->reads = [];

        $phone = self::item($source, 'bind:vwf-fixture/business-info.phone');

        $this->assertArrayHasKey('reads', $phone->facts);
        $this->assertNull($phone->facts['reads']);
        $this->assertSame('mu-plugins/vwf-fixture-core.php', $phone->facts['defined_in']);
        $this->assertSame('options-general.php?page=vwf-business-info', $phone->edit);
    }

    public function testAPostMetaBindingIsNotEvaluatedBecauseItRefsItsField(): void
    {
        $source = new FakeLayoutSource();
        $source->meta = ['price_from' => ['service']];
        $source->sourceFiles = ['core/post-meta' => 'core'];
        $source->reads = ['core/post-meta' => ['price_from' => ['meta:price_from']]];
        $source->templates = [FakeLayoutSource::row('single-service', implode("\n", [
            '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"price_from"}}}}} -->',
            '<p></p>',
            '<!-- /wp:paragraph -->',
        ]))];

        $binding = self::item($source, 'bind:core/post-meta.price_from');

        $this->assertNull($binding->facts['reads']);
        $this->assertSame('core', $binding->facts['defined_in']);
        $this->assertSame([], $source->readCalls);
    }

    public function testABindingIsEvaluatedOnceWithInputsThatDoNotDependOnWalkOrder(): void
    {
        $paragraph = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"acme/data","args":{"key":"x"}}}}} --><p></p><!-- /wp:paragraph -->';
        $heading = '<!-- wp:heading {"metadata":{"bindings":{"content":{"source":"acme/data","args":{"key":"x","fallback":"y"}}}}} --><h2></h2><!-- /wp:heading -->';

        $calls = [];

        foreach ([[$paragraph, $heading], [$heading, $paragraph]] as [$first, $second]) {
            $source = new FakeLayoutSource();
            $source->reads = ['acme/data' => ['x' => ['option:acme_x']]];
            $source->parts = [FakeLayoutSource::row('a', $first), FakeLayoutSource::row('b', $second)];

            $this->assertSame(['option:acme_x'], self::item($source, 'bind:acme/data.x')->facts['reads']);
            $calls[] = $source->readCalls;
        }

        $this->assertSame($calls[0], $calls[1]);
        $this->assertSame(
            [['source' => 'acme/data', 'key' => 'x', 'args' => ['key' => 'x', 'fallback' => 'y'], 'block' => 'core/heading', 'attribute' => 'content']],
            $calls[0],
            'The args whose canonical JSON sorts first, bound to the attribute that sorts first.'
        );
    }

    public function testTheBindingKeyFallsBackToTheFirstArgThenToTheSourceAlone(): void
    {
        $source = new FakeLayoutSource();
        $source->parts = [FakeLayoutSource::row('banner', implode("\n", [
            '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"acme/data","args":{"name":"tagline"}}}}} -->',
            '<p></p>',
            '<!-- /wp:paragraph -->',
            '<!-- wp:heading {"metadata":{"bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->',
            '<h2></h2>',
            '<!-- /wp:heading -->',
        ]))];

        $section = self::scan($source);
        $tagline = $section->item('bind:acme/data.tagline');
        $overrides = $section->item('bind:core/pattern-overrides');

        $this->assertNotNull($tagline);
        $this->assertNotNull($overrides);
        $this->assertSame('acme/data: tagline', $tagline->label, 'An unregistered source is labelled by its name.');
        $this->assertNull($tagline->facts['source_label']);
        $this->assertNull($overrides->facts['key']);
        $this->assertSame(['core/heading.__default'], $overrides->facts['bound']);
        $this->assertSame(['bind:acme/data.tagline', 'bind:core/pattern-overrides'], $section->item('part:banner')?->refs);
    }

    public function testAPostMetaBindingRefsTheMatchingFieldIds(): void
    {
        $source = new FakeLayoutSource();
        $source->meta = ['price_from' => ['service']];
        $source->templates = [FakeLayoutSource::row('single-service', implode("\n", [
            '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"price_from"}}}}} -->',
            '<p></p>',
            '<!-- /wp:paragraph -->',
        ]))];

        $binding = self::item($source, 'bind:core/post-meta.price_from');

        $this->assertSame(['field:service.price_from'], $binding->refs);
        $this->assertSame(['tpl:single-service'], $binding->stats['used_in']);
    }

    public function testUsedInIsCappedAtFiftyAndUsageCountKeepsTheTotal(): void
    {
        $source = FakeLayoutSource::fixture();
        $source->content = [];

        for ($i = 1; $i <= 60; $i++) {
            $source->content[] = ['type' => 'post', 'slug' => sprintf('post-%02d', $i), 'content' => '<!-- wp:block {"ref":90003} /-->'];
        }

        $pattern = self::item($source, 'pat:cta-banner');

        $this->assertSame(61, $pattern->stats['usage_count']);
        $this->assertCount(LayoutScanner::USED_IN_CAP, $pattern->stats['used_in']);
        $this->assertSame('tpl:single-service', $pattern->stats['used_in'][0], 'Layout containers come before content.');
        $this->assertSame('post:post-49', $pattern->stats['used_in'][49]);
    }

    public function testRefsToTargetsThatDoNotExistMoveToMissingRefs(): void
    {
        $source = new FakeLayoutSource();
        $source->templates = [FakeLayoutSource::row('index', implode("\n", [
            '<!-- wp:template-part {"slug":"sidebar"} /-->',
            '<!-- wp:block {"ref":999} /-->',
            '<!-- wp:navigation {"ref":888} /-->',
            '<!-- wp:navigation /-->',
            '<!-- wp:pattern {"slug":"gone/hero"} /-->',
        ]))];

        $template = self::item($source, 'tpl:index');

        $this->assertSame([], $template->refs);
        $this->assertSame(['nav:#888', 'part:sidebar', 'pat:#999', 'pat:gone/hero'], $template->facts['missing_refs']);
        $this->assertSame(['gone/hero'], $source->registeredLookups);
    }

    public function testAMenuCountsItsItemsAndListsWhereItIsUsed(): void
    {
        $section = self::scan(FakeLayoutSource::fixture());
        $primary = $section->item('nav:primary');

        $this->assertNotNull($primary);
        $this->assertSame('navigation', $primary->kind);
        $this->assertSame('Primary', $primary->label);
        $this->assertSame(5, $primary->stats['item_count']);
        $this->assertSame(['part:header'], $primary->stats['used_in']);
        $this->assertSame(2, $section->item('nav:footer')?->stats['item_count']);
        $this->assertSame([], $primary->facts);
    }

    public function testRegisteredPatternsAppearOnlyWhenUsedAndAreFollowedThroughOtherPatterns(): void
    {
        $source = FakeLayoutSource::fixture();
        $source->templates[] = FakeLayoutSource::row('home', '<!-- wp:pattern {"slug":"acme/hero"} /-->');
        $source->registered = [
            'acme/hero'    => ['title' => 'Hero', 'content' => "<!-- wp:pattern {\"slug\":\"acme/buttons\"} /-->\n<!-- wp:template-part {\"slug\":\"footer\"} /-->"],
            'acme/buttons' => ['title' => 'Buttons', 'content' => '<!-- wp:buttons /-->'],
            'acme/unused'  => ['title' => 'Unused', 'content' => ''],
        ];

        $section = self::scan($source);
        $hero = $section->item('pat:acme/hero');
        $buttons = $section->item('pat:acme/buttons');

        $this->assertNotNull($hero);
        $this->assertNotNull($buttons);
        $this->assertNull($section->item('pat:acme/unused'));
        $this->assertSame(['part:footer', 'pat:acme/buttons'], $hero->refs);
        $this->assertSame(['file' => null, 'reads' => null, 'registered' => true, 'synced' => false], $hero->facts, 'No theme file registers it, so neither is known.');
        $this->assertSame(['usage_count' => 1, 'used_in' => ['tpl:home']], $hero->stats);
        $this->assertNull($hero->edit, 'A registered pattern lives in code; there is nothing to open.');
        $this->assertSame(['pat:acme/hero'], $buttons->stats['used_in']);
        $this->assertSame(
            ['pat:acme/hero', 'tpl:page-contact', 'tpl:single-service'],
            $section->item('part:footer')?->stats['used_in'],
            'tpl:home reaches the footer through the pattern, so only the pattern lists it.'
        );
    }

    public function testTemplatesAndPartsNameTheirFileRelativeToWpContent(): void
    {
        $section = self::scan(FakeLayoutSource::fixture());

        $this->assertSame('themes/vwf-fixture/templates/single-service.html', $section->item('tpl:single-service')?->facts['file']);
        $this->assertSame('themes/vwf-fixture/parts/footer.html', $section->item('part:footer')?->facts['file']);
    }

    public function testTemplateSourceAndUserModifiedComeFromTheSource(): void
    {
        $source = new FakeLayoutSource();
        $source->templates = [
            FakeLayoutSource::row('single', '', 'Single Posts', '', 'theme', true),
            FakeLayoutSource::row('landing', '', '', '', 'custom'),
        ];

        $section = self::scan($source);

        $this->assertSame(['file' => null, 'file_theme' => null, 'overrides' => null, 'slug' => 'single', 'source' => 'theme', 'user_modified' => true, 'lock' => ['effective' => 'none', 'src' => 'none', 'locked' => 0], 'locked_blocks' => [], 'classes' => [], 'block_names' => [], 'hidden_classes' => false], $section->item('tpl:single')?->facts);
        $this->assertSame(['file' => null, 'file_theme' => null, 'overrides' => null, 'slug' => 'landing', 'source' => 'custom', 'user_modified' => false, 'lock' => ['effective' => 'none', 'src' => 'none', 'locked' => 0], 'locked_blocks' => [], 'classes' => [], 'block_names' => [], 'hidden_classes' => false], $section->item('tpl:landing')?->facts);
        $this->assertSame('landing', $section->item('tpl:landing')?->label, 'A template with no title is labelled by its slug.');
    }

    public function testTemplatesAndPartsSayWhichThemeFileServesThemAndWhatTheyOverride(): void
    {
        $source = new FakeLayoutSource();
        $source->templates = [
            ['file_theme' => 'twentytwentyfive', 'overrides' => null] + FakeLayoutSource::row('index', ''),
        ];
        $source->parts = [
            ['file_theme' => 'vwf-fixture', 'overrides' => 'twentytwentyfive'] + FakeLayoutSource::row('footer', '', 'Footer', 'footer'),
        ];

        $section = self::scan($source);

        $this->assertSame('twentytwentyfive', $section->item('tpl:index')?->facts['file_theme']);
        $this->assertNull($section->item('tpl:index')?->facts['overrides']);
        $this->assertNull($section->item('tpl:index')?->facts['file'], 'A row that names no file carries null.');
        $this->assertSame('vwf-fixture', $section->item('part:footer')?->facts['file_theme']);
        $this->assertSame('twentytwentyfive', $section->item('part:footer')?->facts['overrides']);
    }

    public function testTheSectionIsTheSameWhateverOrderRowsArriveIn(): void
    {
        $forward = FakeLayoutSource::fixture();
        $reversed = FakeLayoutSource::fixture();
        $reversed->templates = array_reverse($reversed->templates);
        $reversed->parts = array_reverse($reversed->parts);
        $reversed->navigations = array_reverse($reversed->navigations);
        $reversed->content = array_reverse($reversed->content);

        $this->assertSame(self::scan($forward)->hash, self::scan($reversed)->hash);
        $this->assertSame(self::scan($forward)->hash, self::scan($forward)->hash, 'A scanner can be run twice.');
    }

    private static function scan(FakeLayoutSource $source): Section
    {
        return (new LayoutScanner($source))->scan();
    }

    private static function item(FakeLayoutSource $source, string $id): Item
    {
        $item = self::scan($source)->item($id);

        if ($item === null) {
            self::fail(sprintf('No item "%s" in the layout section.', $id));
        }

        return $item;
    }
}
