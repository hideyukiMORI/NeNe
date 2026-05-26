# Rate Limiting

How to add request rate limiting to NeNe endpoints using Redis (already available via `predis/predis`). NeNe does not ship a built-in rate-limiting middleware — this guide shows how to add it.

## When to add rate limiting

| Endpoint type | Risk without limiting | Recommendation |
|---|---|---|
| Login / `POST /session/login` | Brute-force password attacks | Yes — always |
| Invitation claim / registration | Enumeration of invite codes | Yes |
| Password reset request | Email flooding / enumeration | Yes |
| API endpoints (general) | Abuse / scraping | Optional — depends on traffic |
| Internal admin endpoints | Lower risk | Low priority |

## The sliding window counter pattern

The simplest Redis-based approach uses a per-key counter with a TTL:

1. `INCR key` — atomically increment the counter and get the new value.
2. If the returned value is 1 (first request), set the key TTL with `EXPIRE key <window_seconds>`.
3. If the counter exceeds the limit, return 429.

```php
// class/func/RateLimiter.php

namespace Nene\Func;

use Predis\Client;

final class RateLimiter
{
    private Client $redis;

    public function __construct(Client $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Check if the given key is within the allowed rate.
     *
     * @param string $key      Unique identifier (e.g. "login:ip:1.2.3.4")
     * @param int    $limit    Max requests allowed within the window
     * @param int    $window   Window duration in seconds
     * @return bool  true = allowed, false = rate limit exceeded
     */
    public function allow(string $key, int $limit, int $window): bool
    {
        $count = (int)$this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $window);
        }
        return $count <= $limit;
    }

    /** Remaining requests in the current window */
    public function remaining(string $key, int $limit): int
    {
        $count = (int)($this->redis->get($key) ?? 0);
        return max(0, $limit - $count);
    }

    /** Seconds until the current window resets */
    public function ttl(string $key): int
    {
        return max(0, (int)$this->redis->ttl($key));
    }
}
```

## Usage in a controller

```php
use Nene\Func\RateLimiter;
use Nene\Xion\RedisConnection;

// POST /session/login
public function indexPostRest(): array
{
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $limiter = new RateLimiter(RedisConnection::getInstance());
    $key     = 'rate:login:ip:' . $ip;

    // 10 attempts per 15 minutes per IP
    if (!$limiter->allow($key, limit: 10, window: 900)) {
        return $this->API_RESPONSE->failure('RATE-LIMIT-EXCEEDED');
    }

    // ... verify credentials ...
}
```

## Rate limit key strategies

Choose the key based on what you want to throttle:

```php
// Per IP address (unauthenticated endpoints — login, registration)
$key = 'rate:login:ip:' . ($ip);

// Per user ID (authenticated endpoints — password reset, email send)
$key = 'rate:password-reset:user:' . $userId;

// Per API key (third-party integrations)
$key = 'rate:api:key:' . hash('sha256', $apiKey);

// Global endpoint cap (emergency brake)
$key = 'rate:global:endpoint:' . $endpointName;
```

Namespace keys with `rate:` to distinguish from session and feature-flag keys in the same Redis instance.

## Response headers

Include `Retry-After` and rate limit headers in 429 responses to help clients back off correctly:

```php
if (!$limiter->allow($key, 10, 900)) {
    $retryAfter = $limiter->ttl($key);
    header('Retry-After: ' . $retryAfter);
    header('X-RateLimit-Limit: 10');
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Reset: ' . (time() + $retryAfter));
    return $this->API_RESPONSE->failure('RATE-LIMIT-EXCEEDED');
}
```

## Error code

```php
// config/error_codes.php
'RATE-LIMIT-EXCEEDED' => ['message' => 'Too many requests. Please try again later.', 'httpStatus' => 429],
```

## RedisConnection

NeNe already has `class/xion/RedisConnection.php` (singleton Predis client). Check the connection uses the same Redis instance as session storage. The rate limiter keys live alongside session keys — use distinct prefixes to avoid collisions.

```php
$redis = \Nene\Xion\RedisConnection::getInstance();
$limiter = new RateLimiter($redis);
```

## Limitations of the simple INCR pattern

| Limitation | Mitigation |
|---|---|
| Race on INCR + EXPIRE (two calls, not atomic) | Use a Lua script or Redis `SET key 0 EX <window> NX` for atomic initialization |
| Fixed-window resets allow bursts at boundary | Use a sliding-window log (ZADD / ZREMRANGEBYSCORE) for strict rate control |
| No distributed coordination across multiple PHP workers | Redis handles this natively — all workers share the same counter |

For most NeNe use cases (protecting login and registration endpoints on a single server), the simple INCR pattern is sufficient.

## Lua script for atomic initialization

If the race between `INCR` and `EXPIRE` is a concern, use a Lua script that sets both atomically:

```lua
-- rate_limit.lua
local key   = KEYS[1]
local limit = tonumber(ARGV[1])
local ttl   = tonumber(ARGV[2])

local count = redis.call('INCR', key)
if count == 1 then
    redis.call('EXPIRE', key, ttl)
end
return count
```

```php
$count = $this->redis->eval($luaScript, 1, $key, $limit, $window);
return (int)$count <= $limit;
```

## Related

- `docs/development/feature-flags.md` — Redis caching pattern
- `docs/development/session-storage.md` — Redis session storage
- `docs/development/agent-bearer-auth.md` — Bearer token authentication (stateless; rate-limit by bearer key, not session)
