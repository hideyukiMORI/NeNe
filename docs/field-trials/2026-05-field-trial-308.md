# Field Trial 308 — ExpenseClaim

**Date**: 2026-05-29
**Branch**: `feat/ft308-expense-claim`
**Baseline**: post FT307 merge

## Goal

Add `Nene\Kit\ExpenseClaim` — employee expense reimbursement claims with integer-cent line items and an approval lifecycle. Distinct from `Payout` (FT291), `BudgetTracker`, and `CreditNote`.

## What was built

### `Nene\Kit\ExpenseClaim` (`class/kit/ExpenseClaim.php`)

Two tables: claims + line items. Lifecycle `draft → submitted → approved | rejected`; `approved → paid`.

| Method | Description |
| --- | --- |
| `create(claimant): int` | Open a draft. |
| `addItem(claimId, description, amountCents)` | Add a line (draft only). |
| `total(claimId) / items(claimId)` | Sum / lines. |
| `submit / approve / reject / markPaid (claimId): bool` | Guarded transitions. |
| `status / claimsFor(claimant, status=null)` | Read (with totals). |

Key design points:

- **Status-guarded transitions** (`UPDATE … WHERE status = <from>`); items only addable while draft; submit requires ≥1 item.
- **Integer-cent** totals computed from line items (no stored/denormalised total → no drift).

### Tests (`tests/Unit/Kit/ExpenseClaimTest.php`)

12 unit tests (26 assertions): create/add/total, full lifecycle (submit→approve→paid), reject, no-add-after-submit, empty-submit throws, transition guards (approve-before-submit, paid-before-approve), no double-approve, claimsFor with totals + by-status, claimant separation, unknown null, validation (non-positive amount, empty claimant).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
