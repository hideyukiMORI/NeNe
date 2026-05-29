# Field Trial 274 — DeadLetterQueue

**Date**: 2026-05-29
**Branch**: `feat/ft274-dead-letter-queue`
**Baseline**: post FT273 merge

## Goal

Add `Nene\Xion\DeadLetterQueue` — a parking lot for messages that exhausted their retries. Complements `JobQueue` (FT73) and `NotificationQueue` (FT217), which handle live retryable work; this holds the terminal failures they give up on, for inspection and replay.

## What was built

### `Nene\Xion\DeadLetterQueue` (`class/xion/DeadLetterQueue.php`)

| Method | Description |
| --- | --- |
| `record(queue, payload, error='', attempts=0): int` | Park a failed message. |
| `get(id) / forQueue(queue, limit=null)` | Inspect one / list newest-first. |
| `count(queue=null) / queues()` | Totals / per-queue summary (busiest first). |
| `remove(id)` | Discard after review. |
| `requeue(id): ?array` | Claim for replay — returns and deletes atomically. |
| `purgeOlderThan(days, asOf=null): int` | Housekeeping. |

Key design points:

- **Opaque payloads**: stored as TEXT, never interpreted (caller JSON-encodes).
- **`requeue()` is atomic** (transaction, reuses an open one): returns the entry and removes it so a replay can't double-process.
- **`asOf` purge** for deterministic tests; **PDO injection**.

### Tests (`tests/Unit/Xion/DeadLetterQueueTest.php`)

14 unit tests (29 assertions): record/get, missing null, forQueue newest-first + limit, count total/per-queue, queues summary busiest-first, remove (+ missing no-op), requeue returns+removes + missing null, purgeOlderThan aged rows, validation (empty queue, negative attempts, negative days).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 14 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
