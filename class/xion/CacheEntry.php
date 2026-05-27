<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * CacheEntry — DB-backed key-value cache with optional TTL.
 *
 * Lightweight caching layer backed by the application database. Suitable
 * for caching small, infrequently-changing values (config, computed results,
 * external API responses). For high-throughput scenarios prefer Redis or
 * Memcached; this is the "zero extra infrastructure" fallback.
 *
 * ## Usage
 *
 * ```php
 * $cache = new CacheEntry($pdo);
 *
 * // Store with 60-second TTL
 * $cache->set('user:42:profile', json_encode($profile), 60);
 *
 * // Store forever
 * $cache->set('static:countries', json_encode($countries));
 *
 * // Retrieve
 * $raw = $cache->get('user:42:profile'); // null if missing or expired
 *
 * // Clean up
 * $cache->flushExpired();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE cache_entries (
 *     cache_key  VARCHAR(255) NOT NULL PRIMARY KEY,
 *     cache_value TEXT        NOT NULL DEFAULT '',
 *     expires_at DATETIME     DEFAULT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class CacheEntry
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Store a value in the cache.
     *
     * @param int|null $ttlSeconds Time-to-live in seconds; null = never expires.
     * @throws \InvalidArgumentException if the key is empty.
     */
    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
        $key       = $this->validateKey($key);
        $expiresAt = $ttlSeconds !== null
            ? (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s')
            : null;

        $db = $this->db();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO cache_entries (cache_key, cache_value, expires_at)
                 VALUES (:k, :v, :exp)
                 ON CONFLICT (cache_key)
                 DO UPDATE SET cache_value = excluded.cache_value,
                               expires_at  = excluded.expires_at,
                               created_at  = CURRENT_TIMESTAMP'
            )->execute([':k' => $key, ':v' => $value, ':exp' => $expiresAt]);
        } else {
            $db->prepare(
                'INSERT INTO cache_entries (cache_key, cache_value, expires_at)
                 VALUES (:k, :v, :exp)
                 ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value),
                                         expires_at  = VALUES(expires_at),
                                         created_at  = CURRENT_TIMESTAMP'
            )->execute([':k' => $key, ':v' => $value, ':exp' => $expiresAt]);
        }
    }

    /**
     * Retrieve a cached value.
     *
     * Returns null if the key does not exist or has expired.
     */
    public function get(string $key): ?string
    {
        $key  = $this->validateKey($key);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT cache_value FROM cache_entries
             WHERE cache_key = :k
               AND (expires_at IS NULL OR expires_at > :now)
             LIMIT 1'
        );
        $stmt->execute([':k' => $key, ':now' => $now]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Check whether a non-expired entry exists for the key.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Delete a cache entry.
     *
     * @return bool True if an entry was deleted.
     */
    public function delete(string $key): bool
    {
        $key  = $this->validateKey($key);
        $stmt = $this->db()->prepare('DELETE FROM cache_entries WHERE cache_key = :k');
        $stmt->execute([':k' => $key]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all cache entries.
     *
     * @return int Number of rows deleted.
     */
    public function flush(): int
    {
        $stmt = $this->db()->prepare('DELETE FROM cache_entries');
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Delete all expired cache entries.
     *
     * @return int Number of rows deleted.
     */
    public function flushExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'DELETE FROM cache_entries WHERE expires_at IS NOT NULL AND expires_at <= :now'
        );
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }

    /**
     * Count all stored entries (including expired).
     */
    public function count(): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM cache_entries');
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('cache_key must not be empty.');
        }
        return $key;
    }
}
