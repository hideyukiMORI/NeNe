# Field Trial 67 — Point / Loyalty System

**Date**: 2026-05-27
**Branch**: `feat/ft67-point-ledger`
**Baseline**: post FT66 merge

## Goal

Establish an append-only point / loyalty system pattern for NeNe applications. Provide earn/spend/balance/history operations with negative-balance prevention and a full audit trail.

## What was built

### `Nene\Xion\PointLedger` (`class/xion/PointLedger.php`)

Append-only DB-backed point ledger providing:

| Method | Description |
| --- | --- |
| `earn(string $userId, int $points, string $description = ''): int` | Add points. Returns ledger entry ID. |
| `spend(string $userId, int $points, string $description = ''): bool` | Deduct points atomically. False if insufficient. |
| `balance(string $userId): int` | Current balance (`SUM(delta)`). |
| `history(string $userId, int $limit = 20): array` | Recent entries, newest first, limit 1–100. |

Key design points:

- **Append-only**: no UPDATE or DELETE — full audit trail.
- **`delta` sign**: positive for earn, negative for spend; `COALESCE(SUM(delta), 0)` = balance.
- **Negative-balance prevention**: `spend()` wraps balance check + INSERT in a transaction.
- **Validation**: `earn()` and `spend()` throw `InvalidArgumentException` for non-positive amounts.
- **`history()` limit**: clamped to 1–100.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/PointLedgerTest.php`)

21 unit tests covering:

- earn returns id
- earn increases balance
- earn accumulates
- earn with description
- earn zero points throws
- earn negative points throws
- spend returns true on success
- spend decreases balance
- spend returns false when insufficient balance
- spend does not alter balance when insufficient
- spend exact balance
- spend zero points throws
- spend negative points throws
- balance is zero for new user
- balance is user-isolated
- history returns entries newest first
- history returns empty for new user
- history shows positive delta for earn
- history shows negative delta for spend
- history limit clamps
- history is user-isolated

### Howto (`docs/development/point-ledger.md`)

Covers: schema, API table, usage examples, negative-balance prevention mechanism, append-only design rationale, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`PointLedger` is a clean `Nene\Xion` helper. 21 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
