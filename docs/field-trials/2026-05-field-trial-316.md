# Field Trial 316 — Escalation

**Date**: 2026-05-29
**Branch**: `feat/ft316-escalation`
**Baseline**: post FT315 merge

## Goal

Add `Nene\Kit\Escalation` — a tiered escalation ladder for a work item (ticket, incident, alert): drive a reference up ordered levels (L1 → L2 → … → Lmax) and resolve it. Distinct from `IncidentLog` (records incidents with severity/lifecycle) and `SlaTracker` (deadline-breach detection): this is the who-handles-it-next tier-progression state machine.

## What was built

### `Nene\Kit\Escalation` (`class/kit/Escalation.php`)

| Method | Description |
| --- | --- |
| `open(reference, maxLevel=3): int` | Open a case at L1. |
| `escalate(reference): bool` | +1 level if open and below ceiling. |
| `resolve(reference): bool` | Close an open case. |
| `level / isResolved / atMaxLevel` | Per-case reads. |
| `activeCases(): array` | Open cases, most-escalated first. |
| `countAtLevel(level): int` | Open cases at a level. |

Key design points:

- **Guarded ladder**: `escalate()` is one `UPDATE … SET level = level + 1 WHERE status = 'open' AND level < max_level`, so it never exceeds the ceiling and is a no-op on a resolved/unknown case (`rowCount() > 0` reports whether it advanced).
- **`resolve()`** likewise guards on `status = 'open'`, so double-resolve returns false.
- **Unknown-reference reads are safe** (null / false); `open()` rejects a duplicate reference (UNIQUE).

### Tests (`tests/Unit/Kit/EscalationTest.php`)

11 tests (34 assertions): open at L1, full ladder to ceiling + cap, resolve stops escalation, no double-resolve, single-level ladder immediately at max, activeCases ordering + exclusion of resolved, countAtLevel across escalate/resolve, safe unknown reads, duplicate-open throw, and two validation guards.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 11 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised. **This closes the FT307–FT316 batch-5 wave (50 trials in the Nene\Kit run).**
