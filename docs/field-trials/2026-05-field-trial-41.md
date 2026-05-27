# Field Trial 41 — Account Lockout

**Date:** 2026-05-27
**Theme:** DB-backed account lockout after repeated failed login attempts
**Issue:** #FT41

---

## What was built

`Nene\Xion\LoginAttemptTracker` — a self-contained class that tracks consecutive failed login attempts per user in a `login_attempts` DB table and locks the account once a configurable threshold is reached.

### New class

**`class/xion/LoginAttemptTracker.php`**

| Method | Signature | Description |
|---|---|---|
| `__construct` | `(?PDO $db, int $maxFailures, string $table)` | Defaults to live DB, 5-failure threshold, `login_attempts` table. |
| `recordFailure` | `(string $userId): int` | Increment counter; set `locked_at` when threshold is reached. Returns new count. |
| `isLocked` | `(string $userId): bool` | True when `locked_at IS NOT NULL`. |
| `reset` | `(string $userId): void` | Clears counter and lock. Call after successful login. |
| `failureCount` | `(string $userId): int` | Returns current failure count (0 for unknown users). |

### Schema

```sql
CREATE TABLE login_attempts (
    user_id     VARCHAR(255) PRIMARY KEY,
    failures    INT          NOT NULL DEFAULT 0,
    locked_at   DATETIME     NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

### Supporting changes

| File | Change |
|---|---|
| `config/error_codes.php` | Added `ACCOUNT-LOCKED` → HTTP 423 |
| `docs/development/error-codes.md` | Added `ACCOUNT-LOCKED` row to catalog table |
| `docs/development/account-lockout.md` | New how-to: schema, API table, controller pattern, unlocking, customisation |

### Tests

**`tests/Unit/Xion/LoginAttemptTrackerTest.php`** — 10 tests covering:

- New user is not locked
- `recordFailure` returns incremented count
- Multiple failures accumulate
- Account locks after 5 failures (default threshold)
- Account not locked below threshold (4 failures)
- `reset` clears failure count
- `reset` unlocks a locked account
- `reset` on a non-existent user does not error
- Custom `maxFailures` (locks after 2 with `new LoginAttemptTracker($pdo, 2, ...)`)
- `failureCount` returns 0 for unknown user

All 10 tests pass against SQLite `:memory:`.

---

## Findings

### F-1 — `PdoConnection::getInstance()` returns `PDO` directly (design note)

The prompt spec showed `PdoConnection::getInstance()->getConnection()`. Inspecting the actual class revealed that `getInstance()` already returns the `PDO` object (not a `PdoConnection` wrapper), so `getConnection()` does not exist. The implementation uses `PdoConnection::getInstance()` directly, matching the pattern in `DataMapperBase`.

### F-2 — SQLite `INSERT OR REPLACE` vs MySQL `ON DUPLICATE KEY UPDATE`

Both upsert strategies are needed because the test suite uses SQLite `:memory:` while production uses MySQL. The class detects the driver via `PDO::ATTR_DRIVER_NAME` and branches accordingly. This mirrors the pattern used elsewhere in the framework for cross-DB compatibility.

### F-3 — `reset()` for non-existent users is safe with INSERT OR REPLACE

`INSERT OR REPLACE` (SQLite) and `INSERT … ON DUPLICATE KEY UPDATE` (MySQL) both handle the "no prior row" case correctly: they insert a clean row with `failures = 0` and `locked_at = NULL`. No pre-check is needed.

### F-4 — `locked_at` as sentinel vs a boolean column

Storing the lock timestamp rather than a boolean `is_locked` flag provides two benefits: admins can see when the account was locked without a separate audit trail, and a future "auto-unlock after N minutes" feature can be added by comparing `locked_at` against `NOW()` without a schema change.
