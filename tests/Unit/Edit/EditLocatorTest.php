<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Edit;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * Find-edit-location on the fixture site, with no database.
 *
 * @phpstan-import-type Result from EditLocator
 */
final class EditLocatorTest extends TestCase
{
    public function testThePhoneNumberLeadsToTheBusinessInfoBindingAndItsSettingsPage(): void
    {
        $result = self::find('/', '555-0142');

        $this->assertTrue($result['matched']);
        $this->assertSame(
            ['bind:vwf-fixture/business-info.phone', 'part:footer', 'tpl:page', 'route:front-page'],
            self::ids($result)
        );

        $phone = $result['chain'][0];
        $this->assertTrue($phone['match']);
        $this->assertSame('options-general.php?page=vwf-business-info', $phone['edit']);
        $this->assertSame(
            'Phone in the footer. It comes from Business info: options-general.php?page=vwf-business-info',
            $phone['instruction']
        );
        $this->assertFalse($result['chain'][1]['match']);
    }

    public function testAServiceUrlListsItsTemplateAndTheCtaPattern(): void
    {
        $result = self::find('/services/drain-cleaning/');

        $this->assertSame('route:single-service', $result['route']);
        $this->assertSame('tpl:single-service', $result['template']);
        $this->assertNull($result['matched']);
        $this->assertSame('pt:service', $result['chain'][0]['id']);
        $this->assertSame('post.php?post=30&action=edit', $result['chain'][0]['edit']);
        $this->assertSame('tpl:single-service', $result['chain'][1]['id']);
        $this->assertContains('pat:cta-banner', self::ids($result));
        $this->assertSame('route:single-service', $result['chain'][count($result['chain']) - 1]['id']);
    }

    public function testCtaTextOnAboutFindsTheSyncedPatternAndListsItsThreeUsages(): void
    {
        $result = self::find('/about/', 'Need a plumber TODAY?');

        $this->assertSame(['pat:cta-banner', 'pt:page', 'tpl:page', 'route:single-page'], self::ids($result));

        $pattern = $result['chain'][0];
        $this->assertSame(3, $pattern['usage_count'] ?? null);
        $this->assertSame(['tpl:single-service', 'page:about', 'page:home'], $pattern['used_in'] ?? null);
        $this->assertStringContainsString('One edit here changes every usage (3): tpl:single-service, page:about, page:home.', $pattern['instruction']);
        $this->assertSame('post.php?post=90003&action=edit', $pattern['edit']);
    }

    public function testAnUnknownUrlHasNoRouteAndSaysWhy(): void
    {
        $result = self::find('/no-such-page/', 'anything');

        $this->assertNull($result['route']);
        $this->assertSame([], $result['chain']);
        $this->assertStringStartsWith('No route matches this URL.', (string) $result['message']);
    }

    public function testTextThatIsNowhereFallsBackToTheWholePage(): void
    {
        $result = self::find('/about/', 'free estimates');

        $this->assertFalse($result['matched']);
        $this->assertStringStartsWith('No block on this page contains that text.', (string) $result['message']);
        $this->assertSame(self::ids(self::find('/about/')), self::ids($result));
    }

    public function testABindingSourceWithNoEditPathAsksForAnAgencyNote(): void
    {
        $layout = FakeRouteSource::layout();
        $layout->editPaths = [];

        $result = (new EditLocator(FakeRouteSource::fixture(), $layout))->find('/', '555-0142');

        $this->assertNull($result['chain'][0]['edit']);
        $this->assertSame(
            'Phone in the footer, set by Business info, edit location unknown, add an agency note.',
            $result['chain'][0]['instruction']
        );
    }

    public function testPostMetaBindingsPointAtThePostEditScreen(): void
    {
        $routes = FakeRouteSource::fixture();
        $routes->meta[30] = ['price_from' => '149'];
        $layout = FakeRouteSource::layout();

        foreach ($layout->templates as $i => $row) {
            if ($row['slug'] === 'single-service') {
                $layout->templates[$i]['content'] .= "\n" . '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"price_from"}}}}} -->' . "\n<p></p>\n<!-- /wp:paragraph -->";
            }
        }

        $result = (new EditLocator($routes, $layout))->find('/services/drain-cleaning/', '149');

        $this->assertSame(['bind:core/post-meta.price_from', 'tpl:single-service', 'route:single-service'], self::ids($result));
        $this->assertSame('post.php?post=30&action=edit', $result['chain'][0]['edit']);
        $this->assertSame(
            'Price from in the "single-service" template. It comes from the "price_from" field of this service: post.php?post=30&action=edit',
            $result['chain'][0]['instruction']
        );
    }

    public function testMenuLinkTextLeadsToTheMenuThenThePartItSitsIn(): void
    {
        $result = self::find('/about/', 'Blog');

        $this->assertSame(['nav:primary', 'part:header', 'tpl:page', 'route:single-page'], self::ids($result));
        $this->assertSame('The Primary menu. Edit it here: site-editor.php?p=/wp_navigation/90001', $result['chain'][0]['instruction']);
    }

    public function testStaticTemplateTextLeadsToTheTemplate(): void
    {
        $result = self::find('/contact/', 'send us a   message');

        $this->assertSame(['tpl:page-contact', 'route:page-contact'], self::ids($result));
        $this->assertSame('site-editor.php?p=/wp_template/vwf-fixture//page-contact&canvas=edit', $result['chain'][0]['edit']);
    }

    public function testThePostTitleCountsAsContent(): void
    {
        $result = self::find('https://riverbend.test/services/drain-cleaning/', 'Drain cleaning');

        $this->assertSame(['pt:service', 'tpl:single-service', 'route:single-service'], self::ids($result));
        $this->assertSame('Service content: Drain cleaning', $result['chain'][0]['label']);
    }

    public function testTheThemeFilePlaceholderUnderABindingIsNotAHit(): void
    {
        $layout = FakeRouteSource::layout();
        $layout->parts[0]['content'] = str_replace(
            "\"key\":\"address\"}}}}} -->\n\t<p></p>",
            "\"key\":\"address\"}}}}} -->\n\t<p>Mill Race Rd placeholder</p>",
            $layout->parts[0]['content']
        );

        $this->assertStringContainsString('Mill Race Rd placeholder', $layout->parts[0]['content']);

        $result = (new EditLocator(FakeRouteSource::fixture(), $layout))->find('/', 'Mill Race');
        $matches = array_values(array_filter($result['chain'], static fn (array $entry): bool => $entry['match']));

        $this->assertSame(['bind:vwf-fixture/business-info.address'], array_column($matches, 'id'));
    }

    /**
     * @return Result
     */
    private static function find(string $url, ?string $text = null): array
    {
        return (new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout()))->find($url, $text);
    }

    /**
     * @param Result $result
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        return array_column($result['chain'], 'id');
    }
}
