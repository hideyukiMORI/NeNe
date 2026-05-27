<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\LeaderBoard;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LeaderBoard.
 */
final class LeaderBoardTest extends TestCase
{
    private PDO $db;
    private LeaderBoard $lb;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE leaderboard (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                board_name VARCHAR(100) NOT NULL,
                user_id    VARCHAR(255) NOT NULL,
                score      BIGINT       NOT NULL DEFAULT 0,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (board_name, user_id)
            )
        ');
        $this->lb = new LeaderBoard($this->db);
    }

    // ── setScore ──────────────────────────────────────────────────────────────

    public function testSetScoreCreatesEntry(): void
    {
        $this->lb->setScore('alltime', 'user-1', 100);
        $this->assertSame(100, $this->lb->score('alltime', 'user-1'));
    }

    public function testSetScoreUpdatesExisting(): void
    {
        $this->lb->setScore('alltime', 'user-1', 100);
        $this->lb->setScore('alltime', 'user-1', 200);
        $this->assertSame(200, $this->lb->score('alltime', 'user-1'));
    }

    public function testSetScoreIsBoardScoped(): void
    {
        $this->lb->setScore('board-a', 'user-1', 100);
        $this->lb->setScore('board-b', 'user-1', 999);
        $this->assertSame(100, $this->lb->score('board-a', 'user-1'));
    }

    public function testSetScoreThrowsOnEmptyBoardName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lb->setScore('', 'user-1', 100);
    }

    public function testSetScoreThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lb->setScore('alltime', '', 100);
    }

    // ── increment ─────────────────────────────────────────────────────────────

    public function testIncrementCreatesEntry(): void
    {
        $this->lb->increment('alltime', 'user-1', 10);
        $this->assertSame(10, $this->lb->score('alltime', 'user-1'));
    }

    public function testIncrementAddsToExisting(): void
    {
        $this->lb->setScore('alltime', 'user-1', 100);
        $this->lb->increment('alltime', 'user-1', 50);
        $this->assertSame(150, $this->lb->score('alltime', 'user-1'));
    }

    public function testIncrementCanDecrement(): void
    {
        $this->lb->setScore('alltime', 'user-1', 100);
        $this->lb->increment('alltime', 'user-1', -30);
        $this->assertSame(70, $this->lb->score('alltime', 'user-1'));
    }

    // ── top ───────────────────────────────────────────────────────────────────

    public function testTopReturnsHighestScoresFirst(): void
    {
        $this->lb->setScore('b', 'user-1', 100);
        $this->lb->setScore('b', 'user-2', 300);
        $this->lb->setScore('b', 'user-3', 200);
        $top = $this->lb->top('b', 3);
        $this->assertSame('user-2', $top[0]['user_id']);
        $this->assertSame('user-3', $top[1]['user_id']);
        $this->assertSame('user-1', $top[2]['user_id']);
    }

    public function testTopReturnsCorrectRanks(): void
    {
        $this->lb->setScore('b', 'user-1', 100);
        $this->lb->setScore('b', 'user-2', 200);
        $top = $this->lb->top('b', 2);
        $this->assertSame(1, $top[0]['rank']);
        $this->assertSame(2, $top[1]['rank']);
    }

    public function testTopRespectsLimit(): void
    {
        $this->lb->setScore('b', 'u1', 1);
        $this->lb->setScore('b', 'u2', 2);
        $this->lb->setScore('b', 'u3', 3);
        $this->assertCount(2, $this->lb->top('b', 2));
    }

    public function testTopReturnsEmptyForEmptyBoard(): void
    {
        $this->assertSame([], $this->lb->top('empty', 10));
    }

    // ── score ─────────────────────────────────────────────────────────────────

    public function testScoreReturnsNullForNonMember(): void
    {
        $this->assertNull($this->lb->score('alltime', 'nobody'));
    }

    // ── rank ──────────────────────────────────────────────────────────────────

    public function testRankReturnsCorrectRank(): void
    {
        $this->lb->setScore('b', 'user-1', 100);
        $this->lb->setScore('b', 'user-2', 200);
        $this->lb->setScore('b', 'user-3', 300);
        $r = $this->lb->rank('b', 'user-2');
        $this->assertNotNull($r);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, $r['rank']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(200, $r['score']);
    }

    public function testRankReturnsNullForNonMember(): void
    {
        $this->assertNull($this->lb->rank('alltime', 'nobody'));
    }

    public function testRankOneForTopUser(): void
    {
        $this->lb->setScore('b', 'user-1', 999);
        $r = $this->lb->rank('b', 'user-1');
        $this->assertNotNull($r);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, $r['rank']);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesEntry(): void
    {
        $this->lb->setScore('b', 'user-1', 100);
        $this->assertTrue($this->lb->remove('b', 'user-1'));
        $this->assertNull($this->lb->score('b', 'user-1'));
    }

    public function testRemoveReturnsFalseIfNotFound(): void
    {
        $this->assertFalse($this->lb->remove('b', 'nobody'));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearDeletesAllEntries(): void
    {
        $this->lb->setScore('b', 'u1', 1);
        $this->lb->setScore('b', 'u2', 2);
        $this->assertSame(2, $this->lb->clear('b'));
        $this->assertSame(0, $this->lb->count('b'));
    }

    public function testClearDoesNotAffectOtherBoards(): void
    {
        $this->lb->setScore('b1', 'u1', 1);
        $this->lb->setScore('b2', 'u1', 1);
        $this->lb->clear('b1');
        $this->assertSame(1, $this->lb->count('b2'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroForEmptyBoard(): void
    {
        $this->assertSame(0, $this->lb->count('empty'));
    }

    public function testCountReturnsCorrectCount(): void
    {
        $this->lb->setScore('b', 'u1', 1);
        $this->lb->setScore('b', 'u2', 2);
        $this->assertSame(2, $this->lb->count('b'));
    }
}
