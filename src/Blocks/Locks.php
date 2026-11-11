<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Blocks;

use Closure;

/**
 * Whether a block can be moved, removed or rewritten in the editor, and why.
 *
 * The state a block inherits is threaded down the walk the caller already
 * does over a BlockNode tree: `root()` gives the state at the top of a
 * container, `block()` the block's own effective lock, `inner()` the state
 * its children inherit. Nothing here walks a tree twice.
 *
 * Sources, in the order they apply:
 *  1. The nearest ancestor `templateLock` (`all`, `insert`, `contentOnly`,
 *     or `false` to clear an inherited one); with none, the post type's
 *     `template_lock`.
 *  2. A `contentOnly` anywhere above a block is final. Nothing below it can
 *     loosen it (core rule since 6.1).
 *  3. The block's own `lock.move` and `lock.remove`, when defined, replace
 *     the inherited value (the 5.9 table: `remove: true` cannot remove
 *     anywhere; undefined cannot remove under `all` and `insert`; `move`
 *     undefined cannot move under `all` only).
 *  4. On WordPress 7.0 or later, unsynced pattern instances are content
 *     only unless the `disableContentOnlyForUnsyncedPatterns` editor setting
 *     turns that off. The source is `wp7_default`. Template parts are content
 *     only too, but only where a template places them: opened at their own
 *     edit path the editor isolates them and the default does not apply
 *     (`isIsolatedEditorKey` in core's editor.js, read on 7.1.2). So a part
 *     is walked from root() unlocked, and inTemplate() says what the
 *     default does to it inside a template. The
 *     `disableContentOnlyForTemplateParts` editor setting turns that off.
 *  5. Under content only, a block's text is editable when it has an
 *     attribute with `role: content` in the block type registry, or, when
 *     the registry knows no role for it, when it shows text of its own.
 *
 * `effective` is one of EFFECTIVE, least strict first. `insert` means the
 * block can be moved and removed but nothing can be added beside it.
 * effect() says what each one allows in plain words.
 *
 * @phpstan-type State array{mode: 'none'|'insert'|'all'|'content_only', src: 'none'|'template_lock'|'post_type'|'wp7_default', isolated?: true}
 * @phpstan-type Lock array{effective: 'none'|'move'|'remove'|'insert'|'all'|'content_only', src: 'none'|'block'|'template_lock'|'post_type'|'wp7_default', text_editable: bool}
 * @phpstan-type Summary array{effective: 'none'|'move'|'remove'|'insert'|'all'|'content_only', src: 'none'|'block'|'template_lock'|'post_type'|'wp7_default', locked: int}
 * @phpstan-type LockedBlock array{block: string, effective: 'move'|'remove'|'insert'|'all'|'content_only', name: string|null, path: string, src: 'block'|'template_lock'|'post_type'|'wp7_default', text_editable: bool}
 */
final class Locks
{
    /** Effective locks, least strict first. */
    public const EFFECTIVE = ['none', 'move', 'remove', 'insert', 'all', 'content_only'];

    /** Attribute names whose string values show on the page, for blocks the registry gives no role. */
    public const TEXT_ATTRIBUTE = '/(content|label|text|title|caption|alt|placeholder|heading|citation)$/i';

    /** The first release where parts and unsynced patterns default to content only. */
    public const CONTENT_ONLY_DEFAULTS_SINCE = '7.0';

    /** Container kinds root() knows. Anything else starts unlocked. */
    public const CONTENT = 'content';

    public const TEMPLATE_PART = 'template_part';

    /** A registered (code) pattern placed by `core/pattern`: an unsynced pattern. */
    public const UNSYNCED_PATTERN = 'unsynced_pattern';

    /** A synced pattern (`wp_block`), opened on its own. */
    public const SYNCED_PATTERN = 'synced_pattern';

    /** Most locked blocks a container lists; its `locked` count has them all. */
    public const LOCKED_BLOCKS_CAP = 50;

    /** @var Closure(string): (list<string>|null) */
    private Closure $contentRoles;

    /** @var Closure(string): mixed */
    private Closure $postTypeLock;

