<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Ai;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Render\Ai\SectionRenderer;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * The lock lines keep who can open a thing apart from what its layout
 * allows. Run 4's agents blamed the
 * footer's content-only lock for an Editor not being able to change it,
 * listed the groups that set templateLock as the blocks that cannot be
 * deleted, and read `templateLock: all` as "the text is locked".
 */
final class LockLinesTest extends TestCase
{
    private const FOOTER_EDIT = 'site-editor.php?p=/wp_template_part/vwf-fixture//footer&canvas=edit';

    public function testACapabilityOnlyItemSaysWhoCannotOpenItAndNothingAboutLocks(): void
    {
        $template = new Item(
            'tpl:single',
            'template',
            'Single',
            ['lock' => ['effective' => 'none', 'locked' => 0, 'src' => 'none'], 'locked_blocks' => [], 'source' => 'theme'],
            'site-editor.php?p=/wp_template/acme//single&canvas=edit',
            [],
            ['client' => ['can_open' => false, 'needs' => 'edit_theme_options', 'role' => 'editor']]
        );

        $line = Facts::describe($template);

        $this->assertStringContainsString('editor cannot open: needs edit_theme_options', $line);
        $this->assertStringNotContainsString('lock', $line, 'Nothing is locked, so no lock words.');
    }

    public function testALockOnlyItemSaysTheRoleCanOpenItThenNamesItsLockedBlocks(): void
    {
        $pattern = new Item(
            'pat:pricing',
            'pattern',
            'Pricing',
            [
                'lock'          => ['effective' => 'all', 'locked' => 1, 'src' => 'block'],
                'locked_blocks' => [self::locked('Terms', '1', 'all', 'block', true)],
                'registered'    => false,
                'synced'        => true,
            ],
            'post.php?post=7&action=edit',
            [],
            ['client' => ['can_open' => true, 'needs' => 'edit_others_posts', 'role' => 'editor']]
        );

        $this->assertSame(
            'synced; editor can open it; locked blocks Terms (all, block: text can change, cannot be moved or removed); unused',
            Facts::describe($pattern)
        );
    }

    public function testAnItemWithBothStatesWhoCanOpenItBeforeWhatItsLayoutAllows(): void
    {
        $part = self::footer(['effective' => 'none', 'in_template' => 'content_only', 'locked' => 0, 'src' => 'none'], []);

        $parts = explode('; ', Facts::describe($part));
        $who = array_search('editor cannot open: needs edit_theme_options', $parts, true);
        $what = array_search('not locked at its edit path', $parts, true);

        $this->assertIsInt($who);
        $this->assertIsInt($what);
        $this->assertLessThan($what, $who, 'Who can open it comes first.');
        $this->assertContains('content only inside a template (WordPress 7 default): there only its text can change', $parts);
    }

    public function testAContentOnlyContainerListsItsEditableChildrenAndNotItself(): void
    {
        $layout = FakeLayoutSource::fixture();
        $layout->templates[] = FakeLayoutSource::row(
            'offer',
            '<!-- wp:group {"templateLock":"contentOnly","metadata":{"name":"Offer card"}} --><div class="wp-block-group">'
            . self::paragraph('Spring offer', ['metadata' => ['name' => 'Offer title']])
            . self::paragraph('$49 off', ['metadata' => ['name' => 'Offer price']])
            . '<!-- wp:separator /-->'
            . '</div><!-- /wp:group -->'
        );

        $item = (new LayoutScanner($layout, new Locks('7.1.2'), self::editor()))->scan()->item('tpl:offer');
        $this->assertNotNull($item);

        $this->assertSame(
            [
                ['block' => 'core/paragraph', 'effective' => 'content_only', 'name' => 'Offer title', 'path' => '0.0', 'src' => 'template_lock', 'text_editable' => true],
                ['block' => 'core/paragraph', 'effective' => 'content_only', 'name' => 'Offer price', 'path' => '0.1', 'src' => 'template_lock', 'text_editable' => true],
                ['block' => 'core/separator', 'effective' => 'content_only', 'name' => null, 'path' => '0.2', 'src' => 'template_lock', 'text_editable' => false],
            ],
            $item->facts['locked_blocks'],
            'The group that sets contentOnly is not under it, so it is not listed.'
        );
        $this->assertStringContainsString(
            'locked blocks Offer title, Offer price (content only, template_lock: text can change, cannot be moved, removed or restyled) and separator at 0.2 (content only, template_lock: no text to change, cannot be moved, removed or restyled)',
            Facts::describe($item)
        );
        $this->assertStringContainsString('"locked_blocks":[{"block":"core/paragraph"', (string) (new SectionRenderer())->render(new Manifest([new Section('layout', [$item])]), 'tpl:offer'));
    }

