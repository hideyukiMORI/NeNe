<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * PriceHistory — append-only price change log for products or any priced entity.
 *
 * Records each price change with currency, amount, reason, and who changed it.
 * The current price is always the latest record. Provides aggregation helpers.
 *
 * ## Usage
 *
 * ```php
 * $ph = new PriceHistory($pdo);
 *
 * // Record a price change
 * $ph->record('product', 'SKU-42', 2999, 'USD', 'admin-1', 'Launch price');
 * $ph->record('product', 'SKU-42', 1999, 'USD', 'admin-2', 'Sale discount');
 *
 * // Get current price
 * $ph->current('product', 'SKU-42'); // 1999
 *
 * // Get price history
 * $ph->history('product', 'SKU-42', 10);
 *
 * // Get lowest/highest ever
 * $ph->lowest('product', 'SKU-42');
 * $ph->highest('product', 'SKU-42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE price_history (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type   VARCHAR(100) NOT NULL,
 *     entity_id     VARCHAR(255) NOT NULL,
 *     amount        BIGINT       NOT NULL,
 *     currency      VARCHAR(3)   NOT NULL DEFAULT 'USD',
 *     changed_by    VARCHAR(255) NOT NULL DEFAULT '',
 *     reason        VARCHAR(255) NOT NULL DEFAULT '',
 *     recorded_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class PriceHistory
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a price change.
     *
     * @param  int    $amount    Price in smallest currency unit (e.g. cents).
     * @param  string $currency  ISO 4217 currency code (e.g. 'USD').
     * @return int  The new record ID.
     * @throws \InvalidArgumentException if entity_type or entity_id is empty, or amount is negative.
     */
    public function record(
        string $entityType,
        string $entityId,
        int $amount,
        string $currency = 'USD',
        string $changedBy = '',
        string $reason = ''
    ): int {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        if ($amount < 0) {
            throw new \InvalidArgumentException('amount must not be negative.');
        }
        $currency = strtoupper(trim($currency)) ?: 'USD';

        $this->db()->prepare(
            'INSERT INTO price_history (entity_type, entity_id, amount, currency, changed_by, reason)
             VALUES (:type, :id, :amount, :cur, :by, :reason)'
        )->execute([
            ':type'   => $entityType,
            ':id'     => $entityId,
            ':amount' => $amount,
            ':cur'    => $currency,
            ':by'     => $changedBy,
            ':reason' => $reason,
        ]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Get the current (most recent) price record.
     *
     * @return array<string,mixed>|null  null if no price has been recorded.
     */
    public function current(string $entityType, string $entityId): ?array
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, amount, currency, changed_by, reason, recorded_at
             FROM price_history WHERE entity_type = :type AND entity_id = :id
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the current price amount (integer, smallest unit).
     *
     * @return int|null  null if no price has been recorded.
     */
    public function currentAmount(string $entityType, string $entityId): ?int
    {
        $row = $this->current($entityType, $entityId);
        return $row !== null ? (int)$row['amount'] : null;
    }

    /**
     * Get the price history (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $entityType, string $entityId, int $limit = 20): array
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT id, entity_type, entity_id, amount, currency, changed_by, reason, recorded_at
             FROM price_history WHERE entity_type = :type AND entity_id = :id
             ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get the lowest price ever recorded.
     *
     * @return array<string,mixed>|null
     */
    public function lowest(string $entityType, string $entityId): ?array
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, amount, currency, changed_by, reason, recorded_at
             FROM price_history WHERE entity_type = :type AND entity_id = :id
             ORDER BY amount ASC, id ASC LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the highest price ever recorded.
     *
     * @return array<string,mixed>|null
     */
    public function highest(string $entityType, string $entityId): ?array
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, amount, currency, changed_by, reason, recorded_at
             FROM price_history WHERE entity_type = :type AND entity_id = :id
             ORDER BY amount DESC, id ASC LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Count how many price changes have been recorded.
     */
    public function count(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM price_history WHERE entity_type = :type AND entity_id = :id'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge history older than N days (keeps at least the most recent record).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $entityType, string $entityId, int $days): int
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');

        // Keep the most recent record, purge older ones before cutoff
        $stmt = $this->db()->prepare(
            'DELETE FROM price_history
             WHERE entity_type = :type AND entity_id = :id
               AND recorded_at < :cutoff
               AND id != (
                   SELECT id FROM price_history
                   WHERE entity_type = :type2 AND entity_id = :id2
                   ORDER BY id DESC LIMIT 1
               )'
        );
        $stmt->execute([
            ':type'  => $entityType,
            ':id'    => $entityId,
            ':cutoff' => $cutoff,
            ':type2' => $entityType,
            ':id2'   => $entityId,
        ]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $entityType, string $entityId): array
    {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        return [$entityType, $entityId];
    }
}
