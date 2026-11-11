<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support;

use RuntimeException;
use Voyager\Wayfinder\Scanners\LayoutSource;
use Voyager\Wayfinder\Scanners\OptionScreens;

/**
 * In-memory LayoutSource. fixture() fills it from the fixture theme's block
 * markup and from the rows fixtures/seed.php creates, so a test can run the
 * layout scanner on the fixture site with no database.
 *
 * @phpstan-import-type TemplateRow from LayoutSource
 * @phpstan-import-type PostRow from LayoutSource
 * @phpstan-import-type PatternRow from LayoutSource
 * @phpstan-import-type ContentRow from LayoutSource
 * @phpstan-import-type MenuLocationRow from LayoutSource
 * @phpstan-import-type SidebarRow from LayoutSource
 * @phpstan-import-type BuilderTemplateRow from LayoutSource
 */
final class FakeLayoutSource implements LayoutSource
{
    public const THEME = 'vwf-fixture';

    /** @var list<TemplateRow> */
    public array $templates = [];

    /** @var list<TemplateRow> */
    public array $parts = [];

    /** @var list<PatternRow> */
    public array $patterns = [];

    /** @var list<PostRow> */
    public array $navigations = [];

    /** @var list<ContentRow> */
    public array $content = [];

    /** Whether content() keeps only rows that reference something, as WordPressLayoutSource does. */
    public bool $contentReferencesOnly = false;

    /** @var array<string, array{title: string, content: string}> By slug. */
    public array $registered = [];

    /** @var array<string, string> Source name => label. */
    public array $sourceLabels = [];

    /** @var array<string, string> Source name => edit path. */
    public array $editPaths = [];

    /** @var array<string, string|null> Source name => file its callback is defined in. */
    public array $sourceFiles = [];

    /** @var array<string, array<string, list<string>|null>> Source name => key ('' for none) => reads. */
    public array $reads = [];

    /** @var list<array{source: string, key: string|null, args: array<array-key, mixed>, block: string, attribute: string}> Every call to bindingReads(), in order. */
    public array $readCalls = [];

    /** @var array<string, list<string>> Meta key => post types. */
    public array $meta = [];

    /** @var array<string, array<string, string|null>> Settings group => option name => label. */
    public array $settingsGroups = [];

    /** @var list<string> Every call to registeredPattern(), in order. */
    public array $registeredLookups = [];

    /** @var array<string, array{file: string|null, reads: list<string>|null}> Registered pattern slug => its theme file and reads. */
    public array $patternFiles = [];

    /** @var array<string, string|null> The Site Data fields of the plugin that owns them, option => label. */
    public array $siteData = [];

    /** Where that plugin saves them. Null with no owner. */
    public ?string $siteDataScreen = OptionScreens::BLOCKS_SCREEN;

    /** @var array<string, string|null> Option => its value as text. An option not here is unset (''). */
    public array $options = [];

    /** @var list<MenuLocationRow> Classic themes only. */
    public array $menuLocations = [];

    /** @var list<SidebarRow> Classic themes only. */
    public array $sidebars = [];

    /** @var list<BuilderTemplateRow> Templates of active page builders. */
    public array $builderTemplates = [];

    /**
     * The fixture site as fixtures/seed.php leaves it: the theme's two
     * templates and two parts, the two menus, the CTA banner and the pages
     * that use it, and the business-info bindings source.
     */
    public static function fixture(): self
    {
        $source = new self();

        $source->templates = [
            self::template('page-contact', 'templates/page-contact.html', 'Contact page'),
            self::template('single-service', 'templates/single-service.html', 'single-service'),
        ];

        $source->parts = [
            self::template('footer', 'parts/footer.html', 'Footer', 'footer'),
            self::template('header', 'parts/header.html', 'Header', 'header'),
        ];

        $source->patterns = [[
            'id'      => 90003,
            'slug'    => 'cta-banner',
            'title'   => 'CTA banner',
            'synced'  => true,
            'content' => "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Need a plumber today?</h2>\n<!-- /wp:heading -->\n\n"
                . "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link\" href=\"/contact/\">Book a visit</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons --></div>\n<!-- /wp:group -->",
        ]];

        $source->navigations = [
            self::navigation(90001, 'primary', 'Primary', ['Home' => '/', 'About' => '/about/', 'Services' => '/services/', 'Blog' => '/blog/', 'Contact' => '/contact/']),
            self::navigation(90002, 'footer', 'Footer', ['About' => '/about/', 'Contact' => '/contact/']),
        ];

        $cta = '<!-- wp:block {"ref":90003} /-->';
        $source->content = [
            ['type' => 'page', 'slug' => 'home', 'content' => self::paragraph('Riverbend Plumbing') . "\n\n" . $cta],
            ['type' => 'page', 'slug' => 'about', 'content' => self::paragraph('Family owned since 1998.') . "\n\n" . $cta],
            ['type' => 'page', 'slug' => 'contact', 'content' => self::paragraph('Call or write.')],
        ];

        $source->sourceLabels = ['vwf-fixture/business-info' => 'Business info', 'core/post-meta' => 'Post Meta'];
        $source->editPaths = ['vwf-fixture/business-info' => 'options-general.php?page=vwf-business-info'];
        $source->sourceFiles = ['vwf-fixture/business-info' => 'mu-plugins/vwf-fixture-core.php', 'core/post-meta' => 'core'];
        $source->reads = ['vwf-fixture/business-info' => [
            'address' => ['option:vwf_address'],
            'hours'   => ['option:vwf_hours'],
            'phone'   => ['option:vwf_phone'],
        ]];
        $source->meta = ['price_from' => ['service']];
        $source->settingsGroups = ['vwf-business-info' => ['vwf_address' => 'Address', 'vwf_hours' => 'Hours', 'vwf_phone' => 'Phone']];

        return $source;
    }

