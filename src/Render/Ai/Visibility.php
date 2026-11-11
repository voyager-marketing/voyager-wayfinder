<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use Voyager\Wayfinder\Manifest\IdFormat;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;

/**
 * Which items the brief and the index list, and which they fold into one
 * count line per kind.
 *
 * A child theme inherits every template and part its parent ships, and the
 * parent's templates pull in its registered patterns. On the fixture that is
 * most of the layout section, and none of it is how the site is built. So a
 * template or part served from the parent theme's files, and a registered
 * (code) pattern, are listed only when something listed points at them (a
 * route's template, a part in a listed template, a pattern in a listed part)
 * or published content uses them. Everything else becomes a line like
 * `tpl: +6 unused from twentytwentyfive (section layout)`.
 *
 * A plugin's REST routes are named, not read, and a site carries dozens of
 * them, so they fold the same way (`rest: +40 from plugins (section code)`)
 * unless something listed points at them. The theme's and mu-plugins'
 * routes are always listed.
 *
 * Only the compact tiers fold. The manifest and the section tier keep every
 * item.
 */
final class Visibility
{
    /** @var list<array{item: Item, section: string}> Sorted by id. */
    public readonly array $listed;

    /** @var list<string> One count line per folded kind, sorted. */
    public readonly array $folds;

    /** @var array<string, int> Folded items per id prefix. */
    public readonly array $foldedCounts;

    /**
     * @param list<array{item: Item, section: string}> $listed
     * @param list<string>                             $folds
     * @param array<string, int>                       $foldedCounts
     */
    private function __construct(array $listed, array $folds, array $foldedCounts)
    {
        $this->listed = $listed;
        $this->folds = $folds;
        $this->foldedCounts = $foldedCounts;
    }

    public static function of(Manifest $manifest): self
    {
        $stylesheet = self::stylesheet($manifest);

        /** @var array<string, array{item: Item, section: string}> $all */
        $all = [];

        /** @var array<string, true> $candidates Items listed only when something uses them. */
        $candidates = [];

        foreach ($manifest->sections as $section) {
            foreach ($section->items as $item) {
                $all[$item->id] = ['item' => $item, 'section' => $section->name];

                if (self::isCandidate($item, $stylesheet)) {
                    $candidates[$item->id] = true;
                }
            }
        }

        ksort($all, SORT_STRING);

        $listed = array_diff_key($all, $candidates);

        // Follow refs out from what is listed until nothing new is reached.
        do {
            $added = false;

            foreach (array_keys(array_diff_key($candidates, $listed)) as $id) {
                if (self::usedByContent($all[$id]['item']) || self::referencedBy($id, $listed)) {
                    $listed[$id] = $all[$id];
                    $added = true;
                }
            }
        } while ($added);

        ksort($listed, SORT_STRING);

        $groups = [];
        $foldedCounts = [];

        foreach (array_diff_key($all, $listed) as $id => ['item' => $item, 'section' => $section]) {
            $prefix = substr($id, 0, (int) strpos($id, ':'));
            $what = match ($item->kind) {
                'pattern'    => 'unused registered',
                'rest_route' => 'from plugins',
                default      => 'unused from ' . (string) $item->facts['file_theme'],
            };

            $key = $prefix . ': +%d ' . $what . ' (section ' . $section . ')';
            $groups[$key] = ($groups[$key] ?? 0) + 1;
            $foldedCounts[$prefix] = ($foldedCounts[$prefix] ?? 0) + 1;
        }

        ksort($groups, SORT_STRING);
        ksort($foldedCounts, SORT_STRING);

        $folds = [];
        foreach ($groups as $format => $count) {
            $folds[] = sprintf($format, $count);
        }

        return new self(array_values($listed), $folds, $foldedCounts);
    }

    /**
     * The active theme, from the site section. Null when the manifest has no
     * site section, and then nothing counts as inherited.
     */
    private static function stylesheet(Manifest $manifest): ?string
    {
        foreach ($manifest->sections as $section) {
            $item = $section->item('opt:stylesheet');

            if ($item !== null && is_string($item->facts['value'] ?? null)) {
                return $item->facts['value'];
            }
        }

        return null;
    }

    private static function isCandidate(Item $item, ?string $stylesheet): bool
    {
        if ($item->kind === 'pattern') {
            return ($item->facts['registered'] ?? false) === true;
        }

        if ($item->kind === 'rest_route') {
            return is_array($item->facts['owner'] ?? null) && ($item->facts['owner']['kind'] ?? null) === 'plugin';
        }

        if ($item->kind !== 'template' && $item->kind !== 'template_part') {
            return false;
        }

        $fileTheme = $item->facts['file_theme'] ?? null;

        return $stylesheet !== null
            && ($item->facts['source'] ?? null) === 'theme'
            && is_string($fileTheme)
            && $fileTheme !== ''
            && $fileTheme !== $stylesheet;
    }

    /**
     * The `used_in` stat names published content as `<post_type>:<slug>`,
     * which is never an item id.
     */
    private static function usedByContent(Item $item): bool
    {
        $usedIn = $item->stats['used_in'] ?? [];

        foreach (is_array($usedIn) ? $usedIn : [] as $container) {
            if (is_string($container) && !IdFormat::isValid($container)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{item: Item, section: string}> $listed
     */
    private static function referencedBy(string $id, array $listed): bool
    {
        foreach ($listed as ['item' => $item]) {
            if (in_array($id, $item->refs, true)) {
                return true;
            }
        }

        return false;
    }
}
