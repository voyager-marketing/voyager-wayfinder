<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Drift;

use InvalidArgumentException;
use UnexpectedValueException;
use Voyager\Wayfinder\Manifest\Differ;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\SnapshotStore;
use Voyager\Wayfinder\Notes\NoteService;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Scanners\ScannerRegistry;

/**
 * Scan, store, re-check notes, remember the fingerprint and the time. The one
 * path every rescan takes, whether WP-CLI, a drift check or the Wayfinder
 * page asked for it.
 */
final class Refresher
{
    /** When the last refresh ran, UTC `Y-m-d\TH:i:s\Z`. Written even when nothing changed. */
    public const LAST_SCAN_OPTION = 'vwf_last_scan';

    private SnapshotStore $snapshots;

    private NoteService $notes;

    private Fingerprint $fingerprint;

    public function __construct(?SnapshotStore $snapshots = null, ?NoteService $notes = null, ?Fingerprint $fingerprint = null)
    {
        $this->snapshots = $snapshots ?? new SnapshotStore();
        $this->notes = $notes ?? new NoteService(null, $this->snapshots);
        $this->fingerprint = $fingerprint ?? new Fingerprint();
    }

    /**
     * Scans the site now. Stores nothing.
     *
     * @throws InvalidArgumentException When `$only` names no section or a scanner builds a bad item.
     * @throws UnexpectedValueException When a scanner returns the wrong section.
     */
    public static function scan(?string $only = null): Manifest
    {
        return new Manifest(ScannerRegistry::fromFilters()->scan($only), [
            'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'plugin_version' => Plugin::VERSION,
            'site_url'       => home_url(),
        ]);
    }

    /**
     * Whether this refresh runs under a different plugin version than the one
     * that stored the snapshot. A snapshot stored before versions were
     * recorded counts as an upgrade. With no snapshot there is nothing to
     * compare and no note to keep.
     *
     * Only a version bump is seen. A build that changes what the scanners
     * emit without bumping Plugin::VERSION still flags notes.
     *
     * @param array{plugin_version: string|null}|null $stored
     */
    public static function isUpgrade(?array $stored, string $version): bool
    {
        return $stored !== null && $stored['plugin_version'] !== $version;
    }

    /**
     * @param Manifest|null $manifest A scan made in this request, so a caller that already scanned does not scan twice.
     *
     * @return array{manifest: Manifest, change: array{previous: string|null, hash: string, added: list<string>, removed: list<string>, changed: list<string>}, notes: array<int, string>}
     *
     * @throws InvalidArgumentException|UnexpectedValueException When the scan fails. Nothing is stored then.
     */
    public function refresh(?Manifest $manifest = null): array
    {
        $manifest ??= self::scan();
        $stored = $this->snapshots->snapshot();
        $storedHashes = $this->snapshots->itemHashes();
        $newHashes = Differ::itemHashes($manifest->body());

        $change = $this->snapshots->store($manifest);

        // A new release may add facts, which moves item hashes on a site that did not change.
        if (self::isUpgrade($stored, $manifest->header['plugin_version'] ?? Plugin::VERSION)) {
            $this->notes->repin($storedHashes, $newHashes);
        }

        $notes = $this->notes->reconcile($newHashes);

        update_option(Fingerprint::OPTION, $this->fingerprint->hash(), false);
        update_option(self::LAST_SCAN_OPTION, $manifest->header['generated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'), false);

        return ['manifest' => $manifest, 'change' => $change, 'notes' => $notes];
    }
}
