# Account Lockout

NeNe ships `Nene\Kit\LoginAttemptTracker` — a DB-backed class that counts consecutive failed login attempts per user and locks the account once a configurable threshold is reached.

## Why account lockout matters

Without lockout, an attacker can issue unlimited `POST /session/login` requests against a known user ID, attempting passwords at network speed. Even a modest rate (500 req/s) exhausts an 8-character alphanumeric space in hours. A lockout after N failures halts that attack and forces the attacker to deal with unlock flow (which can involve email or admin intervention), dramatically raising the cost of an online brute-force.

Combined with rate limiting (see `docs/development/rate-limiting.md`) and strong password hashing (bcrypt/argon2), lockout closes the most common vector for credential stuffing.

## Schema

Run this one-time migration before enabling the feature:

```sql
CREATE TABLE login_attempts (
    user_id     VARCHAR(255) PRIMARY KEY,
    failures    INT          NOT NULL DEFAULT 0,
    locked_at   DATETIME     NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

For MySQL the `updated_at` column can be given `ON UPDATE CURRENT_TIMESTAMP` if you want automatic refresh on every write. The class manages `updated_at` explicitly so both MySQL and SQLite behave the same way.

## `LoginAttemptTracker` API

| Method | Signature | Description |
|---|---|---|
| `__construct` | `(?PDO $db, int $maxFailures, string $table)` | All parameters optional. Defaults: live DB connection, 5 failures, `login_attempts` table. |
| `recordFailure` | `(string $userId): int` | Increment the failure counter. Returns the new count. Sets `locked_at` when the count reaches `$maxFailures`. |
| `isLocked` | `(string $userId): bool` | Returns `true` when `locked_at IS NOT NULL` for the user. Returns `false` for users with no record. |
| `reset` | `(string $userId): void` | Sets `failures = 0` and `locked_at = NULL`. Call after a successful login. Safe to call for users with no prior record. |
| `failureCount` | `(string $userId): int` | Returns the current failure count. Returns `0` for users with no record. |

## Controller pattern

Check lock status before verifying credentials so that a locked account gets a `423` response before any password hash work:

```php
use Nene\Kit\LoginAttemptTracker;
use Nene\Xion\DomainException;

$tracker = new LoginAttemptTracker();

// 1. Reject immediately if already locked
if ($tracker->isLocked($userId)) {
    throw new DomainException('ACCOUNT-LOCKED');
    // HTTP 423 Locked
}

// 2. Verify credentials
if (!$auth->verify($userId, $password)) {
    $tracker->recordFailure($userId);
    throw new DomainException('LOGIN-FAILED');
    // HTTP 401
}

// 3. Successful login — clear any prior failures
$tracker->reset($userId);
// start session, return 200
```

After 5 consecutive failures (the default), `isLocked()` returns `true` and the account is refused until an admin resets it.

## Unlocking manually

### Admin tool (preferred)

```bash
php cli/setupDatabase.php  # example; substitute your admin CLI
```

If you build an admin panel, call `$tracker->reset($userId)` to unlock programmatically.

### Direct SQL

```sql
UPDATE login_attempts
SET    failures = 0, locked_at = NULL, updated_at = NOW()
WHERE  user_id = 'user@example.com';
```

## Customizing the threshold

Pass `maxFailures` to the constructor to override the default of 5:

```php
// Lock after 10 failures instead of 5
$tracker = new LoginAttemptTracker(maxFailures: 10);

// Use a different table (e.g. per-app isolation)
$tracker = new LoginAttemptTracker(table: 'admin_login_attempts');

// Inject a test PDO (SQLite :memory:) in unit tests
$tracker = new LoginAttemptTracker($pdo, 3, 'login_attempts');
```

## Related files

- `class/kit/LoginAttemptTracker.php` — implementation
- `config/error_codes.php` — `ACCOUNT-LOCKED` entry (HTTP 423)
- `docs/development/error-codes.md` — error code catalog
- `docs/development/rate-limiting.md` — complementary per-IP rate limiting
- `docs/field-trials/2026-05-field-trial-41.md` — FT41 report
