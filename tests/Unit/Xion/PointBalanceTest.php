<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\PointBalance;
use PDO;
use PHPUnit\Framework\TestCase;

final class PointBalanceTest extends TestCase
{
    private PDO $pdo;
    private PointBalance $pb;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE point_ledger (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                delta      INTEGER      NOT NULL,
                reason     VARCHAR(100) NOT NULL,
                reference  VARCHAR(255) NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pb = new PointBalance($this->pdo);
    }

    // ── earn ──────────────────────────────────────────────────────────────────

    public function testEarnReturnsId(): void
    {
        $id = $this->pb->earn('user-1', 100, 'purchase');
        $this->assertGreaterThan(0, $id);
    }

    public function testEarnStoresCorrectDelta(): void
    {
        $id  = $this->pb->earn('user-1', 100, 'purchase', 'order-42');
        $row = $this->pb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(100, (int)$row['delta']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('purchase', $row['reason']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('order-42', $row['reference']);
    }

    public function testEarnThrowsOnZeroPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->earn('user-1', 0, 'purchase');
    }

    public function testEarnThrowsOnNegativePoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->earn('user-1', -10, 'purchase');
    }

    public function testEarnThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->earn('', 10, 'purchase');
    }

    public function testEarnThrowsOnEmptyReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->earn('user-1', 10, '');
    }

    // ── spend ─────────────────────────────────────────────────────────────────

    public function testSpendReducesBalance(): void
    {
        $this->pb->earn('user-1', 100, 'purchase');
        $id = $this->pb->spend('user-1', 30, 'redeem');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(70, $this->pb->balance('user-1'));
    }

    public function testSpendThrowsWhenInsufficientBalance(): void
    {
        $this->pb->earn('user-1', 50, 'purchase');
        $this->expectException(\RuntimeException::class);
        $this->pb->spend('user-1', 100, 'redeem');
    }

    public function testSpendThrowsOnZeroPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->spend('user-1', 0, 'redeem');
    }

    // ── expire ────────────────────────────────────────────────────────────────

    public function testExpireReducesBalance(): void
    {
        $this->pb->earn('user-1', 100, 'purchase');
        $this->pb->expire('user-1', 20, 'monthly-expiry');
        $this->assertSame(80, $this->pb->balance('user-1'));
    }

    public function testExpireDoesNotGuardAgainstNegative(): void
    {
        // No balance — expire does not throw
        $this->pb->expire('user-1', 50, 'batch-expiry');
        $this->assertSame(-50, $this->pb->balance('user-1'));
    }

    // ── balance ───────────────────────────────────────────────────────────────

    public function testBalanceReturnsZeroWithNoEntries(): void
    {
        $this->assertSame(0, $this->pb->balance('nobody'));
    }

    public function testBalanceSumsAllDeltas(): void
    {
        $this->pb->earn('user-1', 100, 'a');
        $this->pb->earn('user-1', 50, 'b');
        $this->pb->spend('user-1', 30, 'c');
        $this->assertSame(120, $this->pb->balance('user-1'));
    }

    public function testBalanceIsIsolatedByUser(): void
    {
        $this->pb->earn('user-1', 100, 'purchase');
        $this->pb->earn('user-2', 200, 'purchase');
        $this->assertSame(100, $this->pb->balance('user-1'));
        $this->assertSame(200, $this->pb->balance('user-2'));
    }

    // ── totalEarned / totalSpent ──────────────────────────────────────────────

    public function testTotalEarned(): void
    {
        $this->pb->earn('user-1', 100, 'a');
        $this->pb->earn('user-1', 50, 'b');
        $this->pb->spend('user-1', 30, 'c');
        $this->assertSame(150, $this->pb->totalEarned('user-1'));
    }

    public function testTotalSpent(): void
    {
        $this->pb->earn('user-1', 200, 'a');
        $this->pb->spend('user-1', 30, 'b');
        $this->pb->spend('user-1', 20, 'c');
        $this->assertSame(50, $this->pb->totalSpent('user-1'));
    }

    public function testTotalEarnedReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->pb->totalEarned('nobody'));
    }

    public function testTotalSpentReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->pb->totalSpent('nobody'));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsNewestFirst(): void
    {
        $id1 = $this->pb->earn('user-1', 100, 'a');
        $id2 = $this->pb->earn('user-1', 50, 'b');
        $list = $this->pb->history('user-1');
        $this->assertSame($id2, (int)$list[0]['id']);
        $this->assertSame($id1, (int)$list[1]['id']);
    }

    public function testHistoryReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->pb->history('nobody'));
    }

    public function testHistoryRespectsLimitOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pb->earn('user-1', 10, 'purchase');
        }
        $page1 = $this->pb->history('user-1', 3, 0);
        $page2 = $this->pb->history('user-1', 3, 3);
        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->pb->find(9999));
    }
}
