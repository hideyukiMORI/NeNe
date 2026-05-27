<?php

declare(strict_types=1);

namespace Nene\Func;

use Nene\Xion\HttpTermination;
use Nene\Xion\JsonResponder;

/**
 * Fixed-window rate limiter.
 *
 * Uses a storage backend (typically Redis) to count requests per key
 * within a sliding window. Designed to be called from `preAction()`
 * or at the start of a REST method.
 *
 * ## Typical usage
 *
 * ```php
 * protected function preAction(): void
 * {
 *     $redis   = \Nene\Xion\RedisConnection::getInstance();
 *     $storage = new RedisRateLimiterStorage($redis);
 *     $limiter = new RateLimiter($storage);
 *     $limiter->check(
 *         key: 'login:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
 *         limit: 10,
 *         windowSeconds: 60
 *     );
 * }
 * ```
 *
 * `check()` throws `HttpTermination(429)` with `Retry-After` header if exceeded.
 * On success it sets the `X-RateLimit-*` response headers.
 */
final class RateLimiter
{
    public function __construct(private readonly RateLimiterStorageInterface $storage)
    {
    }

    /**
     * Check the rate limit for a key.
     *
     * Sets `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
     * headers on every request.
     *
     * Throws `HttpTermination(429)` with `Retry-After: N` when exceeded.
     *
     * @param string $key           Unique bucket key.
     * @param int    $limit         Maximum requests allowed in the window.
     * @param int    $windowSeconds Window duration in seconds.
     *
     * @return void
     */
    public function check(string $key, int $limit, int $windowSeconds): void
    {
        $count     = $this->storage->increment($key, $windowSeconds);
        $remaining = max(0, $limit - $count);
        $ttl       = $this->storage->ttl($key);

        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $ttl);

        if ($count > $limit) {
            header('Retry-After: ' . $ttl);
            throw new HttpTermination(
                JsonResponder::response(
                    ['Result' => false, 'Data' => [
                        'status'       => 'failure',
                        'errorCode'    => 'RATE-LIMIT-EXCEEDED',
                        'errorMessage' => 'Too many requests. Please retry after ' . $ttl . ' seconds.',
                    ]],
                    429
                )
            );
        }
    }

    /**
     * Peek at the current count for a key without incrementing.
     *
     * @param string $key   Bucket key.
     * @param int    $limit Maximum allowed.
     *
     * @return int Remaining requests (0 if already exceeded).
     */
    public function remaining(string $key, int $limit): int
    {
        return max(0, $limit - $this->storage->count($key));
    }
}
