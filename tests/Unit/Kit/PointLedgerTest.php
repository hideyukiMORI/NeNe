<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\PointLedger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PointLedger using an in-memory SQLite database.
 */
final class PointLedgerTest extends TestCase
{
    private PDO $db;
    private PointLedger $ledger;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE point_ledger (
                id          INTEGER      PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                delta       INTEGER      NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->ledger = new PointLedger($this->db);
    }

    // ── earn ──────────────────────────────────────────────────────────────────

    public function testEarnReturnsId(): void
    {
        $id = $this->ledger->earn('user:1', 100);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testEarnIncreasesBalance(): void
    {
        $this->ledger->earn('user:1', 100);
        $this->assertSame(100, $this->ledger->balance('user:1'));
    }

    public function testEarnAccumulates(): void
    {
        $this->ledger->earn('user:1', 100);
        $this->ledger->earn('user:1', 50);
        $this->assertSame(150, $this->ledger->balance('user:1'));
    }

    public function testEarnWithDescription(): void
    {
        $this->ledger->earn('user:1', 100, 'Purchase reward');
        $history = $this->ledger->history('user:1');
        $this->assertSame('Purchase reward', $history[0]['description']);
    }

    public function testEarnZeroPointsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->earn('user:1', 0);
    }

    public function testEarnNegativePointsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->earn('user:1', -10);
    }

    // ── spend ─────────────────────────────────────────────────────────────────

    public function testSpendReturnsTrueOnSuccess(): void
    {
        $this->ledger->earn('user:1', 100);
        $this->assertTrue($this->ledger->spend('user:1', 30));
    }

    public function testSpendDecreasesBalance(): void
    {
        $this->ledger->earn('user:1', 100);
        $this->ledger->spend('user:1', 30);
        $this->assertSame(70, $this->ledger->balance('user:1'));
    }

    public function testSpendReturnsFalseWhenInsufficientBalance(): void
    {
        $this->ledger->earn('user:1', 50);
        $this->assertFalse($this->ledger->spend('user:1', 100));
    }

    public function testSpendDoesNotAlterBalanceWhenInsufficient(): void
    {
        $this->ledger->earn('user:1', 50);
        $this->ledger->spend('user:1', 100);
        $this->assertSame(50, $this->ledger->balance('user:1'));
    }

    public function testSpendExactBalance(): void
    {
        $this->ledger->earn('user:1', 100);
        $this->assertTrue($this->ledger->spend('user:1', 100));
        $this->assertSame(0, $this->ledger->balance('user:1'));
    }

    public function testSpendZeroPointsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->spend('user:1', 0);
    }

    public function testSpendNegativePointsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->spend('user:1', -5);
    }

    // ── balance ───────────────────────────────────────────────────────────────

    public function testBalanceIsZeroForNewUser(): void
    {
        $this->assertSame(0, $this->ledger->balance('user:1'));
    }

    public function testBalanceIsUserIsolated(): void
    {
        $this->ledger->earn('user:1', 100);
        $this->assertSame(0, $this->ledger->balance('user:2'));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsEntriesNewestFirst(): void
    {
        $this->ledger->earn('user:1', 100, 'first');
        $this->ledger->earn('user:1', 50, 'second');
        $history = $this->ledger->history('user:1');
        $this->assertSame('second', $history[0]['description']);
        $this->assertSame('first', $history[1]['description']);
    }

    public function testHistoryReturnsEmptyForNewUser(): void
    {
        $this->assertEmpty($this->ledger->history('user:1'));
    }

    public function testHistoryShowsPositiveDeltaForEarn(): void
    {
        $this->ledger->earn('user:1', 100);
        $history = $this->ledger->history('user:1');
        $this->assertGreaterThan(0, $history[0]['delta']);
    }

    public function testHistoryShowsNegativeDeltaForSpend(): void
    {
        $this->ledger->earn('user:1', 100);
        $this->ledger->spend('user:1', 30);
        $history = $this->ledger->history('user:1');
        $this->assertLessThan(0, $history[0]['delta']);
    }

    public function testHistoryLimitClampsToMax(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->ledger->earn('user:1', 10, "entry {$i}");
        }
        $history = $this->ledger->history('user:1', 3);
        $this->assertCount(3, $history);
    }

    public function testHistoryIsUserIsolated(): void
    {
        $this->ledger->earn('user:1', 100);
        $this->assertEmpty($this->ledger->history('user:2'));
    }
}
