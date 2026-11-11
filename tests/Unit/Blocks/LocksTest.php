<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Blocks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\BlockNode;
use Voyager\Wayfinder\Blocks\BlockTreeBuilder;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * Lock resolution: the 5.9 lock table, templateLock inheritance, content
 * only, the post type's template_lock and the 7.0 defaults, then the lock
 * and client_can facts that LayoutScanner and EditLocator carry.
 *
 * @phpstan-import-type State from Locks
 */
final class LocksTest extends TestCase
{
    /**
     * Every cell of the 5.9 table: an inherited templateLock (none, insert,
     * all) against a block's own lock.move and lock.remove (true, false,
     * undefined).
     *
     * @return iterable<string, array{0: 'none'|'insert'|'all', 1: bool|null, 2: bool|null, 3: string, 4: string}>
     */
    public static function lockTable(): iterable
    {
        $expected = [
            // inherited, move, remove => effective, src
            'none'   => [
                'u/u' => ['none', 'none'],     'u/t' => ['remove', 'block'], 'u/f' => ['none', 'none'],
                't/u' => ['move', 'block'],    't/t' => ['all', 'block'],    't/f' => ['move', 'block'],
                'f/u' => ['none', 'none'],     'f/t' => ['remove', 'block'], 'f/f' => ['none', 'none'],
            ],
            'insert' => [
                'u/u' => ['remove', 'template_lock'], 'u/t' => ['remove', 'block'], 'u/f' => ['insert', 'template_lock'],
                't/u' => ['all', 'block'],            't/t' => ['all', 'block'],    't/f' => ['move', 'block'],
                'f/u' => ['remove', 'block'],         'f/t' => ['remove', 'block'], 'f/f' => ['insert', 'template_lock'],
            ],
            'all'    => [
                'u/u' => ['all', 'template_lock'], 'u/t' => ['all', 'block'],  'u/f' => ['move', 'block'],
                't/u' => ['all', 'block'],         't/t' => ['all', 'block'],  't/f' => ['move', 'block'],
                'f/u' => ['remove', 'block'],      'f/t' => ['remove', 'block'], 'f/f' => ['insert', 'template_lock'],
            ],
        ];
        $values = ['u' => null, 't' => true, 'f' => false];

        foreach ($expected as $mode => $cells) {
            foreach ($cells as $cell => [$effective, $src]) {
                [$move, $remove] = explode('/', $cell);
                yield $mode . ' move:' . $move . ' remove:' . $remove => [$mode, $values[$move], $values[$remove], $effective, $src];
            }
        }
    }

    /**
     * @param 'none'|'insert'|'all' $mode
     */
    #[DataProvider('lockTable')]
    public function testTheLockTable(string $mode, ?bool $move, ?bool $remove, string $effective, string $src): void
    {
        $lock = [];
        if ($move !== null) {
            $lock['move'] = $move;
        }
        if ($remove !== null) {
            $lock['remove'] = $remove;
        }

        $attrs = $lock === [] ? [] : ['lock' => $lock];
        $markup = '<!-- wp:group ' . ($mode === 'none' ? '' : '{"templateLock":"' . $mode . '"} ') . '-->'
            . '<div class="wp-block-group">' . self::paragraph('Hello', $attrs) . '</div><!-- /wp:group -->';

        $paragraph = (new Locks())->tree(self::tree($markup), Locks::unlocked())['0.0'];

        $this->assertSame($effective, $paragraph['effective']);
        $this->assertSame($src, $paragraph['src']);
        $this->assertTrue($paragraph['text_editable']);
    }

    public function testContentOnlyAboveIsFinalAndChildrenCannotLoosenIt(): void
    {
        $markup = '<!-- wp:group {"templateLock":"contentOnly"} --><div class="wp-block-group">'
            . '<!-- wp:group {"templateLock":false} --><div class="wp-block-group">'
            . self::paragraph('Inner', ['lock' => ['move' => false, 'remove' => false]])
            . '</div><!-- /wp:group -->'
            . '<!-- wp:group {"templateLock":"all"} --><div class="wp-block-group">' . self::paragraph('Other') . '</div><!-- /wp:group -->'
            . '</div><!-- /wp:group -->';

        $locks = (new Locks())->tree(self::tree($markup), Locks::unlocked());

        $this->assertSame('none', $locks['0']['effective'], 'The group that sets contentOnly is not itself under it.');
        foreach (['0.0', '0.0.0', '0.1', '0.1.0'] as $path) {
            $this->assertSame('content_only', $locks[$path]['effective'], $path);
            $this->assertSame('template_lock', $locks[$path]['src'], $path);
        }
    }

