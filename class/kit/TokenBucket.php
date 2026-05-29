<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * TokenBucket — DB-backed token bucket algorithm for flexible rate limiting.
 *
 * Each bucket refills at a configurable rate (tokens per second). A request
 * consumes one or more tokens. If the bucket is empty, the request is denied.
 *
 * Complements FT28's fixed-window rate limiter with burst tolerance.
 *
 * ## Usage
 *
 * ```php
 * $tb = new TokenBucket($pdo, capacity: 10, refillRate: 1.0);
 *
 * // Try to consume 1 token (returns false if bucket is empty)
 * $tb->consume('user-1');
 *
 * // Consume multiple tokens
 * $tb->consume('user-1', 3);
 *
 * // Peek at remaining tokens without consuming
 * $tb->tokens('user-1');
 *
 * // Reset a bucket
 * $tb->reset('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE token_buckets (
 *     bucket_key  VARCHAR(255) NOT NULL PRIMARY KEY,
 *     tokens      DOUBLE       NOT NULL,
 *     last_refill DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class TokenBucket
{
    /**
     * @param float $capacity   Maximum tokens the bucket can hold.
     * @param float $refillRate Tokens added per second.
     */
    public function __construct(
        private readonly ?PDO $db = null,
        private readonly float $capacity = 10.0,
        private readonly float $refillRate = 1.0,
    ) {
        if ($this->capacity <= 0) {
            throw new \InvalidArgumentException('capacity must be positive.');
        }
        if ($this->refillRate <= 0) {
            throw new \InvalidArgumentException('refillRate must be positive.');
        }
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Attempt to consume tokens from a bucket.
     *
     * The bucket is refilled based on elapsed time since last refill before consumption.
     *
     * @param  string $bucketKey Unique identifier for this rate-limit subject (e.g. user ID, IP).
     * @param  int    $cost      Number of tokens to consume (default 1).
     * @return bool   True if tokens were available and consumed; false if rate-limited.
     * @throws \InvalidArgumentException if bucket_key is empty or cost is not positive.
     */
    public function consume(string $bucketKey, int $cost = 1): bool
    {
        $bucketKey = trim($bucketKey);
        if ($bucketKey === '') {
            throw new \InvalidArgumentException('bucket_key must not be empty.');
        }
        if ($cost <= 0) {
            throw new \InvalidArgumentException('cost must be positive.');
        }

        $db  = $this->db();
        $now = microtime(true);

        // Load existing bucket or initialise
        $stmt = $db->prepare(
            'SELECT tokens, last_refill FROM token_buckets WHERE bucket_key = :key LIMIT 1'
        );
        $stmt->execute([':key' => $bucketKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            // First request: full bucket
            $tokens     = $this->capacity;
            $lastRefill = $now;
        } else {
            $lastRefill  = (float)strtotime((string)$row['last_refill']);
            $elapsed     = max(0.0, $now - $lastRefill);
            $tokens      = min($this->capacity, (float)$row['tokens'] + $elapsed * $this->refillRate);
            $lastRefill  = $now;
        }

        if ($tokens < $cost) {
            // Not enough tokens — persist the refilled (but unconsumed) state
            $this->upsert($db, $bucketKey, $tokens, $lastRefill);
            return false;
        }

        $this->upsert($db, $bucketKey, $tokens - $cost, $lastRefill);
        return true;
    }

    /**
     * Get the current token count for a bucket (after applying refill).
     *
     * Does not modify the bucket state.
     *
     * @throws \InvalidArgumentException if bucket_key is empty.
     */
    public function tokens(string $bucketKey): float
    {
        $bucketKey = trim($bucketKey);
        if ($bucketKey === '') {
            throw new \InvalidArgumentException('bucket_key must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'SELECT tokens, last_refill FROM token_buckets WHERE bucket_key = :key LIMIT 1'
        );
        $stmt->execute([':key' => $bucketKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return $this->capacity; // never used → full
        }

        $elapsed = max(0.0, microtime(true) - (float)strtotime((string)$row['last_refill']));
        return min($this->capacity, (float)$row['tokens'] + $elapsed * $this->refillRate);
    }

    /**
     * Reset a bucket to full capacity.
     *
     * @return bool True if a record was reset or created.
     */
    public function reset(string $bucketKey): bool
    {
        $bucketKey = trim($bucketKey);
        if ($bucketKey === '') {
            throw new \InvalidArgumentException('bucket_key must not be empty.');
        }
        $now = microtime(true);
        $this->upsert($this->db(), $bucketKey, $this->capacity, $now);
        return true;
    }

    /**
     * Delete a bucket record entirely.
     *
     * @return bool True if a row was deleted.
     */
    public function remove(string $bucketKey): bool
    {
        $bucketKey = trim($bucketKey);
        $stmt      = $this->db()->prepare('DELETE FROM token_buckets WHERE bucket_key = :key');
        $stmt->execute([':key' => $bucketKey]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove buckets that haven't been used in the given number of seconds.
     *
     * @return int Number of rows deleted.
     */
    public function purgeStale(int $seconds = 86400): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$seconds} seconds")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM token_buckets WHERE last_refill < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function upsert(PDO $db, string $key, float $tokens, float $nowTs): void
    {
        $nowStr = date('Y-m-d H:i:s', (int)$nowTs);
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO token_buckets (bucket_key, tokens, last_refill)
                 VALUES (:key, :tokens, :now)
                 ON CONFLICT (bucket_key)
                 DO UPDATE SET tokens = excluded.tokens, last_refill = excluded.last_refill'
            )->execute([':key' => $key, ':tokens' => $tokens, ':now' => $nowStr]);
        } else {
            $db->prepare(
                'INSERT INTO token_buckets (bucket_key, tokens, last_refill)
                 VALUES (:key, :tokens, :now)
                 ON DUPLICATE KEY UPDATE tokens = VALUES(tokens), last_refill = VALUES(last_refill)'
            )->execute([':key' => $key, ':tokens' => $tokens, ':now' => $nowStr]);
        }
    }
}
