<?php

declare(strict_types=1);

namespace Nene\Func;

/**
 * Geographic distance calculation and bounding box helper.
 *
 * Uses the Haversine formula for great-circle distance on a sphere.
 * Coordinates are in decimal degrees (latitude: -90..90, longitude: -180..180).
 *
 * ## Usage
 *
 * ```php
 * // Distance
 * GeoHelper::distanceKm(35.6895, 139.6917, 34.6937, 135.5023);  // Tokyo → Osaka ≈ 402 km
 * GeoHelper::distanceMi(35.6895, 139.6917, 34.6937, 135.5023);  // ≈ 250 miles
 *
 * // Bounding box for proximity search (±radius)
 * $box = GeoHelper::boundingBox(35.6895, 139.6917, 10.0);
 * // $box = ['minLat' => ..., 'maxLat' => ..., 'minLon' => ..., 'maxLon' => ...]
 * // Use in SQL: WHERE lat BETWEEN :minLat AND :maxLat AND lon BETWEEN :minLon AND :maxLon
 * ```
 */
final class GeoHelper
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const KM_PER_MILE     = 1.60934;

    /**
     * Calculate the great-circle distance between two points in kilometres.
     *
     * @param float $lat1 Latitude of first point (degrees).
     * @param float $lon1 Longitude of first point (degrees).
     * @param float $lat2 Latitude of second point (degrees).
     * @param float $lon2 Longitude of second point (degrees).
     */
    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return self::haversine($lat1, $lon1, $lat2, $lon2);
    }

    /**
     * Calculate the great-circle distance between two points in miles.
     *
     * @param float $lat1 Latitude of first point (degrees).
     * @param float $lon1 Longitude of first point (degrees).
     * @param float $lat2 Latitude of second point (degrees).
     * @param float $lon2 Longitude of second point (degrees).
     */
    public static function distanceMi(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return self::haversine($lat1, $lon1, $lat2, $lon2) / self::KM_PER_MILE;
    }

    /**
     * Generate a bounding box for a proximity search.
     *
     * Returns the min/max latitude and longitude that form a square bounding
     * box around the given point at the given radius in kilometres.
     * Use the box as a fast pre-filter in SQL before applying exact Haversine distance.
     *
     * @param  float $lat      Centre latitude (degrees).
     * @param  float $lon      Centre longitude (degrees).
     * @param  float $radiusKm Radius in kilometres.
     * @return array{minLat: float, maxLat: float, minLon: float, maxLon: float}
     * @throws \InvalidArgumentException if radius is not positive.
     */
    public static function boundingBox(float $lat, float $lon, float $radiusKm): array
    {
        if ($radiusKm <= 0) {
            throw new \InvalidArgumentException("Radius must be positive, got {$radiusKm}.");
        }

        $latDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        // Longitude delta depends on latitude (cosine shrinks at poles)
        $cosLat   = cos(deg2rad($lat));
        $lonDelta = $cosLat > 0.0 ? rad2deg($radiusKm / (self::EARTH_RADIUS_KM * $cosLat)) : 180.0;

        return [
            'minLat' => max(-90.0, $lat - $latDelta),
            'maxLat' => min(90.0, $lat + $latDelta),
            'minLon' => max(-180.0, $lon - $lonDelta),
            'maxLon' => min(180.0, $lon + $lonDelta),
        ];
    }

    /**
     * Convert degrees to radians (public for testing).
     */
    public static function toRadians(float $degrees): float
    {
        return deg2rad($degrees);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }
}
