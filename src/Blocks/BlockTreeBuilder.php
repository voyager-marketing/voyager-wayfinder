<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Blocks;

/**
 * Walks parse_blocks() output into a tree of BlockNode plus a summary.
 *
 * Ported from voyager-orbit src/Modules/Blocks/Services/BlockTreeBuilder.php
 * @ abe7d26. Two additions, both in reference(): a synced pattern record
 * carries the pattern's `slug`, and `core/navigation` with a `ref` becomes a
 * fourth pointer block, resolved to its `wp_navigation` post. A navigation
 * block with no `ref` (inline links, or the fallback menu) is not a pointer
 * and is walked like any other block. Each node also carries its full own
 * text (BlockNode::$text) for the edit locator's text search.
 *
 * Pure PHP over the array shape core's parser emits, so it runs the same
 * against a live post and against a fixture in the unit suite. The only
 * WordPress calls are behind ReferenceResolver, which is injectable.
 *
 * Parity contract with Orbit's walker: tests/fixtures/blocks/hero-nested.paths.json (BlockTreePathsGoldenTest).
 *
 * Rules the walker applies:
 *  - Freeform whitespace (blockName null, empty innerHTML) is skipped and
 *    never counted. Freeform that carries content is reported as
 *    `core/freeform`, the name the editor gives it.
 *  - `depth` is zero-based and `index_path` is the dotted position from the
 *    root, both counting only the siblings that survived the skip.
 *  - A block carrying `templateLock` or `allowedBlocks` with no `layout`
 *    attribute counts toward `curation_without_layout` (Gutenberg #63820).
 *  - `core/block`, `core/pattern`, `core/template-part` and a `core/navigation`
 *    with a `ref` are resolved to a reference record and never inlined.
 *  - Each block's className tokens and `metadata.name` are counted into
 *    the summary's `classes` and `names` (see ClassIndex), pointer blocks
 *    included, since their attributes sit in this markup.
 *  - The label is the block's own text: the first LABEL_MAX_CHARS characters
 *    of its innerHTML text, `media #<id>` for an image, `full-bleed section`
 *    for a cover aligned full, and the reference target for the pointer
 *    blocks. Containers whose text lives in their children get none.
 */
final class BlockTreeBuilder
{
    public const LABEL_MAX_CHARS = 32;

    public const FREEFORM_NAME = 'core/freeform';

    private int $total = 0;

    private int $maxDepth = 0;

    private int $core = 0;

    private int $custom = 0;

    private int $curationWithoutLayout = 0;

    /** @var array<string, int> */
    private array $byBlock = [];

    /** @var array<string, int> className token => blocks carrying it. */
    private array $classes = [];

    /** @var array<string, int> `metadata.name` => blocks carrying it. */
    private array $names = [];

    public function __construct(private readonly ReferenceResolver $resolver = new ReferenceResolver())
    {
    }

    /**
     * Build the full tree and its summary from parse_blocks() output.
     *
     * @param array<mixed> $blocks parse_blocks() output.
     * @return array{tree: list<BlockNode>, summary: array<string, mixed>}
     */
    public function build(array $blocks): array
    {
        $this->total                 = 0;
        $this->maxDepth              = 0;
        $this->core                  = 0;
        $this->custom                = 0;
        $this->curationWithoutLayout = 0;
        $this->byBlock               = [];
        $this->classes               = [];
        $this->names                 = [];

        $tree = $this->walk($blocks, 0, '');

        return ['tree' => $tree, 'summary' => $this->summary()];
    }

    /**
     * @return array{total: int, depth: int, by_block: array<string, int>, core: int, custom: int, custom_ratio: float, curation_without_layout: int, classes: array<string, int>, names: array<string, int>}
     */
    private function summary(): array
    {
        $byBlock = $this->byBlock;
        ksort($byBlock);
        $classes = $this->classes;
        ksort($classes, SORT_STRING);
        $names = $this->names;
        ksort($names, SORT_STRING);

        return [
            'total'                   => $this->total,
            'depth'                   => $this->total === 0 ? 0 : $this->maxDepth,
            'by_block'                => $byBlock,
            'core'                    => $this->core,
            'custom'                  => $this->custom,
            'custom_ratio'            => $this->total === 0 ? 0.0 : round($this->custom / $this->total, 3),
            'curation_without_layout' => $this->curationWithoutLayout,
            'classes'                 => $classes,
            'names'                   => $names,
        ];
    }

