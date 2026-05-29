# Field Trial 284 — FunnelStep

**Date**: 2026-05-29
**Branch**: `feat/ft284-funnel-step`
**Baseline**: post FT283 merge

## Goal

Add `Nene\Xion\FunnelStep` — conversion-funnel step completion tracking so drop-off and step-to-step conversion can be computed (signup → verify → onboard → activate).

## What was built

### `Nene\Xion\FunnelStep` (`class/xion/FunnelStep.php`)

| Method | Description |
| --- | --- |
| `reach(funnel, subject, step, order=0, asOf=null)` | Record reaching a step (idempotent). |
| `hasReached(funnel, subject, step)` | Membership. |
| `reachedSteps(funnel, subject)` | Steps reached, in funnel order. |
| `counts(funnel)` | Distinct-subject count per step (chart data). |
| `conversionRate(funnel, fromStep, toStep): float` | Of those reaching from, fraction reaching to. |
| `purgeOlderThan(days, asOf=null)` | Housekeeping. |

Key design points:

- **Idempotent per (funnel, subject, step)** so re-reaching never double-counts.
- **`conversionRate`** = `|F ∩ T| / |F|` via a subquery; 0.0 when the from-step has no subjects (no divide-by-zero).
- **`counts` uses `COUNT(DISTINCT subject)`** ordered by step order for direct funnel-chart rendering.
- **PDO injection**; `asOf` for deterministic time.

### Tests (`tests/Unit/Xion/FunnelStepTest.php`)

11 unit tests (18 assertions): reach + hasReached, idempotent, reachedSteps ordering, counts distinct-per-step, conversionRate 0.5 / full 1.0 / zero (no to) / zero (no from), funnel separation, purgeOlderThan, validation (empty funnel/step).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 11 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
