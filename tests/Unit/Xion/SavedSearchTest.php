<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\SavedSearch;
use PDO;
use PHPUnit\Framework\TestCase;

final class SavedSearchTest extends TestCase
{
    private PDO $pdo;
    private SavedSearch $ss;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE saved_searches (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                name       VARCHAR(255) NOT NULL,
                query      TEXT         NOT NULL,
                scope      VARCHAR(100) NOT NULL DEFAULT \'\',
                used_count INTEGER      NOT NULL DEFAULT 0,
                last_used  DATETIME     NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, scope, name)
            )
        ');
        $this->ss = new SavedSearch($this->pdo);
    }

    // ── save ──────────────────────────────────────────────────────────────────

    public function testSaveReturnsId(): void
    {
        $id = $this->ss->save('user-1', 'My Search', '{"q":"php"}');
        $this->assertGreaterThan(0, $id);
    }

    public function testSaveStoresFields(): void
    {
        $id  = $this->ss->save('user-1', 'Open Issues', '{"status":"open"}', 'issues');
        $row = $this->ss->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Open Issues', $row['name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('{"status":"open"}', $row['query']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('issues', $row['scope']);
    }

    public function testSaveUpdatesQueryOnDuplicate(): void
    {
        $id1 = $this->ss->save('user-1', 'My Search', '{"q":"old"}', 'posts');
        $id2 = $this->ss->save('user-1', 'My Search', '{"q":"new"}', 'posts');
        $this->assertSame($id1, $id2);

        $row = $this->ss->find($id1);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('{"q":"new"}', $row['query']);
    }

    public function testSaveThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->save('', 'Name', '{"q":"x"}');
    }

    public function testSaveThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->save('user-1', '', '{"q":"x"}');
    }

    public function testSaveThrowsOnEmptyQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->save('user-1', 'Name', '');
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->ss->find(9999));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsAllForUser(): void
    {
        $this->ss->save('user-1', 'A', '{"q":"a"}');
        $this->ss->save('user-1', 'B', '{"q":"b"}');
        $this->ss->save('user-2', 'C', '{"q":"c"}');

        $rows = $this->ss->forUser('user-1');
        $this->assertCount(2, $rows);
    }

    public function testForUserFiltersByScope(): void
    {
        $this->ss->save('user-1', 'A', '{"q":"a"}', 'posts');
        $this->ss->save('user-1', 'B', '{"q":"b"}', 'issues');

        $rows = $this->ss->forUser('user-1', 'posts');
        $this->assertCount(1, $rows);
        $this->assertSame('A', $rows[0]['name']);
    }

    public function testForUserReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ss->forUser('user-99'));
    }

    public function testForUserThrowsOnEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->forUser('');
    }

    // ── recordUse ─────────────────────────────────────────────────────────────

    public function testRecordUseIncrementsCounter(): void
    {
        $id = $this->ss->save('user-1', 'Search', '{"q":"x"}');
        $this->ss->recordUse($id);
        $this->ss->recordUse($id);

        $row = $this->ss->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['used_count']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['last_used']);
    }

    public function testRecordUseReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->ss->recordUse(9999));
    }

    // ── rename ────────────────────────────────────────────────────────────────

    public function testRenameChangesName(): void
    {
        $id = $this->ss->save('user-1', 'Old Name', '{"q":"x"}');
        $result = $this->ss->rename($id, 'user-1', 'New Name');
        $this->assertTrue($result);

        $row = $this->ss->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('New Name', $row['name']);
    }

    public function testRenameReturnsFalseForWrongOwner(): void
    {
        $id = $this->ss->save('user-1', 'Search', '{"q":"x"}');
        $this->assertFalse($this->ss->rename($id, 'user-2', 'Hacked'));
    }

    public function testRenameThrowsOnEmptyName(): void
    {
        $id = $this->ss->save('user-1', 'Search', '{"q":"x"}');
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->rename($id, 'user-1', '');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRow(): void
    {
        $id = $this->ss->save('user-1', 'Search', '{"q":"x"}');
        $result = $this->ss->delete($id, 'user-1');
        $this->assertTrue($result);
        $this->assertNull($this->ss->find($id));
    }

    public function testDeleteReturnsFalseForWrongOwner(): void
    {
        $id = $this->ss->save('user-1', 'Search', '{"q":"x"}');
        $this->assertFalse($this->ss->delete($id, 'user-2'));
    }

    // ── deleteAll ─────────────────────────────────────────────────────────────

    public function testDeleteAllRemovesAllForUser(): void
    {
        $this->ss->save('user-1', 'A', '{"q":"a"}');
        $this->ss->save('user-1', 'B', '{"q":"b"}');
        $this->ss->save('user-2', 'C', '{"q":"c"}');

        $n = $this->ss->deleteAll('user-1');
        $this->assertSame(2, $n);
        $this->assertSame([], $this->ss->forUser('user-1'));
        $this->assertCount(1, $this->ss->forUser('user-2'));
    }

    public function testDeleteAllThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->deleteAll('');
    }
}
