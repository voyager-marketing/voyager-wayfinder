<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Export;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Notes\NoteStatus;
use Voyager\Wayfinder\Render\Export\HtmlExporter;
use Voyager\Wayfinder\Render\Export\MarkdownExporter;
use Voyager\Wayfinder\Tests\Support\HandoffFixture;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class HtmlExporterTest extends TestCase
{
    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testOneSelfContainedFileWithPrintCssAndBrandableVariables(): void
    {
        $html = $this->export();

        $this->assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">", $html);
        $this->assertStringEndsWith("</body>\n</html>\n", $html);
        $this->assertStringContainsString('<title>Riverbend Plumbing site manual</title>', $html);

        foreach (['<script', '<link', '<img', ' src=', '@import', 'url('] as $external) {
            $this->assertStringNotContainsString($external, $html, $external);
        }

        $this->assertSame(1, substr_count($html, '<style>'));
        $this->assertStringContainsString('@media print', $html);
        $this->assertSame(1, preg_match('/:root \{(.*?)\}/s', $html, $root));
        foreach (['--vwf-font:', '--vwf-font-mono:', '--vwf-color-text:', '--vwf-color-accent:', '--vwf-color-page:'] as $variable) {
            $this->assertStringContainsString($variable, $root[1], $variable);
        }
        $this->assertSame(0, preg_match('/#[0-9a-f]{3,6}\b/i', (string) preg_replace('/:root \{.*?\}/s', '', HtmlExporter::CSS)), 'every color is a variable');
    }

    public function testHeadingsMatchTheMarkdown(): void
    {
        $html = $this->export();
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/', $html, $h2);

        $markdown = (new MarkdownExporter())->export(HandoffFixture::handoff());
        preg_match_all('/^## (.*)$/m', $markdown, $md);

        $this->assertSame(['Contents', ...$md[1]], $h2[1]);
    }

    public function testOneCardPerListedItemWithItsAnchorAndFullEditUrl(): void
    {
        $html = $this->export();
        $markdown = (new MarkdownExporter())->export(HandoffFixture::handoff());

        $this->assertSame(preg_match_all('/^- \*\*/m', $markdown), substr_count($html, '<article class="vwf-item"'));
        $this->assertStringContainsString('<article class="vwf-item" id="vwf-item-bind:vwf-fixture/business-info.phone"><h3>Business info: phone</h3>', $html);
        $this->assertStringContainsString('<span class="vwf-edit-label">Edit:</span> <a href="https://example.test/wp-admin/options-general.php?page=vwf-business-info">', $html);
        $this->assertStringContainsString('href="https://example.test/wp-admin/site-editor.php?p=/wp_template_part/vwf-fixture//footer&amp;canvas=edit"', $html);
        $this->assertStringContainsString('<p class="vwf-edit">No edit screen of its own. Edit the template or the content it uses.</p>', $html);
    }

    public function testRelatedItemsLinkWithinTheFileAndFoldedOnesAreOnlyNamed(): void
    {
        $html = $this->export();
        $footer = (string) strstr((string) strstr($html, 'id="vwf-item-part:footer"'), '</article>', true);

        $this->assertStringContainsString('<a href="#vwf-item-bind%3Avwf-fixture%2Fbusiness-info.phone">Business info: phone</a> <code>bind:vwf-fixture/business-info.phone</code>', $footer);
        $this->assertStringContainsString('Index <code>tpl:index</code>', $footer, 'the folded index template is named');
        $this->assertStringNotContainsString('href="#vwf-item-tpl%3Aindex"', $html);
        $this->assertStringNotContainsString('id="vwf-item-tpl:index"', $html);
    }

    public function testNotesShowUnderTheirItemsWithTheirStatus(): void
    {
        $html = $this->export([
            'field:service.warranty_years' => [['body' => 'Warranty shows under the price.', 'status' => NoteStatus::ORPHANED]],
            'pt:service' => [['body' => "Prices show on the service cards.\nCheck them each spring.", 'status' => NoteStatus::NEEDS_REVIEW]],
        ]);

        $this->assertStringContainsString('<ul class="vwf-notes"><li><span class="vwf-badge">Needs review</span>Prices show on the service cards.<br>' . "\n" . 'Check them each spring.</li></ul>', $html);
        $this->assertStringContainsString('<p>2 agency notes. 1 needs review. 1 is orphaned.</p>', $html);
        $this->assertStringContainsString('<li><a href="#vwf-item-pt%3Aservice">Services</a> <code>pt:service</code>: Prices show', $html);
        $this->assertStringContainsString('<li><code>field:service.warranty_years</code>: Warranty shows under the price.</li>', $html);
    }

    public function testEveryValueIsEscaped(): void
    {
        $html = $this->export(
            ['opt:blogname' => [['body' => '<script>alert(1)</script> & more', 'status' => NoteStatus::OK]]],
            ['site' => [new Item('opt:blogdescription', 'option', '<img src=x onerror=alert(1)>', ['value' => '"quoted"'], 'options-general.php')]],
            '<b>Bold</b> & Sons'
        );

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>Bold</b>', $html);
        $this->assertStringContainsString('<title>&lt;b&gt;Bold&lt;/b&gt; &amp; Sons site manual</title>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; more', $html);
        $this->assertStringContainsString('<h3>&lt;img src=x onerror=alert(1)&gt;</h3>', $html);
    }

    public function testTheSameManifestGivesTheSameBytes(): void
    {
        $this->assertSame($this->export(), (new HtmlExporter())->export(HandoffFixture::handoff([], [], true)));
    }

    /**
     * @param array<string, list<array{body: string, status: string}>> $notes
     * @param array<string, list<Item>>                                 $extra
     */
    private function export(array $notes = [], array $extra = [], string $siteName = 'Riverbend Plumbing'): string
    {
        return (new HtmlExporter())->export(HandoffFixture::handoff($extra, $notes, false, $siteName));
    }
}
