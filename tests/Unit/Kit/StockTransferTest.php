<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\StockTransfer;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StockTransfer.
 */
final class StockTransferTest extends TestCase
{
    private PDO $db;
    private StockTransfer $st;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE stock_ledger (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                sku        VARCHAR(150) NOT NULL,
                location   VARCHAR(150) NOT NULL,
                delta      INTEGER      NOT NULL,
                note       VARCHAR(190) NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->st = new StockTransfer($this->db);
    }

    public function testReceiveAndBalance(): void
    {
        $this->st->receive('sku', 'wh', 100);
        $this->assertSame(100, $this->st->balance('sku', 'wh'));
        $this->assertSame(0, $this->st->balance('sku', 'store'));
    }

    public function testTransferMovesStock(): void
    {
        $this->st->receive('sku', 'wh', 100);
        $this->st->transfer('sku', 'wh', 'store', 30);
        $this->assertSame(70, $this->st->balance('sku', 'wh'));
        $this->assertSame(30, $this->st->balance('sku', 'store'));
    }

    public function testTotalStockConserved(): void
    {
        $this->st->receive('sku', 'wh', 100);
        $this->st->transfer('sku', 'wh', 'store', 40);
        $this->st->transfer('sku', 'store', 'kiosk', 10);
        $this->assertSame(100, $this->st->totalStock('sku')); // moves don't change total
    }

    public function testTransferGuardsAgainstOverdraw(): void
    {
        $this->st->receive('sku', 'wh', 20);
        $this->expectException(\InvalidArgumentException::class);
        $this->st->transfer('sku', 'wh', 'store', 21);
    }

    public function testOverdrawDoesNotPartiallyApply(): void
    {
        $this->st->receive('sku', 'wh', 20);
        try {
            $this->st->transfer('sku', 'wh', 'store', 21);
        } catch (\InvalidArgumentException) {
            // expected
        }
        $this->assertSame(20, $this->st->balance('sku', 'wh'));  // unchanged
        $this->assertSame(0, $this->st->balance('sku', 'store')); // nothing added
    }

    public function testLocationsExcludesZeroBalances(): void
    {
        $this->st->receive('sku', 'wh', 50);
        $this->st->transfer('sku', 'wh', 'store', 50); // wh now 0
        $locs = $this->st->locations('sku');
        $this->assertCount(1, $locs); // only 'store' has non-zero
        $this->assertSame('store', $locs[0]['location']);
        $this->assertSame(50, $locs[0]['balance']);
    }

    public function testHistoryRecordsMoves(): void
    {
        $this->st->receive('sku', 'wh', 100);
        $this->st->transfer('sku', 'wh', 'store', 30);
        $this->assertCount(3, $this->st->history('sku'));       // receive + 2 transfer rows
        $this->assertCount(2, $this->st->history('sku', 'wh')); // receive + transfer-out
    }

    public function testSkusAreSeparate(): void
    {
        $this->st->receive('a', 'wh', 10);
        $this->st->receive('b', 'wh', 5);
        $this->assertSame(10, $this->st->balance('a', 'wh'));
        $this->assertSame(5, $this->st->balance('b', 'wh'));
    }

    public function testTransferRejectsSameLocation(): void
    {
        $this->st->receive('sku', 'wh', 10);
        $this->expectException(\InvalidArgumentException::class);
        $this->st->transfer('sku', 'wh', 'wh', 5);
    }

    public function testReceiveRejectsZeroQty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->st->receive('sku', 'wh', 0);
    }

    public function testReceiveRejectsEmptyLocation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->st->receive('sku', '  ', 10);
    }
}
