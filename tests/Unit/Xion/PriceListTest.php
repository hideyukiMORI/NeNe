<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\PriceList;
use PDO;
use PHPUnit\Framework\TestCase;

final class PriceListTest extends TestCase
{
    private PDO $pdo;
    private PriceList $pl;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE price_list (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                sku          VARCHAR(255) NOT NULL,
                price_type   VARCHAR(50)  NOT NULL DEFAULT \'retail\',
                price_cents  INTEGER      NOT NULL,
                effective_at DATETIME     NULL,
                expires_at   DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (sku, price_type)
            )
        ');
        $this->pl = new PriceList($this->pdo);
    }

    // ── setPrice ──────────────────────────────────────────────────────────────

    public function testSetPriceReturnsId(): void
    {
        $id = $this->pl->setPrice('SKU-001', 'retail', 1999);
        $this->assertGreaterThan(0, $id);
    }

    public function testSetPriceStoresCorrectly(): void
    {
        $id  = $this->pl->setPrice('SKU-001', PriceList::TYPE_RETAIL, 1999);
        $row = $this->pl->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('SKU-001', $row['sku']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(PriceList::TYPE_RETAIL, $row['price_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1999, (int)$row['price_cents']);
    }

    public function testSetPriceUpsertUpdatesExisting(): void
    {
        $this->pl->setPrice('SKU-001', 'retail', 1999);
        $this->pl->setPrice('SKU-001', 'retail', 2499);
        $this->assertSame(2499, $this->pl->getPrice('SKU-001', 'retail'));
    }

    public function testSetPriceDefaultsTypeToRetail(): void
    {
        $this->pl->setPrice('SKU-001', '', 999);
        $this->assertSame(999, $this->pl->getPrice('SKU-001', 'retail'));
    }

    public function testSetPriceAllowsZeroCents(): void
    {
        $id = $this->pl->setPrice('FREE-001', 'retail', 0);
        $this->assertGreaterThan(0, $id);
        $this->assertSame(0, $this->pl->getPrice('FREE-001', 'retail'));
    }

    public function testSetPriceThrowsOnEmptySku(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pl->setPrice('', 'retail', 999);
    }

    public function testSetPriceThrowsOnNegativeCents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pl->setPrice('SKU-001', 'retail', -1);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->pl->find(9999));
    }

    // ── getPrice ──────────────────────────────────────────────────────────────

    public function testGetPriceReturnsCorrectValue(): void
    {
        $this->pl->setPrice('SKU-001', 'wholesale', 1299);
        $this->assertSame(1299, $this->pl->getPrice('SKU-001', 'wholesale'));
    }

    public function testGetPriceReturnsNullWhenNotSet(): void
    {
        $this->assertNull($this->pl->getPrice('NONE', 'retail'));
    }

    public function testGetPriceIgnoresDateWindow(): void
    {
        // Expired price still returned by getPrice
        $past = new \DateTimeImmutable('-1 year');
        $this->pl->setPrice('SKU-001', 'promo', 999, null, $past);
        $this->assertSame(999, $this->pl->getPrice('SKU-001', 'promo'));
    }

    // ── activePrice ───────────────────────────────────────────────────────────

    public function testActivePriceReturnsWhenNoDates(): void
    {
        $this->pl->setPrice('SKU-001', 'retail', 1999);
        $this->assertSame(1999, $this->pl->activePrice('SKU-001', 'retail'));
    }

    public function testActivePriceReturnsNullWhenExpired(): void
    {
        $past = new \DateTimeImmutable('-1 second');
        $this->pl->setPrice('SKU-001', 'promo', 999, null, $past);
        $this->assertNull($this->pl->activePrice('SKU-001', 'promo'));
    }

    public function testActivePriceReturnsNullWhenNotYetEffective(): void
    {
        $future = new \DateTimeImmutable('+1 hour');
        $this->pl->setPrice('SKU-001', 'promo', 999, $future, null);
        $this->assertNull($this->pl->activePrice('SKU-001', 'promo'));
    }

    public function testActivePriceReturnsWhenInWindow(): void
    {
        $past   = new \DateTimeImmutable('-1 hour');
        $future = new \DateTimeImmutable('+1 hour');
        $this->pl->setPrice('SKU-001', 'promo', 999, $past, $future);
        $this->assertSame(999, $this->pl->activePrice('SKU-001', 'promo'));
    }

    public function testActivePriceReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->pl->activePrice('NONE', 'retail'));
    }

    // ── delete / deleteSku ────────────────────────────────────────────────────

    public function testDeleteRemovesRecord(): void
    {
        $id = $this->pl->setPrice('SKU-001', 'retail', 1999);
        $this->assertTrue($this->pl->delete($id));
        $this->assertNull($this->pl->find($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->pl->delete(9999));
    }

    public function testDeleteSkuRemovesAllPricesForSku(): void
    {
        $this->pl->setPrice('SKU-001', 'retail', 1999);
        $this->pl->setPrice('SKU-001', 'wholesale', 1299);
        $this->pl->setPrice('SKU-002', 'retail', 2999);

        $count = $this->pl->deleteSku('SKU-001');
        $this->assertSame(2, $count);
        $this->assertSame([], $this->pl->forSku('SKU-001'));
        $this->assertCount(1, $this->pl->forSku('SKU-002'));
    }

    // ── forSku ────────────────────────────────────────────────────────────────

    public function testForSkuReturnsAllPriceTypes(): void
    {
        $this->pl->setPrice('SKU-001', 'retail', 1999);
        $this->pl->setPrice('SKU-001', 'wholesale', 1299);
        $this->pl->setPrice('SKU-002', 'retail', 2999);

        $list = $this->pl->forSku('SKU-001');
        $this->assertCount(2, $list);
    }

    public function testForSkuReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->pl->forSku('MISSING'));
    }

    // ── skus ─────────────────────────────────────────────────────────────────

    public function testSkusReturnsDistinctSkus(): void
    {
        $this->pl->setPrice('SKU-001', 'retail', 1999);
        $this->pl->setPrice('SKU-001', 'wholesale', 1299);
        $this->pl->setPrice('SKU-002', 'retail', 2999);

        $skus = $this->pl->skus();
        $this->assertCount(2, $skus);
        $this->assertContains('SKU-001', $skus);
        $this->assertContains('SKU-002', $skus);
    }

    public function testSkusReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->pl->skus());
    }
}
