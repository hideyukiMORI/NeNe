<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\CreditLedger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CreditLedger.
 */
final class CreditLedgerTest extends TestCase
{
    private PDO $db;
    private CreditLedger $cl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE credit_ledger (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                amount      INT          NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cl = new CreditLedger($this->db);
    }

    // ── credit ────────────────────────────────────────────────────────────────

    public function testCreditReturnsId(): void
    {
        $id = $this->cl->credit('user-1', 100, 'bonus');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreditIncreasesBalance(): void
    {
        $this->cl->credit('user-1', 100);
        $this->assertSame(100, $this->cl->balance('user-1'));
    }

    public function testCreditAddsToExistingBalance(): void
    {
        $this->cl->credit('user-1', 50);
        $this->cl->credit('user-1', 30);
        $this->assertSame(80, $this->cl->balance('user-1'));
    }

    public function testCreditThrowsOnZeroAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->credit('user-1', 0);
    }

    public function testCreditThrowsOnNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->credit('user-1', -10);
    }

    // ── debit ─────────────────────────────────────────────────────────────────

    public function testDebitDecreasesBalance(): void
    {
        $this->cl->credit('user-1', 100);
        $this->cl->debit('user-1', 30);
        $this->assertSame(70, $this->cl->balance('user-1'));
    }

    public function testDebitThrowsIfInsufficientBalance(): void
    {
        $this->cl->credit('user-1', 10);
        $this->expectException(\RuntimeException::class);
        $this->cl->debit('user-1', 20);
    }

    public function testDebitThrowsOnZeroAmount(): void
    {
        $this->cl->credit('user-1', 100);
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->debit('user-1', 0);
    }

    public function testDebitReturnsId(): void
    {
        $this->cl->credit('user-1', 100);
        $id = $this->cl->debit('user-1', 30);
        $this->assertGreaterThan(0, $id);
    }

    // ── balance ───────────────────────────────────────────────────────────────

    public function testBalanceReturnsZeroForUnknownUser(): void
    {
        $this->assertSame(0, $this->cl->balance('nobody'));
    }

    public function testBalanceIsScopedToUser(): void
    {
        $this->cl->credit('user-1', 100);
        $this->assertSame(0, $this->cl->balance('user-2'));
    }

    public function testBalanceAfterMultipleTransactions(): void
    {
        $this->cl->credit('user-1', 100);
        $this->cl->credit('user-1', 50);
        $this->cl->debit('user-1', 30);
        $this->assertSame(120, $this->cl->balance('user-1'));
    }

    // ── hasEnough ─────────────────────────────────────────────────────────────

    public function testHasEnoughReturnsTrueWhenSufficient(): void
    {
        $this->cl->credit('user-1', 100);
        $this->assertTrue($this->cl->hasEnough('user-1', 100));
    }

    public function testHasEnoughReturnsFalseWhenInsufficient(): void
    {
        $this->cl->credit('user-1', 50);
        $this->assertFalse($this->cl->hasEnough('user-1', 100));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsEntries(): void
    {
        $this->cl->credit('user-1', 100, 'bonus');
        $this->cl->debit('user-1', 30, 'purchase');
        $history = $this->cl->history('user-1');
        $this->assertCount(2, $history);
    }

    public function testHistoryReturnsLatestFirst(): void
    {
        $this->cl->credit('user-1', 100, 'first');
        $this->cl->credit('user-1', 50, 'second');
        $history = $this->cl->history('user-1');
        $this->assertSame(50, $history[0]['amount']);
    }

    public function testHistoryContainsCorrectShape(): void
    {
        $this->cl->credit('user-1', 100, 'bonus');
        $history = $this->cl->history('user-1');
        $this->assertArrayHasKey('id', $history[0]);
        $this->assertArrayHasKey('amount', $history[0]);
        $this->assertArrayHasKey('description', $history[0]);
        $this->assertArrayHasKey('created_at', $history[0]);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroInitially(): void
    {
        $this->assertSame(0, $this->cl->count('user-1'));
    }

    public function testCountReturnsEntryCount(): void
    {
        $this->cl->credit('user-1', 100);
        $this->cl->credit('user-1', 50);
        $this->assertSame(2, $this->cl->count('user-1'));
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function testCreditThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->credit('', 100);
    }

    public function testDebitThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->debit('', 10);
    }
}
