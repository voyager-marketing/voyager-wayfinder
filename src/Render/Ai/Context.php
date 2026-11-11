<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Ai;

use Closure;
use InvalidArgumentException;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Scanners\ScannerRegistry;

/**
 * The one path every AI surface goes through: REST, WP-CLI, and later the
 * abilities. It scans once per instance, reads the notes once, and renders
 * whichever tier is asked for.
 *
 * @phpstan-import-type NotesByItem from AgencyNotes
 */
final class Context
{
    public const TIERS = [BriefRenderer::TIER, IndexRenderer::TIER, SectionRenderer::TIER];

    /** @var Closure(): Manifest */
    private Closure $scan;

    /** @var Closure(): NotesByItem */
    private Closure $readNotes;

    private ?Manifest $manifest = null;

    /** @var NotesByItem|null */
    private ?array $notes = null;

    /**
     * @param (callable(): Manifest)|null    $scan  Defaults to every registered scanner.
     * @param (callable(): NotesByItem)|null $notes Defaults to the notes table, when it exists.
     */
    public function __construct(?callable $scan = null, ?callable $notes = null)
    {
        $this->scan = $scan !== null ? Closure::fromCallable($scan) : self::scanSite(...);
        $this->readNotes = $notes !== null ? Closure::fromCallable($notes) : AgencyNotes::fromDatabase(...);
    }

    public function manifest(): Manifest
    {
        return $this->manifest ??= ($this->scan)();
    }

    /**
     * @return NotesByItem
     */
    public function notes(): array
    {
        return $this->notes ??= ($this->readNotes)();
    }

    /**
     * @param string $id Section name or item id. Section tier only.
     * @return string|null Null when the section tier names no known section or item.
     *
     * @throws InvalidArgumentException On an unknown tier, or the section tier with no id.
     */
    public function render(string $tier, string $id = ''): ?string
    {
        return match ($tier) {
            BriefRenderer::TIER   => (new BriefRenderer())->render($this->manifest(), $this->notes()),
            IndexRenderer::TIER   => (new IndexRenderer())->render($this->manifest(), $this->notes()),
            SectionRenderer::TIER => $id === ''
                ? throw new InvalidArgumentException('The section tier needs an id: a section name or an item id.')
                : (new SectionRenderer())->render($this->manifest(), $id, $this->notes()),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown tier "%s". Use one of: %s.',
                esc_html($tier),
                esc_html(implode(', ', self::TIERS))
            )),
        };
    }

    /**
     * The manifest hash. Notes are rendered into the tiers but are not part
     * of the manifest, so when any exist a short digest of them is appended:
     * a new note must not be answered with 304.
     */
    public function etag(): string
    {
        $notes = $this->notes();

        return $notes === []
            ? $this->manifest()->hash
            : $this->manifest()->hash . '-' . substr(CanonicalJson::hash($notes), 0, 12);
    }

    /**
     * Size of the brief and the index. `est_tokens` is characters / 4, an estimate.
     *
     * @return list<array{tier: string, chars: int, est_tokens: int}>
     */
    public function stats(): array
    {
        $rows = [];

        foreach ([BriefRenderer::TIER, IndexRenderer::TIER] as $tier) {
            $text = (string) $this->render($tier);
            $rows[] = [
                'tier'       => $tier,
                'chars'      => Compact::length($text),
                'est_tokens' => Compact::estimateTokens($text),
            ];
        }

        return $rows;
    }

    private static function scanSite(): Manifest
    {
        return new Manifest(ScannerRegistry::fromFilters()->scan(), [
            'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'plugin_version' => Plugin::VERSION,
            'site_url'       => home_url(),
        ]);
    }
}
