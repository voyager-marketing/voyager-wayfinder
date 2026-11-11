<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Export;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Notes\NoteStatus;
use Voyager\Wayfinder\Render\Export\Handoff;
use Voyager\Wayfinder\Render\Export\MarkdownExporter;
use Voyager\Wayfinder\Tests\Support\HandoffFixture;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class MarkdownExporterTest extends TestCase
{
    private const PHONE = 'bind:vwf-fixture/business-info.phone';

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testSectionsFollowThePageOrderWithNotesLast(): void
    {
        $markdown = $this->export();

        preg_match_all('/^## .*$/m', $markdown, $headings);

        $this->assertSame(['## Site', '## Content', '## Layout', '## Routes', '## Integrations', '## Notes'], $headings[0]);
        $this->assertSame(4, preg_match_all('/^## (Content|Layout|Routes|Notes)$/m', $markdown), 'the fitness metric counts exactly these');
    }

    public function testTitleGeneratedLineAndSiteComeFirst(): void
    {
        $handoff = HandoffFixture::handoff();
        $lines = explode("\n", (new MarkdownExporter())->export($handoff));

        $this->assertSame('# Riverbend Plumbing site manual', $lines[0]);
        $this->assertSame('Generated 2026-10-03 22:15 UTC from manifest ' . $handoff->shortHash . '.', $lines[2]);
        $this->assertSame(6, strlen($handoff->shortHash));
        $this->assertSame('Site: <https://example.test>', $lines[4]);
    }

    public function testThePhoneBindingLineCarriesItsFullEditUrl(): void
    {
        $line = $this->line($this->export(), self::PHONE);

        $this->assertStringStartsWith('- **Business info: phone** (Block binding, `' . self::PHONE . '`): ', $line);
        $this->assertStringContainsString('reads option:vwf_phone', $line, 'an underscore inside a word is left alone');
        $this->assertStringEndsWith('Edit: <https://example.test/wp-admin/options-general.php?page=vwf-business-info>', $line);
    }

    public function testEveryEditPathIsAFullAdminUrlOrSaysWhereItIsEdited(): void
    {
        $markdown = $this->export();

        $this->assertStringContainsString('Edit: <https://example.test/wp-admin/site-editor.php?p=/wp_template_part/vwf-fixture//footer&canvas=edit>', $markdown);
        $this->assertStringEndsWith('No edit screen of its own. Edit the template or the content it uses.', $this->line($markdown, 'route:single-post'));
        $this->assertStringEndsWith('No edit screen in wp-admin.', $this->line($markdown, 'pat:twentytwentyfive/post-meta'));
        $this->assertDoesNotMatchRegularExpression('/Edit: <(?!https:\/\/example\.test\/wp-admin\/)/', $markdown);
    }

    public function testRoutesAlwaysGetAHeadingAndIntegrationsOnlyWhenTheSiteHasAny(): void
    {
        $manifest = new Manifest([new Section('content_model', [
            new Item('pt:page', 'post_type', 'Pages', ['public' => true], 'edit.php?post_type=page'),
        ])]);

        $markdown = (new MarkdownExporter())->export(new Handoff($manifest, [], 'Riverbend Plumbing', HandoffFixture::ADMIN));

        preg_match_all('/^## .*$/m', $markdown, $headings);
        $this->assertSame(['## Content', '## Layout', '## Routes', '## Notes'], $headings[0]);
        $this->assertStringContainsString("## Routes\n\nWhich template renders each kind of URL, and what it pulls in.\n\nNothing found on this site.\n", $markdown);
        $this->assertStringContainsString("## Notes\n\nNo agency notes yet.\n", $markdown);
    }

    public function testItemsTheAiTiersFoldAreCountedNotListed(): void
    {
        $markdown = $this->export();

        $this->assertStringNotContainsString('`tpl:index`)', $this->entriesOnly($markdown), 'the parent index template is folded');
        $this->assertStringNotContainsString('- **Sidebar**', $markdown);
        $this->assertStringContainsString('- **Single Posts** (Template, `tpl:single`)', $markdown, 'a route uses it, so it is listed');
        $this->assertStringContainsString(
            "\nNot listed: 2 items that nothing on this site uses (parent theme files and registered patterns). The Wayfinder page in wp-admin lists them.\n",
            $this->section($markdown, 'Layout')
        );
    }

    public function testAnItemWithANoteIsListedEvenWhenTheTiersFoldIt(): void
    {
        $markdown = $this->export(['part:sidebar' => [['body' => 'The sidebar only shows on the blog index.', 'status' => NoteStatus::OK]]]);

        $this->assertStringContainsString("- **Sidebar** (Template part, `part:sidebar`)", $markdown);
        $this->assertStringContainsString("  - Note: The sidebar only shows on the blog index.\n", $markdown);
        $this->assertStringContainsString('Not listed: 1 item that nothing on this site uses', $markdown);
    }

    public function testNotesSitUnderTheirItemsAndTheNotesSectionListsWhatNeedsAttention(): void
    {
        $markdown = $this->export([
            'field:service.warranty_years' => [['body' => 'Warranty shows under the price.', 'status' => NoteStatus::ORPHANED]],
            'part:footer' => [['body' => 'Phone and address come from Settings > Business Info.', 'status' => NoteStatus::OK]],
            'pt:service'  => [['body' => 'Prices show on the service cards.', 'status' => NoteStatus::NEEDS_REVIEW]],
        ]);

        $footer = (string) strstr($markdown, '- **Footer** (Template part, `part:footer`)');
        $this->assertStringContainsString("\n  - Note: Phone and address come from Settings > Business Info.\n", $footer);
        $this->assertStringContainsString("  - Note (needs review): Prices show on the service cards.\n", $markdown);

        $notes = $this->section($markdown, 'Notes');
        $this->assertStringContainsString("3 agency notes. 1 needs review. 1 is orphaned.\n", $notes);
        $this->assertStringContainsString("### Needs review\n\nA note needs review when the item it describes has changed since the note was saved.\n\n- **Services** (`pt:service`): Prices show on the service cards.\n", $notes);
        $this->assertStringContainsString("### Orphaned\n\nThese notes describe items that are no longer on the site.\n\n- `field:service.warranty_years`: Warranty shows under the price.\n", $notes);
        $this->assertStringNotContainsString('Phone and address', $notes, 'a current note is not listed again');
    }

    public function testMarkdownInLabelsAndNotesIsEscapedAndALongNoteStaysInItsItem(): void
    {
        $markdown = $this->export(
            ['opt:blogname' => [['body' => "First *line* with <b>tags</b>\n\n\n# Not a heading\n    indented line", 'status' => NoteStatus::OK]]],
            ['site' => [new Item('opt:blogdescription', 'option', 'Tagline [_draft_] `x`', ['value' => '*Fast* plumbing'], 'options-general.php')]]
        );

        $this->assertStringContainsString('- **Tagline \[\_draft\_\] \`x\`** (Setting, `opt:blogdescription`): \*Fast\* plumbing. Edit: ', $markdown);
        $this->assertStringContainsString("  - Note: First \\*line\\* with \\<b>tags\\</b>\n\n    \\# Not a heading\n    indented line\n", $markdown);
        $this->assertSame(0, preg_match('/^#{1,6} Not a heading/m', $markdown));
    }

    public function testTheSameManifestGivesTheSameBytesWhateverTheInputOrder(): void
    {
        $notes = ['pt:service' => [['body' => 'B note', 'status' => NoteStatus::OK], ['body' => 'A note', 'status' => NoteStatus::OK]]];

        $once = (new MarkdownExporter())->export(HandoffFixture::handoff([], $notes));
        $again = (new MarkdownExporter())->export(HandoffFixture::handoff([], $notes));
        $shuffled = (new MarkdownExporter())->export(HandoffFixture::handoff([], array_reverse($notes, true), true));

        $this->assertSame($once, $again);
        $this->assertSame($once, $shuffled);
        $this->assertStringEndsWith("\n", $once);
        $this->assertStringNotContainsString("\n\n\n", $once);
    }

    public function testNoNoteAuthorTimeOrEmDash(): void
    {
        $markdown = $this->export(['pt:service' => [['body' => 'Prices show on the service cards.', 'status' => NoteStatus::NEEDS_REVIEW]]]);

        $this->assertStringNotContainsString('Updated', $markdown);
        $this->assertStringNotContainsString("\u{2014}", $markdown, 'no em dashes');
        $this->assertStringNotContainsString("\u{2013}", $markdown, 'no en dashes');
    }

    /**
     * @param array<string, list<array{body: string, status: string}>> $notes
     * @param array<string, list<Item>>                                 $extra
     */
    private function export(array $notes = [], array $extra = []): string
    {
        return (new MarkdownExporter())->export(HandoffFixture::handoff($extra, $notes));
    }

    /**
     * The list line of one item.
     */
    private function line(string $markdown, string $id): string
    {
        $this->assertSame(1, preg_match('/^- \*\*.*\(.*`' . preg_quote($id, '/') . '`\).*$/m', $markdown, $match), $id);

        return $match[0];
    }

    /**
     * One `##` section, heading included, up to the next one.
     */
    private function section(string $markdown, string $title): string
    {
        $this->assertSame(1, preg_match('/^## ' . preg_quote($title, '/') . '$.*?(?=^## |\z)/ms', $markdown, $match), $title);

        return $match[0];
    }

    /**
     * Item lines only, without the nested related and used-by lines that name other ids.
     */
    private function entriesOnly(string $markdown): string
    {
        preg_match_all('/^- .*$/m', $markdown, $lines);

        return implode("\n", $lines[0]);
    }
}
