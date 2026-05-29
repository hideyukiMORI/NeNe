# Field Trial 275 — RetrySchedule

**Date**: 2026-05-29
**Branch**: `feat/ft275-retry-schedule`
**Baseline**: post FT274 merge

## Goal

Add `Nene\Xion\RetrySchedule` — exponential-backoff retry tracking for arbitrary named operations (webhooks, syncs, imports), independent of how the work executes. Hands off to `DeadLetterQueue` (FT274) on exhaustion.

## What was built

### `Nene\Xion\RetrySchedule` (`class/xion/RetrySchedule.php`)

Next delay = `baseSeconds * 2^(attempts-1)`, exponent capped at 16.

| Method | Description |
| --- | --- |
| `arm(ref, baseSeconds=60, maxAttempts=5, asOf=null)` | Register/reset; due now. |
| `backoff(ref, asOf=null): ?string` | Record failure; schedule next (null when exhausted). |
| `due(asOf=null): array` | Operations ready to retry now, soonest first. |
| `attempts / nextAttemptAt / isExhausted (ref)` | State accessors. |
| `clear(ref)` | Success / abandon. |

Key design points:

- **Exponential backoff with cap** to avoid overflow / absurd delays.
- **Exhaustion is terminal**: at `attempts >= maxAttempts` the row is flagged `exhausted` and excluded from `due()`.
- **`due()` boundary inclusive** (`next_attempt_at <= asOf`); `asOf` makes the whole schedule deterministic in tests.
- **Cross-driver upsert** (arm); **PDO injection**.

### Tests (`tests/Unit/Xion/RetryScheduleTest.php`)

13 unit tests (27 assertions): arm due-now; **exponential sequence 60→120→240s** asserted exactly; exhaustion at max; exhausted excluded from due; due time boundary (too-early vs exactly-due); soonest-first ordering; arm resets exhausted state; clear (+ missing no-op); backoff-on-unarmed throws; validation (empty ref, zero base, zero max).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
