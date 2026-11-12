<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Cli;

use InvalidArgumentException;
use UnexpectedValueException;
use Voyager\Wayfinder\Blocks\BlockFingerprint;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Drift\Refresher;
use Voyager\Wayfinder\Edit\Anchors;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Edit\FindPayload;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Differ;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\SnapshotStore;
use Voyager\Wayfinder\Scanners\WordPressLayoutSource;
use WP_CLI;

/**
 * Scans the site and prints what Wayfinder knows about how it is built.
 */
final class Command
{
    /**
     * Scans the site and prints the manifest.
     *
     * ## OPTIONS
     *
     * [--section=<name>]
     * : Scan only this section.
     *
     * [--format=<format>]
     * : Output format. `ids` prints one item id per line, sorted.
     * ---
     * default: json
     * options:
     *   - json
     *   - ids
     * ---
     *
     * [--refresh]
     * : Store the scan as the new snapshot and update note statuses. Prints
     * what changed instead of the manifest. Cannot be combined with --section.
     *
     * ## EXAMPLES
     *
     *     wp wayfinder scan
     *     wp wayfinder scan --section=site --format=ids
     *     wp wayfinder scan --refresh
     *
     * @param list<string>               $args      Unused.
     * @param array<string, string|bool> $assocArgs
     */
    public function scan(array $args, array $assocArgs): void
    {
        $only = isset($assocArgs['section']) ? (string) $assocArgs['section'] : null;
        $format = $assocArgs['format'] ?? 'json';

        if (!empty($assocArgs['refresh'])) {
            if ($only !== null) {
                WP_CLI::error('--refresh stores the whole manifest, so it cannot be combined with --section.');
            }

            $this->refresh();

            return;
        }

        $manifest = self::scanOrFail($only);

        if ($format === 'ids') {
            foreach ($manifest->ids() as $id) {
                WP_CLI::line($id);
            }

            return;
        }

        WP_CLI::line($manifest->toJson(true));
    }

