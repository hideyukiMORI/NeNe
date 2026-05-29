# Field Trial 303 — Achievement

**Date**: 2026-05-29
**Branch**: `feat/ft303-achievement`
**Baseline**: post FT302 merge

## Goal

Add `Nene\Kit\Achievement` — progress-tracked, auto-unlocking achievements per user ("read 10 articles"). Distinct from `ProfileBadge` (FT257, one-shot badge award): this models *progress toward* an unlock.

## What was built

### `Nene\Kit\Achievement` (`class/kit/Achievement.php`)

Two tables: definitions (code, target) + per-user progress.

| Method | Description |
| --- | --- |
| `define(code, name, target)` | Define an achievement (target ≥ 1). |
| `advance(userId, code, by=1, asOf=null): bool` | Add progress; true only on the unlocking call. |
| `progress / isUnlocked / progressPct` | Per-user state. |
| `unlockedFor(userId)` | Codes unlocked. |

Key design points:

- **Auto-unlock at target**; `advance` returns true *only* on the call that crosses into unlocked (idempotent thereafter) — a clean hook for "achievement unlocked!" notifications.
- **Progress capped at target**; atomic read-modify-write in a transaction.
- `progressPct` capped at 1.0; re-`define` changes the target.

### Tests (`tests/Unit/Kit/AchievementTest.php`)

11 unit tests (21 assertions): unlock at target, true-only-on-unlocking-call, progress caps at target, progressPct, unlockedFor, unknown progress 0, user separation, idempotent define (raises target), advance-undefined throws, zero-increment rejected, zero-target rejected.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 11 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