    /**
     * @param string                                  $wordpressVersion        The site's version; empty reads as before 7.0.
     * @param bool                                    $unsyncedPatternsOptOut  The `disableContentOnlyForUnsyncedPatterns` editor setting.
     * @param (callable(string): (list<string>|null))|null $contentRoles  A block's attributes with `role: content`, or null when the registry gives it none.
     * @param (callable(string): mixed)|null          $postTypeLock            A post type's `template_lock`.
     * @param bool                                    $templatePartsOptOut     The `disableContentOnlyForTemplateParts` editor setting.
     */
    public function __construct(
        private readonly string $wordpressVersion = '',
        private readonly bool $unsyncedPatternsOptOut = false,
        ?callable $contentRoles = null,
        ?callable $postTypeLock = null,
        private readonly bool $templatePartsOptOut = false,
    ) {
        $this->contentRoles = $contentRoles !== null
            ? Closure::fromCallable($contentRoles)
            : static fn (string $name): ?array => null;
        $this->postTypeLock = $postTypeLock !== null
            ? Closure::fromCallable($postTypeLock)
            : static fn (string $type): mixed => null;
    }

    /**
     * The resolver for the running site: its version, the editor setting
     * as filtered, the block type registry and the post type objects.
     */
    public static function forSite(): self
    {
        $version = function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '';

        $optOut = false;
        $partsOptOut = false;
        if (function_exists('apply_filters') && class_exists('WP_Block_Editor_Context')) {
            $settings = apply_filters(
                'block_editor_settings_all',
                ['disableContentOnlyForUnsyncedPatterns' => false, 'disableContentOnlyForTemplateParts' => false],
                new \WP_Block_Editor_Context(['name' => 'core/edit-site'])
            );
            $optOut = is_array($settings) && ($settings['disableContentOnlyForUnsyncedPatterns'] ?? false) === true;
            $partsOptOut = is_array($settings) && ($settings['disableContentOnlyForTemplateParts'] ?? false) === true;
        }

        return new self(
            $version,
            $optOut,
            static function (string $name): ?array {
                if (!class_exists('WP_Block_Type_Registry')) {
                    return null;
                }

                $type = \WP_Block_Type_Registry::get_instance()->get_registered($name);

                if ($type === null || !is_array($type->attributes)) {
                    return null;
                }

                return self::rolesOf($type->attributes);
            },
            static function (string $postType): mixed {
                $object = function_exists('get_post_type_object') ? get_post_type_object($postType) : null;

                return $object !== null ? $object->template_lock : null;
            },
            $partsOptOut
        );
    }

    /**
     * Content-role attribute names from a registered type's attribute
     * schema, or null when none of them has a role. `__experimentalRole`
     * is the name before 6.7.
     *
     * @param array<array-key, mixed> $attributes
     * @return list<string>|null
     */
    public static function rolesOf(array $attributes): ?array
    {
        $content = [];
        $any = false;

        foreach ($attributes as $name => $schema) {
            if (!is_array($schema)) {
                continue;
            }

            $role = $schema['role'] ?? $schema['__experimentalRole'] ?? null;

            if (is_string($role)) {
                $any = true;

                if ($role === 'content') {
                    $content[] = (string) $name;
                }
            }
        }

        sort($content, SORT_STRING);

        return $any ? $content : null;
    }

    public function contentOnlyDefaults(): bool
    {
        return $this->wordpressVersion !== ''
            && version_compare($this->wordpressVersion, self::CONTENT_ONLY_DEFAULTS_SINCE, '>=');
    }

    /**
     * The state at the top of a container as its own edit path opens it: an
     * unsynced pattern on 7.0 or later, or post content under its post
     * type's `template_lock`. Templates start unlocked. Template parts and
     * synced patterns start unlocked and isolated: the editor opens them on
     * their own, where neither the part default nor the unsynced pattern
     * default applies (see inTemplate()).
     *
     * @return State
     */
    public function root(string $kind, ?string $postType = null): array
    {
        if ($kind === self::UNSYNCED_PATTERN && $this->contentOnlyDefaults() && !$this->unsyncedPatternsOptOut) {
            return ['mode' => 'content_only', 'src' => 'wp7_default'];
        }

        if ($kind === self::TEMPLATE_PART || $kind === self::SYNCED_PATTERN) {
            return self::isolated();
        }

        if ($kind === self::CONTENT && $postType !== null) {
            $mode = self::mode(($this->postTypeLock)($postType));

            if ($mode !== null) {
                return ['mode' => $mode, 'src' => 'post_type'];
            }
        }

        return self::unlocked();
    }

    /**
     * @return State
     */
    public static function unlocked(): array
    {
        return ['mode' => 'none', 'src' => 'none'];
    }

    /**
     * Unlocked, in an editor that has one part or synced pattern open on its
     * own. Block locks and `templateLock` still apply there.
     *
     * @return State
     */
    public static function isolated(): array
    {
        return ['mode' => 'none', 'src' => 'none', 'isolated' => true];
    }

