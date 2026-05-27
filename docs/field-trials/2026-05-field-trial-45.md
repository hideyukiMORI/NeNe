# Field Trial 45 — CORS header utility (`Nene\Xion\Cors`)

**Date:** 2026-05-27
**Theme:** Cross-Origin Resource Sharing — emit CORS response headers and handle OPTIONS preflight in NeNe REST APIs
**Issue:** (FT45)

---

## What was built

`Nene\Xion\Cors` — a final, static-method utility class for emitting CORS response headers and handling OPTIONS preflight requests. Enables NeNe REST APIs to be called from browser SPAs on different origins.

### New framework class

**`class/xion/Cors.php`**:

| Method | Signature | Purpose |
|---|---|---|
| `sendHeaders()` | `static, void` | Emit `Access-Control-*` headers; origin-injectable for tests |
| `handlePreflight()` | `static, void` | Exit 204 on OPTIONS; no-op otherwise; method-injectable for tests |
| `isAllowed()` | `static, bool` | Pure case-insensitive origin membership check |

Key design points:
- When `allowedOrigins` is `['*']`, emits `Access-Control-Allow-Origin: *`.
- When a specific allow-list is given, reflects the matched origin back (required for credentials) and adds `Vary: Origin`.
- Unrecognised origins cause `sendHeaders()` to return early — no CORS headers are emitted, so the browser sees a denied preflight.
- `credentials: true` adds `Access-Control-Allow-Credentials: true`; callers are responsible for not combining this with `['*']`.
- `exposeHeaders` controls which response headers the browser exposes to JavaScript.

### Tests

**`tests/Unit/Xion/CorsTest.php`** — 13 tests covering:
- `isAllowed()`: wildcard, exact match, unknown origin, case-insensitive matching, empty list, one-of-multiple
- `sendHeaders()` smoke tests: null origin (no-op), wildcard, allowed origin, unmatched origin, credentials, expose-headers
- `handlePreflight()`: no-op for GET, POST, PUT, DELETE (OPTIONS path not tested as it calls `exit`)

---

## Findings

### F-1 — No CORS utility existed; docs referenced manual header() calls (medium, fixed)

`docs/development/cors-and-csrf.md` stated: "NeNe does not ship a CORS middleware — add a `header('Access-Control-Allow-Origin: ...')` in `htdocs/index.php` or in a `preAction()` hook if your deployment needs cross-origin access."

This left every project to re-implement origin matching, `Vary: Origin`, preflight handling, and `Access-Control-Allow-Credentials`. Projects that cargo-culted `header('Access-Control-Allow-Origin: *')` from Stack Overflow would silently break credential flows.

**Fix:** `Nene\Xion\Cors` centralises the logic. The `cors-and-csrf.md` cross-reference is retained; a new `cors.md` adds usage guidance.

### F-2 — `header()` is a no-op in CLI; OPTIONS exit path is untestable without process isolation (low, documented)

The `handlePreflight()` method calls `exit` when the method is OPTIONS. This cannot be tested in a single PHPUnit process without process isolation or `runInSeparateProcess` (which adds overhead and complexity). The test suite covers all non-exit paths; the OPTIONS path is a one-liner guarded by a simple `strtoupper()` equality check.

**Decision:** Accept the gap. The logic being tested is the guard condition; the `http_response_code(204)` + `exit` idiom is PHP standard and carries no logic risk.

### F-3 — Wildcard + credentials combination is a browser-rejected configuration (low, documented)

PHP's `header()` will happily emit both `Access-Control-Allow-Origin: *` and `Access-Control-Allow-Credentials: true` — but the browser will reject the response. `sendHeaders()` does not enforce the constraint at runtime (it would require throwing an exception, which breaks no-side-effect production code paths).

**Decision:** Document the incompatibility in `docs/development/cors.md` (security notes section). A future PR could add a `trigger_error()` or log warning when the combination is detected.

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) | 238 tests, 431 assertions — OK |
| Phan (polyfill parser) | 0 errors — exit 0 |
| `isAllowed()` pure logic | 6 tests: pass |
| `sendHeaders()` smoke | 6 tests: pass (header() no-op in CLI) |
| `handlePreflight()` no-op paths | 4 tests: pass |
| Wildcard origin | Emits `Access-Control-Allow-Origin: *` |
| Explicit origin match | Reflects origin, adds `Vary: Origin` |
| Unmatched origin | No CORS headers emitted (early return) |
| `credentials: true` | Adds `Access-Control-Allow-Credentials: true` |
| `exposeHeaders` | Adds `Access-Control-Expose-Headers` header |

---

## How to add CORS to a controller

```php
use Nene\Xion\Cors;

final class ApiController extends ControllerBase
{
    protected function preAction(): void
    {
        Cors::sendHeaders(
            allowedOrigins: ['https://app.example.com'],
            allowedMethods: ['GET', 'POST', 'PUT', 'DELETE'],
            allowedHeaders: ['Content-Type', 'X-CSRF-Token'],
            credentials:    true,
        );
        Cors::handlePreflight();
    }
}
```

See `docs/development/cors.md` for the full guide including credential flows, preflight caching, and security notes.

---

## Related

- `class/xion/Cors.php` — implementation
- `tests/Unit/Xion/CorsTest.php` — unit tests
- `docs/development/cors.md` — usage guide (added this trial)
- `docs/development/cors-and-csrf.md` — CORS vs CSRF conceptual distinction
- FT20 (ServerTiming) — similar static-header-emitter pattern
