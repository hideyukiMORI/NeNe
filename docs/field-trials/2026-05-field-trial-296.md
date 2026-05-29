# Field Trial 296 — PriceAlert

**Date**: 2026-05-29
**Branch**: `feat/ft296-price-alert`
**Baseline**: post FT295 merge

## Goal

Add `Nene\Kit\PriceAlert` — users watch an item for a target price; a price feed fires every watcher whose target is at/above the current price. Distinct from `StockAlert` (FT232, back-in-stock) and `AlertRule` (metric thresholds).

## What was built

### `Nene\Kit\PriceAlert` (`class/kit/PriceAlert.php`)

| Method | Description |
| --- | --- |
| `watch(userId, item, targetCents)` | Arm an alert (re-watch re-arms with new target). |
| `check(item, currentCents, asOf=null): array` | Fire + mark watchers with `target >= current`; returns fired user ids. |
| `isWatching / targetFor / pending` | Read armed state. |
| `unwatch(userId, item)` | Stop watching. |

Key design points:

- **Drop-to-target semantics**: `check` fires watchers where `target_cents >= currentCents` (boundary inclusive), marks them triggered so they don't re-fire until re-watched.
- One alert per `(user_id, item)` (cross-driver upsert); re-watch resets `triggered`.
- Integer cents; `asOf` for deterministic trigger time.

### Tests (`tests/Unit/Kit/PriceAlertTest.php`)

12 unit tests (19 assertions): watch/target, check fires at/below target, boundary inclusive, no-refire after trigger, re-watch re-arms, multiple watchers fire, pending excludes triggered, item separation, unwatch, no-match empty, validation (non-positive target, empty item).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
