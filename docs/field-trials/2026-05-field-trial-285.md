# Field Trial 285 — Endorsement

**Date**: 2026-05-29
**Branch**: `feat/ft285-endorsement`
**Baseline**: post FT284 merge

## Goal

Add `Nene\Xion\Endorsement` — peer skill endorsements between users (LinkedIn-style), with per-skill counts and top-skills ranking.

## What was built

### `Nene\Xion\Endorsement` (`class/xion/Endorsement.php`)

| Method | Description |
| --- | --- |
| `endorse(subjectUser, skill, endorser): bool` | Record (idempotent; true only when new). |
| `hasEndorsed(subjectUser, skill, endorser)` | Membership. |
| `count(subjectUser, skill) / endorsers(...)` | Count / endorser ids. |
| `topSkills(subjectUser, limit=null)` | Skills by endorsement count, desc. |
| `withdraw(subjectUser, skill, endorser)` | Take back. |

Key design points:

- **One per (subject, skill, endorser)** via UNIQUE; re-endorse returns false, withdraw-then-re-endorse works.
- **Self-endorsement rejected** (subject == endorser throws).
- **topSkills** ranks by `COUNT(*)` desc for profile display.
- **PDO injection**.

### Tests (`tests/Unit/Xion/EndorsementTest.php`)

12 unit tests (21 assertions): endorse + count, idempotent, hasEndorsed, endorsers list, topSkills ordering + limit, withdraw (+ missing no-op), withdraw-then-re-endorse, subject/skill scoping, **self-endorsement rejected**, empty-skill rejected.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
