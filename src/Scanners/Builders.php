<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * Page builders (Elementor, Beaver Builder, Divi, Bricks, Breakdance) from
 * data/builders.json: which plugin or theme turns each one on, the post meta
 * that marks a post as one of its documents, its edit URL and the post types
 * it keeps its templates in.
 *
 * A builder document is opaque to Wayfinder. Its text lives in the builder's
 * own meta (`_elementor_data` and the like), which is never parsed; the map
 * only says which builder owns the post, where it is edited, and that what
 * Wayfinder reads of it is partial. Builders are detected by plugin and theme
 * slugs and meta keys alone, never by loading their code.
 *
 * Everything here is plain data in, plain data out, so the unit suite runs
 * it with injected plugin lists and post meta. WordPressBuilders reads the
 * site.
 *
 * @phpstan-type Builder array{slug: string, label: string, plugins: list<string>, themes: list<string>, marker_key: string, marker_value: string|null, edit: string, templates: list<string>, type_meta: string|null}
 * @phpstan-type BuilderDoc array{builder: string, label: string, edit: string}
 */
final class Builders
{
    /** The coverage word for an item or a find result whose content Wayfinder cannot read. */
    public const PARTIAL = 'partial';

    /** Why coverage is partial: the post is a page builder's document. */
    public const REASON = 'builder_document';

    private static ?self $bundled = null;

    /** @var array<string, Builder> By slug, sorted. */
    private array $builders;

    /**
     * @param array<string, Builder> $builders
     */
    private function __construct(array $builders)
    {
        ksort($builders, SORT_STRING);
        $this->builders = $builders;
    }

    /**
     * The list that ships with the plugin, read once per request.
     *
     * @throws UnexpectedValueException When data/builders.json is missing or not JSON.
     * @throws InvalidArgumentException When it is JSON of the wrong shape.
     */
    public static function bundled(): self
    {
        if (self::$bundled !== null) {
            return self::$bundled;
        }

        $path = dirname(__DIR__, 2) . '/data/builders.json';
        $json = is_readable($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new UnexpectedValueException('Cannot read data/builders.json.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('data/builders.json is not valid JSON: ' . esc_html($e->getMessage()));
        }

        return self::$bundled = self::fromData($data);
    }

    /**
     * Builds the list from the decoded file: `{"builders": {"<slug>": {...}}}`.
     *
     * @throws InvalidArgumentException On a missing label, meta key or edit URL, or a bad slug.
     */
    public static function fromData(mixed $data): self
    {
        if (!is_array($data) || !isset($data['builders']) || !is_array($data['builders'])) {
            throw new InvalidArgumentException('Builders need a "builders" object of slug => builder.');
        }

        $builders = [];

        foreach ($data['builders'] as $slug => $row) {
            $slug = (string) $slug;

            if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || !is_array($row)) {
                throw new InvalidArgumentException(sprintf('Builder "%s" needs a lowercase slug and an object.', esc_html($slug)));
            }

            $meta = $row['meta'] ?? null;

            if (
                !is_string($row['label'] ?? null) || $row['label'] === ''
                || !is_array($meta) || !is_string($meta['key'] ?? null) || $meta['key'] === ''
                || !is_string($row['edit'] ?? null) || $row['edit'] === ''
            ) {
                throw new InvalidArgumentException(sprintf('Builder "%s" needs a label, a meta key and an edit URL.', esc_html($slug)));
            }

            $builders[$slug] = [
                'slug'         => $slug,
                'label'        => $row['label'],
                'plugins'      => self::strings($row['plugins'] ?? [], $slug, 'plugins'),
                'themes'       => self::strings($row['themes'] ?? [], $slug, 'themes'),
                'marker_key'   => $meta['key'],
                'marker_value' => is_string($meta['value'] ?? null) ? $meta['value'] : null,
                'edit'         => $row['edit'],
                'templates'    => self::strings($row['templates'] ?? [], $slug, 'templates'),
                'type_meta'    => is_string($row['type_meta'] ?? null) && $row['type_meta'] !== '' ? $row['type_meta'] : null,
            ];
        }

        return new self($builders);
    }

