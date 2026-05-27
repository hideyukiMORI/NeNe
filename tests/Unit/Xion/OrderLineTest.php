<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\OrderLine;
use PDO;
use PHPUnit\Framework\TestCase;

final class OrderLineTest extends TestCase
{
    private PDO $pdo;
    private OrderLine $ol;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE orders (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       VARCHAR(255) NOT NULL,
                status        VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                currency      VARCHAR(3)   NOT NULL DEFAULT \'USD\',
                address_ref   TEXT         NULL,
                confirmed_at  DATETIME     NULL,
                shipped_at    DATETIME     NULL,
                delivered_at  DATETIME     NULL,
                cancelled_at  DATETIME     NULL,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE order_lines (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id   INTEGER      NOT NULL,
                sku        VARCHAR(100) NOT NULL,
                name       TEXT         NOT NULL,
                qty        INTEGER      NOT NULL,
                unit_cents INTEGER      NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ol = new OrderLine($this->pdo);
    }

    // ── createOrder ───────────────────────────────────────────────────────────

    public function testCreateOrderReturnsId(): void
    {
        $id = $this->ol->createOrder('u1');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateOrderStoresCorrectly(): void
    {
        $id  = $this->ol->createOrder('u1', 'JPY', 'addr-1');
        $row = $this->ol->findOrder($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('u1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('JPY', $row['currency']);
    }

    public function testCreateOrderThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ol->createOrder('');
    }

    // ── addLine / lines / totalCents ──────────────────────────────────────────

    public function testAddLineReturnsId(): void
    {
        $oid = $this->ol->createOrder('u1');
        $lid = $this->ol->addLine($oid, 'SKU-1', 'Widget', 2, 1999);
        $this->assertGreaterThan(0, $lid);
    }

    public function testAddLineStoresCorrectly(): void
    {
        $oid   = $this->ol->createOrder('u1');
        $this->ol->addLine($oid, 'SKU-1', 'Widget', 3, 500);
        $lines = $this->ol->lines($oid);
        $this->assertCount(1, $lines);
        $this->assertSame('SKU-1', $lines[0]['sku']);
        $this->assertSame(3, (int)$lines[0]['qty']);
        $this->assertSame(500, (int)$lines[0]['unit_cents']);
    }

    public function testTotalCentsCalculatesCorrectly(): void
    {
        $oid = $this->ol->createOrder('u1');
        $this->ol->addLine($oid, 'A', 'ItemA', 2, 1000); // 2000
        $this->ol->addLine($oid, 'B', 'ItemB', 1, 500);  // 500
        $this->assertSame(2500, $this->ol->totalCents($oid));
    }

    public function testTotalCentsZeroWhenNoLines(): void
    {
        $oid = $this->ol->createOrder('u1');
        $this->assertSame(0, $this->ol->totalCents($oid));
    }

    public function testAddLineThrowsOnEmptySku(): void
    {
        $oid = $this->ol->createOrder('u1');
        $this->expectException(\InvalidArgumentException::class);
        $this->ol->addLine($oid, '', 'Name', 1, 100);
    }

    public function testAddLineThrowsOnZeroQty(): void
    {
        $oid = $this->ol->createOrder('u1');
        $this->expectException(\InvalidArgumentException::class);
        $this->ol->addLine($oid, 'SKU', 'Name', 0, 100);
    }

    public function testAddLineThrowsOnNegativeCents(): void
    {
        $oid = $this->ol->createOrder('u1');
        $this->expectException(\InvalidArgumentException::class);
        $this->ol->addLine($oid, 'SKU', 'Name', 1, -1);
    }

    // ── status lifecycle ──────────────────────────────────────────────────────

    public function testConfirmChangesStatus(): void
    {
        $id = $this->ol->createOrder('u1');
        $this->assertTrue($this->ol->confirm($id));

        $row = $this->ol->findOrder($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('confirmed', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['confirmed_at']);
    }

    public function testShipChangesStatus(): void
    {
        $id = $this->ol->createOrder('u1');
        $this->ol->confirm($id);
        $this->assertTrue($this->ol->ship($id));

        $row = $this->ol->findOrder($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('shipped', $row['status']);
    }

    public function testDeliverChangesStatus(): void
    {
        $id = $this->ol->createOrder('u1');
        $this->ol->confirm($id);
        $this->ol->ship($id);
        $this->assertTrue($this->ol->deliver($id));

        $row = $this->ol->findOrder($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('delivered', $row['status']);
    }

    public function testCancelFromPending(): void
    {
        $id = $this->ol->createOrder('u1');
        $this->assertTrue($this->ol->cancel($id));

        $row = $this->ol->findOrder($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('cancelled', $row['status']);
    }

    public function testCannotCancelShippedOrder(): void
    {
        $id = $this->ol->createOrder('u1');
        $this->ol->confirm($id);
        $this->ol->ship($id);
        $this->assertFalse($this->ol->cancel($id));
    }

    public function testConfirmReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->ol->confirm(9999));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsUsersOrders(): void
    {
        $id1 = $this->ol->createOrder('u1');
        $id2 = $this->ol->createOrder('u1');
        $this->ol->createOrder('u2');

        $orders = $this->ol->forUser('u1');
        $this->assertCount(2, $orders);
        $this->assertSame($id2, (int)$orders[0]['id']); // newest first
    }
}