    /**
     * What a container is under where a template places it, when that is
     * stricter than its own edit path: a template part on 7.0 or later is
     * content only inside a template (core makes it a content-only section)
     * unless the `disableContentOnlyForTemplateParts` setting is on. Null
     * for anything else.
     *
     * @return State|null
     */
    public function inTemplate(string $kind): ?array
    {
        return $kind === self::TEMPLATE_PART && $this->contentOnlyDefaults() && !$this->templatePartsOptOut
            ? ['mode' => 'content_only', 'src' => 'wp7_default']
            : null;
    }

    /**
     * One block's entry in a container's list of locked blocks: its List
     * View name (`metadata.name`) when it has one, its block type and index
     * path, and its lock. Null when the block is not locked.
     *
     * @param Lock $lock From block().
     * @return LockedBlock|null
     */
    public static function lockedBlock(BlockNode $node, array $lock): ?array
    {
        if ($lock['effective'] === 'none' || $lock['src'] === 'none') {
            return null;
        }

        $metadata = $node->attrs['metadata'] ?? null;
        $name = is_array($metadata) && is_string($metadata['name'] ?? null) && $metadata['name'] !== '' ? $metadata['name'] : null;

        return [
            'block'         => $node->name,
            'effective'     => $lock['effective'],
            'name'          => $name,
            'path'          => $node->indexPath,
            'src'           => $lock['src'],
            'text_editable' => $lock['text_editable'],
        ];
    }

    /**
     * What a block's lock allows, in plain words, for a reader who knows
     * nothing of the lock modes: `all` still lets the text change, and no
     * lock stops it unless the block has no text a writer can change.
     */
    public static function effect(string $effective, bool $textEditable = true): string
    {
        $what = match ($effective) {
            'content_only' => 'cannot be moved, removed or restyled',
            'all'          => 'cannot be moved or removed',
            'insert'       => 'can be moved or removed, but nothing can be added beside it',
            'remove'       => 'cannot be removed',
            'move'         => 'cannot be moved',
            default        => null,
        };

        if ($what === null) {
            return 'not locked';
        }

        return match (true) {
            $textEditable                  => 'text can change, ' . $what,
            $effective === 'content_only'  => 'no text to change, ' . $what,
            default                        => $what,
        };
    }

    /**
     * A block's own effective lock under the state it inherits.
     *
     * @param State $inherited
     * @return Lock
     */
    public function block(BlockNode $node, array $inherited): array
    {
        $inherited = $this->entering($node, $inherited);
        $mode = $inherited['mode'];

        if ($mode === 'content_only') {
            return ['effective' => 'content_only', 'src' => $inherited['src'], 'text_editable' => $this->textEditable($node)];
        }

        $lock = is_array($node->attrs['lock'] ?? null) ? $node->attrs['lock'] : [];
        $move = is_bool($lock['move'] ?? null) ? $lock['move'] : null;
        $remove = is_bool($lock['remove'] ?? null) ? $lock['remove'] : null;

        $canMove = $move !== null ? !$move : $mode !== 'all';
        $canRemove = $remove !== null ? !$remove : !in_array($mode, ['all', 'insert'], true);

        $effective = match (true) {
            !$canMove && !$canRemove => 'all',
            !$canRemove              => 'remove',
            !$canMove                => 'move',
            $mode !== 'none'         => 'insert',
            default                  => 'none',
        };

        $own = $move !== null || $remove !== null;
        $src = match (true) {
            $effective === 'none'                   => 'none',
            $effective === 'insert' || !$own        => $inherited['src'],
            default                                 => 'block',
        };

        return ['effective' => $effective, 'src' => $src, 'text_editable' => $this->textEditable($node)];
    }

    /**
     * The state a block's children inherit: its own `templateLock` when it
     * sets one (`false` clears an inherited `all` or `insert`), else what it
     * inherited. Content only is never loosened.
     *
     * @param State $inherited
     * @return State
     */
    public function inner(BlockNode $node, array $inherited): array
    {
        $inherited = $this->entering($node, $inherited);

        if ($inherited['mode'] === 'content_only' || !array_key_exists('templateLock', $node->attrs)) {
            return $inherited;
        }

        $value = $node->attrs['templateLock'];
        $isolated = array_intersect_key($inherited, ['isolated' => true]);

        if ($value === false) {
            return self::unlocked() + $isolated;
        }

        $mode = self::mode($value);

        return $mode !== null ? ['mode' => $mode, 'src' => 'template_lock'] + $isolated : $inherited;
    }

