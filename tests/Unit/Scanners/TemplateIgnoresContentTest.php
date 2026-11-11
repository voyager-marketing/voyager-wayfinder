<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Render\Ai\Facts;
use Voyager\Wayfinder\Scanners\ClassicTheme;
use Voyager\Wayfinder\Scanners\LayoutScanner;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;

/**
 * A classic template that builds the page from fields and never prints the
 * post's title or content says so, and a link it hard codes is said to live
 * in the file. In run 4 agents sent "the big headline" to the page title and
 * a hard-coded CTA link to a flexible content field.
 */
final class TemplateIgnoresContentTest extends TestCase
{
    private const THEME = 'acme';

    private const FILES = [
        'style.css'                  => "/*\nTheme Name: Acme\n*/\n",
        'header.php'                 => "<?php\nbloginfo('name');\n",
        'footer.php'                 => "<?php\necho esc_url(home_url('/privacy/'));\n",
        'index.php'                  => "<?php\nget_header();\nwhile (have_posts()) {\n    the_post();\n    get_template_part('template-parts/content');\n}\nget_footer();\n",
        'template-parts/content.php' => "<?php\nthe_title('<h1>', '</h1>');\nthe_content();\n",
        'landing.php'                => "<?php\n/**\n * Template Name: Landing\n */\nget_header();\nwhile (have_rows('sections')) {\n    the_row();\n    echo esc_html((string) get_sub_field('heading'));\n}\nforeach ((array) get_field('related') as \$related) {\n    echo esc_html(get_the_title(\$related));\n}\necho esc_url(home_url('/quote/'));\nget_footer();\n",
        'page-about.php'             => "<?php\nget_header();\nthe_content();\nget_footer();\n",
    ];

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vwf-template-content-' . bin2hex(random_bytes(4));

        foreach (self::FILES as $name => $php) {
            $path = $this->root . '/' . self::THEME . '/' . $name;

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $php);
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::FILES) as $name) {
            @unlink($this->root . '/' . self::THEME . '/' . $name);
        }

        @rmdir($this->root . '/' . self::THEME . '/template-parts');
        @rmdir($this->root . '/' . self::THEME);
        @rmdir($this->root);
    }

    public function testShowsPostReadsWhatAFilePrintsOfTheCurrentPost(): void
    {
        $this->assertSame(['content', 'title'], ClassicTheme::showsPost("<?php the_title(); the_content();"));
        $this->assertSame(['excerpt', 'title'], ClassicTheme::showsPost("<?php echo get_the_title(); the_excerpt();"));
        $this->assertSame(['title'], ClassicTheme::showsPost("<?php single_post_title();"));
        $this->assertSame([], ClassicTheme::showsPost("<?php echo get_the_title(\$related); echo get_the_content(null, false, \$other);"), 'A getter given an argument reads another post.');
        $this->assertSame([], ClassicTheme::showsPost("<?php \$post->the_content(); // the_title()\n"), 'Method calls and comments do not count.');
    }

    public function testATemplateCountsWhatItsPartsPrint(): void
    {
        $rows = array_column($this->theme()->templates(), null, 'slug');

        $this->assertSame(['content', 'title'], $rows['index']['shows_post'] ?? null, 'Through get_template_part().');
        $this->assertSame(['content'], $rows['page-about']['shows_post'] ?? null);
        $this->assertSame([], $rows['landing']['shows_post'] ?? null, 'get_the_title($related) is another post\'s title.');
    }

    public function testTheIndexLineSaysTheTemplateNeverShowsThePostAndWhereItsLinkLives(): void
    {
        $landing = $this->layout()->item('tpl:landing');
        $this->assertNotNull($landing);

        $this->assertSame([], $landing->facts['shows_post']);
        $this->assertSame(
            'file themes/acme/landing.php; never shows the post\'s own title or content; reads acf:related, acf:sections; hard codes links /quote/ in the file (no field or setting changes them)',
            Facts::describe($landing)
        );
        $this->assertSame('file themes/acme/page-about.php; never shows the post\'s own title', Facts::describe($this->layout()->item('tpl:page-about') ?? $landing));
        $this->assertStringNotContainsString('never shows', Facts::describe($this->layout()->item('tpl:index') ?? $landing));
    }

    public function testFindSaysThePagesOwnContentIsNotShownAndTheLinkIsInTheFile(): void
    {
        $routes = new FakeRouteSource();
        $routes->classic = $this->theme();
        $routes->add(40, 'page', 'offer', 'Spring offer', '/offer/', 'landing.php');
        $routes->postTypes = [['name' => 'page', 'archive_path' => null, 'rewrite' => null, 'hierarchical' => true, 'query_var' => null]];
        $access = new ClientAccess('editor', static fn (string $cap): bool => true, static fn (string $edit): string => 'edit_others_pages');

        $chain = array_column((new EditLocator($routes, $this->source(), new Locks('7.1.2'), $access))->find('/offer/')['chain'], null, 'id');

        $this->assertSame(
            'The title and content of this page, "Spring offer". Edit them here: post.php?post=40&action=edit. Its template, themes/acme/landing.php, never shows this page\'s title or content: what the page shows comes from acf:related, acf:sections.',
            $chain['pt:page']['instruction']
        );
        $this->assertSame(
            'Template "Landing", used for this URL and every other one it matches. It is the theme file themes/acme/landing.php, edited in code. It hard codes links to /quote/ in the file; no field or setting changes them.',
            $chain['tpl:landing']['instruction']
        );
    }

    public function testABlockTemplateHasNoShowsPostFact(): void
    {
        $section = (new LayoutScanner(FakeLayoutSource::fixture(), new Locks('7.1.2')))->scan();

        $this->assertArrayNotHasKey('shows_post', $section->item('tpl:page-contact')?->facts ?? ['shows_post' => true]);
    }

    private function theme(): ClassicTheme
    {
        return new ClassicTheme([['slug' => self::THEME, 'dir' => $this->root . '/' . self::THEME]], null, [['themes', $this->root]]);
    }

    private function source(): FakeLayoutSource
    {
        $theme = $this->theme();
        $source = new FakeLayoutSource();
        $source->templates = $theme->templates();
        $source->parts = $theme->parts();

        return $source;
    }

    private function layout(): \Voyager\Wayfinder\Manifest\Section
    {
        return (new LayoutScanner($this->source(), new Locks('7.1.2')))->scan();
    }
}
