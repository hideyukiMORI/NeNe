# Field Trial 61 — Leaderboard

**Date**: 2026-05-27
**Branch**: `feat/ft61-leaderboard`
**Baseline**: `b71f4b6` (post FT51–FT53 merge)
**NENE2 reference**: FT141 (ranklog)

## Goal

Establish a score-based leaderboard pattern for NeNe: best-score retention, ranked listing with tie handling, and personal rank lookup.

## What was built

### `Nene\Xion\Leaderboard` (`class/xion/Leaderboard.php`)

| Method | Returns | Description |
|--------|---------|-------------|
| `submit(leaderboardId, userId, score)` | `{is_best, score}` | Submit. Retained only if new personal best. |
| `rankings(leaderboardId, limit=10)` | `array[]` | Top entries, score DESC. Limit clamped 1–100. |
| `rank(leaderboardId, userId)` | `{rank, score}\|null` | Personal rank via `COUNT(score > ?)`. |
| `remove(leaderboardId, userId)` | `bool` | Remove score. |

Key design points:

- **Best-score retention**: SELECT + conditional INSERT/UPDATE pattern; `UNIQUE (leaderboard_id, user_id)` as DB-level guard.
- **`is_best` response**: `submit()` signals whether a new personal best was achieved — useful for achievement triggers.
- **Rank via `COUNT(*)`**: `SELECT COUNT(*) WHERE score > ?` + 1. Works on all DB versions without window functions.
- **Tied scores share rank**: users with identical scores get the same rank; the next rank skips appropriately.
- **Tie-breaking in `rankings()`**: `ORDER BY score DESC, submitted_at ASC` — earlier submission wins when scores are equal.
- **Limit clamping**: `max(1, min($limit, 100))` — prevents accidental full-table scans from large limit values.
- **Namespaced leaderboard IDs**: supports multiple leaderboards in one table (`game:tetris:daily`, `quiz:weekly`, etc.).

### Tests (`tests/Unit/Xion/LeaderboardTest.php`)

18 unit tests covering:

- submit: first score is best, higher score is best, lower score not best, same score not best, multiple users, isolation per leaderboard
- rankings: highest first, limit applied, limit clamped to max, tied score same rank, empty for no scores
- rank: returns position + score, null for unranked, first place is 1, tied users share rank
- remove: returns true when found, false when not found, deletes score

### Howto (`docs/development/leaderboard.md`)

Schema, API table, basic usage, best-score retention logic, tied scores, leaderboard ID convention, score type enforcement, limit clamping.

## Findings

### F-1 — Limit clamping ported from NENE2 FT141

NENE2 FT141 identified `?limit=99999` as a potential DoS vector. Applied proactively: `max(1, min($limit, 100))`.

### F-2 — No other findings

All 18 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
