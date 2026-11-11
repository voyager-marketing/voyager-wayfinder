<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Inspector;

use Voyager\Wayfinder\Blocks\BlockRefs;
use Voyager\Wayfinder\Blocks\BlockTreeBuilder;
use Voyager\Wayfinder\Edit\EditLocator;

/**
 * Marks the rendered blocks a page is built from with where each one is
 * edited, for the front-end inspector.
 *
 * Each template part, synced pattern, menu (`core/navigation` with a ref),
 * post content block and block with `metadata.bindings` gets two
 * attributes on its first tag:
 *
 *  - `data-vwf-src`, the id chain from the outermost container to the
 *    block, joined with `>`: `part:footer>bind:vwf-fixture/business-info.phone`.
 *  - `data-vwf-edit`, the admin URL of the last id's edit screen, when it
 *    has one.
 *
 * Ids come from the same code the scanners use (BlockTreeBuilder's
 * reference records through BlockRefs), and labels and edit paths from
 * EditLocator's chain for this URL (see targets()), so the inspector, find
 * and the manual name a thing the same way and send you to the same screen.
 *
 * WordPress renders inner blocks before the block around them, so the
 * container stack is kept by the caller's hooks: enter() as a block starts
 * (`pre_render_block`), leave() as its markup comes back (`render_block`).
 * Nothing here calls WordPress; the first-tag write goes through a
 * TagWriter.
 *
 * @phpstan-import-type Result from EditLocator
 * @phpstan-type Target array{label: string, edit: string|null, client_can: 'yes'|'partial'|'no', client_can_why: string}
 * @phpstan-type Context array{postId?: mixed, postType?: mixed}
 */
final class Annotator
{
    public const SRC = 'data-vwf-src';

    public const EDIT = 'data-vwf-edit';

    public const SEPARATOR = '>';

    /** Blocks that place content stored elsewhere, and so open a level of the chain. */
    public const CONTAINERS = ['core/block', 'core/navigation', 'core/post-content', 'core/template-part'];

    /** Bound attributes that carry the block's visible text, named first when a block binds several. */
    private const TEXT_BINDINGS = ['content', 'text'];

    /** @var list<array{name: string, id: string|null}> Open containers, outermost first. */
    private array $stack = [];

    /**
     * @param array<string, Target> $targets Id => label and edit URL, from targets().
     * @param int|null              $postId  The post this URL shows. Post content of any other post (a query
     *                                       loop) is marked without an edit URL, because the chain's edit
     *                                       path is this post's.
     */
    public function __construct(
        private readonly array $targets,
        private readonly BlockTreeBuilder $builder,
        private readonly TagWriter $writer,
        private readonly ?int $postId = null
    ) {
    }

    /**
     * Label, edit URL and client verdict per id, from EditLocator::find()
     * run on this URL without text: everything the page is built from.
     *
     * @param Result                   $result
     * @param callable(string): string $adminUrl Turns an edit path (`site-editor.php?...`) into a full URL.
     * @return array<string, Target>
     */
    public static function targets(array $result, callable $adminUrl): array
    {
        $targets = [];

        foreach ($result['chain'] as $entry) {
            $targets[$entry['id']] = [
                'label'          => $entry['label'],
                'edit'           => $entry['edit'] !== null ? $adminUrl($entry['edit']) : null,
                'client_can'     => $entry['client_can'],
                'client_can_why' => $entry['client_can_why'],
            ];
        }

        ksort($targets, SORT_STRING);

        return $targets;
    }

    /**
     * The id one rendered block stands for: `part:`, `pat:` or `nav:` for a
     * pointer, `pt:<post_type>` for post content, the binding's id for a
     * bound block (its text binding when it binds several), else null.
     *
     * @param array<array-key, mixed> $block   A parsed block.
     * @param Context                 $context The block's context.
     */
    public function idFor(array $block, array $context = []): ?string
    {
        $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

        if ($name === 'core/post-content') {
            $type = $context['postType'] ?? null;

            return is_string($type) && $type !== '' ? 'pt:' . $type : null;
        }

        if (in_array($name, self::CONTAINERS, true)) {
            return BlockRefs::pointer($this->builder->reference($name, $attrs));
        }

        return self::bindingOf($attrs);
    }

    /**
     * A block starts rendering. Containers open a level, even one whose id
     * does not resolve, so leave() closes the level it opened.
     *
     * @param array<array-key, mixed> $block
     * @param Context                 $context
     */
    public function enter(array $block, array $context = []): void
    {
        $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';

        if (in_array($name, self::CONTAINERS, true)) {
            $this->stack[] = ['name' => $name, 'id' => $this->idFor($block, $context)];
        }
    }

    /**
     * A block's markup is back: close its level when it opened one, and mark
     * its first tag when it stands for an id.
     *
     * @param array<array-key, mixed> $block
     * @param Context                 $context
     */
    public function leave(string $html, array $block, array $context = []): string
    {
        $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
        $top = $this->stack[count($this->stack) - 1] ?? null;

        if (in_array($name, self::CONTAINERS, true) && $top !== null && $top['name'] === $name) {
            array_pop($this->stack);
            $id = $top['id'];
        } else {
            $id = $this->idFor($block, $context);
        }

        if ($id === null) {
            return $html;
        }

        $chain = array_values(array_filter(array_column($this->stack, 'id'), 'is_string'));
        $chain[] = $id;

        $postId = $context['postId'] ?? null;
        $otherPost = $name === 'core/post-content' && is_int($postId) && $postId !== $this->postId;

        return $this->annotate($html, $chain, !$otherPost);
    }

    /**
     * Marks the first tag of `$html` with the chain and, when it has one and
     * `$withEdit` holds, the edit URL of the chain's last id.
     *
     * @param list<string> $chain Outermost first.
     */
    public function annotate(string $html, array $chain, bool $withEdit = true): string
    {
        if ($chain === [] || !str_contains($html, '<')) {
            return $html;
        }

        $attributes = [self::SRC => implode(self::SEPARATOR, $chain)];
        $edit = $withEdit ? ($this->targets[$chain[count($chain) - 1]]['edit'] ?? null) : null;

        if ($edit !== null) {
            $attributes[self::EDIT] = $edit;
        }

        return $this->writer->write($html, $attributes, self::SRC);
    }

    /**
     * How deep the container stack is now; zero once a page has rendered.
     */
    public function depth(): int
    {
        return count($this->stack);
    }

    /**
     * @param array<array-key, mixed> $attrs
     */
    private static function bindingOf(array $attrs): ?string
    {
        $metadata = $attrs['metadata'] ?? null;
        $entries = is_array($metadata) && is_array($metadata['bindings'] ?? null) ? $metadata['bindings'] : [];
        $ids = [];

        foreach ($entries as $attribute => $entry) {
            $binding = BlockRefs::binding($entry);

            if ($binding !== null) {
                $ids[(string) $attribute] = $binding['id'];
            }
        }

        if ($ids === []) {
            return null;
        }

        foreach (self::TEXT_BINDINGS as $attribute) {
            if (isset($ids[$attribute])) {
                return $ids[$attribute];
            }
        }

        ksort($ids, SORT_STRING);

        return reset($ids);
    }
}