    public function testTemplateLockInheritsThroughNestedInnerBlocks(): void
    {
        $markup = '<!-- wp:group {"templateLock":"all"} --><div class="wp-block-group">'
            . '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
            . self::paragraph('Deep')
            . '</div><!-- /wp:column --></div><!-- /wp:columns -->'
            . '</div><!-- /wp:group -->';

        $locks = (new Locks())->tree(self::tree($markup), Locks::unlocked());

        $this->assertSame('none', $locks['0']['effective']);
        $this->assertSame(['effective' => 'all', 'src' => 'template_lock', 'text_editable' => true], $locks['0.0.0.0']);
        $this->assertSame('all', $locks['0.0']['effective']);
    }

    public function testTemplateLockFalseClearsAnInheritedLock(): void
    {
        $markup = '<!-- wp:group {"templateLock":"insert"} --><div class="wp-block-group">'
            . '<!-- wp:group {"templateLock":false} --><div class="wp-block-group">' . self::paragraph('Free') . '</div><!-- /wp:group -->'
            . '</div><!-- /wp:group -->';

        $locks = (new Locks())->tree(self::tree($markup), Locks::unlocked());

        $this->assertSame('remove', $locks['0.0']['effective']);
        $this->assertSame(['effective' => 'none', 'src' => 'none', 'text_editable' => true], $locks['0.0.0']);
    }

    public function testThePostTypeTemplateLockAppliesToPostContent(): void
    {
        $locks = new Locks('7.1.2', false, null, static fn (string $type): mixed => $type === 'service' ? 'all' : ($type === 'page' ? 'contentOnly' : false));

        $this->assertSame(['mode' => 'all', 'src' => 'post_type'], $locks->root(Locks::CONTENT, 'service'));
        $this->assertSame(['mode' => 'content_only', 'src' => 'post_type'], $locks->root(Locks::CONTENT, 'page'));
        $this->assertSame(Locks::unlocked(), $locks->root(Locks::CONTENT, 'post'));

        $paragraph = $locks->tree(self::tree(self::paragraph('Price')), $locks->root(Locks::CONTENT, 'service'))['0'];
        $this->assertSame(['effective' => 'all', 'src' => 'post_type', 'text_editable' => true], $paragraph);

        // A nearer templateLock wins over the post type's.
        $markup = '<!-- wp:group {"templateLock":"contentOnly"} --><div class="wp-block-group">' . self::paragraph('Price') . '</div><!-- /wp:group -->';
        $inner = $locks->tree(self::tree($markup), $locks->root(Locks::CONTENT, 'service'))['0.0'];
        $this->assertSame(['effective' => 'content_only', 'src' => 'template_lock', 'text_editable' => true], $inner);
    }

    /**
     * Core makes a part a content-only section only where a template places
     * it. Opened at its own edit path the editor is isolated and the part's
     * blocks are not locked by the default (editor.js `isIsolatedEditorKey`,
     * block-editor.js `isSectionBlockCandidate`, WordPress 7.1.2).
     */
    public function testTemplatePartsAreContentOnlyOnlyInsideATemplateFromWordPress7(): void
    {
        $locks = new Locks('7.1.2');
        $paragraph = $locks->tree(self::tree(self::paragraph('555-0142')), $locks->root(Locks::TEMPLATE_PART))['0'];

        $this->assertTrue($locks->contentOnlyDefaults());
        $this->assertSame(['effective' => 'none', 'src' => 'none', 'text_editable' => true], $paragraph, 'At its own edit path the default does not apply.');
        $this->assertSame(Locks::isolated(), $locks->root(Locks::TEMPLATE_PART));
        $this->assertSame(['mode' => 'content_only', 'src' => 'wp7_default'], $locks->inTemplate(Locks::TEMPLATE_PART));
        $this->assertSame(['mode' => 'content_only', 'src' => 'wp7_default'], (new Locks('7.0'))->inTemplate(Locks::TEMPLATE_PART));
        $this->assertNull($locks->inTemplate(Locks::SYNCED_PATTERN));
    }

    public function testAPartsOwnLocksStillApplyAtItsEditPath(): void
    {
        $locks = new Locks('7.1.2');
        $markup = '<!-- wp:group {"templateLock":"all"} --><div class="wp-block-group">' . self::paragraph('Hours') . '</div><!-- /wp:group -->';

        $tree = $locks->tree(self::tree($markup), $locks->root(Locks::TEMPLATE_PART));

        $this->assertSame(['effective' => 'all', 'src' => 'template_lock', 'text_editable' => true], $tree['0.0'], 'Before, the wp7 default hid this lock.');
    }

