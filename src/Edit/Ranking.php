<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Edit;

use Voyager\Wayfinder\Blocks\Locks;

/**
 * How find matches a query and orders the homes of a match. Deterministic:
 * no PageRank, no scores, only a tuple compared key by key.
 *
 * Matching tiers, best first:
 *  0. exact text: the block's own text (or a bound value, or the post
 *     title) is the query, once both are normalized;
 *  1. label: a `label` attribute contains the query;
 *  2. text attribute: another text attribute (alt, caption, title...)
 *     contains it;
 *  3. all words: every word of the query appears in the block's text.
 * A class search has one tier of its own, 0.
 *
 * The query is normalized like the text it is compared with: entities
 * decoded, tags stripped, whitespace and NBSP collapsed, lowercased. Words
 * that name a place on the site (a part's area, slug or title words, or
 * footer, header, sidebar, menu, navigation) are location hints: they are
 * taken out of the text to match, unless nothing else is left. A
 * digit-heavy query (four or more digits, at least half its characters)
 * also matches by digits alone, so (540) 555-0142, 540.555.0142 and
 * 555-0142 meet the same bound value.
 *
 * Hits sort by: tier; on this URL's route first; a location hint the hit's
 * container answers; smaller distance from the content; homes with an edit
 * screen before code-only ones; then routes reaching the home, fewer first
 * when the query names a page and more first when it does not; then id and
 * block path, so ties never depend on walk order. The answer is ambiguous
 * when the top two hits tie on tier and on being on the route: a hint or a
 * distance orders two copies, but both are still places to edit.
 *
 * @phpstan-type Query array{text: string, words: list<string>, digits: string|null, hints: list<string>, raw: string}
 * @phpstan-import-type Lock from Locks
 * @phpstan-type Found array{id: string, in: string, path: string|null, snippet: string, tier: int, on_route: bool, distance: int|null, routes: int|null, hint: string|null, code_only: bool, lock: Lock, fp?: string|null, post?: int|null, type?: string|null}
 * @phpstan-type RankedHit array{rank: int, id: string, block_path: string|null, snippet: string, why: list<string>, on_route: bool, distance: int|null, routes: int|null}
 */
final class Ranking
{
    public const TIER_EXACT = 0;

    public const TIER_LABEL = 1;

    public const TIER_ATTRIBUTE = 2;

    public const TIER_WORDS = 3;

    public const TIER_NAMES = ['exact text', 'label', 'text attribute', 'all words present'];

    /** Location words every site has, beside its parts' own area, slug and title words. */
    public const PLACE_WORDS = ['footer', 'header', 'sidebar', 'menu', 'navigation'];

    public const SNIPPET_CHARS = 80;

    /** Attributes that are a label rather than running text. */
    private const LABEL_ATTRIBUTES = ['label'];

    /**
     * @param list<string> $places Location words this site answers to.
     * @return Query
     */
    public static function query(string $text, array $places = []): array
    {
        $normalized = self::normalize($text);
        $words = $normalized === '' ? [] : explode(' ', $normalized);
        $places = array_values(array_unique([...self::PLACE_WORDS, ...array_map(self::normalize(...), $places)]));

        $hints = array_values(array_unique(array_intersect($words, $places)));
        $rest = array_values(array_diff($words, $hints));

        // A query that is only place words still has to match something.
        if ($rest === []) {
            $rest = $words;
            $hints = [];
        }

        $matchText = implode(' ', $rest);

        return [
            'text'   => $matchText,
            'words'  => $rest,
            'digits' => self::isDigitHeavy($matchText) ? self::digits($matchText) : null,
            'hints'  => $hints,
            'raw'    => $normalized,
        ];
    }

    /**
     * The tier a block's own text or a bound value matches at, or null.
     *
     * @param Query $query
     */
    public static function textTier(string $haystack, array $query): ?int
    {
        $text = self::normalize($haystack);

        if ($text === '' || $query['text'] === '') {
            return null;
        }

        if ($text === $query['text'] || ($query['digits'] !== null && self::digits($text) === $query['digits'])) {
            return self::TIER_EXACT;
        }

        return self::hasAllWords($text, $query) ? self::TIER_WORDS : null;
    }

    /**
     * The tier one string attribute matches at, or null. `label` is a label;
     * any other attribute the text pattern accepts is a text attribute.
     *
     * @param Query $query
     */
    public static function attributeTier(string $name, string $value, array $query): ?int
    {
        if (preg_match(Locks::TEXT_ATTRIBUTE, $name) !== 1) {
            return null;
        }

        $text = self::normalize($value);

        if ($text === '' || $query['text'] === '' || !self::contains($text, $query)) {
            return null;
        }

        return in_array(strtolower($name), self::LABEL_ATTRIBUTES, true) ? self::TIER_LABEL : self::TIER_ATTRIBUTE;
    }