    /**
     * A template or part row over a file in fixtures/theme/vwf-fixture.
     *
     * @return TemplateRow
     */
    public static function template(string $slug, string $file, string $title, string $area = ''): array
    {
        return ['file' => 'themes/' . self::THEME . '/' . $file] + self::row($slug, self::themeFile($file), $title, $area);
    }

    /**
     * A template or part row over literal markup.
     *
     * @return TemplateRow
     */
    public static function row(string $slug, string $content, string $title = '', string $area = '', string $source = 'theme', bool $modified = false): array
    {
        return [
            'slug'          => $slug,
            'title'         => $title,
            'theme'         => self::THEME,
            'source'        => $source,
            'user_modified' => $modified,
            'area'          => $area,
            'content'       => $content,
        ];
    }

    /**
     * @param array<string, string> $links Label => URL.
     * @return PostRow
     */
    public static function navigation(int $id, string $slug, string $title, array $links): array
    {
        $blocks = [];

        foreach ($links as $label => $url) {
            $blocks[] = '<!-- wp:navigation-link ' . json_encode(['label' => $label, 'url' => $url], JSON_UNESCAPED_SLASHES) . ' /-->';
        }

        return ['id' => $id, 'slug' => $slug, 'title' => $title, 'content' => implode("\n", $blocks)];
    }

    public static function paragraph(string $text): string
    {
        return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
    }

    public static function themeFile(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/fixtures/theme/vwf-fixture/' . $file;
        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Missing fixture theme file ' . $file);
        }

        return $content;
    }

    public function templates(string $type): array
    {
        return $type === 'wp_template_part' ? $this->parts : $this->templates;
    }

    public function syncedPatterns(): array
    {
        return $this->patterns;
    }

    public function navigations(): array
    {
        return $this->navigations;
    }

    public function content(): array
    {
        if (!$this->contentReferencesOnly) {
            return $this->content;
        }

        return array_values(array_filter(
            $this->content,
            static fn (array $row): bool => preg_match('/<!-- wp:(block|pattern|template-part|navigation) |"bindings"/', $row['content']) === 1
        ));
    }

    public function contentMatching(array $needles): array
    {
        return array_values(array_filter(
            $this->content,
            static function (array $row) use ($needles): bool {
                foreach ($needles as $needle) {
                    if ($needle !== '' && stripos($row['content'], $needle) !== false) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    public function contentOfType(string $type, ?int $id = null): array
    {
        $rows = array_values(array_filter(
            $this->content,
            static fn (array $row): bool => $row['type'] === $type && ($id === null || ($row['id'] ?? null) === $id)
        ));

        return array_slice($rows, 0, self::MATCHING_CAP);
    }

    public function registeredPattern(string $slug): ?array
    {
        $this->registeredLookups[] = $slug;

        return $this->registered[$slug] ?? null;
    }

    public function patternFile(string $slug): ?array
    {
        return $this->patternFiles[$slug] ?? null;
    }

    public function siteDataFields(): array
    {
        return $this->siteData;
    }

    public function siteDataScreen(): ?string
    {
        return $this->siteDataScreen;
    }

    public function optionText(string $option): ?string
    {
        return array_key_exists($option, $this->options) ? $this->options[$option] : '';
    }

    public function bindingSourceLabel(string $source): ?string
    {
        return $this->sourceLabels[$source] ?? null;
    }

    public function bindingEditPath(string $source, ?string $key): ?string
    {
        return $this->editPaths[$source] ?? ($source === OptionScreens::SITE_DATA_SOURCE && $this->siteData !== [] ? $this->siteDataScreen : null);
    }

    public function bindingSourceFile(string $source): ?string
    {
        return $this->sourceFiles[$source] ?? null;
    }

    public function bindingReads(string $source, ?string $key, array $args, string $block, string $attribute): ?array
    {
        $this->readCalls[] = ['source' => $source, 'key' => $key, 'args' => $args, 'block' => $block, 'attribute' => $attribute];

        return $this->reads[$source][$key ?? ''] ?? null;
    }

    public function settingsBeside(string $option): array
    {
        foreach ($this->settingsGroups as $options) {
            if (array_key_exists($option, $options)) {
                unset($options[$option]);
                ksort($options, SORT_STRING);

                return $options;
            }
        }

        return [];
    }

    public function metaPostTypes(string $key): array
    {
        return $this->meta[$key] ?? [];
    }

    public function menuLocations(): array
    {
        return $this->menuLocations;
    }

    public function sidebars(): array
    {
        return $this->sidebars;
    }

    public function builderTemplates(): array
    {
        return $this->builderTemplates;
    }
}
