<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * HealthCheck — persistent service health status with history.
 *
 * Services report their health (ok/degraded/down) via heartbeat. The current
 * status and recent history are queryable. Useful for dashboards and alerting.
 *
 * ## Usage
 *
 * ```php
 * $hc = new HealthCheck($pdo);
 *
 * // Report health
 * $hc->report('database', 'ok');
 * $hc->report('email-service', 'degraded', 'Slow response times');
 * $hc->report('payments', 'down', 'Connection refused');
 *
 * // Get current status
 * $hc->status('database'); // 'ok'|'degraded'|'down'|null
 *
 * // Get all current statuses
 * $hc->all();
 *
 * // Get recent history
 * $hc->history('email-service', 20);
 *
 * // Check if all services are healthy
 * $hc->isHealthy(); // true if all current statuses are 'ok'
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE health_checks (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     service     VARCHAR(255) NOT NULL,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'ok',
 *     message     TEXT         NOT NULL DEFAULT '',
 *     checked_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE health_check_current (
 *     service     VARCHAR(255) NOT NULL PRIMARY KEY,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'ok',
 *     message     TEXT         NOT NULL DEFAULT '',
 *     checked_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class HealthCheck
{
    private const VALID_STATUSES = ['ok', 'degraded', 'down'];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Report the health of a service.
     *
     * Appends to history and updates the current status.
     *
     * @param  string $status  One of: 'ok', 'degraded', 'down'.
     * @param  string $message Optional detail message.
     * @throws \InvalidArgumentException if service name is empty or status is invalid.
     */
    public function report(string $service, string $status, string $message = ''): void
    {
        $service = trim($service);
        if ($service === '') {
            throw new \InvalidArgumentException('service must not be empty.');
        }
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                'status must be one of: ' . implode(', ', self::VALID_STATUSES) . '.'
            );
        }

        $db = $this->db();

        // Append to history
        $db->prepare(
            'INSERT INTO health_checks (service, status, message) VALUES (:svc, :status, :msg)'
        )->execute([':svc' => $service, ':status' => $status, ':msg' => $message]);

        // Update current status (upsert)
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO health_check_current (service, status, message)
                 VALUES (:svc, :status, :msg)
                 ON CONFLICT (service)
                 DO UPDATE SET status = excluded.status,
                               message = excluded.message,
                               checked_at = CURRENT_TIMESTAMP'
            )->execute([':svc' => $service, ':status' => $status, ':msg' => $message]);
        } else {
            $db->prepare(
                'INSERT INTO health_check_current (service, status, message)
                 VALUES (:svc, :status, :msg)
                 ON DUPLICATE KEY UPDATE status = VALUES(status),
                                         message = VALUES(message),
                                         checked_at = CURRENT_TIMESTAMP'
            )->execute([':svc' => $service, ':status' => $status, ':msg' => $message]);
        }
    }

    /**
     * Get the current status of a service.
     *
     * @return 'ok'|'degraded'|'down'|null  null if the service has never reported.
     */
    public function status(string $service): ?string
    {
        $service = trim($service);
        $stmt    = $this->db()->prepare(
            'SELECT status FROM health_check_current WHERE service = :svc LIMIT 1'
        );
        $stmt->execute([':svc' => $service]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Get full current status record for a service.
     *
     * @return array<string,mixed>|null
     */
    public function current(string $service): ?array
    {
        $service = trim($service);
        $stmt    = $this->db()->prepare(
            'SELECT service, status, message, checked_at
             FROM health_check_current WHERE service = :svc LIMIT 1'
        );
        $stmt->execute([':svc' => $service]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get all services' current statuses.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT service, status, message, checked_at
             FROM health_check_current ORDER BY service ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Check if all known services are currently 'ok'.
     *
     * Returns true if there are no services or all are 'ok'.
     */
    public function isHealthy(): bool
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM health_check_current WHERE status != 'ok'"
        );
        $stmt->execute();
        return (int)$stmt->fetchColumn() === 0;
    }

    /**
     * Get the recent health history for a service.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $service, int $limit = 20): array
    {
        $service = trim($service);
        $limit   = max(1, $limit);
        $stmt    = $this->db()->prepare(
            "SELECT id, service, status, message, checked_at
             FROM health_checks WHERE service = :svc
             ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([':svc' => $service]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Purge history entries older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare('DELETE FROM health_checks WHERE checked_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
