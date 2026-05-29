<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * PriceList — product price catalog with multiple price tiers per SKU.
 *
 * Stores integer-cent prices for SKU + price-type combinations (e.g. retail,
 * wholesale, member). Prices are upserted so re-running an import is safe.
 * Effective/expiry dates allow time-limited promotional prices.
 *
 * This helper intentionally does not model discounts or quantity breaks — it
 * is a flat price lookup by SKU and type. Use it as a canonical price catalog
 * that other helpers (e.g. OrderLine, ShoppingCart) query.
 *
 * ## Usage
 *
 * ```php
 * $pl = new PriceList($pdo);
 *
 * // Set prices (integer cents)
 * $pl->setPrice('SKU-001', 'retail',    1999);
 * $pl->setPrice('SKU-001', 'wholesale', 1299);
 * $pl->setPrice('SKU-001', 'member',    1599);
 *
 * // Look up
 * $cents = $pl->getPrice('SKU-001', 'retail');  // 1999
 * $all   = $pl->forSku('SKU-001');
 *
 * // Time-limited promotion
 * $pl->setPrice('SKU-001', 'promo', 999,
 *     new \DateTimeImmutable('2026-06-01'),
 *     new \DateTimeImmutable('2026-06-07'));
 * $active = $pl->activePrice('SKU-001', 'promo');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE price_list (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     sku          VARCHAR(255) NOT NULL,
 *     price_type   VARCHAR(50)  NOT NULL DEFAULT 'retail',
 *     price_cents  INTEGER      NOT NULL,
 *     effective_at DATETIME     NULL,
 *     expires_at   DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (sku, price_type)
 * );
 * ```
 */
final class PriceList
{
    public const TYPE_RETAIL    = 'retail';
    public const TYPE_WHOLESALE = 'wholesale';
    public const TYPE_MEMBER    = 'member';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (upsert) a price for a SKU + price-type pair.
     *
     * @param int                    $priceCents   Price in integer cents (must be >= 0).
     * @param \DateTimeImmutable|null $effectiveAt  Null = always effective.
     * @param \DateTimeImmutable|null $expiresAt    Null = no expiry.
     * @return int Row ID.
     * @throws \InvalidArgumentException on invalid sku, type, or negative price.
     */
    public function setPrice(
        string $sku,
        string $priceType,
        int $priceCents,
        ?\DateTimeImmutable $effectiveAt = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): int {
        $sku       = trim($sku);
        $priceType = trim($priceType) ?: self::TYPE_RETAIL;
        if ($sku === '') {
            throw new \InvalidArgumentException('sku must not be empty.');
        }
        if ($priceCents < 0) {
            throw new \InvalidArgumentException('price_cents must be >= 0.');
        }

        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $effectiveAt = $effectiveAt?->format('Y-m-d H:i:s');
        $expiresAt   = $expiresAt?->format('Y-m-d H:i:s');

        // SQLite upsert
        $stmt = $this->db()->prepare(
            'INSERT INTO price_list (sku, price_type, price_cents, effective_at, expires_at, created_at, updated_at)
             VALUES (:sku, :type, :cents, :eff, :exp, :now, :now)
             ON CONFLICT (sku, price_type) DO UPDATE SET
                 price_cents  = :cents2,
                 effective_at = :eff2,
                 expires_at   = :exp2,
                 updated_at   = :now2'
        );
        try {
            $stmt->execute([
                ':sku'   => $sku,
                ':type'  => $priceType,
                ':cents' => $priceCents,
                ':eff'   => $effectiveAt,
                ':exp'   => $expiresAt,
                ':now'   => $now,
                ':cents2' => $priceCents,
                ':eff2'  => $effectiveAt,
                ':exp2'  => $expiresAt,
                ':now2'  => $now,
            ]);
            return (int)$this->db()->lastInsertId();
        } catch (\PDOException) {
            // MySQL fallback
            $stmt2 = $this->db()->prepare(
                'INSERT INTO price_list (sku, price_type, price_cents, effective_at, expires_at, created_at, updated_at)
                 VALUES (:sku, :type, :cents, :eff, :exp, :now, :now)
                 ON DUPLICATE KEY UPDATE
                     price_cents  = :cents2,
                     effective_at = :eff2,
                     expires_at   = :exp2,
                     updated_at   = :now2'
            );
            $stmt2->execute([
                ':sku'   => $sku,
                ':type'  => $priceType,
                ':cents' => $priceCents,
                ':eff'   => $effectiveAt,
                ':exp'   => $expiresAt,
                ':now'   => $now,
                ':cents2' => $priceCents,
                ':eff2'  => $effectiveAt,
                ':exp2'  => $expiresAt,
                ':now2'  => $now,
            ]);
            return (int)$this->db()->lastInsertId();
        }
    }

    /**
     * Find a price record by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM price_list WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the price in cents for a SKU + type (ignores effective/expiry dates).
     *
     * @return int|null null if not found.
     */
    public function getPrice(string $sku, string $priceType = self::TYPE_RETAIL): ?int
    {
        $stmt = $this->db()->prepare(
            'SELECT price_cents FROM price_list WHERE sku = :sku AND price_type = :type'
        );
        $stmt->execute([':sku' => trim($sku), ':type' => trim($priceType)]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * Get the price in cents only if it is currently active (within effective/expires window).
     *
     * @return int|null null if not found or outside the active window.
     */
    public function activePrice(string $sku, string $priceType = self::TYPE_RETAIL): ?int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT price_cents FROM price_list
             WHERE sku = :sku AND price_type = :type
               AND (effective_at IS NULL OR effective_at <= :now)
               AND (expires_at   IS NULL OR expires_at   >  :now2)'
        );
        $stmt->execute([
            ':sku'  => trim($sku),
            ':type' => trim($priceType),
            ':now'  => $now,
            ':now2' => $now,
        ]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * Delete a price record.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM price_list WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all prices for a SKU.
     *
     * @return int Number of rows deleted.
     */
    public function deleteSku(string $sku): int
    {
        $stmt = $this->db()->prepare('DELETE FROM price_list WHERE sku = :sku');
        $stmt->execute([':sku' => trim($sku)]);
        return $stmt->rowCount();
    }

    /**
     * List all price records for a SKU.
     *
     * @return list<array<string,mixed>>
     */
    public function forSku(string $sku): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM price_list WHERE sku = :sku ORDER BY price_type ASC'
        );
        $stmt->execute([':sku' => trim($sku)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all distinct SKUs in the price list.
     *
     * @return list<string>
     */
    public function skus(): array
    {
        $stmt = $this->db()->query('SELECT DISTINCT sku FROM price_list ORDER BY sku ASC');
        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
