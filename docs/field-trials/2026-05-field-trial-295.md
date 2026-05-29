# Field Trial 295 — PurchaseLimit

**Date**: 2026-05-29
**Branch**: `feat/ft295-purchase-limit`
**Baseline**: post FT294 merge

## Goal

Add `Nene\Kit\PurchaseLimit` — per-user purchase caps per SKU over a rolling window ("max N per user per P days": limited drops, fair allocation, regulated goods). Distinct from `Quota` (FT166, plan resource quotas) and `StorageQuota`.

## What was built

### `Nene\Kit\PurchaseLimit` (`class/kit/PurchaseLimit.php`)

Two tables: a per-SKU policy (max + window) and a purchase-records log.

| Method | Description |
| --- | --- |
| `setLimit(sku, maxQty, periodDays) / removeLimit(sku)` | Configure / clear cap. |
| `record(sku, userId, qty, asOf=null)` | Log a purchase. |
| `purchasedInPeriod(sku, userId, asOf=null)` | Qty bought in the rolling window. |
| `remaining(sku, userId, asOf=null): ?int` | Units left (null = un-capped). |
| `canPurchase(sku, userId, qty, asOf=null): bool` | Cap check (un-capped → true). |

Key design points:

- **Rolling window**: counts records with `purchased_at >= asOf - periodDays` (boundary inclusive); old purchases age out.
- **Un-capped = unlimited**: no policy → `remaining` null, `canPurchase` true; records are still logged.
- Integer quantities; `asOf` makes the window deterministic in tests.

### Tests (`tests/Unit/Kit/PurchaseLimitTest.php`)

12 unit tests (21 assertions): un-capped always allows, remaining/cap, canPurchase up-to-cap, rolling window expiry, window boundary inclusive, user/SKU separation, removeLimit → unlimited, idempotent setLimit, validation (zero limit, zero record qty, zero canPurchase qty).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
