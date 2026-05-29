# Field Trial 265 — SequenceNumber

**Date**: 2026-05-29
**Branch**: `feat/ft265-sequence-number`
**Baseline**: post FT264 merge

## Goal

Add `Nene\Xion\SequenceNumber` — gapless, per-scope sequential numbering for human-facing identifiers (invoice / order / ticket numbers) that must be sequential and unique rather than random tokens or raw auto-increment primary keys.

## What was built

### `Nene\Xion\SequenceNumber` (`class/xion/SequenceNumber.php`)

A small helper that hands out monotonically increasing integers per named `scope` using an atomic row-level increment. Independent of any table's row lifecycle (deleting rows never burns numbers) and supports many independent sequences side by side.

| Method | Description |
| --- | --- |
| `next(scope): int` | Atomically consume and return the next value (first call returns 1). |
| `formatted(scope, prefix='', pad=6): string` | `next()` then prefix + zero-padded, e.g. `'INV-000042'`. |
| `peek(scope): int` | Current value without consuming (0 if scope never used). |
| `reset(scope, to=0): void` | Reset the counter; next `next()` returns `to + 1`. |

Key design points:

- **Atomic increment**: `next()` runs the upsert (`current_value = current_value + 1`) and the read inside one transaction; the row-level write lock serialises concurrent callers so each gets a distinct value with no gaps.
- **Transaction reuse**: if a transaction is already open, the existing one is reused rather than nested (`inTransaction()` guard); the helper only begins/commits/rolls back the transaction it owns.
- **Cross-driver upsert**: uses `DbUpsert::run()` with `updateExprs` (raw `current_value + 1`) — works identically on SQLite (`ON CONFLICT … DO UPDATE`) and MySQL (`ON DUPLICATE KEY UPDATE`).
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/SequenceNumberTest.php`)

20 unit tests (83 assertions) covering:

- `next()` first value is 1, increments sequentially, gapless over 50 calls
- independent scopes advance independently
- scope is trimmed; empty scope throws
- `next()` reuses an already-open transaction
- `formatted()` prefix + default/custom padding; no truncation when value exceeds width; consumes the number; `pad < 1` throws
- `peek()` returns 0 for unknown scope and does not consume
- `reset()` to zero restarts at 1, to N continues at N+1, creates a missing scope; negative value and empty scope throw

## Findings

### F-1 — process-gap: `make:xion` scaffold emits a wrong `PdoConnection` import

**Kind**: process-gap
**Severity**: low
**Decision**: fix-in-framework (separate PR)

The `composer make:xion` template (`tools/make-xion.php`) inserts `use Nene\Database\PdoConnection;`, but `PdoConnection` lives in `Nene\Xion` (`class/xion/PdoConnection.php`). The bad import is latent: unit tests inject a PDO so `getInstance()` is never reached, but a production call with `$db === null` would fatal, and full Phan flags `PhanUndeclaredClassMethod`. Removed the import in this class to match the convention used by every other Xion class (bare `PdoConnection::getInstance()`, same namespace). Scaffold template fix tracked as a follow-up.

## Decision

Merge as-is. Follow-up: correct the `make:xion` scaffold import (see F-1).
