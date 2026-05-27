<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\GeoHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GeoHelper.
 */
final class GeoHelperTest extends TestCase
{
    // Tokyo: 35.6895, 139.6917
    // Osaka: 34.6937, 135.5023
    // Expected distance ~402 km

    // ── distanceKm ────────────────────────────────────────────────────────────

    public function testDistanceKmSamePointIsZero(): void
    {
        $this->assertEqualsWithDelta(0.0, GeoHelper::distanceKm(35.0, 139.0, 35.0, 139.0), 0.001);
    }

    public function testDistanceKmTokyoOsaka(): void
    {
        $dist = GeoHelper::distanceKm(35.6895, 139.6917, 34.6937, 135.5023);
        // Known distance ≈ 396–402 km; allow 10 km tolerance
        $this->assertEqualsWithDelta(396.0, $dist, 10.0);
    }

    public function testDistanceKmIsSymmetric(): void
    {
        $d1 = GeoHelper::distanceKm(35.6895, 139.6917, 34.6937, 135.5023);
        $d2 = GeoHelper::distanceKm(34.6937, 135.5023, 35.6895, 139.6917);
        $this->assertEqualsWithDelta($d1, $d2, 0.001);
    }

    // ── distanceMi ────────────────────────────────────────────────────────────

    public function testDistanceMiSamePointIsZero(): void
    {
        $this->assertEqualsWithDelta(0.0, GeoHelper::distanceMi(35.0, 139.0, 35.0, 139.0), 0.001);
    }

    public function testDistanceMiIsLessThanKm(): void
    {
        $km = GeoHelper::distanceKm(35.6895, 139.6917, 34.6937, 135.5023);
        $mi = GeoHelper::distanceMi(35.6895, 139.6917, 34.6937, 135.5023);
        $this->assertLessThan($km, $mi);
    }

    public function testDistanceMiConversionRatio(): void
    {
        $km = GeoHelper::distanceKm(35.6895, 139.6917, 34.6937, 135.5023);
        $mi = GeoHelper::distanceMi(35.6895, 139.6917, 34.6937, 135.5023);
        // 1 km ≈ 0.621371 miles
        $this->assertEqualsWithDelta($km * 0.621371, $mi, 0.5);
    }

    // ── boundingBox ───────────────────────────────────────────────────────────

    public function testBoundingBoxReturnsAllKeys(): void
    {
        $box = GeoHelper::boundingBox(35.0, 139.0, 10.0);
        $this->assertArrayHasKey('minLat', $box);
        $this->assertArrayHasKey('maxLat', $box);
        $this->assertArrayHasKey('minLon', $box);
        $this->assertArrayHasKey('maxLon', $box);
    }

    public function testBoundingBoxCentreIsInside(): void
    {
        $lat = 35.6895;
        $lon = 139.6917;
        $box = GeoHelper::boundingBox($lat, $lon, 10.0);
        $this->assertGreaterThanOrEqual($box['minLat'], $lat);
        $this->assertLessThanOrEqual($box['maxLat'], $lat);
        $this->assertGreaterThanOrEqual($box['minLon'], $lon);
        $this->assertLessThanOrEqual($box['maxLon'], $lon);
    }

    public function testBoundingBoxLargerRadiusGivesLargerBox(): void
    {
        $box10 = GeoHelper::boundingBox(35.0, 139.0, 10.0);
        $box50 = GeoHelper::boundingBox(35.0, 139.0, 50.0);
        // box50 spans a larger range than box10
        $this->assertGreaterThan(
            $box10['maxLat'] - $box10['minLat'],
            $box50['maxLat'] - $box50['minLat']
        );
    }

    public function testBoundingBoxClampsToValidRange(): void
    {
        // Near north pole — maxLat should not exceed 90
        $box = GeoHelper::boundingBox(89.9, 0.0, 200.0);
        $this->assertLessThanOrEqual(90.0, $box['maxLat']);
        $this->assertGreaterThanOrEqual(-90.0, $box['minLat']);
    }

    public function testBoundingBoxZeroRadiusThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GeoHelper::boundingBox(35.0, 139.0, 0.0);
    }

    public function testBoundingBoxNegativeRadiusThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GeoHelper::boundingBox(35.0, 139.0, -5.0);
    }
}
