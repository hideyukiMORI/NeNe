<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * GeoFence — define named circular geo-fences and check point containment.
 *
 * A geo-fence is a named circle defined by a centre (lat, lng) and a radius
 * in metres. Given any lat/lng point, you can check which fences contain it.
 *
 * Uses the Haversine formula for accurate great-circle distance calculation.
 *
 * ## Usage
 *
 * ```php
 * $gf = new GeoFence($pdo);
 *
 * // Define a fence
 * $gf->define('tokyo-station', 35.6812, 139.7671, 500);  // 500m radius
 *
 * // Check if a point is inside
 * $gf->contains('tokyo-station', 35.6820, 139.7680);     // true/false
 *
 * // Find all fences containing a point
 * $gf->fencesAt(35.6820, 139.7680);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE geo_fences (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name       VARCHAR(255) NOT NULL UNIQUE,
 *     lat        DOUBLE       NOT NULL,
 *     lng        DOUBLE       NOT NULL,
 *     radius_m   INT          NOT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class GeoFence
{
    private const EARTH_RADIUS_M = 6371000.0;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Define (or replace) a named circular geo-fence.
     *
     * @param  float $lat      Centre latitude  (-90 … 90).
     * @param  float $lng      Centre longitude (-180 … 180).
     * @param  int   $radiusM  Radius in metres (> 0).
     * @return int The fence record ID.
     * @throws \InvalidArgumentException if name is empty, coordinates are out of range,
     *                                   or radius is not positive.
     */
    public function define(string $name, float $lat, float $lng, int $radiusM): int
    {
        $name = $this->validateName($name);
        $this->validateCoordinates($lat, $lng);
        if ($radiusM <= 0) {
            throw new \InvalidArgumentException('radius_m must be greater than zero.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO geo_fences (name, lat, lng, radius_m)
                 VALUES (:name, :lat, :lng, :radius)
                 ON CONFLICT (name)
                 DO UPDATE SET lat = excluded.lat, lng = excluded.lng, radius_m = excluded.radius_m'
            )->execute([':name' => $name, ':lat' => $lat, ':lng' => $lng, ':radius' => $radiusM]);
        } else {
            $db->prepare(
                'INSERT INTO geo_fences (name, lat, lng, radius_m)
                 VALUES (:name, :lat, :lng, :radius)
                 ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), radius_m = VALUES(radius_m)'
            )->execute([':name' => $name, ':lat' => $lat, ':lng' => $lng, ':radius' => $radiusM]);
        }

        $stmt = $db->prepare('SELECT id FROM geo_fences WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Remove a named geo-fence.
     *
     * @return bool True if the fence was found and removed.
     */
    public function remove(string $name): bool
    {
        $name = $this->validateName($name);
        $stmt = $this->db()->prepare('DELETE FROM geo_fences WHERE name = :name');
        $stmt->execute([':name' => $name]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a geo-fence by name.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $name): ?array
    {
        $name = $this->validateName($name);
        $stmt = $this->db()->prepare(
            'SELECT id, name, lat, lng, radius_m, created_at FROM geo_fences WHERE name = :name LIMIT 1'
        );
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['lat']      = (float)$row['lat'];
        $row['lng']      = (float)$row['lng'];
        $row['radius_m'] = (int)$row['radius_m'];
        return $row;
    }

    /**
     * Check whether a point (lat, lng) is inside a named geo-fence.
     *
     * @throws \InvalidArgumentException if fence not found or coordinates invalid.
     */
    public function contains(string $name, float $lat, float $lng): bool
    {
        $this->validateCoordinates($lat, $lng);
        $fence = $this->find($name);
        if ($fence === null) {
            throw new \InvalidArgumentException("Geo-fence '{$name}' not found.");
        }
        $dist = $this->haversine($fence['lat'], $fence['lng'], $lat, $lng);
        return $dist <= $fence['radius_m'];
    }

    /**
     * Return the distance in metres from a point to a fence centre.
     *
     * @throws \InvalidArgumentException if fence not found.
     */
    public function distanceTo(string $name, float $lat, float $lng): float
    {
        $this->validateCoordinates($lat, $lng);
        $fence = $this->find($name);
        if ($fence === null) {
            throw new \InvalidArgumentException("Geo-fence '{$name}' not found.");
        }
        return $this->haversine($fence['lat'], $fence['lng'], $lat, $lng);
    }

    /**
     * List all geo-fences that contain the given point.
     *
     * @return list<array{name: string, distance_m: float}>
     */
    public function fencesAt(float $lat, float $lng): array
    {
        $this->validateCoordinates($lat, $lng);
        $stmt = $this->db()->prepare('SELECT name, lat, lng, radius_m FROM geo_fences');
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dist = $this->haversine((float)$row['lat'], (float)$row['lng'], $lat, $lng);
            if ($dist <= (int)$row['radius_m']) {
                $result[] = ['name' => (string)$row['name'], 'distance_m' => round($dist, 2)];
            }
        }
        return $result;
    }

    /**
     * Count defined geo-fences.
     */
    public function count(): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM geo_fences');
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }
        return $name;
    }

    private function validateCoordinates(float $lat, float $lng): void
    {
        if ($lat < -90.0 || $lat > 90.0) {
            throw new \InvalidArgumentException('lat must be between -90 and 90.');
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw new \InvalidArgumentException('lng must be between -180 and 180.');
        }
    }

    /**
     * Haversine great-circle distance in metres.
     */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);

        $dLat = $lat2 - $lat1;
        $dLng = $lng2 - $lng1;

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $c = 2 * asin(sqrt($a));

        return self::EARTH_RADIUS_M * $c;
    }
}
