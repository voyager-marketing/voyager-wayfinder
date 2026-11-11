<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Blocks;

/**
 * One node of a parsed block tree, as BlockTreeBuilder sees it.
 *
 * Ported from voyager-orbit src/Modules/Blocks/Services/BlockNode.php @ abe7d26.
 * Unchanged apart from the namespace, the navigation reference type and
 * `text`, which the edit locator searches.
 *
 * A plain value object: nothing mutates it once the walker has built it.
 * `depth` is zero-based (a top-level block is depth 0), `indexPath` is the
 * dotted position from the root ("0.1.2", counting only the siblings that
 * survived the freeform-whitespace skip), and `innerBlockCount` is the number
 * of real children the block carries. `text` is the visible text of the
 * block's own innerHTML, in full, where `label` stops at 32 characters.
 */
final class BlockNode
{
    /**
     * @param array<string, mixed>      $attrs     The block's attrs as parsed.
     * @param array<string, mixed>      $flags     See BlockTreeBuilder::flags().
     * @param array<string, mixed>|null $reference Resolved core/block, core/pattern,
     *                                             core/template-part or core/navigation
     *                                             record, else null.
     * @param list<BlockNode>           $children  Nested blocks, freeform whitespace skipped.
     */
    public function __construct(
        public readonly string $name,
        public readonly int $depth,
        public readonly string $indexPath,
        public readonly array $attrs,
        public readonly int $innerBlockCount,
        public readonly array $flags,
        public readonly string $label,
        public readonly ?array $reference,
        public readonly array $children = [],
        public readonly string $text = '',
    ) {
    }
}