    public function testAnIsolatedEditorMakesNoPatternInstanceContentOnly(): void
    {
        $locks = new Locks('7.1.2');
        $instance = '<!-- wp:group {"metadata":{"patternName":"vwf-fixture/hero"}} --><div class="wp-block-group">'
            . '<!-- wp:group {"templateLock":false} --><div class="wp-block-group">' . self::paragraph('Hero') . '</div><!-- /wp:group -->'
            . '</div><!-- /wp:group -->';

        foreach ([Locks::TEMPLATE_PART, Locks::SYNCED_PATTERN] as $kind) {
            $tree = $locks->tree(self::tree($instance), $locks->root($kind));

            $this->assertSame('none', $tree['0']['effective'], $kind);
            $this->assertSame('none', $tree['0.0.0']['effective'], $kind . ': templateLock false keeps the isolation.');
        }

        $this->assertSame('content_only', $locks->tree(self::tree($instance), Locks::unlocked())['0']['effective'], 'In a template or post content it is still content only.');
    }

    public function testBeforeWordPress7PartsAndUnsyncedPatternsAreNotLocked(): void
    {
        foreach (['6.9.1', '6.8', ''] as $version) {
            $locks = new Locks($version);

            $this->assertFalse($locks->contentOnlyDefaults(), $version);
            $this->assertSame(Locks::isolated(), $locks->root(Locks::TEMPLATE_PART), $version);
            $this->assertNull($locks->inTemplate(Locks::TEMPLATE_PART), $version);
            $this->assertSame(Locks::unlocked(), $locks->root(Locks::UNSYNCED_PATTERN), $version);

            $instance = self::paragraph('From a pattern', ['metadata' => ['patternName' => 'vwf-fixture/hero']]);
            $this->assertSame('none', $locks->tree(self::tree($instance), Locks::unlocked())['0']['effective'], $version);
        }
    }

    public function testAnUnsyncedPatternInstanceIsContentOnlyFromWordPress7(): void
    {
        $markup = '<!-- wp:group {"metadata":{"patternName":"vwf-fixture/hero"}} --><div class="wp-block-group">'
            . self::paragraph('Hero text', ['lock' => ['move' => false, 'remove' => false]])
            . '</div><!-- /wp:group -->' . self::paragraph('Outside');

        $locks = (new Locks('7.1.2'))->tree(self::tree($markup), Locks::unlocked());

        $this->assertSame('content_only', $locks['0']['effective']);
        $this->assertSame('wp7_default', $locks['0']['src']);
        $this->assertSame(['effective' => 'content_only', 'src' => 'wp7_default', 'text_editable' => true], $locks['0.0']);
        $this->assertSame('none', $locks['1']['effective'], 'A sibling outside the pattern is not touched.');
        $this->assertSame(['mode' => 'content_only', 'src' => 'wp7_default'], (new Locks('7.1.2'))->root(Locks::UNSYNCED_PATTERN));
    }

    public function testTheUnsyncedPatternOptOutLeavesTemplatePartsContentOnly(): void
    {
        $locks = new Locks('7.1.2', true);
        $instance = self::paragraph('Hero', ['metadata' => ['patternName' => 'vwf-fixture/hero']]);

        $this->assertSame(Locks::unlocked(), $locks->root(Locks::UNSYNCED_PATTERN));
        $this->assertSame('none', $locks->tree(self::tree($instance), Locks::unlocked())['0']['effective']);
        $this->assertSame(['mode' => 'content_only', 'src' => 'wp7_default'], $locks->inTemplate(Locks::TEMPLATE_PART));
    }

    public function testTheTemplatePartOptOutLeavesPartsUnlockedInsideATemplate(): void
    {
        $locks = new Locks('7.1.2', false, null, null, true);

        $this->assertNull($locks->inTemplate(Locks::TEMPLATE_PART));
        $this->assertSame(['mode' => 'content_only', 'src' => 'wp7_default'], $locks->root(Locks::UNSYNCED_PATTERN), 'The unsynced pattern default is separate.');
    }

