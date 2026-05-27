<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * DB-backed idempotency key store for POST endpoints.
 *
 * Prevents duplicate operations on retry: first call stores the response;
 * subsequent calls with the same key replay the stored response.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE idempotency_keys (
 *     key_hash    VARCHAR(64) PRIMARY KEY,   -- SHA-256 of the raw key
 *     status_code SMALLINT    NOT NULL,
 *     body        TEXT        NOT NULL,      -- JSON-encoded response body
 *     created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 *
 * ## Usage
 *
 * ```php
 * $store = new IdempotencyStore();
 * $rawKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
 *
 * if ($rawKey !== '') {
 *     $cached = $store->get($rawKey);
 *     if ($cached !== null) {
 *         header('X-Idempotent-Replayed: true');
 *         http_response_code($cached['status_code']);
 *         echo $cached['body'];
 *         exit;
 *     }
 * }
 *
 * // ... perform operation ...
 * $responseBody = json_encode($result, JSON_THROW_ON_ERROR);
 *
 * if ($rawKey !== '') {
 *     $store->put($rawKey, 201, $responseBody);
 * }
 * ```
 */
final class IdempotencyStore
{
    private const TABLE = 'idempotency_keys';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {}

    /**
     * Look up a cached response by raw idempotency key.
     *
     * @return array{status_code: int, body: string}|null Cached response or null.
     */
    public function get(string $rawKey): ?array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT status_code, body FROM ' . $this->table .
            ' WHERE key_hash = :h LIMIT 1'
        );
        $stmt->bindValue(':h', $this->hash($rawKey), PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'status_code' => (int)$row['status_code'],
            'body'        => (string)$row['body'],
        ];
    }

    /**
     * Store a response body under a raw idempotency key.
     * Silently ignores duplicate-key violations (concurrent retries).
     */
    public function put(string $rawKey, int $statusCode, string $body): void
    {
        $db     = $this->db ?? PdoConnection::getInstance();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO ' . $this->table . ' (key_hash, status_code, body) VALUES (:h, :s, :b)'
            : 'INSERT IGNORE INTO '    . $this->table . ' (key_hash, status_code, body) VALUES (:h, :s, :b)';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':h', $this->hash($rawKey), PDO::PARAM_STR);
        $stmt->bindValue(':s', $statusCode,           PDO::PARAM_INT);
        $stmt->bindValue(':b', $body,                 PDO::PARAM_STR);
        try {
            $stmt->execute();
        } catch (\PDOException) {
            // Ignore duplicate key — concurrent retry stored it first
        }
    }

    /**
     * Hash a raw idempotency key for safe DB storage.
     */
    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }
}
