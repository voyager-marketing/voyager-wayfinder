<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Render\Human;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Admin\EditAccess;
use Voyager\Wayfinder\Edit\ClientAccess;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Notes\Note;
use Voyager\Wayfinder\Notes\NoteStatus;
use Voyager\Wayfinder\Render\Ai\Visibility;
use Voyager\Wayfinder\Render\Human\ManualView;
use Voyager\Wayfinder\Render\Human\PageState;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class ManualViewTest extends TestCase
{
    private const ADMIN = 'https://example.test/wp-admin/';

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testEveryItemGetsExactlyOneCard(): void
    {
        $manifest = FixtureManifest::manifest();
        $html = $this->render($manifest);

        $this->assertSame(count($manifest->ids()), substr_count($html, 'data-vwf-item='));

        foreach ($manifest->ids() as $id) {
            $this->assertSame(1, substr_count($html, 'data-vwf-item="' . htmlspecialchars($id, ENT_QUOTES) . '"'), $id);
            $this->assertSame(1, substr_count($html, 'id="' . htmlspecialchars(ManualView::anchor($id), ENT_QUOTES) . '"'), $id);
        }
    }

    public function testItemsTheAiTiersFoldSitInAClosedGroupAtTheEndOfTheirSection(): void
    {
        $manifest = FixtureManifest::manifest();
        $html = $this->render($manifest);
        $folded = count($manifest->ids()) - count(Visibility::of($manifest)->listed);

        $this->assertGreaterThan(0, $folded);
        $this->assertStringContainsString('<summary>' . $folded . ' more items that nothing on this site uses</summary>', $html);

        $group = strpos($html, '<details class="vwf-folded">');
        $this->assertNotFalse($group);
        $this->assertGreaterThan($group, strpos($html, 'data-vwf-item="tpl:index"'), 'a parent template nothing lists is folded');
        $this->assertLessThan($group, strpos($html, 'data-vwf-item="tpl:single-service"'), 'the child theme template is listed');
    }

    public function testSectionsFollowThePageOrderAndTheNavLinksToEach(): void
    {
        $html = $this->render(FixtureManifest::manifest(shuffle: true));

        $positions = array_map(
            static fn (string $name): int|false => strpos($html, '<section class="vwf-section" id="vwf-section-' . $name . '"'),
            ['site', 'content_model', 'layout', 'integrations']
        );

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        foreach (['site' => 'Site', 'content_model' => 'Content', 'layout' => 'Layout', 'integrations' => 'Integrations'] as $name => $title) {
            $this->assertStringContainsString('<a href="#vwf-section-' . $name . '">' . $title . ' <span class="count">', $html);
        }

        $this->assertStringNotContainsString('vwf-section-routes', $html, 'a section the manifest does not have gets no nav entry');
        $this->assertStringContainsString('<a href="#vwf-notes">Notes needing review', $html);
    }

    public function testEditLinksJoinTheAdminUrl(): void
    {
        $html = $this->render(FixtureManifest::manifest());

        $this->assertStringContainsString('href="https://example.test/wp-admin/edit.php?post_type=service"', $html);
        $this->assertStringContainsString('href="https://example.test/wp-admin/site-editor.php?p=/wp_template_part/vwf-fixture//footer&amp;canvas=edit"', $html);
        $this->assertStringContainsString('href="https://example.test/wp-admin/options-general.php?page=vwf-business-info"', $html);
        $this->assertStringContainsString('No edit screen in wp-admin.', $html, 'the registered pattern has no edit path');
    }

    public function testAScreenTheReaderCannotOpenIsShownNotLinked(): void
    {
        $view = new ManualView(null, static fn (string $edit): bool => !str_starts_with($edit, 'options-general.php'));
        $html = $view->render(FixtureManifest::manifest(), [], new PageState('Riverbend Plumbing', self::ADMIN));

        $this->assertStringNotContainsString('href="https://example.test/wp-admin/options-general.php', $html);
        $this->assertStringContainsString('<code class="vwf-edit-path">options-general.php?page=vwf-business-info</code> <span class="description">Your account cannot open this screen.</span>', $html);
        $this->assertStringContainsString('href="https://example.test/wp-admin/edit.php?post_type=service"', $html, 'screens the reader can open stay linked');
    }

    public function testEachCardSaysWhetherTheClientCanEditIt(): void
    {
        $client = new ClientAccess(
            'editor',
            static fn (string $cap): bool => $cap !== 'edit_theme_options' && $cap !== 'manage_options',
            static fn (string $edit): string => str_starts_with($edit, 'site-editor.php') ? 'edit_theme_options' : (str_starts_with($edit, 'options') ? 'manage_options' : 'edit_others_posts')
        );
        $manifest = new Manifest([new Section('layout', [
            new Item('part:footer', 'template_part', 'Footer', ['area' => 'footer', 'lock' => ['effective' => 'content_only', 'locked' => 3, 'src' => 'wp7_default']], 'site-editor.php?p=/wp_template_part/vwf-fixture//footer&canvas=edit'),
            new Item('pat:cta', 'pattern', 'CTA', ['lock' => ['effective' => 'none', 'locked' => 0, 'src' => 'none'], 'registered' => false, 'synced' => true], 'post.php?post=7&action=edit'),
            new Item('pat:hero', 'pattern', 'Hero', ['registered' => true, 'synced' => false], null),
        ])]);

        $html = (new ManualView(null, null, $client))->render($manifest, [], new PageState('Riverbend Plumbing', self::ADMIN));

        $this->assertSame(3, substr_count($html, 'class="vwf-client"'));
        $this->assertStringContainsString('data-vwf-client-can="no">Client can edit: no (the editor role cannot open it: it needs edit_theme_options)</p>', $html);
        $this->assertStringContainsString('data-vwf-client-can="yes">Client can edit: yes (the editor role can open it; not locked)</p>', $html);
        $this->assertStringContainsString('Client can edit: no (no edit screen in wp-admin; it is changed in code)', $html);
        $this->assertStringNotContainsString('vwf-client', $this->render($manifest), 'Without a client role the page adds no line.');
    }

    public function testEditAccessKnowsTheCapabilityOfFixedScreens(): void
    {
        $this->assertSame('edit_theme_options', EditAccess::screenCapability('site-editor.php'));
        $this->assertSame('manage_options', EditAccess::screenCapability('options-reading.php'));
        $this->assertSame('activate_plugins', EditAccess::screenCapability('plugins.php'));
        $this->assertSame('update_core', EditAccess::screenCapability('update-core.php'));
        $this->assertNull(EditAccess::screenCapability('edit.php'), 'depends on the post type');
        $this->assertNull(EditAccess::screenCapability('admin.php'), 'depends on the page');
    }

    public function testEditUrlLeavesSiteEditorTrailsAndEmptyPathsAlone(): void
    {
        $this->assertSame(self::ADMIN . 'edit.php', ManualView::editUrl('edit.php', self::ADMIN));
        $this->assertSame(self::ADMIN . 'edit.php', ManualView::editUrl('/edit.php', rtrim(self::ADMIN, '/')));
        $this->assertNull(ManualView::editUrl('Site Editor > Patterns > Footer', self::ADMIN));
        $this->assertNull(ManualView::editUrl(null, self::ADMIN));
        $this->assertNull(ManualView::editUrl('', self::ADMIN));
    }

    public function testRelatedItemsLinkToCardsThatExist(): void
    {
        $html = $this->render(FixtureManifest::manifest());

        $this->assertStringContainsString('<a href="#vwf-item-tax%3Aservice_area">Service areas</a>', $html, 'pt:service uses its taxonomy');
        $this->assertStringContainsString('<a href="#vwf-item-tpl%3Asingle-service">single-service</a>', $html, 'the CTA banner is used by the template');

        $service = (string) strstr((string) strstr($html, 'data-vwf-item="pt:service"'), '</article>', true);
        $this->assertStringContainsString('<h4>Related</h4>', $service);
        $this->assertStringNotContainsString('<h4>Used by</h4>', $service, 'its fields point back at it, and Related already lists them');

        $footer = (string) strstr((string) strstr($html, 'data-vwf-item="part:footer"'), '</article>', true);
        $this->assertStringContainsString('<h4>Used by</h4>', $footer);
        $this->assertStringContainsString('<a href="#vwf-item-tpl%3Asingle-service">single-service</a>', $footer);

        preg_match_all('/href="#(vwf-item-[^"]+)"/', $html, $links);
        $this->assertNotEmpty($links[1]);

        foreach (array_unique($links[1]) as $fragment) {
            $this->assertStringContainsString('id="' . htmlspecialchars(rawurldecode($fragment), ENT_QUOTES) . '"', $html, $fragment);
        }
    }

    public function testCountsAreExactAndContentUsesAreNamed(): void
    {
        $html = $this->render(FixtureManifest::manifest(services: 7));

        $this->assertStringContainsString('<dt>Content</dt><dd>7 publish</dd>', $html);
        $this->assertStringContainsString('<dt>Used in content</dt><dd>page:about, page:home</dd>', $html);
    }

    public function testAnEditorReadsNotesAndGetsNoWriteControls(): void
    {
        $html = $this->render(FixtureManifest::manifest(), [self::note(1, 'pt:service', 'Prices show on the service cards')]);

        $this->assertStringContainsString('Prices show on the service cards', $html);
        $this->assertStringNotContainsString('data-vwf-action', $html);
        $this->assertStringNotContainsString('data-vwf-note-add', $html);
        $this->assertStringNotContainsString('vwf_rescan', $html);
        $this->assertStringNotContainsString('id="vwf-settings"', $html);
        $this->assertStringNotContainsString('vwf_mcp_enabled', $html);
    }

    public function testAnAdminGetsNoteControlsRescanAndTheSettingsPanel(): void
    {
        $manifest = FixtureManifest::manifest();
        $html = $this->render($manifest, [self::note(7, 'pt:service', 'Prices show on the service cards')], canWrite: true);

        $this->assertSame(count($manifest->ids()), substr_count($html, 'data-vwf-note-add='), 'every card can take a note');
        $this->assertStringContainsString('data-vwf-note-edit="7"', $html);
        $this->assertStringContainsString('data-vwf-note-delete="7"', $html);
        $this->assertStringContainsString('aria-controls="vwf-note-7-form"', $html);

        $this->assertStringContainsString('<form method="post" action="https://example.test/wp-admin/admin-post.php" class="vwf-rescan">', $html);
        $this->assertStringContainsString('<input type="hidden" name="action" value="vwf_rescan"><input type="hidden" name="_wpnonce" value="rescan-nonce">', $html);

        $this->assertStringContainsString('<form method="post" action="https://example.test/wp-admin/options.php">', $html);
        $this->assertStringContainsString('<input type="hidden" name="option_page" value="vwf_settings">', $html);
        $this->assertStringContainsString('<input type="hidden" name="_wpnonce" value="settings-nonce">', $html);
        $this->assertStringContainsString('<input type="hidden" name="vwf_mcp_enabled" value="0">', $html);
        $this->assertStringContainsString('name="vwf_mcp_enabled" value="1" aria-describedby', $html, 'off by default');
    }

    public function testTheMcpCheckboxShowsTheStoredValue(): void
    {
        $html = $this->render(FixtureManifest::manifest(), canWrite: true, mcpEnabled: true);

        $this->assertStringContainsString('name="vwf_mcp_enabled" value="1" checked aria-describedby', $html);
    }

    public function testAdminsGetTheExportButtonAndEditorsDoNot(): void
    {
        $admin = $this->render(FixtureManifest::manifest(), canWrite: true);

        $this->assertStringContainsString('<form method="post" action="https://example.test/wp-admin/admin-post.php" class="vwf-export">'
            . '<input type="hidden" name="action" value="vwf_export"><input type="hidden" name="_wpnonce" value="export-nonce">', $admin);
        $this->assertStringContainsString('<label for="vwf-export-format">Export as</label> <select id="vwf-export-format" name="format">'
            . '<option value="md">Markdown</option><option value="html">HTML to print</option></select>', $admin);
        $this->assertStringContainsString('<button type="submit" class="button">Export</button>', $admin);

        $editor = $this->render(FixtureManifest::manifest());

        $this->assertStringNotContainsString('vwf_export', $editor);
        $this->assertStringNotContainsString('vwf-export', $editor);
    }

    public function testBadgesCountNotesThatNeedReviewAndOrphans(): void
    {
        $html = $this->render(FixtureManifest::manifest(), [
            self::note(1, 'pt:service', 'Prices show on the service cards', NoteStatus::NEEDS_REVIEW),
            self::note(2, 'pt:page', 'The about page is hand built'),
            self::note(3, 'field:service.warranty_years', 'Warranty shows under the price', NoteStatus::ORPHANED),
        ], canWrite: true);

        $this->assertStringContainsString('<dt>Notes</dt><dd>3 <a class="vwf-badge vwf-badge--review" href="#vwf-notes">1 needs review</a>', $html);
        $this->assertStringContainsString('<a class="vwf-badge vwf-badge--orphaned" href="#vwf-notes">1 orphaned</a>', $html);
        $this->assertStringContainsString('Notes needing review <span class="vwf-badge vwf-badge--review">2</span>', $html);

        $this->assertSame(1, substr_count($html, '<li class="vwf-note vwf-note--needs_review"'));
        $this->assertStringContainsString('<span class="vwf-badge vwf-badge--review">Needs review</span>', $html);

        $review = (string) strstr($html, 'id="vwf-notes"');
        $this->assertStringContainsString('<a href="#vwf-item-pt%3Aservice">Services</a> <code>pt:service</code>: Prices show on the service cards', $review);
        $this->assertStringContainsString('<code>field:service.warranty_years</code> <span class="vwf-badge vwf-badge--orphaned">Orphaned</span>', $review);
        $this->assertStringContainsString('data-vwf-note-delete="3"', $review, 'an orphan can be deleted where it is listed');
        $this->assertStringNotContainsString('The about page is hand built', $review, 'a current note is not listed for review');
    }

    public function testANoteWhoseItemIsGoneReadsOrphanedWhateverItsStoredStatus(): void
    {
        $html = $this->render(FixtureManifest::manifest(), [self::note(4, 'pt:gone', 'Old note', NoteStatus::OK)]);

        $this->assertStringContainsString('<li class="vwf-note vwf-note--orphaned" data-vwf-note="4">', $html);
        $this->assertStringContainsString('1 orphaned', $html);
    }

    public function testWithNoNotesNothingNeedsReview(): void
    {
        $html = $this->render(FixtureManifest::manifest());

        $this->assertStringContainsString('<dt>Notes</dt><dd>0</dd>', $html);
        $this->assertStringContainsString('No notes need review.', $html);
        $this->assertStringNotContainsString('vwf-badge', $html);
    }

    public function testEveryValueIsEscaped(): void
    {
        $manifest = FixtureManifest::manifest(['site' => [
            new Item('opt:blogdescription', 'option', '<script>alert(1)</script>', ['value' => '"><img src=x onerror=alert(2)>'], 'javascript:alert(3)'),
        ]]);

        $html = $this->render($manifest, [self::note(5, 'opt:blogdescription', '<b>bold</b> & more')], canWrite: true, brief: "# brief\n<tag> & co");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<tag>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; more', $html);
    }

    public function testTheCopyButtonCarriesTheBrief(): void
    {
        $html = $this->render(FixtureManifest::manifest(), brief: "# wayfinder v1 brief h:abc123\nsite: Riverbend Plumbing\n");

        $this->assertStringContainsString('data-vwf-copy-brief', $html);
        $this->assertStringContainsString(">Copy AI brief</button>", $html);
        $this->assertStringContainsString("readonly hidden># wayfinder v1 brief h:abc123\nsite: Riverbend Plumbing\n</textarea>", $html);
    }

    public function testTheHeaderShowsTheSiteLastScanAndShortHash(): void
    {
        $manifest = FixtureManifest::manifest();
        $html = $this->render($manifest, lastScan: '2 October 2026 9:33 pm');

        $this->assertStringContainsString('<dd>Riverbend Plumbing</dd>', $html);
        $this->assertStringContainsString('<dd>2 October 2026 9:33 pm</dd>', $html);
        $this->assertStringContainsString('<code title="' . $manifest->hash . '">' . substr($manifest->hash, 0, 6) . '</code>', $html);
    }

    public function testSearchTextHoldsTheLabelIdAndSummaryInLowercase(): void
    {
        $html = $this->render(FixtureManifest::manifest());

        $this->assertMatchesRegularExpression('/data-vwf-item="pt:service" data-vwf-search="services pt:service [^"]*price_from/', $html);
        $this->assertStringContainsString('data-vwf-search-box hidden', $html, 'search waits for the script');
    }

    public function testHeadingsGoFromOneToFourAndButtonsHaveText(): void
    {
        $html = $this->render(FixtureManifest::manifest(), [self::note(1, 'pt:service', 'A note')], canWrite: true);

        $this->assertSame(1, substr_count($html, '<h1'));
        preg_match_all('/<h([1-6])/', $html, $levels);
        $this->assertSame('1', $levels[1][0]);

        // A heading is never more than one level below the one before it.
        $previous = 0;
        foreach ($levels[1] as $level) {
            $this->assertLessThanOrEqual($previous + 1, (int) $level);
            $previous = (int) $level;
        }

        $this->assertSame(['1', '2', '3', '4'], array_values(array_unique($levels[1])));

        preg_match_all('/<button[^>]*>(.*?)<\/button>/s', $html, $buttons);
        foreach ($buttons[1] as $label) {
            $this->assertNotSame('', trim(strip_tags($label)));
        }
    }

    public function testCopyFollowsTheHouseRules(): void
    {
        $html = $this->render(FixtureManifest::manifest(), [self::note(1, 'pt:service', 'A note', NoteStatus::NEEDS_REVIEW)], canWrite: true);

        $this->assertStringNotContainsString("\u{2014}", $html, 'no em dashes');
        $this->assertStringNotContainsString("\u{2013}", $html, 'no en dashes');
    }

    /**
     * @param list<Note> $notes
     */
    private function render(
        Manifest $manifest,
        array $notes = [],
        bool $canWrite = false,
        bool $mcpEnabled = false,
        string $brief = '',
        string $lastScan = ''
    ): string {
        return (new ManualView())->render($manifest, $notes, new PageState(
            siteName: 'Riverbend Plumbing',
            adminUrl: self::ADMIN,
            lastScan: $lastScan,
            brief: $brief,
            canWrite: $canWrite,
            mcpEnabled: $mcpEnabled,
            rescanNonce: $canWrite ? 'rescan-nonce' : '',
            settingsNonce: $canWrite ? 'settings-nonce' : '',
            referer: '/wp-admin/admin.php?page=voyager-wayfinder',
            exportNonce: $canWrite ? 'export-nonce' : ''
        ));
    }

    private static function note(int $id, string $itemId, string $body, string $status = NoteStatus::OK): Note
    {
        return new Note($id, $itemId, $body, $status, 1, 'hash', '2026-10-02 21:00:00');
    }
}
