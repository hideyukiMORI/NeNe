<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\DailyReward;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DailyReward.
 */
final class DailyRewardTest extends TestCase
{
    private PDO $db;
    private DailyReward $dr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE daily_reward_claims (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    BIGINT   NOT NULL,
                claim_date CHAR(10) NOT NULL,
                reward     INTEGER  NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, claim_date)
            )
        ');
        $this->dr = new DailyReward($this->db);
    }

    public function testClaimOncePerDay(): void
    {
        $this->assertTrue($this->dr->claim(42, 10, '2026-06-01'));
        $this->assertFalse($this->dr->claim(42, 10, '2026-06-01')); // already claimed
        $this->assertTrue($this->dr->claim(42, 20, '2026-06-02'));
    }

    public function testClaimedTodayAndCanClaim(): void
    {
        $this->dr->claim(42, 10, '2026-06-01');
        $this->assertTrue($this->dr->claimedToday(42, '2026-06-01'));
        $this->assertFalse($this->dr->canClaim(42, '2026-06-01'));
        $this->assertTrue($this->dr->canClaim(42, '2026-06-02'));
    }

    public function testTotalClaimed(): void
    {
        $this->dr->claim(42, 10, '2026-06-01');
        $this->dr->claim(42, 20, '2026-06-02');
        $this->dr->claim(42, 5, '2026-06-03');
        $this->assertSame(35, $this->dr->totalClaimed(42));
    }

    public function testLastClaim(): void
    {
        $this->dr->claim(42, 10, '2026-06-01');
        $this->dr->claim(42, 10, '2026-06-05');
        $this->assertSame('2026-06-05', $this->dr->lastClaim(42));
        $this->assertNull($this->dr->lastClaim(99));
    }

    public function testStreakConsecutiveDays(): void
    {
        $this->dr->claim(42, 1, '2026-06-01');
        $this->dr->claim(42, 1, '2026-06-02');
        $this->dr->claim(42, 1, '2026-06-03');
        $this->assertSame(3, $this->dr->claimStreak(42, '2026-06-03'));
    }

    public function testStreakBreaksOnGap(): void
    {
        $this->dr->claim(42, 1, '2026-06-01');
        $this->dr->claim(42, 1, '2026-06-02');
        // gap on the 3rd
        $this->dr->claim(42, 1, '2026-06-04');
        $this->dr->claim(42, 1, '2026-06-05');
        $this->assertSame(2, $this->dr->claimStreak(42, '2026-06-05')); // only 4th+5th
    }

    public function testStreakCountsThroughYesterdayWhenTodayUnclaimed(): void
    {
        $this->dr->claim(42, 1, '2026-06-01');
        $this->dr->claim(42, 1, '2026-06-02');
        // today (06-03) not yet claimed → streak still counts up to yesterday
        $this->assertSame(2, $this->dr->claimStreak(42, '2026-06-03'));
    }

    public function testStreakZeroWhenGapBeforeYesterday(): void
    {
        $this->dr->claim(42, 1, '2026-06-01');
        // today 06-03, yesterday 06-02 unclaimed → streak 0
        $this->assertSame(0, $this->dr->claimStreak(42, '2026-06-03'));
    }

    public function testUsersAreSeparate(): void
    {
        $this->dr->claim(1, 10, '2026-06-01');
        $this->assertTrue($this->dr->canClaim(2, '2026-06-01'));
        $this->assertSame(0, $this->dr->totalClaimed(2));
    }

    public function testClaimRejectsNegativeReward(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dr->claim(42, -1, '2026-06-01');
    }

    public function testZeroRewardIsAllowed(): void
    {
        $this->assertTrue($this->dr->claim(42, 0, '2026-06-01'));
        $this->assertSame(0, $this->dr->totalClaimed(42));
    }
}
