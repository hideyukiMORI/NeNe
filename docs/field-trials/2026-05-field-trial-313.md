# Field Trial 313 — ShiftRoster

**Date**: 2026-05-29
**Branch**: `feat/ft313-shiftroster`
**Baseline**: post FT312 merge

## Goal

Add `Nene\Kit\ShiftRoster` — staff shift scheduling: define named shifts on a day (e.g. 2026-06-01 "morning") with a required headcount, assign workers, and track coverage (assigned vs. required). Distinct from `TimeSlot` (customer appointment booking) and `AccessSchedule` (time-window access control).

## What was built

### `Nene\Kit\ShiftRoster` (`class/kit/ShiftRoster.php`)

Two tables: `roster_shifts` (date + name + required) and `roster_assignments`.

| Method | Description |
| --- | --- |
| `defineShift(date, name, required=1): int` | Idempotent on (date, name); updates headcount. |
| `assign(date, name, worker): bool` | Assign to a defined shift (idempotent). |
| `unassign(date, name, worker): bool` | Remove. |
| `assignees(date, name): array` | Workers, in assignment order. |
| `isCovered / coverage` | Coverage (required/assigned/short). |
| `shiftsFor(worker, date): array` | A worker's shifts that day. |

Key design points:

- **`defineShift` uses `DbUpsert::run`** (cross-driver) for the (date, name) unique key, then reads back the id so it works on both INSERT and update.
- **`assign` uses `ON CONFLICT DO NOTHING`** for idempotent assignment; returns whether a row was newly added (`rowCount`).
- **Undefined-shift reads are safe** (coverage→null, isCovered→false, assignees→[]); only writes (`assign`/`unassign`) require a defined shift.

## Findings

### F-1 — DbUpsert lives in `Nene\Xion`, not `Nene\Kit`

The scaffold imports only `Nene\Xion\PdoConnection`. Referencing `DbUpsert` without an import resolved to `Nene\Kit\DbUpsert` (undeclared) — caught immediately by Phan (`PhanUndeclaredClassMethod`) and the failing tests. Added `use Nene\Xion\DbUpsert;`. A reminder that `Nene\Kit` helpers reaching for a core utility must import it from `Nene\Xion`.

## Decision

Merge as-is. No follow-up Issues raised.
