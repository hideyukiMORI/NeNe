# Subscription

`Nene\Xion\Subscription` — user subscription / plan management with history tracking.

## Schema

```sql
CREATE TABLE subscriptions (
    id         INTEGER      PRIMARY KEY AUTOINCREMENT,
    user_id    VARCHAR(255) NOT NULL UNIQUE,
    plan       VARCHAR(64)  NOT NULL,
    status     VARCHAR(16)  NOT NULL DEFAULT 'active',
    expires_at DATETIME     DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE subscription_history (
    id         INTEGER      PRIMARY KEY AUTOINCREMENT,
    user_id    VARCHAR(255) NOT NULL,
    plan       VARCHAR(64)  NOT NULL,
    action     VARCHAR(32)  NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_subscription_history_user ON subscription_history (user_id);
```

## API

| Method | Description |
| --- | --- |
| `subscribe(string $userId, string $plan, ?int $expiresIn = null): void` | Create or replace a subscription. |
| `changePlan(string $userId, string $plan): bool` | Change plan without touching status/expiry. |
| `cancel(string $userId): bool` | Set status to `'cancelled'`. |
| `renew(string $userId, ?int $expiresIn = null): bool` | Restore to `'active'` with new expiry. |
| `isActive(string $userId): bool` | True if status is `'active'` and not past expires_at. |
| `currentPlan(string $userId): ?string` | Current plan name, or null if no subscription. |
| `history(string $userId): array` | Plan change log, newest first. |

## Usage

```php
$sub = new Subscription($pdo);

// Subscribe
$sub->subscribe('user:1', 'pro', expiresIn: 86400 * 30);
$sub->isActive('user:1');     // true
$sub->currentPlan('user:1');  // 'pro'

// Upgrade
$sub->changePlan('user:1', 'enterprise');

// Cancel
$sub->cancel('user:1');
$sub->isActive('user:1');  // false

// Renew after cancellation
$sub->renew('user:1', 86400 * 30);
$sub->isActive('user:1');  // true

// History (newest first)
$sub->history('user:1');
// [
//   ['id' => 4, 'plan' => 'enterprise', 'action' => 'renew',     'created_at' => '...'],
//   ['id' => 3, 'plan' => 'enterprise', 'action' => 'cancel',    'created_at' => '...'],
//   ['id' => 2, 'plan' => 'enterprise', 'action' => 'change',    'created_at' => '...'],
//   ['id' => 1, 'plan' => 'pro',        'action' => 'subscribe', 'created_at' => '...'],
// ]
```

## Status values

| Status | Meaning |
| --- | --- |
| `active` | Subscription is running. |
| `cancelled` | User cancelled; may still be valid if expires_at is in the future. |

`isActive()` returns `false` when: no subscription, `status = 'cancelled'`, or `expires_at` is in the past.

## Key design points

- **One row per user**: `UNIQUE (user_id)` in `subscriptions`. `subscribe()` upserts.
- **History table**: every plan/status change writes a history row with `action` and `plan`.
- **`cancel()`**: idempotent guard — won't double-cancel (checks `status != 'cancelled'`).
- **`renew()`**: works from any status — restores `active` and sets new expiry.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
// Create subscriptions + subscription_history tables (see schema above)
$sub = new Subscription($db);
```
