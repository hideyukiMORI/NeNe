# Field Trial 311 — Dispute

**Date**: 2026-05-29
**Branch**: `feat/ft311-dispute`
**Baseline**: post FT310 merge

## Goal

Add `Nene\Kit\Dispute` — a transaction dispute / chargeback workflow: open a money-bearing case, move it through review, attach evidence, and resolve it won/lost, with a running total of the amount still at risk. Distinct from `ContentReport`/`ContentFlag` (moderation queues): this is financial, with a guarded state machine.

## What was built

### `Nene\Kit\Dispute` (`class/kit/Dispute.php`)

| Method | Description |
| --- | --- |
| `open(reference, reason, amountCents): int` | Open a dispute (status `open`). |
| `review(id): bool` | `open` → `under_review` (guarded). |
| `addEvidence(id, text): bool` | Append a line; only while unresolved (transactional read-modify-write). |
| `resolve(id, won): bool` | `open`/`under_review` → `won`/`lost`; stamps `resolved_at`. |
| `status / get / byStatus` | Read. |
| `amountAtRisk(): int` | Sum of `open` + `under_review` amounts (cents). |

Key design points:

- **Guarded transitions**: each state change is `UPDATE … WHERE id = ? AND status IN (…)`, returning `rowCount() > 0`, so illegal/double transitions are no-ops returning false.
- **Evidence append** is wrapped in a transaction (reused if already in one) to avoid lost updates.
- **Integer cents** throughout; negative amounts rejected at `open`.

### Tests (`tests/Unit/Kit/DisputeTest.php`)

13 tests (30 assertions): open defaults, full lifecycle, direct resolve-from-open, review-only-from-open, no double resolve, evidence append + newline join, no evidence after resolve, amount-at-risk math across statuses, byStatus filtering, missing-id nulls, and three validation guards.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
