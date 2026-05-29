<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\InventoryStock;
use PDO;
use PHPUnit\Framework\TestCase;

final class InventoryStockTest extends TestCase
{
    private PDO $pdo;
    private InventoryStock $inv;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE inventory_stock (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                sku        VARCHAR(100) NOT NULL UNIQUE,
                available  INTEGER      NOT NULL DEFAULT 0,
                reserved   INTEGER      NOT NULL DEFAULT 0,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE inventory_log (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                sku        VARCHAR(100) NOT NULL,
                action     VARCHAR(20)  NOT NULL,
                qty        INTEGER      NOT NULL,
                reference  TEXT         NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->inv = new InventoryStock($this->pdo);
    }

    // ── setStock ──────────────────────────────────────────────────────────────

    public function testSetStockCreatesRecord(): void
    {
        $this->inv->setStock('SKU-1', 100);
        $this->assertSame(100, $this->inv->available('SKU-1'));
    }

    public function testSetStockOverwritesPrevious(): void
    {
        $this->inv->setStock('SKU-1', 100);
        $this->inv->setStock('SKU-1', 50);
        $this->assertSame(50, $this->inv->available('SKU-1'));
    }

    public function testSetStockThrowsOnNegativeQty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->inv->setStock('SKU-1', -1);
    }

    public function testSetStockThrowsOnEmptySku(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->inv->setStock('', 10);
    }

    // ── available / stockFor ──────────────────────────────────────────────────

    public function testAvailableReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, $this->inv->available('MISSING'));
    }

    public function testStockForReturnsFullRecord(): void
    {
        $this->inv->setStock('SKU-1', 100);
        $row = $this->inv->stockFor('SKU-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(100, (int)$row['available']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['reserved']);
    }

    public function testStockForReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->inv->stockFor('MISSING'));
    }

    // ── reserve ───────────────────────────────────────────────────────────────

    public function testReserveDecreasesAvailableIncreasesReserved(): void
    {
        $this->inv->setStock('SKU-1', 10);
        $this->assertTrue($this->inv->reserve('SKU-1', 3));

        $row = $this->inv->stockFor('SKU-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(7, (int)$row['available']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(3, (int)$row['reserved']);
    }

    public function testReserveReturnsFalseWhenInsufficientStock(): void
    {
        $this->inv->setStock('SKU-1', 5);
        $this->assertFalse($this->inv->reserve('SKU-1', 10));
    }

    public function testReserveThrowsOnZeroQty(): void
    {
        $this->inv->setStock('SKU-1', 10);
        $this->expectException(\InvalidArgumentException::class);
        $this->inv->reserve('SKU-1', 0);
    }

    // ── release ───────────────────────────────────────────────────────────────

    public function testReleaseRestoresReservation(): void
    {
        $this->inv->setStock('SKU-1', 10);
        $this->inv->reserve('SKU-1', 4);
        $this->assertTrue($this->inv->release('SKU-1', 4));

        $row = $this->inv->stockFor('SKU-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(10, (int)$row['available']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['reserved']);
    }

    public function testReleaseReturnsFalseWhenInsufficientReserved(): void
    {
        $this->inv->setStock('SKU-1', 10);
        $this->assertFalse($this->inv->release('SKU-1', 1)); // none reserved
    }

    // ── commit ────────────────────────────────────────────────────────────────

    public function testCommitDeductsFromReserved(): void
    {
        $this->inv->setStock('SKU-1', 10);
        $this->inv->reserve('SKU-1', 5);
        $this->assertTrue($this->inv->commit('SKU-1', 5));

        $row = $this->inv->stockFor('SKU-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(5, (int)$row['available']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['reserved']);
    }

    public function testCommitReturnsFalseWhenInsufficientReserved(): void
    {
        $this->inv->setStock('SKU-1', 10);
        $this->assertFalse($this->inv->commit('SKU-1', 1));
    }

    // ── adjust ────────────────────────────────────────────────────────────────

    public function testAdjustPositiveDeltaAddsStock(): void
    {
        $this->inv->setStock('SKU-1', 10);
        $this->assertTrue($this->inv->adjust('SKU-1', 5));
        $this->assertSame(15, $this->inv->available('SKU-1'));
    }

    public function testAdjustNegativeDeltaRemovesStock(): void
    {
        $this->inv->setStock('SKU-1', 10);
        $this->assertTrue($this->inv->adjust('SKU-1', -3));
        $this->assertSame(7, $this->inv->available('SKU-1'));
    }

    public function testAdjustReturnsFalseWhenWouldGoNegative(): void
    {
        $this->inv->setStock('SKU-1', 5);
        $this->assertFalse($this->inv->adjust('SKU-1', -10));
    }

    // ── logFor ────────────────────────────────────────────────────────────────

    public function testLogForRecordsActions(): void
    {
        $this->inv->setStock('SKU-1', 10, 'init');
        $this->inv->reserve('SKU-1', 2, 'cart-1');

        $log = $this->inv->logFor('SKU-1');
        $this->assertCount(2, $log);
        $this->assertSame('reserve', $log[0]['action']); // newest first
        $this->assertSame('set', $log[1]['action']);
    }
}
