<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ChangeLog;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChangeLog.
 */
final class ChangeLogTest extends TestCase
{
    private PDO $db;
    private ChangeLog $cl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE change_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                action      VARCHAR(100) NOT NULL,
                actor_id    VARCHAR(255) NOT NULL DEFAULT \'\',
                summary     TEXT         NOT NULL DEFAULT \'\',
                before_data TEXT         NOT NULL DEFAULT \'\',
                after_data  TEXT         NOT NULL DEFAULT \'\',
                changed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cl = new ChangeLog($this->db);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->cl->record('post', '1', 'created');
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresSummaryAndActor(): void
    {
        $this->cl->record('post', '1', 'updated', 'user-1', 'Changed title');
        $rows = $this->cl->history('post', '1');
        $this->assertCount(1, $rows);
        $this->assertSame('user-1', $rows[0]['actor_id']);
        $this->assertSame('Changed title', $rows[0]['summary']);
    }

    public function testRecordStoresBeforeAfterAsJson(): void
    {
        $this->cl->record('post', '1', 'updated', '', '', ['title' => 'Old'], ['title' => 'New']);
        $rows = $this->cl->history('post', '1');
        $this->assertStringContainsString('Old', $rows[0]['before_data']);
        $this->assertStringContainsString('New', $rows[0]['after_data']);
    }

    public function testRecordThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->record('', '1', 'created');
    }

    public function testRecordThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->record('post', '', 'created');
    }

    public function testRecordThrowsOnEmptyAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->record('post', '1', '');
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsOldestFirst(): void
    {
        $this->cl->record('post', '1', 'created');
        $this->cl->record('post', '1', 'updated');
        $this->cl->record('post', '1', 'published');
        $rows = $this->cl->history('post', '1');
        $this->assertCount(3, $rows);
        $this->assertSame('created', $rows[0]['action']);
        $this->assertSame('published', $rows[2]['action']);
    }

    public function testHistoryReturnsEmptyForUnknownEntity(): void
    {
        $this->assertSame([], $this->cl->history('post', '999'));
    }

    public function testHistoryIsScopedToEntity(): void
    {
        $this->cl->record('post', '1', 'created');
        $this->cl->record('post', '2', 'created');
        $this->assertCount(1, $this->cl->history('post', '1'));
        $this->assertCount(1, $this->cl->history('post', '2'));
    }

    // ── latest ────────────────────────────────────────────────────────────────

    public function testLatestReturnsNewestEntry(): void
    {
        $this->cl->record('post', '1', 'created');
        $this->cl->record('post', '1', 'updated');
        $row = $this->cl->latest('post', '1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('updated', $row['action']);
    }

    public function testLatestReturnsNullForUnknownEntity(): void
    {
        $this->assertNull($this->cl->latest('post', '999'));
    }

    // ── recentByActor ─────────────────────────────────────────────────────────

    public function testRecentByActorReturnsNewestFirst(): void
    {
        $this->cl->record('post', '1', 'created', 'user-1');
        $this->cl->record('post', '2', 'created', 'user-1');
        $this->cl->record('post', '3', 'created', 'user-2');
        $rows = $this->cl->recentByActor('user-1');
        $this->assertCount(2, $rows);
        // Newest first
        $this->assertGreaterThan((int)$rows[1]['id'], (int)$rows[0]['id']);
    }

    public function testRecentByActorReturnsEmptyForUnknownActor(): void
    {
        $this->assertSame([], $this->cl->recentByActor('unknown'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsCorrectNumber(): void
    {
        $this->cl->record('post', '1', 'created');
        $this->cl->record('post', '1', 'updated');
        $this->cl->record('post', '2', 'created');
        $this->assertSame(2, $this->cl->count('post', '1'));
        $this->assertSame(1, $this->cl->count('post', '2'));
        $this->assertSame(0, $this->cl->count('post', '999'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldEntries(): void
    {
        $old = (new \DateTimeImmutable())->modify('-2 days')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO change_log (entity_type, entity_id, action, changed_at)
                         VALUES ('post', '1', 'created', '{$old}')");
        $this->cl->record('post', '1', 'updated'); // recent

        $deleted = $this->cl->purgeOlderThan(1);
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->cl->count('post', '1'));
    }
}
