# Field Trial 310 — Tournament

**Date**: 2026-05-29
**Branch**: `feat/ft310-tournament`
**Baseline**: post FT309 merge

## Goal

Add `Nene\Kit\Tournament` — single-elimination entrant tracking with match recording: register entrants, record match results (loser eliminated), and determine the champion. Distinct from `Leaderboard`/`ScoreBoard` (ranked scores): this is knockout progression.

## What was built

### `Nene\Kit\Tournament` (`class/kit/Tournament.php`)

Two tables: entrants (with eliminated flag) + matches. The caller pairs entrants per round (no auto-bracket → flexible for byes/seeding).

| Method | Description |
| --- | --- |
| `register(tournament, player)` | Add entrant (idempotent). |
| `recordMatch(tournament, round, a, b, winner): int` | Record; loser eliminated (atomic, guarded). |
| `entrants / remaining / isEliminated` | Read. |
| `champion(tournament): ?string` | Single survivor once ≥1 match played. |
| `matches(tournament, round=null)` | Match log. |

Key design points:

- **Knockout via elimination flag**: `recordMatch` validates both players are registered + active and the winner is one of them, then eliminates the loser (transactional).
- **Champion** = the one remaining entrant after matches begin; null while >1 remain.

### Tests (`tests/Unit/Kit/TournamentTest.php`)

11 unit tests (18 assertions): full 4-player bracket to champion, loser eliminated, registration order, idempotent register, cannot-match eliminated/unregistered, winner-must-be-a-player, same-player rejected, matches by round, tournament separation, zero-round rejected.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 11 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
