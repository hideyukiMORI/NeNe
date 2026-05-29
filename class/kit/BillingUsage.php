<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * BillingUsage — metered usage tracking for billing.
 *
 * Records individual usage events (API calls, storage bytes, messages sent, …)
 * per account/user and metric, grouped by billing period. Provides period
 * aggregation and overage detection for metered billing systems.
 *
 * All quantities are integers (units of the metric). Monetary conversion is the
 * caller's responsibility.
 *
 * ## Usage
 *
 * ```php
 * $bu = new BillingUsage($pdo);
 *
 * // Record events
 * $bu->record('acct-1', 'api_calls', 1);
 * $bu->record('acct-1', 'api_calls', 5);
 * $bu->record('acct-1', 'storage_bytes', 1024);
 *
 * // Query a period
 * $total = $bu->sum('acct-1', 'api_calls', '2026-05');   // 6
 * $summary = $bu->summary('acct-1', '2026-05');
 * // → [['metric' => 'api_calls', 'total' => 6], ...]
 *
 * // Overage check
 * $over = $bu->overage('acct-1', 'api_calls', '2026-05', 5); // 1
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE billing_usage (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     account_id VARCHAR(255) NOT NULL,
 *     metric     VARCHAR(100) NOT NULL,
 *     quantity   INTEGER      NOT NULL DEFAULT 1,
 *     period     VARCHAR(20)  NOT NULL,
 *     recorded_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_billing_usage_lookup ON billing_usage (account_id, metric, period);
 * ```
 */
final class BillingUsage
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a usage event.
     *
     * @param string $accountId Account or user identifier.
     * @param string $metric    Metric name (e.g. 'api_calls', 'storage_bytes').
     * @param int    $quantity  Units consumed (must be >= 1).
     * @param string $period    Billing period key (e.g. '2026-05'). Defaults to
     *                          the current month in 'Y-m' format.
     * @throws \InvalidArgumentException on empty accountId/metric or quantity < 1.
     */
    public function record(
        string $accountId,
        string $metric,
        int $quantity = 1,
        string $period = ''
    ): void {
        [$accountId, $metric] = $this->validateAccountMetric($accountId, $metric);
        if ($quantity < 1) {
            throw new \InvalidArgumentException('quantity must be >= 1.');
        }
        if ($period === '') {
            $period = (new \DateTimeImmutable())->format('Y-m');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO billing_usage (account_id, metric, quantity, period, recorded_at)
             VALUES (:aid, :metric, :qty, :period, :now)'
        );
        $stmt->execute([
            ':aid'    => $accountId,
            ':metric' => $metric,
            ':qty'    => $quantity,
            ':period' => $period,
            ':now'    => $now,
        ]);
    }

    /**
     * Return the total usage for an account + metric in a period.
     *
     * @return int Total quantity (0 if no events).
     */
    public function sum(string $accountId, string $metric, string $period): int
    {
        [$accountId, $metric] = $this->validateAccountMetric($accountId, $metric);
        $stmt                 = $this->db()->prepare(
            'SELECT COALESCE(SUM(quantity), 0) AS total
             FROM billing_usage
             WHERE account_id = :aid AND metric = :metric AND period = :period'
        );
        $stmt->execute([':aid' => $accountId, ':metric' => $metric, ':period' => $period]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row !== false ? $row['total'] : 0);
    }

    /**
     * Return a per-metric usage summary for an account in a period.
     *
     * @return list<array<string,mixed>> Each row: {metric, total}
     */
    public function summary(string $accountId, string $period): array
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new \InvalidArgumentException('account_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT metric, COALESCE(SUM(quantity), 0) AS total
             FROM billing_usage
             WHERE account_id = :aid AND period = :period
             GROUP BY metric
             ORDER BY metric ASC'
        );
        $stmt->execute([':aid' => $accountId, ':period' => $period]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return how many units over the given limit the account is in a period.
     *
     * Returns 0 if within the limit.
     *
     * @param int $limit Free allowance for the period.
     */
    public function overage(string $accountId, string $metric, string $period, int $limit): int
    {
        $total = $this->sum($accountId, $metric, $period);
        return max(0, $total - $limit);
    }

    /**
     * Delete all usage records for an account + period (billing cycle reset).
     *
     * @return int Number of rows deleted.
     */
    public function reset(string $accountId, string $period): int
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new \InvalidArgumentException('account_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'DELETE FROM billing_usage WHERE account_id = :aid AND period = :period'
        );
        $stmt->execute([':aid' => $accountId, ':period' => $period]);
        return $stmt->rowCount();
    }

    /**
     * Delete records older than a cutoff date (e.g. after archiving to a data warehouse).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM billing_usage WHERE recorded_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
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
    private function validateAccountMetric(string $accountId, string $metric): array
    {
        $accountId = trim($accountId);
        $metric    = trim($metric);
        if ($accountId === '') {
            throw new \InvalidArgumentException('account_id must not be empty.');
        }
        if ($metric === '') {
            throw new \InvalidArgumentException('metric must not be empty.');
        }
        return [$accountId, $metric];
    }
}
