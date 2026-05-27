<?php

declare(strict_types=1);

namespace Nene\Func;

/**
 * Storage backend for RateLimiter.
 */
interface RateLimiterStorageInterface
{
    /**
     * Atomically increment the counter for the given key within a time window.
     *
     * If the key does not exist, create it with value 1 and set its TTL.
     * If it already exists, increment and return the new value.
     *
     * @param string $key           Bucket key (e.g. "login:ip:1.2.3.4").
     * @param int    $windowSeconds Window duration in seconds.
     *
     * @return int New counter value after increment.
     */
    public function increment(string $key, int $windowSeconds): int;

    /**
     * Return remaining TTL of the key in seconds, or 0 if key does not exist.
     */
    public function ttl(string $key): int;

    /**
     * Get the current counter value without incrementing, or 0 if absent.
     */
    public function count(string $key): int;
}
