# Field Trial 293 — ReportSchedule

**Date**: 2026-05-29
**Branch**: `feat/ft293-report-schedule`
**Baseline**: post FT292 merge

## Goal

Add `Nene\Kit\ReportSchedule` — recurring report definitions (recipients, format, fixed-interval cadence) with a next-run clock for a report-generation cron. Distinct from `ScheduledTask` (FT174, generic last-run registry): this carries report config and an interval-advancing next-run.

## What was built

### `Nene\Kit\ReportSchedule` (`class/kit/ReportSchedule.php`)

| Method | Description |
| --- | --- |
| `schedule(name, intervalDays, recipients=[], format='csv', firstRun=null)` | Upsert; resets cadence + re-activates. |
| `due(asOf=null)` | Active reports with next_run ≤ now, soonest first. |
| `markGenerated(name)` | Advance next_run by interval (cadence-preserving). |
| `get(name) / pause / resume / remove` | Read / toggle / delete. |

Key design points:

- **Cadence-preserving advance**: `markGenerated` adds the interval to the *previous* next_run (not "now"), so a fixed schedule never drifts.
- `due` is `active = 1 AND next_run <= asOf`; pause excludes without deleting; recipients stored as JSON.

### Tests (`tests/Unit/Kit/ReportScheduleTest.php`)

13 unit tests (25 assertions): schedule/get, due boundary (before/at/after), markGenerated +interval, cadence-preserving over two runs, due soonest-first, pause excludes, resume, idempotent re-schedule re-activates, remove, unknown get null, markGenerated-unknown throws, validation (zero interval, empty name).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
