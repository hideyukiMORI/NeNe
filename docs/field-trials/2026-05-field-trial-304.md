# Field Trial 304 — QueueTicket

**Date**: 2026-05-29
**Branch**: `feat/ft304-queue-ticket`
**Baseline**: post FT303 merge

## Goal

Add `Nene\Kit\QueueTicket` — a "take a number" service queue: issue tickets, advance the now-serving number, and show a waiting customer their position. Distinct from `JobQueue` (FT73, background work) and `TimeSlot` (FT203, booked appointments): a human-facing ordered service line.

## What was built

### `Nene\Kit\QueueTicket` (`class/kit/QueueTicket.php`)

Lifecycle: `waiting → serving → done` (or `skipped`); numbers increase per queue.

| Method | Description |
| --- | --- |
| `issue(queue, label='') : int` | Assign next number (atomic). |
| `callNext(queue): ?int` | Finish current serving, serve lowest waiting. |
| `nowServing(queue) / position(queue, number) / waiting(queue)` | Read. |
| `complete / skip (queue, number) / reset(queue)` | Mutate. |

Key design points:

- **Atomic issue + callNext** in transactions; `callNext` completes the current serving ticket then promotes the lowest waiting number.
- **`position` is 1-based** (1 = next up) and only for waiting tickets; skipped tickets are excluded from `callNext` and `position`.
- `reset` restarts numbering.

### Tests (`tests/Unit/Kit/QueueTicketTest.php`)

11 unit tests (27 assertions): incrementing numbers, waiting count, callNext advances (done→serving), empty→null, position (1-based + after-call), unknown null, complete, skip excludes from line, queue separation, reset restarts numbering, empty-queue validation.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 11 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
