<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support;

/**
 * A stand-in for the voyager-open-blocks pack's
 * `VoyagerOpenBlocks\Purge\site_data_plan()`, so the unit suite can test the
 * plan read with no WordPress and no pack. The function lives under this
 * namespace, not the pack's, so loading it never makes the real name exist
 * for another test.
 *
 * plan() builds what the pack documents it returns (voyager-open-blocks
 * docs/use-in-your-theme.md at 7531d79): the permalinks of the posts by ID,
 * then the front page, and the context.
 */
final class OpenBlocksStub
{
    /** The stub's name, for RefreshPlan::planFunction(). */
    public const FUNCTION = 'Voyager\\Wayfinder\\Tests\\Support\\OpenBlocks\\site_data_plan';

    public const HOME = 'https://example.test/';

    /** @var array<string, array<array-key, mixed>|null> Key => what the stub returns for it. A key not here gets null. */
    public static array $plans = [];

    /** @var list<list<string>> Every call, by the keys it was given. */
    public static array $calls = [];

    public static function load(): void
    {
        require_once __DIR__ . '/open-blocks-stub.php';
        self::$plans = [];
        self::$calls = [];
    }

    /**
     * What the stub answers for some keys: the first key's plan.
     *
     * @param list<string> $keys
     * @return array<array-key, mixed>|null
     */
    public static function answer(array $keys): ?array
    {
        self::$calls[] = $keys;

        return self::$plans[$keys[0] ?? ''] ?? null;
    }

    /**
     * A plan in the documented shape: each post's permalink by ID, then the
     * front page.
     *
     * @param array<int, string> $posts     Post ID => permalink.
     * @param list<string>       $templates `<theme>//<slug>` ids.
     * @return array{urls: list<string>, context: array{source: string, keys: list<string>, post_ids: list<int>, templates: list<string>, reason: string, purge_all: bool}}
     */
    public static function plan(string $key, array $posts, array $templates = [], bool $all = false): array
    {
        ksort($posts);

        return [
            'urls'    => [...array_values($posts), self::HOME],
            'context' => [
                'source'    => 'voyager/site-data',
                'keys'      => [$key],
                'post_ids'  => array_keys($posts),
                'templates' => $templates,
                'reason'    => 'site-data',
                'purge_all' => $all || $templates !== [],
            ],
        ];
    }
}
