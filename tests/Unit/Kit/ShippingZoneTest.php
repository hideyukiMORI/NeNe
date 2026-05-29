<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ShippingZone;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ShippingZone.
 */
final class ShippingZoneTest extends TestCase
{
    private PDO $db;
    private ShippingZone $sz;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE shipping_zones (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                region          VARCHAR(100) NOT NULL,
                zone            VARCHAR(100) NOT NULL,
                rate_cents      INTEGER      NOT NULL DEFAULT 0,
                free_over_cents INTEGER      NOT NULL DEFAULT 0,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (region)
            )
        ');
        $this->sz = new ShippingZone($this->db);
    }

    public function testSetAndRate(): void
    {
        $this->sz->setRate('JP', 'domestic', 500);
        $this->assertSame(500, $this->sz->rateFor('JP'));
    }

    public function testRateUnknownRegionIsNull(): void
    {
        $this->assertNull($this->sz->rateFor('XX'));
    }

    public function testFreeShippingThreshold(): void
    {
        $this->sz->setRate('US', 'intl', 2500, freeOverCents: 10000);
        $this->assertSame(2500, $this->sz->rateFor('US', 9999));   // under
        $this->assertSame(0, $this->sz->rateFor('US', 10000));     // exactly at threshold → free
        $this->assertSame(0, $this->sz->rateFor('US', 20000));     // over
        $this->assertSame(2500, $this->sz->rateFor('US'));         // no order total → full rate
    }

    public function testNoThresholdAlwaysCharges(): void
    {
        $this->sz->setRate('JP', 'domestic', 500); // free_over = 0
        $this->assertSame(500, $this->sz->rateFor('JP', 999999));
    }

    public function testZoneOf(): void
    {
        $this->sz->setRate('US', 'intl', 2500);
        $this->assertSame('intl', $this->sz->zoneOf('US'));
        $this->assertNull($this->sz->zoneOf('XX'));
    }

    public function testRegionsIn(): void
    {
        $this->sz->setRate('US', 'intl', 2500);
        $this->sz->setRate('CA', 'intl', 2000);
        $this->sz->setRate('JP', 'domestic', 500);
        $this->assertSame(['CA', 'US'], $this->sz->regionsIn('intl'));
    }

    public function testZonesDistinct(): void
    {
        $this->sz->setRate('US', 'intl', 2500);
        $this->sz->setRate('CA', 'intl', 2000);
        $this->sz->setRate('JP', 'domestic', 500);
        $this->assertSame(['domestic', 'intl'], $this->sz->zones());
    }

    public function testSetRateIsIdempotent(): void
    {
        $this->sz->setRate('JP', 'domestic', 500);
        $this->sz->setRate('JP', 'express', 1200, freeOverCents: 5000);
        $this->assertSame('express', $this->sz->zoneOf('JP'));
        $this->assertSame(0, $this->sz->rateFor('JP', 5000));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM shipping_zones')->fetchColumn());
    }

    public function testRemove(): void
    {
        $this->sz->setRate('JP', 'domestic', 500);
        $this->sz->remove('JP');
        $this->assertNull($this->sz->rateFor('JP'));
    }

    public function testRemoveMissingIsNoop(): void
    {
        $this->sz->remove('XX');
        $this->assertSame([], $this->sz->zones());
    }

    public function testSetRateRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sz->setRate('JP', 'domestic', -1);
    }

    public function testSetRateRejectsEmptyRegion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sz->setRate('  ', 'domestic', 500);
    }
}
