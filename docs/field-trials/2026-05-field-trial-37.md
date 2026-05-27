# Field Trial 37 — Idempotency Key Store

**Date:** 2026-05-27
**Theme:** DB-backed idempotency key cache — `X-Idempotency-Key` header, replay detection, `X-Idempotent-Replayed` response header
**Reference:** NENE2 FT55 (idempotencylog)

---

## What was built

`Nene\Xion\IdempotencyStore` — a `final` class that stores and replays POST
responses keyed by the SHA-256 hash of an arbitrary raw string.

### Class

**`class/xion/IdempotencyStore.php`**

| Method | Purpose |
|---|---|
| `get(string $rawKey): ?array` | Return `['status_code', 'body']` or `null` if not cached. |
| `put(string $rawKey, int $statusCode, string $body): void` | Cache a response. Silent on duplicate-key violations. |
| `hash(string $rawKey): string` | SHA-256 hex of the raw key (64 chars). |

Constructor accepts optional `PDO $db` (defaults to `PdoConnection::getInstance()`)
and `string $table` (defaults to `'idempotency_keys'`) for testability.

MySQL uses `INSERT IGNORE`; SQLite uses `INSERT OR IGNORE` (driver detected via
`PDO::ATTR_DRIVER_NAME` at runtime).

### Tests

**`tests/Unit/Xion/IdempotencyStoreTest.php`** — 8 tests, 16 assertions.

All tests use SQLite `:memory:` — no container or external database required.
The schema is created in `setUp()` so each test starts with an empty table.

| Test | What is verified |
|---|---|
| `testGetReturnsNullForUnknownKey` | `get()` returns `null` for a key that was never stored. |
| `testPutThenGetReturnsSameBodyAndStatusCode` | Round-trip with status 201. |
| `testPutThenGetWith200StatusCode` | Round-trip with status 200. |
| `testSecondPutDoesNotOverwriteFirstValue` | Second `put()` with same key is silently ignored. |
| `testHashReturnsSixtyFourCharHex` | `hash()` returns a 64-char lowercase hex string. |
| `testHashIsDeterministic` | Same input produces the same hash on repeated calls. |
| `testHashDifferentKeysProduceDifferentHashes` | Different keys produce different hashes. |
| `testDifferentKeysAreStoredAndRetrievedIndependently` | Two keys coexist without collision. |

### Documentation

**`docs/development/idempotency.md`** — howto covering:
- Schema (SchemaDefinition snippet + raw SQL)
- Controller pattern (get → early-return, operate, put)
- Request header (`X-Idempotency-Key`)
- Response header (`X-Idempotent-Replayed: true`)
- `IdempotencyStore` API reference
- Security: max key length, TTL, purge strategy, key scoping for multi-tenant services

---

## Findings

### F-1 — `put()` draft had dead code before the working statement (low, fixed)

The specification snippet called `$db->prepare(...)` twice — once with a hard-coded
`INSERT IGNORE` and then again with the driver-detected SQL. The first statement
was constructed and immediately discarded. The implementation was cleaned so only
the driver-correct statement is prepared.

### F-2 — No framework convention for `X-Idempotency-Key` validation (low, documented)

`IdempotencyStore` is intentionally key-agnostic (it accepts any `string`). There
is no framework-level guard on key length or character set. Without application-level
validation, an attacker could send a 1 MB header value that still hashes fine but
wastes memory and log space.

Documented in `docs/development/idempotency.md` under Security: validate that
`strlen($rawKey) <= 256` before calling `get()` or `put()`.

### F-3 — TTL / purge is a deployment concern, not a store concern (design note)

The store does not enforce a TTL. Rows accumulate until explicitly purged. The
two viable strategies (cron DELETE vs on-read expiry check) are both valid and
the right choice depends on table volume and whether "expired key treated as new
call" is acceptable.

Documented with both options; cron purge is the recommended default for simplicity.

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) | 230 tests, 430 assertions — OK (was 222/412 before this trial) |
| Phan | 0 errors (exit 0) |
| IdempotencyStoreTest | 8 tests, 16 assertions — all pass |
| SQLite :memory: round-trip | get→null, put+get→match, duplicate put→no-overwrite — all pass |

---

## Related

- `class/xion/IdempotencyStore.php`
- `tests/Unit/Xion/IdempotencyStoreTest.php`
- `docs/development/idempotency.md`
- NENE2 FT55 (idempotencylog) — original reference implementation
