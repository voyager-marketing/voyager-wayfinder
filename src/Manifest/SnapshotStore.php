<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Manifest;

use Closure;
use InvalidArgumentException;
use JsonException;

/**
 * The last stored manifest and the hash chain that led to it.
 *
 * `vwf_manifest_snapshot` holds the body as canonical JSON next to its hash,
 * so sha256 of the stored body reproduces the stored hash. `vwf_manifest_history`
 * holds the last five changes, oldest first, each with the item ids it added,
 * removed and changed. Both are stored with autoload off: they are read when
 * the manual is, not on every request.
 */
final class SnapshotStore
{
    public const SNAPSHOT_OPTION = 'vwf_manifest_snapshot';

    public const HISTORY_OPTION = 'vwf_manifest_history';

    public const HISTORY_LIMIT = 5;

    /** @var Closure(string): mixed */
    private Closure $getOption;

    /** @var Closure(string, mixed): void */
    private Closure $updateOption;

    /**
     * @param (callable(string): mixed)|null       $getOption    Defaults to get_option(), null when missing.
     * @param (callable(string, mixed): void)|null $updateOption Defaults to update_option() with autoload off.
     */
    public function __construct(?callable $getOption = null, ?callable $updateOption = null)
    {
        $this->getOption = $getOption !== null
            ? Closure::fromCallable($getOption)
            : static fn (string $name): mixed => get_option($name, null);

        $this->updateOption = $updateOption !== null
            ? Closure::fromCallable($updateOption)
            : static function (string $name, mixed $value): void {
                update_option($name, $value, false);
            };
    }

    /**
     * The stored manifest hash and body, and the plugin version that scanned
     * it (null for a snapshot stored before versions were recorded). Null
     * before the first store or when the option does not hold a readable
     * snapshot.
     *
     * @return array{hash: string, body: array<mixed>, plugin_version: string|null}|null
     */
    public function snapshot(): ?array
    {
        $stored = ($this->getOption)(self::SNAPSHOT_OPTION);

        if (!is_array($stored) || !is_string($stored['hash'] ?? null) || !is_string($stored['body'] ?? null)) {
            return null;
        }

        try {
            $body = json_decode($stored['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $version = $stored['plugin_version'] ?? null;

        return is_array($body)
            ? ['hash' => $stored['hash'], 'body' => $body, 'plugin_version' => is_string($version) ? $version : null]
            : null;
    }

    /**
     * Item hash by id in the stored manifest, empty before the first store.
     *
     * @return array<string, string>
     */
    public function itemHashes(): array
    {
        $snapshot = $this->snapshot();

        if ($snapshot === null) {
            return [];
        }

        try {
            return Differ::itemHashes($snapshot['body']);
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Stores the manifest when its hash differs from the stored one, and adds
     * the change to the history. Storing an unchanged manifest writes nothing,
     * unless a different plugin version scanned it: then the snapshot is
     * stored again to record the version, and the history is left alone.
     *
     * @return array{previous: string|null, hash: string, added: list<string>, removed: list<string>, changed: list<string>}
     */
    public function store(Manifest $manifest): array
    {
        $previous = $this->snapshot();
        $version = $manifest->header['plugin_version'] ?? null;
        $change = [
            'previous' => $previous['hash'] ?? null,
            'hash'     => $manifest->hash,
        ] + Differ::diff($this->itemHashes(), Differ::itemHashes($manifest->body()));
        $record = [
            'hash'           => $manifest->hash,
            'body'           => CanonicalJson::encode($manifest->body()),
            'plugin_version' => $version,
        ];

        if ($previous !== null && $previous['hash'] === $manifest->hash) {
            if ($previous['plugin_version'] !== $version) {
                ($this->updateOption)(self::SNAPSHOT_OPTION, $record);
            }

            return $change;
        }

        ($this->updateOption)(self::SNAPSHOT_OPTION, $record);

        $history = $this->history();
        $history[] = $change + ['generated_at' => $manifest->header['generated_at'] ?? ''];

        ($this->updateOption)(self::HISTORY_OPTION, array_slice($history, -self::HISTORY_LIMIT));

        return $change;
    }

    /**
     * The last stored changes, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        $history = ($this->getOption)(self::HISTORY_OPTION);

        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }
}
