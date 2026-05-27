# Field Trial 43 — Circuit Breaker (resilience pattern for external service calls)

Methodology reference: `docs/field-trials/README.md`. Trial Issue: FT43.

## Date

2026-05-27

## Baseline

- NeNe ref: post-FT42 main
- PHP: 8.4 (in `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default) / SQLite 3 (tests)

### Baseline verification

| Check | Result |
| --- | --- |
| `vendor/bin/phpunit --testsuite unit` | all pass |
| `vendor/bin/phan --no-progress-bar` | exit 0 |

## Goal

Add `Nene\Xion\CircuitBreaker` — a DB-backed circuit breaker that prevents cascading failures when an external service is unavailable. Three states: CLOSED (normal), OPEN (rejecting all calls), HALF-OPEN (testing recovery).

## Service Built

- `class/xion/CircuitBreaker.php` — the circuit breaker implementation.
- `tests/Unit/Xion/CircuitBreakerTest.php` — 13 unit tests using SQLite `:memory:`.
- `config/error_codes.php` — `CIRCUIT-OPEN` error code (HTTP 503).
- `docs/development/error-codes.md` — catalog row for `CIRCUIT-OPEN`.
- `docs/development/circuit-breaker.md` — developer guide.

## Design Decisions

### DB-backed state vs in-process

An in-memory circuit breaker would not survive PHP-FPM worker recycling or horizontal scaling. Storing state in the existing database keeps the implementation dependency-free and consistent across all workers.

### `opened_at` as the timer origin

The transition from OPEN to HALF-OPEN uses `opened_at` (the timestamp when the circuit was opened) rather than a wall-clock check at `isAvailable()` time. This ensures the cooldown is measured from the fault event, not from the last check, and survives across requests.

### Injectable `$now` parameter

`recordFailure(?int $now = null)` and `isAvailable(?int $now = null)` accept an optional Unix timestamp. This keeps `time()` calls out of the core logic and makes cooldown-based tests deterministic without mocking globals.

### SQLite vs MySQL dialect

`setState()` detects the PDO driver and uses `INSERT OR REPLACE` for SQLite and `INSERT … ON DUPLICATE KEY UPDATE` for MySQL. This keeps tests runnable on SQLite `:memory:` while the production path uses MySQL.

## Steps Taken

1. Surveyed `PdoConnection::getInstance()` — returns `PDO` directly; the constructor accepts an optional `?PDO` for injection.
2. Checked `TransactionManager` as a pattern for how to inject PDO in tests (extend `PDO` with a constructor override, or pass a real in-memory SQLite instance).
3. Implemented `CircuitBreaker` with the three-state machine and SQLite/MySQL dialect switch.
4. Wrote 13 unit tests covering all state transitions, threshold boundary, cooldown boundary, multi-circuit isolation, and manual reset.
5. Added `CIRCUIT-OPEN` to `config/error_codes.php` and `docs/development/error-codes.md` (kept in sync per the `testEveryRuntimeCodeAppearsInDocsMarkdownTable` contract test).
6. Wrote `docs/development/circuit-breaker.md` with state diagram, schema SQL, API table, usage pattern, and configuration guide.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| Fresh circuit `isAvailable()` returns true | yes | yes | Pass |
| Failures below threshold keep circuit CLOSED | yes | yes | Pass |
| Failures at threshold open circuit | yes | yes | Pass |
| OPEN circuit `isAvailable()` returns false | yes | yes | Pass |
| OPEN circuit transitions to HALF-OPEN after cooldown | yes | yes | Pass |
| OPEN circuit stays OPEN before cooldown | yes | yes | Pass |
| Success in HALF-OPEN closes circuit | yes | yes | Pass |
| Failure in HALF-OPEN reopens circuit | yes | yes | Pass |
| `recordSuccess()` resets failure count | yes | yes | Pass |
| `reset()` manually closes circuit | yes | yes | Pass |
| Custom threshold respected | yes | yes | Pass |
| `failureCount()` returns accurate value | yes | yes | Pass |
| Multiple circuits are isolated | yes | yes | Pass |
| `ErrorCodeTest::testEveryRuntimeCodeAppearsInDocsMarkdownTable` passes | yes | yes | Pass |
| Phan static analysis exits 0 | yes | yes | Pass |

## Friction Summary

| ID  | Location | Severity | Kind | Decision |
| --- | --- | --- | --- | --- |
| F-1 | (none found) | — | — | — |

No friction encountered. The PDO injection pattern and the SQLite `:memory:` test strategy are already established by `TransactionManager`. The only dialect difference (`INSERT OR REPLACE` vs `ON DUPLICATE KEY UPDATE`) was handled with a one-line driver check.

## Recommendations

### Immediate

None. The implementation is complete.

### Suggested

1. **Sliding-window failure rate** — The current implementation counts absolute failures. A production-grade circuit breaker might use a sliding time window (e.g., 5 failures in the last 60 seconds) rather than a cumulative count. This adds complexity and requires additional columns; defer until a concrete use case demands it.
2. **`half_open` probe count** — Currently any number of calls pass through HALF-OPEN. A stricter variant limits the probe to exactly one call by transitioning to OPEN immediately on the first call in HALF-OPEN (before the result is known). This requires a flag column. Defer until needed.
3. **Admin UI** — The `reset()` method enables a simple admin endpoint. If multiple circuit names are in use, a list endpoint (`SELECT name, state, failures, opened_at FROM circuit_breaker_states`) would complement the manual reset. Implement as a controller alongside the admin auth layer.

## Related

- `docs/development/circuit-breaker.md` — developer guide.
- NENE2 FT137 — Python-side circuit breaker trial.
- `config/error_codes.php` — `CIRCUIT-OPEN` (HTTP 503).
