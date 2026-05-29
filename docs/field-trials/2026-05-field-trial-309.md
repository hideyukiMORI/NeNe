# Field Trial 309 — Kudos

**Date**: 2026-05-29
**Branch**: `feat/ft309-kudos`
**Baseline**: post FT308 merge

## Goal

Add `Nene\Kit\Kudos` — peer recognition / shout-outs between users, with optional message + category, received/given counts, and a recipient leaderboard. Distinct from `Endorsement` (FT285, skill endorsements) and `Reaction` (emoji on content).

## What was built

### `Nene\Kit\Kudos` (`class/kit/Kudos.php`)

| Method | Description |
| --- | --- |
| `give(fromUser, toUser, message='', category=''): int` | Give kudos (no self-kudos). |
| `receivedCount / givenCount` | Counts. |
| `received(toUser, limit=null)` | Recent received (newest first). |
| `countByCategory(toUser) / topRecipients(limit=10)` | Breakdown / leaderboard. |
| `remove(id)` | Delete. |

Key design points:

- **Self-kudos disallowed** (from == to throws); append-only; category trimmed.
- `topRecipients` groups by recipient ordered by count desc.

### Tests (`tests/Unit/Kit/KudosTest.php`)

9 unit tests (16 assertions): give + counts, received newest-first, received limit, countByCategory, topRecipients ranking, remove (+ missing no-op), category trimmed, self-kudos rejected.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 9 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
