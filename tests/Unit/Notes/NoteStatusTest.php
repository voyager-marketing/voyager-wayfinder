<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Notes;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Manifest\Differ;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Manifest\Manifest;
use Voyager\Wayfinder\Manifest\Section;
use Voyager\Wayfinder\Notes\Note;
use Voyager\Wayfinder\Notes\NoteStatus;

final class NoteStatusTest extends TestCase
{
    public function testANoteWhoseItemIsUnchangedIsOk(): void
    {
        $this->assertSame(NoteStatus::OK, NoteStatus::evaluate('aaa', 'aaa'));
    }

    public function testANoteWhoseItemChangedNeedsReview(): void
    {
        $this->assertSame(NoteStatus::NEEDS_REVIEW, NoteStatus::evaluate('bbb', 'aaa'));
    }

    public function testANoteWhoseItemIsGoneIsOrphaned(): void
    {
        $this->assertSame(NoteStatus::ORPHANED, NoteStatus::evaluate(null, 'aaa'));
    }

    public function testChangesListsOnlyTheNotesWhoseStatusMoves(): void
    {
        $notes = [
            $this->note(4, 'pt:service', NoteStatus::OK, 'svc-1'),
            $this->note(2, 'field:service.warranty_years', NoteStatus::OK, 'war-1'),
            $this->note(3, 'pt:team_member', NoteStatus::OK, 'team-1'),
            $this->note(1, 'opt:blogname', NoteStatus::NEEDS_REVIEW, 'blog-1'),
        ];

        $changes = NoteStatus::changes($notes, [
            'pt:service'     => 'svc-2',
            'pt:team_member' => 'team-1',
            'opt:blogname'   => 'blog-1',
        ]);

        $this->assertSame([
            1 => NoteStatus::OK,
            2 => NoteStatus::ORPHANED,
            4 => NoteStatus::NEEDS_REVIEW,
        ], $changes);
    }

    public function testAnItemThatComesBackUnchangedClearsTheFlag(): void
    {
        $note = $this->note(1, 'field:service.warranty_years', NoteStatus::ORPHANED, 'war-1');

        $this->assertSame([1 => NoteStatus::OK], NoteStatus::changes([$note], ['field:service.warranty_years' => 'war-1']));
    }

    public function testPublishingContentLeavesANoteOkAndANewFieldStillFlagsIt(): void
    {
        $manifest = static fn (int $published, array $refs = []): array => Differ::itemHashes((new Manifest([
            new Section('content_model', [
                new Item('pt:service', 'post_type', 'Services', ['public' => true], 'edit.php?post_type=service', $refs, ['counts' => ['publish' => $published]]),
            ]),
        ]))->body());

        $written = $manifest(6);
        $note = $this->note(1, 'pt:service', NoteStatus::OK, $written['pt:service']);

        $this->assertSame([], NoteStatus::changes([$note], $manifest(7)), 'A count is a stat, so the note still describes its item.');
        $this->assertSame(
            [1 => NoteStatus::NEEDS_REVIEW],
            NoteStatus::changes([$note], $manifest(7, ['field:service.warranty_years'])),
            'A new field is structure.'
        );
    }

    public function testAPluginUpgradeRepinsNotesThatWereCurrent(): void
    {
        // The stored manifest had svc-1; the new release adds a fact, so the same site scans as svc-2.
        $note = $this->note(1, 'pt:service', NoteStatus::OK, 'svc-1');
        $repins = NoteStatus::repins([$note], ['pt:service' => 'svc-1'], ['pt:service' => 'svc-2']);

        $this->assertSame([1 => 'svc-2'], $repins);

        $repinned = new Note(1, 'pt:service', 'A note', NoteStatus::OK, 1, $repins[1], '2026-10-01 12:00:00');
        $this->assertSame([], NoteStatus::changes([$repinned], ['pt:service' => 'svc-2']), 'the note stays ok');
        $this->assertSame([1 => NoteStatus::NEEDS_REVIEW], NoteStatus::changes([$note], ['pt:service' => 'svc-2']), 'without the re-pin it would be flagged');
    }

    public function testAnUpgradeLeavesStaleNotesOrphansAndUnchangedItemsAlone(): void
    {
        $notes = [
            $this->note(1, 'pt:service', NoteStatus::NEEDS_REVIEW, 'svc-0'),   // already behind the stored manifest
            $this->note(2, 'field:service.warranty_years', NoteStatus::OK, 'war-1'), // its item is gone
            $this->note(3, 'opt:blogname', NoteStatus::OK, 'blog-1'),           // its item did not change
            $this->note(4, 'pt:page', NoteStatus::OK, 'page-1'),                // current, and its item changed
        ];

        $this->assertSame([4 => 'page-2'], NoteStatus::repins(
            $notes,
            ['pt:service' => 'svc-1', 'field:service.warranty_years' => 'war-1', 'opt:blogname' => 'blog-1', 'pt:page' => 'page-1'],
            ['pt:service' => 'svc-2', 'opt:blogname' => 'blog-1', 'pt:page' => 'page-2']
        ));
    }

    public function testTheRestShapeLeavesThePinnedHashOut(): void
    {
        $note = Note::fromRow([
            'id'                 => '7',
            'item_id'            => 'pt:service',
            'body'               => 'Prices show on the service cards',
            'status'             => 'needs_review',
            'author_id'          => '1',
            'item_hash_at_write' => str_repeat('a', 64),
            'updated_at'         => '2026-10-01 12:00:00',
        ]);

        $this->assertSame([
            'id'         => 7,
            'item_id'    => 'pt:service',
            'status'     => 'needs_review',
            'body'       => 'Prices show on the service cards',
            'author_id'  => 1,
            'updated_at' => '2026-10-01T12:00:00Z',
        ], $note->toArray());
    }

    private function note(int $id, string $itemId, string $status, string $hash): Note
    {
        return new Note($id, $itemId, 'A note', $status, 1, $hash, '2026-10-01 12:00:00');
    }
}
