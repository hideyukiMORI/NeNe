<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ApiUsageLog — per-API-key request tracking with endpoint and status logging.
 *
 * Records every API call with key, endpoint, method, status code, and latency.
 * Useful for usage analytics, quota enforcement, and debugging.
 *
 * ## Usage
 *
 * ```php
 * $log = new ApiUsageLog($pdo);
 *
 * // Record a call
 * $log->record('key-abc', '/api/posts', 'GET', 200, 45);
 *
 * // Query usage
 * $log->countByKey('key-abc');                  // total calls
 * $log->countByKey('key-abc', since: '-1 hour'); // calls in last hour
 * $log->recent('key-abc', 20);                  // 20 most recent
 * $log->topEndpoints('key-abc', 5);             // most-called endpoints
 * $log->errorRate('key-abc');                   // fraction of 4xx/5xx
 * $log->purgeOlderThan(30);                     // maintenance
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE api_usage_log (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     api_key      VARCHAR(255) NOT NULL,
 *     endpoint     VARCHAR(500) NOT NULL DEFAULT '',
 *     http_method  VARCHAR(10)  NOT NULL DEFAULT '',
 *     status_code  SMALLINT     NOT NULL DEFAULT 0,
 *     latency_ms   INT          NOT NULL DEFAULT 0,
 *     logged_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ApiUsageLog
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record an API call.
     *
     * @param  string $apiKey      The API key used.
     * @param  string $endpoint    Request path/endpoint.
     * @param  string $httpMethod  HTTP verb (GET, POST, …).
     * @param  int    $statusCode  HTTP response status code.
     * @param  int    $latencyMs   Response latency in milliseconds.
     * @return int    The new record ID.
     * @throws \InvalidArgumentException if api_key is empty.
     */
    public function record(
        string $apiKey,
        string $endpoint = '',
        string $httpMethod = '',
        int $statusCode = 0,
        int $latencyMs = 0
    ): int {
        $apiKey = $this->validateKey($apiKey);
        $stmt   = $this->db()->prepare(
            'INSERT INTO api_usage_log (api_key, endpoint, http_method, status_code, latency_ms)
             VALUES (:key, :ep, :method, :status, :latency)'
        );
        $stmt->execute([
            ':key'     => $apiKey,
            ':ep'      => $endpoint,
            ':method'  => strtoupper($httpMethod),
            ':status'  => $statusCode,
            ':latency' => max(0, $latencyMs),
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Count API calls for a key, optionally since a datetime string.
     *
     * @param  string|null $since  Datetime string accepted by strtotime() or DateTimeImmutable,
     *                             e.g. '-1 hour', '2026-05-01 00:00:00'. Null = no time filter.
     */
    public function countByKey(string $apiKey, ?string $since = null): int
    {
        $apiKey = $this->validateKey($apiKey);
        if ($since !== null) {
            $cutoff = (new \DateTimeImmutable($since))->format('Y-m-d H:i:s');
            $stmt   = $this->db()->prepare(
                'SELECT COUNT(*) FROM api_usage_log
                 WHERE api_key = :key AND logged_at >= :since'
            );
            $stmt->execute([':key' => $apiKey, ':since' => $cutoff]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM api_usage_log WHERE api_key = :key'
            );
            $stmt->execute([':key' => $apiKey]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get the N most recent log entries for a key.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(string $apiKey, int $limit = 20): array
    {
        $apiKey = $this->validateKey($apiKey);
        $limit  = max(1, $limit);
        $stmt   = $this->db()->prepare(
            "SELECT id, api_key, endpoint, http_method, status_code, latency_ms, logged_at
             FROM api_usage_log
             WHERE api_key = :key
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':key' => $apiKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return the top N endpoints by call count for a key.
     *
     * @return list<array{endpoint: string, calls: int}>
     */
    public function topEndpoints(string $apiKey, int $limit = 10): array
    {
        $apiKey = $this->validateKey($apiKey);
        $limit  = max(1, $limit);
        $stmt   = $this->db()->prepare(
            "SELECT endpoint, COUNT(*) AS calls
             FROM api_usage_log
             WHERE api_key = :key
             GROUP BY endpoint
             ORDER BY calls DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':key' => $apiKey]);
        return array_map(
            static fn (array $r) => ['endpoint' => (string)$r['endpoint'], 'calls' => (int)$r['calls']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Return the fraction of calls with 4xx or 5xx status codes (0.0–1.0).
     *
     * Returns 0.0 if no calls have been recorded.
     */
    public function errorRate(string $apiKey): float
    {
        $apiKey = $this->validateKey($apiKey);
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS errors
             FROM api_usage_log WHERE api_key = :key'
        );
        $stmt->execute([':key' => $apiKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['total'] === 0) {
            return 0.0;
        }
        return round((int)$row['errors'] / (int)$row['total'], 4);
    }

    /**
     * Return the average latency in milliseconds for a key.
     *
     * Returns 0.0 if no calls have been recorded.
     */
    public function avgLatency(string $apiKey): float
    {
        $apiKey = $this->validateKey($apiKey);
        $stmt   = $this->db()->prepare(
            'SELECT AVG(latency_ms) FROM api_usage_log WHERE api_key = :key'
        );
        $stmt->execute([':key' => $apiKey]);
        $val = $stmt->fetchColumn();
        return $val !== false ? round((float)$val, 2) : 0.0;
    }

    /**
     * Purge log entries older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM api_usage_log WHERE logged_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateKey(string $apiKey): string
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('api_key must not be empty.');
        }
        return $apiKey;
    }
}
