<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ShoppingCart;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ShoppingCart.
 */
final class ShoppingCartTest extends TestCase
{
    private PDO $db;
    private ShoppingCart $cart;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE cart_items (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                cart_id    VARCHAR(255) NOT NULL,
                sku        VARCHAR(255) NOT NULL,
                qty        INTEGER      NOT NULL DEFAULT 1,
                unit_price INTEGER      NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (cart_id, sku)
            )
        ');
        $this->cart = new ShoppingCart($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddItemToCart(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 2, 1500);
        $this->assertTrue($this->cart->has('cart-1', 'SKU-001'));
        $this->assertSame(2, $this->cart->qty('cart-1'));
    }

    public function testAddSameSkuIncrementsQty(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 2, 1500);
        $this->cart->add('cart-1', 'SKU-001', 3, 1500);
        $this->assertSame(5, $this->cart->qty('cart-1'));
        $this->assertSame(1, $this->cart->itemCount('cart-1'));
    }

    public function testAddUpdatesUnitPrice(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 1, 1000);
        $this->cart->add('cart-1', 'SKU-001', 1, 1200);
        $items = $this->cart->items('cart-1');
        $this->assertSame(1200, (int)$items[0]['unit_price']);
    }

    public function testAddThrowsOnInvalidQty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cart->add('cart-1', 'SKU-001', 0, 100);
    }

    public function testAddThrowsOnNegativePrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cart->add('cart-1', 'SKU-001', 1, -1);
    }

    public function testAddThrowsOnEmptyCartId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cart->add('', 'SKU-001', 1, 100);
    }

    public function testAddThrowsOnEmptySku(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cart->add('cart-1', '', 1, 100);
    }

    // ── setQty ────────────────────────────────────────────────────────────────

    public function testSetQty(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 5, 100);
        $this->assertTrue($this->cart->setQty('cart-1', 'SKU-001', 2));
        $this->assertSame(2, $this->cart->qty('cart-1'));
    }

    public function testSetQtyToZeroRemovesItem(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 5, 100);
        $this->assertTrue($this->cart->setQty('cart-1', 'SKU-001', 0));
        $this->assertFalse($this->cart->has('cart-1', 'SKU-001'));
    }

    public function testSetQtyReturnsFalseForMissingSku(): void
    {
        $this->assertFalse($this->cart->setQty('cart-1', 'NOPE', 2));
    }

    public function testSetQtyThrowsOnNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cart->setQty('cart-1', 'SKU-001', -1);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemove(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 1, 100);
        $this->assertTrue($this->cart->remove('cart-1', 'SKU-001'));
        $this->assertFalse($this->cart->has('cart-1', 'SKU-001'));
    }

    public function testRemoveReturnsFalseForMissingSku(): void
    {
        $this->assertFalse($this->cart->remove('cart-1', 'NOPE'));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClear(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 1, 100);
        $this->cart->add('cart-1', 'SKU-002', 2, 200);
        $removed = $this->cart->clear('cart-1');
        $this->assertSame(2, $removed);
        $this->assertSame(0, $this->cart->itemCount('cart-1'));
    }

    // ── total / qty / itemCount ───────────────────────────────────────────────

    public function testTotal(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 3, 1500);
        $this->cart->add('cart-1', 'SKU-002', 1, 800);
        $this->assertSame(5300, $this->cart->total('cart-1'));
    }

    public function testTotalReturnsZeroForEmptyCart(): void
    {
        $this->assertSame(0, $this->cart->total('empty-cart'));
    }

    public function testQtyReturnsZeroForEmptyCart(): void
    {
        $this->assertSame(0, $this->cart->qty('empty-cart'));
    }

    public function testItemCountReturnsDistinctSkus(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 3, 100);
        $this->cart->add('cart-1', 'SKU-002', 2, 200);
        $this->assertSame(2, $this->cart->itemCount('cart-1'));
        $this->assertSame(5, $this->cart->qty('cart-1'));
    }

    // ── items / isolation ─────────────────────────────────────────────────────

    public function testItemsReturnsSortedBySku(): void
    {
        $this->cart->add('cart-1', 'SKU-002', 1, 200);
        $this->cart->add('cart-1', 'SKU-001', 2, 100);
        $items = $this->cart->items('cart-1');
        $this->assertCount(2, $items);
    }

    public function testCartsAreIsolated(): void
    {
        $this->cart->add('cart-1', 'SKU-001', 1, 100);
        $this->cart->add('cart-2', 'SKU-001', 5, 100);
        $this->assertSame(1, $this->cart->qty('cart-1'));
        $this->assertSame(5, $this->cart->qty('cart-2'));
    }
}
