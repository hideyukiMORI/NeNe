<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\PurchaseLimit;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PurchaseLimit.
 */
final class PurchaseLimitTest extends TestCase
{
    private PDO $db;
    private PurchaseLimit $pl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE purchase_limit_policies (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                sku         VARCHAR(150) NOT NULL,
                max_qty     INTEGER      NOT NULL,
                period_days INTEGER      NOT NULL,
                UNIQUE (sku)
            )
        ');
        $this->db->exec('
            CREATE TABLE purchase_limit_records (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                sku          VARCHAR(150) NOT NULL,
                user_id      BIGINT       NOT NULL,
                qty          INTEGER      NOT NULL,
                purchased_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pl = new PurchaseLimit($this->db);
    }

    public function testUncappedSkuAlwaysAllows(): void
    {
        $this->assertTrue($this->pl->canPurchase('free', 7, 999));
        $this->assertNull($this->pl->remaining('free', 7));
    }

    public function testRemainingAndCap(): void
    {
        $this->pl->setLimit('sku', 2, 30);
        $this->assertSame(2, $this->pl->remaining('sku', 7));
        $this->pl->record('sku', 7, 2, '2026-06-01 00:00:00');
        $this->assertSame(0, $this->pl->remaining('sku', 7, '2026-06-02 00:00:00'));
        $this->assertFalse($this->pl->canPurchase('sku', 7, 1, '2026-06-02 00:00:00'));
    }

    public function testCanPurchaseUpToCap(): void
    {
        $this->pl->setLimit('sku', 3, 30);
        $this->pl->record('sku', 7, 1, '2026-06-01 00:00:00');
        $this->assertTrue($this->pl->canPurchase('sku', 7, 2, '2026-06-02 00:00:00'));  // 1+2 = 3 ok
        $this->assertFalse($this->pl->canPurchase('sku', 7, 3, '2026-06-02 00:00:00')); // 1+3 = 4 over
    }

    public function testRollingWindowExpiresOldPurchases(): void
    {
        $this->pl->setLimit('sku', 2, 30);
        $this->pl->record('sku', 7, 2, '2026-05-01 00:00:00'); // old
        // 40 days later → outside the 30-day window
        $this->assertSame(2, $this->pl->remaining('sku', 7, '2026-06-10 00:00:00'));
        $this->assertTrue($this->pl->canPurchase('sku', 7, 2, '2026-06-10 00:00:00'));
    }

    public function testWindowBoundaryInclusive(): void
    {
        $this->pl->setLimit('sku', 2, 30);
        $this->pl->record('sku', 7, 2, '2026-05-01 00:00:00');
        // exactly 30 days later → cutoff is 2026-05-01 00:00:00; purchase at cutoff counts (>=)
        $this->assertSame(0, $this->pl->remaining('sku', 7, '2026-05-31 00:00:00'));
    }

    public function testUsersAreSeparate(): void
    {
        $this->pl->setLimit('sku', 2, 30);
        $this->pl->record('sku', 7, 2, '2026-06-01 00:00:00');
        $this->assertSame(0, $this->pl->remaining('sku', 7, '2026-06-02 00:00:00'));
        $this->assertSame(2, $this->pl->remaining('sku', 8, '2026-06-02 00:00:00'));
    }

    public function testSkusAreSeparate(): void
    {
        $this->pl->setLimit('a', 1, 30);
        $this->pl->setLimit('b', 5, 30);
        $this->pl->record('a', 7, 1, '2026-06-01 00:00:00');
        $this->assertFalse($this->pl->canPurchase('a', 7, 1, '2026-06-02 00:00:00'));
        $this->assertTrue($this->pl->canPurchase('b', 7, 5, '2026-06-02 00:00:00'));
    }

    public function testRemoveLimitMakesUnlimited(): void
    {
        $this->pl->setLimit('sku', 1, 30);
        $this->pl->record('sku', 7, 1, '2026-06-01 00:00:00');
        $this->pl->removeLimit('sku');
        $this->assertTrue($this->pl->canPurchase('sku', 7, 100, '2026-06-02 00:00:00'));
        $this->assertNull($this->pl->remaining('sku', 7));
    }

    public function testSetLimitIsIdempotent(): void
    {
        $this->pl->setLimit('sku', 2, 30);
        $this->pl->setLimit('sku', 5, 7);
        $this->assertSame(5, $this->pl->remaining('sku', 7));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM purchase_limit_policies')->fetchColumn());
    }

    public function testSetLimitRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pl->setLimit('sku', 0, 30);
    }

    public function testRecordRejectsZeroQty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pl->record('sku', 7, 0);
    }

    public function testCanPurchaseRejectsZeroQty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pl->canPurchase('sku', 7, 0);
    }
}
