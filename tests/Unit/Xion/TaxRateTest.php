<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\TaxRate;
use PDO;
use PHPUnit\Framework\TestCase;

final class TaxRateTest extends TestCase
{
    private PDO $pdo;
    private TaxRate $tax;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE tax_rates (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                region     VARCHAR(50)  NOT NULL,
                category   VARCHAR(50)  NOT NULL DEFAULT \'default\',
                rate       DECIMAL(8,4) NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (region, category)
            )
        ');
        $this->tax = new TaxRate($this->pdo);
    }

    // ── set ───────────────────────────────────────────────────────────────────

    public function testSetReturnsId(): void
    {
        $id = $this->tax->set('JP', 'default', 10.00);
        $this->assertGreaterThan(0, $id);
    }

    public function testSetStoresCorrectly(): void
    {
        $id  = $this->tax->set('JP', 'default', 10.00);
        $row = $this->tax->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('JP', $row['region']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('default', $row['category']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertEqualsWithDelta(10.00, (float)$row['rate'], 0.0001);
    }

    public function testSetUpsertUpdatesExistingRate(): void
    {
        $this->tax->set('JP', 'default', 8.00);
        $this->tax->set('JP', 'default', 10.00);
        $rate = $this->tax->getRate('JP', 'default');
        $this->assertEqualsWithDelta(10.00, $rate, 0.0001);
    }

    public function testSetDefaultsCategoryToDefault(): void
    {
        $this->tax->set('JP', '', 10.00);
        $rate = $this->tax->getRate('JP', 'default');
        $this->assertNotNull($rate);
    }

    public function testSetThrowsOnEmptyRegion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tax->set('', 'default', 10.00);
    }

    public function testSetThrowsOnNegativeRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tax->set('JP', 'default', -1.0);
    }

    public function testSetAllowsZeroRate(): void
    {
        $id = $this->tax->set('XX', 'default', 0.0);
        $this->assertGreaterThan(0, $id);
        $this->assertEqualsWithDelta(0.0, $this->tax->getRate('XX', 'default'), 0.0001);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->tax->find(9999));
    }

    // ── getRate ───────────────────────────────────────────────────────────────

    public function testGetRateReturnsNullWhenNotConfigured(): void
    {
        $this->assertNull($this->tax->getRate('US', 'default'));
    }

    public function testGetRateReturnsCorrectValue(): void
    {
        $this->tax->set('JP', 'food', 8.00);
        $this->assertEqualsWithDelta(8.00, $this->tax->getRate('JP', 'food'), 0.0001);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRecord(): void
    {
        $id = $this->tax->set('JP', 'default', 10.00);
        $this->assertTrue($this->tax->delete($id));
        $this->assertNull($this->tax->find($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->tax->delete(9999));
    }

    // ── forRegion ─────────────────────────────────────────────────────────────

    public function testForRegionReturnsAllCategoriesForRegion(): void
    {
        $this->tax->set('JP', 'default', 10.00);
        $this->tax->set('JP', 'food', 8.00);
        $this->tax->set('US', 'default', 7.50);

        $list = $this->tax->forRegion('JP');
        $this->assertCount(2, $list);
    }

    public function testForRegionReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->tax->forRegion('XX'));
    }

    // ── regions ───────────────────────────────────────────────────────────────

    public function testRegionsReturnsDistinctRegions(): void
    {
        $this->tax->set('JP', 'default', 10.00);
        $this->tax->set('JP', 'food', 8.00);
        $this->tax->set('US', 'default', 7.50);

        $regions = $this->tax->regions();
        $this->assertCount(2, $regions);
        $this->assertContains('JP', $regions);
        $this->assertContains('US', $regions);
    }

    public function testRegionsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->tax->regions());
    }

    // ── calculateCents ────────────────────────────────────────────────────────

    public function testCalculateCentsReturnsCorrectTax(): void
    {
        $this->tax->set('JP', 'default', 10.00);
        // 1000 cents at 10% = 100 cents
        $this->assertSame(100, $this->tax->calculateCents(1000, 'JP', 'default'));
    }

    public function testCalculateCentsRoundsCorrectly(): void
    {
        $this->tax->set('JP', 'default', 8.00);
        // 125 cents at 8% = 10.0 cents
        $this->assertSame(10, $this->tax->calculateCents(125, 'JP', 'default'));
        // 126 cents at 8% = 10.08 → 10 cents
        $this->assertSame(10, $this->tax->calculateCents(126, 'JP', 'default'));
        // 119 cents at 8% = 9.52 → 10 cents
        $this->assertSame(10, $this->tax->calculateCents(119, 'JP', 'default'));
    }

    public function testCalculateCentsThrowsWhenNoRateConfigured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->tax->calculateCents(1000, 'XX', 'default');
    }

    public function testCalculateCentsZeroRate(): void
    {
        $this->tax->set('XX', 'default', 0.0);
        $this->assertSame(0, $this->tax->calculateCents(1000, 'XX', 'default'));
    }

    // ── totalWithTaxCents ─────────────────────────────────────────────────────

    public function testTotalWithTaxCents(): void
    {
        $this->tax->set('JP', 'default', 10.00);
        // 1000 + 100 = 1100
        $this->assertSame(1100, $this->tax->totalWithTaxCents(1000, 'JP', 'default'));
    }

    public function testTotalWithTaxCentsZeroRate(): void
    {
        $this->tax->set('XX', 'default', 0.0);
        $this->assertSame(500, $this->tax->totalWithTaxCents(500, 'XX', 'default'));
    }

    public function testTotalWithTaxCentsThrowsWhenNoRateConfigured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->tax->totalWithTaxCents(1000, 'XX', 'default');
    }
}
