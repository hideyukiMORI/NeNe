# Field Trial 302 — DailyReward

**Date**: 2026-05-29
**Branch**: `feat/ft302-daily-reward`
**Baseline**: post FT301 merge

## Goal

Add `Nene\Kit\DailyReward` — once-per-day claimable reward (daily login bonus) with a consecutive-day claim streak. Distinct from `DailyStreak` (FT255, counts activity days) and `PointBalance` (FT213, ledger): this records the claim grant with one-per-day enforcement.

## What was built

### `Nene\Kit\DailyReward` (`class/kit/DailyReward.php`)

| Method | Description |
| --- | --- |
| `claim(userId, reward, asOf=null): bool` | Claim today (true if newly claimed). |
| `claimedToday / canClaim (userId, asOf=null)` | Today's state. |
| `lastClaim(userId) / totalClaimed(userId)` | Last date / sum. |
| `claimStreak(userId, asOf=null): int` | Consecutive claim days. |

Key design points:

- **One per calendar day** via `UNIQUE (user_id, claim_date)`; a second claim the same day returns false.
- **Streak counts through yesterday** when today is unclaimed (so a not-yet-claimed today doesn't zero the streak), and breaks on a gap.
- `asOf` ('Y-m-d') makes day logic deterministic.

### Tests (`tests/Unit/Kit/DailyRewardTest.php`)

11 unit tests (18 assertions): claim once-per-day, claimedToday/canClaim, totalClaimed sum, lastClaim, streak consecutive, streak breaks on gap, **streak counts through yesterday when today unclaimed**, streak 0 when gap before yesterday, user separation, negative-reward rejected, zero reward allowed.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 11 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
