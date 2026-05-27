<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\PriceHistory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PriceHistory.
 */
final class PriceHistoryTest extends TestCase
{
    private PDO $db;
    private PriceHistory $ph;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE price_history (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                amount      BIGINT       NOT NULL,
                currency    VARCHAR(3)   NOT NULL DEFAULT \'USD\',
                changed_by  VARCHAR(255) NOT NULL DEFAULT \'\',
                reason      VARCHAR(255) NOT NULL DEFAULT \'\',
                recorded_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ph = new PriceHistory($this->db);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->ph->record('product', 'SKU-1', 2999);
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordThrowsOnNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ph->record('product', 'SKU-1', -1);
    }

    public function testRecordThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ph->record('', 'SKU-1', 100);
    }

    public function testRecordThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ph->record('product', '', 100);
    }

    public function testRecordZeroAmountIsAllowed(): void
    {
        $id = $this->ph->record('product', 'SKU-1', 0, 'USD', 'admin', 'Free');
        $this->assertGreaterThan(0, $id);
    }

    // ── current ───────────────────────────────────────────────────────────────

    public function testCurrentReturnsLatestRecord(): void
    {
        $this->ph->record('product', 'SKU-1', 2999);
        $this->ph->record('product', 'SKU-1', 1999);
        $cur = $this->ph->current('product', 'SKU-1');
        $this->assertNotNull($cur);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1999, (int)$cur['amount']);
    }

    public function testCurrentReturnsNullWhenNoHistory(): void
    {
        $this->assertNull($this->ph->current('product', 'SKU-X'));
    }

    public function testCurrentAmountReturnsInt(): void
    {
        $this->ph->record('product', 'SKU-1', 5000);
        $this->assertSame(5000, $this->ph->currentAmount('product', 'SKU-1'));
    }

    public function testCurrentAmountReturnsNullWhenNoHistory(): void
    {
        $this->assertNull($this->ph->currentAmount('product', 'SKU-X'));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsNewestFirst(): void
    {
        $this->ph->record('product', 'SKU-1', 1000);
        $this->ph->record('product', 'SKU-1', 2000);
        $history = $this->ph->history('product', 'SKU-1');
        $this->assertSame(2000, (int)$history[0]['amount']);
        $this->assertSame(1000, (int)$history[1]['amount']);
    }

    public function testHistoryRespectsLimit(): void
    {
        $this->ph->record('product', 'SKU-1', 1000);
        $this->ph->record('product', 'SKU-1', 2000);
        $this->ph->record('product', 'SKU-1', 3000);
        $this->assertCount(2, $this->ph->history('product', 'SKU-1', 2));
    }

    public function testHistoryReturnsEmptyWhenNoHistory(): void
    {
        $this->assertSame([], $this->ph->history('product', 'SKU-X'));
    }

    // ── lowest / highest ──────────────────────────────────────────────────────

    public function testLowestReturnsMinPrice(): void
    {
        $this->ph->record('product', 'SKU-1', 3000);
        $this->ph->record('product', 'SKU-1', 500);
        $this->ph->record('product', 'SKU-1', 1500);
        $low = $this->ph->lowest('product', 'SKU-1');
        $this->assertNotNull($low);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(500, (int)$low['amount']);
    }

    public function testHighestReturnsMaxPrice(): void
    {
        $this->ph->record('product', 'SKU-1', 500);
        $this->ph->record('product', 'SKU-1', 3000);
        $this->ph->record('product', 'SKU-1', 1500);
        $high = $this->ph->highest('product', 'SKU-1');
        $this->assertNotNull($high);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(3000, (int)$high['amount']);
    }

    public function testLowestReturnsNullForNoHistory(): void
    {
        $this->assertNull($this->ph->lowest('product', 'SKU-X'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsCorrectCount(): void
    {
        $this->ph->record('product', 'SKU-1', 1000);
        $this->ph->record('product', 'SKU-1', 2000);
        $this->assertSame(2, $this->ph->count('product', 'SKU-1'));
    }

    public function testCountIsEntityScoped(): void
    {
        $this->ph->record('product', 'SKU-1', 1000);
        $this->ph->record('product', 'SKU-2', 2000);
        $this->assertSame(1, $this->ph->count('product', 'SKU-1'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldRecordsKeepsLatest(): void
    {
        $this->ph->record('product', 'SKU-1', 1000);
        $this->ph->record('product', 'SKU-1', 2000);
        $this->db->exec("UPDATE price_history SET recorded_at = datetime('now', '-8 days')");
        // Add a fresh record
        $this->ph->record('product', 'SKU-1', 3000);
        $deleted = $this->ph->purgeOlderThan('product', 'SKU-1', 7);
        // The 2 old records minus the most recent = 1 purged (most recent old record preserved)
        $this->assertGreaterThanOrEqual(1, $deleted);
        // Current price still exists
        $this->assertSame(3000, $this->ph->currentAmount('product', 'SKU-1'));
    }
}
