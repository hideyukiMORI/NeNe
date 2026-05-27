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
                user_id     VARCHAR(255) NOT NULL,
                parent_id   INTEGER      DEFAULT NULL,
                body        TEXT         NOT NULL,
                deleted_at  DATETIME     DEFAULT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ct = new CommentThread($this->db);
    }

    // ── post ──────────────────────────────────────────────────────────────────

    public function testPostReturnsId(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'Hello!');
        $this->assertGreaterThan(0, $id);
    }

    public function testPostCreatesTopLevelComment(): void
    {
        $id  = $this->ct->post('post', '1', 'user-1', 'Hello!');
        $row = $this->ct->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['parent_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Hello!', $row['body']);
    }

    public function testPostThrowsOnEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->post('post', '1', 'user-1', '');
    }

    public function testPostThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->post('', '1', 'user-1', 'Hello');
    }

    // ── reply ─────────────────────────────────────────────────────────────────

    public function testReplyLinksToParent(): void
    {
        $parentId = $this->ct->post('post', '1', 'user-1', 'Parent');
        $replyId  = $this->ct->reply('post', '1', 'user-2', 'Reply', $parentId);
        $row      = $this->ct->get($replyId);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame((string)$parentId, (string)$row['parent_id']);
    }

    public function testReplyThrowsIfParentNotExists(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->ct->reply('post', '1', 'user-1', 'Reply', 999);
    }

    public function testReplyThrowsIfParentIsDeleted(): void
    {
        $parentId = $this->ct->post('post', '1', 'user-1', 'Parent');
        $this->ct->delete($parentId);
        $this->expectException(\RuntimeException::class);
        $this->ct->reply('post', '1', 'user-2', 'Reply', $parentId);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditUpdatesBody(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'Original');
        $this->assertTrue($this->ct->edit($id, 'user-1', 'Updated'));
        $row = $this->ct->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Updated', $row['body']);
    }

    public function testEditReturnsFalseForWrongUser(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'Original');
        $this->assertFalse($this->ct->edit($id, 'user-2', 'Hijack'));
    }

    public function testEditThrowsOnEmptyBody(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'Original');
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->edit($id, 'user-1', '');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteReplacesBodyWithTombstone(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'Original');
        $this->assertTrue($this->ct->delete($id, 'user-1'));
        $row = $this->ct->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('[deleted]', $row['body']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['deleted_at']);
    }

    public function testDeleteReturnsFalseForWrongUser(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'Original');
        $this->assertFalse($this->ct->delete($id, 'user-2'));
    }

    public function testDeleteWithNullUserIdForceDeletes(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'Original');
        $this->assertTrue($this->ct->delete($id, null));
    }

    public function testDeleteIsIdempotentReturnsFalseSecondTime(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'Original');
        $this->ct->delete($id);
        $this->assertFalse($this->ct->delete($id));
    }

    // ── thread ────────────────────────────────────────────────────────────────

    public function testThreadReturnsAllComments(): void
    {
        $this->ct->post('post', '1', 'user-1', 'A');
        $this->ct->post('post', '1', 'user-2', 'B');
        $this->assertCount(2, $this->ct->thread('post', '1'));
    }

    public function testThreadIncludesDeletedAsTombstone(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'A');
        $this->ct->delete($id);
        $this->assertCount(1, $this->ct->thread('post', '1'));
    }

    public function testThreadIsEntityScoped(): void
    {
        $this->ct->post('post', '1', 'user-1', 'A');
        $this->ct->post('post', '2', 'user-1', 'B');
        $this->assertCount(1, $this->ct->thread('post', '1'));
    }

    // ── topLevel / replies ────────────────────────────────────────────────────

    public function testTopLevelExcludesReplies(): void
    {
        $parentId = $this->ct->post('post', '1', 'user-1', 'Parent');
        $this->ct->reply('post', '1', 'user-2', 'Reply', $parentId);
        $this->assertCount(1, $this->ct->topLevel('post', '1'));
    }

    public function testRepliesReturnsChildComments(): void
    {
        $parentId = $this->ct->post('post', '1', 'user-1', 'Parent');
        $this->ct->reply('post', '1', 'user-2', 'Reply 1', $parentId);
        $this->ct->reply('post', '1', 'user-3', 'Reply 2', $parentId);
        $this->assertCount(2, $this->ct->replies($parentId));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountExcludesDeletedComments(): void
    {
        $id = $this->ct->post('post', '1', 'user-1', 'A');
        $this->ct->post('post', '1', 'user-2', 'B');
        $this->ct->delete($id);
        $this->assertSame(1, $this->ct->count('post', '1'));
    }
}