    /**
     * Every block's lock, by index path. The walk callers do themselves
     * through block() and inner(); this is the same walk for a tree that
     * has nothing else to do.
     *
     * @param list<BlockNode> $nodes
     * @param State           $state
     * @return array<string, Lock>
     */
    public function tree(array $nodes, array $state): array
    {
        $locks = [];

        foreach ($nodes as $node) {
            $locks[$node->indexPath] = $this->block($node, $state);
            $locks += $this->tree($node->children, $this->inner($node, $state));
        }

        return $locks;
    }

    /**
     * Every locked block in a tree, in parse order, at most
     * LOCKED_BLOCKS_CAP (see lockedBlock()).
     *
     * @param list<BlockNode> $nodes
     * @param State           $state
     * @return list<LockedBlock>
     */
    public function lockedBlocks(array $nodes, array $state): array
    {
        $locked = [];

        foreach ($nodes as $node) {
            $entry = self::lockedBlock($node, $this->block($node, $state));

            if ($entry !== null) {
                $locked[] = $entry;
            }

            $locked = [...$locked, ...$this->lockedBlocks($node->children, $this->inner($node, $state))];
        }

        return array_slice($locked, 0, self::LOCKED_BLOCKS_CAP);
    }

    /**
     * The strictest of a set of locks, and how many are locked at all.
     * Ties go to the first seen, so the source follows walk order.
     *
     * @param iterable<Lock> $locks
     * @return Summary
     */
    public static function strictest(iterable $locks): array
    {
        $summary = ['effective' => 'none', 'src' => 'none', 'locked' => 0];

        foreach ($locks as $lock) {
            if ($lock['effective'] === 'none') {
                continue;
            }

            $summary['locked']++;

            if (self::rank($lock['effective']) > self::rank($summary['effective'])) {
                $summary['effective'] = $lock['effective'];
                $summary['src'] = $lock['src'];
            }
        }

        return $summary;
    }

    public static function rank(string $effective): int
    {
        $rank = array_search($effective, self::EFFECTIVE, true);

        return $rank === false ? 0 : $rank;
    }

    /**
     * A container's lock as a whole: its root state, with text editable.
     *
     * @param State $state
     * @return Lock
     */
    public static function ofState(array $state): array
    {
        return ['effective' => $state['mode'], 'src' => $state['src'], 'text_editable' => true];
    }

    /**
     * Whether the block is an unsynced pattern instance: an inlined copy of
     * a pattern, which keeps the pattern's name in `metadata.patternName`.
     */
    public static function isUnsyncedPatternInstance(BlockNode $node): bool
    {
        $metadata = $node->attrs['metadata'] ?? null;

        return $node->reference === null
            && is_array($metadata)
            && is_string($metadata['patternName'] ?? null)
            && $metadata['patternName'] !== '';
    }

    /**
     * The state at a block itself: content only from 7.0 when the block is
     * an unsynced pattern instance and the site has not opted out, except in
     * an isolated editor (a part or synced pattern open on its own), where
     * core makes no pattern a content-only section.
     *
     * @param State $inherited
     * @return State
     */
    private function entering(BlockNode $node, array $inherited): array
    {
        if (
            $inherited['mode'] !== 'content_only'
            && !isset($inherited['isolated'])
            && $this->contentOnlyDefaults()
            && !$this->unsyncedPatternsOptOut
            && self::isUnsyncedPatternInstance($node)
        ) {
            return ['mode' => 'content_only', 'src' => 'wp7_default'];
        }

        return $inherited;
    }

    /**
     * Whether the block has text a writer can change: a content-role
     * attribute, or with no role known, its own text or a text attribute.
     * An attribute a binding fills is not the writer's to change.
     */
    private function textEditable(BlockNode $node): bool
    {
        $bound = array_keys(BlockRefs::bindingsOf($node));
        $roles = ($this->contentRoles)($node->name);

        if ($roles !== null) {
            return array_diff($roles, $bound) !== [];
        }

        if ($node->text !== '' && array_intersect($bound, ['content', 'text']) === []) {
            return true;
        }

        foreach ($node->attrs as $name => $value) {
            if (
                is_string($value)
                && $value !== ''
                && !in_array($name, $bound, true)
                && preg_match(self::TEXT_ATTRIBUTE, (string) $name) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `templateLock` value as a mode, or null for anything that is not one.
     *
     * @return 'all'|'insert'|'content_only'|null
     */
    private static function mode(mixed $value): ?string
    {
        return match ($value) {
            'all'         => 'all',
            'insert'      => 'insert',
            'contentOnly' => 'content_only',
            default       => null,
        };
    }
}
