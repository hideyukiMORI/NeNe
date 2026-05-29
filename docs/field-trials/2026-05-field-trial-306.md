# Field Trial 306 — GiftRegistry

**Date**: 2026-05-29
**Branch**: `feat/ft306-gift-registry`
**Baseline**: post FT305 merge

## Goal

Add `Nene\Kit\GiftRegistry` — a wedding/baby-style gift registry where the owner lists desired items and gift-givers claim quantities so the same gift isn't bought twice. Distinct from `Wishlist` (FT81, private saved items, no claiming): the novel mechanic is claimed-vs-desired accounting.

## What was built

### `Nene\Kit\GiftRegistry` (`class/kit/GiftRegistry.php`)

| Method | Description |
| --- | --- |
| `addItem(registry, item, desiredQty=1)` | List/requantify (preserves claims). |
| `claim(registry, item, qty=1): bool` | Claim if within desired (atomic). |
| `unclaim(registry, item, qty=1)` | Release (clamps at 0). |
| `claimedQty / remaining / isFulfilled` | Accounting. |
| `items(registry) / removeItem(...)` | List / delete. |

Key design points:

- **Claimed-vs-desired guard**: `claim` succeeds only if `claimed + qty <= desired` (atomic read-modify-write in a transaction), preventing over-gifting; unknown item → false.
- **Requantify preserves claims**: `addItem` updates desired_qty but keeps claimed_qty.
- `unclaim` clamps at 0; `isFulfilled` = claimed >= desired.

### Tests (`tests/Unit/Kit/GiftRegistryTest.php`)

14 unit tests (28 assertions): add/remaining, claim reduces remaining, claim up-to-desired, exceeding fails (unchanged), unknown item fails, unclaim releases + clamps at 0, isFulfilled, items accounting, requantify preserves claims, removeItem, registry separation, validation (zero desired, zero claim qty).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 14 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
