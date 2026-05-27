<?php

declare(strict_types=1);

namespace Nene\Func;

use Predis\Client;

/**
 * Redis-backed rate limiter storage using INCR + EXPIRE.
 *
 * IMPORTANT: PHP-FPM workers share this state via Redis. Do NOT use
 * ArrayRateLimiterStorage in production.
 */
final class RedisRateLimiterStorage implements RateLimiterStorageInterface
{
    public function __construct(private readonly Client $redis) {}

    public function increment(string $key, int $windowSeconds): int
    {
        $count = (int)$this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $windowSeconds);
        }
        return $count;
    }

    public function ttl(string $key): int
    {
        return max(0, (int)$this->redis->ttl($key));
    }

    public function count(string $key): int
    {
        return (int)($this->redis->get($key) ?? 0);
    }
}