    /**
     * The builders this site runs: one of their plugins is active, or the
     * active theme or its parent is one of their themes.
     *
     * @param list<string> $plugins Active plugin slugs (IntegrationsScanner::slug()).
     * @param list<string> $themes  The active theme's stylesheet and template.
     */
    public function active(array $plugins, array $themes): self
    {
        $plugins = array_fill_keys($plugins, true);
        $themes = array_fill_keys($themes, true);

        return new self(array_filter(
            $this->builders,
            static fn (array $builder): bool => array_intersect_key(array_fill_keys($builder['plugins'], true), $plugins) !== []
                || array_intersect_key(array_fill_keys($builder['themes'], true), $themes) !== []
        ));
    }

    public function isEmpty(): bool
    {
        return $this->builders === [];
    }

    /**
     * @return array<string, Builder> By slug, sorted.
     */
    public function all(): array
    {
        return $this->builders;
    }

    /**
     * The builder's label, or the slug when the list does not name it.
     */
    public function label(string $slug): string
    {
        return $this->builders[$slug]['label'] ?? $slug;
    }

    /**
     * The builder that owns a post, from its meta: the first builder, by
     * slug, whose marker the post carries. Null for a post no builder owns.
     *
     * @param callable(string): mixed $meta The post's single meta value for a key, null when it has none.
     */
    public function ownerOf(callable $meta): ?string
    {
        foreach ($this->builders as $slug => $builder) {
            if (self::marks($builder, $meta($builder['marker_key']))) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Whether a meta value marks a post as the builder's document.
     *
     * @param Builder $builder
     */
    public static function marks(array $builder, mixed $value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        $value = (string) $value;

        return $builder['marker_value'] !== null ? $value === $builder['marker_value'] : $value !== '' && $value !== '0';
    }

    /**
     * A post's builder document facts, or null when no active builder owns it.
     *
     * @param callable(string): mixed $meta The post's single meta value for a key, null when it has none.
     * @param string                  $path The post's permalink relative to the site root, query string kept.
     * @param string                  $home The home URL's path, `/` or `/sub/`.
     * @return BuilderDoc|null
     */
    public function document(callable $meta, int $id, string $path, string $home = '/'): ?array
    {
        $slug = $this->ownerOf($meta);

        return $slug !== null ? $this->doc($slug, $id, $path, $home) : null;
    }

    /**
     * @return BuilderDoc
     */
    public function doc(string $slug, int $id, string $path, string $home = '/'): array
    {
        return ['builder' => $slug, 'label' => $this->label($slug), 'edit' => $this->editUrl($slug, $id, $path, $home)];
    }

    /**
     * The builder's edit URL for one post: relative to wp-admin
     * (`post.php?post=12&action=elementor`), or to the site root when the
     * builder edits on the front end (`/about/?bricks=run`). A path that
     * already has a query string gets `&` instead of a second `?`.
     */
    public function editUrl(string $slug, int $id, string $path, string $home = '/'): string
    {
        $url = strtr($this->builders[$slug]['edit'] ?? '', ['{id}' => (string) $id, '{path}' => $path, '{home}' => $home]);
        $first = strpos($url, '?');

        if ($first !== false) {
            $url = substr($url, 0, $first + 1) . str_replace('?', '&', substr($url, $first + 1));
        }

        return $url;
    }

    /**
     * Whether an edit path is relative to the site root (a builder's
     * front-end editor, `/about/?bricks=run`) rather than to wp-admin. A
     * leading slash before a wp-admin script (`/edit.php`) still means
     * wp-admin, as it always has.
     */
    public static function isSitePath(string $edit): bool
    {
        return str_starts_with($edit, '/') && preg_match('#^/[^/?]+\.php(\?|$)#', $edit) !== 1;
    }

    /**
     * Post type => builder slug, for the post types active builders keep
     * their templates in, sorted by post type.
     *
     * @return array<string, string>
     */
    public function templateTypes(): array
    {
        $types = [];

        foreach ($this->builders as $slug => $builder) {
            foreach ($builder['templates'] as $type) {
                $types[$type] ??= $slug;
            }
        }

        ksort($types, SORT_STRING);

        return $types;
    }

    /**
     * The meta key that names a builder template's type (header, footer, ...), if the builder has one.
     */
    public function typeMeta(string $slug): ?string
    {
        return $this->builders[$slug]['type_meta'] ?? null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values, string $slug, string $key): array
    {
        if (!is_array($values)) {
            throw new InvalidArgumentException(sprintf('Builder "%s" must list %s.', esc_html($slug), esc_html($key)));
        }

        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[^\s|,]+$/', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('Builder "%s" lists an invalid entry in %s.', esc_html($slug), esc_html($key)));
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
