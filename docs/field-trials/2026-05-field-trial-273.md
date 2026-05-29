# Field Trial 273 — Heartbeat

**Date**: 2026-05-29
**Branch**: `feat/ft273-heartbeat`
**Baseline**: post FT272 merge

## Goal

Add `Nene\Xion\Heartbeat` — liveness / dead-man-switch tracking per service. A single last-seen timestamp per service, optimised for "is X still running?". Distinct from `HealthCheck` (FT225), which logs rich check results over time.

## What was built

### `Nene\Xion\Heartbeat` (`class/xion/Heartbeat.php`)

| Method | Description |
| --- | --- |
| `beat(service, asOf=null)` | Upsert the service's last-seen timestamp. |
| `lastBeat(service): ?string` | Last beat, or null. |
| `isAlive(service, withinSeconds, asOf=null): bool` | Beat within the freshness window? |
| `alive(withinSeconds, asOf=null) / stale(...)` | Services in / past the window. |
| `forget(service) / all()` | Deregister / list. |

Key design points:

- **Inclusive freshness boundary**: alive iff `last_beat >= asOf - withinSeconds`; a beat exactly at the cutoff counts as alive (`>=`).
- **alive/stale partition** the known services exactly at a given window.
- **Sortable timestamps**: `'Y-m-d H:i:s'` string comparison; `asOf` keeps tests deterministic.
- **Cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/HeartbeatTest.php`)

13 unit tests (18 assertions): beat + lastBeat, beat upsert, null when unseen, isAlive within window, **boundary (exactly 300s inclusive vs 301s dead)**, isAlive false when unseen, stale list, alive freshest-first, alive+stale partition, forget (+ missing no-op), validation (empty service, zero window).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
