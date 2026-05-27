# Field Trial 52 — Event Dispatcher

**Date**: 2026-05-27
**Branch**: `feat/ft52-event-dispatcher`
**Baseline**: `d3c6720` (post FT25–FT50 merge wave)

## Goal

Establish a lightweight, in-process publish-subscribe event dispatcher for NeNe applications, enabling loose coupling between domain events and their side-effect handlers.

## What was built

### `Nene\Func\EventDispatcher` (`class/func/EventDispatcher.php`)

Static dispatcher providing:

| Method | Description |
| --- | --- |
| `listen(string $event, callable $listener)` | Register a listener FIFO. |
| `emit(string $event, array $payload, bool $stopOnError): array` | Dispatch to all listeners; returns collected `\Throwable`s when `$stopOnError = false`. |
| `listeners(string $event): array` | Introspect registered listeners (for testing). |
| `forget(?string $event)` | Remove listeners for one event, or all events. |
| `reset()` | Clear the full registry (for tests). |

Key design points:

- **FIFO order**: listeners fire in registration order.
- **Payload immutability**: PHP arrays are passed by value; one listener's mutations are invisible to the next.
- **Dual error modes**: fail-fast (default) or collect-all (`stopOnError: false`).
- **No external dependencies**: pure static helper, zero I/O.

### Tests (`tests/Unit/Func/EventDispatcherTest.php`)

13 unit tests covering:

- Listener receives payload
- Multiple listeners fire in registration order
- Emit with no listeners returns empty array
- Emit with empty payload defaults to `[]`
- Exception propagates when `stopOnError = true`
- All listeners run when `stopOnError = false`; exceptions collected
- `emit()` returns `[]` when no errors occur
- `listeners()` returns registered callables
- `listeners()` returns `[]` for unknown event
- `forget(string)` removes one event's listeners
- `forget()` (null) removes all listeners
- `reset()` clears everything
- Listener cannot mutate payload visible to next listener

### Howto (`docs/development/event-dispatcher.md`)

Covers: API table, bootstrap pattern, listener signature, payload immutability, fail-fast vs collect-all error handling, `forget()`, test isolation with `reset()`.

## Findings

### F-1 — No finding (clean trial)

The implementation required no framework changes. `EventDispatcher` is a pure `Nene\Func` helper with no external dependencies or NeNe-core coupling. All 13 tests pass; CS fixer and Phan both clean.

## Decision

Merge as-is. No follow-up Issues raised.
