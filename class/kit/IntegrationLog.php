<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * IntegrationLog — outbound/inbound API call log with request and response data.
 *
 * Records every external HTTP/API call your application makes (or receives from
 * partners) for debugging, auditing, and replay purposes. Each log entry stores
 * the service name, endpoint, method, request/response bodies, HTTP status, and
 * duration.
 *
 * ## Usage
 *
 * ```php
 * $log = new IntegrationLog($pdo);
 *
 * // Record a call
 * $id = $log->record('stripe', '/v1/charges', 'POST', ['amount' => 500], 200, '{"id":"ch_1"}', 230);
 *
 * // Query
 * $entry   = $log->find($id);
 * $entries = $log->forService('stripe', 50, 0);
 * $errors  = $log->errors('stripe', 50, 0);
 * $count   = $log->countErrors('stripe');
 * $log->deleteOlderThan(new \DateTimeImmutable('-30 days'));
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE integration_logs (
 *     id             INTEGER PRIMARY KEY AUTOINCREMENT,
 *     service        VARCHAR(100) NOT NULL,
 *     endpoint       TEXT         NOT NULL,
 *     method         VARCHAR(10)  NOT NULL DEFAULT 'GET',
 *     request_body   TEXT         NULL,
 *     http_status    INTEGER      NULL,
 *     response_body  TEXT         NULL,
 *     duration_ms    INTEGER      NULL,
 *     error_message  TEXT         NULL,
 *     created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class IntegrationLog
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record an API call.
     *
     * @param string               $service      Logical service name (e.g. "stripe").
     * @param string               $endpoint     URL path or identifier.
     * @param string               $method       HTTP method (GET, POST, …).
     * @param array<mixed>|string|null $requestBody  Request payload — array is JSON-encoded.
     * @param int|null             $httpStatus   HTTP response status code.
     * @param string|null          $responseBody Response body string.
     * @param int|null             $durationMs   Round-trip duration in milliseconds.
     * @param string|null          $errorMessage Error description if the call failed.
     * @return int Row ID of the new log entry.
     * @throws \InvalidArgumentException on empty service or endpoint.
     */
    public function record(
        string $service,
        string $endpoint,
        string $method = 'GET',
        array|string|null $requestBody = null,
        ?int $httpStatus = null,
        ?string $responseBody = null,
        ?int $durationMs = null,
        ?string $errorMessage = null,
    ): int {
        $service  = trim($service);
        $endpoint = trim($endpoint);
        if ($service === '') {
            throw new \InvalidArgumentException('service must not be empty.');
        }
        if ($endpoint === '') {
            throw new \InvalidArgumentException('endpoint must not be empty.');
        }

        $reqBody = is_array($requestBody) ? json_encode($requestBody) : $requestBody;

        $stmt = $this->db()->prepare(
            'INSERT INTO integration_logs
                (service, endpoint, method, request_body, http_status, response_body, duration_ms, error_message)
             VALUES
                (:service, :endpoint, :method, :req, :status, :resp, :dur, :err)'
        );
        $stmt->execute([
            ':service'  => $service,
            ':endpoint' => $endpoint,
            ':method'   => strtoupper(trim($method)) ?: 'GET',
            ':req'      => $reqBody,
            ':status'   => $httpStatus,
            ':resp'     => $responseBody,
            ':dur'      => $durationMs,
            ':err'      => $errorMessage,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a single log entry by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM integration_logs WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Delete a single log entry.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM integration_logs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List log entries for a specific service (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function forService(string $service, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM integration_logs
             WHERE service = :service
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':service', trim($service));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List entries that have an error_message or non-2xx HTTP status (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function errors(string $service, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM integration_logs
             WHERE service = :service
               AND (error_message IS NOT NULL OR (http_status IS NOT NULL AND (http_status < 200 OR http_status >= 300)))
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':service', trim($service));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count error entries for a service.
     */
    public function countErrors(string $service): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM integration_logs
             WHERE service = :service
               AND (error_message IS NOT NULL OR (http_status IS NOT NULL AND (http_status < 200 OR http_status >= 300)))'
        );
        $stmt->execute([':service' => trim($service)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * List all distinct service names recorded.
     *
     * @return list<string>
     */
    public function services(): array
    {
        $stmt = $this->db()->query(
            'SELECT DISTINCT service FROM integration_logs ORDER BY service ASC'
        );
        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    /**
     * Delete all log entries older than the given cutoff datetime.
     *
     * @return int Number of rows deleted.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM integration_logs WHERE created_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff->format('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