    /**
     * @param array<mixed> $blocks
     * @return list<BlockNode>
     */
    private function walk(array $blocks, int $depth, string $parentPath): array
    {
        $nodes = [];
        $index = 0;

        foreach ($blocks as $block) {
            if (!is_array($block) || self::isWhitespaceFreeform($block)) {
                continue;
            }

            $name = self::name($block);
            $path = $parentPath === '' ? (string) $index : $parentPath . '.' . $index;
            $index++;

            $attrs     = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $inner     = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            $innerHtml = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';

            $this->count($name, $depth);
            $this->countClasses($attrs);

            $flags = self::flags($attrs);
            if ($flags['curationWithoutLayout']) {
                $this->curationWithoutLayout++;
            }

            $reference = $this->reference($name, $attrs);
            $children  = $this->walk($inner, $depth + 1, $path);
            $label     = self::label($name, $attrs, $innerHtml, $reference);

            $nodes[] = new BlockNode(
                $name,
                $depth,
                $path,
                $attrs,
                count($children),
                $flags,
                $label,
                $reference,
                $children,
                self::text($innerHtml)
            );
        }

        return $nodes;
    }

    /**
     * @param array<mixed> $block
     */
    private static function isWhitespaceFreeform(array $block): bool
    {
        if (($block['blockName'] ?? null) !== null) {
            return false;
        }

        $innerHtml = $block['innerHTML'] ?? '';

        return !is_string($innerHtml) || trim($innerHtml) === '';
    }

    /**
     * @param array<mixed> $block
     */
    private static function name(array $block): string
    {
        $name = $block['blockName'] ?? null;

        return is_string($name) && $name !== '' ? $name : self::FREEFORM_NAME;
    }

    /**
     * @param array<array-key, mixed> $attrs
     */
    private function countClasses(array $attrs): void
    {
        foreach (ClassIndex::tokens($attrs) as $token) {
            $this->classes[$token] = ($this->classes[$token] ?? 0) + 1;
        }

        $name = ClassIndex::name($attrs);

        if ($name !== null) {
            $this->names[$name] = ($this->names[$name] ?? 0) + 1;
        }
    }

    private function count(string $name, int $depth): void
    {
        $this->total++;
        $this->maxDepth = max($this->maxDepth, $depth);

        $this->byBlock[$name] = ($this->byBlock[$name] ?? 0) + 1;

        if (str_starts_with($name, 'core/')) {
            $this->core++;
        } else {
            $this->custom++;
        }
    }

    /**
     * The per-node flags.
     *
     * `templateLock` carries the attribute's value (a string, or `false` for
     * an explicit unlock) so a reader can tell `contentOnly` from `all`;
     * the rest are presence booleans, plus `className` verbatim.
     *
     * @param array<mixed> $attrs
     * @return array{templateLock: mixed, allowedBlocks: bool, lock: bool, layout: bool, bindings: bool, className: string|null, curationWithoutLayout: bool}
     */
    public static function flags(array $attrs): array
    {
        $hasCuration = array_key_exists('templateLock', $attrs) || array_key_exists('allowedBlocks', $attrs);
        $hasLayout   = array_key_exists('layout', $attrs);

        $lock      = $attrs['lock'] ?? null;
        $metadata  = $attrs['metadata'] ?? null;
        $bindings  = is_array($metadata) ? ($metadata['bindings'] ?? null) : null;
        $className = $attrs['className'] ?? null;

        return [
            'templateLock'          => array_key_exists('templateLock', $attrs) ? $attrs['templateLock'] : null,
            'allowedBlocks'         => array_key_exists('allowedBlocks', $attrs),
            'lock'                  => is_array($lock) && $lock !== [],
            'layout'                => $hasLayout,
            'bindings'              => is_array($bindings) && $bindings !== [],
            'className'             => is_string($className) ? $className : null,
            'curationWithoutLayout' => $hasCuration && !$hasLayout,
        ];
    }