    public function testTextIsEditableOnlyThroughContentRoleAttributes(): void
    {
        $roles = static fn (string $name): ?array => match ($name) {
            'core/paragraph' => ['content'],
            'core/group'     => [],
            default          => null,
        };
        $locks = new Locks('7.1.2', false, $roles);

        $markup = '<!-- wp:group {"templateLock":"contentOnly"} --><div class="wp-block-group">'
            . '<!-- wp:group --><div class="wp-block-group">' . self::paragraph('Text') . '</div><!-- /wp:group -->'
            . '<!-- wp:acme/badge {"label":"Since 1998"} /-->'
            . '<!-- wp:acme/spacer /-->'
            . self::paragraph('Phone', ['metadata' => ['bindings' => ['content' => ['source' => 'vwf-fixture/business-info', 'args' => ['key' => 'phone']]]]])
            . '</div><!-- /wp:group -->';

        $tree = $locks->tree(self::tree($markup), Locks::unlocked());

        $this->assertFalse($tree['0.0']['text_editable'], 'The registry gives the group no content attribute.');
        $this->assertTrue($tree['0.0.0']['text_editable']);
        $this->assertTrue($tree['0.1']['text_editable'], 'No role known: the label attribute falls back to the text attribute pattern.');
        $this->assertFalse($tree['0.2']['text_editable']);
        $this->assertFalse($tree['0.3']['text_editable'], 'A bound content attribute is not the writer\'s to change.');
    }

    public function testRolesComeFromTheRegisteredAttributeSchema(): void
    {
        $this->assertSame(['content'], Locks::rolesOf(['content' => ['type' => 'string', 'role' => 'content'], 'dropCap' => ['type' => 'boolean']]));
        $this->assertSame(['text'], Locks::rolesOf(['text' => ['__experimentalRole' => 'content'], 'url' => ['role' => 'local']]));
        $this->assertSame([], Locks::rolesOf(['url' => ['role' => 'local']]));
        $this->assertNull(Locks::rolesOf(['align' => ['type' => 'string']]));
    }

    public function testStrictestPicksTheTightestLockAndCountsLockedBlocks(): void
    {
        $summary = Locks::strictest([
            ['effective' => 'none', 'src' => 'none', 'text_editable' => true],
            ['effective' => 'move', 'src' => 'block', 'text_editable' => true],
            ['effective' => 'all', 'src' => 'template_lock', 'text_editable' => true],
            ['effective' => 'remove', 'src' => 'block', 'text_editable' => false],
        ]);

        $this->assertSame(['effective' => 'all', 'src' => 'template_lock', 'locked' => 3], $summary);
        $this->assertSame(['effective' => 'none', 'src' => 'none', 'locked' => 0], Locks::strictest([]));
        $this->assertSame(Locks::EFFECTIVE, ['none', 'move', 'remove', 'insert', 'all', 'content_only']);
    }

    public function testLayoutItemsCarryTheirStrictestLockAsAFact(): void
    {
        $layout = FakeLayoutSource::fixture();
        $layout->templates[] = FakeLayoutSource::row(
            'locked',
            '<!-- wp:group {"templateLock":"all"} --><div class="wp-block-group">' . self::paragraph('One') . self::paragraph('Two') . '</div><!-- /wp:group -->'
        );

        $modern = (new LayoutScanner($layout, new Locks('7.1.2')))->scan();
        $older = (new LayoutScanner($layout, new Locks('6.9')))->scan();

        $this->assertSame(['effective' => 'all', 'locked' => 2, 'src' => 'template_lock'], self::sorted($modern->item('tpl:locked')?->facts['lock']));
        $this->assertSame(['effective' => 'none', 'in_template' => 'content_only', 'locked' => 0, 'src' => 'none'], self::sorted($modern->item('part:footer')?->facts['lock']));
        $this->assertSame(['effective' => 'none', 'locked' => 0, 'src' => 'none'], self::sorted($older->item('part:footer')?->facts['lock']));
        $this->assertSame('none', $modern->item('pat:cta-banner')?->facts['lock']['effective'] ?? null, 'A synced pattern is not content only by default.');
        $this->assertArrayNotHasKey('in_template', $modern->item('pat:cta-banner')?->facts['lock'] ?? []);
        $this->assertArrayNotHasKey('in_template', $modern->item('tpl:locked')?->facts['lock'] ?? []);
        $this->assertArrayNotHasKey('lock', $modern->item('nav:primary')?->facts ?? []);
        $this->assertNotSame($modern->item('part:footer')?->hash, $older->item('part:footer')?->hash, 'The lock is hashed like other facts.');
    }