    /**
     * Says where something on a page is edited.
     *
     * ## OPTIONS
     *
     * <url>
     * : Page path or URL on this site, such as / or /services/drain-cleaning/.
     *
     * [--text=<text>]
     * : Text you can see on that page.
     *
     * [--class=<class>]
     * : A CSS class a block carries (a leading dot is fine), or a block's name
     * from the editor's List View. With --text, only blocks matching both.
     *
     * [--detail=<detail>]
     * : compact (three hits, three chain entries) or full (everything).
     * The compact answer counts what it left out and drops the page's class
     * and lock lists.
     * ---
     * default: compact
     * options:
     *   - compact
     *   - full
     * ---
     *
     * [--format=<format>]
     * : Output format. `ids` prints the chain's ids, most specific first, then
     * one line per hit: rank, id, block path and snippet.
     * ---
     * default: json
     * options:
     *   - json
     *   - ids
     * ---
     *
     * ## EXAMPLES
     *
     *     wp wayfinder find / --text="555-0142"
     *     wp wayfinder find /services/drain-cleaning/ --format=ids
     *     wp wayfinder find /about/ --class=hide-mobile
     *     wp wayfinder find / --text="Services" --detail=full
     *
     * Each hit on a block carries an `anchor` and the result a `verify`
     * recipe; see `wp wayfinder anchor`.
     *
     * @param list<string>          $args      The URL.
     * @param array<string, string> $assocArgs
     */
    public function find(array $args, array $assocArgs): void
    {
        $result = (new EditLocator())->find($args[0] ?? '/', $assocArgs['text'] ?? null, $assocArgs['class'] ?? null);

        if ($result['route'] === null) {
            WP_CLI::error((string) $result['message']);
        }

        $payload = FindPayload::shape($result, FindPayload::detail($assocArgs['detail'] ?? null) ?? FindPayload::COMPACT);

        if ($payload['matched'] === false) {
            WP_CLI::warning((string) $payload['message']);
        }

        if (($assocArgs['format'] ?? 'json') === 'ids') {
            foreach ($payload['chain'] as $entry) {
                WP_CLI::line((string) $entry['id']);
            }

            foreach ($payload['hits'] as $hit) {
                WP_CLI::line(sprintf('hit %d %s %s %s %s', $hit['rank'], $hit['id'], $hit['block_path'] ?? '-', $hit['anchor']['fp'] ?? '-', $hit['snippet']));
            }

            if ($payload['ambiguous']) {
                WP_CLI::line('ambiguous: the top two hits are equally good answers');
            }

            if (isset($payload['coverage'], $payload['reason'])) {
                WP_CLI::line(sprintf('coverage: %s (%s)', $payload['coverage'], $payload['reason']));
            }

            if (isset($payload['hits_more']) || isset($payload['chain_more'])) {
                WP_CLI::line(sprintf('more: %d hits, %d chain entries; --detail=full lists them', $payload['hits_more'] ?? 0, $payload['chain_more'] ?? 0));
            }

            return;
        }

        WP_CLI::line((string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Prints a block's anchor now, or finds where a fingerprint moved to.
     *
     * With a path: the block's current fingerprint and its item's hash, as
     * find reports them in `anchor`. With --fp: every path in the entity
     * whose block carries that fingerprint, so a writer can re-anchor after
     * a sibling was inserted. Exits 1 when no block matches. Writes nothing.
     *
     * ## OPTIONS
     *
     * <entity>
     * : The item that holds the block (`part:footer`, `tpl:page`, `pat:cta`,
     * `nav:main`), or `pt:<post_type>` for a post's content.
     *
     * [<path>]
     * : The block's parse-order path, such as 0.2.1.
     *
     * [--fp[=<fp>]]
     * : A fingerprint to look for instead of a path. `--fp <fp> <entity>` works too.
     *
     * [--post=<id>]
     * : The post, for a `pt:` entity. Needed with a path; with --fp and no
     * post, every published post of that type is searched.
     *
     * ## EXAMPLES
     *
     *     wp wayfinder anchor part:footer 0.1
     *     wp wayfinder anchor pt:page 2 --post=42
     *     wp wayfinder anchor --fp=3f9c0a1b2c3d4e5f pt:page
     *
     * @param list<string>               $args      The entity, then a path.
     * @param array<string, string|bool> $assocArgs
     */
    public function anchor(array $args, array $assocArgs): void
    {
        $fp = $assocArgs['fp'] ?? null;

        // `--fp <fp> <entity>`: WP-CLI reads the bare flag as true and the value as the first positional.
        if ($fp === true) {
            $fp = (string) array_shift($args);
        }

        $entity = (string) ($args[0] ?? '');
        $post = isset($assocArgs['post']) && is_numeric($assocArgs['post']) ? (int) $assocArgs['post'] : null;
        $anchors = new Anchors(new WordPressLayoutSource(), Locks::forSite());

        if ($entity === '') {
            WP_CLI::error('Name the entity that holds the block, such as part:footer or pt:page.');
        }

        if (is_string($fp)) {
            if (!BlockFingerprint::isValid($fp)) {
                WP_CLI::error(sprintf('A fingerprint is %d lowercase hex characters.', BlockFingerprint::LENGTH));
            }

            $located = $anchors->locate($entity, $fp, $post);

            if ($located === []) {
                WP_CLI::error(sprintf('No block in %s carries fingerprint %s.', $entity, $fp));
            }

            WP_CLI::line((string) wp_json_encode($located, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $path = (string) ($args[1] ?? '');

        if ($path === '') {
            WP_CLI::error('Give a block path, such as 0.2.1, or --fp=<fingerprint>.');
        }

        if (Anchors::isContent($entity) && $post === null) {
            WP_CLI::error('A pt: entity needs --post=<id>: the path is inside one post.');
        }

        $anchor = $anchors->anchor($entity, $path, $post);

        if ($anchor === null) {
            WP_CLI::error(sprintf('No block at %s in %s.', $path, $entity));
        }

        WP_CLI::line((string) wp_json_encode($anchor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Compares the site now with the stored manifest. Stores nothing.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : `text` prints one `added|removed|changed <id>` line per item.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp wayfinder diff
     *     wp wayfinder diff --format=json
     *
     * @param list<string>          $args      Unused.
     * @param array<string, string> $assocArgs
     */
    public function diff(array $args, array $assocArgs): void
    {
        $store = new SnapshotStore();
        $snapshot = $store->snapshot();

        if ($snapshot === null) {
            WP_CLI::error('No manifest is stored yet. Run `wp wayfinder scan --refresh` first.');
        }

        $manifest = self::scanOrFail(null);
        $diff = Differ::diff($store->itemHashes(), Differ::itemHashes($manifest->body()));

        if (($assocArgs['format'] ?? 'text') === 'json') {
            WP_CLI::line(CanonicalJson::encode(['from' => $snapshot['hash'], 'to' => $manifest->hash] + $diff, true));

            return;
        }

        if ($diff['added'] === [] && $diff['removed'] === [] && $diff['changed'] === []) {
            WP_CLI::success(sprintf('No changes since the stored manifest %s.', self::short($snapshot['hash'])));

            return;
        }

        foreach ($diff as $kind => $ids) {
            foreach ($ids as $id) {
                WP_CLI::line($kind . ' ' . $id);
            }
        }
    }

    private function refresh(): void
    {
        try {
            $result = (new Refresher())->refresh();
        } catch (InvalidArgumentException | UnexpectedValueException $e) {
            WP_CLI::error($e->getMessage());
        }

        $change = $result['change'];
        $stats = self::short($result['manifest']->statsHash);

        foreach ($result['notes'] as $id => $status) {
            WP_CLI::log(sprintf('Note %d is now %s.', $id, $status));
        }

        if ($change['previous'] === $change['hash']) {
            WP_CLI::success(sprintf('Manifest %s is unchanged. Stats %s.', self::short($change['hash']), $stats));

            return;
        }

        WP_CLI::success(sprintf(
            'Stored manifest %s (was %s): %d added, %d removed, %d changed. Stats %s.',
            self::short($change['hash']),
            $change['previous'] === null ? 'none' : self::short($change['previous']),
            count($change['added']),
            count($change['removed']),
            count($change['changed']),
            $stats
        ));
    }

    private static function scanOrFail(?string $only): Manifest
    {
        try {
            return Refresher::scan($only);
        } catch (InvalidArgumentException | UnexpectedValueException $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    private static function short(string $hash): string
    {
        return substr($hash, 0, 12);
    }
}
