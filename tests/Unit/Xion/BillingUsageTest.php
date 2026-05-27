<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\BillingUsage;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingUsageTest extends TestCase
{
    private PDO $pdo;
    private BillingUsage $bu;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE billing_usage (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id  VARCHAR(255) NOT NULL,
                metric      VARCHAR(100) NOT NULL,
                quantity    INTEGER      NOT NULL DEFAULT 1,
                period      VARCHAR(20)  NOT NULL,
                recorded_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->bu = new BillingUsage($this->pdo);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordInsertsRow(): void
    {
        $this->bu->record('acct-1', 'api_calls', 1, '2026-05');
        $this->assertSame(1, $this->bu->sum('acct-1', 'api_calls', '2026-05'));
    }

    public function testRecordAccumulatesQuantity(): void
    {
        $this->bu->record('acct-1', 'api_calls', 3, '2026-05');
        $this->bu->record('acct-1', 'api_calls', 5, '2026-05');
        $this->assertSame(8, $this->bu->sum('acct-1', 'api_calls', '2026-05'));
    }

    public function testRecordUsesCurrentMonthByDefault(): void
    {
        $this->bu->record('acct-1', 'api_calls', 1);
        $period = (new \DateTimeImmutable())->format('Y-m');
        $this->assertSame(1, $this->bu->sum('acct-1', 'api_calls', $period));
    }

    public function testRecordThrowsOnEmptyAccountId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bu->record('', 'api_calls', 1, '2026-05');
    }

    public function testRecordThrowsOnEmptyMetric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bu->record('acct-1', '', 1, '2026-05');
    }

    public function testRecordThrowsOnZeroQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bu->record('acct-1', 'api_calls', 0, '2026-05');
    }

    public function testRecordThrowsOnNegativeQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bu->record('acct-1', 'api_calls', -1, '2026-05');
    }

    // ── sum ───────────────────────────────────────────────────────────────────

    public function testSumReturnsZeroWhenNoRecords(): void
    {
        $this->assertSame(0, $this->bu->sum('acct-1', 'api_calls', '2026-05'));
    }

    public function testSumIsIsolatedByPeriod(): void
    {
        $this->bu->record('acct-1', 'api_calls', 10, '2026-05');
        $this->bu->record('acct-1', 'api_calls', 20, '2026-06');
        $this->assertSame(10, $this->bu->sum('acct-1', 'api_calls', '2026-05'));
        $this->assertSame(20, $this->bu->sum('acct-1', 'api_calls', '2026-06'));
    }

    public function testSumIsIsolatedByAccount(): void
    {
        $this->bu->record('acct-1', 'api_calls', 10, '2026-05');
        $this->bu->record('acct-2', 'api_calls', 5, '2026-05');
        $this->assertSame(10, $this->bu->sum('acct-1', 'api_calls', '2026-05'));
        $this->assertSame(5, $this->bu->sum('acct-2', 'api_calls', '2026-05'));
    }

    public function testSumIsIsolatedByMetric(): void
    {
        $this->bu->record('acct-1', 'api_calls', 10, '2026-05');
        $this->bu->record('acct-1', 'storage_bytes', 1024, '2026-05');
        $this->assertSame(10, $this->bu->sum('acct-1', 'api_calls', '2026-05'));
        $this->assertSame(1024, $this->bu->sum('acct-1', 'storage_bytes', '2026-05'));
    }

    // ── summary ───────────────────────────────────────────────────────────────

    public function testSummaryReturnsAllMetrics(): void
    {
        $this->bu->record('acct-1', 'api_calls', 10, '2026-05');
        $this->bu->record('acct-1', 'storage_bytes', 1024, '2026-05');
        $summary = $this->bu->summary('acct-1', '2026-05');
        $this->assertCount(2, $summary);
        $metrics = array_column($summary, 'metric');
        $this->assertContains('api_calls', $metrics);
        $this->assertContains('storage_bytes', $metrics);
    }

    public function testSummaryReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->bu->summary('acct-1', '2026-05'));
    }

    public function testSummaryTotalsAreCorrect(): void
    {
        $this->bu->record('acct-1', 'api_calls', 3, '2026-05');
        $this->bu->record('acct-1', 'api_calls', 7, '2026-05');
        $summary = $this->bu->summary('acct-1', '2026-05');
        $this->assertCount(1, $summary);
        $this->assertSame('10', (string)$summary[0]['total']);
    }

    public function testSummaryThrowsOnEmptyAccountId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bu->summary('', '2026-05');
    }

    // ── overage ───────────────────────────────────────────────────────────────

    public function testOverageReturnsZeroWhenWithinLimit(): void
    {
        $this->bu->record('acct-1', 'api_calls', 5, '2026-05');
        $this->assertSame(0, $this->bu->overage('acct-1', 'api_calls', '2026-05', 10));
    }

    public function testOverageReturnsExcessWhenOverLimit(): void
    {
        $this->bu->record('acct-1', 'api_calls', 15, '2026-05');
        $this->assertSame(5, $this->bu->overage('acct-1', 'api_calls', '2026-05', 10));
    }

    public function testOverageReturnsZeroWhenNoUsage(): void
    {
        $this->assertSame(0, $this->bu->overage('acct-1', 'api_calls', '2026-05', 10));
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetDeletesPeriodRecords(): void
    {
        $this->bu->record('acct-1', 'api_calls', 10, '2026-05');
        $this->bu->record('acct-1', 'api_calls', 20, '2026-06');
        $deleted = $this->bu->reset('acct-1', '2026-05');
        $this->assertSame(1, $deleted);
        $this->assertSame(0, $this->bu->sum('acct-1', 'api_calls', '2026-05'));
        $this->assertSame(20, $this->bu->sum('acct-1', 'api_calls', '2026-06'));
    }

    public function testResetThrowsOnEmptyAccountId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bu->reset('', '2026-05');
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldRows(): void
    {
        $this->pdo->exec("INSERT INTO billing_usage (account_id, metric, quantity, period, recorded_at)
                          VALUES ('acct-1', 'api_calls', 1, '2025-01', '2025-01-15 00:00:00')");
        $this->bu->record('acct-1', 'api_calls', 1, '2026-05');
        $deleted = $this->bu->purgeOlderThan('2026-01-01 00:00:00');
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->bu->sum('acct-1', 'api_calls', '2026-05'));
    }
}
