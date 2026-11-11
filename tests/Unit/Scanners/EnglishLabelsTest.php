<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Voyager\Wayfinder\Scanners\EnglishLabels;

final class EnglishLabelsTest extends TestCase
{
    /** Core's catalog on a de_DE site, for the strings these tests use. */
    private const GERMAN = [
        'Media'             => 'Medien',
        'Pages'             => 'Seiten',
        'Posts'             => 'Beiträge',
        'Categories'        => 'Kategorien',
        'Formats'           => 'Formate',
        'Tags'              => 'Schlagwörter',
        'Pattern Overrides' => 'Vorlagen-Überschreibungen',
        'Post Data'         => 'Beitragsdaten',
        'Post Meta'         => 'Beitrags-Metadaten',
        'Term Data'         => 'Begriffsdaten',
    ];

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_post_types'], $GLOBALS['wp_taxonomies']);
    }

    public function testATemplateTitleIsReadInEnglishAndTheLocaleSwitchedBack(): void
    {
        $locale = 'de_DE';
        $restores = 0;

        $title = EnglishLabels::whileSwitched(
            static function () use (&$locale): bool {
                $locale = 'en_US';

                return true;
            },
            static function () use (&$locale, &$restores): void {
                $locale = 'de_DE';
                $restores++;
            },
            // What core's default `page` template title reads in each language.
            static function () use (&$locale): string {
                return $locale === 'en_US' ? 'Pages' : 'Seiten';
            }
        );

        $this->assertSame('Pages', $title);
        $this->assertSame('de_DE', $locale);
        $this->assertSame(1, $restores);
    }

    public function testASiteAlreadyInEnglishIsNotSwitchedBack(): void
    {
        $restores = 0;

        $title = EnglishLabels::whileSwitched(
            // switch_to_locale('en_US') returns false on an en_US site.
            static fn (): bool => false,
            static function () use (&$restores): void {
                $restores++;
            },
            static fn (): string => 'Pages'
        );

        $this->assertSame('Pages', $title);
        $this->assertSame(0, $restores, 'Restoring after a switch that did not happen pops someone else\'s switch.');
    }

    public function testTheLocaleIsSwitchedBackWhenTheReadThrows(): void
    {
        $restores = 0;

        try {
            EnglishLabels::whileSwitched(
                static fn (): bool => true,
                static function () use (&$restores): void {
                    $restores++;
                },
                static function (): never {
                    throw new RuntimeException('read failed');
                }
            );
            $this->fail('The read\'s exception should reach the caller.');
        } catch (RuntimeException $e) {
            $this->assertSame('read failed', $e->getMessage());
        }

        $this->assertSame(1, $restores);
    }

    public function testCorePostTypesAndTaxonomiesAreLeftAsTheSwitchFoundThem(): void
    {
        $post = new stdClass();
        $category = new stdClass();
        // A custom post type attached to a core taxonomy after init.
        $category->object_type = ['post', 'service'];

        $GLOBALS['wp_post_types'] = ['post' => $post];
        $GLOBALS['wp_taxonomies'] = ['category' => $category];

        $sawNew = EnglishLabels::whileSwitched(
            // Core registers its own types again on `change_locale`.
            static function (): bool {
                $GLOBALS['wp_post_types'] = ['post' => new stdClass()];
                $GLOBALS['wp_taxonomies'] = ['category' => (object) ['object_type' => ['post']]];

                return true;
            },
            static function (): void {
                $GLOBALS['wp_post_types'] = ['post' => new stdClass()];
                $GLOBALS['wp_taxonomies'] = ['category' => (object) ['object_type' => ['post']]];
            },
            static fn (): bool => $GLOBALS['wp_post_types']['post'] !== $post
        );

        $this->assertTrue($sawNew, 'The read runs after the switch.');
        $this->assertSame($post, $GLOBALS['wp_post_types']['post']);
        $this->assertSame($category, $GLOBALS['wp_taxonomies']['category']);
        $this->assertSame(['post', 'service'], $GLOBALS['wp_taxonomies']['category']->object_type);
    }

    public function testCoreBindingSourceLabelsAreEnglish(): void
    {
        $labels = self::german();

        $this->assertSame('Post Meta', $labels->bindingSource('core/post-meta', 'Beitrags-Metadaten'));
        $this->assertSame('Pattern Overrides', $labels->bindingSource('core/pattern-overrides', 'Vorlagen-Überschreibungen'));
        $this->assertSame('Post Data', $labels->bindingSource('core/post-data', 'Beitragsdaten'));
        $this->assertSame('Term Data', $labels->bindingSource('core/term-data', 'Begriffsdaten'));
    }

    public function testBuiltInPostTypeAndTaxonomyLabelsAreEnglish(): void
    {
        $labels = self::german();

        $this->assertSame('Posts', $labels->postType('post', 'Beiträge'));
        $this->assertSame('Pages', $labels->postType('page', 'Seiten'));
        $this->assertSame('Media', $labels->postType('attachment', 'Medien'));
        $this->assertSame('Categories', $labels->taxonomy('category', 'Kategorien'));
        $this->assertSame('Tags', $labels->taxonomy('post_tag', 'Schlagwörter'));
        $this->assertSame('Formats', $labels->taxonomy('post_format', 'Formate'));
    }

    public function testLabelsRegisteredByThemesAndPluginsPassThroughUnchanged(): void
    {
        // Even a translator that knows the string changes nothing outside core's own.
        $labels = new EnglishLabels(static fn (string $text): string => $text === 'Services' ? 'Leistungen' : $text);

        $this->assertSame('Leistungen', $labels->postType('service', 'Leistungen'));
        $this->assertSame('Einsatzgebiete', $labels->taxonomy('service_area', 'Einsatzgebiete'));
        $this->assertSame('Firmendaten', $labels->bindingSource('vwf-fixture/site-data', 'Firmendaten'));
    }

    public function testACoreLabelTheSiteChangedIsKept(): void
    {
        $labels = self::german();

        // A plugin that renames Posts to News, on a German site.
        $this->assertSame('News', $labels->postType('post', 'News'));
        $this->assertSame('Themen', $labels->taxonomy('category', 'Themen'));
    }

    public function testAnEnglishSiteKeepsEveryLabel(): void
    {
        // On en_US, core's catalog gives every string back unchanged.
        $labels = new EnglishLabels(static fn (string $text): string => $text);

        foreach (EnglishLabels::POST_TYPES as $slug => [$english]) {
            $this->assertSame($english, $labels->postType($slug, $english));
            $this->assertSame('News', $labels->postType($slug, 'News'));
        }

        foreach (EnglishLabels::TAXONOMIES as $slug => [$english]) {
            $this->assertSame($english, $labels->taxonomy($slug, $english));
        }

        foreach (EnglishLabels::BINDING_SOURCES as $source => [$english]) {
            $this->assertSame($english, $labels->bindingSource($source, $english));
        }
    }

    private static function german(): EnglishLabels
    {
        return new EnglishLabels(static fn (string $text, string $context): string => self::GERMAN[$text] ?? $text);
    }
}
