<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Throwable;
use WP_Post;

/**
 * What a change to a Site Data value refreshes, read from the
 * voyager-open-blocks pack when it is active. Wayfinder owns the map and the
 * pack owns the purge: the pack keeps a usage index of its bindings and
 * purges page caches from it, and this only asks it what a purge would cover.
 *
 * The pack documents `VoyagerOpenBlocks\Purge\site_data_plan( array $keys ): ?array`
 * as one of its read-only helpers (voyager-open-blocks
 * docs/use-in-your-theme.md, "Functions", and inc/freshness/purge.php at
 * 7531d79). It works out a purge without running it: null when no published
 * post of a public type and no template or part of the active theme shows the
 * keys or a key made from them (`phone_link` for `phone`); otherwise `urls`,
 * the permalinks of the first 50 of those posts by ID and then the front
 * page, and `context` with `post_ids`, `templates` (`<theme>//<slug>`) and
 * `purge_all`, true when a template or part shows them or more than 50 posts
 * do. It saves no option or post meta and fires no purge. It does prime the
 * object cache with the posts it finds, as Wayfinder's own get_posts() reads
 * do.
 *
 * The stat it gives is `count`, the distinct URLs the plan lists (the front
 * page included, once even when it is one of the posts), `pages`, the posts
 * as `<post_type>:<slug>` sorted and capped as `used_in` is, `templates` as
 * the plan names them, and `all_pages` from `purge_all`. A null plan is a
 * real answer, a change refreshes nothing, so it gives a count of 0.
 *
 * Fails soft. With no pack, or a plan that throws or returns something this
 * cannot read, there is no stat at all, never a 0, and only the log says why.
 *
 * @phpstan-type Refreshes array{all_pages: bool, count: int, pages: list<string>, templates: list<string>}
 */
final class RefreshPlan
{
    /** The pack's read-only helper. */
    public const FUNCTION = 'VoyagerOpenBlocks\\Purge\\site_data_plan';

    /** @var (callable(list<string>): mixed)|null */
    private $plan;

    /** @var callable(int): (string|null) */
    private $page;

    /** @var callable(string): void */
    private $log;

    /**
     * @param (callable(list<string>): mixed)|null $plan The pack's site_data_plan(), or null when it is not active.
     * @param callable(int): (string|null)        $page A post's `<post_type>:<slug>`, or null when there is no such post.
     * @param (callable(string): void)|null        $log  Where a failed read is noted.
     */
    public function __construct(?callable $plan, callable $page, ?callable $log = null)
    {
        $this->plan = $plan;
        $this->page = $page;
        $this->log = $log ?? static function (string $message): void {
        };
    }

    /**
     * Over the live site: the pack's helper when it is loaded, posts read
     * with get_post(), and failures sent to the PHP error log.
     */
    public static function forSite(string $function = self::FUNCTION): self
    {
        return new self(
            self::planFunction($function),
            static function (int $id): ?string {
                $post = get_post($id);

                if (!$post instanceof WP_Post) {
                    return null;
                }

                return $post->post_type . ':' . ($post->post_name !== '' ? $post->post_name : '#' . $post->ID);
            },
            static function (string $message): void {
                // The manifest stays as it would be without the pack; only the log says why.
                error_log($message);
            }
        );
    }

    /**
     * The pack's helper, by name, when it is loaded; null otherwise. Asked
     * when the scan runs, so a plugin loaded later counts.
     *
     * @return (callable(list<string>): mixed)|null
     */
    public static function planFunction(string $function = self::FUNCTION): ?callable
    {
        return function_exists($function) ? $function : null;
    }

    /**
     * What a change to one Site Data key refreshes, or null when that cannot
     * be told.
     *
     * @return Refreshes|null
     */
    public function refreshes(string $key): ?array
    {
        if ($this->plan === null) {
            return null;
        }

        try {
            $plan = ($this->plan)([$key]);
        } catch (Throwable $e) {
            ($this->log)(sprintf('Wayfinder could not read what a change to Site Data key "%s" refreshes: %s', $key, $e->getMessage()));

            return null;
        }

        if ($plan === null) {
            return ['all_pages' => false, 'count' => 0, 'pages' => [], 'templates' => []];
        }

        $read = is_array($plan) ? $this->read($plan) : null;

        if ($read === null) {
            ($this->log)(sprintf('Wayfinder could not read what a change to Site Data key "%s" refreshes: the plan is not in the documented shape.', $key));
        }

        return $read;
    }

    /**
     * The stat from a plan, or null when it lacks `urls` or `context`.
     *
     * @param array<array-key, mixed> $plan
     * @return Refreshes|null
     */
    private function read(array $plan): ?array
    {
        $urls = $plan['urls'] ?? null;
        $context = $plan['context'] ?? null;

        if (!is_array($urls) || !is_array($context)) {
            return null;
        }

        $distinct = [];

        foreach ($urls as $url) {
            if (is_string($url) && $url !== '') {
                $distinct[$url] = true;
            }
        }

        $pages = [];

        foreach (is_array($context['post_ids'] ?? null) ? $context['post_ids'] : [] as $id) {
            $page = is_int($id) ? ($this->page)($id) : null;

            if ($page !== null) {
                $pages[$page] = true;
            }
        }

        $templates = [];

        foreach (is_array($context['templates'] ?? null) ? $context['templates'] : [] as $template) {
            if (is_string($template) && $template !== '') {
                $templates[$template] = true;
            }
        }

        $pages = array_map('strval', array_keys($pages));
        sort($pages, SORT_STRING);
        $templates = array_map('strval', array_keys($templates));
        sort($templates, SORT_STRING);

        return [
            'all_pages' => ($context['purge_all'] ?? false) === true,
            'count'     => count($distinct),
            'pages'     => array_slice($pages, 0, LayoutScanner::USED_IN_CAP),
            'templates' => $templates,
        ];
    }
}