    public function testFindPutsALockAndClientCanOnEveryChainEntry(): void
    {
        $result = self::locator()->find('/', '555-0142');

        $this->assertNotSame([], $result['chain']);
        foreach ($result['chain'] as $entry) {
            $this->assertArrayHasKey('lock', $entry);
            $this->assertContains($entry['client_can'], ['yes', 'partial', 'no']);
            $this->assertNotSame('', $entry['client_can_why']);
        }

        $byId = array_column($result['chain'], null, 'id');
        $this->assertSame('no', $byId['part:footer']['client_can']);
        $this->assertSame('the editor role cannot open it: it needs edit_theme_options', $byId['part:footer']['client_can_why']);
        $this->assertSame(['effective' => 'none', 'src' => 'none', 'text_editable' => true], $byId['part:footer']['lock'], 'The footer as its edit path opens it.');
        $this->assertSame('no', $byId['bind:vwf-fixture/business-info.phone']['client_can']);
        $this->assertSame('the editor role cannot open it: it needs manage_options', $byId['bind:vwf-fixture/business-info.phone']['client_can_why']);
        $this->assertFalse($byId['bind:vwf-fixture/business-info.phone']['lock']['text_editable'], 'The phone comes from the binding, not the block text.');
    }

    public function testFindReportsTheMatchedBlocksOwnLockInPostContent(): void
    {
        $routes = FakeRouteSource::fixture();
        $routes->add(
            90050,
            'page',
            'locked',
            'Locked',
            '/locked/',
            '',
            '<!-- wp:group {"templateLock":"contentOnly"} --><div class="wp-block-group">' . self::paragraph('Opening hours vary') . '</div><!-- /wp:group -->'
            . self::paragraph('Licensed and insured', ['lock' => ['move' => true, 'remove' => true]])
        );
        $locator = new EditLocator($routes, FakeRouteSource::layout(), new Locks('7.1.2'), self::client());

        $inGroup = $locator->find('/locked/', 'Opening hours vary')['chain'][0];
        $this->assertSame('pt:page', $inGroup['id']);
        $this->assertSame(['effective' => 'content_only', 'src' => 'template_lock', 'text_editable' => true], $inGroup['lock']);
        $this->assertSame('partial', $inGroup['client_can']);

        $locked = $locator->find('/locked/', 'Licensed and insured')['chain'][0];
        $this->assertSame(['effective' => 'all', 'src' => 'block', 'text_editable' => true], $locked['lock']);
        $this->assertSame('yes', $locked['client_can']);
        $this->assertSame('the editor role can open it; all (block): text can change, cannot be moved or removed', $locked['client_can_why']);
    }

    public function testClientCanFollowsTheRolesCapabilitiesAndTheLock(): void
    {
        $editor = self::client();
        $none = ['effective' => 'none', 'src' => 'none', 'text_editable' => true];
        $content = ['effective' => 'content_only', 'src' => 'wp7_default', 'text_editable' => true];

        $this->assertSame('yes', $editor->verdict('post.php?post=12&action=edit', $none)['client_can']);
        $this->assertSame('partial', $editor->verdict('post.php?post=12&action=edit', $content)['client_can']);
        $this->assertSame('no', $editor->verdict('post.php?post=12&action=edit', ['text_editable' => false] + $content)['client_can']);
        $this->assertSame('no', $editor->verdict('site-editor.php?p=/wp_template_part/vwf-fixture//footer&canvas=edit', $content)['client_can']);
        $this->assertSame(
            ['client_can' => 'no', 'client_can_why' => 'no edit screen in wp-admin; it is changed in code'],
            $editor->verdict(null, $none)
        );

        $admin = new ClientAccess('administrator', static fn (string $cap): bool => true, static fn (string $edit): string => 'edit_theme_options');
        $this->assertSame('partial', $admin->verdict('site-editor.php?p=/wp_template_part/vwf-fixture//footer&canvas=edit', $content)['client_can']);
    }

    private static function locator(): EditLocator
    {
        return new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout(), new Locks('7.1.2'), self::client());
    }

    /**
     * An editor: everything but theme options and site settings.
     */
    private static function client(): ClientAccess
    {
        return new ClientAccess(
            'editor',
            static fn (string $cap): bool => !in_array($cap, ['edit_theme_options', 'manage_options', 'customize'], true),
            static fn (string $edit): string => match ((string) strtok($edit, '?')) {
                'site-editor.php'     => 'edit_theme_options',
                'options-general.php' => 'manage_options',
                'post.php'            => 'edit_others_pages',
                default               => 'read',
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

    /**
     * @return list<BlockNode>
     */
    private static function tree(string $markup): array
    {
        return (new BlockTreeBuilder())->build(parse_blocks($markup))['tree'];
    }

    private static function sorted(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);
        }

        return $value;
    }
}
