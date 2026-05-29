# Field Trial 272 — PercentageRollout

**Date**: 2026-05-29
**Branch**: `feat/ft272-percentage-rollout`
**Baseline**: post FT271 merge

## Goal

Add `Nene\Xion\PercentageRollout` — gradual feature rollout to a deterministic percentage of keys, with sticky bucketing. Distinct from `FeatureFlag` (FT124, global on/off): membership is hashed from `(flag, key)`.

## What was built

### `Nene\Xion\PercentageRollout` (`class/xion/PercentageRollout.php`)

| Method | Description |
| --- | --- |
| `setPercentage(flag, 0..100)` | Upsert rollout percentage. |
| `percentageFor(flag): int` | Current percentage (0 if unset). |
| `isEnabled(flag, key): bool` | Deterministic, sticky membership. |
| `enableFully / disable / remove (flag)` | 100% / 0% / delete. |
| `flags(): array` | List flags + percentages. |

Key design points:

- **Sticky + monotonic**: bucket = `crc32(flag.':'.key) % 100`; `isEnabled` is `bucket < pct`. The same key always gets the same answer for a given pct (no per-request flicker), and raising pct only *adds* keys — a key live at 30% stays live at 60%.
- **Cheap fast-paths**: 0% short-circuits to false, 100% to true (no hashing).
- **Cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/PercentageRolloutTest.php`)

16 unit tests (259 assertions), mostly property-based (hash output not hard-coded): default 0; 0%→nobody, 100%→everybody; unconfigured→false; determinism for a key; **monotonic membership** (30% ⊆ 60% ⊆ 90% over 200 keys); approximate distribution (~40% over 2000 keys within slack); enableFully/disable; idempotent set; remove; ordered flags(); range validation (101, -1, empty flag).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 16 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
