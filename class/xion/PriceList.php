<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Product price list — manage prices per SKU, currency, and optional tier.
 *
 * Prices are stored as integers (smallest currency unit: cents for USD, yen for JPY).
 * This avoids floating-point issues. Use `Nene\Func\Money` for formatting.
 *
 * Supports pricing tiers (e.g. 'retail', 'wholesale', 'member') per SKU/currency pair.
 * The latest price row for a (sku, currency, tier) triple is the effective price.
 *
 * ## Usage
 *
 * ```php
 * $pl = new PriceList($pdo);
 *
 * // Set a price (upsert)
 * $pl->set('SKU-001', 'USD', 1999); // $19.99
 * $pl->set('SKU-001', 'USD', 1499, tier: 'member');
 *
 * // Get current price
 * $pl->get('SKU-001', 'USD');              // 1999
 * $pl->get('SKU-001', 'USD', 'member');    // 1499
 *
 * // Get all prices for a SKU
 * $pl->allForSku('SKU-001');
 *
 * // Delete a specific tier price
 * $pl->delete('SKU-001', 'USD', 'member');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE price_list (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     sku        VARCHAR(100) NOT NULL,
 *     currency   VARCHAR(3)   NOT NULL,
 *     tier       VARCHAR(50)  NOT NULL DEFAULT 'default',
 *     amount     INTEGER      NOT NULL,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (sku, currency, tier)
 * );
 * ```
 */
final class PriceList
{
    private const DEFAULT_TIER = 'default';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (upsert) a price for a SKU / currency / tier combination.
     *
     * @param string $sku      Product identifier.
     * @param string $currency ISO 4217 currency code (e.g. 'USD', 'JPY').
     * @param int    $amount   Price in the smallest currency unit (cents/yen).
     * @param string $tier     Pricing tier (default 'default').
     * @throws \InvalidArgumentException if SKU or currency is empty, or amount is negative.
     */
    public function set(string $sku, string $currency, int $amount, string $tier = self::DEFAULT_TIER): void
    {
        $sku      = $this->notEmpty($sku, 'sku');
        $currency = strtoupper($this->notEmpty($currency, 'currency'));
        $tier     = trim($tier) !== '' ? trim($tier) : self::DEFAULT_TIER;

        if ($amount < 0) {
            throw new \InvalidArgumentException("Amount must be >= 0, got {$amount}.");
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'INSERT OR REPLACE INTO price_list (sku, currency, tier, amount, updated_at)
                 VALUES (:sku, :currency, :tier, :amount, CURRENT_TIMESTAMP)'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO price_list (sku, currency, tier, amount, updated_at)
                 VALUES (:sku, :currency, :tier, :amount, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_at = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([
            ':sku'      => $sku,
            ':currency' => $currency,
            ':tier'     => $tier,
            ':amount'   => $amount,
        ]);
    }

    /**
     * Get the price for a SKU / currency / tier combination.
     *
     * @return int|null Amount in smallest currency unit, or null if not found.
     */
    public function get(string $sku, string $currency, string $tier = self::DEFAULT_TIER): ?int
    {
        $currency = strtoupper($currency);
        $tier     = trim($tier) !== '' ? trim($tier) : self::DEFAULT_TIER;

        $stmt = $this->db()->prepare(
            'SELECT amount FROM price_list WHERE sku = :sku AND currency = :currency AND tier = :tier LIMIT 1'
        );
        $stmt->execute([':sku' => $sku, ':currency' => $currency, ':tier' => $tier]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * Return all price rows for a SKU.
     *
     * @return list<array{sku: string, currency: string, tier: string, amount: int, updated_at: string}>
     */
    public function allForSku(string $sku): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM price_list WHERE sku = :sku ORDER BY currency ASC, tier ASC'
        );
        $stmt->execute([':sku' => $sku]);
        return array_map(
            static fn (array $r): array => array_merge($r, ['amount' => (int)$r['amount']]),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Delete a specific price row.
     *
     * @return bool True if a row was deleted.
     */
    public function delete(string $sku, string $currency, string $tier = self::DEFAULT_TIER): bool
    {
        $currency = strtoupper($currency);
        $tier     = trim($tier) !== '' ? trim($tier) : self::DEFAULT_TIER;

        $stmt = $this->db()->prepare(
            'DELETE FROM price_list WHERE sku = :sku AND currency = :currency AND tier = :tier'
        );
        $stmt->execute([':sku' => $sku, ':currency' => $currency, ':tier' => $tier]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if any price exists for a SKU.
     */
    public function exists(string $sku): bool
    {
        $stmt = $this->db()->prepare('SELECT 1 FROM price_list WHERE sku = :sku LIMIT 1');
        $stmt->execute([':sku' => $sku]);
        return $stmt->fetchColumn() !== false;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function notEmpty(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$field} must not be empty.");
        }
        return $value;
    }
}
