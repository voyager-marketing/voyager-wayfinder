<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Inspector;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Inspector\Annotator;
use Voyager\Wayfinder\Scanners\RouteScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;
use Voyager\Wayfinder\Tests\Support\FakeTagWriter;

/**
 * The inspector's block marks on the fixture front page, with no
 * WordPress: blocks are rendered inside out here the way WordPress
 * renders them, calling enter() as each starts and leave() as it ends.
 *
 * @phpstan-import-type Context from Annotator
 */
final class AnnotatorTest extends TestCase
{
    private const ADMIN = 'https://example.test/wp-admin/';

    private const PHONE = 'bind:vwf-fixture/business-info.phone';

    public function testTheFooterPhoneIsNamedForBusinessInfoAndLinksToItsSettingsPage(): void
    {
        $html = self::render(self::annotator(), self::part('footer'));

        $this->assertStringContainsString(
            '<p data-vwf-src="part:footer&gt;' . self::PHONE . '" data-vwf-edit="' . self::ADMIN . 'options-general.php?page=vwf-business-info">(540) 555-0142</p>',
            $html
        );

        $target = self::targets()[self::PHONE];
        $this->assertSame('Business info: phone', $target['label']);
        $this->assertSame(self::ADMIN . 'options-general.php?page=vwf-business-info', $target['edit']);
    }

    public function testTargetsCarryFindsLabelEditPathAndClientVerdictForEveryChainEntry(): void
    {
        $result = self::find();
        $targets = self::targets();

        $this->assertSame(array_column($result['chain'], 'id'), array_values(array_intersect(array_column($result['chain'], 'id'), array_keys($targets))));
        $this->assertCount(count($result['chain']), $targets);

        foreach ($result['chain'] as $entry) {
            $target = $targets[$entry['id']];
            $this->assertSame($entry['label'], $target['label']);
            $this->assertSame($entry['edit'] !== null ? self::ADMIN . $entry['edit'] : null, $target['edit']);
            $this->assertSame($entry['client_can'], $target['client_can']);
            $this->assertSame($entry['client_can_why'], $target['client_can_why']);
        }
    }

    public function testTheTemplatePartIsMarkedWithItsOwnIdAndSiteEditorLink(): void
    {
        $annotator = self::annotator();
        $html = self::render($annotator, self::part('footer'));

        $this->assertStringStartsWith(
            '<footer data-vwf-src="part:footer" data-vwf-edit="' . htmlspecialchars((string) self::targets()['part:footer']['edit'], ENT_QUOTES) . '" class="wp-block-template-part">',
            $html
        );
        $this->assertSame(0, $annotator->depth());
    }

    public function testAMenuInTheFooterChainsUnderThePart(): void
    {
        $html = self::render(self::annotator(), self::part('footer'));

        $this->assertStringContainsString('<nav data-vwf-src="part:footer&gt;nav:footer"', $html);
    }

    public function testASyncedPatternInThePostContentChainsUnderTheContent(): void
    {
        $content = self::block('core/post-content', [], [
            self::block('core/paragraph', [], [], '<p>Licensed plumbers.</p>'),
            self::block('core/block', ['ref' => 90003], [self::block('core/heading', [], [], '<h2>Need a plumber today?</h2>')]),
        ]);

        $html = self::render(self::annotator(10), $content, ['postId' => 10, 'postType' => 'page']);

        $this->assertStringStartsWith('<div data-vwf-src="pt:page" data-vwf-edit="' . self::ADMIN . 'post.php?post=10&amp;action=edit"', $html);
        $this->assertStringContainsString('<h2 data-vwf-src="pt:page&gt;pat:cta-banner" data-vwf-edit="' . self::ADMIN . 'post.php?post=90003&amp;action=edit">', $html);
        $this->assertStringContainsString('<p>Licensed plumbers.</p>', $html);
    }

    public function testAnInnerMarkOnTheSameFirstTagIsKept(): void
    {
        // A synced pattern renders no wrapper, so its first tag can be a bound block's.
        $pattern = self::block('core/block', ['ref' => 90003], [self::phone()]);

        $html = self::render(self::annotator(), $pattern);

        $this->assertSame(1, substr_count($html, 'data-vwf-src='));
        $this->assertStringContainsString('data-vwf-src="pat:cta-banner&gt;' . self::PHONE . '"', $html);
    }

    public function testAnotherPostsContentIsMarkedWithoutThisPostsEditLink(): void
    {
        $annotator = self::annotator(10);
        $content = self::block('core/post-content', [], [self::block('core/paragraph', [], [], '<p>Shut-off valves.</p>')]);

        $html = self::render($annotator, $content, ['postId' => 20, 'postType' => 'post']);

        $this->assertStringStartsWith('<div data-vwf-src="pt:post" class=', $html);
        $this->assertStringNotContainsString('data-vwf-edit', $html);
    }

    public function testBlocksThatPlaceNothingComeBackByteForByte(): void
    {
        $annotator = self::annotator();
        $plain = '<p class="has-text-align-center">Licensed plumbers.</p>';

        $this->assertNull($annotator->idFor(self::block('core/paragraph', [], [], $plain)));
        $this->assertSame($plain, $annotator->leave($plain, self::block('core/paragraph', [], [], $plain)));
        $this->assertSame('', $annotator->leave('', self::phone()));
        $this->assertSame('just text', $annotator->annotate('just text', ['part:footer']));
    }

