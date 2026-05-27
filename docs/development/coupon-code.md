# Coupon Code

`Nene\Xion\CouponCode` — coupon / promo code management with usage limits, expiry, and per-user redemption tracking.

## Schema

```sql
CREATE TABLE coupon_codes (
    id         INTEGER      PRIMARY KEY AUTOINCREMENT,
    code       VARCHAR(64)  NOT NULL UNIQUE,
    discount   INTEGER      NOT NULL DEFAULT 0,
    max_uses   INTEGER      DEFAULT NULL,
    expires_at DATETIME     DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE coupon_redemptions (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    coupon_id   INTEGER      NOT NULL,
    user_id     VARCHAR(255) NOT NULL,
    redeemed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (coupon_id, user_id)
);
```

The `UNIQUE (coupon_id, user_id)` constraint in `coupon_redemptions` prevents duplicate redemptions at the DB layer.

## API

| Method | Description |
| --- | --- |
| `create(string $code, int $discount = 0, ?int $maxUses = null, ?int $expiresIn = null): int` | Create coupon. Returns new ID. |
| `isValid(string $code, string $userId): bool` | Check validity without redeeming. |
| `redeem(string $code, string $userId): ?array` | Atomically redeem. Returns coupon data or null on failure. |
| `find(string $code): ?array` | Get coupon info. Returns null if not found. |

## Usage

```php
$coupons = new CouponCode($pdo);

// Create
$id = $coupons->create('SUMMER20', discount: 20, maxUses: 100, expiresIn: 86400 * 30);

// Validate without redeeming
$coupons->isValid('SUMMER20', 'user:1');  // true

// Redeem (atomic)
$result = $coupons->redeem('SUMMER20', 'user:1');
if ($result === null) {
    // invalid code, expired, over limit, or already used by this user
    http_response_code(422); exit;
}
echo $result['discount'];     // 20
echo $result['redeemed_at'];  // '2026-05-27 12:34:56'

// Duplicate redemption by same user
$coupons->redeem('SUMMER20', 'user:1');  // null

// Different user can still redeem
$coupons->redeem('SUMMER20', 'user:2');  // array (if max_uses not reached)

// Find
$coupons->find('SUMMER20');
// ['id' => 1, 'code' => 'SUMMER20', 'discount' => 20, 'max_uses' => 100, ...]
```

## Validation rules

`redeem()` (and `isValid()`) fails with `null`/`false` when:

| Condition | Result |
| --- | --- |
| Code does not exist | null |
| `expires_at` is in the past | null |
| `max_uses` reached | null |
| User already redeemed this code | null |

All failures return null — no specific error code is exposed to prevent oracle attacks.

## `discount` semantics

`discount` is an application-defined integer. CouponCode does not interpret it — your app decides whether it's a percentage, a fixed amount, or a product SKU. This keeps the helper generic.

## Atomicity

`redeem()` wraps validation and the `INSERT` into a transaction. The `UNIQUE (coupon_id, user_id)` constraint + `INSERT OR IGNORE` / `INSERT IGNORE` ensures that concurrent redemption attempts by the same user are idempotent — only one succeeds; the second gets `rowCount() === 0` and returns null.

## Key design points

- **Two tables**: `coupon_codes` (definition) + `coupon_redemptions` (audit trail). Usage count derived from `COUNT(*)`.
- **Per-user prevention**: DB-enforced `UNIQUE (coupon_id, user_id)`.
- **Atomic redeem**: transaction + UNIQUE constraint together prevent double-redemption races.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE coupon_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(64) NOT NULL UNIQUE,
    discount INTEGER NOT NULL DEFAULT 0,
    max_uses INTEGER DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE TABLE coupon_redemptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id INTEGER NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (coupon_id, user_id)
)');
$coupons = new CouponCode($db);
```
