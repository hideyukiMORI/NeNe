# Field Trial 44 — HTTP Cache Headers

**Date:** 2026-05-27
**Theme:** `Cache-Control`, `Last-Modified`, and conditional GET (304 Not Modified) for REST endpoints
**Issue:** FT44

---

## What was built

A `Nene\Xion\HttpCache` static utility class that provides HTTP cache header emission and conditional GET handling. Designed for read-heavy REST endpoints where bandwidth savings and CDN compatibility matter.

### New framework class

**`class/xion/HttpCache.php`** — static utility for HTTP caching:

| Method | Description |
|---|---|
| `sendCacheControl(maxAge, private, immutable, noStore)` | Emit `Cache-Control` header with common directive combinations |
| `sendLastModified(lastModified)` | Emit `Last-Modified` header from a MySQL DATETIME string or Unix timestamp |
| `isNotModified(lastModified, ifModifiedSince)` | Check `If-Modified-Since` against the resource's last-modified time; returns `true` if client cache is still valid |
| `send304()` | Send HTTP 304 and `exit` (return type `never`) |
| `sendNoCache()` | Emit `Cache-Control: no-cache, no-store, must-revalidate` + `Pragma: no-cache` + `Expires: 0` |

### Tests

**`tests/Unit/Xion/HttpCacheTest.php`** — 15 tests covering:

- Smoke tests for all `header()`-emitting methods (no exception thrown in CLI context)
- `isNotModified()` logic: no header present, client older, client matches, client newer, invalid header, Unix int timestamp, empty header string

---

## Findings

### F-1 — Day-of-week in RFC 7231 date literals must match the actual date (low, test-authoring note)

When writing tests that use RFC 7231 date strings (e.g. `'Mon, 14 Jan 2026 10:30:00 GMT'`), the day-of-week abbreviation must be correct for that date. PHP's `strtotime()` advances to the next matching weekday if the day-of-week does not match the calendar date.

January 14, 2026 is a Wednesday, not Monday. An initial test used `'Mon, 14 Jan 2026 10:30:00 GMT'`, which `strtotime` silently resolved to January 19 (the next Monday), causing a spurious test failure.

**Fix:** Verified calendar dates with `date('D', strtotime(...))` and corrected the test fixtures to `'Wed, 14 Jan 2026 10:30:00 GMT'` and `'Fri, 16 Jan 2026 10:30:00 GMT'`. This is a test-authoring pitfall to note in any guide covering RFC 7231 date strings.

### F-2 — `isNotModified()` with a plain MySQL DATETIME uses local timezone (design note)

`strtotime('2026-01-15 10:30:00')` (no timezone suffix) uses the server's local timezone. The `If-Modified-Since` header from the client uses GMT. When the container timezone differs from UTC, the comparison can be incorrect.

The framework's Docker image sets `date.timezone = UTC` in `ini/php.ini`. Tests pass and the comparison is correct under UTC. A production deployment in a non-UTC timezone should either keep the PHP timezone as UTC or ensure `$lastModified` values include an explicit timezone when passing them to `strtotime()`.

This is documented in `docs/development/http-caching.md` rather than changed in the implementation, as forcing UTC inside the method would be surprising behaviour.

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) | 237 tests, 427 assertions — OK |
| Phan | 0 errors (exit 0) |
| PHP syntax | `class/xion/HttpCache.php` — no errors |
| `isNotModified()` false / true / edge cases | All 8 logic tests pass |
| Smoke tests (header-emitting methods) | 7 smoke tests pass |

---

## How to use HttpCache in a controller

```php
public function indexGetRest(): array
{
    $lastModified = $this->mapper->getLastModifiedAt(); // MAX(updated_at)

    if (HttpCache::isNotModified($lastModified)) {
        HttpCache::send304(); // exits here; no DB query
    }

    HttpCache::sendCacheControl(maxAge: 60);
    HttpCache::sendLastModified($lastModified);

    return $this->API_RESPONSE->success(['items' => $this->mapper->findALL()]);
}
```

For sensitive data (login, user profile), use `sendNoCache()` instead.

---

## Related

- `class/xion/HttpCache.php` — implementation
- `tests/Unit/Xion/HttpCacheTest.php` — unit tests
- `docs/development/http-caching.md` — developer guide