    /**
     * The reference record for a pointer block (see BlockRefs::pointer), or
     * null for any other block. Public so the front-end inspector names a
     * rendered block exactly as the scanners name it.
     *
     * @param array<mixed> $attrs
     * @return array<string, mixed>|null
     */
    public function reference(string $name, array $attrs): ?array
    {
        switch ($name) {
            case 'core/block':
                $id       = self::intAttr($attrs['ref'] ?? null);
                $resolved = $id > 0 ? $this->resolver->syncedPattern($id) : null;

                return [
                    'type'  => 'synced_pattern',
                    'id'    => $id,
                    'slug'  => $resolved['slug'] ?? null,
                    'title' => $resolved['title'] ?? null,
                    'found' => $resolved !== null,
                ];

            case 'core/pattern':
                $slug     = self::stringAttr($attrs['slug'] ?? null);
                $resolved = $slug !== '' ? $this->resolver->pattern($slug) : null;

                return [
                    'type'       => 'pattern',
                    'slug'       => $slug,
                    'title'      => $resolved['title'] ?? null,
                    'registered' => $resolved['registered'] ?? null,
                ];

            case 'core/template-part':
                $theme = self::stringAttr($attrs['theme'] ?? null);
                $area  = self::stringAttr($attrs['area'] ?? null);

                return [
                    'type'  => 'template_part',
                    'slug'  => self::stringAttr($attrs['slug'] ?? null),
                    'theme' => $theme !== '' ? $theme : null,
                    'area'  => $area !== '' ? $area : null,
                ];

            case 'core/navigation':
                $id = self::intAttr($attrs['ref'] ?? null);
                if ($id <= 0) {
                    return null;
                }

                $resolved = $this->resolver->navigation($id);

                return [
                    'type'  => 'navigation',
                    'id'    => $id,
                    'slug'  => $resolved['slug'] ?? null,
                    'title' => $resolved['title'] ?? null,
                    'found' => $resolved !== null,
                ];
        }

        return null;
    }

    /**
     * @param array<mixed>              $attrs
     * @param array<string, mixed>|null $reference
     */
    private static function label(string $name, array $attrs, string $innerHtml, ?array $reference): string
    {
        if ($name === 'core/image') {
            $id = self::intAttr($attrs['id'] ?? null);
            if ($id > 0) {
                return self::truncate('media #' . $id);
            }
        }

        if ($name === 'core/cover' && ($attrs['align'] ?? null) === 'full') {
            return 'full-bleed section';
        }

        if ($reference !== null) {
            return self::truncate(self::referenceLabel($reference));
        }

        return self::truncate(self::text($innerHtml));
    }

    /**
     * @param array<string, mixed> $reference
     */
    private static function referenceLabel(array $reference): string
    {
        $type = $reference['type'] ?? '';

        if ($type === 'synced_pattern' || $type === 'navigation') {
            $id    = self::intAttr($reference['id'] ?? null);
            $title = self::stringAttr($reference['title'] ?? null);
            $found = (bool) ($reference['found'] ?? false);
            $kind  = $type === 'navigation' ? 'menu #' : 'ref #';

            return $kind . $id . ' ' . ($found ? ($title !== '' ? $title : 'untitled') : 'missing');
        }

        if ($type === 'pattern') {
            return self::stringAttr($reference['slug'] ?? null);
        }

        if ($type === 'template_part') {
            $slug  = self::stringAttr($reference['slug'] ?? null);
            $theme = self::stringAttr($reference['theme'] ?? null);

            return $theme !== '' ? $slug . ' (' . $theme . ')' : $slug;
        }

        return '';
    }

    /**
     * Visible text of a block's own innerHTML: tags stripped, entities
     * decoded, whitespace (including non-breaking) collapsed to one space.
     */
    public static function text(string $html): string
    {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Hard cut at LABEL_MAX_CHARS characters, then trailing whitespace
     * trimmed, so a cut that lands on a space does not leave one behind.
     */
    public static function truncate(string $text): string
    {
        return rtrim(mb_substr($text, 0, self::LABEL_MAX_CHARS, 'UTF-8'));
    }

    private static function intAttr(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function stringAttr(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
