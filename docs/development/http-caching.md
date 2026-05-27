# HTTP Caching

How to add HTTP cache headers to NeNe REST endpoints using `Nene\Xion\HttpCache`. Enables bandwidth savings and CDN compatibility for read-heavy API routes.

## Why HTTP caching matters for REST APIs

HTTP caching avoids regenerating or retransmitting responses that have not changed. For a list endpoint that queries a database and serialises JSON, a cache hit means:

- The client skips the request body entirely (304 Not Modified, zero-byte body).
- A CDN or reverse proxy can serve cached responses without reaching PHP at all.
- Database load drops for endpoints polled frequently.

NeNe's `HttpCache` is a thin static utility class. It does not introduce middleware, does not store responses internally, and has no dependencies beyond PHP built-ins. The controller owns all decisions about what is cacheable and for how long.

## HttpCache API reference

| Method | Description |
|---|---|
| `HttpCache::sendCacheControl(maxAge, private, immutable, noStore)` | Emit a `Cache-Control` header. |
| `HttpCache::sendLastModified(lastModified)` | Emit a `Last-Modified` header. Accepts MySQL DATETIME string or Unix timestamp. |
| `HttpCache::isNotModified(lastModified, ifModifiedSince)` | Return `true` if the client's copy is still fresh (conditional GET check). |
| `HttpCache::send304()` | Send HTTP 304 and `exit`. |
| `HttpCache::sendNoCache()` | Emit `Cache-Control: no-cache, no-store, must-revalidate` + `Pragma: no-cache` + `Expires: 0`. |

### sendCacheControl() parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$maxAge` | `int` | `0` | Seconds the response may be cached. Zero means "must revalidate". |
| `$private` | `bool` | `false` | Use `private` directive (per-user) instead of `public` (shared / CDN). |
| `$immutable` | `bool` | `false` | Add `immutable` directive. Use only for truly static content. |
| `$noStore` | `bool` | `false` | Overrides all other parameters. Disables all caching. |

### sendLastModified() / isNotModified() parameter

`$lastModified` accepts either a MySQL DATETIME string (`'YYYY-MM-DD HH:MM:SS'`) or a Unix integer timestamp. Both are converted to RFC 7231 format for the response header and for comparison.

## Conditional GET flow

When a client has a cached response it will send `If-Modified-Since: <timestamp>` on the next request. The controller checks this before doing any expensive work:

```
Client                           Controller / PHP
  |                                    |
  |-- GET /items (If-Modified-Since) ->|
  |                                    |-- isNotModified(lastModified, ...)
  |                                    |   returns true (cache still valid)
  |                                    |-- send304() → exit
  |<--- 304 Not Modified --------------|
  |    (no body, no DB query needed)   |
```

If the resource has changed since the client's timestamp, `isNotModified()` returns `false` and execution continues normally.

## Controller pattern — list endpoint with Last-Modified

```php
public function indexGetRest(): array
{
    $lastModified = $this->mapper->getLastModifiedAt(); // e.g. '2026-01-15 10:30:00'

    if (HttpCache::isNotModified($lastModified)) {
        HttpCache::send304(); // exits here
    }

    HttpCache::sendCacheControl(maxAge: 60);
    HttpCache::sendLastModified($lastModified);

    return $this->API_RESPONSE->success(['items' => $this->mapper->findALL()]);
}
```

`getLastModifiedAt()` is a mapper method that returns `MAX(updated_at)` from the table. Because the conditional check happens before the `findALL()` query, a cache hit skips the full table scan.

## Cache-Control directives guide

### public vs private

Use `public` (the default) for responses that are the same for every user — anonymous lists, product catalogs, public feed endpoints. These can be stored by CDNs and shared proxies.

Use `private` for responses that are specific to the authenticated user — profile data, inbox contents, user-specific settings. A CDN must not cache these.

### max-age

`max-age=N` tells the client (and any intermediate cache) to serve the stored response for `N` seconds without revalidating. After expiry the client will revalidate using `If-Modified-Since`.

Choosing `max-age`:

| Endpoint type | Suggested max-age |
|---|---|
| Rarely changing reference data | 3600 – 86400 (1 h – 1 day) |
| Frequently updated lists | 30 – 120 (30 s – 2 min) |
| Real-time data (prices, stock) | 0 (must revalidate) |
| User-specific data | 0 or `private` with short max-age |

### immutable

`immutable` tells the browser the response will never change during the `max-age` window — it will not revalidate even when the user presses Reload. Use only for versioned static assets. Do not use for API responses.

### no-store

`no-store` prevents any storage of the response, including in private browser caches. Use for responses containing credentials, tokens, or sensitive personal data.

## When to use no-store vs private vs public

| Scenario | Directive |
|---|---|
| Public list endpoint, same for all users | `public, max-age=60` |
| Authenticated user's own data | `private, max-age=0` |
| Login response, session token in body | `no-store` |
| Password reset page | `no-store` |
| Admin dashboard page | `no-store` or `private, max-age=0` |

## CDN compatibility notes

- `Cache-Control: public, max-age=N` instructs CDNs (Cloudflare, CloudFront, Fastly) to cache the response at the edge. The CDN serves subsequent requests without reaching origin.
- `Cache-Control: private` explicitly excludes shared caches. Most CDNs honour this directive.
- `Last-Modified` combined with `If-Modified-Since` works for client-side conditional GET but CDNs typically use `ETag` for their own revalidation. `HttpCache` does not implement ETag; add it as a future extension if CDN-level revalidation is needed.
- When a CDN is in front of NeNe, set `max-age` to the CDN edge TTL and configure the CDN's origin TTL separately if needed.

## Related

- `class/xion/HttpCache.php` — implementation
- `tests/Unit/Xion/HttpCacheTest.php` — unit tests
- `docs/development/security-headers.md` — non-cache response headers
- `docs/field-trials/2026-05-field-trial-44.md` — FT44 trial report
