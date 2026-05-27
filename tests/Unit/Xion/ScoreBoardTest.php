<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ScoreBoard;
use PDO;
use PHPUnit\Framework\TestCase;

final class ScoreBoardTest extends TestCase
{
    private PDO $pdo;
    private ScoreBoard $sb;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE score_board (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                category   VARCHAR(100) NOT NULL,
                score      INTEGER      NOT NULL,
                period     VARCHAR(20)  NOT NULL DEFAULT \'\',
                meta       TEXT         NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->sb = new ScoreBoard($this->pdo);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitReturnsId(): void
    {
        $id = $this->sb->submit('user-1', 'arcade', 5000);
        $this->assertGreaterThan(0, $id);
    }

    public function testSubmitThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sb->submit('', 'arcade', 5000);
    }

    public function testSubmitThrowsOnEmptyCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sb->submit('user-1', '', 5000);
    }

    public function testSubmitStoresMeta(): void
    {
        $id  = $this->sb->submit('user-1', 'arcade', 5000, '', ['level' => 3]);
        $row = $this->pdo->query("SELECT meta FROM score_board WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertStringContainsString('level', $row['meta']);
    }

    // ── top ───────────────────────────────────────────────────────────────────

    public function testTopReturnsPersonalBestPerPlayer(): void
    {
        $this->sb->submit('user-1', 'arcade', 5000);
        $this->sb->submit('user-1', 'arcade', 8000);
        $this->sb->submit('user-2', 'arcade', 6000);

        $top = $this->sb->top('arcade');
        $this->assertCount(2, $top);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(8000, (int)$top[0]['best_score']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $top[0]['user_id']);
    }

    public function testTopRespectsLimit(): void
    {
        $this->sb->submit('user-1', 'arcade', 9000);
        $this->sb->submit('user-2', 'arcade', 8000);
        $this->sb->submit('user-3', 'arcade', 7000);

        $top = $this->sb->top('arcade', 2);
        $this->assertCount(2, $top);
    }

    public function testTopIsIsolatedByCategory(): void
    {
        $this->sb->submit('user-1', 'arcade', 9000);
        $this->sb->submit('user-1', 'puzzle', 3000);

        $this->assertCount(1, $this->sb->top('arcade'));
        $this->assertCount(1, $this->sb->top('puzzle'));
    }

    public function testTopIsIsolatedByPeriod(): void
    {
        $this->sb->submit('user-1', 'arcade', 9000, '2026-W01');
        $this->sb->submit('user-1', 'arcade', 7000, '2026-W02');

        $this->assertCount(1, $this->sb->top('arcade', 10, '2026-W01'));
        $this->assertCount(0, $this->sb->top('arcade', 10, '2026-W03'));
    }

    public function testTopReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->sb->top('unknown'));
    }

    // ── personalBest ──────────────────────────────────────────────────────────

    public function testPersonalBestReturnsHighest(): void
    {
        $this->sb->submit('user-1', 'arcade', 5000);
        $this->sb->submit('user-1', 'arcade', 9000);
        $this->sb->submit('user-1', 'arcade', 7000);
        $this->assertSame(9000, $this->sb->personalBest('user-1', 'arcade'));
    }

    public function testPersonalBestReturnsNullWhenNone(): void
    {
        $this->assertNull($this->sb->personalBest('user-1', 'arcade'));
    }

    public function testPersonalBestIsIsolatedByPeriod(): void
    {
        $this->sb->submit('user-1', 'arcade', 9000, '2026-W01');
        $this->assertNull($this->sb->personalBest('user-1', 'arcade', '2026-W02'));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsNewestFirst(): void
    {
        $id1 = $this->sb->submit('user-1', 'arcade', 5000);
        $id2 = $this->sb->submit('user-1', 'arcade', 8000);
        $list = $this->sb->history('user-1', 'arcade');
        $this->assertCount(2, $list);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id2, (int)$list[0]['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id1, (int)$list[1]['id']);
    }

    public function testHistoryRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->sb->submit('user-1', 'arcade', $i * 1000);
        }
        $this->assertCount(3, $this->sb->history('user-1', 'arcade', 3));
    }

    public function testHistoryIsIsolatedByUser(): void
    {
        $this->sb->submit('user-1', 'arcade', 5000);
        $this->sb->submit('user-2', 'arcade', 6000);
        $this->assertCount(1, $this->sb->history('user-1', 'arcade'));
    }

    public function testHistoryReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->sb->history('user-1', 'arcade'));
    }

    // ── rank ──────────────────────────────────────────────────────────────────

    public function testRankReturnsOneForTopPlayer(): void
    {
        $this->sb->submit('user-1', 'arcade', 9000);
        $this->sb->submit('user-2', 'arcade', 7000);
        $this->assertSame(1, $this->sb->rank('user-1', 'arcade'));
    }

    public function testRankReturnsTwoForSecondPlayer(): void
    {
        $this->sb->submit('user-1', 'arcade', 9000);
        $this->sb->submit('user-2', 'arcade', 7000);
        $this->assertSame(2, $this->sb->rank('user-2', 'arcade'));
    }

    public function testRankReturnsNullWhenNoScores(): void
    {
        $this->assertNull($this->sb->rank('user-1', 'arcade'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsSubmissionCount(): void
    {
        $this->sb->submit('user-1', 'arcade', 5000);
        $this->sb->submit('user-1', 'arcade', 8000);
        $this->sb->submit('user-2', 'arcade', 6000);
        $this->assertSame(3, $this->sb->count('arcade'));
    }

    public function testCountIsIsolatedByPeriod(): void
    {
        $this->sb->submit('user-1', 'arcade', 5000, '2026-W01');
        $this->sb->submit('user-1', 'arcade', 8000, '2026-W02');
        $this->assertSame(1, $this->sb->count('arcade', '2026-W01'));
        $this->assertSame(0, $this->sb->count('arcade', '2026-W03'));
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetDeletesPlayerScores(): void
    {
        $this->sb->submit('user-1', 'arcade', 5000);
        $this->sb->submit('user-1', 'arcade', 8000);
        $this->sb->submit('user-2', 'arcade', 6000);
        $deleted = $this->sb->reset('user-1', 'arcade');
        $this->assertSame(2, $deleted);
        $this->assertNull($this->sb->personalBest('user-1', 'arcade'));
        $this->assertNotNull($this->sb->personalBest('user-2', 'arcade'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldRows(): void
    {
        $this->pdo->exec(
            "INSERT INTO score_board (user_id, category, score, period, created_at)
             VALUES ('user-1', 'arcade', 5000, '', '2020-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO score_board (user_id, category, score, period, created_at)
             VALUES ('user-2', 'arcade', 6000, '', '2025-01-01 00:00:00')"
        );
        $deleted = $this->sb->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->sb->count('arcade'));
    }
}
