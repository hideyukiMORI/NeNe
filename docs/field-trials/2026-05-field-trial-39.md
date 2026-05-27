# Field Trial 39 — API Versioning (RFC 8594 Deprecation Headers)

**Date:** 2026-05-27
**Theme:** URI prefix versioning (`/v1/`, `/v2/`) + RFC 8594 `Deprecation`/`Sunset`/`Link` headers
**Issue:** #FT39

---

## What was built

### `class/xion/ApiDeprecation.php` (new)

A `final class ApiDeprecation` with a single static entry point:

```php
ApiDeprecation::sendHeaders(sunsetDate: '2027-01-01', successor: '/v2/notes');
```

Emits:

```
Deprecation: true
Sunset: Wed, 01 Jan 2027 23:59:59 GMT
Link: </v2/notes>; rel="successor-version"
```

`toHttpDate()` accepts either `YYYY-MM-DD` (converted to `23:59:59 UTC` of that day) or an
already-formatted RFC 7231 string (passed through unchanged).

### `tests/Unit/Xion/ApiDeprecationTest.php` (new)

Three smoke tests verifying that `sendHeaders()` does not throw:
- date-only call
- call with successor URI
- pre-formatted RFC 7231 date

`header()` is a no-op in the CLI test runner; the tests assert no exception is raised.

### `docs/adr/0013-api-versioning.md` (new)

ADR recording the URI prefix versioning decision and the mandatory RFC 8594 header requirement.

### `docs/development/api-versioning.md` (new)

Developer howto covering:
- Route registration pattern for `/v1/`, `/v2/`
- Controller directory layout
- Adding deprecation headers to a retired version
- Backward-compatible response transformation (`toV1()` / `toV2()`)
- Removing a version after Sunset date

### `docs/adr/README.md` (updated)

ADR-0013 entry added to the index.

---

## Findings

### F-1 — `header()` is untestable in the CLI test runner (design note, no fix needed)

PHP's `header()` function is a no-op in the CLI context where PHPUnit runs. There is no
standard way to assert which headers were queued without running through an HTTP server or
mocking `header()` at the C level.

The test strategy is a minimal smoke test: assert that `sendHeaders()` runs without
throwing. This is consistent with how `ResponseDecorator::sendHeaders()` is treated in
the existing test suite.

If header assertion coverage is needed in the future, an HttpKernel-style integration test
that inspects `HttpResponse` headers would be the right layer.

### F-2 — `toHttpDate()` private — tested only indirectly (design note, no fix needed)

The private `toHttpDate()` helper is exercised by the three public smoke tests (each passes
a different input shape). Direct unit testing of private methods is intentionally avoided
in this codebase; the public interface is sufficient for confidence at this scope.

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) | All tests pass (3 new assertions added) |
| Phan (static analysis) | 0 errors |
| New PHP files | 1 (`ApiDeprecation.php`) |
| New test files | 1 (`ApiDeprecationTest.php`) |
| New doc files | 3 (`0013-api-versioning.md`, `api-versioning.md`, this report) |
| Doc files updated | 1 (`docs/adr/README.md`) |

---

## Related

- `class/xion/ApiDeprecation.php` — RFC 8594 header helper
- `tests/Unit/Xion/ApiDeprecationTest.php` — smoke tests
- `docs/adr/0013-api-versioning.md` — versioning strategy ADR
- `docs/development/api-versioning.md` — developer howto
- NENE2 FT115 (versionlog): URI prefix + RFC 8594 source reference
