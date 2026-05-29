<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * HealthCheck — service/component health monitoring log.
 *
 * Records health check results for named services or components with a
 * status, optional message, and response time. Surfaces the latest status,
 * average response time, and failure rate for dashboards and alerting.
 *
 * Distinct from IntegrationLog (individual API calls); HealthCheck is a
 * periodic monitoring probe result — "is the service healthy right now?"
 *
 * ## Usage
 *
 * ```php
 * $hc = new HealthCheck($pdo);
 *
 * $hc->record('database', HealthCheck::STATUS_OK,      120);
 * $hc->record('cache',    HealthCheck::STATUS_DEGRADED, 850, 'High latency');
 * $hc->record('queue',    HealthCheck::STATUS_FAIL,     0,   'Connection refused');
 *
 * $status  = $hc->latestStatus('database');    // 'ok'
 * $all     = $hc->latestAll();                 // ['database' => 'ok', 'cache' => 'degraded', ...]
 * $avgMs   = $hc->avgResponseTime('database'); // float ms
 * $rate    = $hc->failureRate('queue', 10);    // 1.0 (100%)
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE health_checks (
 *     id              INTEGER PRIMARY KEY AUTOINCREMENT,
 *     service         VARCHAR(100) NOT NULL,
 *     status          VARCHAR(20)  NOT NULL,
 *     response_time   INTEGER      NOT NULL DEFAULT 0,
 *     message         TEXT         NULL,
 *     checked_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_health_checks_service ON health_checks (service, checked_at);
 * ```
 */
final class HealthCheck
{
    public const STATUS_OK       = 'ok';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_FAIL     = 'fail';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a health check result.
     *
     * @param string   $service      Service or component name.
     * @param string   $status       One of STATUS_OK / STATUS_DEGRADED / STATUS_FAIL.
     * @param int      $responseTime Response time in milliseconds (0 for failed checks).
     * @param string   $message      Optional diagnostic message.
     * @throws \InvalidArgumentException on empty service or invalid status.
     */
    public function record(
        string $service,
        string $status,
        int $responseTime = 0,
        string $message = ''
    ): int {
        $service = trim($service);
        if ($service === '') {
            throw new \InvalidArgumentException('service must not be empty.');
        }
        if (!in_array($status, [self::STATUS_OK, self::STATUS_DEGRADED, self::STATUS_FAIL], true)) {
            throw new \InvalidArgumentException(
                "status must be one of: ok, degraded, fail. Got '{$status}'."
            );
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO health_checks (service, status, response_time, message, checked_at)
             VALUES (:service, :status, :rt, :msg, :now)'
        );
        $stmt->execute([
            ':service' => $service,
            ':status'  => $status,
            ':rt'      => $responseTime,
            ':msg'     => $message !== '' ? $message : null,
            ':now'     => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return the status of the most recent check for a service.
     *
     * @return string|null null if no checks recorded.
     */
    public function latestStatus(string $service): ?string
    {
        $service = trim($service);
        $stmt    = $this->db()->prepare(
            'SELECT status FROM health_checks WHERE service = :service ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':service' => $service]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (string)$row['status'] : null;
    }

    /**
     * Return a map of service → latest status for all services.
     *
     * @return array<string,string>
     */
    public function latestAll(): array
    {
        // Subquery to get the latest check id per service
        $stmt = $this->db()->query(
            'SELECT h.service, h.status
             FROM health_checks h
             INNER JOIN (
                 SELECT service, MAX(id) AS max_id
                 FROM health_checks
                 GROUP BY service
             ) latest ON h.service = latest.service AND h.id = latest.max_id
             ORDER BY h.service ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['service']] = (string)$row['status'];
        }
        return $result;
    }

    /**
     * Return recent check records for a service (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function recent(string $service, int $limit = 20): array
    {
        $service = trim($service);
        $limit   = max(1, $limit);
        $stmt    = $this->db()->prepare(
            'SELECT id, service, status, response_time, message, checked_at
             FROM health_checks
             WHERE service = :service
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':service', $service, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return the average response time (ms) for a service over the last $n checks.
     *
     * Returns 0.0 if no checks found.
     */
    public function avgResponseTime(string $service, int $last = 10): float
    {
        $service = trim($service);
        $last    = max(1, $last);

        // Average of the last N rows
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'SELECT AVG(response_time) AS avg_rt
                    FROM (SELECT response_time FROM health_checks
                          WHERE service = :service ORDER BY id DESC LIMIT :last)';
        } else {
            $sql = 'SELECT AVG(response_time) AS avg_rt
                    FROM (SELECT response_time FROM health_checks
                          WHERE service = :service ORDER BY id DESC LIMIT :last) sub';
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->bindValue(':service', $service, PDO::PARAM_STR);
        $stmt->bindValue(':last', $last, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false && $row['avg_rt'] !== null ? (float)$row['avg_rt'] : 0.0;
    }

    /**
     * Return the failure rate (0.0–1.0) for a service over the last $n checks.
     *
     * A "failure" is STATUS_FAIL only. Returns 0.0 if no checks found.
     */
    public function failureRate(string $service, int $last = 10): float
    {
        $service = trim($service);
        $last    = max(1, $last);

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'SELECT COUNT(*) AS total,
                           SUM(CASE WHEN status = \'fail\' THEN 1 ELSE 0 END) AS failures
                    FROM (SELECT status FROM health_checks
                          WHERE service = :service ORDER BY id DESC LIMIT :last)';
        } else {
            $sql = 'SELECT COUNT(*) AS total,
                           SUM(CASE WHEN status = \'fail\' THEN 1 ELSE 0 END) AS failures
                    FROM (SELECT status FROM health_checks
                          WHERE service = :service ORDER BY id DESC LIMIT :last) sub';
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->bindValue(':service', $service, PDO::PARAM_STR);
        $stmt->bindValue(':last', $last, PDO::PARAM_INT);
        $stmt->execute();
        $row   = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = $row !== false ? (int)$row['total'] : 0;
        if ($total === 0) {
            return 0.0;
        }
        return (float)((int)$row['failures']) / $total;
    }

    /**
     * Delete check records older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare('DELETE FROM health_checks WHERE checked_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
