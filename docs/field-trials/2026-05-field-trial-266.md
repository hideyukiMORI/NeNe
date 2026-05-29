# Field Trial 266 — BusinessCalendar

**Date**: 2026-05-29
**Branch**: `feat/ft266-business-calendar`
**Baseline**: post FT265 merge

## Goal

Add `Nene\Xion\BusinessCalendar` — a working-day calendar that excludes weekends and registered holidays, for SLA deadlines, due-date arithmetic, and "deliver in N working days" scheduling. Complements `SlaTracker` (FT237), whose deadline computation currently has no notion of business days.

## What was built

### `Nene\Xion\BusinessCalendar` (`class/xion/BusinessCalendar.php`)

Multiple independent calendars coexist, keyed by `calKey` (e.g. one per country/office), so a single deployment serves regions with different public holidays. "Business day" = Mon–Fri that is not a registered holiday.

| Method | Description |
| --- | --- |
| `addHoliday(calKey, date, label='')` | Register/relabel a holiday (idempotent per date, cross-driver upsert). |
| `removeHoliday(calKey, date)` | Remove a holiday (no-op if absent). |
| `isHoliday(calKey, date): bool` | Registered-holiday check (ignores weekends). |
| `holidays(calKey, from=null, to=null): array` | List holidays in half-open range `[from, to)`. |
| `isBusinessDay(calKey, date): bool` | Mon–Fri and not a holiday. |
| `nextBusinessDay` / `previousBusinessDay(calKey, date): string` | Strictly after / before. |
| `addBusinessDays(calKey, date, days): string` | Move ±N working days (skips weekends + holidays). |
| `businessDaysBetween(calKey, from, to): int` | Count working days in `[from, to)`. |

Key design points:

- **Half-open ranges**: `businessDaysBetween` and `holidays` use `[from, to)` (end exclusive) so adjacent ranges compose without double-counting — the common off-by-one trap for date ranges.
- **Round-trip date validation**: `toDate()` rejects malformed input (`2026-13-40`) and silent overflow (`2026-02-30` → Mar 2) by requiring `createFromFormat('!Y-m-d', …)->format('Y-m-d')` to match the input — no reliance on `getLastErrors()` (which also avoids a `PhanTypeComparisonFromArray` on its `array|false` return).
- **Weekend = ISO `N` >= 6** (Sat/Sun); non-Sat/Sun weekends are explicitly out of scope until a real need appears.
- **Cross-driver upsert** via `DbUpsert::run()`; **PDO injection** via `?PDO` constructor.

### Tests (`tests/Unit/Xion/BusinessCalendarTest.php`)

24 unit tests (34 assertions) over a fixed, fully day-of-week-annotated reference week (two holidays: 元日 Thu 2026-01-01, 成人の日 Mon 2026-01-12):

- holiday register/relabel/remove/scoping; half-open `holidays()` range
- `isBusinessDay` for weekday / weekend / holiday
- `addBusinessDays` forward over weekend+holiday, single-day over weekend, negative, and zero (returns input unchanged even on a holiday)
- `nextBusinessDay` from a holiday and strictly-after semantics; `previousBusinessDay`
- `businessDaysBetween` end-exclusivity, spanning a holiday, empty (start==end), and reversed (start>end → 0)
- validation: empty calendar key, malformed date; unknown calendar still applies weekends

## Findings

### F-1 — No finding (clean trial)

`BusinessCalendar` is a clean `Nene\Xion` helper. 24 tests pass; CS Fixer and Phan clean. The FT265 scaffold-template fix (PR #719) held — generated stubs were namespace- and import-clean with no manual correction needed.

## Decision

Merge as-is. No follow-up Issues raised.
