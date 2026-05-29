# Field Trial 300 — ServiceStatus

**Date**: 2026-05-29
**Branch**: `feat/ft300-service-status`
**Baseline**: post FT299 merge

## Goal

Add `Nene\Kit\ServiceStatus` — public status-page component states with an overall roll-up (the worst component). Distinct from `HealthCheck` (FT225, internal probe log), `Heartbeat` (FT273, liveness), and `IncidentLog` (incident tracking): this is the operator-set, user-facing status board.

## What was built

### `Nene\Kit\ServiceStatus` (`class/kit/ServiceStatus.php`)

| Method | Description |
| --- | --- |
| `setStatus(component, status, message='')` | Upsert a component's state. |
| `statusOf(component): ?string` | Current state. |
| `components()` | All components + statuses. |
| `overall(): string` | Worst component (operational when empty). |
| `isOperational() / remove(component)` | Roll-up check / delete. |

Key design points:

- **Severity roll-up**: operational < maintenance < degraded < partial_outage < major_outage; `overall()` returns the highest present, defaulting to `operational` when nothing is registered.
- Status values validated against the constant set; cross-driver upsert per component.

### Tests (`tests/Unit/Kit/ServiceStatusTest.php`)

12 unit tests (19 assertions): set/statusOf, empty → operational, all-operational, overall returns worst, severity ordering (partial_outage > maintenance), maintenance > operational, components ordered, idempotent set, remove → operational, missing no-op, validation (unknown status, empty component).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean. (FT300 milestone — 300 field trials.)

## Decision

Merge as-is. No follow-up Issues raised.
