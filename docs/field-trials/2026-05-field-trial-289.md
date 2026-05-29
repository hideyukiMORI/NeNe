# Field Trial 289 — Snooze

**Date**: 2026-05-29
**Branch**: `feat/ft289-snooze`
**Baseline**: post FT288 merge

## Goal

Add `Nene\Kit\Snooze` — temporarily hide an existing item (notification, task, ticket) from an owner's active view until a wake time, after which it resurfaces. Distinct from `Reminder` (FT184, which creates a *new* future reminder): Snooze defers an *existing* item.

## What was built

### `Nene\Kit\Snooze` (`class/kit/Snooze.php`)

| Method | Description |
| --- | --- |
| `snooze(owner, item, until)` | Hide until a wake time (idempotent; re-snooze replaces). |
| `isSnoozed(owner, item, asOf=null)` | Still hidden (wake in the future)? |
| `wakeAt(owner, item): ?string` | The wake time, or null. |
| `snoozed(owner, asOf=null)` | Still-hidden items, soonest wake first. |
| `due(owner, asOf=null)` | Woken items (wake passed), ready to resurface. |
| `unsnooze(owner, item) / clearWoken(owner, asOf=null): int` | Cancel / cleanup. |

Key design points:

- **`snoozed` + `due` partition** an owner's rows at a given instant (`wake_at > now` vs `<= now`).
- **Boundary**: at exactly `wake_at` the item is *due*, not snoozed (`isSnoozed` uses `>`).
- One snooze per `(owner, item)` (cross-driver upsert); `asOf` for deterministic time.

### Tests (`tests/Unit/Kit/SnoozeTest.php`)

12 unit tests (23 assertions): snooze/isSnoozed boundary (before/at/after wake), wakeAt, re-snooze replaces, snoozed soonest-first, due woken items, snoozed+due partition, unsnooze (+ missing no-op), clearWoken removes only woken, owner separation, validation (empty owner, bad time).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
