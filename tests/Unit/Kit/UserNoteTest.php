<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\UserNote;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserNote.
 */
final class UserNoteTest extends TestCase
{
    private PDO $db;
    private UserNote $un;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE user_notes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                author_id  VARCHAR(255) NOT NULL,
                content    TEXT         NOT NULL,
                pinned     TINYINT(1)   NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->un = new UserNote($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddReturnsPositiveId(): void
    {
        $id = $this->un->add('user-1', 'admin-1', 'Note content');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddStoresContent(): void
    {
        $id   = $this->un->add('user-1', 'admin-1', 'Some note');
        $note = $this->un->find($id);
        $this->assertNotNull($note);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Some note', $note['content']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('admin-1', $note['author_id']);
    }

    public function testAddThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->un->add('', 'admin-1', 'Note');
    }

    public function testAddThrowsOnEmptyContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->un->add('user-1', 'admin-1', '');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function testUpdateChangesContent(): void
    {
        $id = $this->un->add('user-1', 'admin-1', 'Original');
        $this->assertTrue($this->un->update($id, 'Updated'));
        $note = $this->un->find($id);
        $this->assertNotNull($note);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Updated', $note['content']);
    }

    public function testUpdateReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->un->update(999, 'Content'));
    }

    public function testUpdateThrowsOnEmptyContent(): void
    {
        $id = $this->un->add('user-1', 'admin-1', 'Note');
        $this->expectException(\InvalidArgumentException::class);
        $this->un->update($id, '');
    }

    // ── pin / unpin ───────────────────────────────────────────────────────────

    public function testPinMarksNoteAsPinned(): void
    {
        $id = $this->un->add('user-1', 'admin-1', 'Important');
        $this->assertTrue($this->un->pin($id));
        $note = $this->un->find($id);
        $this->assertNotNull($note);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$note['pinned']);
    }

    public function testUnpinClearsPinnedFlag(): void
    {
        $id = $this->un->add('user-1', 'admin-1', 'Important');
        $this->un->pin($id);
        $this->assertTrue($this->un->unpin($id));
        $note = $this->un->find($id);
        $this->assertNotNull($note);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$note['pinned']);
    }

    public function testPinReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->un->pin(999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesNote(): void
    {
        $id = $this->un->add('user-1', 'admin-1', 'To delete');
        $this->assertTrue($this->un->delete($id));
        $this->assertNull($this->un->find($id));
    }

    public function testDeleteReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->un->delete(999));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserListsAllNotes(): void
    {
        $this->un->add('user-1', 'admin-1', 'Note 1');
        $this->un->add('user-1', 'admin-2', 'Note 2');
        $this->un->add('user-2', 'admin-1', 'Other user');
        $notes = $this->un->forUser('user-1');
        $this->assertCount(2, $notes);
    }

    public function testForUserReturnsPinnedFirst(): void
    {
        $id1 = $this->un->add('user-1', 'admin-1', 'First');
        $id2 = $this->un->add('user-1', 'admin-1', 'Pinned');
        $this->un->pin($id2);
        $notes = $this->un->forUser('user-1');
        $this->assertSame($id2, (int)$notes[0]['id']);
        $this->assertSame($id1, (int)$notes[1]['id']);
    }

    public function testForUserReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->un->forUser('nobody'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsCorrectCount(): void
    {
        $this->assertSame(0, $this->un->count('user-1'));
        $this->un->add('user-1', 'admin-1', 'Note 1');
        $this->un->add('user-1', 'admin-1', 'Note 2');
        $this->assertSame(2, $this->un->count('user-1'));
    }

    // ── deleteAllForUser ──────────────────────────────────────────────────────

    public function testDeleteAllForUserRemovesAllNotes(): void
    {
        $this->un->add('user-1', 'admin-1', 'A');
        $this->un->add('user-1', 'admin-1', 'B');
        $count = $this->un->deleteAllForUser('user-1');
        $this->assertSame(2, $count);
        $this->assertSame(0, $this->un->count('user-1'));
    }

    public function testDeleteAllForUserReturnsZeroForUnknownUser(): void
    {
        $this->assertSame(0, $this->un->deleteAllForUser('nobody'));
    }
}