    /**
     * Whether the text holds the whole query, or for a digit-heavy query its digits.
     *
     * @param Query $query
     */
    public static function contains(string $normalized, array $query): bool
    {
        if ($query['text'] !== '' && str_contains($normalized, $query['text'])) {
            return true;
        }

        return $query['digits'] !== null && str_contains(self::digits($normalized), $query['digits']);
    }

    /**
     * @param Query $query
     */
    private static function hasAllWords(string $normalized, array $query): bool
    {
        if (self::contains($normalized, $query)) {
            return true;
        }

        if ($query['words'] === []) {
            return false;
        }

        foreach ($query['words'] as $word) {
            if (!str_contains($normalized, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Orders the hits, numbers them from 1 and says why each sits where it
     * does. `$namesPage` flips the routes key: a query that names a page
     * wants the home few routes reach.
     *
     * @param list<Found> $found
     * @return array{hits: list<RankedHit>, ambiguous: bool, order: list<Found>}
     */
    public static function rank(array $found, bool $namesPage): array
    {
        usort($found, static fn (array $a, array $b): int => self::key($a, $namesPage) <=> self::key($b, $namesPage));

        $hits = [];

        foreach ($found as $i => $hit) {
            $hits[] = [
                'rank'       => $i + 1,
                'id'         => $hit['id'],
                'block_path' => $hit['path'],
                'snippet'    => $hit['snippet'],
                'why'        => self::why($hit),
                'on_route'   => $hit['on_route'],
                'distance'   => $hit['distance'],
                'routes'     => $hit['routes'],
            ];
        }

        $ambiguous = count($found) >= 2
            && $found[0]['tier'] === $found[1]['tier']
            && $found[0]['on_route'] === $found[1]['on_route'];

        return ['hits' => $hits, 'ambiguous' => $ambiguous, 'order' => $found];
    }

    /**
     * The sort tuple. Nulls (off the route, routes unknown) sort last.
     *
     * @param Found $hit
     * @return list<int|string>
     */
    private static function key(array $hit, bool $namesPage): array
    {
        $routes = $hit['routes'];

        return [
            $hit['tier'],
            $hit['on_route'] ? 0 : 1,
            $hit['hint'] !== null ? 0 : 1,
            $hit['distance'] ?? PHP_INT_MAX,
            $hit['code_only'] ? 1 : 0,
            $routes === null ? PHP_INT_MAX : ($namesPage ? $routes : -$routes),
            $hit['id'],
            $hit['path'] ?? '',
        ];
    }

    /**
     * @param Found $hit
     * @return list<string>
     */
    private static function why(array $hit): array
    {
        $why = [self::TIER_NAMES[$hit['tier']] ?? 'class'];

        $why[] = $hit['on_route']
            ? sprintf('on this URL, %d %s from the content', (int) $hit['distance'], $hit['distance'] === 1 ? 'step' : 'steps')
            : 'not on this URL';

        if ($hit['hint'] !== null) {
            $why[] = sprintf('the query names the %s', $hit['hint']);
        }

        if ($hit['code_only']) {
            $why[] = 'edited in code only';
        }

        if ($hit['routes'] !== null) {
            $why[] = sprintf('reached by %d %s', $hit['routes'], $hit['routes'] === 1 ? 'route' : 'routes');
        }

        return $why;
    }

    /**
     * Text as compared: tags stripped, entities decoded, whitespace and NBSP
     * collapsed, lowercased.
     */
    public static function normalize(string $text): string
    {
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        return mb_strtolower(trim($text), 'UTF-8');
    }

    /**
     * A snippet of the text as written, whitespace collapsed, at most
     * SNIPPET_CHARS characters.
     */
    public static function snippet(string $text): string
    {
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));

        return mb_strlen($text, 'UTF-8') > self::SNIPPET_CHARS
            ? rtrim(mb_substr($text, 0, self::SNIPPET_CHARS - 1, 'UTF-8')) . '…'
            : $text;
    }

    private static function isDigitHeavy(string $text): bool
    {
        $compact = str_replace(' ', '', $text);
        $digits = strlen(self::digits($text));

        return $digits >= 4 && $compact !== '' && $digits * 2 >= strlen($compact);
    }

    private static function digits(string $text): string
    {
        return (string) preg_replace('/\D+/', '', $text);
    }
}
