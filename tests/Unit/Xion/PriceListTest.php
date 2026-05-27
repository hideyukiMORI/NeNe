<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\PriceList;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PriceList.
 */
final class PriceListTest extends TestCase
{
    private PDO $db;
    private PriceList $pl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE price_list (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                sku        VARCHAR(100) NOT NULL,
                currency   VARCHAR(3)   NOT NULL,
                tier       VARCHAR(50)  NOT NULL DEFAULT \'default\',
                amount     INTEGER      NOT NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (sku, currency, tier)
            )
        ');
        $this->pl = new PriceList($this->db);
    }

    // ── set ───────────────────────────────────────────────────────────────────

    public function testSetCreatesPrice(): void
    {
        $this->pl->set('SKU-1', 'USD', 1999);
        $this->assertSame(1999, $this->pl->get('SKU-1', 'USD'));
    }

    public function testSetIsUpsert(): void
    {
        $this->pl->set('SKU-1', 'USD', 1999);
        $this->pl->set('SKU-1', 'USD', 2499);
        $this->assertSame(2499, $this->pl->get('SKU-1', 'USD'));
    }

    public function testSetNormalisescurrencyToUppercase(): void
    {
        $this->pl->set('SKU-1', 'usd', 1000);
        $this->assertSame(1000, $this->pl->get('SKU-1', 'USD'));
    }

    public function testSetWithTier(): void
    {
        $this->pl->set('SKU-1', 'USD', 1999);
        $this->pl->set('SKU-1', 'USD', 1499, tier: 'member');
        $this->assertSame(1999, $this->pl->get('SKU-1', 'USD'));
        $this->assertSame(1499, $this->pl->get('SKU-1', 'USD', 'member'));
    }

    public function testSetThrowsOnEmptySku(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pl->set('', 'USD', 100);
    }

    public function testSetThrowsOnEmptyCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pl->set('SKU-1', '', 100);
    }

    public function testSetThrowsOnNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pl->set('SKU-1', 'USD', -1);
    }

    public function testSetAllowsZeroAmount(): void
    {
        $this->pl->set('SKU-1', 'USD', 0);
        $this->assertSame(0, $this->pl->get('SKU-1', 'USD'));
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingPrice(): void
    {
        $this->assertNull($this->pl->get('NO-SUCH-SKU', 'USD'));
    }

    public function testGetReturnsNullForMissingTier(): void
    {
        $this->pl->set('SKU-1', 'USD', 1999);
        $this->assertNull($this->pl->get('SKU-1', 'USD', 'vip'));
    }

    // ── allForSku ─────────────────────────────────────────────────────────────

    public function testAllForSkuReturnsAllPrices(): void
    {
        $this->pl->set('SKU-1', 'USD', 1999);
        $this->pl->set('SKU-1', 'JPY', 2000);
        $this->pl->set('SKU-1', 'USD', 1499, tier: 'member');
        $all = $this->pl->allForSku('SKU-1');
        $this->assertCount(3, $all);
    }

    public function testAllForSkuReturnsEmptyForUnknownSku(): void
    {
        $this->assertSame([], $this->pl->allForSku('GHOST'));
    }

    public function testAllForSkuAmountIsInt(): void
    {
        $this->pl->set('SKU-1', 'USD', 1999);
        $all = $this->pl->allForSku('SKU-1');
        $this->assertSame(1999, $all[0]['amount']);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRow(): void
    {
        $this->pl->set('SKU-1', 'USD', 1999, tier: 'member');
        $this->assertTrue($this->pl->delete('SKU-1', 'USD', 'member'));
        $this->assertNull($this->pl->get('SKU-1', 'USD', 'member'));
    }

    public function testDeleteReturnsFalseForMissingRow(): void
    {
        $this->assertFalse($this->pl->delete('GHOST', 'USD'));
    }

    // ── exists ────────────────────────────────────────────────────────────────

    public function testExistsReturnsFalseForNewSku(): void
    {
        $this->assertFalse($this->pl->exists('GHOST'));
    }

    public function testExistsReturnsTrueAfterSet(): void
    {
        $this->pl->set('SKU-1', 'USD', 1000);
        $this->assertTrue($this->pl->exists('SKU-1'));
    }
}
