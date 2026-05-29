# Field Trial 276 — RoundRobinAssigner

**Date**: 2026-05-29
**Branch**: `feat/ft276-round-robin-assigner`
**Baseline**: post FT275 merge

## Goal

Add `Nene\Xion\RoundRobinAssigner` — fair rotating assignment across a named pool (tickets→agents, leads→reps, jobs→workers). Each pool keeps an ordered member list and a persistent cursor that survives across requests.

## What was built

### `Nene\Xion\RoundRobinAssigner` (`class/xion/RoundRobinAssigner.php`)

| Method | Description |
| --- | --- |
| `setMembers(pool, members[])` | Set the list (dedupes/trims, resets cursor). |
| `next(pool): ?string` | Return next member and advance the cursor (atomic). |
| `peek(pool): ?string` | Next without advancing. |
| `addMember / removeMember (pool, member)` | Mutate the list (cursor clamped). |
| `members / reset / remove (pool)` | Read / rewind / delete. |

Key design points:

- **Persistent cursor** stored per pool → rotation continues across requests/processes.
- **Atomic `next()`**: read + pick + advance inside a transaction (reuses an open one), so concurrent callers don't collide.
- **Cursor clamped** on member removal (`cursor % count`) so the rotation stays valid; `addMember` creates the pool on demand.
- **Cross-driver upsert**; **PDO injection**; members stored as JSON.

### Tests (`tests/Unit/Xion/RoundRobinAssignerTest.php`)

16 unit tests (27 assertions): rotation + wrap; empty/unknown pool → null; peek non-advancing; setMembers dedupe/trim + cursor reset; addMember append/create/idempotent; removeMember + cursor clamp; reset; remove; independent pools; validation (empty pool, empty member).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 16 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
