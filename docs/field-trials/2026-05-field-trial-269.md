# Field Trial 269 — MaintenanceWindow

**Date**: 2026-05-29
**Branch**: `feat/ft269-maintenance-window`
**Baseline**: post FT268 merge

## Goal

Add `Nene\Xion\MaintenanceWindow` — scheduled, time-bounded maintenance windows per service scope. Distinct from `MaintenanceMode` (FT87), a single global on/off flag: this models scheduled intervals, holds several upcoming entries, and is scoped.

## What was built

### `Nene\Xion\MaintenanceWindow` (`class/xion/MaintenanceWindow.php`)

A window is active during the half-open interval `[starts_at, ends_at)`.

| Method | Description |
| --- | --- |
| `schedule(scope, startsAt, endsAt, reason='') : int` | Create a window (end must be strictly after start). |
| `isActive(scope, asOf=null): bool` | Whether a window covers the instant. |
| `activeWindow(scope, asOf=null): ?array` | The covering window (soonest-ending if overlapping). |
| `upcoming(scope, asOf=null): array` | Future windows, soonest first. |
| `cancel(id)` | Delete a window (no-op if absent). |
| `purgeEnded(asOf=null): int` | Drop windows that already ended. |

Key design points:

- **Half-open `[start, end)`**: start inclusive, end exclusive (consistent with the wave's range convention).
- **Overlap resolution**: `activeWindow` returns the soonest-ending covering window (`ORDER BY ends_at`).
- **Deterministic time**: `asOf` parameter for testable, reproducible queries.
- **PDO injection**; timestamps normalised via `strtotime` → `'Y-m-d H:i:s'`.

### Tests (`tests/Unit/Xion/MaintenanceWindowTest.php`)

15 unit tests (23 assertions): schedule returns id; isActive within / half-open boundaries (start incl, end excl, just-before) / scoped; activeWindow details / null / soonest-ending preference; upcoming ordering and exclusion of active+past; cancel + missing no-op; purgeEnded removes only finished windows; validation (end<start, end==start, empty scope).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 15 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