    public function testAMenuWithNoRefOpensNoLevelAndLeavesTheStackBalanced(): void
    {
        $annotator = self::annotator();
        $header = self::block('core/template-part', ['slug' => 'header'], [
            self::block('core/navigation', [], [], ''),
            self::phone(),
        ]);

        $html = self::render($annotator, $header);

        $this->assertStringStartsWith('<nav class="wp-block-navigation">', substr($html, (int) strpos($html, '<nav')));
        $this->assertStringContainsString('data-vwf-src="part:header&gt;' . self::PHONE . '"', $html);
        $this->assertSame(0, $annotator->depth());
    }

    public function testABlockBindingSeveralAttributesIsNamedByItsTextBinding(): void
    {
        $button = self::block('core/button', ['metadata' => ['bindings' => [
            'url'  => ['source' => 'core/post-meta', 'args' => ['key' => 'booking_url']],
            'text' => ['source' => 'vwf-fixture/business-info', 'args' => ['key' => 'phone']],
        ]]], [], '<div class="wp-block-button"><a class="wp-block-button__link">Call</a></div>');

        $this->assertSame(self::PHONE, self::annotator()->idFor($button));
        $this->assertSame(
            'bind:core/post-meta.booking_url',
            self::annotator()->idFor(self::block('core/button', ['metadata' => ['bindings' => [
                'url' => ['source' => 'core/post-meta', 'args' => ['key' => 'booking_url']],
            ]]]))
        );
    }

    public function testEditUrlsAreEscapedByTheWriterNotTheAnnotator(): void
    {
        $annotator = new Annotator(
            ['part:footer' => ['label' => 'Footer', 'edit' => 'https://example.test/wp-admin/site-editor.php?p=/wp_template_part/a//footer&canvas=edit', 'client_can' => 'no', 'client_can_why' => '']],
            RouteScanner::builder(FakeRouteSource::layout()),
            new FakeTagWriter()
        );

        $this->assertSame(
            '<footer data-vwf-src="part:footer" data-vwf-edit="https://example.test/wp-admin/site-editor.php?p=/wp_template_part/a//footer&amp;canvas=edit">x</footer>',
            $annotator->annotate('<footer>x</footer>', ['part:footer'])
        );
    }

    private static function annotator(?int $postId = null): Annotator
    {
        return new Annotator(self::targets(), RouteScanner::builder(FakeRouteSource::layout()), new FakeTagWriter(), $postId);
    }

    /**
     * @return array<string, array{label: string, edit: string|null, client_can: 'yes'|'partial'|'no', client_can_why: string}>
     */
    private static function targets(): array
    {
        return Annotator::targets(self::find(), static fn (string $edit): string => self::ADMIN . $edit);
    }

    /**
     * @return array<string, mixed>
     */
    private static function find(): array
    {
        return (new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout(), null, null, static fn (): ?array => null, static fn (): ?string => null))->find('/');
    }

    /**
     * A template part block with the fixture theme's part file as its inner blocks.
     *
     * @return array<string, mixed>
     */
    private static function part(string $slug): array
    {
        return self::block('core/template-part', ['slug' => $slug], parse_blocks(FakeLayoutSource::themeFile('parts/' . $slug . '.html')));
    }

    /**
     * @return array<string, mixed>
     */
    private static function phone(): array
    {
        return self::block('core/paragraph', ['metadata' => ['bindings' => ['content' => [
            'source' => 'vwf-fixture/business-info',
            'args'   => ['key' => 'phone'],
        ]]]], [], '<p></p>');
    }

    /**
     * @param array<string, mixed>       $attrs
     * @param list<array<string, mixed>> $inner
     * @return array<string, mixed>
     */
    private static function block(string $name, array $attrs = [], array $inner = [], string $html = ''): array
    {
        return ['blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $inner, 'innerHTML' => $html, 'innerContent' => [$html]];
    }

    /**
     * Renders a block the way WordPress orders it: enter, the inner blocks,
     * then leave with the finished markup. Each block's markup is a stand-in
     * for what core prints.
     *
     * @param array<array-key, mixed> $block
     * @param Context                 $context
     */
    private static function render(Annotator $annotator, array $block, array $context = []): string
    {
        $annotator->enter($block, $context);

        $inner = '';

        foreach (is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [] as $child) {
            if (is_array($child) && is_string($child['blockName'] ?? null)) {
                $inner .= self::render($annotator, $child, $context);
            }
        }

        $name = $block['blockName'] ?? '';
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $html = match (true) {
            $name === 'core/template-part' => '<footer class="wp-block-template-part">' . $inner . '</footer>',
            $name === 'core/navigation'    => '<nav class="wp-block-navigation"><ul><li>About</li></ul></nav>',
            $name === 'core/post-content'  => '<div class="entry-content wp-block-post-content">' . $inner . '</div>',
            $name === 'core/block'         => $inner,
            $name === 'core/group'         => '<div class="wp-block-group">' . $inner . '</div>',
            isset($attrs['metadata'])      => '<p>(540) 555-0142</p>',
            default                        => (string) ($block['innerHTML'] ?? ''),
        };

        return $annotator->leave($html, $block, $context);
    }
}
