# Field Trial 55 — Distributed Lock

**Date**: 2026-05-27
**Branch**: `feat/ft55-distributed-lock`
**Baseline**: `b71f4b6` (post FT51–FT53 merge)

## Goal

Establish a DB-backed distributed lock pattern for NeNe. Enables mutual exclusion across multiple processes or instances without an external Redis dependency — suitable for scheduled jobs, batch deduplication, and single-writer enforcement.

## What was built

### `Nene\Xion\DistributedLock` (`class/xion/DistributedLock.php`)

| Method | Returns | Description |
|--------|---------|-------------|
| `acquire(resource, owner, ttlSeconds=30)` | `bool` | Try to acquire the lock. Reclaims stale (expired) locks. |
| `release(resource, owner)` | `bool` | Release. Returns `false` if not owned by `$owner`. |
| `renew(resource, owner, ttlSeconds=30)` | `bool` | Extend TTL. Returns `false` if expired, not found, or wrong owner. |
| `isHeld(resource)` | `bool` | Check whether any unexpired lock exists. |

Key design points:

- **Stale-lock reclaim**: expired lock claimed by the next caller automatically — no manual cleanup.
- **Same-owner idempotency**: re-acquiring an active lock with the same owner updates the TTL (safe for retry).
- **INSERT OR IGNORE / INSERT IGNORE**: race-safe insert for SQLite and MySQL respectively; concurrent INSERT on the same resource returns `false` rather than throwing.
- **Owner-only release**: `release()` uses `WHERE resource = ? AND owner = ?`; wrong-owner release returns `false` without error.
- **Renewal guard**: `renew()` adds `AND expires_at > NOW` — an expired lock cannot be renewed (the claim may already be gone or taken by another process).
- **No `sleep()` in tests**: inject PDO + crafted `expires_at` past timestamps to simulate expiry deterministically.

### Tests (`tests/Unit/Xion/DistributedLockTest.php`)

17 unit tests covering:

- Acquire new resource
- Same-owner re-acquire (idempotent)
- Contested lock returns `false`
- Stale lock reclaimed by new owner
- Acquire multiple distinct resources
- Release: correct owner (true), wrong owner (false), not found (false)
- After release, new owner can acquire
- Renew: correct owner (true), wrong owner (false), expired (false), not found (false)
- isHeld: active lock (true), no lock (false), after release (false), expired lock (false)

### Howto (`docs/development/distributed-lock.md`)

API table, basic usage, stale-lock reclaim, TTL renewal pattern, owner identity guidance, error semantics table, testing without `sleep()`.

## Findings

### F-1 — No framework findings (clean trial)

The implementation required no changes to existing NeNe core classes. `DistributedLock` is a self-contained `Nene\Xion` helper that follows the same PDO injection pattern as `IdempotencyStore` and `LoginAttemptTracker`. All 17 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
