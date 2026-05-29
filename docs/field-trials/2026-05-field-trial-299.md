# Field Trial 299 — Petition

**Date**: 2026-05-29
**Branch**: `feat/ft299-petition`
**Baseline**: post FT298 merge

## Goal

Add `Nene\Kit\Petition` — a signature campaign toward a goal: people sign once each, progress tracks toward a target, and the petition can be closed. Distinct from `DocumentSignature` (FT248, e-signature approval workflow) and `VotePoll` (FT239, multi-option voting).

## What was built

### `Nene\Kit\Petition` (`class/kit/Petition.php`)

Two tables: petition definition (goal, closed) + signatures (unique per signer).

| Method | Description |
| --- | --- |
| `create(name, goal) / close(name) / isClosed(name)` | Lifecycle. |
| `sign(name, signer, comment=''): bool` | Sign once (guards unknown/closed). |
| `hasSigned / signatureCount / goalReached` | Membership / counts. |
| `progress(name): ?array` | `{count, goal, reached, pct}`. |
| `signatures(name, limit=null)` | Recent signatures. |

Key design points:

- **One signature per `(petition, signer)`**; re-sign returns false (no double-count); `sign` throws for unknown or closed petitions.
- **`progress.pct` capped at 1.0**; `goalReached` is `count >= goal` (inclusive).
- Re-`create` re-opens with a new goal.

### Tests (`tests/Unit/Kit/PetitionTest.php`)

13 unit tests (29 assertions): create/sign/count, idempotent per signer, hasSigned, goalReached at count==goal, progress, pct caps at 1.0, unknown→null, close stops signing, re-create reopens, signatures newest-first, petition separation, sign-unknown throws, zero-goal rejected.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
