<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * TaxRate — tax rate lookup and amount calculation by region and category.
 *
 * Stores configurable tax rates keyed by region (e.g. country code, state,
 * postal zone) and product category. Rates are stored as percentage values
 * (e.g. 10.00 for 10%). Amount calculations return integer cents to avoid
 * floating-point rounding errors.
 *
 * ## Usage
 *
 * ```php
 * $tax = new TaxRate($pdo);
 *
 * // Set rates
 * $id = $tax->set('JP', 'default', 10.00);
 * $tax->set('JP', 'food', 8.00);
 * $tax->set('US-CA', 'default', 8.25);
 *
 * // Calculate
 * $rate       = $tax->getRate('JP', 'default');           // 10.00
 * $taxCents   = $tax->calculateCents(1000, 'JP', 'default'); // 100
 * $totalCents = $tax->totalWithTaxCents(1000, 'JP', 'default'); // 1100
 *
 * // Manage
 * $all = $tax->forRegion('JP');
 * $tax->delete($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE tax_rates (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     region     VARCHAR(50)    NOT NULL,
 *     category   VARCHAR(50)    NOT NULL DEFAULT 'default',
 *     rate       DECIMAL(8,4)   NOT NULL,
 *     created_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (region, category)
 * );
 * ```
 */
final class TaxRate
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (upsert) a tax rate for a region + category pair.
     *
     * @param float $rate Percentage value (e.g. 10.00 for 10%).
     * @return int Row ID.
     * @throws \InvalidArgumentException on empty region, or negative rate.
     */
    public function set(string $region, string $category, float $rate): int
    {
        $region   = trim($region);
        $category = trim($category) ?: 'default';
        if ($region === '') {
            throw new \InvalidArgumentException('region must not be empty.');
        }
        if ($rate < 0) {
            throw new \InvalidArgumentException('rate must be >= 0.');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // SQLite upsert
        $stmt = $this->db()->prepare(
            'INSERT INTO tax_rates (region, category, rate, created_at, updated_at)
             VALUES (:region, :cat, :rate, :now, :now)
             ON CONFLICT (region, category) DO UPDATE SET rate = :rate2, updated_at = :now2'
        );
        try {
            $stmt->execute([
                ':region' => $region,
                ':cat'    => $category,
                ':rate'   => $rate,
                ':now'    => $now,
                ':rate2'  => $rate,
                ':now2'   => $now,
            ]);
            return (int)$this->db()->lastInsertId();
        } catch (\PDOException) {
            // MySQL fallback: ON DUPLICATE KEY UPDATE
            $stmt2 = $this->db()->prepare(
                'INSERT INTO tax_rates (region, category, rate, created_at, updated_at)
                 VALUES (:region, :cat, :rate, :now, :now)
                 ON DUPLICATE KEY UPDATE rate = :rate2, updated_at = :now2'
            );
            $stmt2->execute([
                ':region' => $region,
                ':cat'    => $category,
                ':rate'   => $rate,
                ':now'    => $now,
                ':rate2'  => $rate,
                ':now2'   => $now,
            ]);
            return (int)$this->db()->lastInsertId();
        }
    }

    /**
     * Find a single rate record by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM tax_rates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the percentage rate for a region + category.
     * Returns null if not configured.
     */
    public function getRate(string $region, string $category = 'default'): ?float
    {
        $stmt = $this->db()->prepare(
            'SELECT rate FROM tax_rates WHERE region = :region AND category = :cat'
        );
        $stmt->execute([':region' => trim($region), ':cat' => trim($category) ?: 'default']);
        $val = $stmt->fetchColumn();
        return $val !== false ? (float)$val : null;
    }

    /**
     * Delete a rate record.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM tax_rates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all rate records for a region.
     *
     * @return list<array<string,mixed>>
     */
    public function forRegion(string $region): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM tax_rates WHERE region = :region ORDER BY category ASC'
        );
        $stmt->execute([':region' => trim($region)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all configured regions (distinct).
     *
     * @return list<string>
     */
    public function regions(): array
    {
        $stmt = $this->db()->query('SELECT DISTINCT region FROM tax_rates ORDER BY region ASC');
        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    /**
     * Calculate tax amount in integer cents.
     *
     * @param int $amountCents Pre-tax amount in cents.
     * @return int Tax amount in cents (rounded to nearest cent).
     * @throws \RuntimeException if no rate is configured.
     */
    public function calculateCents(int $amountCents, string $region, string $category = 'default'): int
    {
        $rate = $this->getRate($region, $category);
        if ($rate === null) {
            throw new \RuntimeException("No tax rate configured for region={$region} category={$category}.");
        }
        return (int)round($amountCents * $rate / 100);
    }

    /**
     * Return the total (amount + tax) in integer cents.
     *
     * @throws \RuntimeException if no rate is configured.
     */
    public function totalWithTaxCents(int $amountCents, string $region, string $category = 'default'): int
    {
        return $amountCents + $this->calculateCents($amountCents, $region, $category);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
