<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * BulkDiscount — quantity-tiered percentage discounts per SKU.
 *
 * Defines "buy N or more, get X% off" tiers per product and computes the
 * discounted total for a given quantity (integer cents, no floats). The
 * applicable tier is the highest `min_qty` that does not exceed the purchased
 * quantity. Distinct from `CouponCode` (code-based discounts) and `PriceList`
 * (per-customer-type price tiers): this is volume pricing.
 *
 * ## Usage
 *
 * ```php
 * $bd = new BulkDiscount($pdo);
 *
 * $bd->addTier('sku-1', minQty: 10, percentOff: 5);
 * $bd->addTier('sku-1', minQty: 50, percentOff: 12);
 *
 * $bd->discountFor('sku-1', 8);   // 0  (below first tier)
 * $bd->discountFor('sku-1', 20);  // 5
 * $bd->discountFor('sku-1', 100); // 12
 *
 * $bd->priceFor('sku-1', 20, 1000); // 20 × $10 = $200, −5% = 19000 cents
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE bulk_discount_tiers (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     sku         VARCHAR(150) NOT NULL,
 *     min_qty     INTEGER      NOT NULL,
 *     percent_off INTEGER      NOT NULL,
 *     UNIQUE (sku, min_qty)
 * );
 * ```
 */
final class BulkDiscount
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add or update a discount tier. Idempotent per (sku, minQty).
     *
     * @param  string $sku        Product identifier.
     * @param  int    $minQty     Minimum quantity for the tier (>= 1).
     * @param  int    $percentOff Discount percentage (0–100).
     * @throws \InvalidArgumentException on empty SKU, minQty < 1, or bad percentage.
     */
    public function addTier(string $sku, int $minQty, int $percentOff): void
    {
        $sku = $this->validate($sku);
        if ($minQty < 1) {
            throw new \InvalidArgumentException('Minimum quantity must be at least 1.');
        }
        if ($percentOff < 0 || $percentOff > 100) {
            throw new \InvalidArgumentException('Percentage must be between 0 and 100.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'bulk_discount_tiers',
            data:         ['sku' => $sku, 'min_qty' => $minQty, 'percent_off' => $percentOff],
            conflictCols: ['sku', 'min_qty'],
            updateCols:   ['percent_off'],
        );
    }

    /**
     * Discount percentage applicable to a quantity (highest qualifying tier).
     *
     * @param  string $sku Product identifier.
     * @param  int    $qty Purchase quantity.
     * @return int         Percentage off (0 if no tier qualifies).
     */
    public function discountFor(string $sku, int $qty): int
    {
        $sku  = $this->validate($sku);
        $stmt = $this->db()->prepare(
            'SELECT percent_off FROM bulk_discount_tiers WHERE sku = ? AND min_qty <= ?
             ORDER BY min_qty DESC LIMIT 1'
        );
        $stmt->execute([$sku, $qty]);
        $pct = $stmt->fetchColumn();

        return $pct === false ? 0 : (int)$pct;
    }

    /**
     * Discounted total for `qty × unitCents`, applying the qualifying tier.
     * Rounds the discount amount half-up to whole cents.
     *
     * @param  string $sku       Product identifier.
     * @param  int    $qty       Quantity (>= 0).
     * @param  int    $unitCents Unit price in cents (>= 0).
     * @return int               Total in cents after discount.
     * @throws \InvalidArgumentException on negative qty/price.
     */
    public function priceFor(string $sku, int $qty, int $unitCents): int
    {
        if ($qty < 0 || $unitCents < 0) {
            throw new \InvalidArgumentException('Quantity and unit price must not be negative.');
        }

        $gross    = $qty * $unitCents;
        $pct      = $this->discountFor($sku, $qty);
        $discount = intdiv($gross * $pct + 50, 100); // half-up

        return $gross - $discount;
    }

    /**
     * All tiers for a SKU, ascending by minimum quantity.
     *
     * @return array<int,array{min_qty:int,percent_off:int}>
     */
    public function tiers(string $sku): array
    {
        $sku  = $this->validate($sku);
        $stmt = $this->db()->prepare('SELECT min_qty, percent_off FROM bulk_discount_tiers WHERE sku = ? ORDER BY min_qty ASC');
        $stmt->execute([$sku]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['min_qty' => (int)$row['min_qty'], 'percent_off' => (int)$row['percent_off']];
        }

        return $out;
    }

    /**
     * Remove a single tier. No-op if absent.
     */
    public function removeTier(string $sku, int $minQty): void
    {
        $sku  = $this->validate($sku);
        $stmt = $this->db()->prepare('DELETE FROM bulk_discount_tiers WHERE sku = ? AND min_qty = ?');
        $stmt->execute([$sku, $minQty]);
    }

    /**
     * Remove all tiers for a SKU. Returns the number removed.
     */
    public function clear(string $sku): int
    {
        $sku  = $this->validate($sku);
        $stmt = $this->db()->prepare('DELETE FROM bulk_discount_tiers WHERE sku = ?');
        $stmt->execute([$sku]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function validate(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('SKU must not be empty.');
        }

        return $sku;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
