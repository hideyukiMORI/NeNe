# Field Trial 59 — Voting System

**Date**: 2026-05-27
**Branch**: `feat/ft59-voting-system`
**Baseline**: `b71f4b6` (post FT51–FT53 merge)
**NENE2 reference**: FT131 (votelog)

## Goal

Establish an upvote/downvote voting pattern for NeNe with toggle semantics, score retrieval, and per-user vote state.

## What was built

### `Nene\Xion\VotingBooth` (`class/xion/VotingBooth.php`)

| Method | Returns | Description |
|--------|---------|-------------|
| `cast(targetId, userId, direction)` | `{vote, score}` | Cast/toggle/switch vote. |
| `score(targetId)` | `{up, down, score}` | Vote counts and net score. |
| `userVote(targetId, userId)` | `string\|null` | Current user vote direction. |
| `validDirection(direction)` | `bool` | Static input validator. |

Key design points:

- **Toggle semantics**: same-direction re-vote DELETEs the row (vote removed); opposite-direction switches via UPDATE; new vote INSERTs.
- **Single entry point**: `cast()` handles all three state transitions — handler stays thin.
- **Score in cast response**: returns current score alongside the new vote state, avoiding a client round-trip for counter updates.
- **UNIQUE constraint**: `UNIQUE (target_id, user_id)` at DB level as a race-safe second layer.
- **`CHECK (direction IN ('up', 'down'))`**: DB-level guard against invalid values.
- **Namespaced `target_id`**: supports multiple votable types in one table (`post:42`, `comment:17`, etc.).
- **Auth note documented**: `cast()` does not enforce user identity — callers must bind `$userId` from JWT claims.

### Tests (`tests/Unit/Xion/VotingBoothTest.php`)

16 unit tests covering:

- cast new upvote/downvote, invalid direction throws
- toggle: same direction removes vote (up→up, down→down)
- switch: opposite direction changes vote (up→down, down→up)
- score: no votes, net calculation, isolation per target
- userVote: null before vote, direction after vote, null after toggle, isolation per user
- validDirection: accepts 'up'/'down', rejects empty/other/uppercase

### Howto (`docs/development/voting-booth.md`)

Toggle semantics table, schema, API table, basic usage, target ID convention, security note.

## Findings

### F-1 — No findings (clean trial)

The implementation required no NeNe-core changes. `VotingBooth` is self-contained. All 16 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
