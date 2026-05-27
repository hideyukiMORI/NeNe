<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\CommentThread;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CommentThread.
 */
final class CommentThreadTest extends TestCase
{
    private PDO $db;
    private CommentThread $ct;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE comments (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                parent_id   INTEGER      DEFAULT NULL,
                author_id   VARCHAR(255) NOT NULL,
                body        TEXT         NOT NULL DEFAULT \'\',
                deleted_at  DATETIME     DEFAULT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ct = new CommentThread($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddReturnsId(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'Hello!');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddStoresBody(): void
    {
        $id  = $this->ct->add('post', '1', 'user-1', 'Great post!');
        $row = $this->ct->find($id);
        $this->assertSame('Great post!', $row['body']);
    }

    public function testAddWithParentId(): void
    {
        $id    = $this->ct->add('post', '1', 'user-1', 'Parent');
        $reply = $this->ct->add('post', '1', 'user-2', 'Reply', $id);
        $row   = $this->ct->find($reply);
        $this->assertSame($id, (int)$row['parent_id']);
    }

    public function testAddThrowsOnEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->add('post', '1', 'user-1', '');
    }

    public function testAddThrowsOnEmptyAuthorId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->add('post', '1', '', 'Hello');
    }

    public function testAddThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->add('', '1', 'user-1', 'Hello');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditUpdatesBody(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'Original');
        $this->assertTrue($this->ct->edit($id, 'user-1', 'Updated'));
        $this->assertSame('Updated', $this->ct->find($id)['body']);
    }

    public function testEditReturnsFalseForWrongAuthor(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'Original');
        $this->assertFalse($this->ct->edit($id, 'user-2', 'Hacked'));
    }

    public function testEditThrowsOnEmptyBody(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'Original');
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->edit($id, 'user-1', '');
    }

    // ── delete (soft) ─────────────────────────────────────────────────────────

    public function testDeleteSoftDeletesComment(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'Hello');
        $this->assertTrue($this->ct->delete($id, 'user-1'));
        $row = $this->ct->find($id);
        $this->assertSame('', $row['body']);
        $this->assertNotNull($row['deleted_at']);
    }

    public function testDeleteReturnsFalseForWrongAuthor(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'Hello');
        $this->assertFalse($this->ct->delete($id, 'user-2'));
    }

    public function testDeleteReturnsFalseIfAlreadyDeleted(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'Hello');
        $this->ct->delete($id, 'user-1');
        $this->assertFalse($this->ct->delete($id, 'user-1'));
    }

    // ── remove (hard delete) ──────────────────────────────────────────────────

    public function testRemoveDeletesCommentAndReplies(): void
    {
        $id    = $this->ct->add('post', '1', 'user-1', 'Parent');
        $reply = $this->ct->add('post', '1', 'user-2', 'Reply', $id);
        $this->assertTrue($this->ct->remove($id));
        $this->assertNull($this->ct->find($id));
        $this->assertNull($this->ct->find($reply));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsAllComments(): void
    {
        $this->ct->add('post', '1', 'user-1', 'A');
        $this->ct->add('post', '1', 'user-2', 'B');
        $list = $this->ct->list('post', '1');
        $this->assertCount(2, $list);
    }

    public function testListExcludesDeletedWhenRequested(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'A');
        $this->ct->add('post', '1', 'user-2', 'B');
        $this->ct->delete($id, 'user-1');
        $list = $this->ct->list('post', '1', false);
        $this->assertCount(1, $list);
    }

    public function testListIsEntityScoped(): void
    {
        $this->ct->add('post', '1', 'user-1', 'A');
        $this->ct->add('post', '2', 'user-1', 'B');
        $this->assertCount(1, $this->ct->list('post', '1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountIgnoresDeleted(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'A');
        $this->ct->add('post', '1', 'user-2', 'B');
        $this->ct->delete($id, 'user-1');
        $this->assertSame(1, $this->ct->count('post', '1'));
    }

    // ── replies ───────────────────────────────────────────────────────────────

    public function testRepliesReturnsChildComments(): void
    {
        $id     = $this->ct->add('post', '1', 'user-1', 'Parent');
        $reply1 = $this->ct->add('post', '1', 'user-2', 'Reply 1', $id);
        $reply2 = $this->ct->add('post', '1', 'user-3', 'Reply 2', $id);
        $replies = $this->ct->replies($id);
        $this->assertCount(2, $replies);
    }

    public function testRepliesReturnsEmptyForNoReplies(): void
    {
        $id = $this->ct->add('post', '1', 'user-1', 'Alone');
        $this->assertSame([], $this->ct->replies($id));
    }
}
