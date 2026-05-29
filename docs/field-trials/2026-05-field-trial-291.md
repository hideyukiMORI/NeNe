# Field Trial 291 — Payout

**Date**: 2026-05-29
**Branch**: `feat/ft291-payout`
**Baseline**: post FT290 merge

## Goal

Add `Nene\Kit\Payout` — accrue integer-cent amounts owed to payees (sellers / affiliates / creators) and settle them in payout runs. The outbound marketplace counterpart to `PaymentRecord` (incoming) and `CreditLedger` (general).

## What was built

### `Nene\Kit\Payout` (`class/kit/Payout.php`)

| Method | Description |
| --- | --- |
| `accrue(payee, amountCents, reference=''): int` | Record a pending line item (amount > 0). |
| `pendingTotal / paidTotal (payee)` | Sum by status. |
| `pay(payee, asOf=null): int` | Settle all pending → paid; returns total settled. |
| `markFailed(id): bool` | Pending → failed (true only if it was pending). |
| `items(payee, status=null)` | List lines, newest first. |

Key design points:

- **Integer-cent line items** with `pending → paid | failed` status; `pay()` settles only pending lines (failed excluded) and returns the settled total.
- `markFailed`/`pay` guard on the prior status in the WHERE clause (no regressions).
- Append-only accrual; `asOf` for deterministic settlement time.

### Tests (`tests/Unit/Kit/PayoutTest.php`)

12 unit tests (19 assertions): accrue + pendingTotal, pay settles, pay-nothing → 0, accrue-after-pay fresh pending, markFailed excludes from pending, markFailed only on pending, pay skips failed, items all/filtered, payee separation, validation (non-positive, empty payee, unknown status).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
