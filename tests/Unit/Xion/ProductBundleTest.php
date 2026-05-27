<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ProductBundle;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProductBundleTest extends TestCase
{
    private PDO $pdo;
    private ProductBundle $pb;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE product_bundles (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                bundle_key  VARCHAR(100) NOT NULL UNIQUE,
                name        VARCHAR(255) NOT NULL,
                price_cents INTEGER      NOT NULL,
                is_active   TINYINT(1)   NOT NULL DEFAULT 0,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE product_bundle_items (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                bundle_id INTEGER      NOT NULL,
                sku       VARCHAR(255) NOT NULL,
                quantity  INTEGER      NOT NULL DEFAULT 1,
                UNIQUE (bundle_id, sku)
            )
        ');
        $this->pb = new ProductBundle($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->pb->create('starter', 'Starter Kit', 2999);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresCorrectly(): void
    {
        $id  = $this->pb->create('starter', 'Starter Kit', 2999);
        $row = $this->pb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('starter', $row['bundle_key']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Starter Kit', $row['name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2999, (int)$row['price_cents']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['is_active']);
    }

    public function testCreateThrowsOnDuplicateKey(): void
    {
        $this->pb->create('starter', 'Kit A', 1000);
        $this->expectException(\RuntimeException::class);
        $this->pb->create('starter', 'Kit B', 2000);
    }

    public function testCreateThrowsOnEmptyBundleKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->create('', 'Name', 999);
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->create('key', '', 999);
    }

    public function testCreateThrowsOnNegativePrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->create('key', 'Name', -1);
    }

    public function testCreateAllowsZeroPrice(): void
    {
        $id = $this->pb->create('free', 'Free Bundle', 0);
        $this->assertGreaterThan(0, $id);
    }

    // ── find / findByKey ──────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->pb->find(9999));
    }

    public function testFindByKeyReturnsBundle(): void
    {
        $this->pb->create('starter', 'Starter', 999);
        $row = $this->pb->findByKey('starter');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Starter', $row['name']);
    }

    public function testFindByKeyReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->pb->findByKey('missing'));
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function testUpdateChangesNameAndPrice(): void
    {
        $id = $this->pb->create('bundle', 'Old Name', 1000);
        $this->assertTrue($this->pb->update($id, 'New Name', 1500));
        $row = $this->pb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('New Name', $row['name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1500, (int)$row['price_cents']);
    }

    public function testUpdateReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->pb->update(9999, 'Name', 999));
    }

    // ── activate / deactivate ─────────────────────────────────────────────────

    public function testActivateSetsIsActiveTrue(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->assertTrue($this->pb->activate($id));
        $row = $this->pb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['is_active']);
    }

    public function testDeactivateSetsIsActiveFalse(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->pb->activate($id);
        $this->assertTrue($this->pb->deactivate($id));
        $row = $this->pb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['is_active']);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesBundleAndItems(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->pb->addItem($id, 'SKU-1');
        $this->assertTrue($this->pb->delete($id));
        $this->assertNull($this->pb->find($id));
        $this->assertSame([], $this->pb->items($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->pb->delete(9999));
    }

    // ── addItem / removeItem ──────────────────────────────────────────────────

    public function testAddItemStoresItem(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->pb->addItem($id, 'SKU-1', 2);
        $items = $this->pb->items($id);
        $this->assertCount(1, $items);
        $this->assertSame('SKU-1', $items[0]['sku']);
        $this->assertSame(2, (int)$items[0]['quantity']);
    }

    public function testAddItemUpsertQuantity(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->pb->addItem($id, 'SKU-1', 1);
        $this->pb->addItem($id, 'SKU-1', 3);
        $items = $this->pb->items($id);
        $this->assertCount(1, $items);
        $this->assertSame(3, (int)$items[0]['quantity']);
    }

    public function testAddItemThrowsOnEmptySku(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->addItem($id, '');
    }

    public function testAddItemThrowsOnZeroQuantity(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->expectException(\InvalidArgumentException::class);
        $this->pb->addItem($id, 'SKU-1', 0);
    }

    public function testRemoveItemDeletesItem(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->pb->addItem($id, 'SKU-1');
        $this->assertTrue($this->pb->removeItem($id, 'SKU-1'));
        $this->assertSame([], $this->pb->items($id));
    }

    public function testRemoveItemReturnsFalseWhenMissing(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->assertFalse($this->pb->removeItem($id, 'NONE'));
    }

    // ── items ─────────────────────────────────────────────────────────────────

    public function testItemsReturnsAlphabetical(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->pb->addItem($id, 'SKU-C');
        $this->pb->addItem($id, 'SKU-A');
        $this->pb->addItem($id, 'SKU-B');
        $skus = array_column($this->pb->items($id), 'sku');
        $this->assertSame(['SKU-A', 'SKU-B', 'SKU-C'], $skus);
    }

    public function testItemsReturnsEmptyForBundleWithNoItems(): void
    {
        $id = $this->pb->create('bundle', 'Bundle', 999);
        $this->assertSame([], $this->pb->items($id));
    }

    // ── allActive ─────────────────────────────────────────────────────────────

    public function testAllActiveReturnsOnlyActiveBundles(): void
    {
        $id1 = $this->pb->create('a', 'Active', 999);
        $this->pb->create('b', 'Inactive', 499);
        $this->pb->activate($id1);
        $active = $this->pb->allActive();
        $this->assertCount(1, $active);
        $this->assertSame('Active', $active[0]['name']);
    }

    public function testAllActiveReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->pb->allActive());
    }
}