    public function testTheFixtureFooterSaysTheEditorCannotOpenItAndNeverGivesItsLockAsTheReason(): void
    {
        $section = (new LayoutScanner(FakeLayoutSource::fixture(), new Locks('7.1.2'), self::editor()))->scan();
        $footer = $section->item('part:footer');
        $this->assertNotNull($footer);

        $this->assertSame(['effective' => 'none', 'in_template' => 'content_only', 'locked' => 0, 'src' => 'none'], self::sorted($footer->facts['lock']));
        $this->assertSame(['can_open' => false, 'needs' => 'edit_theme_options', 'role' => 'editor'], $footer->stats['client']);

        $manifest = new Manifest([$section]);
        $brief = (new BriefRenderer())->render($manifest);
        $index = (new IndexRenderer())->render($manifest);

        $this->assertMatchesRegularExpression('/^edit: part:footer \| Footer \| area footer; editor cannot open: needs edit_theme_options \| /m', $brief);
        $this->assertStringContainsString("\nlocks: part:header, part:footer: not locked at the edit path, content only inside a template (WordPress 7 default)\n", $brief);
        $this->assertStringNotContainsString('edit_theme_options', self::line($brief, 'locks: '), 'The locks line says what the layout allows, never who can open it.');
        $this->assertStringContainsString('part:footer | Footer | area footer; editor cannot open: needs edit_theme_options; not locked at its edit path; content only inside a template', $index);

        $chain = array_column(self::locator()->find('/contact/')['chain'], null, 'id');
        $this->assertSame('no', $chain['part:footer']['client_can']);
        $this->assertSame('the editor role cannot open it: it needs edit_theme_options', $chain['part:footer']['client_can_why']);
        $this->assertStringNotContainsString('content', $chain['part:footer']['client_can_why']);
    }

    public function testFindListsThePostsLockedBlocksAndSaysTheirTextCanChange(): void
    {
        $routes = FakeRouteSource::fixture();
        $routes->add(
            90050,
            'page',
            'plans',
            'Plans',
            '/plans/',
            '',
            '<!-- wp:group {"templateLock":"all","metadata":{"name":"Terms box"}} --><div class="wp-block-group">'
            . self::paragraph('Renews yearly.', ['metadata' => ['name' => 'Renewal']])
            . '</div><!-- /wp:group -->'
            . self::paragraph('Licensed in Virginia.', ['lock' => ['move' => true, 'remove' => true], 'metadata' => ['name' => 'Licence']])
            . self::paragraph('Call any time.')
        );
        $locator = new EditLocator($routes, FakeRouteSource::layout(), new Locks('7.1.2'), self::editor());

        $content = $locator->find('/plans/')['chain'][0];
        $this->assertSame('pt:page', $content['id']);
        $this->assertSame(['Renewal', 'Licence'], array_column($content['locked_blocks'], 'name'), 'The group itself can be deleted; its child and the locked paragraph cannot.');
        $this->assertSame(['all', 'all'], array_column($content['locked_blocks'], 'effective'));

        $hit = $locator->find('/plans/', 'Renews yearly.')['chain'][0];
        $this->assertSame('yes', $hit['client_can']);
        $this->assertSame('the editor role can open it; all (template_lock): text can change, cannot be moved or removed', $hit['client_can_why']);
    }

    public function testAPartialVerdictLeadsWithWhoCanOpenItAndWhatCanChange(): void
    {
        $verdict = self::editor()->verdict('post.php?post=12&action=edit', ['effective' => 'content_only', 'src' => 'template_lock', 'text_editable' => true]);

        $this->assertSame('partial', $verdict['client_can']);
        $this->assertSame('the editor role can open it; content only (template_lock): text can change, cannot be moved, removed or restyled', $verdict['client_can_why']);
    }

    /**
     * @param array<string, mixed>       $lock
     * @param list<array<string, mixed>> $locked
     */
    private static function footer(array $lock, array $locked): Item
    {
        return new Item(
            'part:footer',
            'template_part',
            'Footer',
            ['area' => 'footer', 'lock' => $lock, 'locked_blocks' => $locked, 'source' => 'theme'],
            self::FOOTER_EDIT,
            [],
            ['client' => ['can_open' => false, 'needs' => 'edit_theme_options', 'role' => 'editor']]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function locked(?string $name, string $path, string $effective, string $src, bool $text): array
    {
        return ['block' => 'core/paragraph', 'effective' => $effective, 'name' => $name, 'path' => $path, 'src' => $src, 'text_editable' => $text];
    }

    private static function locator(): EditLocator
    {
        return new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout(), new Locks('7.1.2'), self::editor());
    }

    /**
     * An editor: everything but theme options and site settings.
     */
    private static function editor(): ClientAccess
    {
        return new ClientAccess(
            'editor',
            static fn (string $cap): bool => !in_array($cap, ['edit_theme_options', 'manage_options'], true),
            static fn (string $edit): string => match ((string) strtok($edit, '?')) {
                'site-editor.php'     => 'edit_theme_options',
                'options-general.php' => 'manage_options',
                default               => 'edit_others_pages',
            }
        );
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function paragraph(string $text, array $attrs = []): string
    {
        return '<!-- wp:paragraph ' . ($attrs === [] ? '' : json_encode($attrs) . ' ') . '--><p>' . $text . '</p><!-- /wp:paragraph -->';
    }

    private static function line(string $text, string $prefix): string
    {
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, $prefix)) {
                return $line;
            }
        }

        return '';
    }

    private static function sorted(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);
        }

        return $value;
    }
}
