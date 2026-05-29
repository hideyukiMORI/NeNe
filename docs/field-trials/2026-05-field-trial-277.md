# Field Trial 277 — WeightedPicker

**Date**: 2026-05-29
**Branch**: `feat/ft277-weighted-picker`
**Baseline**: post FT276 merge

## Goal

Add `Nene\Xion\WeightedPicker` — weighted random selection from a named pool (weighted A/B variants, creative rotation, prize draws, capability-weighted load distribution).

## What was built

### `Nene\Xion\WeightedPicker` (`class/xion/WeightedPicker.php`)

| Method | Description |
| --- | --- |
| `setWeight(pool, item, weight)` | Upsert an item's weight (>= 0). |
| `pick(pool, roll=null): ?string` | Weighted pick; `roll` gives deterministic selection. |
| `totalWeight(pool) / weights(pool)` | Sum / list. |
| `remove(pool, item) / clear(pool)` | Mutate. |

Key design points:

- **Deterministic-or-random**: `pick($pool, $roll)` selects via an explicit roll in `[0, total)` (reproducible / testable); `pick($pool)` rolls `random_int`.
- **Zero-weight items retained but never selected**; `pick` returns null when no positive weight exists.
- **Stable banding**: entries walked in insertion (id) order so bands are predictable.
- **Cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/WeightedPickerTest.php`)

13 unit tests (27 assertions): deterministic roll bands (A=[0,70), B=[70,100)); totalWeight; null when no positive weight; zero-weight never picked; idempotent update; weights listing; out-of-range roll throws; ~80/20 random distribution over 2000 draws; remove; clear; independent pools; validation (negative weight, empty item).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
