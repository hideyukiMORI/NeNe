<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * StockAlert — user-defined stock/availability alert registrations.
 *
 * Allows users to register an alert to be notified when a product/item
 * becomes available or drops below/above a quantity threshold. A cron job
 * calls pendingTriggers() to find alerts that should fire, then calls
 * markTriggered() after sending the notification.
 *
 * Alerts can be dismissed (kept but silenced) or deleted when no longer
 * wanted.
 *
 * ## Usage
 *
 * ```php
 * $sa = new StockAlert($pdo);
 *
 * // Register an alert when SKU-1 comes back in stock (qty >= 1)
 * $id = $sa->create('user-1', 'sku-1', 1);
 *
 * // Cron: check which alerts should fire
 * $stock = ['sku-1' => 5, 'sku-2' => 0];
 * foreach ($sa->pendingTriggers() as $alert) {
 *     if ($stock[$alert['sku']] >= $alert['threshold']) {
 *         sendNotification($alert);
 *         $sa->markTriggered($alert['id']);
 *     }
 * }
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE stock_alerts (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     sku          VARCHAR(255) NOT NULL,
 *     threshold    INTEGER      NOT NULL DEFAULT 1,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'active',
 *     triggered_at DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class StockAlert
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_TRIGGERED = 'triggered';
    public const STATUS_DISMISSED = 'dismissed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a stock alert.
     *
     * @param int $threshold Minimum quantity that should trigger the alert (>= 1).
     * @return int New alert ID.
     * @throws \InvalidArgumentException on empty userId/sku or threshold < 1.
     */
    public function create(string $userId, string $sku, int $threshold = 1): int
    {
        $userId = trim($userId);
        $sku    = trim($sku);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($sku === '') {
            throw new \InvalidArgumentException('sku must not be empty.');
        }
        if ($threshold < 1) {
            throw new \InvalidArgumentException('threshold must be >= 1.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO stock_alerts (user_id, sku, threshold, status, created_at)
             VALUES (:uid, :sku, :threshold, :status, :now)'
        );
        $stmt->execute([
            ':uid'       => $userId,
            ':sku'       => $sku,
            ':threshold' => $threshold,
            ':status'    => self::STATUS_ACTIVE,
            ':now'       => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve an alert by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM stock_alerts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return all active alerts for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function activeFor(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM stock_alerts WHERE user_id = :uid AND status = :status ORDER BY created_at ASC'
        );
        $stmt->execute([':uid' => trim($userId), ':status' => self::STATUS_ACTIVE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return all active alerts for a specific SKU (for cron processing).
     *
     * @return list<array<string,mixed>>
     */
    public function pendingForSku(string $sku): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM stock_alerts WHERE sku = :sku AND status = :status ORDER BY created_at ASC'
        );
        $stmt->execute([':sku' => trim($sku), ':status' => self::STATUS_ACTIVE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return all active alerts across all SKUs (for cron batch processing).
     *
     * @return list<array<string,mixed>>
     */
    public function pendingTriggers(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM stock_alerts WHERE status = :status ORDER BY sku ASC, created_at ASC'
        );
        $stmt->execute([':status' => self::STATUS_ACTIVE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark an alert as triggered (notification sent).
     *
     * @return bool True if found and updated.
     */
    public function markTriggered(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE stock_alerts SET status = :status, triggered_at = :now WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_TRIGGERED, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Dismiss an alert (silence without deleting).
     *
     * @return bool True if found and dismissed.
     */
    public function dismiss(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE stock_alerts SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_DISMISSED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an alert.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM stock_alerts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete triggered/dismissed alerts older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purge(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM stock_alerts WHERE status != :active AND created_at < :cutoff'
        );
        $stmt->execute([':active' => self::STATUS_ACTIVE, ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
