<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\TimeEntry;
use PDO;
use PHPUnit\Framework\TestCase;

final class TimeEntryTest extends TestCase
{
    private PDO $pdo;
    private TimeEntry $te;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE time_entries (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                project_ref TEXT         NULL,
                description TEXT         NULL,
                started_at  DATETIME     NOT NULL,
                stopped_at  DATETIME     NULL,
                seconds     INTEGER      NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->te = new TimeEntry($this->pdo);
    }

    // ── start / stop ──────────────────────────────────────────────────────────

    public function testStartReturnsId(): void
    {
        $id = $this->te->start('u1', 'Working on FT202');
        $this->assertGreaterThan(0, $id);
    }

    public function testStartCreatesActiveTimer(): void
    {
        $id  = $this->te->start('u1');
        $row = $this->te->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['stopped_at']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['seconds']);
    }

    public function testStopSetsStoppedAtAndSeconds(): void
    {
        $id = $this->te->start('u1');
        $this->assertTrue($this->te->stop($id));

        $row = $this->te->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['stopped_at']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertGreaterThanOrEqual(0, (int)$row['seconds']);
    }

    public function testStopReturnsFalseForAlreadyStopped(): void
    {
        $id = $this->te->start('u1');
        $this->te->stop($id);
        $this->assertFalse($this->te->stop($id)); // already stopped
    }

    public function testStopReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->te->stop(9999));
    }

    public function testStartThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->te->start('');
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddManualEntryCalculatesSeconds(): void
    {
        $id = $this->te->add('u1', '2026-01-01 09:00:00', '2026-01-01 11:30:00', 'Design');
        $row = $this->te->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(9000, (int)$row['seconds']); // 2.5h = 9000s
    }

    public function testAddThrowsWhenEndBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->te->add('u1', '2026-01-01 11:00:00', '2026-01-01 09:00:00');
    }

    public function testAddThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->te->add('', '2026-01-01 09:00:00', '2026-01-01 10:00:00');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesEntry(): void
    {
        $id = $this->te->start('u1');
        $this->assertTrue($this->te->delete($id));
        $this->assertNull($this->te->find($id));
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->te->delete(9999));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsUsersEntries(): void
    {
        $this->te->add('u1', '2026-01-01 09:00:00', '2026-01-01 10:00:00');
        $this->te->add('u1', '2026-01-01 11:00:00', '2026-01-01 12:00:00');
        $this->te->add('u2', '2026-01-01 09:00:00', '2026-01-01 10:00:00');

        $entries = $this->te->forUser('u1');
        $this->assertCount(2, $entries);
    }

    public function testForUserFiltersByProject(): void
    {
        $this->te->add('u1', '2026-01-01 09:00:00', '2026-01-01 10:00:00', 'A', 'proj-1');
        $this->te->add('u1', '2026-01-01 11:00:00', '2026-01-01 12:00:00', 'B', 'proj-2');

        $entries = $this->te->forUser('u1', 'proj-1');
        $this->assertCount(1, $entries);
        $this->assertSame('A', $entries[0]['description']);
    }

    // ── totalSeconds ──────────────────────────────────────────────────────────

    public function testTotalSecondsSumsCompletedEntries(): void
    {
        $this->te->add('u1', '2026-01-01 09:00:00', '2026-01-01 10:00:00'); // 3600s
        $this->te->add('u1', '2026-01-01 11:00:00', '2026-01-01 12:30:00'); // 5400s
        $this->te->start('u1'); // active, not counted

        $this->assertSame(9000, $this->te->totalSeconds('u1'));
    }

    public function testTotalSecondsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->te->totalSeconds('u99'));
    }

    // ── activeTimer ───────────────────────────────────────────────────────────

    public function testActiveTimerReturnsRunningEntry(): void
    {
        $id = $this->te->start('u1', 'Active work');
        $active = $this->te->activeTimer('u1');
        $this->assertNotNull($active);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id, (int)$active['id']);
    }

    public function testActiveTimerReturnsNullWhenNone(): void
    {
        $this->assertNull($this->te->activeTimer('u1'));
    }

    public function testActiveTimerReturnsNullAfterStop(): void
    {
        $id = $this->te->start('u1');
        $this->te->stop($id);
        $this->assertNull($this->te->activeTimer('u1'));
    }
}
