<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\BudgetTracker;
use PDO;
use PHPUnit\Framework\TestCase;

final class BudgetTrackerTest extends TestCase
{
    private PDO $pdo;
    private BudgetTracker $bt;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE budget_allocations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id   VARCHAR(255) NOT NULL,
                category   VARCHAR(100) NOT NULL,
                period     VARCHAR(50)  NOT NULL,
                amount     INTEGER      NOT NULL,
                status     VARCHAR(20)  NOT NULL DEFAULT \'active\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE budget_spends (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                allocation_id INTEGER  NOT NULL,
                amount        INTEGER  NOT NULL,
                note          TEXT     NULL,
                spent_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->bt = new BudgetTracker($this->pdo);
    }

    // ── allocate ──────────────────────────────────────────────────────────────

    public function testAllocateReturnsId(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->assertGreaterThan(0, $id);
    }

    public function testAllocateStoresFields(): void
    {
        $id  = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $row = $this->bt->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('dept-eng', $row['owner_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('cloud', $row['category']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-Q2', $row['period']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(500000, (int)$row['amount']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(BudgetTracker::STATUS_ACTIVE, $row['status']);
    }

    public function testAllocateThrowsOnEmptyOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bt->allocate('', 'cloud', '2026-Q2', 100);
    }

    public function testAllocateThrowsOnEmptyCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bt->allocate('dept-eng', '', '2026-Q2', 100);
    }

    public function testAllocateThrowsOnEmptyPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bt->allocate('dept-eng', 'cloud', '', 100);
    }

    public function testAllocateThrowsOnZeroAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 0);
    }

    public function testAllocateThrowsOnNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', -1);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->bt->get(9999));
    }

    // ── spend ─────────────────────────────────────────────────────────────────

    public function testSpendRecordsTransaction(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->assertTrue($this->bt->spend($id, 12000, 'AWS invoice'));
        $this->assertSame(12000, $this->bt->spent($id));
    }

    public function testSpendReturnsFalseForMissingAllocation(): void
    {
        $this->assertFalse($this->bt->spend(9999, 100));
    }

    public function testSpendThrowsOnZeroAmount(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->expectException(\InvalidArgumentException::class);
        $this->bt->spend($id, 0);
    }

    public function testSpendThrowsOnOverflow(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 1000);
        $this->expectException(\OverflowException::class);
        $this->bt->spend($id, 1001);
    }

    public function testSpendMarksExhaustedWhenFullySpent(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 1000);
        $this->bt->spend($id, 1000);
        $row = $this->bt->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(BudgetTracker::STATUS_EXHAUSTED, $row['status']);
    }

    // ── spent / remaining ─────────────────────────────────────────────────────

    public function testSpentIsZeroWhenNoSpends(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->assertSame(0, $this->bt->spent($id));
    }

    public function testSpentAccumulatesMultipleTransactions(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->bt->spend($id, 10000);
        $this->bt->spend($id, 5000);
        $this->assertSame(15000, $this->bt->spent($id));
    }

    public function testRemainingEqualsAmountMinusSpent(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 100000);
        $this->bt->spend($id, 30000);
        $this->assertSame(70000, $this->bt->remaining($id));
    }

    public function testRemainingIsZeroForMissingAllocation(): void
    {
        $this->assertSame(0, $this->bt->remaining(9999));
    }

    // ── isExhausted ───────────────────────────────────────────────────────────

    public function testIsExhaustedFalseWhenBudgetRemains(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 1000);
        $this->bt->spend($id, 500);
        $this->assertFalse($this->bt->isExhausted($id));
    }

    public function testIsExhaustedTrueWhenFullySpent(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 1000);
        $this->bt->spend($id, 1000);
        $this->assertTrue($this->bt->isExhausted($id));
    }

    public function testIsExhaustedFalseForMissingAllocation(): void
    {
        $this->assertFalse($this->bt->isExhausted(9999));
    }

    // ── close ─────────────────────────────────────────────────────────────────

    public function testCloseChangesStatus(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->assertTrue($this->bt->close($id));
        $row = $this->bt->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(BudgetTracker::STATUS_CLOSED, $row['status']);
    }

    public function testCloseReturnsFalseForMissingAllocation(): void
    {
        $this->assertFalse($this->bt->close(9999));
    }

    public function testCloseReturnsFalseWhenAlreadyClosed(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->bt->close($id);
        $this->assertFalse($this->bt->close($id));
    }

    // ── spends ────────────────────────────────────────────────────────────────

    public function testSpendsReturnsAllTransactions(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->bt->spend($id, 1000, 'First');
        $this->bt->spend($id, 2000, 'Second');
        $spends = $this->bt->spends($id);
        $this->assertCount(2, $spends);
        $this->assertSame(1000, (int)$spends[0]['amount']);
        $this->assertSame(2000, (int)$spends[1]['amount']);
    }

    public function testSpendsReturnsEmptyWhenNone(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->assertSame([], $this->bt->spends($id));
    }

    // ── forOwner ──────────────────────────────────────────────────────────────

    public function testForOwnerFiltersCorrectly(): void
    {
        $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 100000);
        $this->bt->allocate('dept-eng', 'saas', '2026-Q2', 50000);
        $this->bt->allocate('dept-hr', 'saas', '2026-Q2', 20000);
        $this->assertCount(2, $this->bt->forOwner('dept-eng'));
        $this->assertCount(1, $this->bt->forOwner('dept-hr'));
    }

    public function testForOwnerFiltersByPeriod(): void
    {
        $this->bt->allocate('dept-eng', 'cloud', '2026-Q1', 100000);
        $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 100000);
        $this->assertCount(1, $this->bt->forOwner('dept-eng', '2026-Q1'));
        $this->assertCount(2, $this->bt->forOwner('dept-eng'));
    }

    // ── activePeriod ──────────────────────────────────────────────────────────

    public function testActivePeriodReturnsOnlyActive(): void
    {
        $id1 = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 100000);
        $this->bt->allocate('dept-hr', 'tools', '2026-Q2', 50000);
        $this->bt->close($id1);
        $this->assertCount(1, $this->bt->activePeriod('2026-Q2'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesAllocationAndSpends(): void
    {
        $id = $this->bt->allocate('dept-eng', 'cloud', '2026-Q2', 500000);
        $this->bt->spend($id, 1000);
        $this->assertTrue($this->bt->delete($id));
        $this->assertNull($this->bt->get($id));
        $this->assertSame([], $this->bt->spends($id));
    }

    public function testDeleteReturnsFalseForMissingAllocation(): void
    {
        $this->assertFalse($this->bt->delete(9999));
    }
}
