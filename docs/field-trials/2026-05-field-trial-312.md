# Field Trial 312 — PledgeDrive

**Date**: 2026-05-29
**Branch**: `feat/ft312-pledgedrive`
**Baseline**: post FT311 merge

## Goal

Add `Nene\Kit\PledgeDrive` — a crowdfunding / fundraising drive: a campaign with a monetary goal (integer cents) and optional deadline, plus pledges toward it. Report total raised, progress toward goal, distinct backers, top backers, and whether the goal is reached. Distinct from `Petition` (signature count toward a goal — no money) and `Payout`/`CreditLedger` (accounting primitives).

## What was built

### `Nene\Kit\PledgeDrive` (`class/kit/PledgeDrive.php`)

Two tables: `pledge_drives` (goal + deadline) and `pledge_drive_pledges`.

| Method | Description |
| --- | --- |
| `createDrive(name, goalCents, deadline=null): int` | Create a drive (goal > 0). |
| `pledge(driveId, backer, amountCents): int` | Record a pledge (drive must exist; amount > 0). |
| `raised(driveId): int` | Total pledged (cents). |
| `progress(driveId): float` | raised/goal, capped at 1.0. |
| `goalReached(driveId): bool` | raised >= goal. |
| `remaining(driveId): int` | Cents still needed (0 once reached). |
| `backerCount(driveId): int` | Distinct backers. |
| `topBackers(driveId, limit=10): array` | Backers by summed pledge, descending. |

Key design points:

- **Integer cents** throughout; goal and pledge amounts must be positive.
- **Unknown-drive reads are safe**: `raised`/`progress`/`goalReached`/`remaining` return zero-ish values rather than throwing; only `pledge()` rejects an unknown drive (writes must be valid).
- `topBackers` groups by backer with a deterministic `total DESC, backer ASC` tiebreak.

### Tests (`tests/Unit/Kit/PledgeDriveTest.php`)

13 tests (27 assertions): raised + goalReached, exact-goal boundary, progress cap at 1.0, remaining, distinct backer count, topBackers sum/order/limit, drive separation, safe unknown-drive reads, and four validation guards.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
