# Field Trial 66 — Coupon / Promo Code

**Date**: 2026-05-27
**Branch**: `feat/ft66-coupon-code`
**Baseline**: post FT65 merge

## Goal

Establish a coupon/promo-code management pattern for NeNe applications. Handle usage limits, expiry, and per-user duplicate redemption prevention with transactional atomicity.

## What was built

### `Nene\Xion\CouponCode` (`class/xion/CouponCode.php`)

Two-table DB-backed coupon manager providing:

| Method | Description |
| --- | --- |
| `create(string $code, int $discount = 0, ?int $maxUses = null, ?int $expiresIn = null): int` | Create coupon. Returns ID. |
| `isValid(string $code, string $userId): bool` | Validate without redeeming. |
| `redeem(string $code, string $userId): ?array` | Atomic validate-and-record. |
| `find(string $code): ?array` | Lookup coupon info. |

Key design points:

- **Two tables**: `coupon_codes` (definition) + `coupon_redemptions` (audit trail per user).
- **Usage count**: derived from `COUNT(*)` on redemptions — no drift from a counter column.
- **Per-user prevention**: `UNIQUE (coupon_id, user_id)` DB constraint.
- **Atomic redeem**: transaction + `INSERT OR IGNORE` — `rowCount() === 0` catches concurrent races.
- **Generic discount**: integer value; semantics defined by the application.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/CouponCodeTest.php`)

19 unit tests covering:

- create returns id
- create with discount
- create with max_uses
- create with expiry stores expires_at
- isValid true for valid code
- isValid false for unknown code
- isValid false for expired coupon
- isValid false when max_uses reached
- isValid false if user already redeemed
- redeem valid coupon returns array
- redeem returns null for unknown code
- redeem returns null for expired coupon
- redeem returns null if already redeemed by user
- redeem returns null when max_uses reached
- different users can redeem same coupon (up to limit)
- redeem result contains redeemed_at
- find returns array for existing code
- find returns null for unknown code
- find max_uses is null when unlimited

### Howto (`docs/development/coupon-code.md`)

Covers: schema, API table, usage examples, validation rules, discount semantics, atomicity, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`CouponCode` is a clean `Nene\Xion` helper. 19 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
