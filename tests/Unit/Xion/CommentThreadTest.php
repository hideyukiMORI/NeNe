<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\CommentThread;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CommentThread using an in-memory SQLite database.
 */
final class CommentThreadTest extends TestCase
{
    private PDO $db;
    private CommentThread $ct;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE comments (
                id          INTEGER      PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(64)  NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                parent_id   INTEGER      DEFAULT NULL,
                author_id   VARCHAR(255),
                body        TEXT         NOT NULL,
                depth       INTEGER      NOT NULL DEFAULT 0,
                deleted_at  DATETIME     DEFAULT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->ct = new CommentThread($this->db);
    }

    // ── post ──────────────────────────────────────────────────────────────────

    public function testPostReturnsId(): void
    {
        $id = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testPostedCommentAppearsInList(): void
    {
        $this->ct->post('article', '1', 'user:1', 'Hello!');
        $list = $this->ct->list('article', '1');
        $this->assertCount(1, $list);
        $this->assertSame('Hello!', $list[0]['body']);
    }

    public function testPostedCommentHasDepthZero(): void
    {
        $id = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $list = $this->ct->list('article', '1');
        $this->assertSame(0, $list[0]['depth']);
    }

    public function testPostEmptyBodyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->post('article', '1', 'user:1', '');
    }

    // ── reply ─────────────────────────────────────────────────────────────────

    public function testReplyReturnsId(): void
    {
        $parentId = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $replyId  = $this->ct->reply($parentId, 'user:2', 'Thanks!');
        $this->assertIsInt($replyId);
        $this->assertNotSame($parentId, $replyId);
    }

    public function testReplyHasDepthOneMoreThanParent(): void
    {
        $parentId = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->ct->reply($parentId, 'user:2', 'Thanks!');
        $list = $this->ct->list('article', '1');
        $this->assertSame(1, $list[1]['depth']);
    }

    public function testReplyHasParentIdSet(): void
    {
        $parentId = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $replyId  = $this->ct->reply($parentId, 'user:2', 'Thanks!');
        $list     = $this->ct->list('article', '1');
        $reply    = array_values(array_filter($list, fn ($c) => $c['id'] === $replyId))[0];
        $this->assertSame($parentId, $reply['parent_id']);
    }

    public function testReplyToNonexistentParentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->reply(9999, 'user:1', 'Hello!');
    }

    public function testReplyEmptyBodyThrows(): void
    {
        $parentId = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->reply($parentId, 'user:2', '   ');
    }

    public function testReplyMaxDepthEnforced(): void
    {
        // With maxDepth=2, depth 0→1→2 is allowed, depth 3 is not
        $ct = new CommentThread($this->db, maxDepth: 2);
        $d0 = $ct->post('article', '1', 'user:1', 'depth 0');
        $d1 = $ct->reply($d0, 'user:1', 'depth 1');
        $d2 = $ct->reply($d1, 'user:1', 'depth 2');
        $this->expectException(\InvalidArgumentException::class);
        $ct->reply($d2, 'user:1', 'depth 3 — too deep');
    }

    // ── softDelete ────────────────────────────────────────────────────────────

    public function testSoftDeleteReturnsTrueByAuthor(): void
    {
        $id = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->assertTrue($this->ct->softDelete($id, 'user:1'));
    }

    public function testSoftDeleteReturnsFalseByNonAuthor(): void
    {
        $id = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->assertFalse($this->ct->softDelete($id, 'user:2'));
    }

    public function testSoftDeleteReplacesBody(): void
    {
        $id = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->ct->softDelete($id, 'user:1');
        $list = $this->ct->list('article', '1');
        $this->assertSame('[deleted]', $list[0]['body']);
    }

    public function testSoftDeleteNullsAuthorId(): void
    {
        $id = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->ct->softDelete($id, 'user:1');
        $list = $this->ct->list('article', '1');
        $this->assertNull($list[0]['author_id']);
    }

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $id = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->ct->softDelete($id, 'user:1');
        $list = $this->ct->list('article', '1');
        $this->assertNotNull($list[0]['deleted_at']);
    }

    public function testSoftDeleteAlreadyDeletedReturnsFalse(): void
    {
        $id = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->ct->softDelete($id, 'user:1');
        $this->assertFalse($this->ct->softDelete($id, 'user:1'));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsAllCommentsIncludingDeleted(): void
    {
        $id = $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->ct->softDelete($id, 'user:1');
        $this->assertCount(1, $this->ct->list('article', '1'));
    }

    public function testListIsEntityTypeIsolated(): void
    {
        $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->assertEmpty($this->ct->list('product', '1'));
    }

    public function testListIsEntityIdIsolated(): void
    {
        $this->ct->post('article', '1', 'user:1', 'Hello!');
        $this->assertEmpty($this->ct->list('article', '2'));
    }

    public function testListOrdersByIdAscending(): void
    {
        $this->ct->post('article', '1', 'user:1', 'first');
        $this->ct->post('article', '1', 'user:2', 'second');
        $list = $this->ct->list('article', '1');
        $this->assertSame('first', $list[0]['body']);
        $this->assertSame('second', $list[1]['body']);
    }
}
