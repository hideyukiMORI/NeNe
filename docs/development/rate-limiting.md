# Rate Limiting

How to add request rate limiting to NeNe endpoints using the built-in `RateLimiter` class (backed by Redis via `predis/predis`).

## Framework class: `RateLimiter`

`Nene\Func\RateLimiter` and `Nene\Func\RedisRateLimiterStorage` are built-in.
Add `predis/predis` to your project if not already present (it ships with NeNe).

### Typical usage

```php
use Nene\Func\RateLimiter;
use Nene\Func\RedisRateLimiterStorage;
use Nene\Xion\RedisConnection;

protected function preAction(): void
{
    $storage = new RedisRateLimiterStorage(RedisConnection::getInstance());
    $limiter = new RateLimiter($storage);
    $limiter->check(
        key: 'login:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        limit: 10,
        windowSeconds: 60
    );
}
```

`check()` throws `HttpTermination(429)` with a `Retry-After` header when the limit is exceeded. On every request it also sets `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` headers. No further action is needed in the controller — the framework catches `HttpTermination` and emits the response automatically.

To inspect the remaining quota without consuming a request, use `remaining()`:

```php
$left = $limiter->remaining('login:ip:' . $ip, 10);
```

## When to add rate limiting

| Endpoint type | Risk without limiting | Recommendation |
|---|---|---|
| Login / `POST /session/login` | Brute-force password attacks | Yes — always |
| Invitation claim / registration | Enumeration of invite codes | Yes |
| Password reset request | Email flooding / enumeration | Yes |
| API endpoints (general) | Abuse / scraping | Optional — depends on traffic |
| Internal admin endpoints | Lower risk | Low priority |

## The fixed-window counter pattern

`RateLimiter` uses a fixed-window counter backed by Redis:

1. `INCR key` — atomically increment the counter and get the new value.
2. If the returned value is 1 (first request in the window), set the key TTL with `EXPIRE key <window_seconds>`.
3. If the counter exceeds the limit, throw `HttpTermination(429)` with `Retry-After`.

`RedisRateLimiterStorage` handles steps 1–2. `RateLimiter::check()` handles step 3.

## Usage in a controller

Call `check()` from `preAction()` (applies to all methods in the controller) or from a specific REST method:

```php
use Nene\Func\RateLimiter;
use Nene\Func\RedisRateLimiterStorage;
use Nene\Xion\RedisConnection;

// POST /session/login — 10 attempts per 15 minutes per IP
public function indexPostRest(): array
{
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $storage = new RedisRateLimiterStorage(RedisConnection::getInstance());
    $limiter = new RateLimiter($storage);
    $limiter->check('rate:login:ip:' . $ip, limit: 10, windowSeconds: 900);

    // ... verify credentials ...
}
```

If the limit is exceeded, `check()` terminates the request with a 429 JSON response before reaching your credential logic.

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

`RateLimiter::check()` sets these headers on every request (not just 429):

| Header | Value |
|---|---|
| `X-RateLimit-Limit` | The configured `$limit` |
| `X-RateLimit-Remaining` | Remaining requests in the current window (0 if exceeded) |
| `X-RateLimit-Reset` | Seconds until the window resets |

On a 429 response, `Retry-After` is also set to the same TTL value so clients can back off correctly. No manual header management is required — `check()` handles all of this before throwing.

## Error code

`RATE-LIMIT-EXCEEDED` (HTTP 429) is already registered in `config/error_codes.php`. No action needed — `RateLimiter::check()` uses it automatically.

## RedisConnection

NeNe already has `class/xion/RedisConnection.php` (singleton Predis client). Check the connection uses the same Redis instance as session storage. The rate limiter keys live alongside session keys — use distinct prefixes to avoid collisions.

```php
$storage = new \Nene\Func\RedisRateLimiterStorage(\Nene\Xion\RedisConnection::getInstance());
$limiter = new \Nene\Func\RateLimiter($storage);
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
