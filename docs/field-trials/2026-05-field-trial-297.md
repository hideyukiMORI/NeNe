# Field Trial 297 — StockTransfer

**Date**: 2026-05-29
**Branch**: `feat/ft297-stock-transfer`
**Baseline**: post FT296 merge

## Goal

Add `Nene\Kit\StockTransfer` — multi-location stock ledger that tracks SKU quantities across named locations and moves them between locations atomically. Distinct from `InventoryStock` (FT201, single-pool reserve/release/commit): this is about *where* stock sits.

## What was built

### `Nene\Kit\StockTransfer` (`class/kit/StockTransfer.php`)

Append-only delta ledger; balances are derived sums.

| Method | Description |
| --- | --- |
| `receive(sku, location, qty)` | Inbound stock (+delta). |
| `transfer(sku, from, to, qty)` | Atomic move; guards against overdrawing source. |
| `balance(sku, location) / totalStock(sku)` | Derived sums. |
| `locations(sku)` | Non-zero balances per location. |
| `history(sku, location=null)` | Movement log. |

Key design points:

- **Transfer = two ledger rows in a transaction** (−from, +to), guarded by `balance(from) >= qty`; an overdraw throws and applies nothing (rollback).
- **Total conserved** across moves (a transfer is delta-neutral); `locations()` hides drained (zero) locations.
- Distinct from InventoryStock's reservation model.

### Tests (`tests/Unit/Kit/StockTransferTest.php`)

11 unit tests (18 assertions): receive/balance, transfer moves, total conserved across chained moves, overdraw guard, **overdraw is all-or-nothing (no partial apply)**, locations excludes zero, history rows, SKU separation, same-location rejected, validation (zero qty, empty location).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 11 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
