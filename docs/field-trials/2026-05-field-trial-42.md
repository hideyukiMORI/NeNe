# Field Trial 42 — Signed URL

**Date:** 2026-05-27
**Theme:** Time-limited access tokens embedded in URLs — `Nene\Xion\SignedUrl`
**Issue:** FT42

---

## What was built

`Nene\Xion\SignedUrl` — a `final readonly` class that generates and verifies HMAC-SHA256-signed URLs with an expiry timestamp. Enables time-limited access to resources without session authentication: file downloads, email confirmation links, password-reset links, admin invitations.

### New framework class

**`class/xion/SignedUrl.php`**:

| Method | Signature | Purpose |
|---|---|---|
| `sign` | `sign(string $path, array $params = [], ?int $ttl = null, ?int $now = null): string` | Build a signed URL with `expires` and `signature` query params |
| `verify` | `verify(string $url, ?int $now = null): bool` | Return `true` if unexpired and signature matches; never throws |
| `requireValid` | `requireValid(string $url, ?int $now = null): void` | Throw `HttpTermination(410)` for expired, `HttpTermination(403)` for invalid |
| `generateSecret` | `static generateSecret(): string` | Return a 64-char hex string (32 bytes of cryptographic entropy) |

Parameters are sorted by key before signing so that query-string parameter order does not affect validity. The `hash_equals` timing-safe comparison is used in `verify()` to prevent timing-side-channel attacks.

### Error codes added

| Code | HTTP status | When thrown |
|---|---|---|
| `SIGNED-URL-EXPIRED` | 410 Gone | `expires` timestamp is in the past |
| `SIGNED-URL-INVALID` | 403 Forbidden | Signature does not match or required params missing |

### Tests

**`tests/Unit/Xion/SignedUrlTest.php`** — 15 tests, 27 assertions:
- Round-trip sign + verify
- Expired URL detection (injectable `$now`)
- Tampered signature detection
- Tampered expiry detection (signature no longer matches)
- Missing `expires` / `signature` parameters
- URL with no query string
- Custom TTL respected (verify succeeds within window, fails outside)
- `requireValid()` throws HTTP 410 on expired URL
- `requireValid()` throws HTTP 403 on invalid signature
- `requireValid()` does not throw for valid URL
- `generateSecret()` returns 64-char hex
- Default TTL applied (verify 1 second before and after)

### Documentation

- `docs/development/signed-urls.md` — API reference, controller guard pattern, email confirmation flow example, security notes (secret length, TTL guidance, replay protection, clock skew).
- `config/error_codes.php` — two new error codes.
- `docs/development/error-codes.md` — two new catalog rows.

---

## Findings

### F-1 — `readonly` class requires constructor promotion only (design note)

PHP 8.2+ `readonly` classes require all properties to be promoted in the constructor. The `ALGO` constant was declared as `private const` which is allowed — constants are exempt from the readonly promotion requirement. Verified that `final readonly class SignedUrl` with a `private const` compiles cleanly.

### F-2 — `requireValid()` checks expiry separately before `verify()` to return 410 vs 403 (design decision)

`verify()` returns a plain `bool` and cannot distinguish "expired" from "invalid signature". `requireValid()` must therefore check `expires` directly before calling `verify()` to decide which error code to use. This produces the expected 410/403 split:

- Expired (signature would be valid but timestamp passed): 410 Gone.
- Signature invalid or params missing: 403 Forbidden.

A URL where both `expires` is past AND the signature is invalid returns 410 (expiry is checked first). This is intentional — 410 is the most actionable error for the recipient.

### F-3 — Injectable `$now` parameter enables deterministic tests (pattern confirmed)

All time-dependent behavior is tested by passing a fixed `$now` integer to `sign()`, `verify()`, and `requireValid()`. No mocking of `time()` is needed. This pattern matches how `AuthSession` and other time-sensitive classes are tested in the framework.

### F-4 — `hash_build_query` encodes params consistently (no edge cases found)

`http_build_query` uses `&` as the separator and percent-encodes special characters. Since both `sign()` and `verify()` use the same function with `ksort()` applied, the payload is deterministic regardless of the original parameter order. Tested with params containing spaces and special characters in `testSignAndVerifyRoundTripWithExtraParams`.

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) | 239 tests, 443 assertions — OK |
| Phan | exit 0 — no errors |
| `testEveryRuntimeCodeAppearsInDocsMarkdownTable` | Passes — both new codes documented |

---

## How to add a signed URL to a new endpoint

1. Store a `SIGNED_URL_SECRET` in `.env` (generate with `SignedUrl::generateSecret()`).
2. Instantiate `new SignedUrl($_ENV['SIGNED_URL_SECRET'])` — or inject via constructor.
3. Call `$signer->sign('/path', $params, $ttl)` to produce a shareable URL.
4. In the receiving controller, call `$signer->requireValid($_SERVER['REQUEST_URI'])` before serving the resource.
5. For one-time use, record used signatures in a `used_tokens` table after first use.

See `docs/development/signed-urls.md` for full examples.
