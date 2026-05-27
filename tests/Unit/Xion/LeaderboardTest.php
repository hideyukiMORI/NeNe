<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Leaderboard;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Leaderboard using an in-memory SQLite database.
 */
final class LeaderboardTest extends TestCase
{
    private PDO $db;
    private Leaderboard $lb;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE leaderboard_scores (
                id             INTEGER      PRIMARY KEY AUTOINCREMENT,
                leaderboard_id VARCHAR(255) NOT NULL,
                user_id        VARCHAR(255) NOT NULL,
                score          INTEGER      NOT NULL,
                submitted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (leaderboard_id, user_id)
            )'
        );
        $this->lb = new Leaderboard($this->db);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitFirstScoreIsBest(): void
    {
        $result = $this->lb->submit('game:1', 'user:1', 1000);
        $this->assertTrue($result['is_best']);
        $this->assertSame(1000, $result['score']);
    }

    public function testSubmitHigherScoreIsBest(): void
    {
        $this->lb->submit('game:1', 'user:1', 1000);
        $result = $this->lb->submit('game:1', 'user:1', 2000);
        $this->assertTrue($result['is_best']);
        $this->assertSame(2000, $result['score']);
    }

    public function testSubmitLowerScoreIsNotBest(): void
    {
        $this->lb->submit('game:1', 'user:1', 1000);
        $result = $this->lb->submit('game:1', 'user:1', 500);
        $this->assertFalse($result['is_best']);
        $this->assertSame(1000, $result['score']); // still the previous best
    }

    public function testSubmitSameScoreIsNotBest(): void
    {
        $this->lb->submit('game:1', 'user:1', 1000);
        $result = $this->lb->submit('game:1', 'user:1', 1000);
        $this->assertFalse($result['is_best']);
    }

    public function testSubmitMultipleUsersOneRowEach(): void
    {
        $this->lb->submit('game:1', 'user:1', 1000);
        $this->lb->submit('game:1', 'user:2', 2000);
        $rankings = $this->lb->rankings('game:1');
        $this->assertCount(2, $rankings);
    }

    public function testSubmitIsolatedPerLeaderboard(): void
    {
        $this->lb->submit('game:1', 'user:1', 1000);
        $this->lb->submit('game:2', 'user:1', 9999);
        $this->assertSame(1000, $this->lb->rank('game:1', 'user:1')['score']);
        $this->assertSame(9999, $this->lb->rank('game:2', 'user:1')['score']);
    }

    // ── rankings ─────────────────────────────────────────────────────────────

    public function testRankingsReturnHighestFirst(): void
    {
        $this->lb->submit('game:1', 'user:1', 500);
        $this->lb->submit('game:1', 'user:2', 1000);
        $this->lb->submit('game:1', 'user:3', 750);

        $rankings = $this->lb->rankings('game:1');
        $this->assertSame(1000, $rankings[0]['score']);
        $this->assertSame(750, $rankings[1]['score']);
        $this->assertSame(500, $rankings[2]['score']);
    }

    public function testRankingsLimitClamped(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->lb->submit('game:1', "user:{$i}", $i * 100);
        }
        $rankings = $this->lb->rankings('game:1', limit: 3);
        $this->assertCount(3, $rankings);
    }

    public function testRankingsLimitClampedToMax(): void
    {
        // Max is 100 — requesting 999 should clamp
        for ($i = 1; $i <= 3; $i++) {
            $this->lb->submit('game:1', "user:{$i}", $i * 100);
        }
        $rankings = $this->lb->rankings('game:1', limit: 999);
        $this->assertCount(3, $rankings); // only 3 users
    }

    public function testRankingsTiedScoreSameRank(): void
    {
        $this->lb->submit('game:1', 'user:1', 1000);
        $this->lb->submit('game:1', 'user:2', 1000);
        $this->lb->submit('game:1', 'user:3', 500);

        $rankings = $this->lb->rankings('game:1');
        $this->assertSame(1, $rankings[0]['rank']); // user:1 or user:2
        $this->assertSame(1, $rankings[1]['rank']); // same rank for tied score
        $this->assertSame(3, $rankings[2]['rank']); // rank 3, not 2
    }

    public function testRankingsEmptyForNoScores(): void
    {
        $this->assertEmpty($this->lb->rankings('game:empty'));
    }

    // ── rank ─────────────────────────────────────────────────────────────────

    public function testRankReturnsPositionAndScore(): void
    {
        $this->lb->submit('game:1', 'user:1', 500);
        $this->lb->submit('game:1', 'user:2', 1000);
        $this->lb->submit('game:1', 'user:3', 750);

        $rank = $this->lb->rank('game:1', 'user:3');
        $this->assertSame(2, $rank['rank']);
        $this->assertSame(750, $rank['score']);
    }

    public function testRankReturnsNullForUnrankedUser(): void
    {
        $this->assertNull($this->lb->rank('game:1', 'user:99'));
    }

    public function testRankFirstPlaceIsOne(): void
    {
        $this->lb->submit('game:1', 'user:1', 9999);
        $this->lb->submit('game:1', 'user:2', 5000);
        $rank = $this->lb->rank('game:1', 'user:1');
        $this->assertSame(1, $rank['rank']);
    }

    public function testRankTiedUsersShareRank(): void
    {
        $this->lb->submit('game:1', 'user:1', 1000);
        $this->lb->submit('game:1', 'user:2', 1000);
        $rank1 = $this->lb->rank('game:1', 'user:1');
        $rank2 = $this->lb->rank('game:1', 'user:2');
        $this->assertSame($rank1['rank'], $rank2['rank']);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveReturnsTrueWhenFound(): void
    {
        $this->lb->submit('game:1', 'user:1', 1000);
        $this->assertTrue($this->lb->remove('game:1', 'user:1'));
    }

    public function testRemoveReturnsFalseWhenNotFound(): void
    {
        $this->assertFalse($this->lb->remove('game:1', 'user:99'));
    }

    public function testRemoveDeletesScore(): void
    {
        $this->lb->submit('game:1', 'user:1', 1000);
        $this->lb->remove('game:1', 'user:1');
        $this->assertNull($this->lb->rank('game:1', 'user:1'));
    }
}
