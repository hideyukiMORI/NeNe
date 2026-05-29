# Field Trial 288 — QuietHours

**Date**: 2026-05-29
**Branch**: `feat/ft288-quiet-hours`
**Baseline**: post FT287 merge — **first helper scaffolded into `Nene\Kit` (ADR-0014)**

## Goal

Add `Nene\Kit\QuietHours` — per-user do-not-disturb time-of-day windows so a notification pipeline can defer non-urgent messages. Distinct from `MaintenanceWindow` (FT269, absolute service-scoped intervals): this is a recurring per-user time-of-day range.

## What was built

### `Nene\Kit\QuietHours` (`class/kit/QuietHours.php`)

| Method | Description |
| --- | --- |
| `set(userId, 'HH:MM', 'HH:MM', tz='UTC')` | Upsert the quiet window (idempotent per user). |
| `isQuiet(userId, 'HH:MM'): bool` | Whether a local time is inside the window. |
| `window(userId): ?array` | `{start, end, tz}` as clock strings. |
| `hasWindow / clear (userId)` | Exists / remove. |

Key design points:

- **Minutes-from-midnight, half-open `[start, end)`**: start inclusive, end exclusive.
- **Overnight wrap**: when `start > end` (e.g. 22:00–07:00) the window spans midnight (`t >= start || t < end`); `start == end` is "never quiet".
- **tz stored as metadata**; caller passes local wall-clock time.
- First class created with `composer make:kit` — landed in `Nene\Kit` with `use Nene\Xion\PdoConnection;`/`DbUpsert;` cross-namespace imports.

### Tests (`tests/Unit/Kit/QuietHoursTest.php`)

13 unit tests (28 assertions): no-window never quiet; same-day half-open boundaries; overnight wrap (incl. past-midnight + end-exclusive); zero-length never quiet; window clock strings; idempotent per user; user separation; clear (+ missing no-op); default tz UTC; malformed-time rejection (set start/end, isQuiet).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper; the make:kit scaffold produced correct namespace/imports. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
