<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\GeoFence;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GeoFence.
 */
final class GeoFenceTest extends TestCase
{
    private PDO $db;
    private GeoFence $gf;

    // Tokyo Station approximate lat/lng
    private const TOKYO_LAT = 35.6812;
    private const TOKYO_LNG = 139.7671;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE geo_fences (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       VARCHAR(255) NOT NULL UNIQUE,
                lat        DOUBLE       NOT NULL,
                lng        DOUBLE       NOT NULL,
                radius_m   INT          NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->gf = new GeoFence($this->db);
    }

    // ── define ────────────────────────────────────────────────────────────────

    public function testDefineReturnsId(): void
    {
        $id = $this->gf->define('tokyo', self::TOKYO_LAT, self::TOKYO_LNG, 500);
        $this->assertGreaterThan(0, $id);
    }

    public function testDefineIsUpsert(): void
    {
        $id1 = $this->gf->define('tokyo', self::TOKYO_LAT, self::TOKYO_LNG, 500);
        $id2 = $this->gf->define('tokyo', self::TOKYO_LAT, self::TOKYO_LNG, 1000);
        $this->assertSame($id1, $id2);
        $this->assertSame(1000, $this->gf->find('tokyo')['radius_m']);
    }

    public function testDefineThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gf->define('', self::TOKYO_LAT, self::TOKYO_LNG, 500);
    }

    public function testDefineThrowsOnInvalidLat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gf->define('bad', 91.0, self::TOKYO_LNG, 500);
    }

    public function testDefineThrowsOnInvalidLng(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gf->define('bad', self::TOKYO_LAT, 181.0, 500);
    }

    public function testDefineThrowsOnZeroRadius(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gf->define('bad', self::TOKYO_LAT, self::TOKYO_LNG, 0);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsFence(): void
    {
        $this->gf->define('tokyo', self::TOKYO_LAT, self::TOKYO_LNG, 500);
        $fence = $this->gf->find('tokyo');
        $this->assertSame('tokyo', $fence['name']);
        $this->assertEqualsWithDelta(self::TOKYO_LAT, $fence['lat'], 0.0001);
        $this->assertSame(500, $fence['radius_m']);
    }

    public function testFindReturnsNullForUnknown(): void
    {
        $this->assertNull($this->gf->find('nonexistent'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesFence(): void
    {
        $this->gf->define('tokyo', self::TOKYO_LAT, self::TOKYO_LNG, 500);
        $this->assertTrue($this->gf->remove('tokyo'));
        $this->assertNull($this->gf->find('tokyo'));
    }

    public function testRemoveReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->gf->remove('nonexistent'));
    }

    // ── contains ──────────────────────────────────────────────────────────────

    public function testContainsReturnsTrueForPointInsideFence(): void
    {
        // 500m radius around Tokyo Station
        $this->gf->define('tokyo', self::TOKYO_LAT, self::TOKYO_LNG, 500);
        // ~50m away — should be inside
        $this->assertTrue($this->gf->contains('tokyo', 35.6815, 139.7674));
    }

    public function testContainsReturnsFalseForPointOutsideFence(): void
    {
        // 500m radius around Tokyo Station
        $this->gf->define('tokyo', self::TOKYO_LAT, self::TOKYO_LNG, 500);
        // ~10km away (Shibuya) — should be outside
        $this->assertFalse($this->gf->contains('tokyo', 35.6580, 139.7016));
    }

    public function testContainsThrowsForUnknownFence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gf->contains('unknown', 35.0, 139.0);
    }

    // ── distanceTo ────────────────────────────────────────────────────────────

    public function testDistanceToReturnsMetre(): void
    {
        $this->gf->define('tokyo', self::TOKYO_LAT, self::TOKYO_LNG, 500);
        // Same point should be ~0m
        $dist = $this->gf->distanceTo('tokyo', self::TOKYO_LAT, self::TOKYO_LNG);
        $this->assertEqualsWithDelta(0.0, $dist, 1.0);
    }

    public function testDistanceToApproximation(): void
    {
        // 1 degree latitude ≈ 111km
        $this->gf->define('origin', 0.0, 0.0, 1000);
        $dist = $this->gf->distanceTo('origin', 1.0, 0.0);
        $this->assertEqualsWithDelta(111195.0, $dist, 2000.0);
    }

    // ── fencesAt ──────────────────────────────────────────────────────────────

    public function testFencesAtReturnsContainingFences(): void
    {
        $this->gf->define('large', self::TOKYO_LAT, self::TOKYO_LNG, 10000);  // 10km
        $this->gf->define('small', self::TOKYO_LAT, self::TOKYO_LNG, 10);    // 10m
        // Point 50m away
        $at = $this->gf->fencesAt(35.6815, 139.7674);
        $names = array_column($at, 'name');
        $this->assertContains('large', $names);
        $this->assertNotContains('small', $names);
    }

    public function testFencesAtReturnsEmptyForPointInNoFence(): void
    {
        $this->gf->define('tokyo', self::TOKYO_LAT, self::TOKYO_LNG, 10);
        // Somewhere far away
        $at = $this->gf->fencesAt(0.0, 0.0);
        $this->assertSame([], $at);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroInitially(): void
    {
        $this->assertSame(0, $this->gf->count());
    }

    public function testCountIncrements(): void
    {
        $this->gf->define('a', 0.0, 0.0, 100);
        $this->gf->define('b', 1.0, 1.0, 100);
        $this->assertSame(2, $this->gf->count());
    }
}
