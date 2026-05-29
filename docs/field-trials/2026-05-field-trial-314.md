# Field Trial 314 — SpaceOccupancy

**Date**: 2026-05-29
**Branch**: `feat/ft314-spaceoccupancy`
**Baseline**: post FT313 merge

## Goal

Add `Nene\Kit\SpaceOccupancy` — a live, capacity-limited headcount for a physical space (room, gym, parking lot, venue): admit occupants only while room remains, release them, and record a high-water peak.

## Pivot — concept-duplicate avoided (the FT281 discipline)

This slot was originally scoped as **PunchCard** (clock in/out). The pre-work concept scan (`grep -i class/kit/INDEX.md`) surfaced `TimeEntry` — *"work time tracking with start/stop/duration"* — which already covers clock-in/clock-out and total worked time, including active timers and manual entries. PunchCard would have been a near-duplicate (cf. the FT281 TermsAcceptance ≈ TermConsent lesson). Pivoted to **SpaceOccupancy**, which has no existing analogue: `PresenceChannel` tracks *which identities* are in a channel (no capacity ceiling), `EventTicket` is pre-sold tickets with check-in. SpaceOccupancy is an anonymous, capacity-enforced live counter.

## What was built

### `Nene\Kit\SpaceOccupancy` (`class/kit/SpaceOccupancy.php`)

| Method | Description |
| --- | --- |
| `defineSpace(space, capacity): int` | Idempotent; updates capacity, preserves count/peak. |
| `enter(space, count=1): bool` | All-or-nothing admission; false if it would overflow. |
| `leave(space, count=1): bool` | Release (floored at 0). |
| `reset(space): bool` | Zero the live count (peak retained). |
| `current / available / isFull / peak` | Reads. |

Key design points:

- **Atomic, overshoot-proof admission**: `enter()` is a single guarded `UPDATE … WHERE current + :n <= capacity`, with `peak` advanced via an inline `CASE` in the same statement — no read-modify-write race, capacity can never be exceeded even under concurrency.
- **All-or-nothing**: a multi-person `enter` that would partially fit is rejected entirely.
- **Unknown-space reads are safe** (zeros / false); only `enter()` rejects an unknown space.

### Tests (`tests/Unit/Kit/SpaceOccupancyTest.php`)

12 tests (36 assertions): fill to capacity, partial-overflow rejection + exact fill, leave/available, leave floors at zero, peak high-water across enter/leave/enter, reset keeps peak, capacity update keeps count + one row, space separation, safe unknown reads, and three validation guards.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean. (`DbUpsert` imported from `Nene\Xion` per the FT313 finding.)

## Decision

Merge as-is. No follow-up Issues raised.
