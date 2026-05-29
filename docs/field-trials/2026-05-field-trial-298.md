# Field Trial 298 — BulkDiscount

**Date**: 2026-05-29
**Branch**: `feat/ft298-bulk-discount`
**Baseline**: post FT297 merge

## Goal

Add `Nene\Kit\BulkDiscount` — quantity-tiered percentage discounts per SKU ("buy N+ get X% off"), with integer-cent total computation. Distinct from `CouponCode` (code-based) and `PriceList` (per-customer-type tiers): this is volume pricing.

## What was built

### `Nene\Kit\BulkDiscount` (`class/kit/BulkDiscount.php`)

| Method | Description |
| --- | --- |
| `addTier(sku, minQty, percentOff)` | Upsert a tier (percent 0–100). |
| `discountFor(sku, qty): int` | Highest qualifying tier's percentage (0 if none). |
| `priceFor(sku, qty, unitCents): int` | Discounted total, half-up rounded. |
| `tiers(sku) / removeTier / clear` | List / remove. |

Key design points:

- **Highest qualifying tier**: `min_qty <= qty ORDER BY min_qty DESC LIMIT 1` (boundary inclusive).
- **Integer-cent** total: `gross − round(gross·pct/100)` via `intdiv(gross·pct + 50, 100)` (half-up); no floats.
- Tiers upserted per `(sku, min_qty)`.

### Tests (`tests/Unit/Kit/BulkDiscountTest.php`)

13 unit tests (22 assertions): tier selection (below/at/between/highest), no-tiers 0, priceFor applies discount, no-discount below tier, **half-up rounding (333→330, 350→346 at 1%)**, zero-qty, idempotent tier update, ascending tiers, removeTier, clear, SKU separation, validation (bad percentage, zero minQty).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
