# Field Trial 73 — Simple Job Queue

**Date**: 2026-05-27
**Branch**: `feat/ft73-job-queue`
**Baseline**: post FT72 merge

## Goal

Establish a lightweight DB-backed job queue pattern for NeNe applications. Provide enqueue/dequeue/complete/fail lifecycle with retry support — a simpler alternative to FT36 (ADR-class background jobs).

## What was built

### `Nene\Xion\JobQueue` (`class/xion/JobQueue.php`)

DB-backed job queue providing:

| Method | Description |
| --- | --- |
| `enqueue(array $payload, string $queue, int $maxAttempts, int $delaySeconds): int` | Add job; JSON payload. |
| `dequeue(string $queue): ?array` | Atomically claim next pending job. |
| `complete(int $jobId): void` | Mark completed. |
| `fail(int $jobId, string $error): void` | Record failure; retry or mark failed. |
| `count(string $queue, ?string $status): int` | Job counts. |

Key design points:

- **Atomic dequeue**: transaction + SELECT…LIMIT 1 + UPDATE prevents double-claim.
- **FIFO**: `ORDER BY id ASC` in dequeue.
- **Retry semantics**: `fail()` re-queues if `attempts < max_attempts`, else marks `failed`.
- **Delayed jobs**: `delaySeconds > 0` sets future `available_at`; skipped until available.
- **Multi-queue**: jobs isolated by `queue` name.
- **JSON payload**: encode on enqueue, decode on dequeue.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/JobQueueTest.php`)

14 unit tests covering:

- enqueue returns id
- enqueued job is pending
- enqueue with custom queue
- enqueue with delay (not immediately available)
- dequeue returns job
- dequeue returns null when empty
- dequeue changes status to processing
- dequeue increments attempts
- dequeue is FIFO
- complete marks job done
- fail with remaining attempts re-queues
- fail with no attempts remaining marks failed
- count all statuses when null filter
- count is queue-isolated

### Howto (`docs/development/job-queue.md`)

Covers: schema, API table, status values, usage examples, atomic dequeue, retry semantics, delayed jobs, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`JobQueue` is a clean `Nene\Xion` helper. 14 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
