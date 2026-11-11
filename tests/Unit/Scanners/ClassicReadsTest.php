<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Scanners\ClassicTheme;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;

/**
 * What a classic theme's templates and parts read (ACF fields, options-page
 * fields, options) and the paths they hard code, found in the same token pass
 * as their includes, with no theme code run.
 */
final class ClassicReadsTest extends TestCase
{
    public function testReadsComeFromLiteralFieldAndOptionCallsOnly(): void
    {
        $php = <<<'PHP'
            <?php
            // get_field('commented', 'option');
            $text = "get_option('quoted')";
            $page->get_field('method', 'option');
            Fields::get_field('static');
            echo esc_html( get_field( 'company_phone', 'option' ) );
            the_field( 'company_address', 'options' );
            if ( have_rows( 'project_phases' ) ) {}
            echo get_field( 'price_from', $post_id );
            echo get_field( 'price_from' );
            echo get_option( 'blogname' );
            echo get_field( $key, 'option' );
            echo GET_FIELD( 'loud', 'option' );
            PHP;

        $this->assertSame(
            ['acf:price_from', 'acf:project_phases', 'acf_option:company_address', 'acf_option:company_phone', 'acf_option:loud', 'option:blogname'],
            ClassicTheme::reads($php)
        );
    }

    public function testLinksAreLiteralHomeUrlPathsOnly(): void
    {
        $php = <<<'PHP'
            <?php
            echo esc_url( home_url( '/contact/' ) );
            echo home_url( '/' );
            echo home_url( '/contact/' );
            echo home_url( $path );
            echo home_url();
            echo home_url( 'contact' );
            echo $site->home_url( '/method/' );
            // home_url('/commented/');
            PHP;

        $this->assertSame(['/', '/contact/'], ClassicTheme::links($php));
    }

    public function testClassicTemplatesAndPartsCarryTheirReadsAndLinks(): void
    {
        $layout = self::layout();
        $footer = $layout->item('part:footer');
        $contact = $layout->item('tpl:page-contact');
        $header = $layout->item('part:header');

        $this->assertNotNull($footer);
        $this->assertSame(['acf_option:company_address', 'acf_option:company_phone', 'option:blogname'], $footer->facts['reads']);
        $this->assertSame([], $footer->facts['links']);

        $this->assertNotNull($contact);
        $this->assertSame([], $contact->facts['reads']);
        $this->assertSame(['/thank-you/'], $contact->facts['links']);

        $this->assertSame([], $header?->facts['reads'], 'A file that reads nothing still says so.');
    }

    public function testTheIndexLineNamesWhatAFileReadsAndLinksTo(): void
    {
        $layout = self::layout();
        $footer = $layout->item('part:footer');
        $contact = $layout->item('tpl:page-contact');
        $this->assertNotNull($footer);
        $this->assertNotNull($contact);

        $this->assertSame(
            'area footer; file themes/vwf-classic/footer.php; reads acf_option:company_address, acf_option:company_phone, option:blogname; used in tpl:archive, tpl:index, tpl:page, tpl:page-contact, tpl:single +1',
            Facts::describe($footer)
        );
        $this->assertSame(
            'file themes/vwf-classic-child/page-contact.php; never shows the post\'s own title; hard codes links /thank-you/ in the file (no field or setting changes them)',
            Facts::describe($contact)
        );
    }

    public function testBlockTemplatesAndPartsCarryNeitherFact(): void
    {
        $source = new FakeLayoutSource();
        $source->templates = [FakeLayoutSource::row('index', '<!-- wp:template-part {"slug":"footer"} /-->')];
        $source->parts = [FakeLayoutSource::row('footer', '<!-- wp:paragraph --><p>Call us</p><!-- /wp:paragraph -->', 'Footer', 'footer')];

        $section = (new LayoutScanner($source))->scan();

        foreach (['tpl:index', 'part:footer'] as $id) {
            $facts = $section->item($id)?->facts ?? [];
            $this->assertArrayNotHasKey('reads', $facts, $id);
            $this->assertArrayNotHasKey('links', $facts, $id);
        }
    }

    private static function layout(): Section
    {
        $root = dirname(__DIR__, 2) . '/fixtures/classic-theme';
        $theme = new ClassicTheme(
            [
                ['slug' => 'vwf-classic-child', 'dir' => $root . '/vwf-classic-child'],
                ['slug' => 'vwf-classic', 'dir' => $root . '/vwf-classic'],
            ],
            null,
            [['themes', $root]]
        );

        $source = new FakeLayoutSource();
        $source->templates = $theme->templates();
        $source->parts = $theme->parts();

        return (new LayoutScanner($source))->scan();
    }
}
