<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\DailyStreak;
use PDO;
use PHPUnit\Framework\TestCase;

final class DailyStreakTest extends TestCase
{
    private PDO $pdo;
    private DailyStreak $ds;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE daily_streaks (
                user_id           VARCHAR(255) PRIMARY KEY,
                current_streak    INT          NOT NULL DEFAULT 0,
                longest_streak    INT          NOT NULL DEFAULT 0,
                last_checked_in   DATE         NULL,
                created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ds = new DailyStreak($this->pdo);
    }

    // ── checkin ───────────────────────────────────────────────────────────────

    public function testCheckinCreatesRowOnFirstVisit(): void
    {
        $result = $this->ds->checkin('user-1', '2026-05-01');
        $this->assertTrue($result);
        $this->assertSame(1, $this->ds->currentStreak('user-1'));
    }

    public function testCheckinIdempotentSameDay(): void
    {
        $this->ds->checkin('user-1', '2026-05-01');
        $result = $this->ds->checkin('user-1', '2026-05-01');
        $this->assertFalse($result);
        $this->assertSame(1, $this->ds->currentStreak('user-1'));
    }

    public function testCheckinIncrementsOnConsecutiveDay(): void
    {
        $this->ds->checkin('user-1', '2026-05-01');
        $this->ds->checkin('user-1', '2026-05-02');
        $this->assertSame(2, $this->ds->currentStreak('user-1'));
    }

    public function testCheckinResetsOnGap(): void
    {
        $this->ds->checkin('user-1', '2026-05-01');
        $this->ds->checkin('user-1', '2026-05-02');
        $this->ds->checkin('user-1', '2026-05-05'); // 3-day gap
        $this->assertSame(1, $this->ds->currentStreak('user-1'));
    }

    public function testCheckinThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ds->checkin('');
    }

    // ── longestStreak ─────────────────────────────────────────────────────────

    public function testLongestStreakTracked(): void
    {
        $this->ds->checkin('user-1', '2026-05-01');
        $this->ds->checkin('user-1', '2026-05-02');
        $this->ds->checkin('user-1', '2026-05-03'); // streak = 3
        $this->ds->checkin('user-1', '2026-05-10'); // gap, streak resets to 1
        $this->assertSame(1, $this->ds->currentStreak('user-1'));
        $this->assertSame(3, $this->ds->longestStreak('user-1'));
    }

    public function testLongestStreakEqualsCurrentWhenGrowing(): void
    {
        $this->ds->checkin('user-1', '2026-05-01');
        $this->ds->checkin('user-1', '2026-05-02');
        $this->assertSame(2, $this->ds->longestStreak('user-1'));
    }

    // ── get / currentStreak / longestStreak / lastCheckedIn ───────────────────

    public function testGetReturnsNullForUnknownUser(): void
    {
        $this->assertNull($this->ds->get('unknown'));
    }

    public function testCurrentStreakZeroForUnknownUser(): void
    {
        $this->assertSame(0, $this->ds->currentStreak('unknown'));
    }

    public function testLongestStreakZeroForUnknownUser(): void
    {
        $this->assertSame(0, $this->ds->longestStreak('unknown'));
    }

    public function testLastCheckedInNullForUnknownUser(): void
    {
        $this->assertNull($this->ds->lastCheckedIn('unknown'));
    }

    public function testLastCheckedInReturnsDate(): void
    {
        $this->ds->checkin('user-1', '2026-05-28');
        $this->assertSame('2026-05-28', $this->ds->lastCheckedIn('user-1'));
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetSetsCurrentToZero(): void
    {
        $this->ds->checkin('user-1', '2026-05-01');
        $this->ds->checkin('user-1', '2026-05-02');
        $this->ds->reset('user-1');
        $this->assertSame(0, $this->ds->currentStreak('user-1'));
    }

    public function testResetPreservesLongestStreak(): void
    {
        $this->ds->checkin('user-1', '2026-05-01');
        $this->ds->checkin('user-1', '2026-05-02');
        $this->ds->reset('user-1');
        $this->assertSame(2, $this->ds->longestStreak('user-1'));
    }

    public function testResetReturnsFalseForUnknownUser(): void
    {
        $this->assertFalse($this->ds->reset('unknown'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesRow(): void
    {
        $this->ds->checkin('user-1', '2026-05-01');
        $this->assertTrue($this->ds->remove('user-1'));
        $this->assertNull($this->ds->get('user-1'));
    }

    public function testRemoveReturnsFalseForUnknownUser(): void
    {
        $this->assertFalse($this->ds->remove('unknown'));
    }
}
