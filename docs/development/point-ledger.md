# Point Ledger

`Nene\Kit\PointLedger` — append-only point / loyalty system with negative-balance prevention.

Points are stored as signed `delta` entries. The balance is the sum of all deltas. The ledger is never modified — only appended.

## Schema

```sql
CREATE TABLE point_ledger (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    user_id     VARCHAR(255) NOT NULL,
    delta       INTEGER      NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_point_ledger_user ON point_ledger (user_id);
```

`delta > 0` for earn entries, `delta < 0` for spend entries.
Balance = `COALESCE(SUM(delta), 0)` for a given user.

## API

| Method | Description |
| --- | --- |
| `earn(string $userId, int $points, string $description = ''): int` | Add points. Returns new ledger entry ID. Throws if $points ≤ 0. |
| `spend(string $userId, int $points, string $description = ''): bool` | Deduct points atomically. Returns false if balance insufficient. Throws if $points ≤ 0. |
| `balance(string $userId): int` | Current balance. Returns 0 for new users. |
| `history(string $userId, int $limit = 20): array` | Recent entries, newest first. Limit clamped to 1–100. |

## Usage

```php
$ledger = new PointLedger($pdo);

// Earn
$ledger->earn('user:1', 100, 'Purchase reward');  // balance: 100
$ledger->earn('user:1', 50,  'Referral bonus');   // balance: 150

// Balance
$ledger->balance('user:1');  // 150

// Spend
$ledger->spend('user:1', 30, 'Redeem discount');   // true  (balance: 120)
$ledger->spend('user:1', 200, 'Too many');          // false (insufficient, balance stays 120)

// History (newest first)
$ledger->history('user:1');
// [
//   ['id' => 4, 'delta' => -30, 'description' => 'Redeem discount', 'created_at' => '...'],
//   ['id' => 3, 'delta' =>  50, 'description' => 'Referral bonus',  'created_at' => '...'],
//   ...
// ]

// Limit history results
$ledger->history('user:1', 5);
```

## Negative-balance prevention

`spend()` wraps the balance check and INSERT in a transaction:

1. `SELECT COALESCE(SUM(delta), 0)` — check current balance
2. If `balance < points` → rollback, return `false`
3. `INSERT … delta = -$points` — record the spend
4. Commit

This prevents concurrent spends from overdrawing in single-DB deployments. For high-concurrency MySQL deployments, consider adding `SELECT … FOR UPDATE` before the balance check.

## Append-only design

The ledger table is **never updated or deleted** — only appended. This gives you:

- Full audit trail of all transactions
- Easy point history for users
- Simple reconciliation: `SUM(delta)` is the balance at any point in time

## Key design points

- **`delta` sign**: positive for earn, negative for spend. Balance = `SUM(delta)`.
- **`COALESCE(SUM, 0)`**: balance returns 0 for users with no entries.
- **`history()` limit**: clamped to 1–100 to prevent runaway queries.
- **User isolation**: all queries scoped by `user_id`.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE point_ledger (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    user_id     VARCHAR(255) NOT NULL,
    delta       INTEGER      NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT \'\',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$ledger = new PointLedger($db);
```
