<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\BulkDiscount;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BulkDiscount.
 */
final class BulkDiscountTest extends TestCase
{
    private PDO $db;
    private BulkDiscount $bd;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE bulk_discount_tiers (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                sku         VARCHAR(150) NOT NULL,
                min_qty     INTEGER      NOT NULL,
                percent_off INTEGER      NOT NULL,
                UNIQUE (sku, min_qty)
            )
        ');
        $this->bd = new BulkDiscount($this->db);
    }

    public function testDiscountForPicksHighestQualifyingTier(): void
    {
        $this->bd->addTier('sku', 10, 5);
        $this->bd->addTier('sku', 50, 12);
        $this->assertSame(0, $this->bd->discountFor('sku', 8));    // below first tier
        $this->assertSame(5, $this->bd->discountFor('sku', 10));   // exactly at tier
        $this->assertSame(5, $this->bd->discountFor('sku', 20));
        $this->assertSame(12, $this->bd->discountFor('sku', 50));
        $this->assertSame(12, $this->bd->discountFor('sku', 100));
    }

    public function testDiscountForNoTiersIsZero(): void
    {
        $this->assertSame(0, $this->bd->discountFor('sku', 100));
    }

    public function testPriceForAppliesDiscount(): void
    {
        $this->bd->addTier('sku', 10, 5);
        // 20 × 1000 = 20000; 5% off = 1000; total 19000
        $this->assertSame(19000, $this->bd->priceFor('sku', 20, 1000));
    }

    public function testPriceForNoDiscountBelowTier(): void
    {
        $this->bd->addTier('sku', 10, 5);
        $this->assertSame(8000, $this->bd->priceFor('sku', 8, 1000)); // 8×1000, no tier
    }

    public function testPriceForRoundsHalfUp(): void
    {
        $this->bd->addTier('sku', 1, 1);
        // 1 × 333 = 333; 1% = 3.33 → 3 (half-up: 333*1+50=383 /100 = 3)
        $this->assertSame(330, $this->bd->priceFor('sku', 1, 333));
        // 1 × 350 = 350; 1% = 3.5 → 4 (half-up)
        $this->assertSame(346, $this->bd->priceFor('sku', 1, 350));
    }

    public function testPriceForZeroQty(): void
    {
        $this->bd->addTier('sku', 1, 50);
        $this->assertSame(0, $this->bd->priceFor('sku', 0, 1000));
    }

    public function testAddTierIsIdempotent(): void
    {
        $this->bd->addTier('sku', 10, 5);
        $this->bd->addTier('sku', 10, 8); // update same tier
        $this->assertSame(8, $this->bd->discountFor('sku', 10));
        $this->assertCount(1, $this->bd->tiers('sku'));
    }

    public function testTiersAscending(): void
    {
        $this->bd->addTier('sku', 50, 12);
        $this->bd->addTier('sku', 10, 5);
        $tiers = $this->bd->tiers('sku');
        $this->assertSame(10, $tiers[0]['min_qty']);
        $this->assertSame(50, $tiers[1]['min_qty']);
    }

    public function testRemoveTier(): void
    {
        $this->bd->addTier('sku', 10, 5);
        $this->bd->addTier('sku', 50, 12);
        $this->bd->removeTier('sku', 10);
        $this->assertSame(0, $this->bd->discountFor('sku', 20)); // 10-tier gone
        $this->assertSame(12, $this->bd->discountFor('sku', 50));
    }

    public function testClear(): void
    {
        $this->bd->addTier('sku', 10, 5);
        $this->bd->addTier('sku', 50, 12);
        $this->assertSame(2, $this->bd->clear('sku'));
        $this->assertSame([], $this->bd->tiers('sku'));
    }

    public function testSkusAreSeparate(): void
    {
        $this->bd->addTier('a', 10, 5);
        $this->assertSame(0, $this->bd->discountFor('b', 100));
    }

    public function testAddTierRejectsBadPercentage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bd->addTier('sku', 10, 101);
    }

    public function testAddTierRejectsZeroMinQty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bd->addTier('sku', 0, 5);
    }
}
