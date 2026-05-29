<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\EntityComment;
use PDO;
use PHPUnit\Framework\TestCase;

final class EntityCommentTest extends TestCase
{
    private PDO $pdo;
    private EntityComment $ec;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE entity_comments (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type  VARCHAR(100) NOT NULL,
                entity_id    VARCHAR(255) NOT NULL,
                author_id    VARCHAR(255) NOT NULL,
                body         TEXT         NOT NULL,
                edited_at    DATETIME     NULL,
                deleted_at   DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ec = new EntityComment($this->pdo);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddReturnsId(): void
    {
        $id = $this->ec->add('article', '1', 'user-1', 'Hello!');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddStoresCorrectly(): void
    {
        $id  = $this->ec->add('article', '1', 'user-1', 'Hello!');
        $row = $this->ec->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('article', $row['entity_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1', $row['entity_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['author_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Hello!', $row['body']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['deleted_at']);
    }

    public function testAddThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ec->add('', '1', 'user-1', 'body');
    }

    public function testAddThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ec->add('article', '', 'user-1', 'body');
    }

    public function testAddThrowsOnEmptyAuthorId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ec->add('article', '1', '', 'body');
    }

    public function testAddThrowsOnEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ec->add('article', '1', 'user-1', '');
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->ec->find(9999));
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditUpdatesBody(): void
    {
        $id     = $this->ec->add('article', '1', 'user-1', 'Original');
        $result = $this->ec->edit($id, 'user-1', 'Updated body');
        $this->assertTrue($result);

        $row = $this->ec->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Updated body', $row['body']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['edited_at']);
    }

    public function testEditReturnsFalseForWrongAuthor(): void
    {
        $id = $this->ec->add('article', '1', 'user-1', 'body');
        $this->assertFalse($this->ec->edit($id, 'user-2', 'hacked'));
    }

    public function testEditReturnsFalseForDeletedComment(): void
    {
        $id = $this->ec->add('article', '1', 'user-1', 'body');
        $this->ec->delete($id, 'user-1');
        $this->assertFalse($this->ec->edit($id, 'user-1', 'new body'));
    }

    public function testEditThrowsOnEmptyBody(): void
    {
        $id = $this->ec->add('article', '1', 'user-1', 'body');
        $this->expectException(\InvalidArgumentException::class);
        $this->ec->edit($id, 'user-1', '');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteSoftDeletesComment(): void
    {
        $id     = $this->ec->add('article', '1', 'user-1', 'body');
        $result = $this->ec->delete($id, 'user-1');
        $this->assertTrue($result);

        $row = $this->ec->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['deleted_at']);
    }

    public function testDeleteReturnsFalseForWrongAuthor(): void
    {
        $id = $this->ec->add('article', '1', 'user-1', 'body');
        $this->assertFalse($this->ec->delete($id, 'user-2'));
    }

    public function testDeleteReturnsFalseWhenAlreadyDeleted(): void
    {
        $id = $this->ec->add('article', '1', 'user-1', 'body');
        $this->ec->delete($id, 'user-1');
        $this->assertFalse($this->ec->delete($id, 'user-1'));
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    public function testPurgeHardDeletesRow(): void
    {
        $id = $this->ec->add('article', '1', 'user-1', 'body');
        $this->assertTrue($this->ec->purge($id));
        $this->assertNull($this->ec->find($id));
    }

    public function testPurgeReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->ec->purge(9999));
    }

    // ── forEntity ─────────────────────────────────────────────────────────────

    public function testForEntityReturnsOldestFirst(): void
    {
        $id1 = $this->ec->add('article', '1', 'user-1', 'First');
        $id2 = $this->ec->add('article', '1', 'user-2', 'Second');
        $list = $this->ec->forEntity('article', '1');
        $this->assertSame($id1, (int)$list[0]['id']);
        $this->assertSame($id2, (int)$list[1]['id']);
    }

    public function testForEntityExcludesDeleted(): void
    {
        $id1 = $this->ec->add('article', '1', 'user-1', 'A');
        $id2 = $this->ec->add('article', '1', 'user-1', 'B');
        $this->ec->delete($id1, 'user-1');
        $list = $this->ec->forEntity('article', '1');
        $this->assertCount(1, $list);
        $this->assertSame($id2, (int)$list[0]['id']);
    }

    public function testForEntityIsIsolatedByEntity(): void
    {
        $this->ec->add('article', '1', 'user-1', 'A');
        $this->ec->add('article', '2', 'user-1', 'B');
        $this->assertCount(1, $this->ec->forEntity('article', '1'));
    }

    public function testForEntityReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ec->forEntity('article', '99'));
    }

    // ── countForEntity ────────────────────────────────────────────────────────

    public function testCountForEntityExcludesDeleted(): void
    {
        $id = $this->ec->add('article', '1', 'user-1', 'A');
        $this->ec->add('article', '1', 'user-2', 'B');
        $this->ec->delete($id, 'user-1');
        $this->assertSame(1, $this->ec->countForEntity('article', '1'));
    }

    // ── byUser ────────────────────────────────────────────────────────────────

    public function testByUserReturnsNonDeletedComments(): void
    {
        $id1 = $this->ec->add('article', '1', 'user-1', 'A');
        $this->ec->add('article', '2', 'user-1', 'B');
        $this->ec->delete($id1, 'user-1');
        $list = $this->ec->byUser('user-1');
        $this->assertCount(1, $list);
    }

    public function testByUserReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ec->byUser('nobody'));
    }
}
