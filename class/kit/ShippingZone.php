<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * ShippingZone — region → shipping-zone rate lookup with free-shipping threshold.
 *
 * Maps each delivery region to a named zone and a flat shipping rate (integer
 * cents), with an optional per-region free-shipping threshold. Complements
 * `TaxRate` (FT207, region tax lookup): same region-keyed, integer-cent shape,
 * for the shipping side of checkout.
 *
 * ## Usage
 *
 * ```php
 * $sz = new ShippingZone($pdo);
 *
 * $sz->setRate('JP', 'domestic', 500);             // ¥5.00 flat
 * $sz->setRate('US', 'intl', 2500, freeOverCents: 10000); // free over $100
 *
 * $sz->rateFor('JP');            // 500
 * $sz->rateFor('US', 5000);      // 2500 (under threshold)
 * $sz->rateFor('US', 10000);     // 0    (>= free-shipping threshold)
 * $sz->rateFor('XX');            // null (unknown region)
 *
 * $sz->zoneOf('US');             // 'intl'
 * $sz->regionsIn('intl');        // ['US', ...]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE shipping_zones (
 *     id              INTEGER PRIMARY KEY AUTOINCREMENT,
 *     region          VARCHAR(100) NOT NULL,
 *     zone            VARCHAR(100) NOT NULL,
 *     rate_cents      INTEGER      NOT NULL DEFAULT 0,
 *     free_over_cents INTEGER      NOT NULL DEFAULT 0,
 *     created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (region)
 * );
 * ```
 */
final class ShippingZone
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (or replace) the zone + rate for a region. Idempotent per region.
     *
     * @param  string $region        Region/country code.
     * @param  string $zone          Zone name (e.g. 'domestic').
     * @param  int    $rateCents      Flat shipping rate in cents (>= 0).
     * @param  int    $freeOverCents Order total at/above which shipping is free
     *                               (0 = no free-shipping threshold).
     * @throws \InvalidArgumentException on empty region/zone or negative amounts.
     */
    public function setRate(string $region, string $zone, int $rateCents, int $freeOverCents = 0): void
    {
        $region = $this->validate($region, 'Region');
        $zone   = $this->validate($zone, 'Zone');
        if ($rateCents < 0 || $freeOverCents < 0) {
            throw new \InvalidArgumentException('Amounts must not be negative.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'shipping_zones',
            data:         ['region' => $region, 'zone' => $zone, 'rate_cents' => $rateCents, 'free_over_cents' => $freeOverCents],
            conflictCols: ['region'],
            updateCols:   ['zone', 'rate_cents', 'free_over_cents'],
        );
    }

    /**
     * Shipping cost for a region, applying the free-shipping threshold if an
     * order total is given.
     *
     * @param  string   $region     Region code.
     * @param  int|null $orderCents Order subtotal in cents; null skips the threshold.
     * @return int|null             Shipping cents (0 if free), or null if region unknown.
     */
    public function rateFor(string $region, ?int $orderCents = null): ?int
    {
        $region = $this->validate($region, 'Region');

        $stmt = $this->db()->prepare('SELECT rate_cents, free_over_cents FROM shipping_zones WHERE region = ?');
        $stmt->execute([$region]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $free = (int)$row['free_over_cents'];
        if ($free > 0 && $orderCents !== null && $orderCents >= $free) {
            return 0;
        }

        return (int)$row['rate_cents'];
    }

    /**
     * Zone name for a region, or null if unknown.
     */
    public function zoneOf(string $region): ?string
    {
        $region = $this->validate($region, 'Region');
        $stmt   = $this->db()->prepare('SELECT zone FROM shipping_zones WHERE region = ?');
        $stmt->execute([$region]);
        $z = $stmt->fetchColumn();

        return $z === false ? null : (string)$z;
    }

    /**
     * Regions assigned to a zone, ascending.
     *
     * @return array<int,string>
     */
    public function regionsIn(string $zone): array
    {
        $zone = $this->validate($zone, 'Zone');
        $stmt = $this->db()->prepare('SELECT region FROM shipping_zones WHERE zone = ? ORDER BY region ASC');
        $stmt->execute([$zone]);

        return array_map(static fn ($r): string => (string)$r, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Distinct zone names, ascending.
     *
     * @return array<int,string>
     */
    public function zones(): array
    {
        $stmt = $this->db()->query('SELECT DISTINCT zone FROM shipping_zones ORDER BY zone ASC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(static fn ($z): string => (string)$z, $rows);
    }

    /**
     * Remove a region's rate. No-op if absent.
     */
    public function remove(string $region): void
    {
        $region = $this->validate($region, 'Region');
        $stmt   = $this->db()->prepare('DELETE FROM shipping_zones WHERE region = ?');
        $stmt->execute([$region]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function validate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} must not be empty.");
        }

        return $value;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
